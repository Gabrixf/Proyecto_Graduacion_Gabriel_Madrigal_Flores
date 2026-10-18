<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\MiAsistenciaController;
use App\Services\MarcaAsistenciaService;
use App\Services\PortalService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Routing\RouteContext;
use Slim\Routing\RoutingResults;
use Slim\Views\Twig;

final class MiAsistenciaControllerTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $vista = null;

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    private function request(string $metodo, array $cuerpo = [], array $query = []): ServerRequestInterface
    {
        $parser = $this->createMock(RouteParserInterface::class);
        $parser->method('urlFor')->willReturnCallback(fn (string $nombre) => '/' . $nombre);

        return (new ServerRequestFactory())
            ->createServerRequest($metodo, '/mi-asistencia')
            ->withAttribute('usuario', ['id' => 7, 'nombre' => 'cjimenez', 'rol' => 'empleado'])
            ->withAttribute(RouteContext::ROUTE_PARSER, $parser)
            ->withAttribute(RouteContext::ROUTING_RESULTS, $this->createMock(RoutingResults::class))
            ->withParsedBody($cuerpo)
            ->withQueryParams($query);
    }

    private function controller(?int $idEmpleado, MarcaAsistenciaService $marcas): MiAsistenciaController
    {
        $portal = $this->createMock(PortalService::class);
        $portal->method('idEmpleado')->with(7)->willReturn($idEmpleado);

        $twig = $this->createMock(Twig::class);
        $twig->method('render')->willReturnCallback(function (ResponseInterface $r, string $tpl, array $datos) {
            $this->vista = $datos;
            return $r;
        });

        return new MiAsistenciaController($twig, $portal, $marcas, fn () => new DateTimeImmutable('2026-10-12 08:00:00'));
    }

    public function testIndexSinVinculoNoPideLaVista(): void
    {
        $marcas = $this->createMock(MarcaAsistenciaService::class);
        $marcas->expects(self::never())->method('vista');

        $this->controller(null, $marcas)->index($this->request('GET'), (new ResponseFactory())->createResponse());

        self::assertFalse($this->vista['vinculado']);
    }

    public function testIndexPasaElMesDeLaUrl(): void
    {
        $marcas = $this->createMock(MarcaAsistenciaService::class);
        $marcas->expects(self::once())->method('vista')
            ->with(4, '2026-09', self::isInstanceOf(DateTimeImmutable::class))
            ->willReturn(['mes' => '2026-09']);

        $this->controller(4, $marcas)->index($this->request('GET', [], ['mes' => '2026-09']), (new ResponseFactory())->createResponse());

        self::assertTrue($this->vista['vinculado']);
        self::assertSame('2026-09', $this->vista['mes']);
    }

    public function testEntradaUsaElEmpleadoDelUsuarioYRedirige(): void
    {
        $marcas = $this->createMock(MarcaAsistenciaService::class);
        $marcas->expects(self::once())->method('marcarEntrada')
            ->with(4, new DateTimeImmutable('2026-10-12 08:00:00'), 7, self::isString());

        $resp = $this->controller(4, $marcas)->entrada(
            $this->request('POST', ['id_empleado' => '1']),   // un id en el formulario se ignora
            (new ResponseFactory())->createResponse()
        );

        self::assertSame(302, $resp->getStatusCode());
        self::assertSame('/portal.asistencia', $resp->getHeaderLine('Location'));
        self::assertSame('Entrada registrada a las 08:00.', $_SESSION['flash_success']);
    }

    public function testSalidaConErrorDejaFlashDeError(): void
    {
        $marcas = $this->createMock(MarcaAsistenciaService::class);
        $marcas->method('marcarSalida')->willThrowException(new InvalidArgumentException('Primero marque su entrada.'));

        $resp = $this->controller(4, $marcas)->salida($this->request('POST'), (new ResponseFactory())->createResponse());

        self::assertSame(302, $resp->getStatusCode());
        self::assertSame('Primero marque su entrada.', $_SESSION['flash_error']);
    }

    public function testPostSinVinculoNoMarca(): void
    {
        $marcas = $this->createMock(MarcaAsistenciaService::class);
        $marcas->expects(self::never())->method('marcarEntrada');

        $resp = $this->controller(null, $marcas)->entrada($this->request('POST'), (new ResponseFactory())->createResponse());

        self::assertSame(302, $resp->getStatusCode());
        self::assertSame('Su usuario no está vinculado a un empleado.', $_SESSION['flash_error']);
    }

    public function testCorregirPasaElIdYLaHora(): void
    {
        $marcas = $this->createMock(MarcaAsistenciaService::class);
        $marcas->expects(self::once())->method('corregirSalida')
            ->with(4, 30, '17:05', self::isInstanceOf(DateTimeImmutable::class), 7, self::isString());

        $this->controller(4, $marcas)->corregir(
            $this->request('POST', ['hora_salida' => '17:05']),
            (new ResponseFactory())->createResponse(),
            ['id' => '30']
        );

        self::assertSame('Salida guardada.', $_SESSION['flash_success']);
    }

    public function testSalidaPasaLaOpcionYElMotivoAlService(): void
    {
        $marcas = $this->createMock(MarcaAsistenciaService::class);
        $marcas->expects(self::once())->method('marcarSalida')
            ->with(4, self::anything(), self::anything(), self::anything(), 'horas_extra', 'Moto de cliente');

        $this->controller(4, $marcas)->salida(
            $this->request('POST', ['opcion' => 'horas_extra', 'motivo' => 'Moto de cliente']),
            (new ResponseFactory())->createResponse()
        );
    }

    public function testSalidaSinOpcionUsaDespues(): void
    {
        $marcas = $this->createMock(MarcaAsistenciaService::class);
        $marcas->expects(self::once())->method('marcarSalida')
            ->with(4, self::anything(), self::anything(), self::anything(), 'despues', '');

        $this->controller(4, $marcas)->salida($this->request('POST'), (new ResponseFactory())->createResponse());
    }

    public function testCorregirPasaLaOpcionYElMotivo(): void
    {
        $marcas = $this->createMock(MarcaAsistenciaService::class);
        $marcas->expects(self::once())->method('corregirSalida')
            ->with(4, 30, '19:00', self::anything(), 7, self::anything(), 'no_trabajo', '');

        $this->controller(4, $marcas)->corregir(
            $this->request('POST', ['hora_salida' => '19:00', 'opcion' => 'no_trabajo', 'motivo' => '']),
            (new ResponseFactory())->createResponse(),
            ['id' => '30']
        );
    }
}
