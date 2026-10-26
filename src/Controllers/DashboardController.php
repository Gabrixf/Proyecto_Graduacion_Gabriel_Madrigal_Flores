<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\DashboardService;
use DateTimeImmutable;
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

        $usuario = $request->getAttribute('usuario');
        $kpis = in_array($usuario['rol'], ['admin', 'super_admin'], true)
            ? $this->service->kpisAdmin()
            : $this->service->kpisEmpleado((int) $usuario['id']);

        // Contratos vive en /mantenimientos (solo super_admin): el aviso es solo para ese rol.
        $contratosPorAtender = $usuario['rol'] === 'super_admin'
            ? $this->service->contratosPorAtender(new DateTimeImmutable('today'))
            : [];

        return $this->twig->render($response, 'dashboard/index.html.twig', [
            'titulo'              => 'Dashboard',
            'flashError'          => $flashError,
            'kpis'                => $kpis,
            'contratosPorAtender' => $contratosPorAtender,
        ]);
    }
}
