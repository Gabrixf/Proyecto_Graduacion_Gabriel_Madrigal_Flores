<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\CalculadoraNominaPeriodo;
use App\Helpers\NominaCalculadora;
use App\Repositories\AuditoriaRepository;
use App\Repositories\NominasRepository;
use App\Services\DeteccionHorasExtraService;
use App\Services\DiasHabilesService;
use App\Services\NominasService;
use App\Services\ParametrosLegalesService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NominasServiceGeneracionTest extends TestCase
{
    private NominasRepository&MockObject $repo;
    private ParametrosLegalesService&MockObject $parametros;
    private DeteccionHorasExtraService&MockObject $deteccion;
    private DiasHabilesService&MockObject $diasHabiles;
    /** @var list<array<string, mixed>> */
    private array $ingresos = [];
    /** @var list<array<string, mixed>> */
    private array $deducciones = [];

    protected function setUp(): void
    {
        $this->repo        = $this->createMock(NominasRepository::class);
        $this->parametros  = $this->createMock(ParametrosLegalesService::class);
        $this->deteccion   = $this->createMock(DeteccionHorasExtraService::class);
        $this->diasHabiles = $this->createMock(DiasHabilesService::class);
        $this->repo->method('existePeriodo')->willReturn(true);
        $this->parametros->method('tasasCcss')->willReturn(['ccss_obrera' => 0.1083, 'ccss_patronal' => 0.2683]);
        $this->parametros->method('tablaRenta')->willReturn([
            'tramos' => [['desde' => 918000.0, 'tasa' => 0.10], ['desde' => 1347000.0, 'tasa' => 0.15],
                         ['desde' => 2364000.0, 'tasa' => 0.20], ['desde' => 4727000.0, 'tasa' => 0.25]],
            'credito_hijo' => 1710.0, 'credito_conyuge' => 2590.0,
        ]);
        $this->repo->method('insertNominaCompleta')->willReturnCallback(function (array $cab, array $i, array $d) {
            $this->ingresos = $i;
            $this->deducciones = $d;
            return 1;
        });
    }

    private function service(): NominasService
    {
        $calc = new NominaCalculadora();
        return new NominasService($this->repo, $this->createMock(AuditoriaRepository::class), $calc,
            $this->deteccion, $this->parametros, $this->diasHabiles, new CalculadoraNominaPeriodo($calc));
    }

    private function empleado(float $salario = 480000.0, int $hijos = 0, int $conyuge = 0): void
    {
        $this->repo->method('empleadosActivosSinNomina')->willReturn([
            ['id_empleado' => 5, 'salario_mensual' => (string)$salario, 'renta_hijos' => $hijos, 'renta_conyuge' => $conyuge],
        ]);
    }

    private function tipo(string $tipo): array
    {
        return array_values(array_filter(array_merge($this->ingresos, $this->deducciones), fn ($l) => $l['tipo'] === $tipo));
    }

    public function testFeriadoTrabajadoAgregaAdicionalSencilloYCotiza(): void
    {
        $this->repo->method('periodo')->willReturn(['fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-15']);
        $this->empleado();
        $this->repo->method('asistenciasDelPeriodo')->willReturn([
            ['fecha' => '2026-09-15', 'hora_entrada' => '08:00:00', 'hora_salida' => '17:00:00'],
        ]);
        $this->deteccion->method('clasificar')->willReturn([
            'tipo_dia' => 'feriado', 'horas_dia_especial' => 8.0, 'horas_extra' => 0.0, 'factor_extra' => 3.0,
            'excede_12h' => false, 'horas_trabajadas' => 9.0, 'horas_ordinarias' => 9.0,
        ]);
        $this->service()->generarPeriodo(9, 1, 'ip');

        self::assertSame(16000.0, $this->tipo('feriado')[0]['monto']);
        self::assertSame(27724.8, $this->tipo('CCSS_empleado')[0]['monto']);   // (240 000 + 16 000) × 10.83 %
    }

    public function testHorasExtraDeOtraFechaSeMarcanComoPagoPosterior(): void
    {
        $this->repo->method('periodo')->willReturn(['fecha_inicio' => '2026-10-16', 'fecha_fin' => '2026-10-31']);
        $this->empleado();
        $this->repo->method('horasExtraDelPeriodo')->willReturn([['fecha' => '2026-10-14', 'cantidad_horas' => '2.00', 'factor_recargo' => '1.50']]);
        $this->service()->generarPeriodo(10, 1, 'ip');

        self::assertStringContainsString('(pago posterior)', $this->tipo('horas_extra')[0]['descripcion']);
    }

    public function testIncapacidadCcssGeneraRebajoYSubsidio(): void
    {
        $this->repo->method('periodo')->willReturn(['fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-15']);
        $this->empleado();
        $this->repo->method('incapacidadesDelPeriodo')->willReturn([
            ['tipo' => 'CCSS', 'fecha_inicio' => '2026-09-08', 'fecha_fin' => '2026-09-10', 'dias' => 3, 'fin_ccss_anterior' => '0000-00-00'],
        ]);
        $this->service()->generarPeriodo(9, 1, 'ip');

        self::assertSame(192000.0, $this->tipo('salario_base')[0]['monto']);
        self::assertSame(24000.0, $this->tipo('subsidio_incapacidad')[0]['monto']);
    }

    public function testReincapacidadDentroDeTreintaDiasNoTieneSubsidio(): void
    {
        $this->repo->method('periodo')->willReturn(['fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-15']);
        $this->empleado();
        $this->repo->method('incapacidadesDelPeriodo')->willReturn([
            ['tipo' => 'CCSS', 'fecha_inicio' => '2026-09-08', 'fecha_fin' => '2026-09-10', 'dias' => 3, 'fin_ccss_anterior' => '2026-08-20'],
        ]);
        $this->service()->generarPeriodo(9, 1, 'ip');

        self::assertSame([], $this->tipo('subsidio_incapacidad'));
    }

    public function testPermisoSinGoceUsaLosDiasHabilesDelEmpleado(): void
    {
        $this->repo->method('periodo')->willReturn(['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-15']);
        $this->empleado();
        $this->repo->method('permisosSinGoceDelPeriodo')->willReturn([['fecha_inicio' => '2026-10-02', 'fecha_fin' => '2026-10-02']]);
        $this->diasHabiles->expects(self::once())->method('contar')->with('2026-10-02', '2026-10-02', 5)->willReturn(1.0);
        $this->service()->generarPeriodo(12, 1, 'ip');

        self::assertSame(224000.0, $this->tipo('salario_base')[0]['monto']);
    }

    public function testSegundaQuincenaRetieneRentaConLaPrimeraDelMes(): void
    {
        $this->repo->method('periodo')->willReturn(['fecha_inicio' => '2026-09-16', 'fecha_fin' => '2026-09-30']);
        $this->empleado(1000000.0);
        $this->repo->method('lineasPrimeraQuincena')->with(5, '2026-09-01')->willReturn([['tipo' => 'salario_base', 'monto' => '500000.00']]);
        $this->service()->generarPeriodo(11, 1, 'ip');

        self::assertSame(8200.0, $this->tipo('impuesto_renta')[0]['monto']);
    }

    public function testSegundaQuincenaSinPrimeraNoFalla(): void
    {
        $this->repo->method('periodo')->willReturn(['fecha_inicio' => '2026-09-16', 'fecha_fin' => '2026-09-30']);
        $this->empleado(1000000.0);
        $this->repo->method('lineasPrimeraQuincena')->willReturn([]);
        $this->service()->generarPeriodo(11, 1, 'ip');

        self::assertSame([], $this->tipo('impuesto_renta'));   // solo 500 000 en el mes
    }

    public function testSinParametrosVigentesNoSeGeneraNinguna(): void
    {
        $parametros = $this->createMock(ParametrosLegalesService::class);
        $parametros->method('tasasCcss')->willReturn(['ccss_obrera' => 0.1083, 'ccss_patronal' => 0.2683]);
        $parametros->method('tablaRenta')->willThrowException(new RuntimeException('Falta cargar el parámetro legal renta_tramo_1 vigente al 16/01/2027.'));
        $this->parametros = $parametros;
        $this->repo->method('periodo')->willReturn(['fecha_inicio' => '2027-01-16', 'fecha_fin' => '2027-01-31']);
        $this->empleado();
        $this->repo->expects(self::never())->method('insertNominaCompleta');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('renta_tramo_1');
        $this->service()->generarPeriodo(40, 1, 'ip');
    }
}
