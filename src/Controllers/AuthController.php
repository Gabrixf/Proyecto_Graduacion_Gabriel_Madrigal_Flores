<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * AuthController
 *
 * Maneja el login y logout.
 * La lógica de autenticación completa se implementa en el módulo Auth/Seguridad.
 *
 * TODO (Módulo 1):
 *  - Inyectar AuthService
 *  - Verificar credenciales contra tabla usuarios
 *  - Registrar LOGIN/LOGOUT en tabla auditoria
 */
class AuthController
{
    public function __construct(private readonly Twig $twig) {}

    /** GET /login */
    public function showLogin(Request $request, Response $response): Response
    {
        // Si ya está autenticado, redirigir al dashboard
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

    /** POST /login */
    public function login(Request $request, Response $response): Response
    {
        // TODO: implementar en Módulo 1 (Auth/Seguridad)
        // Por ahora, acceso de demostración directo
        $body = (array)$request->getParsedBody();
        $user = trim($body['nombre_usuario'] ?? '');
        $pass = trim($body['contrasena']     ?? '');

        // Stub: cualquier credencial entra como admin para desarrollo
        // ⚠ REEMPLAZAR con validación real en Módulo 1
        if ($user !== '' && $pass !== '') {
            $_SESSION['usuario_id']     = 1;
            $_SESSION['usuario_nombre'] = $user;
            $_SESSION['usuario_rol']    = 'admin'; // cambiar según BD

            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $_SESSION['flash_error'] = 'Credenciales incorrectas.';
        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    /** GET /logout */
    public function logout(Request $request, Response $response): Response
    {
        session_unset();
        session_destroy();

        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}
