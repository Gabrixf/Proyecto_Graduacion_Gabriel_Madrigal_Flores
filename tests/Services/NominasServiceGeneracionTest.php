<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\NominaCalculadora;
use App\Repositories\AuditoriaRepository;
use App\Repositories\NominasRepository;
use App\Services\DeteccionHorasExtraService;
use App\Services\NominasService;
use PHPUnit\Framework\TestCase;

final class NominasServiceGeneracionTest extends TestCase
{
    public function testFeriadoTrabajadoAgregaAdicionalSencilloYCotiza(): void
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('existePeriodo')->willReturn(true);
        $repo->method('periodo')->willReturn(['fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-15']);
        $repo->method('empleadosActivosSinNomina')->willReturn([['id_empleado' => 5, 'salario_mensual' => '480000']]);
        $repo->method('horasExtraDelPeriodo')->willReturn([]);
        $repo->method('asistenciasDelPeriodo')->willReturn([
            ['fecha' => '2026-09-15', 'hora_entrada' => '08:00:00', 'hora_salida' => '17:00:00'],
        ]);
        $deteccion = $this->createMock(DeteccionHorasExtraService::class);
        $deteccion->method('clasificar')->willReturn([
            'tipo_dia' => 'feriado', 'horas_dia_especial' => 8.0, 'horas_extra' => 0.0, 'factor_extra' => 3.0,
            'excede_12h' => false, 'horas_trabajadas' => 9.0, 'horas_ordinarias' => 9.0,
        ]);

        $ingresos = [];
        $repo->expects(self::once())->method('insertNominaCompleta')
            ->willReturnCallback(function (array $cab, array $i) use (&$ingresos) { $ingresos = $i; return 1; });

        (new NominasService($repo, $this->createMock(AuditoriaRepository::class), new NominaCalculadora(0.1083, 0.2683), $deteccion))
            ->generarPeriodo(9, 1, 'ip');

        $feriado = array_values(array_filter($ingresos, fn ($i) => $i['tipo'] === 'feriado'));
        self::assertCount(1, $feriado);
        self::assertSame(16000.0, $feriado[0]['monto']);              // 8 h × (480 000 / 240) × 1
        self::assertStringContainsString('Feriado trabajado 15/09/2026', $feriado[0]['descripcion']);
    }

    public function testHorasExtraDeOtraFechaSeMarcanComoPagoPosterior(): void
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('existePeriodo')->willReturn(true);
        $repo->method('periodo')->willReturn(['fecha_inicio' => '2026-10-16', 'fecha_fin' => '2026-10-31']);
        $repo->method('empleadosActivosSinNomina')->willReturn([['id_empleado' => 5, 'salario_mensual' => '480000']]);
        $repo->method('horasExtraDelPeriodo')->willReturn([['fecha' => '2026-10-14', 'cantidad_horas' => '2.00', 'factor_recargo' => '1.50']]);
        $repo->method('asistenciasDelPeriodo')->willReturn([]);
        $ingresos = [];
        $repo->method('insertNominaCompleta')->willReturnCallback(function (array $cab, array $i) use (&$ingresos) { $ingresos = $i; return 1; });

        (new NominasService($repo, $this->createMock(AuditoriaRepository::class), new NominaCalculadora(0.1083, 0.2683),
            $this->createMock(DeteccionHorasExtraService::class)))->generarPeriodo(10, 1, 'ip');

        $he = array_values(array_filter($ingresos, fn ($i) => $i['tipo'] === 'horas_extra'));
        self::assertStringContainsString('(pago posterior)', $he[0]['descripcion']);
    }
}
