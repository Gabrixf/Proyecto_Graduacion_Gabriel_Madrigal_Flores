<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * DashboardController
 *
 * Página de inicio tras el login.
 * En módulos posteriores se inyectarán servicios para mostrar
 * indicadores (nóminas pendientes, solicitudes, etc.).
 */
class DashboardController
{
    public function __construct(private readonly Twig $twig) {}

    /** GET /dashboard */
    public function index(Request $request, Response $response): Response
    {
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        return $this->twig->render($response, 'dashboard/index.html.twig', [
            'titulo'     => 'Dashboard',
            'flashError' => $flashError,
        ]);
    }
}
