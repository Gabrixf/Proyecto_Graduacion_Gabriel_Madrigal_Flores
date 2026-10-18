<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\NominaCalculadora;
use App\Repositories\AuditoriaRepository;
use App\Repositories\NominasRepository;
use App\Services\NominasService;
use PHPUnit\Framework\TestCase;

final class NominasServiceReporteTest extends TestCase
{
    public function testReporteTotalizaYMarcaBorradores(): void
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('periodo')->willReturn(['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-15']);
        $fila = fn (string $estado, float $bruto, float $obrera, float $renta, float $neto, float $patronal) => [
            'nombre' => 'A', 'apellidos' => 'B', 'cedula' => '1', 'estado' => $estado,
            'salario_base' => $bruto, 'horas_extra' => 0.0, 'otros_ingresos' => 0.0, 'subsidio' => 0.0,
            'salario_bruto' => $bruto, 'ccss_obrera' => $obrera, 'renta' => $renta, 'otras_deducciones' => 0.0,
            'salario_neto' => $neto, 'ccss_patronal' => $patronal,
        ];
        $repo->method('reportePeriodo')->willReturn([
            $fila('pagado', 240000.0, 25992.0, 0.0, 214008.0, 64392.0),
            $fila('borrador', 500000.0, 54150.0, 8200.0, 437650.0, 134150.0),
        ]);

        $r = (new NominasService($repo, $this->createMock(AuditoriaRepository::class), new NominaCalculadora()))->reportePeriodo(12);

        self::assertTrue($r['incluye_borrador']);
        self::assertSame(651658.0, $r['totales']['salario_neto']);
        self::assertSame(80142.0, $r['totales']['ccss_obrera']);
        self::assertSame(8200.0, $r['totales']['renta']);
        self::assertSame(80142.0 + 198542.0, $r['totales']['cargas_ccss']);
    }
}
