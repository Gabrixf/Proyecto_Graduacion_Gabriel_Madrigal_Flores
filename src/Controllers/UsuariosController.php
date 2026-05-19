<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\UsuariosService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Views\Twig;

class UsuariosController
{
    public function __construct(
        private readonly Twig            $twig,
        private readonly UsuariosService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'usuarios/index.html.twig', [
            'titulo'       => 'Usuarios del Sistema',
            'usuarios'     => $this->service->listar(),
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'usuarios/form.html.twig', [
            'titulo'  => 'Crear Usuario',
            'accion'  => 'crear',
            'usuario' => [],
            'errores' => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $datos      = (array)$request->getParsedBody();
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Usuario creado exitosamente.';
            return $response->withHeader('Location', '/mantenimientos/usuarios')->withStatus(302);
        } catch (InvalidArgumentException $e) {
            unset($datos['contrasena']);
            return $this->twig->render($response->withStatus(422), 'usuarios/form.html.twig', [
                'titulo'  => 'Crear Usuario',
                'accion'  => 'crear',
                'usuario' => $datos,
                'errores' => [$e->getMessage()],
            ]);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            $usuario = $this->service->obtener((int)$args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Usuario no encontrado.';
            return $response->withHeader('Location', '/mantenimientos/usuarios')->withStatus(302);
        }

        return $this->twig->render($response, 'usuarios/form.html.twig', [
            'titulo'  => 'Editar Usuario',
            'accion'  => 'editar',
            'usuario' => $usuario,
            'errores' => [],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id         = (int)$args['id'];
        $datos      = (array)$request->getParsedBody();
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->actualizar($id, $datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Usuario actualizado correctamente.';
            return $response->withHeader('Location', '/mantenimientos/usuarios')->withStatus(302);
        } catch (InvalidArgumentException $e) {
            return $this->twig->render($response->withStatus(422), 'usuarios/form.html.twig', [
                'titulo'  => 'Editar Usuario',
                'accion'  => 'editar',
                'usuario' => array_merge(['id_usuario' => $id], $datos),
                'errores' => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response->withHeader('Location', '/mantenimientos/usuarios')->withStatus(302);
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->eliminar((int)$args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Usuario eliminado correctamente.';
        } catch (InvalidArgumentException|RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response->withHeader('Location', '/mantenimientos/usuarios')->withStatus(302);
    }

    public function showPasswordReset(Request $request, Response $response, array $args): Response
    {
        try {
            $usuario = $this->service->obtener((int)$args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Usuario no encontrado.';
            return $response->withHeader('Location', '/mantenimientos/usuarios')->withStatus(302);
        }

        return $this->twig->render($response, 'usuarios/password.html.twig', [
            'titulo'  => 'Restablecer Contraseña',
            'usuario' => $usuario,
            'errores' => [],
        ]);
    }

    public function updatePassword(Request $request, Response $response, array $args): Response
    {
        $id         = (int)$args['id'];
        $datos      = (array)$request->getParsedBody();
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->resetearPassword($id, $datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Contraseña restablecida correctamente.';
            return $response->withHeader('Location', '/mantenimientos/usuarios')->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $usuario = $this->service->obtener($id);
            return $this->twig->render($response->withStatus(422), 'usuarios/password.html.twig', [
                'titulo'  => 'Restablecer Contraseña',
                'usuario' => $usuario,
                'errores' => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response->withHeader('Location', '/mantenimientos/usuarios')->withStatus(302);
        }
    }

    private function consumeFlash(string $key): ?string
    {
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }
}
