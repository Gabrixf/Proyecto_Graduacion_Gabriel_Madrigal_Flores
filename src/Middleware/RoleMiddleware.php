<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Slim\Routing\RouteContext;

/**
 * RoleMiddleware (RBAC básico)
 *
 * Verifica que el rol del usuario autenticado coincida con el rol requerido.
 * Debe usarse DESPUÉS de AuthMiddleware (lee el atributo 'usuario' que este
 * adjunta al Request; no la sesión directamente).
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

        $usuario    = $request->getAttribute('usuario', []);
        $rolUsuario = $usuario['rol'] ?? '';

        if ($rolUsuario !== $this->rolRequerido) {
            // Acceso denegado: redirigir al dashboard con mensaje de error
            $_SESSION['flash_error'] = 'No tiene permisos para acceder a esa sección.';

            $routeParser = RouteContext::fromRequest($request)->getRouteParser();
            $response = new Response();
            return $response
                ->withHeader('Location', $routeParser->urlFor('dashboard'))
                ->withStatus(302);
        }

        return $handler->handle($request);
    }
}
