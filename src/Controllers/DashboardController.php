<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\DashboardService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class DashboardController
{
    public function __construct(
        private readonly Twig             $twig,
        private readonly DashboardService $service
    ) {}

    /** GET /dashboard */
    public function index(Request $request, Response $response): Response
    {
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        $rol  = $_SESSION['usuario_rol'] ?? 'empleado';
        $kpis = $rol === 'admin'
            ? $this->service->kpisAdmin()
            : $this->service->kpisEmpleado((int) $_SESSION['usuario_id']);

        return $this->twig->render($response, 'dashboard/index.html.twig', [
            'titulo'     => 'Dashboard',
            'flashError' => $flashError,
            'kpis'       => $kpis,
        ]);
    }
}
