<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\AcumulacionVacaciones;
use App\Repositories\DashboardRepository;
use App\Repositories\SolicitudesRepository;
use App\Services\DashboardService;
use App\Services\SaldoVacacionesService;
use PHPUnit\Framework\TestCase;
use Tests\Support\DiasHabilesStub;

final class DashboardServiceTest extends TestCase
{
    private function service(?array $empleado, float $comprometidos = 0.0): DashboardService
    {
        $repo = $this->createMock(DashboardRepository::class);
        $repo->method('kpisEmpleado')->willReturn(['ultimo_salario' => null, 'colillas' => 0]);
        $repo->method('empleadoPorUsuario')->willReturn($empleado);

        $solicitudesRepo = $this->createMock(SolicitudesRepository::class);
        $solicitudesRepo->method('vacacionesComprometidas')->willReturn(DiasHabilesStub::comprometidas($comprometidos));

        return new DashboardService($repo, new SaldoVacacionesService(new AcumulacionVacaciones(), $solicitudesRepo, DiasHabilesStub::servicio()));
    }

    public function testSinEmpleadoVinculadoLosDiasDeVacacionesSonCero(): void
    {
        self::assertSame(0.0, $this->service(null)->kpisEmpleado(7)['dias_vacaciones']);
    }

    public function testDiasDeVacacionesSonLosGanadosMenosLosSolicitados(): void
    {
        $empleado = ['id_empleado' => 3, 'fecha_ingreso' => '2000-01-01'];
        $con      = $this->service($empleado, comprometidos: 4.0)->kpisEmpleado(7)['dias_vacaciones'];
        $sin      = $this->service($empleado)->kpisEmpleado(7)['dias_vacaciones'];

        self::assertSame($sin - 4.0, $con);
    }

    public function testContratosPorAtenderBuscaHastaTreintaDiasYCalculaLosDias(): void
    {
        $repo = $this->createMock(DashboardRepository::class);
        $repo->expects(self::once())->method('contratosConVencimientoHasta')
            ->with('2026-11-25')
            ->willReturn([
                ['id_contrato' => 4, 'nombre' => 'Carlos', 'apellidos' => 'Jiménez', 'tipo_contrato' => 'plazo_fijo', 'fecha_fin' => '2026-10-15'],
                ['id_contrato' => 8, 'nombre' => 'Ana', 'apellidos' => 'Solís', 'tipo_contrato' => 'obra_determinada', 'fecha_fin' => '2026-10-26'],
                ['id_contrato' => 9, 'nombre' => 'Luis', 'apellidos' => 'Mora', 'tipo_contrato' => 'plazo_fijo', 'fecha_fin' => '2026-11-10'],
            ]);
        $service = new DashboardService($repo, $this->createMock(SaldoVacacionesService::class));

        $filas = $service->contratosPorAtender(new \DateTimeImmutable('2026-10-26'));

        self::assertSame([-11, 0, 15], array_column($filas, 'dias'));
        self::assertSame([true, false, false], array_column($filas, 'vencido'));
    }

    public function testNuncaMuestraUnSaldoNegativo(): void
    {
        $empleado = ['id_empleado' => 3, 'fecha_ingreso' => date('Y-m-d')];
        self::assertSame(0.0, $this->service($empleado, comprometidos: 3.0)->kpisEmpleado(7)['dias_vacaciones']);
    }
}
