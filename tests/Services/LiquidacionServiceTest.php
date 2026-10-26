<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\LiquidacionCalculadora;
use App\Repositories\AguinaldoRepository;
use App\Repositories\AuditoriaRepository;
use App\Repositories\LiquidacionRepository;
use App\Repositories\NominasRepository;
use App\Services\LiquidacionService;
use App\Services\SaldoVacacionesService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LiquidacionServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $guardado = [];
    /** @var string[] */
    private array $iniciosAguinaldo = [];
    /** @var string[] */
    private array $desdesBruto = [];
    /** @var string[] */
    private array $desdesUltimoPagado = [];

    /**
     * Cada ventana se contesta explícitamente: la del Art. 30 (6 meses, `v30`) y la del Art. 157
     * (350 días, `v157`); cualquier otro `desde` hace fallar la prueba. Salida por defecto: 30/06/2026.
     *
     * @param array<string, mixed> $o
     */
    private function servicio(array $o = []): LiquidacionService
    {
        $o += ['ingreso' => '2020-01-01', 'v30' => '2025-12-31', 'v157' => '2025-07-16',
               'bruto30' => 1800000.0, 'primer30' => '2026-01-01', 'ultimo30' => '2026-06-30',
               'bruto157' => 3000000.0, 'primer157' => '2025-07-01', 'ultimo157' => '2026-06-30',
               'disponibles' => 10.0, 'sumaAg' => 1950000.0, 'ultimoPagado' => '2026-06-15',
               'contrato' => ['tipo_contrato' => 'plazo_fijo', 'fecha_fin' => '2026-06-30']];

        $repo = $this->createMock(LiquidacionRepository::class);
        $repo->method('datosEmpleado')->willReturn(['id_empleado' => 7, 'fecha_ingreso' => $o['ingreso'], 'estado' => 'activo', 'salario_base' => 300000]);
        $repo->method('contratoVigente')->willReturn($o['contrato']);
        $repo->method('upsert')->willReturnCallback(function (array $d): int { $this->guardado = $d; return 11; });

        $ventana = function (string $desde) use ($o): string {
            if ($desde === $o['v30']) {
                return '30';
            }
            if ($desde === $o['v157']) {
                return '157';
            }
            $this->fail("ventana inesperada desde $desde");
        };

        $nom = $this->createMock(NominasRepository::class);
        $nom->method('brutoEntre')->willReturnCallback(function (int $e, string $desde) use ($o, $ventana): float {
            $this->desdesBruto[] = $desde;
            return $o['bruto' . $ventana($desde)];
        });
        $nom->method('primerInicioNominaEntre')->willReturnCallback(
            fn (int $e, string $desde) => $o['primer' . $ventana($desde)]);
        $nom->method('ultimoFinNominaEntre')->willReturnCallback(
            fn (int $e, string $desde) => $o['ultimo' . $ventana($desde)]);

        $ag = $this->createMock(AguinaldoRepository::class);
        $ag->method('sumaBrutos')->willReturnCallback(function (int $e, string $desde) use ($o): float {
            $this->iniciosAguinaldo[] = $desde;
            return $o['sumaAg'];
        });
        $ag->method('ultimoFinNominaPagadaEntre')->willReturnCallback(function (int $e, string $desde) use ($o) {
            $this->desdesUltimoPagado[] = $desde;
            return $o['ultimoPagado'];
        });

        $saldo = $this->createMock(SaldoVacacionesService::class);
        $saldo->method('saldoAlSalir')->willReturn((float) $o['disponibles']);

        return new LiquidacionService($repo, $this->createMock(AuditoriaRepository::class), new LiquidacionCalculadora(), $nom, $ag, $saldo);
    }

    private function calcular(LiquidacionService $s, string $motivo = 'despido_sin_causa', string $salida = '2026-06-30'): void
    {
        $s->calcular(['id_empleado' => '7', 'motivo' => $motivo, 'fecha_salida' => $salida], 1, '127.0.0.1');
    }

    /** @return array<string, mixed> opciones de un historial sin nóminas con salida el 31/08/2026 */
    private function sinNominas(): array
    {
        return ['v30' => '2026-03-01', 'v157' => '2025-09-16', 'bruto30' => 0.0, 'bruto157' => 0.0,
                'primer30' => null, 'primer157' => null, 'ultimo30' => null, 'ultimo157' => null,
                'sumaAg' => 0.0, 'ultimoPagado' => null];
    }

    public function testBaseDelArticulo30SalePorPeriodosYDiasComerciales(): void
    {
        $this->calcular($this->servicio());

        // 1 800 000 ÷ 180 días comerciales (01/01 al 30/06) × 30 = 300 000: el salario de un historial constante
        self::assertEqualsWithDelta(300000.0, $this->guardado['salario_promedio'], 0.01);
        self::assertSame(0, $this->guardado['base_estimada']);
        // 01/01/2020 al 30/06/2026 = 6 años y 6 meses: 6 × 21,5 = 129 días × 10 000
        self::assertEqualsWithDelta(129.0, $this->guardado['dias_cesantia'], 0.001);
        self::assertEqualsWithDelta(1290000.0, $this->guardado['cesantia'], 0.01);
        self::assertEqualsWithDelta(300000.0, $this->guardado['preaviso'], 0.01);
        // se pidieron las dos ventanas: Art. 30 (31/12/2025) y Art. 157 (16/07/2025)
        self::assertContains('2025-12-31', $this->desdesBruto);
        self::assertContains('2025-07-16', $this->desdesBruto);
    }

    public function testBaseNoDependeDeLosDiasCalendarioDeLaVentana(): void
    {
        // salida 30/09: ventana 31/03 al 30/09, nóminas 01/04 al 30/09 = 183 días calendario, 180 comerciales
        // 1 800 000 ÷ 180 × 30 = 300 000 (con días calendario saldría 295 081,97)
        $this->calcular($this->servicio(['v30' => '2026-03-31', 'v157' => '2025-10-16', 'primer30' => '2026-04-01',
                                         'ultimo30' => '2026-09-30', 'ultimoPagado' => '2026-09-15']), 'despido_sin_causa', '2026-09-30');

        self::assertEqualsWithDelta(300000.0, $this->guardado['salario_promedio'], 0.01);
        self::assertContains('2026-03-31', $this->desdesBruto);
        self::assertContains('2025-10-16', $this->desdesBruto);
    }

    public function testVacacionesConElSaldoDelSistemaYElMayorValorDiario(): void
    {
        $this->calcular($this->servicio());

        // promedio 157 = 3 000 000 ÷ 350 días (16/07/2025 al 30/06/2026) = 8 571,43 < 10 000: vale salario ÷ 30
        self::assertEqualsWithDelta(10.0, $this->guardado['dias_vacaciones'], 0.001);
        self::assertEqualsWithDelta(100000.0, $this->guardado['vacaciones_pendientes'], 0.01);
    }

    public function testAguinaldoSumaLoPagadoMasLosDiasSinNomina(): void
    {
        $this->calcular($this->servicio());

        // 1 950 000 + 15 días comerciales (16/06 al 30/06) × 10 000 = 2 100 000 ÷ 12 = 175 000
        self::assertSame('2025-12-01', $this->iniciosAguinaldo[0]);
        self::assertEqualsWithDelta(175000.0, $this->guardado['aguinaldo_proporcional'], 0.01);
    }

    public function testAguinaldoConIngresoAMitadDeQuincenaConsultaDesdeElPrimeroDeDiciembre(): void
    {
        $this->calcular($this->servicio(['ingreso' => '2026-03-10']));

        // la primera quincena empieza el 01/03, antes del ingreso: se consulta desde el 1 de diciembre
        self::assertSame('2025-12-01', $this->iniciosAguinaldo[0]);
        self::assertSame('2025-12-01', $this->desdesUltimoPagado[0]);
        // 1 950 000 + 15 × 10 000 = 2 100 000 ÷ 12 = 175 000
        self::assertEqualsWithDelta(175000.0, $this->guardado['aguinaldo_proporcional'], 0.01);
    }

    public function testSinNominasUsaElSalarioDelPuestoYLoMarca(): void
    {
        $this->calcular($this->servicio(['ingreso' => '2026-03-01'] + $this->sinNominas()), 'renuncia', '2026-08-31');

        self::assertContains('2026-03-01', $this->desdesBruto);   // 31/08 − 6 meses = 28/02, + 1 día
        self::assertSame(1, $this->guardado['base_estimada']);
        self::assertEqualsWithDelta(300000.0, $this->guardado['salario_promedio'], 0.01);
        self::assertEqualsWithDelta(0.0, $this->guardado['preaviso'], 0.001);
        self::assertEqualsWithDelta(0.0, $this->guardado['cesantia'], 0.001);
        // sin nóminas, los días sin nómina empiezan en el ingreso (01/03): 180 días comerciales × 10 000 ÷ 12
        self::assertSame('2025-12-01', $this->iniciosAguinaldo[0]);
        self::assertEqualsWithDelta(150000.0, $this->guardado['aguinaldo_proporcional'], 0.01);
    }

    public function testSinNominasEIngresoAnteriorADiciembreCuentaDesdeElPrimeroDeDiciembre(): void
    {
        $this->calcular($this->servicio($this->sinNominas()), 'renuncia', '2026-08-31');

        // 01/12/2025 al 31/08/2026 = 9 meses = 270 días comerciales × 10 000 = 2 700 000 ÷ 12 = 225 000
        self::assertEqualsWithDelta(225000.0, $this->guardado['aguinaldo_proporcional'], 0.01);
    }

    public function testSaldoNegativoPagaCeroDias(): void
    {
        $this->calcular($this->servicio(['disponibles' => -4.0]));

        self::assertEqualsWithDelta(0.0, $this->guardado['dias_vacaciones'], 0.001);
        self::assertEqualsWithDelta(0.0, $this->guardado['vacaciones_pendientes'], 0.001);
    }

    public function testSalidaEnDiciembreEmpiezaElAguinaldoEseMismoDiciembre(): void
    {
        // salida 10/12/2026: Art. 30 desde 11/06/2026; Art. 157 desde 26/12/2025
        $this->calcular($this->servicio(['v30' => '2026-06-11', 'v157' => '2025-12-26', 'ultimoPagado' => null]), 'renuncia', '2026-12-10');

        self::assertSame('2026-12-01', $this->iniciosAguinaldo[0]);
    }

    public function testMuerteYRenunciaJustificadaSonMotivosValidos(): void
    {
        $this->calcular($this->servicio(), 'muerte');
        self::assertSame('muerte', $this->guardado['motivo']);
        self::assertEqualsWithDelta(0.0, $this->guardado['preaviso'], 0.001);
        self::assertGreaterThan(0, $this->guardado['cesantia']);

        $this->calcular($this->servicio(), 'renuncia_justificada');
        self::assertGreaterThan(0, $this->guardado['preaviso']);
    }

    public function testMotivoInvalidoSeRechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calcular($this->servicio(), 'abandono');
    }

    public function testFechaDeSalidaQueNoEsUnaFechaExactaSeRechaza(): void
    {
        foreach (['next monday', '2026-02-30', '2026-6-30', '30/06/2026'] as $salida) {
            try {
                $this->calcular($this->servicio(), 'renuncia', $salida);
                self::fail("se aceptó '$salida'");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('La fecha de salida no es válida.', $e->getMessage(), $salida);
            }
        }
    }

    // ── Vencimiento del plazo fijo (Art. 26 y 31) ─────────

    public function testFinDePlazoFijoPagaVacacionesYAguinaldoSinPreavisoNiCesantia(): void
    {
        $this->calcular($this->servicio(), 'fin_plazo_fijo');

        self::assertSame('fin_plazo_fijo', $this->guardado['motivo']);
        self::assertEqualsWithDelta(0.0, $this->guardado['preaviso'], 0.001);
        self::assertEqualsWithDelta(0.0, $this->guardado['cesantia'], 0.001);
        self::assertGreaterThan(0, $this->guardado['vacaciones_pendientes']);
        self::assertGreaterThan(0, $this->guardado['aguinaldo_proporcional']);
    }

    public function testFinDePlazoFijoRechazaSalidaPosteriorAlVencimiento(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Art. 26');

        $this->calcular($this->servicio(['contrato' => ['tipo_contrato' => 'plazo_fijo', 'fecha_fin' => '2026-06-15']]), 'fin_plazo_fijo');
    }

    public function testFinDePlazoFijoRechazaSalidaAnteriorAlVencimiento(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Art. 31');

        $this->calcular($this->servicio(['contrato' => ['tipo_contrato' => 'plazo_fijo', 'fecha_fin' => '2026-07-31']]), 'fin_plazo_fijo');
    }

    public function testFinDePlazoFijoExigeUnContratoVigenteAPlazoFijo(): void
    {
        foreach ([['tipo_contrato' => 'tiempo_indefinido', 'fecha_fin' => '9999-12-31'], null] as $contrato) {
            try {
                $this->calcular($this->servicio(['contrato' => $contrato]), 'fin_plazo_fijo');
                self::fail('se aceptó sin contrato a plazo fijo');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('contrato vigente a plazo fijo', $e->getMessage());
            }
        }
    }

    public function testFormularioOfreceLosOchoMotivosConEtiqueta(): void
    {
        $motivos = $this->servicio()->datosFormulario()['motivos'];
        self::assertCount(8, $motivos);
        self::assertSame('Vencimiento del plazo fijo (Art. 26)', $motivos['fin_plazo_fijo']);
        self::assertSame('Renuncia justificada (Art. 83)', $motivos['renuncia_justificada']);
        self::assertSame('Muerte del trabajador', $motivos['muerte']);
    }
}
