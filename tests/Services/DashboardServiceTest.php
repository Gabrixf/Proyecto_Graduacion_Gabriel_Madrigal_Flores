<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\AcumulacionVacaciones;
use App\Repositories\DashboardRepository;
use App\Repositories\SolicitudesRepository;
use App\Services\DashboardService;
use App\Services\SaldoVacacionesService;
use PHPUnit\Framework\TestCase;

final class DashboardServiceTest extends TestCase
{
    private function service(?array $empleado, float $comprometidos = 0.0): DashboardService
    {
        $repo = $this->createMock(DashboardRepository::class);
        $repo->method('kpisEmpleado')->willReturn(['ultimo_salario' => null, 'colillas' => 0]);
        $repo->method('empleadoPorUsuario')->willReturn($empleado);

        $solicitudesRepo = $this->createMock(SolicitudesRepository::class);
        $solicitudesRepo->method('diasVacacionesComprometidos')->willReturn($comprometidos);

        return new DashboardService($repo, new SaldoVacacionesService(new AcumulacionVacaciones(), $solicitudesRepo));
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

    public function testNuncaMuestraUnSaldoNegativo(): void
    {
        $empleado = ['id_empleado' => 3, 'fecha_ingreso' => date('Y-m-d')];
        self::assertSame(0.0, $this->service($empleado, comprometidos: 3.0)->kpisEmpleado(7)['dias_vacaciones']);
    }
}
