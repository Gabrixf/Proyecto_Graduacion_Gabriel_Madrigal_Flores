<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AcumulacionVacaciones;
use App\Repositories\SolicitudesRepository;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * SaldoVacacionesService — saldo de vacaciones calculado por antigüedad.
 * Disponibles = días ganados (1 por mes completo desde el ingreso) menos los días de
 * días hábiles de las solicitudes de vacaciones pendientes o aprobadas (sin domingos
 * ni feriados, ver DiasHabilesService). No depende de `saldo_vacaciones`.
 */
class SaldoVacacionesService
{
    public function __construct(
        private readonly AcumulacionVacaciones $acumulacion,
        private readonly SolicitudesRepository $solicitudesRepo,
        private readonly DiasHabilesService    $diasHabiles
    ) {}

    /**
     * @return array{ganados: float, comprometidos: float, disponibles: float, fin_prueba: string, en_prueba: bool}
     */
    public function saldo(
        int $idEmpleado,
        string $fechaIngreso,
        DateTimeImmutable $referencia,
        ?int $excluirSolicitud = null
    ): array {
        $ingreso       = new DateTimeImmutable($fechaIngreso);
        $ganados       = $this->acumulacion->diasGanados($ingreso, $referencia);
        $comprometidos = 0.0;
        foreach ($this->solicitudesRepo->vacacionesComprometidas($idEmpleado, $excluirSolicitud) as $s) {
            $comprometidos += $this->diasHabiles->diasDeSolicitud($s);
        }

        return [
            'ganados'       => $ganados,
            'comprometidos' => $comprometidos,
            'disponibles'   => $ganados - $comprometidos,
            'fin_prueba'    => $this->acumulacion->finPrueba($ingreso)->format('Y-m-d'),
            'en_prueba'     => $this->acumulacion->enPrueba($ingreso, $referencia),
        ];
    }

    /**
     * Valida una solicitud de vacaciones que empieza en `$fechaInicio` y pide `$dias`.
     *
     * @throws InvalidArgumentException si inicia en período de prueba o excede el saldo
     *                                  acumulado a esa fecha.
     */
    public function validarDisponibilidad(
        int $idEmpleado,
        string $fechaIngreso,
        string $fechaInicio,
        float $dias,
        ?int $excluirSolicitud = null
    ): void {
        $inicio = new DateTimeImmutable($fechaInicio);
        $saldo  = $this->saldo($idEmpleado, $fechaIngreso, $inicio, $excluirSolicitud);

        if ($saldo['en_prueba']) {
            throw new InvalidArgumentException(sprintf(
                'El empleado está en período de prueba hasta el %s; no puede tomar vacaciones antes de esa fecha.',
                (new DateTimeImmutable($saldo['fin_prueba']))->format('d/m/Y')
            ));
        }
        if ($dias > $saldo['disponibles']) {
            throw new InvalidArgumentException(sprintf(
                'Saldo de vacaciones insuficiente al %s: el empleado ha acumulado %s día(s), tiene %s ya solicitado(s) y se piden %s.',
                $inicio->format('d/m/Y'),
                $this->formatear($saldo['ganados']),
                $this->formatear($saldo['comprometidos']),
                $this->formatear($dias)
            ));
        }
    }

    private function formatear(float $dias): string
    {
        return rtrim(rtrim(number_format($dias, 1), '0'), '.');
    }
}
