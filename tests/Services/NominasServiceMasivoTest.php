<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\NominaCalculadora;
use App\Repositories\AuditoriaRepository;
use App\Repositories\NominasRepository;
use App\Services\NominasService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NominasServiceMasivoTest extends TestCase
{
    public function testAprobarTodasSoloCambiaLasDeBorradorYAuditaCadaUna(): void
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('idsPorPeriodoYEstado')->with(12, 'borrador')->willReturn([3, 4]);
        $repo->expects(self::once())->method('cambiarEstadoPorPeriodo')->with(12, 'borrador', 'aprobado')->willReturn(2);
        $audit = $this->createMock(AuditoriaRepository::class);
        $audit->expects(self::exactly(2))->method('insert');

        self::assertSame(2, (new NominasService($repo, $audit, new NominaCalculadora()))->aprobarPeriodo(12, 1, 'ip'));
    }

    public function testPagarTodasSoloCambiaLasAprobadas(): void
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('idsPorPeriodoYEstado')->with(12, 'aprobado')->willReturn([3]);
        $repo->expects(self::once())->method('cambiarEstadoPorPeriodo')->with(12, 'aprobado', 'pagado')->willReturn(1);

        self::assertSame(1, (new NominasService($repo, $this->createMock(AuditoriaRepository::class), new NominaCalculadora()))->pagarPeriodo(12, 1, 'ip'));
    }

    public function testSinNominasEnEseEstadoAvisa(): void
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('idsPorPeriodoYEstado')->willReturn([]);
        $repo->expects(self::never())->method('cambiarEstadoPorPeriodo');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No hay nóminas en borrador');
        (new NominasService($repo, $this->createMock(AuditoriaRepository::class), new NominaCalculadora()))->aprobarPeriodo(12, 1, 'ip');
    }
}
