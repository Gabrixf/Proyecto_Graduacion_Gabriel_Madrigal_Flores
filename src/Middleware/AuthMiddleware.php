<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Slim\Routing\RouteContext;

/**
 * AuthMiddleware
 *
 * Verifica que exista una sesión activa (usuario autenticado).
 * Si no hay sesión, redirige a /login. Si la hay, adjunta al Request el
 * atributo 'usuario' (['id', 'nombre', 'rol']) para que Controllers y
 * middlewares posteriores (p.ej. RoleMiddleware) no dependan de leer
 * $_SESSION directamente.
 *
 * Uso en routes.php:
 *   ->add(new AuthMiddleware())
 */
class AuthMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface  $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {

        if (empty($_SESSION['usuario_id'])) {
            // Guardar la URL original para redirigir después del login
            $_SESSION['redirect_after_login'] = (string) $request->getUri();

            $routeParser = RouteContext::fromRequest($request)->getRouteParser();
            $response = new Response();
            return $response
                ->withHeader('Location', $routeParser->urlFor('auth.login'))
                ->withStatus(302);
        }

        $usuario = [
            'id'     => (int) $_SESSION['usuario_id'],
            'nombre' => $_SESSION['usuario_nombre'] ?? '',
            'rol'    => $_SESSION['usuario_rol'] ?? '',
        ];

        return $handler->handle($request->withAttribute('usuario', $usuario));
    }
}
