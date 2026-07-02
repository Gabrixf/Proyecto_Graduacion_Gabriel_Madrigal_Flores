<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class AuthController
{
    public function __construct(
        private readonly Twig        $twig,
        private readonly AuthService $authService
    ) {}

    public function showLogin(Request $request, Response $response): Response
    {
        if (!empty($_SESSION['usuario_id'])) {
            return $this->redirect($request, $response, 'dashboard');
        }

        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        return $this->twig->render($response, 'auth/login.html.twig', [
            'titulo'     => 'Iniciar Sesión',
            'flashError' => $flashError,
        ]);
    }

    public function login(Request $request, Response $response): Response
    {
        $body     = (array)$request->getParsedBody();
        $usuario  = trim($body['nombre_usuario'] ?? '');
        $password = trim($body['contrasena'] ?? '');
        $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $data = $this->authService->verificarCredenciales($usuario, $password, $ip);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $this->redirect($request, $response, 'auth.login');
        }

        $_SESSION['usuario_id']     = $data['id_usuario'];
        $_SESSION['usuario_nombre'] = $data['nombre_usuario'];
        $_SESSION['usuario_rol']    = $data['rol'];

        return $this->redirect($request, $response, 'dashboard');
    }

    public function logout(Request $request, Response $response): Response
    {
        $userId = (int)($_SESSION['usuario_id'] ?? 0);
        $ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if ($userId > 0) {
            $this->authService->cerrarSesion($userId, $ip);
        }

        session_unset();
        session_destroy();

        return $this->redirect($request, $response, 'auth.login');
    }

    private function urlFor(Request $request, string $routeName): string
    {
        return RouteContext::fromRequest($request)->getRouteParser()->urlFor($routeName);
    }

    private function redirect(Request $request, Response $response, string $routeName, array $routeArgs = []): Response
    {
        return $response->withHeader('Location', $this->urlFor($request, $routeName, $routeArgs))->withStatus(302);
    }
}
