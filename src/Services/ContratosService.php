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
 *
 * Vencimiento: plazo fijo y obra determinada exigen fecha_fin. El plazo fijo no
 * puede pasar de 1 año, o de 5 si RRHH marca que requiere preparación técnica
 * (Art. 26 del Código de Trabajo); la obra determinada dura lo que dure la obra
 * (Art. 27), así que su fecha es estimada y sin tope. Indefinido usa 9999-12-31.
 */
class ContratosService
{
    private const TIPOS    = ['tiempo_indefinido', 'plazo_fijo', 'obra_determinada'];
    private const JORNADAS = ['tiempo_completo', 'medio_tiempo'];
    private const SIN_VENCIMIENTO = '9999-12-31';

    /** Días de anticipación con que se avisa un vencimiento (lista y dashboard). */
    public const DIAS_AVISO_VENCIMIENTO = 30;

    public function __construct(
        private readonly ContratosRepository $repo,
        private readonly AuditoriaRepository  $auditoriaRepo
    ) {}

    // ── Consultas ─────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function listar(?int $idEmpleado = null, ?string $estado = null, ?string $tipo = null, ?string $q = null): array
    {
        $hoy    = new DateTimeImmutable('today');
        $limite = $hoy->modify('+' . self::DIAS_AVISO_VENCIMIENTO . ' days')->format('Y-m-d');
        $hoy    = $hoy->format('Y-m-d');
        return array_map(
            fn(array $c) => $c + [
                'vencido'    => $this->estaVencido($c, $hoy),
                'por_vencer' => $this->tieneVencimiento($c) && $c['fecha_fin'] >= $hoy && $c['fecha_fin'] <= $limite,
            ],
            $this->repo->findAll($idEmpleado, $estado, $tipo, $q)
        );
    }

    /**
     * Activo, con vencimiento real (no indefinido) y ya pasado: si la persona sigue
     * laborando, por Art. 26 el contrato se tiene por indefinido.
     *
     * @param array<string, mixed> $c
     */
    private function estaVencido(array $c, string $hoy): bool
    {
        return $this->tieneVencimiento($c) && (string)$c['fecha_fin'] < $hoy;
    }

