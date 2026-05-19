<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * AuthMiddleware
 *
 * Verifica que exista una sesión activa (usuario autenticado).
 * Si no hay sesión, redirige a /login.
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

            $response = new Response();
            return $response
                ->withHeader('Location', '/login')
                ->withStatus(302);
        }

        return $handler->handle($request);
    }
}
