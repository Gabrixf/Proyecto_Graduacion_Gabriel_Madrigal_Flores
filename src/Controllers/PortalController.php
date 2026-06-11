<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PortalService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class PortalController
{
    public function __construct(
        private readonly Twig          $twig,
        private readonly PortalService $service
    ) {}

    public function perfil(Request $request, Response $response): Response
    {
        $datos = $this->service->perfil((int) $_SESSION['usuario_id']);
        return $this->twig->render($response, 'portal/mi_perfil.html.twig', [
            'titulo' => 'Mi Perfil',
        ] + $datos);
    }

    public function colillas(Request $request, Response $response): Response
    {
        $datos = $this->service->colillas((int) $_SESSION['usuario_id']);
        return $this->twig->render($response, 'portal/mis_colillas.html.twig', [
            'titulo'     => 'Mis Colillas',
            'flashError' => $this->consumeFlash('flash_error'),
        ] + $datos);
    }

    public function colilla(Request $request, Response $response, array $args): Response
    {
        try {
            $colilla = $this->service->colilla((int) $args['id'], (int) $_SESSION['usuario_id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Colilla no encontrada.';
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('portal.colillas');
            return $response->withHeader('Location', $url)->withStatus(302);
        }
        return $this->twig->render($response, 'portal/colilla.html.twig', [
            'titulo'  => 'Colilla de Pago',
            'colilla' => $colilla,
        ]);
    }

    public function vacaciones(Request $request, Response $response): Response
    {
        $datos = $this->service->vacaciones((int) $_SESSION['usuario_id']);
        return $this->twig->render($response, 'portal/mis_vacaciones.html.twig', [
            'titulo' => 'Mis Vacaciones',
        ] + $datos);
    }

    private function consumeFlash(string $key): ?string
    {
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }
}
