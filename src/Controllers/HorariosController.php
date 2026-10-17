<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\HorariosService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

/**
 * HorariosController — solo HTTP; la lógica está en HorariosService.
 * Rutas bajo /mantenimientos/horarios (super_admin).
 */
class HorariosController
{
    public function __construct(
        private readonly Twig            $twig,
        private readonly HorariosService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'horarios/index.html.twig', [
            'titulo'       => 'Horarios',
            'horarios'     => $this->service->listar(),
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->form($response, 'crear', ['dias' => []], []);
    }

    public function store(Request $request, Response $response): Response
    {
        $datos = (array)$request->getParsedBody();
        try {
            $this->service->crear($datos, $this->usuarioId($request), $this->ip());
            $_SESSION['flash_success'] = 'Horario creado.';
            return $this->redirect($request, $response, 'horarios.index');
        } catch (InvalidArgumentException $e) {
            return $this->form($response->withStatus(422), 'crear', $datos, [$e->getMessage()]);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            return $this->form($response, 'editar', $this->service->obtener((int)$args['id']), []);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $this->redirect($request, $response, 'horarios.index');
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id    = (int)$args['id'];
        $datos = (array)$request->getParsedBody();
        try {
            $this->service->actualizar($id, $datos, $this->usuarioId($request), $this->ip());
            $_SESSION['flash_success'] = 'Horario actualizado.';
            return $this->redirect($request, $response, 'horarios.index');
        } catch (InvalidArgumentException $e) {
            return $this->form($response->withStatus(422), 'editar', ['id_horario' => $id] + $datos, [$e->getMessage()]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $this->redirect($request, $response, 'horarios.index');
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        try {
            $this->service->eliminar((int)$args['id'], $this->usuarioId($request), $this->ip());
            $_SESSION['flash_success'] = 'Horario eliminado.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $this->redirect($request, $response, 'horarios.index');
    }

    /** @param array<string, mixed> $horario @param string[] $errores */
    private function form(Response $response, string $accion, array $horario, array $errores): Response
    {
        return $this->twig->render($response, 'horarios/form.html.twig', [
            'titulo'  => $accion === 'crear' ? 'Nuevo horario' : 'Editar horario',
            'accion'  => $accion,
            'horario' => $horario,
            'errores' => $errores,
            'nombresDias' => [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'],
        ]);
    }

    private function redirect(Request $request, Response $response, string $routeName): Response
    {
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor($routeName);
        return $response->withHeader('Location', $url)->withStatus(302);
    }

    private function consumeFlash(string $key): ?string
    {
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }

    private function usuarioId(Request $request): int
    {
        return (int)$request->getAttribute('usuario')['id'];
    }

    private function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
