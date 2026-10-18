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
        $repo->expects(self::once())->method('deleteDeduccionesLegales')->with(3);
        $insertadas = [];
        $repo->method('insertDeduccion')->willReturnCallback(function (int $id, array $d) use (&$insertadas) {
            $insertadas[$d['tipo']] = $d['monto'];
            return 1;
        });

        $svc = new NominasService($repo, $this->createMock(AuditoriaRepository::class), new NominaCalculadora(0.1067, 0.2633));
        $svc->agregarIngreso(3, ['tipo' => 'bonificacion', 'monto' => '10000'], 1, 'ip');

        self::assertSame(22407.0, $insertadas['CCSS_empleado']);   // 210 000 × 10.67 %
    }

    public function testRecalcularConservaElEmbargoYCalculaElNeto(): void
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('findByIdConLineas')->willReturn([
            'id_nomina' => 3, 'estado' => 'borrador',
            'ingresos'    => [['tipo' => 'salario_base', 'monto' => '200000.00'], ['tipo' => 'bonificacion', 'monto' => '10000.00']],
            'deducciones' => [
                ['tipo' => 'CCSS_empleado', 'monto' => '21340.00'], ['tipo' => 'CCSS_patronal', 'monto' => '52660.00'],
                ['tipo' => 'embargo', 'monto' => '5000.00'],
            ],
        ]);
        $repo->expects(self::once())->method('deleteDeduccionesLegales')->with(3);
        $tipos = [];
        $repo->method('insertDeduccion')->willReturnCallback(function (int $id, array $d) use (&$tipos) {
            $tipos[] = $d['tipo'];
            return 1;
        });
        $repo->expects(self::once())->method('updateTotales')->with(3, self::callback(function (array $t) {
            return $t['salario_bruto'] === 210000.0
                && $t['total_deducciones'] === 27407.0              // 22 407 CCSS obrera + 5 000 embargo
                && $t['salario_neto'] === 182593.0;                 // patronal no se descuenta
        }));

        $svc = new NominasService($repo, $this->createMock(AuditoriaRepository::class), new NominaCalculadora(0.1067, 0.2633));
        $svc->agregarIngreso(3, ['tipo' => 'bonificacion', 'monto' => '10000'], 1, 'ip');

        self::assertSame(['CCSS_empleado', 'CCSS_patronal'], $tipos);   // el embargo no se borra ni se reinserta
    }

    public function testElSubsidioNoEntraEnLaBaseDeLaCcssAlRecalcular(): void
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('findByIdConLineas')->willReturn([
            'id_nomina' => 3, 'id_empleado' => 5, 'estado' => 'borrador', 'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-15',
            'ingresos'    => [['tipo' => 'salario_base', 'monto' => '160000.00'], ['tipo' => 'subsidio_incapacidad', 'monto' => '24000.00']],
            'deducciones' => [],
        ]);
        $insertadas = [];
        $repo->method('insertDeduccion')->willReturnCallback(function (int $id, array $d) use (&$insertadas) { $insertadas[$d['tipo']] = $d['monto']; return 1; });
        $parametros = $this->createMock(\App\Services\ParametrosLegalesService::class);
        $parametros->method('tasasCcss')->willReturn(['ccss_obrera' => 0.1083, 'ccss_patronal' => 0.2683]);
        $calc = new NominaCalculadora();
        $svc = new NominasService($repo, $this->createMock(AuditoriaRepository::class), $calc, null, $parametros, null,
            new \App\Helpers\CalculadoraNominaPeriodo($calc));

        $svc->agregarIngreso(3, ['tipo' => 'bonificacion', 'monto' => '1000'], 1, 'ip');

        self::assertSame(17328.0, $insertadas['CCSS_empleado']);   // 160 000 × 10.83 %, sin el subsidio
    }

    public function testEnSegundaQuincenaElRecalculoIncluyeLaRenta(): void
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('findByIdConLineas')->willReturn([
            'id_nomina' => 4, 'id_empleado' => 5, 'estado' => 'borrador', 'fecha_inicio' => '2026-09-16', 'fecha_fin' => '2026-09-30',
            'ingresos' => [['tipo' => 'salario_base', 'monto' => '500000.00']], 'deducciones' => [],
        ]);
        $repo->method('lineasPrimeraQuincena')->willReturn([['tipo' => 'salario_base', 'monto' => '500000.00']]);
        $repo->method('datosRentaEmpleado')->willReturn(['renta_hijos' => 0, 'renta_conyuge' => 0]);
        $insertadas = [];
        $repo->method('insertDeduccion')->willReturnCallback(function (int $id, array $d) use (&$insertadas) { $insertadas[$d['tipo']] = $d['monto']; return 1; });
        $parametros = $this->createMock(\App\Services\ParametrosLegalesService::class);
        $parametros->method('tasasCcss')->willReturn(['ccss_obrera' => 0.1083, 'ccss_patronal' => 0.2683]);
        $parametros->method('tablaRenta')->willReturn([
            'tramos' => [['desde' => 918000.0, 'tasa' => 0.10], ['desde' => 1347000.0, 'tasa' => 0.15],
                         ['desde' => 2364000.0, 'tasa' => 0.20], ['desde' => 4727000.0, 'tasa' => 0.25]],
            'credito_hijo' => 1710.0, 'credito_conyuge' => 2590.0,
        ]);
        $calc = new NominaCalculadora();
        $svc = new NominasService($repo, $this->createMock(AuditoriaRepository::class), $calc, null, $parametros, null,
            new \App\Helpers\CalculadoraNominaPeriodo($calc));

        $svc->agregarIngreso(4, ['tipo' => 'bonificacion', 'monto' => '100000'], 1, 'ip');

        // mes = 500 000 + 500 000 (findByIdConLineas devuelve la nómina antes del ingreso) → 8 200
        self::assertSame(8200.0, $insertadas['impuesto_renta']);
    }

    public function testRegenerarSoloEnBorrador(): void
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('findByIdConLineas')->willReturn(['id_nomina' => 3, 'id_empleado' => 5, 'id_periodo' => 9, 'estado' => 'aprobado', 'ingresos' => [], 'deducciones' => []]);
        $repo->expects(self::never())->method('delete');
        $svc = new NominasService($repo, $this->createMock(AuditoriaRepository::class), new NominaCalculadora());

        $this->expectException(\RuntimeException::class);
        $svc->regenerar(3, 1, 'ip');
    }

    private function repoRegenerar(): NominasRepository
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('findByIdConLineas')->willReturn(['id_nomina' => 3, 'id_empleado' => 5, 'id_periodo' => 9, 'estado' => 'borrador', 'ingresos' => [], 'deducciones' => []]);
        $repo->method('periodo')->willReturn(['fecha_inicio' => '2026-09-16', 'fecha_fin' => '2026-09-30']);
        $repo->method('salarioMensualEmpleado')->willReturn(300000.0);
        $repo->method('datosRentaEmpleado')->willReturn(['renta_hijos' => 0, 'renta_conyuge' => 0]);
        return $repo;
    }

    public function testRegenerarReemplazaEnUnaTransaccionYAudita(): void
    {
        $repo = $this->repoRegenerar();
        $repo->expects(self::once())->method('reemplazarNomina')->with(3, self::anything(), self::anything(), self::anything())->willReturn(77);
        $repo->expects(self::never())->method('delete');
        $auditoria = $this->createMock(AuditoriaRepository::class);
        $auditoria->expects(self::exactly(2))->method('insert');
        $parametros = $this->createMock(\App\Services\ParametrosLegalesService::class);
        $parametros->method('tasasCcss')->willReturn(['ccss_obrera' => 0.1083, 'ccss_patronal' => 0.2683]);
        $parametros->method('tablaRenta')->willReturn(['tramos' => [], 'credito_hijo' => 0.0, 'credito_conyuge' => 0.0]);
        $calc = new NominaCalculadora();
        $svc = new NominasService($repo, $auditoria, $calc, null, $parametros, null, new \App\Helpers\CalculadoraNominaPeriodo($calc));

        self::assertSame(77, $svc->regenerar(3, 1, 'ip'));
    }

    public function testSiReemplazarFallaLaExcepcionSePropagaYNoSeBorraNada(): void
    {
        $repo = $this->repoRegenerar();
        $repo->method('reemplazarNomina')->willThrowException(new \RuntimeException('falló la BD'));
        $repo->expects(self::never())->method('delete');
        $auditoria = $this->createMock(AuditoriaRepository::class);
        $auditoria->expects(self::never())->method('insert');
        $parametros = $this->createMock(\App\Services\ParametrosLegalesService::class);
        $parametros->method('tasasCcss')->willReturn(['ccss_obrera' => 0.1083, 'ccss_patronal' => 0.2683]);
        $parametros->method('tablaRenta')->willReturn(['tramos' => [], 'credito_hijo' => 0.0, 'credito_conyuge' => 0.0]);
        $calc = new NominaCalculadora();
        $svc = new NominasService($repo, $auditoria, $calc, null, $parametros, null, new \App\Helpers\CalculadoraNominaPeriodo($calc));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('falló la BD');
        $svc->regenerar(3, 1, 'ip');
    }

    public function testSiFaltaUnParametroLegalNoSeReemplazaNada(): void
    {
        $repo = $this->repoRegenerar();
        $repo->expects(self::never())->method('reemplazarNomina');
        $repo->expects(self::never())->method('delete');
        $parametros = $this->createMock(\App\Services\ParametrosLegalesService::class);
        $parametros->method('tasasCcss')->willThrowException(new \RuntimeException('Falta el parámetro legal ccss_obrera.'));
        $calc = new NominaCalculadora();
        $svc = new NominasService($repo, $this->createMock(AuditoriaRepository::class), $calc, null, $parametros, null, new \App\Helpers\CalculadoraNominaPeriodo($calc));

        $this->expectException(\RuntimeException::class);
        $svc->regenerar(3, 1, 'ip');
    }
}
