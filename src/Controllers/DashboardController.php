<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\DashboardService;
use App\Services\PeriodosService;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class DashboardController
{
    public function __construct(
        private readonly Twig             $twig,
        private readonly DashboardService $service,
        private readonly PeriodosService  $periodos
    ) {}

    /** GET /dashboard */
    public function index(Request $request, Response $response): Response
    {
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        $usuario = $request->getAttribute('usuario');
        $kpis = in_array($usuario['rol'], ['admin', 'super_admin'], true)
            ? $this->service->kpisAdmin()
            : $this->service->kpisEmpleado((int) $usuario['id']);

        // Contratos y Períodos viven en /mantenimientos (solo super_admin): los avisos son solo para ese rol.
        $esSuperAdmin        = $usuario['rol'] === 'super_admin';
        $hoy                 = new DateTimeImmutable('today');
        $contratosPorAtender = $esSuperAdmin ? $this->service->contratosPorAtender($hoy) : [];
        $avisoPeriodos       = $esSuperAdmin ? $this->periodos->aviso($hoy) : null;

        return $this->twig->render($response, 'dashboard/index.html.twig', [
            'titulo'              => 'Dashboard',
            'flashError'          => $flashError,
            'kpis'                => $kpis,
            'contratosPorAtender' => $contratosPorAtender,
            'avisoPeriodos'       => $avisoPeriodos,
        ]);
    }
}
