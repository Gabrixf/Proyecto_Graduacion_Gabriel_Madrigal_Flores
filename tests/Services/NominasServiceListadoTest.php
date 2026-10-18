<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\NominaCalculadora;
use App\Repositories\AuditoriaRepository;
use App\Repositories\NominasRepository;
use App\Services\NominasService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class NominasServiceListadoTest extends TestCase
{
    /** Periodos como los devuelve el repositorio: por fecha_inicio DESC. */
    private const PERIODOS = [
        ['id_periodo' => 13, 'fecha_inicio' => '2026-10-16', 'fecha_fin' => '2026-10-31', 'estado' => 'abierto'],
        ['id_periodo' => 12, 'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-15', 'estado' => 'abierto'],
        ['id_periodo' => 11, 'fecha_inicio' => '2026-09-16', 'fecha_fin' => '2026-09-30', 'estado' => 'cerrado'],
    ];

    private NominasRepository $repo;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(NominasRepository::class);
    }

    private function service(): NominasService
    {
        return new NominasService($this->repo, $this->createMock(AuditoriaRepository::class), new NominaCalculadora(0.1067, 0.2633));
    }

    public function testPorDefectoEligeElPeriodoAbiertoQueContieneHoy(): void
    {
        self::assertSame(13, $this->service()->periodoPorDefecto(self::PERIODOS, new DateTimeImmutable('2026-10-20')));
    }

    public function testSinPeriodoParaHoyEligeElAbiertoMasRecienteYaIniciado(): void
    {
        // El 13 todavía no empieza; ninguno contiene la fecha.
        self::assertSame(12, $this->service()->periodoPorDefecto(self::PERIODOS, new DateTimeImmutable('2026-10-15')));
        $sinPeriodoDeHoy = array_slice(self::PERIODOS, 1);
        self::assertSame(12, $this->service()->periodoPorDefecto($sinPeriodoDeHoy, new DateTimeImmutable('2026-10-20')));
    }

    public function testSinAbiertosEligeElMasRecienteYaIniciado(): void
    {
        $cerrados = [self::PERIODOS[2]];
        self::assertSame(11, $this->service()->periodoPorDefecto($cerrados, new DateTimeImmutable('2026-12-01')));
    }

    public function testSinPeriodosDevuelveNull(): void
    {
        self::assertNull($this->service()->periodoPorDefecto([], new DateTimeImmutable('2026-10-20')));
    }

    public function testVistaSinPeriodoEnLaUrlFiltraPorElPeriodoPorDefecto(): void
    {
        $this->repo->method('periodos')->willReturn(self::PERIODOS);
        $this->repo->expects(self::once())->method('findAll')->with(13, null)->willReturn([]);

        $v = $this->service()->vistaListado(null, null, new DateTimeImmutable('2026-10-20'));

        self::assertSame(13, $v['filtroPeriodo']);
        self::assertSame('2026-10-16', $v['periodo']['fecha_inicio']);
    }

    public function testVistaConTodosNoFiltraPorPeriodo(): void
    {
        $this->repo->method('periodos')->willReturn(self::PERIODOS);
        $this->repo->expects(self::once())->method('findAll')->with(null, 'pagado')->willReturn([]);

        $v = $this->service()->vistaListado('todos', 'pagado', new DateTimeImmutable('2026-10-20'));

        self::assertSame('todos', $v['filtroPeriodo']);
        self::assertNull($v['periodo']);
        self::assertSame('pagado', $v['filtroEstado']);
    }

    public function testVistaIgnoraEstadoInvalidoYPeriodoInexistente(): void
    {
        $this->repo->method('periodos')->willReturn(self::PERIODOS);
        $this->repo->expects(self::once())->method('findAll')->with(null, null)->willReturn([]);

        $v = $this->service()->vistaListado('999', 'cualquiera', new DateTimeImmutable('2026-10-20'));

        self::assertSame('todos', $v['filtroPeriodo']);
        self::assertNull($v['filtroEstado']);
    }

    public function testResumenTotalizaBrutoDeduccionesYNeto(): void
    {
        $r = $this->service()->resumen([
            ['salario_bruto' => '250000.00', 'total_deducciones' => '26675.00', 'salario_neto' => '223325.00'],
            ['salario_bruto' => '300000.50', 'total_deducciones' => '32010.05', 'salario_neto' => '267990.45'],
        ]);

        self::assertSame(['cantidad' => 2, 'bruto' => 550000.5, 'deducciones' => 58685.05, 'neto' => 491315.45], $r);
    }

    public function testConteoPeriodoUsaTodasLasNominasDelPeriodoAunConFiltroDeEstado(): void
    {
        $this->repo->method('periodos')->willReturn(self::PERIODOS);
        $this->repo->method('findAll')->willReturnMap([
            [13, 'pagado', []],
            [13, null, [
                ['estado' => 'borrador', 'salario_neto' => '100.50', 'salario_bruto' => '0', 'total_deducciones' => '0'],
                ['estado' => 'borrador', 'salario_neto' => '200.00', 'salario_bruto' => '0', 'total_deducciones' => '0'],
                ['estado' => 'aprobado', 'salario_neto' => '50.00', 'salario_bruto' => '0', 'total_deducciones' => '0'],
            ]],
        ]);

        $v = $this->service()->vistaListado('13', 'pagado', new DateTimeImmutable('2026-10-20'));

        self::assertSame(['cantidad' => 2, 'neto' => 300.5], $v['conteoPeriodo']['borrador']);
        self::assertSame(['cantidad' => 1, 'neto' => 50.0], $v['conteoPeriodo']['aprobado']);
    }

    public function testConteoPeriodoEsNullSinPeriodo(): void
    {
        $this->repo->method('periodos')->willReturn(self::PERIODOS);
        $this->repo->method('findAll')->willReturn([]);

        self::assertNull($this->service()->vistaListado('todos', null, new DateTimeImmutable('2026-10-20'))['conteoPeriodo']);
    }
}
