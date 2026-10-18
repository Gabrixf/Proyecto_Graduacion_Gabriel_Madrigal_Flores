<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\PortalController;
use App\Services\DeteccionHorasExtraService;
use App\Services\PortalService;
use App\Services\SolicitudesService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;

final class PortalControllerSolicitudVacacionesTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $vistaRenderizada = null;

    private function request(string $metodo, array $cuerpo): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($metodo, '/mis-solicitudes')
            ->withAttribute('usuario', ['id' => 7, 'nombre' => 'emp', 'rol' => 'empleado'])
            ->withParsedBody($cuerpo);
    }

    private function controller(PortalService $portal, SolicitudesService $solicitudes): PortalController
    {
        $twig = $this->createMock(Twig::class);
        $twig->method('render')->willReturnCallback(
            function (ResponseInterface $resp, string $tpl, array $datos) {
                $this->vistaRenderizada = $datos;
                return $resp;
            }
        );
        return new PortalController($twig, $portal, $solicitudes, $this->createMock(DeteccionHorasExtraService::class));
    }

    private function portalConEmpleado(): PortalService
    {
        $portal = $this->createMock(PortalService::class);
        $portal->method('idEmpleado')->willReturn(3);
        $portal->method('saldoVacaciones')->willReturn(null);
        return $portal;
    }

    public function testGuardarRechazaVacacionesSiNoHayDiasDisponibles(): void
    {
        $portal = $this->portalConEmpleado();
        $portal->method('puedeSolicitarVacaciones')->willReturn(false);
        $solicitudes = $this->createMock(SolicitudesService::class);
        $solicitudes->expects(self::never())->method('crear');

        $resp = $this->controller($portal, $solicitudes)->guardarSolicitud(
            $this->request('POST', ['tipo' => 'vacaciones', 'fecha_inicio' => '2030-01-02', 'fecha_fin' => '2030-01-03']),
            (new ResponseFactory())->createResponse()
        );

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame(['No tiene días de vacaciones disponibles.'], $this->vistaRenderizada['errores']);
    }

    public function testGuardarNoBloqueaPermisoAunqueNoHayaDiasDeVacaciones(): void
    {
        // Sin días disponibles, pero el tipo no es vacaciones: la solicitud sigue su curso.
        $portal = $this->portalConEmpleado();
        $portal->method('puedeSolicitarVacaciones')->willReturn(false);
        $solicitudes = $this->createMock(SolicitudesService::class);
        $solicitudes->expects(self::once())->method('crear')->willThrowException(new InvalidArgumentException('x'));

        $resp = $this->controller($portal, $solicitudes)->guardarSolicitud(
            $this->request('POST', ['tipo' => 'permiso', 'fecha_inicio' => '2030-01-02']),
            (new ResponseFactory())->createResponse()
        );

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame(['x'], $this->vistaRenderizada['errores']);
    }

    public function testActualizarExcluyeLaPropiaSolicitudAlVerificarDias(): void
    {
        $portal = $this->portalConEmpleado();
        $portal->method('puedeSolicitarVacaciones')->with(7, 12)->willReturn(false);
        $solicitudes = $this->createMock(SolicitudesService::class);
        $solicitudes->expects(self::never())->method('actualizar');

        $resp = $this->controller($portal, $solicitudes)->actualizarSolicitud(
            $this->request('POST', ['tipo' => 'vacaciones']),
            (new ResponseFactory())->createResponse(),
            ['id' => '12']
        );

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame(['No tiene días de vacaciones disponibles.'], $this->vistaRenderizada['errores']);
    }
}
