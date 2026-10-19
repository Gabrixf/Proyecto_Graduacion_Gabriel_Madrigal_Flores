<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\LiquidacionCalculadora;
use App\Helpers\PromedioSalarial;
use App\Repositories\AguinaldoRepository;
use App\Repositories\AuditoriaRepository;
use App\Repositories\LiquidacionRepository;
use App\Repositories\NominasRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * LiquidacionService — reúne los datos de la liquidación (base del Art. 30, saldo y valor de las
 * vacaciones del Art. 157, aguinaldo de la Ley 2412) y deja las reglas a LiquidacionCalculadora.
 */
class LiquidacionService
{
    public const MOTIVOS = [
        'renuncia'             => 'Renuncia',
        'despido_con_causa'    => 'Despido con causa',
        'despido_sin_causa'    => 'Despido sin causa',
        'renuncia_justificada' => 'Renuncia justificada (Art. 83)',
        'mutuo_acuerdo'        => 'Mutuo acuerdo',
        'jubilacion'           => 'Jubilación o pensión',
        'muerte'               => 'Muerte del trabajador',
    ];

    public function __construct(
        private readonly LiquidacionRepository  $repo,
        private readonly AuditoriaRepository    $auditoriaRepo,
        private readonly LiquidacionCalculadora $calc,
        private readonly NominasRepository      $nominasRepo,
        private readonly AguinaldoRepository    $aguinaldoRepo,
        private readonly SaldoVacacionesService $saldoVacaciones
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function listar(?string $q = null): array
    {
        return $this->repo->findAll($q);
    }

    /** @return array<string, mixed> */
    public function obtener(int $id): array
    {
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new RuntimeException('Liquidación no encontrada.');
        }
        return $row;
    }

    /** @return array{empleados: array<int, array<string, mixed>>, motivos: array<string, string>} */
    public function datosFormulario(): array
    {
        return ['empleados' => $this->repo->empleadosActivos(), 'motivos' => self::MOTIVOS];
    }

