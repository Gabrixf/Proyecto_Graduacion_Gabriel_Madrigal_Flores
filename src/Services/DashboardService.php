<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\DashboardRepository;
use DateTimeImmutable;

class DashboardService
{
    public function __construct(
        private readonly DashboardRepository $repo,
        private readonly SaldoVacacionesService $saldoVacaciones
    ) {}

    public function kpisAdmin(): array
    {
        return $this->repo->kpisAdmin((new DateTimeImmutable('today'))->format('Y-m-d'));
    }

    /**
     * Contratos vencidos o por vencer (ContratosService::DIAS_AVISO_VENCIMIENTO) para
     * que RRHH decida a tiempo. `dias` < 0 = vencido hace N días.
     *
     * @return array<int, array<string, mixed>>
     */
    public function contratosPorAtender(DateTimeImmutable $hoy): array
    {
        $hasta = $hoy->modify('+' . ContratosService::DIAS_AVISO_VENCIMIENTO . ' days')->format('Y-m-d');
        return array_map(function (array $c) use ($hoy): array {
            $dias = (int) $hoy->diff(new DateTimeImmutable((string) $c['fecha_fin']))->format('%r%a');
            return $c + ['dias' => $dias, 'vencido' => $dias < 0];
        }, $this->repo->contratosConVencimientoHasta($hasta));
    }

    public function kpisEmpleado(int $idUsuario): array
    {
        $kpis     = $this->repo->kpisEmpleado($idUsuario);
        $empleado = $this->repo->empleadoPorUsuario($idUsuario);

        // Saldo por antigüedad (no el de `saldo_vacaciones`): 0 si el usuario no tiene empleado.
        $kpis['dias_vacaciones'] = $empleado === null ? 0.0 : max(
            0.0,
            $this->saldoVacaciones->saldo(
                (int) $empleado['id_empleado'],
                (string) $empleado['fecha_ingreso'],
                new DateTimeImmutable('today')
            )['disponibles']
        );

        return $kpis;
    }
}