    /**
     * Contrato vigente con fecha de vencimiento, de un empleado que no ha salido: si ya está
     * inactivo o tiene liquidación, el vencimiento no requiere atención.
     *
     * @param array<string, mixed> $c
     */
    private function tieneVencimiento(array $c): bool
    {
        return $c['estado'] === 'activo' && $c['tipo_contrato'] !== 'tiempo_indefinido'
            && empty($c['salida_registrada']);
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

        $id = $this->repo->insert(['id_empleado' => $idEmpleado] + $campos + ['estado' => 'activo']);

        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'contratos', $id, $ip);
        return $id;
    }

    /**
     * Edita tipo/salario/jornada/fecha_inicio/vencimiento de un contrato existente.
     * No cambia `id_empleado` ni `estado` (el cierre es exclusivo de renovar()).
     * En un contrato finalizado, `fecha_fin` es la fecha de cierre: se conserva.
     *
     * @param array<string, mixed> $datos
     * @throws InvalidArgumentException|RuntimeException
     */
    public function actualizar(int $id, array $datos, int $loggedInId, string $ip): void
    {
        $actual     = $this->obtener($id);
        $finalizado = $actual['estado'] !== 'activo';

        $errores = [];
        $campos  = $this->validarCampos($datos, $errores, !$finalizado);
        if ($finalizado) {
            $campos['fecha_fin']                    = (string)$actual['fecha_fin'];
            $campos['requiere_preparacion_tecnica'] = (int)$actual['requiere_preparacion_tecnica'];
        }
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

    /**
     * Art. 26: si el plazo venció y la persona sigue laborando, el contrato se tiene
     * por indefinido. Lo refleja con una renovación a tiempo indefinido desde el día
     * siguiente al vencimiento, con el mismo salario y jornada.
     *
     * @throws RuntimeException si no existe, no está activo, ya es indefinido o no ha vencido
     * @return int id_contrato del nuevo contrato indefinido
     */
    public function pasarAIndefinido(int $id, int $loggedInId, string $ip): int
    {
        $actual = $this->obtener($id);
        $hoy    = (new DateTimeImmutable('today'))->format('Y-m-d');
        if (!$this->estaVencido($actual, $hoy)) {
            throw new RuntimeException('Solo se puede pasar a indefinido un contrato vigente que ya esté vencido.');
        }

        $nuevoId = $this->repo->renovar(
            (int)$actual['id_contrato'],
            (int)$actual['id_empleado'],
            (string)$actual['fecha_fin'],
            [
                'tipo_contrato'                => 'tiempo_indefinido',
                'salario_pactado'              => (float)$actual['salario_pactado'],
                'jornada'                      => (string)$actual['jornada'],
                'fecha_inicio'                 => (new DateTimeImmutable((string)$actual['fecha_fin']))->modify('+1 day')->format('Y-m-d'),
                'fecha_fin'                    => self::SIN_VENCIMIENTO,
                'requiere_preparacion_tecnica' => 0,
            ]
        );

        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'contratos', $id, $ip, 'Cierre por vencimiento (Art. 26)');
        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'contratos', $nuevoId, $ip, 'Paso a indefinido del contrato #' . $id . ' (Art. 26)');

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
     * Valida tipo_contrato/salario_pactado/jornada/fecha_inicio/vencimiento —
     * comunes a crear/actualizar/renovar. Con $conVencimiento=false (contrato ya
     * finalizado) no se valida la fecha de vencimiento.
     *
     * @param array<string, mixed> $d
     * @param string[] $errores referencia acumuladora
     * @return array{tipo_contrato: string, salario_pactado: float, jornada: string, fecha_inicio: string,
     *               fecha_fin: string, requiere_preparacion_tecnica: int}
     */
    private function validarCampos(array $d, array &$errores, bool $conVencimiento = true): array
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
        $inicio      = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaInicio);
        if ($inicio === false) {
            $errores[] = 'La fecha de inicio es obligatoria y debe tener formato válido.';
        }

        $esPlazoFijo = $tipo === 'plazo_fijo';
        $tecnica     = $esPlazoFijo && !empty($d['requiere_preparacion_tecnica']) ? 1 : 0;
        $fechaFin    = self::SIN_VENCIMIENTO;
        if ($conVencimiento && ($esPlazoFijo || $tipo === 'obra_determinada')) {
            $fechaFin = trim((string)($d['fecha_fin'] ?? ''));
            $fin      = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaFin);
            if ($fin === false) {
                $errores[] = 'La fecha de vencimiento es obligatoria para plazo fijo y obra determinada.';
            } elseif ($inicio !== false) {
                $this->validarVencimiento($inicio, $fin, $esPlazoFijo, $tecnica === 1, $errores);
            }
        }

        return [
            'tipo_contrato'                => $tipo,
            'salario_pactado'              => $salario,
            'jornada'                      => $jornada,
            'fecha_inicio'                 => $fechaInicio,
            'fecha_fin'                    => $fechaFin,
            'requiere_preparacion_tecnica' => $tecnica,
        ];
    }

    /**
     * Art. 26: el plazo fijo no puede exceder 1 año, o 5 si requiere preparación
     * técnica. "1 año" desde el 01/01 termina el 31/12: el último día válido es
     * inicio + N años − 1 día. La obra determinada (Art. 27) no tiene tope.
     *
     * @param string[] $errores referencia acumuladora
     */
    private function validarVencimiento(
        DateTimeImmutable $inicio,
        DateTimeImmutable $fin,
        bool $esPlazoFijo,
        bool $tecnica,
        array &$errores
    ): void {
        if ($fin <= $inicio) {
            $errores[] = 'La fecha de vencimiento debe ser posterior a la fecha de inicio.';
            return;
        }
        if (!$esPlazoFijo) {
            return;
        }
        $anios  = $tecnica ? 5 : 1;
        $maximo = $inicio->modify("+{$anios} years")->modify('-1 day');
        if ($fin > $maximo) {
            $errores[] = sprintf(
                'Un contrato a plazo fijo no puede exceder %s (Art. 26 del Código de Trabajo): el vencimiento máximo es el %s.%s',
                $tecnica ? '5 años' : '1 año',
                $maximo->format('d/m/Y'),
                $tecnica ? '' : ' Si el puesto requiere preparación técnica, marque esa casilla (tope de 5 años).'
            );
        }
    }
}
