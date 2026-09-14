<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ContratosService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class ContratosController
{
    public function __construct(
        private readonly Twig             $twig,
        private readonly ContratosService  $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $q      = trim($params['q'] ?? '');

        return $this->twig->render($response, 'contratos/index.html.twig', [
            'contratos'    => $this->service->listar(
                null,
                ($params['estado'] ?? '') !== '' ? $params['estado'] : null,
                ($params['tipo'] ?? '') !== '' ? $params['tipo'] : null,
                $q !== '' ? $q : null
            ),
            'q'            => $q,
            'estadoFiltro' => $params['estado'] ?? '',
            'tipoFiltro'   => $params['tipo'] ?? '',
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'contratos/form.html.twig', [
            'titulo'    => 'Nuevo Contrato',
            'accion'    => 'crear',
            'contrato'  => [],
            'errores'   => [],
            'empleados' => $this->service->datosFormulario()['empleados'],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $datos      = (array)$request->getParsedBody();
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Contrato creado exitosamente.';
            return $this->redirect($request, $response, 'contratos.index');
        } catch (InvalidArgumentException $e) {
            return $this->twig->render($response->withStatus(422), 'contratos/form.html.twig', [
                'titulo'    => 'Nuevo Contrato',
                'accion'    => 'crear',
                'contrato'  => $datos,
                'errores'   => [$e->getMessage()],
                'empleados' => $this->service->datosFormulario()['empleados'],
            ]);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            $contrato = $this->service->obtener((int)$args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Contrato no encontrado.';
            return $this->redirect($request, $response, 'contratos.index');
        }

        return $this->twig->render($response, 'contratos/form.html.twig', [
            'titulo'   => 'Editar Contrato',
            'accion'   => 'editar',
            'contrato' => $contrato,
            'errores'  => [],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id         = (int)$args['id'];
        $datos      = (array)$request->getParsedBody();
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->actualizar($id, $datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Contrato actualizado correctamente.';
            return $this->redirect($request, $response, 'contratos.index');
        } catch (InvalidArgumentException $e) {
            $actual = $this->service->obtener($id);
            return $this->twig->render($response->withStatus(422), 'contratos/form.html.twig', [
                'titulo'   => 'Editar Contrato',
                'accion'   => 'editar',
                'contrato' => array_merge(
                    ['id_contrato' => $id, 'nombre' => $actual['nombre'], 'apellidos' => $actual['apellidos']],
                    $datos
                ),
                'errores'  => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $this->redirect($request, $response, 'contratos.index');
        }
    }

    public function renovar(Request $request, Response $response, array $args): Response
    {
        $id         = (int)$args['id'];
        $datos      = (array)$request->getParsedBody();
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->renovar($id, $datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Contrato renovado correctamente.';
        } catch (InvalidArgumentException|RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $this->redirect($request, $response, 'contratos.index');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->eliminar((int)$args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Contrato eliminado correctamente.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $this->redirect($request, $response, 'contratos.index');
    }

    // ── Helpers ───────────────────────────────────────────

    private function usuarioId(Request $request): int
    {
        return (int)$request->getAttribute('usuario')['id'];
    }

    private function urlFor(Request $request, string $routeName, array $args = []): string
    {
        return RouteContext::fromRequest($request)->getRouteParser()->urlFor($routeName, $args);
    }

    private function redirect(Request $request, Response $response, string $routeName, array $args = []): Response
    {
        return $response->withHeader('Location', $this->urlFor($request, $routeName, $args))->withStatus(302);
    }

    private function consumeFlash(string $key): ?string
    {
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }
}
