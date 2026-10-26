<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\NominaCalculadora;
use App\Repositories\AuditoriaRepository;
use App\Repositories\NominasRepository;
use App\Services\NominasService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NominasServiceDesgloseTest extends TestCase
{
    private function service(?array $nomina): NominasService
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('findByIdConLineas')->willReturn($nomina);
        return new NominasService($repo, $this->createMock(AuditoriaRepository::class), new NominaCalculadora());
    }

    public function testDesgloseSeparaLaCcssPatronalDeLasDeduccionesQueAfectanElNeto(): void
    {
        $d = $this->service([
            'id_nomina' => 5, 'salario_bruto' => 236250.0, 'total_deducciones' => 25585.88, 'salario_neto' => 210664.12,
            'ingresos'    => [
                ['tipo' => 'salario_base', 'descripcion' => 'Salario base', 'monto' => '225000.00'],
                ['tipo' => 'horas_extra',  'descripcion' => 'Horas extra',  'monto' => '11250.00'],
            ],
            'deducciones' => [
                ['tipo' => 'CCSS_empleado',  'descripcion' => 'CCSS obrera',   'monto' => '25585.88'],
                ['tipo' => 'CCSS_patronal',  'descripcion' => 'CCSS patronal', 'monto' => '63385.88'],
                ['tipo' => 'impuesto_renta', 'descripcion' => 'Renta',         'monto' => '0.00'],
            ],
        ])->desglose(5);

        self::assertCount(2, $d['ingresos']);
        self::assertSame(['CCSS_empleado', 'impuesto_renta'], array_column($d['deducciones_neto'], 'tipo'));
        self::assertSame(63385.88, $d['ccss_patronal']);
    }

    public function testDesgloseSinCcssPatronalDaCero(): void
    {
        $d = $this->service(['id_nomina' => 5, 'ingresos' => [], 'deducciones' => []])->desglose(5);

        self::assertSame([], $d['deducciones_neto']);
        self::assertSame(0.0, $d['ccss_patronal']);
    }

    public function testDesgloseDeNominaInexistenteLanza(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service(null)->desglose(99);
    }
}
