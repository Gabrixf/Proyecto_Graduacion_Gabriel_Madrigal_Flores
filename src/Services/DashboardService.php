<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\DashboardRepository;

class DashboardService
{
    public function __construct(private readonly DashboardRepository $repo) {}

    public function kpisAdmin(): array
    {
        return $this->repo->kpisAdmin();
    }

    public function kpisEmpleado(int $idUsuario): array
    {
        return $this->repo->kpisEmpleado($idUsuario);
    }
}
