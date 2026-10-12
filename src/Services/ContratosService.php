<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\ContratosRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * ContratosService
 *
 * Regla de negocio central: un empleado no puede tener más de un contrato
 * con estado='activo' a la vez. crear() la exige al dar de alta; renovar()
 * la mantiene (cierra el actual antes de abrir el nuevo, en una transacción).
 */
class ContratosService
{
    private const TIPOS    = ['tiempo_indefinido', 'plazo_fijo', 'obra_determinada'];
    private const JORNADAS = ['tiempo_completo', 'medio_tiempo'];

    public function __construct(
        private readonly ContratosRepository $repo,
        private readonly AuditoriaRepository  $auditoriaRepo
    ) {}

    // ── Consultas ─────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function listar(?int $idEmpleado = null, ?string $estado = null, ?string $tipo = null, ?string $q = null): array
    {
        return $this->repo->findAll($idEmpleado, $estado, $tipo, $q);
    }

    /**
     * @return array<string, mixed>
     * @throws RuntimeException si no existe
     */
    public function obtener(int $id): array
    {
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new RuntimeException('Contrato no encontrado.');
        }
        return $row;
    }

    /** @return array{empleados: array<int, array<string, mixed>>} */
    public function datosFormulario(): array
    {
        return ['empleados' => $this->repo->empleadosActivos()];
    }

    // ── Comandos ──────────────────────────────────────────

    /**
     * @param array<string, mixed> $datos
     * @throws InvalidArgumentException
     * @return int id_contrato del nuevo contrato
     */
    public function crear(array $datos, int $loggedInId, string $ip): int
    {
        $errores    = [];
        $idEmpleado = $this->idEmpleadoValidado($datos, $errores);
        $campos     = $this->validarCampos($datos, $errores);

        if ($idEmpleado !== null && $this->repo->findActivoPorEmpleado($idEmpleado) !== null) {
            $errores[] = 'Ese empleado ya tiene un contrato vigente. Use "Renovar" en la lista en vez de crear uno nuevo.';
        }

        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $id = $this->repo->insert([
            'id_empleado'     => $idEmpleado,
            'tipo_contrato'   => $campos['tipo_contrato'],
            'salario_pactado' => $campos['salario_pactado'],
            'jornada'         => $campos['jornada'],
            'fecha_inicio'    => $campos['fecha_inicio'],
            'fecha_fin'       => '9999-12-31',
            'estado'          => 'activo',
        ]);

        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'contratos', $id, $ip);
        return $id;
    }

    /**
     * Edita tipo/salario/jornada/fecha_inicio de un contrato existente.
     * No cambia `id_empleado` ni `estado`/`fecha_fin` (eso es exclusivo de renovar()).
     *
     * @param array<string, mixed> $datos
     * @throws InvalidArgumentException|RuntimeException
     */
    public function actualizar(int $id, array $datos, int $loggedInId, string $ip): void
    {
        $this->obtener($id); // valida que existe

        $errores = [];
        $campos  = $this->validarCampos($datos, $errores);
        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $this->repo->update($id, $campos);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'contratos', $id, $ip);
    }

    /**
     * Renueva el contrato vigente de un empleado: lo cierra (fecha_fin = un día
     * antes del nuevo inicio, estado = finalizado) y abre uno nuevo activo.
     *
     * @param array<string, mixed> $datos datos del NUEVO contrato
     * @throws InvalidArgumentException|RuntimeException
     * @return int id_contrato del nuevo contrato
     */
    public function renovar(int $idContratoActual, array $datos, int $loggedInId, string $ip): int
    {
        $actual = $this->obtener($idContratoActual);
        if ($actual['estado'] !== 'activo') {
            throw new RuntimeException('Solo se puede renovar un contrato vigente (activo).');
        }

        $errores = [];
        $campos  = $this->validarCampos($datos, $errores);

        if (empty($errores)) {
            $fechaInicioNueva  = new DateTimeImmutable($campos['fecha_inicio']);
            $fechaInicioActual = new DateTimeImmutable((string)$actual['fecha_inicio']);
            if ($fechaInicioNueva <= $fechaInicioActual) {
                $errores[] = 'La fecha de inicio del nuevo contrato debe ser posterior a la del contrato vigente.';
            }
        }

        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $fechaFinAnterior = (new DateTimeImmutable($campos['fecha_inicio']))
            ->modify('-1 day')->format('Y-m-d');

        $nuevoId = $this->repo->renovar(
            (int)$actual['id_contrato'],
            (int)$actual['id_empleado'],
            $fechaFinAnterior,
            $campos
        );

        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'contratos', $idContratoActual, $ip, 'Cierre por renovación');
        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'contratos', $nuevoId, $ip, 'Alta por renovación del contrato #' . $idContratoActual);

        return $nuevoId;
    }

    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $this->obtener($id); // valida que existe
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'contratos', $id, $ip);
    }

    // ── Validación ────────────────────────────────────────

    /**
     * @param array<string, mixed> $d
     * @param string[] $errores referencia acumuladora
     */
    private function idEmpleadoValidado(array $d, array &$errores): ?int
    {
        $valor = $d['id_empleado'] ?? '';
        $id    = is_numeric($valor) ? (int)$valor : 0;
        if ($id <= 0 || !$this->repo->esEmpleadoActivo($id)) {
            $errores[] = 'Debe seleccionar un empleado activo válido.';
            return null;
        }
        return $id;
    }

    /**
     * Valida tipo_contrato/salario_pactado/jornada/fecha_inicio — comunes a
     * crear/actualizar/renovar.
     *
     * @param array<string, mixed> $d
     * @param string[] $errores referencia acumuladora
     * @return array{tipo_contrato: string, salario_pactado: float, jornada: string, fecha_inicio: string}
     */
    private function validarCampos(array $d, array &$errores): array
    {
        $tipo = trim((string)($d['tipo_contrato'] ?? ''));
        if (!in_array($tipo, self::TIPOS, true)) {
            $errores[] = 'Debe seleccionar un tipo de contrato válido.';
        }

        $jornada = trim((string)($d['jornada'] ?? ''));
        if ($jornada === '') {
            $jornada = 'tiempo_completo';
        }
        if (!in_array($jornada, self::JORNADAS, true)) {
            $errores[] = 'Debe seleccionar una jornada válida.';
        }

        $salarioRaw = $d['salario_pactado'] ?? '';
        $salario    = is_numeric($salarioRaw) ? (float)$salarioRaw : -1.0;
        if ($salario <= 0 || $salario > 99999999.99) {
            $errores[] = 'El salario pactado debe ser un número mayor a 0.';
        }

        $fechaInicio = trim((string)($d['fecha_inicio'] ?? ''));
        if (DateTimeImmutable::createFromFormat('Y-m-d', $fechaInicio) === false) {
            $errores[] = 'La fecha de inicio es obligatoria y debe tener formato válido.';
        }

        return [
            'tipo_contrato'   => $tipo,
            'salario_pactado' => $salario,
            'jornada'         => $jornada,
            'fecha_inicio'    => $fechaInicio,
        ];
    }
}
