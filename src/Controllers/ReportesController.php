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
        return $this->twig->render($response, 'reportes/planilla.html.twig', [
            'titulo' => 'Planilla por Período',
        ] + $datos);
    }

    public function historial(Request $request, Response $response): Response
    {
        $datos = $this->service->historial($request->getQueryParams());
        return $this->twig->render($response, 'reportes/historial.html.twig', [
            'titulo' => 'Historial por Empleado',
        ] + $datos);
    }

    public function costos(Request $request, Response $response): Response
    {
        $datos = $this->service->costos($request->getQueryParams());
        return $this->twig->render($response, 'reportes/costos.html.twig', [
            'titulo' => 'Costos Patronales',
        ] + $datos);
    }

    public function auditoria(Request $request, Response $response): Response
    {
        $datos = $this->service->auditoria($request->getQueryParams());
        return $this->twig->render($response, 'reportes/auditoria.html.twig', [
            'titulo' => 'Bitácora de Auditoría',
        ] + $datos);
    }
}
