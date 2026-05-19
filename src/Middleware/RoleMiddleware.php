<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * RoleMiddleware (RBAC básico)
 *
 * Verifica que el rol del usuario autenticado coincida con el rol requerido.
 * Debe usarse DESPUÉS de AuthMiddleware (requiere que la sesión ya esté validada).
 *
 * Roles disponibles: 'admin', 'empleado'
 *
 * Uso en routes.php:
 *   ->add(new RoleMiddleware('admin'))->add(new AuthMiddleware())
 */
class RoleMiddleware implements MiddlewareInterface
{
    private string $rolRequerido;

    public function __construct(string $rolRequerido)
    {
        $this->rolRequerido = $rolRequerido;
    }

    public function process(
        ServerRequestInterface  $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {

        $rolUsuario = $_SESSION['usuario_rol'] ?? '';

        if ($rolUsuario !== $this->rolRequerido) {
            // Acceso denegado: redirigir al dashboard con mensaje de error
            $_SESSION['flash_error'] = 'No tiene permisos para acceder a esa sección.';

            $response = new Response();
            return $response
                ->withHeader('Location', '/dashboard')
                ->withStatus(302);
        }

        return $handler->handle($request);
    }
}
