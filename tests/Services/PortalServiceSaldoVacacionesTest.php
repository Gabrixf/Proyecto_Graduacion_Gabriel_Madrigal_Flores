<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\AcumulacionVacaciones;
use App\Repositories\PortalRepository;
use App\Repositories\SolicitudesRepository;
use App\Services\PortalService;
use App\Services\SaldoVacacionesService;
use PHPUnit\Framework\TestCase;
use Tests\Support\DiasHabilesStub;

final class PortalServiceSaldoVacacionesTest extends TestCase
{
    private function service(?array $perfil, float $comprometidos = 0.0): PortalService
    {
        $repo = $this->createMock(PortalRepository::class);
        $repo->method('perfil')->willReturn($perfil);

        $solicitudesRepo = $this->createMock(SolicitudesRepository::class);
        $solicitudesRepo->method('vacacionesComprometidas')->willReturn(DiasHabilesStub::comprometidas($comprometidos));

        return new PortalService($repo, new SaldoVacacionesService(new AcumulacionVacaciones(), $solicitudesRepo, DiasHabilesStub::servicio()));
    }

    public function testSaldoVacacionesDevuelveNullSiElUsuarioNoEstaVinculado(): void
    {
        self::assertNull($this->service(null)->saldoVacaciones(7));
    }

    public function testSaldoVacacionesCalculaPorAntiguedad(): void
    {
        $perfil = ['id_empleado' => 3, 'fecha_ingreso' => '2000-01-01'];
        $saldo  = $this->service($perfil, comprometidos: 5.0)->saldoVacaciones(7);

        self::assertGreaterThan(100.0, $saldo['ganados']);
        self::assertSame($saldo['ganados'] - 5.0, $saldo['disponibles']);
        self::assertFalse($saldo['en_prueba']);
    }

    public function testSaldoRestanteTrasSolicitudNoSeDefineParaSolicitudRechazada(): void
    {
        $perfil = ['id_empleado' => 3, 'fecha_ingreso' => '2000-01-01'];
        $sol    = ['id_solicitud' => 9, 'estado' => 'rechazada', 'fecha_inicio' => '2026-05-04', 'dias' => 2.0];

        self::assertNull($this->service($perfil)->saldoRestanteTrasSolicitud(7, $sol));
    }

    public function testSaldoRestanteTrasSolicitudRestaLosDiasDeEsaSolicitud(): void
    {
        // Ingresó el 01/01/2026; al 04/05/2026 lleva 4 meses completos → 4 días.
        $perfil = ['id_empleado' => 3, 'fecha_ingreso' => '2026-01-01'];
        $sol    = ['id_solicitud' => 9, 'estado' => 'pendiente', 'fecha_inicio' => '2026-05-04', 'dias' => 1.5];

        self::assertSame(2.5, $this->service($perfil)->saldoRestanteTrasSolicitud(7, $sol));
    }

    public function testPuedeSolicitarVacacionesConDiasDisponibles(): void
    {
        $perfil = ['id_empleado' => 3, 'fecha_ingreso' => '2000-01-01'];
        self::assertTrue($this->service($perfil)->puedeSolicitarVacaciones(7));
    }

    public function testNoPuedeSolicitarVacacionesSinDiasDisponibles(): void
    {
        // Ingresó hoy: 0 días ganados.
        $perfil = ['id_empleado' => 3, 'fecha_ingreso' => date('Y-m-d')];
        self::assertFalse($this->service($perfil)->puedeSolicitarVacaciones(7));
    }

    public function testNoPuedeSolicitarVacacionesSiTodoElSaldoYaEstaSolicitado(): void
    {
        $perfil = ['id_empleado' => 3, 'fecha_ingreso' => '2000-01-01'];
        $ganados = $this->service($perfil)->saldoVacaciones(7)['ganados'];

        self::assertFalse($this->service($perfil, comprometidos: $ganados)->puedeSolicitarVacaciones(7));
    }

    public function testQuedaMedioDiaSigueSiendoSuficiente(): void
    {
        $perfil = ['id_empleado' => 3, 'fecha_ingreso' => '2000-01-01'];
        $ganados = $this->service($perfil)->saldoVacaciones(7)['ganados'];

        self::assertTrue($this->service($perfil, comprometidos: $ganados - 0.5)->puedeSolicitarVacaciones(7));
        self::assertFalse($this->service($perfil, comprometidos: $ganados - 0.4)->puedeSolicitarVacaciones(7));
    }

    public function testNoPuedeSolicitarSiElUsuarioNoTieneEmpleado(): void
    {
        self::assertFalse($this->service(null)->puedeSolicitarVacaciones(7));
    }

    public function testSaldoVacacionesPasaLaSolicitudAExcluirAlRepositorio(): void
    {
        $repo = $this->createMock(PortalRepository::class);
        $repo->method('perfil')->willReturn(['id_empleado' => 3, 'fecha_ingreso' => '2000-01-01']);
        $solicitudesRepo = $this->createMock(SolicitudesRepository::class);
        $solicitudesRepo->expects(self::once())->method('vacacionesComprometidas')->with(3, 77)->willReturn([]);

        $service = new PortalService($repo, new SaldoVacacionesService(new AcumulacionVacaciones(), $solicitudesRepo, DiasHabilesStub::servicio()));
        $service->saldoVacaciones(7, 77);
    }
}
