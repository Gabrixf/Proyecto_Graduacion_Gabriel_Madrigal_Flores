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
        private readonly AuditoriaRepository   $auditoriaRepo,
        private readonly SaldoVacacionesService $saldoVacaciones,
        private readonly DiasHabilesService    $diasHabiles
    ) {}

    public function listar(?string $tipo = null, ?string $estado = null, ?string $q = null, ?int $idEmpleado = null): array
    {
        return $this->repo->findAll($tipo, $estado, $q, $idEmpleado);
    }

    /**
     * Solo lo que pidieron los empleados: las horas extra detectadas por la asistencia
     * tienen su propia pestaña ("Horas extra detectadas").
     *
     * @return array<int, array<string, mixed>>
     */
    public function listarDeEmpleados(?string $tipo = null, ?string $estado = null, ?string $q = null): array
    {
        return $this->repo->findAll($tipo, $estado, $q, null, 'empleado');
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
     * Días de una solicitud de vacaciones/permiso; null si es horas_extra.
     * Usa el override guardado en `horas` (medio día) si es mayor a 0; si no
     * (0 = días completos), cuenta los días hábiles del rango (sin domingos ni feriados).
     * @param array<string, mixed> $solicitud
     */
    public function diasSolicitados(array $solicitud): ?float
    {
        if ($solicitud['tipo'] === 'horas_extra') {
            return null;
        }
        return $this->diasHabiles->diasDeSolicitud($solicitud);
    }

    /**
     * Como obtener(), pero exige además que la solicitud esté pendiente —
     * única condición bajo la cual puede editarse. Único punto de la regla:
     * tanto el formulario de edición como el guardado la consultan aquí,
     * en vez de que cada Controller la vuelva a evaluar por su cuenta.
     */
    public function obtenerEditable(int $id, ?int $idEmpleado = null): array
    {
        $row = $this->obtener($id, $idEmpleado);
        $this->exigirNoDetectada($row, $idEmpleado);
        if (($row['origen'] ?? '') === 'asistencia') {
            throw new RuntimeException('Las horas extra detectadas no se editan; se aprueban o rechazan en "Horas extra detectadas".');
        }
        if (($row['estado'] ?? '') !== 'pendiente') {
            throw new RuntimeException('No se puede editar una solicitud ya resuelta.');
        }
        return $row;
    }

    /**
     * @return array{empleados: array<int, array<string, mixed>>, feriados: string[]}
     */
    public function datosFormulario(): array
    {
        return [
            'empleados' => $this->empleadosRepo->findAll('activo'),
            'feriados'  => $this->feriadosCercanos(),
        ];
    }

    /**
     * Feriados para el conteo de días en pantalla del formulario.
     * @return string[] fechas Y-m-d
     */
    public function feriadosCercanos(): array
    {
        return $this->diasHabiles->feriadosCercanos(new DateTimeImmutable('today'));
    }

    public function crear(array $datos, int $loggedInId, string $ip, bool $desdePortal = false): int
    {
        if ($desdePortal && trim((string)($datos['tipo'] ?? '')) === 'horas_extra') {
            throw new InvalidArgumentException('Las horas extra se registran al marcar la salida en Mi asistencia.');
        }
        $fila = $this->validar($datos, esNuevo: true);
        $id = $this->repo->insert($fila);
        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'solicitudes', $id, $ip);
        return $id;
    }

    public function actualizar(int $id, array $datos, int $loggedInId, string $ip, ?int $ownerIdEmpleado = null, bool $desdePortal = false): void
    {
        if ($desdePortal && trim((string)($datos['tipo'] ?? '')) === 'horas_extra') {
            throw new InvalidArgumentException('Las horas extra se registran al marcar la salida en Mi asistencia.');
        }
        $this->obtenerEditable($id, $ownerIdEmpleado);
        $fila = $this->validar($datos, esNuevo: false, excluirSolicitud: $id);
        $this->repo->update($id, $fila);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'solicitudes', $id, $ip);
    }

    public function aprobar(int $id, int $loggedInId, string $ip, ?string $obs): void
    {
        if (($this->obtener($id)['origen'] ?? '') === 'asistencia') {
            throw new RuntimeException('Las horas extra detectadas se aprueban en "Horas extra detectadas".');
        }
        $this->resolverEstado($id, 'aprobada', $loggedInId, $ip, $obs);
    }

    public function rechazar(int $id, int $loggedInId, string $ip, ?string $obs): void
    {
        $actual = $this->obtener($id);
        if (($actual['origen'] ?? '') === 'asistencia' && trim((string)$obs) === '') {
            throw new InvalidArgumentException('Escribe por qué se rechazan, para que el empleado lo vea.');
        }
        $this->resolverEstado($id, 'rechazada', $loggedInId, $ip, $obs);
    }

    public function eliminar(int $id, int $loggedInId, string $ip, ?int $ownerIdEmpleado = null): void
    {
        $actual = $this->obtener($id, $ownerIdEmpleado);
        $this->exigirNoDetectada($actual, $ownerIdEmpleado);
        if (($actual['estado'] ?? '') === 'aprobada') {
            throw new RuntimeException('No se puede eliminar una solicitud aprobada.');
        }
        if ($this->repo->countDependientes($id) > 0) {
            throw new RuntimeException('No se puede eliminar: la solicitud tiene registros asociados.');
        }
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'solicitudes', $id, $ip);
    }

    /**
     * Las horas extra detectadas por asistencia no las edita ni cancela el empleado
     * (solo se justifican); RRHH, sin id de dueño, sí puede.
     *
     * @param array<string, mixed> $fila
     */
    private function exigirNoDetectada(array $fila, ?int $ownerIdEmpleado): void
    {
        if ($ownerIdEmpleado !== null && ($fila['origen'] ?? '') === 'asistencia') {
            throw new RuntimeException('Las horas extra detectadas no se editan ni se cancelan desde el portal.');
        }
    }

    private function resolverEstado(int $id, string $estado, int $loggedInId, string $ip, ?string $obs): void
    {
        $actual = $this->obtener($id);
        if (($actual['estado'] ?? '') !== 'pendiente') {
            throw new RuntimeException('La solicitud ya fue resuelta.');
        }
        if ($estado === 'aprobada' && ($actual['tipo'] ?? '') === 'vacaciones') {
            $this->validarSaldoAlAprobar($actual);
        }
        $obs = ($obs !== null && trim($obs) !== '') ? mb_substr(trim($obs), 0, 255) : null;
        $this->repo->resolver($id, $estado, $loggedInId, $obs);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'solicitudes', $id, $ip);
    }

    /**
     * Revalida al aprobar: entre la creación y la aprobación otras solicitudes pudieron
     * consumir el saldo. `horas` > 0 es el override de medio día.
     *
     * @param array<string, mixed> $solicitud
     */
    private function validarSaldoAlAprobar(array $solicitud): void
    {
        $empleado = $this->empleadosRepo->findById((int)$solicitud['id_empleado']);
        if ($empleado === null) {
            throw new RuntimeException('Empleado no encontrado.');
        }
        $dias = $this->diasHabiles->diasDeSolicitud($solicitud);
        $this->saldoVacaciones->validarDisponibilidad(
            (int)$solicitud['id_empleado'],
            (string)$empleado['fecha_ingreso'],
            (string)$solicitud['fecha_inicio'],
            $dias,
            (int)$solicitud['id_solicitud']
        );
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    private function validar(array $d, bool $esNuevo, ?int $excluirSolicitud = null): array
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
                if ($esNuevo && $iniObj !== false && $iniObj < new DateTimeImmutable('today')) {
                    $errores[] = 'La fecha de inicio no puede ser anterior a hoy.';
                }
                if ($iniObj !== false && $fechaFin === $fechaInicio && !empty($d['medio_dia'])) {
                    $horasValor = 0.5;
                }
                if ($iniObj !== false && $this->diasHabiles->contar($fechaInicio, $fechaFin, $idEmpleado) <= 0) {
                    $errores[] = 'El rango elegido no incluye días hábiles (solo días de descanso o feriados).';
                }
            }
        }

        $motivo = trim((string)($d['motivo'] ?? ''));
        if (mb_strlen($motivo) > 255) {
            $errores[] = 'El motivo no puede superar 255 caracteres.';
        }

        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        if ($tipo === 'vacaciones') {
            $dias = $horasValor ?? $this->diasHabiles->contar($fechaInicio, $fechaFin, $idEmpleado);
            $this->saldoVacaciones->validarDisponibilidad(
                $idEmpleado,
                (string)$empleado['fecha_ingreso'],
                $fechaInicio,
                $dias,
                $excluirSolicitud
            );
        }

        return [
            'id_empleado'  => $idEmpleado,
            'tipo'         => $tipo,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin'    => $finValor,
            'horas'        => $horasValor ?? 0.0,   // 0 = días completos (el esquema no acepta NULL)
            'motivo'       => $motivo,
        ];
    }
}
