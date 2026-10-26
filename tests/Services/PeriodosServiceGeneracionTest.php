<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\PeriodosRepository;
use App\Services\PeriodosService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PeriodosServiceGeneracionTest extends TestCase
{
    /** @var array<int, array{0: string, 1: string}> períodos que "ya existen" en la BD simulada */
    private array $existentes = [];
    private PeriodosRepository&MockObject $repo;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(PeriodosRepository::class);
        $this->repo->method('findOverlapping')->willReturnCallback(fn (string $ini, string $fin) => array_values(array_filter(
            $this->existentes,
            static fn (array $p) => $p[0] <= $fin && $p[1] >= $ini
        )));
        $this->repo->method('findPorFecha')->willReturnCallback(function (string $fecha) {
            foreach ($this->existentes as $p) {
                if ($p[0] <= $fecha && $fecha <= $p[1]) {
                    return ['id_periodo' => 9, 'fecha_inicio' => $p[0], 'fecha_fin' => $p[1], 'estado' => 'abierto'];
                }
            }
            return null;
        });
    }

    private function service(?AuditoriaRepository $auditoria = null): PeriodosService
    {
        return new PeriodosService($this->repo, $auditoria ?? $this->createMock(AuditoriaRepository::class));
    }

    public function testQuincenaDeUnaFecha(): void
    {
        self::assertSame(['2026-10-01', '2026-10-15'], PeriodosService::quincenaDe(new DateTimeImmutable('2026-10-15')));
        self::assertSame(['2026-10-16', '2026-10-31'], PeriodosService::quincenaDe(new DateTimeImmutable('2026-10-26')));
        self::assertSame(['2028-02-16', '2028-02-29'], PeriodosService::quincenaDe(new DateTimeImmutable('2028-02-20')));
    }

    public function testFaltantesDelAnioActualEmpiezanEnLaQuincenaDeHoyYSaltanLasQueExisten(): void
    {
        $this->existentes = [['2026-10-01', '2026-10-15'], ['2026-11-16', '2026-11-30']];

        $faltantes = $this->service()->faltantes(2026, new DateTimeImmutable('2026-10-26'));

        self::assertSame(
            [['2026-10-16', '2026-10-31'], ['2026-11-01', '2026-11-15'], ['2026-12-01', '2026-12-15'], ['2026-12-16', '2026-12-31']],
            array_map(static fn (array $p) => [$p['fecha_inicio'], $p['fecha_fin']], $faltantes)
        );
    }

    public function testFaltantesDelAnioSiguienteSonLas24Quincenas(): void
    {
        $faltantes = $this->service()->faltantes(2027, new DateTimeImmutable('2026-12-20'));

        self::assertCount(24, $faltantes);
        self::assertSame('2027-01-01', $faltantes[0]['fecha_inicio']);
        self::assertSame('2027-02-28', $faltantes[3]['fecha_fin']);
        self::assertSame('2027-12-31', $faltantes[23]['fecha_fin']);
    }

    public function testNoGeneraAniosPasados(): void
    {
        self::assertSame([], $this->service()->faltantes(2025, new DateTimeImmutable('2026-10-26')));
    }

    public function testGenerarAnioInsertaLasFaltantesYAuditaCadaUna(): void
    {
        $this->existentes = [['2026-12-01', '2026-12-15']];
        $insertados = [];
        $this->repo->method('insert')->willReturnCallback(function (string $ini, string $fin) use (&$insertados): int {
            $insertados[] = [$ini, $fin];
            return 100 + count($insertados);
        });
        $auditoria = $this->createMock(AuditoriaRepository::class);
        $auditoria->expects(self::exactly(4))->method('insert');

        $n = $this->service($auditoria)->generarAnio(2026, new DateTimeImmutable('2026-10-26'), 1, '127.0.0.1');

        self::assertSame(4, $n);
        self::assertSame(
            [['2026-10-16', '2026-10-31'], ['2026-11-01', '2026-11-15'], ['2026-11-16', '2026-11-30'], ['2026-12-16', '2026-12-31']],
            $insertados
        );
    }

    public function testGenerarAnioSinFaltantesLanza(): void
    {
        $this->existentes = [['2026-10-16', '2026-10-31'], ['2026-11-01', '2026-12-31']];
        $this->repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ya existen todos los períodos de 2026');

        $this->service()->generarAnio(2026, new DateTimeImmutable('2026-10-26'), 1, '127.0.0.1');
    }

    public function testAnioGenerableIncluyeElSiguienteSoloEnDiciembre(): void
    {
        self::assertSame([2026], $this->service()->aniosGenerables(new DateTimeImmutable('2026-10-26')));
        self::assertSame([2026, 2027], $this->service()->aniosGenerables(new DateTimeImmutable('2026-12-02')));
    }

    public function testAvisoCuandoFaltaLaQuincenaActualOLaSiguiente(): void
    {
        $this->existentes = [['2026-10-01', '2026-10-15']];
        self::assertSame(
            ['falta_actual' => true, 'falta_siguiente' => true, 'anio' => 2026],
            $this->service()->aviso(new DateTimeImmutable('2026-10-26'))
        );

        $this->existentes = [['2026-10-16', '2026-10-31']];
        self::assertSame(
            ['falta_actual' => false, 'falta_siguiente' => true, 'anio' => 2026],
            $this->service()->aviso(new DateTimeImmutable('2026-10-26'))
        );

        $this->existentes = [['2026-12-16', '2026-12-31']];
        self::assertSame(
            ['falta_actual' => false, 'falta_siguiente' => true, 'anio' => 2027],
            $this->service()->aviso(new DateTimeImmutable('2026-12-20'))
        );

        $this->existentes = [['2026-10-16', '2026-10-31'], ['2026-11-01', '2026-11-15']];
        self::assertNull($this->service()->aviso(new DateTimeImmutable('2026-10-26')));
    }
}
