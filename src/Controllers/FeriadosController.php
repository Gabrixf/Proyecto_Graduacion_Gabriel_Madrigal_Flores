<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\FeriadosService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class FeriadosController
{
    public function __construct(
        private readonly Twig            $twig,
        private readonly FeriadosService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'feriados/index.html.twig', [
            'titulo'       => 'Feriados Nacionales',
            'feriados'     => $this->service->listar(),
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'feriados/form.html.twig', [
            'titulo'   => 'Registrar Feriado',
            'accion'   => 'crear',
            'feriado'  => [],
            'errores'  => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $datos      = (array)$request->getParsedBody();
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Feriado registrado exitosamente.';
            return $this->redirect($request, $response, 'feriados.index');
        } catch (InvalidArgumentException $e) {
            return $this->twig->render($response->withStatus(422), 'feriados/form.html.twig', [
                'titulo'  => 'Registrar Feriado',
                'accion'  => 'crear',
                'feriado' => $datos,
                'errores' => [$e->getMessage()],
            ]);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            $feriado = $this->service->obtener((int)$args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Feriado no encontrado.';
            return $this->redirect($request, $response, 'feriados.index');
        }

        return $this->twig->render($response, 'feriados/form.html.twig', [
            'titulo'  => 'Editar Feriado',
            'accion'  => 'editar',
            'feriado' => $feriado,
            'errores' => [],
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
            $_SESSION['flash_success'] = 'Feriado actualizado correctamente.';
            return $this->redirect($request, $response, 'feriados.index');
        } catch (InvalidArgumentException $e) {
            return $this->twig->render($response->withStatus(422), 'feriados/form.html.twig', [
                'titulo'  => 'Editar Feriado',
                'accion'  => 'editar',
                'feriado' => array_merge(['id_feriado' => $id], $datos),
                'errores' => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $this->redirect($request, $response, 'feriados.index');
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->eliminar((int)$args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Feriado eliminado correctamente.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $this->redirect($request, $response, 'feriados.index');
    }

    private function urlFor(Request $request, string $routeName): string
    {
        return RouteContext::fromRequest($request)->getRouteParser()->urlFor($routeName);
    }

    private function redirect(Request $request, Response $response, string $routeName, array $routeArgs = []): Response
    {
        return $response->withHeader('Location', $this->urlFor($request, $routeName, $routeArgs))->withStatus(302);
    }

    private function consumeFlash(string $key): ?string
    {
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }

    private function usuarioId(Request $request): int
    {
        return (int) $request->getAttribute('usuario')['id'];
    }
}
