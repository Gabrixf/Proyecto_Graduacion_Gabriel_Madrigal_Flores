<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\LiquidacionService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class LiquidacionController
{
    public function __construct(
        private readonly Twig               $twig,
        private readonly LiquidacionService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'liquidacion/index.html.twig', [
            'titulo'        => 'Liquidaciones',
            'liquidaciones' => $this->service->listar(),
            'flashSuccess'  => $this->consumeFlash('flash_success'),
            'flashError'    => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $datos = $this->service->datosFormulario();
        return $this->twig->render($response, 'liquidacion/form.html.twig', [
            'titulo'    => 'Nueva Liquidación',
            'empleados' => $datos['empleados'],
            'motivos'   => $datos['motivos'],
            'datos'     => [],
            'errores'   => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $datos      = (array) $request->getParsedBody();
        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $id = $this->service->calcular($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Liquidación calculada correctamente.';
            return $response->withHeader('Location', $this->urlFor($request, 'liquidacion.show', ['id' => $id]))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $form = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'liquidacion/form.html.twig', [
                'titulo'    => 'Nueva Liquidación',
                'empleados' => $form['empleados'],
                'motivos'   => $form['motivos'],
                'datos'     => $datos,
                'errores'   => [$e->getMessage()],
            ]);
        }
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        try {
            $liquidacion = $this->service->obtener((int) $args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Liquidación no encontrada.';
            return $response->withHeader('Location', $this->urlFor($request, 'liquidacion.index'))->withStatus(302);
        }
        return $this->twig->render($response, 'liquidacion/detalle.html.twig', [
            'titulo'       => 'Detalle de Liquidación',
            'liquidacion'  => $liquidacion,
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->eliminar((int) $args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Liquidación eliminada.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $response->withHeader('Location', $this->urlFor($request, 'liquidacion.index'))->withStatus(302);
    }

    private function urlFor(Request $request, string $routeName, array $data = []): string
    {
        return RouteContext::fromRequest($request)->getRouteParser()->urlFor($routeName, $data);
    }

    private function consumeFlash(string $key): ?string
    {
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }
}
