<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\PeriodosRepository;
use App\Services\DeteccionHorasExtraService;
use App\Services\PeriodosService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PeriodosServiceCerrarTest extends TestCase
{
    private PeriodosRepository&MockObject $repo;
    private DeteccionHorasExtraService&MockObject $deteccion;
    private DateTimeImmutable $hoy;

    protected function setUp(): void
    {
        $this->repo      = $this->createMock(PeriodosRepository::class);
        $this->deteccion = $this->createMock(DeteccionHorasExtraService::class);
        $this->hoy       = new DateTimeImmutable('2026-10-20');
    }

    /** @param array<string, mixed> $o */
    private function periodo(array $o = []): array
    {
        return $o + ['id_periodo' => 11, 'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-15', 'estado' => 'abierto'];
    }

    private function service(): PeriodosService
    {
        return new PeriodosService($this->repo, $this->createMock(AuditoriaRepository::class), $this->deteccion);
    }

    public function testListoParaCerrarSoloSiTerminoYTodasLasNominasEstanPagadas(): void
    {
        $this->repo->method('conteoNominas')->willReturnOnConsecutiveCalls(
            ['total' => 6, 'pagadas' => 6],
            ['total' => 6, 'pagadas' => 5],
            ['total' => 0, 'pagadas' => 0],
        );
        $s = $this->service();

        self::assertTrue($s->listoParaCerrar($this->periodo(), $this->hoy));
        self::assertFalse($s->listoParaCerrar($this->periodo(), $this->hoy), 'falta pagar una');
        self::assertFalse($s->listoParaCerrar($this->periodo(), $this->hoy), 'sin nóminas');
        self::assertFalse($s->listoParaCerrar($this->periodo(['fecha_fin' => '2026-10-20']), $this->hoy), 'todavía no terminó');
        self::assertFalse($s->listoParaCerrar($this->periodo(['estado' => 'cerrado']), $this->hoy), 'ya cerrado');
    }

    public function testCerrarCierraRechazaLasPorJustificarYCreaLaQuincenaSiguiente(): void
    {
        $this->repo->method('findById')->willReturn($this->periodo());
        $this->repo->method('conteoNominas')->willReturn(['total' => 6, 'pagadas' => 6]);
        $this->repo->method('findOverlapping')->willReturn([]);
        $this->repo->expects(self::once())->method('update')->with(11, '2026-10-01', '2026-10-15', 'cerrado');
        $this->deteccion->expects(self::once())->method('rechazarSinJustificar')->with('2026-10-01', '2026-10-15', 1, 'ip');
        $this->repo->expects(self::once())->method('insert')->with('2026-10-16', '2026-10-31')->willReturn(34);

        $r = $this->service()->cerrar(11, $this->hoy, 1, 'ip');

        self::assertSame(['siguiente_creado' => true], $r);
    }

    public function testCerrarNoCreaLaSiguienteSiYaExiste(): void
    {
        $this->repo->method('findById')->willReturn($this->periodo());
        $this->repo->method('conteoNominas')->willReturn(['total' => 6, 'pagadas' => 6]);
        $this->repo->method('findOverlapping')->willReturnCallback(
            fn (string $ini) => $ini === '2026-10-16' ? [['id_periodo' => 34]] : []
        );
        $this->repo->expects(self::never())->method('insert');

        self::assertSame(['siguiente_creado' => false], $this->service()->cerrar(11, $this->hoy, 1, 'ip'));
    }

    public function testCerrarRechazaSiQuedanNominasSinPagar(): void
    {
        $this->repo->method('findById')->willReturn($this->periodo());
        $this->repo->method('conteoNominas')->willReturn(['total' => 6, 'pagadas' => 4]);
        $this->repo->expects(self::never())->method('update');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('2 nómina(s) sin pagar');

        $this->service()->cerrar(11, $this->hoy, 1, 'ip');
    }

    public function testCerrarRechazaUnPeriodoQueNoHaTerminado(): void
    {
        $this->repo->method('findById')->willReturn($this->periodo(['fecha_fin' => '2026-10-31']));
        $this->repo->method('conteoNominas')->willReturn(['total' => 6, 'pagadas' => 6]);
        $this->repo->expects(self::never())->method('update');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('todavía no termina');

        $this->service()->cerrar(11, $this->hoy, 1, 'ip');
    }
}
