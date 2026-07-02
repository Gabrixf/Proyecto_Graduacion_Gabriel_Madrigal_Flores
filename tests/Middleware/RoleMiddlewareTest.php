<?php

declare(strict_types=1);

namespace Tests\Middleware;

use App\Middleware\RoleMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class RoleMiddlewareTest extends TestCase
{
    public function testDejaPasarCuandoElRolDelAtributoUsuarioCoincide(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/mantenimientos/puestos')
            ->withAttribute('usuario', ['id' => 1, 'nombre' => 'admin', 'rol' => 'admin']);

        $handlerInvocado = false;
        $handler = new class ($handlerInvocado) implements RequestHandlerInterface {
            public function __construct(private bool &$handlerInvocado) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->handlerInvocado = true;
                return (new ResponseFactory())->createResponse(200);
            }
        };

        $response = (new RoleMiddleware('admin'))->process($request, $handler);

        self::assertTrue($handlerInvocado);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testNoDejaPasarCuandoElRolDelAtributoUsuarioNoCoincide(): void
    {
        // 'empleado' pidiendo una sección exclusiva de 'admin': el atributo
        // ya lo trae RoleMiddleware desde AuthMiddleware, no de $_SESSION.
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/mantenimientos/puestos')
            ->withAttribute('usuario', ['id' => 7, 'nombre' => 'jperez', 'rol' => 'empleado']);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                self::fail('El handler no debía ejecutarse: el rol no coincide.');
            }
        };

        // RoleMiddleware intenta construir la URL del dashboard vía
        // RouteContext al denegar el acceso, lo que exige routing ya resuelto
        // (fuera del alcance de un test unitario); esta prueba solo confirma
        // que el corte ocurre antes de llegar al handler real.
        $this->expectException(\RuntimeException::class);

        (new RoleMiddleware('admin'))->process($request, $handler);
    }
}
