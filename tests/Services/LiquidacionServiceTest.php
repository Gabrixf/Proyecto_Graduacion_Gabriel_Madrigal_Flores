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

    /**
     * @param array{ingreso?:string, bruto30?:float, bruto157?:float, primer?:?string, ultimo?:?string,
     *              disponibles?:float, sumaAg?:float, ultimoPagado?:?string} $o
     */
    private function servicio(array $o = []): LiquidacionService
    {
        $o += ['ingreso' => '2020-01-01', 'bruto30' => 1800000.0, 'bruto157' => 3000000.0, 'primer' => '2026-01-01',
               'ultimo' => '2026-06-30', 'disponibles' => 10.0, 'sumaAg' => 1950000.0, 'ultimoPagado' => '2026-06-15'];

        $repo = $this->createMock(LiquidacionRepository::class);
        $repo->method('datosEmpleado')->willReturn(['id_empleado' => 7, 'fecha_ingreso' => $o['ingreso'], 'estado' => 'activo', 'salario_base' => 300000]);
        $repo->method('upsert')->willReturnCallback(function (array $d): int { $this->guardado = $d; return 11; });

        $nom = $this->createMock(NominasRepository::class);
        // La ventana del Art. 30 empieza 6 meses antes de la salida; la del Art. 157, 349 días antes.
        $nom->method('brutoEntre')->willReturnCallback(function (int $e, string $desde) use ($o): float {
            $this->desdesBruto[] = $desde;
            return $desde >= '2026-01-01' ? $o['bruto30'] : $o['bruto157'];
        });
        $nom->method('primerInicioNominaEntre')->willReturnCallback(
            fn (int $e, string $desde) => $o['primer'] === null ? null : ($desde >= '2026-01-01' ? $o['primer'] : '2025-07-01'));
        $nom->method('ultimoFinNominaEntre')->willReturn($o['ultimo']);

        $ag = $this->createMock(AguinaldoRepository::class);
        $ag->method('sumaBrutos')->willReturnCallback(function (int $e, string $desde) use ($o): float {
            $this->iniciosAguinaldo[] = $desde;
            return $o['sumaAg'];
        });
        $ag->method('ultimoFinNominaPagadaEntre')->willReturn($o['ultimoPagado']);

        $saldo = $this->createMock(SaldoVacacionesService::class);
        $saldo->method('saldo')->willReturn(['ganados' => 0.0, 'comprometidos' => 0.0, 'disponibles' => $o['disponibles'], 'fin_prueba' => '', 'en_prueba' => false]);

        return new LiquidacionService($repo, $this->createMock(AuditoriaRepository::class), new LiquidacionCalculadora(), $nom, $ag, $saldo);
    }

    private function calcular(LiquidacionService $s, string $motivo = 'despido_sin_causa', string $salida = '2026-07-01'): void
    {
        $s->calcular(['id_empleado' => '7', 'motivo' => $motivo, 'fecha_salida' => $salida], 1, '127.0.0.1');
    }

    public function testBaseDelArticulo30EsElPromedioDeLosUltimosSeisMeses(): void
    {
        $this->calcular($this->servicio());

        // 1 800 000 ÷ 180 días (02/01 al 30/06) × 30 = 300 000
        self::assertEqualsWithDelta(300000.0, $this->guardado['salario_promedio'], 0.01);
        self::assertSame(0, $this->guardado['base_estimada']);
        self::assertEqualsWithDelta(129.0, $this->guardado['dias_cesantia'], 0.001);
        self::assertEqualsWithDelta(1290000.0, $this->guardado['cesantia'], 0.01);
        self::assertEqualsWithDelta(300000.0, $this->guardado['preaviso'], 0.01);
    }

    public function testVacacionesConElSaldoDelSistemaYElMayorValorDiario(): void
    {
        $this->calcular($this->servicio());

        // promedio 157 = 3 000 000 ÷ 349 < 10 000, así que vale salario ÷ 30
        self::assertEqualsWithDelta(10.0, $this->guardado['dias_vacaciones'], 0.001);
        self::assertEqualsWithDelta(100000.0, $this->guardado['vacaciones_pendientes'], 0.01);
    }

    public function testAguinaldoSumaLoPagadoMasLosDiasSinNomina(): void
    {
        $this->calcular($this->servicio());

        // 1 950 000 + 16 días comerciales (16/06 al 01/07) × 10 000 = 2 110 000 ÷ 12
        self::assertSame('2025-12-01', $this->iniciosAguinaldo[0]);
        self::assertEqualsWithDelta(175833.33, $this->guardado['aguinaldo_proporcional'], 0.01);
    }

    public function testSinNominasUsaElSalarioDelPuestoYLoMarca(): void
    {
        $this->calcular($this->servicio(['ingreso' => '2026-03-01', 'bruto30' => 0.0, 'bruto157' => 0.0, 'primer' => null,
                                         'ultimo' => null, 'sumaAg' => 0.0, 'ultimoPagado' => null]), 'renuncia', '2026-08-31');

        self::assertSame('2026-03-01', $this->desdesBruto[0]);   // 31/08 − 6 meses = 28/02, + 1 día
        self::assertSame(1, $this->guardado['base_estimada']);
        self::assertEqualsWithDelta(300000.0, $this->guardado['salario_promedio'], 0.01);
        self::assertEqualsWithDelta(0.0, $this->guardado['preaviso'], 0.001);
        self::assertEqualsWithDelta(0.0, $this->guardado['cesantia'], 0.001);
        // empieza en el ingreso (posterior al 1 de diciembre): 180 días comerciales × 10 000 ÷ 12
        self::assertSame('2026-03-01', $this->iniciosAguinaldo[0]);
        self::assertEqualsWithDelta(150000.0, $this->guardado['aguinaldo_proporcional'], 0.01);
    }

    public function testSaldoNegativoPagaCeroDias(): void
    {
        $this->calcular($this->servicio(['disponibles' => -4.0]));

        self::assertEqualsWithDelta(0.0, $this->guardado['dias_vacaciones'], 0.001);
        self::assertEqualsWithDelta(0.0, $this->guardado['vacaciones_pendientes'], 0.001);
    }

    public function testSalidaEnDiciembreEmpiezaElAguinaldoEseMismoDiciembre(): void
    {
        $this->calcular($this->servicio(['ultimoPagado' => null]), 'renuncia', '2026-12-10');

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

    public function testFormularioOfreceLosSieteMotivosConEtiqueta(): void
    {
        $motivos = $this->servicio()->datosFormulario()['motivos'];
        self::assertCount(7, $motivos);
        self::assertSame('Renuncia justificada (Art. 83)', $motivos['renuncia_justificada']);
        self::assertSame('Muerte del trabajador', $motivos['muerte']);
    }
}
