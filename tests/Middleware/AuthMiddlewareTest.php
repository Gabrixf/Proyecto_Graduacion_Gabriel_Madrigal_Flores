<?php

declare(strict_types=1);

namespace Tests\Middleware;

use App\Middleware\AuthMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AuthMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testAdjuntaElAtributoUsuarioCuandoHaySesionActiva(): void
    {
        $_SESSION['usuario_id']     = '7';
        $_SESSION['usuario_nombre'] = 'jperez';
        $_SESSION['usuario_rol']    = 'empleado';

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/mi-perfil');

        $capturado = null;
        $handler = new class ($capturado) implements RequestHandlerInterface {
            public function __construct(private mixed &$capturado) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->capturado = $request->getAttribute('usuario');
                return (new ResponseFactory())->createResponse(200);
            }
        };

        (new AuthMiddleware())->process($request, $handler);

        self::assertSame(['id' => 7, 'nombre' => 'jperez', 'rol' => 'empleado'], $capturado);
    }

    public function testNoInvocaAlHandlerCuandoNoHaySesionActiva(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/mi-perfil');

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                self::fail('El handler no debía ejecutarse sin sesión activa.');
            }
        };

        // Sin sesión, AuthMiddleware intenta construir la URL de login vía
        // RouteContext, que exige routing ya resuelto (fuera del alcance de
        // un test unitario); esta prueba solo confirma que el corte ocurre
        // antes de llegar al handler real.
        $this->expectException(\RuntimeException::class);

        (new AuthMiddleware())->process($request, $handler);
    }
}
