<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ReportesService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class ReportesController
{
    public function __construct(
        private readonly Twig            $twig,
        private readonly ReportesService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'reportes/index.html.twig', [
            'titulo' => 'Reportes',
        ]);
    }

    public function planilla(Request $request, Response $response): Response
    {
        $datos = $this->service->planilla($request->getQueryParams());
        return $this->twig->render($response, 'reportes/planilla.html.twig', $datos + [
            'titulo' => 'Planilla por Período',
        ]);
    }

    public function historial(Request $request, Response $response): Response
    {
        $datos = $this->service->historial($request->getQueryParams());
        return $this->twig->render($response, 'reportes/historial.html.twig', $datos + [
            'titulo' => 'Historial por Empleado',
        ]);
    }

    public function costos(Request $request, Response $response): Response
    {
        $datos = $this->service->costos($request->getQueryParams());
        return $this->twig->render($response, 'reportes/costos.html.twig', $datos + [
            'titulo' => 'Costos Patronales',
        ]);
    }

    public function auditoria(Request $request, Response $response): Response
    {
        $datos = $this->service->auditoria($request->getQueryParams());
        return $this->twig->render($response, 'reportes/auditoria.html.twig', $datos + [
            'titulo' => 'Bitácora de Auditoría',
        ]);
    }
}
