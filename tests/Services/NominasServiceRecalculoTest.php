<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\NominaCalculadora;
use App\Repositories\AuditoriaRepository;
use App\Repositories\NominasRepository;
use App\Services\NominasService;
use PHPUnit\Framework\TestCase;

final class NominasServiceRecalculoTest extends TestCase
{
    public function testAgregarIngresoRecalculaLaCcssSobreElNuevoBruto(): void
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('findByIdConLineas')->willReturn([
            'id_nomina' => 3, 'estado' => 'borrador',
            'ingresos'    => [['tipo' => 'salario_base', 'monto' => '200000.00'], ['tipo' => 'bonificacion', 'monto' => '10000.00']],
            'deducciones' => [['tipo' => 'CCSS_empleado', 'monto' => '21340.00'], ['tipo' => 'CCSS_patronal', 'monto' => '52660.00']],
        ]);
        $repo->expects(self::once())->method('deleteDeduccionesCCSS')->with(3);
        $insertadas = [];
        $repo->method('insertDeduccion')->willReturnCallback(function (int $id, array $d) use (&$insertadas) {
            $insertadas[$d['tipo']] = $d['monto'];
            return 1;
        });

        $svc = new NominasService($repo, $this->createMock(AuditoriaRepository::class), new NominaCalculadora(0.1067, 0.2633));
        $svc->agregarIngreso(3, ['tipo' => 'bonificacion', 'monto' => '10000'], 1, 'ip');

        self::assertSame(22407.0, $insertadas['CCSS_empleado']);   // 210 000 × 10.67 %
    }
}
