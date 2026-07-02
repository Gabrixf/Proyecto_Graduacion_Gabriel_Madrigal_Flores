<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\SolicitudesRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * SolicitudesService — registro y resolución (aprobar/rechazar) de solicitudes.
 * No accede a PDO ni a $_SESSION/$_SERVER.
 */
class SolicitudesService
{
    private const TIPOS = ['horas_extra', 'vacaciones', 'permiso'];

    public function __construct(
        private readonly SolicitudesRepository $repo,
        private readonly EmpleadosRepository   $empleadosRepo,
        private readonly AuditoriaRepository   $auditoriaRepo
    ) {}

    public function listar(?string $tipo = null, ?string $estado = null, ?string $q = null, ?int $idEmpleado = null): array
    {
        return $this->repo->findAll($tipo, $estado, $q, $idEmpleado);
    }

    /**
     * Si $idEmpleado se indica, una solicitud que exista pero pertenezca a otro
     * empleado se trata igual que una que no existe (no revela su existencia).
     */
    public function obtener(int $id, ?int $idEmpleado = null): array
    {
        $row = $this->repo->findById($id, $idEmpleado);
        if ($row === null) {
            throw new RuntimeException('Solicitud no encontrada.');
        }
        return $row;
    }

    /**
     * @return array{empleados: array<int, array<string, mixed>>}
     */
    public function datosFormulario(): array
    {
        return ['empleados' => $this->empleadosRepo->findAll('activo')];
    }

    public function crear(array $datos, int $loggedInId, string $ip): int
    {
        $fila = $this->validar($datos);
        $id = $this->repo->insert($fila);
        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'solicitudes', $id, $ip);
        return $id;
    }

    public function actualizar(int $id, array $datos, int $loggedInId, string $ip, ?int $ownerIdEmpleado = null): void
    {
        $actual = $this->obtener($id, $ownerIdEmpleado);
        if (($actual['estado'] ?? '') !== 'pendiente') {
            throw new RuntimeException('No se puede editar una solicitud ya resuelta.');
        }
        $fila = $this->validar($datos);
        $this->repo->update($id, $fila);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'solicitudes', $id, $ip);
    }

    public function aprobar(int $id, int $loggedInId, string $ip, ?string $obs): void
    {
        $this->resolverEstado($id, 'aprobada', $loggedInId, $ip, $obs);
    }

    public function rechazar(int $id, int $loggedInId, string $ip, ?string $obs): void
    {
        $this->resolverEstado($id, 'rechazada', $loggedInId, $ip, $obs);
    }

    public function eliminar(int $id, int $loggedInId, string $ip, ?int $ownerIdEmpleado = null): void
    {
        $actual = $this->obtener($id, $ownerIdEmpleado);
        if (($actual['estado'] ?? '') === 'aprobada') {
            throw new RuntimeException('No se puede eliminar una solicitud aprobada.');
        }
        if ($this->repo->countDependientes($id) > 0) {
            throw new RuntimeException('No se puede eliminar: la solicitud tiene registros asociados.');
        }
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'solicitudes', $id, $ip);
    }

    private function resolverEstado(int $id, string $estado, int $loggedInId, string $ip, ?string $obs): void
    {
        $actual = $this->obtener($id);
        if (($actual['estado'] ?? '') !== 'pendiente') {
            throw new RuntimeException('La solicitud ya fue resuelta.');
        }
        $obs = ($obs !== null && trim($obs) !== '') ? mb_substr(trim($obs), 0, 255) : null;
        $this->repo->resolver($id, $estado, $loggedInId, $obs);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'solicitudes', $id, $ip);
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    private function validar(array $d): array
    {
        $errores = [];

        $tipo = trim((string)($d['tipo'] ?? ''));
        if (!in_array($tipo, self::TIPOS, true)) {
            $errores[] = 'Debe seleccionar un tipo de solicitud válido.';
        }

        $idEmpleado = isset($d['id_empleado']) && is_numeric($d['id_empleado']) ? (int)$d['id_empleado'] : 0;
        $empleado   = $idEmpleado > 0 ? $this->empleadosRepo->findById($idEmpleado) : null;
        if ($empleado === null) {
            $errores[] = 'Debe seleccionar un empleado válido.';
        } elseif (($empleado['estado'] ?? '') !== 'activo') {
            $errores[] = 'El empleado seleccionado no está activo.';
        }

        $fechaInicio = trim((string)($d['fecha_inicio'] ?? ''));
        $iniObj = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaInicio);
        if ($iniObj === false) {
            $errores[] = 'La fecha de inicio es obligatoria y debe tener formato válido.';
        }

        $fechaFin   = trim((string)($d['fecha_fin'] ?? ''));
        $horasRaw   = $d['horas'] ?? '';
        $finValor   = null;
        $horasValor = null;

        if ($tipo === 'horas_extra') {
            if (!is_numeric($horasRaw) || (float)$horasRaw <= 0 || (float)$horasRaw > 999.99) {
                $errores[] = 'Para horas extra, las horas deben ser un número mayor a 0.';
            } else {
                $horasValor = round((float)$horasRaw, 2);
            }
            // Día único: la fecha de fin es la misma de inicio.
            $finValor = $iniObj !== false ? $fechaInicio : null;
        } elseif ($tipo === 'vacaciones' || $tipo === 'permiso') {
            $finObj = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaFin);
            if ($finObj === false) {
                $errores[] = 'La fecha de fin es obligatoria para vacaciones y permisos.';
            } elseif ($iniObj !== false && $finObj < $iniObj) {
                $errores[] = 'La fecha de fin no puede ser anterior a la de inicio.';
            } else {
                $finValor = $fechaFin;
            }
        }

        $motivo = trim((string)($d['motivo'] ?? ''));
        if (mb_strlen($motivo) > 255) {
            $errores[] = 'El motivo no puede superar 255 caracteres.';
        }

        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        return [
            'id_empleado'  => $idEmpleado,
            'tipo'         => $tipo,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin'    => $finValor,
            'horas'        => $horasValor,
            'motivo'       => $motivo !== '' ? $motivo : null,
        ];
    }
}
