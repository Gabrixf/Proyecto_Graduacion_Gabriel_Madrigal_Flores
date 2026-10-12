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
        return $this->repo->kpisAdmin();
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
