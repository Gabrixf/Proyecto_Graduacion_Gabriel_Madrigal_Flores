<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
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
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
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
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $_SESSION['usuario_id']     = $data['id_usuario'];
        $_SESSION['usuario_nombre'] = $data['nombre_usuario'];
        $_SESSION['usuario_rol']    = $data['rol'];

        return $response->withHeader('Location', '/dashboard')->withStatus(302);
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

        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}