    /** Calcula y persiste la liquidación de un empleado. @return int id_liquidacion */
    public function calcular(array $datos, int $loggedInId, string $ip): int
    {
        $idEmpleado  = isset($datos['id_empleado']) && is_numeric($datos['id_empleado']) ? (int) $datos['id_empleado'] : 0;
        $motivo      = (string) ($datos['motivo'] ?? '');
        $fechaSalida = trim((string) ($datos['fecha_salida'] ?? ''));

        $errores = [];
        $emp = $idEmpleado > 0 ? $this->repo->datosEmpleado($idEmpleado) : null;
        if ($emp === null) {
            $errores[] = 'Debe seleccionar un empleado válido.';
        }
        if (!array_key_exists($motivo, self::MOTIVOS)) {
            $errores[] = 'El motivo seleccionado no es válido.';
        }
        if ($fechaSalida === '' || strtotime($fechaSalida) === false) {
            $errores[] = 'La fecha de salida no es válida.';
        } elseif ($emp !== null && $fechaSalida < (string) $emp['fecha_ingreso']) {
            $errores[] = 'La fecha de salida no puede ser anterior a la fecha de ingreso.';
        }
        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $ingreso = (string) $emp['fecha_ingreso'];
        $salario = (float) $emp['salario_base'];
        $salida  = new DateTimeImmutable($fechaSalida);

        // Art. 30: promedio de los salarios de los últimos seis meses (o del tiempo trabajado).
        $desde30  = $this->mesesAntes($salida, 6)->modify('+1 day')->format('Y-m-d');
        $diario30 = $this->promedioDiario($idEmpleado, $desde30, $fechaSalida, $ingreso, 184);
        $baseEstimada    = $diario30 === null;
        $salarioPromedio = $baseEstimada ? $salario : round($diario30 * 30, 2);

        // Art. 157: el día de vacaciones vale el mayor entre salario ÷ 30 y el promedio de 50 semanas.
        $desde157  = $salida->modify('-349 days')->format('Y-m-d');
        $diarioVac = max($salario / 30, $this->promedioDiario($idEmpleado, $desde157, $fechaSalida, $ingreso, 350) ?? 0.0);
        $diasVac   = (float) $this->saldoVacaciones->saldo($idEmpleado, $ingreso, $salida)['disponibles'];

        // Ley 2412: lo pagado desde el 1 de diciembre más los días que todavía no tienen nómina.
        $inicioAg  = $this->inicioAguinaldo($ingreso, $salida);
        $ultimoFin = $this->aguinaldoRepo->ultimoFinNominaPagadaEntre($idEmpleado, $inicioAg, $fechaSalida);
        $desdeSinNomina = $ultimoFin === null
            ? $inicioAg
            : (new DateTimeImmutable($ultimoFin))->modify('+1 day')->format('Y-m-d');
        $brutosAg = $this->aguinaldoRepo->sumaBrutos($idEmpleado, $inicioAg, $fechaSalida)
                  + LiquidacionCalculadora::diasComerciales($desdeSinNomina, $fechaSalida) * $salario / 30;

        $r = $this->calc->calcular([
            'fechaIngreso'            => $ingreso,
            'fechaSalida'             => $fechaSalida,
            'motivo'                  => $motivo,
            'salarioPromedio'         => $salarioPromedio,
            'salarioDiarioVacaciones' => $diarioVac,
            'diasVacaciones'          => $diasVac,
            'brutosAguinaldo'         => $brutosAg,
        ]);

        $id = $this->repo->upsert([
            'id_empleado'            => $idEmpleado,
            'fecha_salida'           => $fechaSalida,
            'motivo'                 => $motivo,
            'salario_promedio'       => $r['salario_promedio'],
            'dias_preaviso'          => $r['dias_preaviso'],
            'dias_cesantia'          => $r['dias_cesantia'],
            'dias_vacaciones'        => $r['dias_vacaciones'],
            'base_estimada'          => $baseEstimada ? 1 : 0,
            'preaviso'               => $r['preaviso'],
            'cesantia'               => $r['cesantia'],
            'vacaciones_pendientes'  => $r['vacaciones_pendientes'],
            'aguinaldo_proporcional' => $r['aguinaldo_proporcional'],
            'total_liquidacion'      => $r['total_liquidacion'],
        ]);
        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'liquidacion', $id, $ip);
        return $id;
    }

    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $this->obtener($id);
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'liquidacion', $id, $ip);
    }

    /** Promedio diario de lo devengado entre las fechas, por días cubiertos; null si no hay nóminas. */
    private function promedioDiario(int $idEmpleado, string $desde, string $hasta, string $ingreso, int $maxDias): ?float
    {
        return PromedioSalarial::diario(
            $this->nominasRepo->brutoEntre($idEmpleado, $desde, $hasta),
            $desde,
            $hasta,
            $ingreso,
            $this->nominasRepo->primerInicioNominaEntre($idEmpleado, $desde, $hasta),
            $this->nominasRepo->ultimoFinNominaEntre($idEmpleado, $desde, $hasta),
            $maxDias
        );
    }

    /** La misma fecha $n meses antes, sin desbordar: el 31/08 menos 6 meses es el 28/02, no el 03/03. */
    private function mesesAntes(DateTimeImmutable $fecha, int $n): DateTimeImmutable
    {
        $base = $fecha->modify('first day of -' . $n . ' months');
        return $base->setDate((int) $base->format('Y'), (int) $base->format('n'), min((int) $fecha->format('j'), (int) $base->format('t')));
    }

    /** 1 de diciembre anterior a la salida (o el de ese año si la salida es en diciembre), o el ingreso si es posterior. */
    private function inicioAguinaldo(string $ingreso, DateTimeImmutable $salida): string
    {
        $anio   = (int) $salida->format('Y');
        $inicio = $salida->format('m') === '12' ? $anio . '-12-01' : ($anio - 1) . '-12-01';
        return $ingreso > $inicio ? $ingreso : $inicio;
    }
}
