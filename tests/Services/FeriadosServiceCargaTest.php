<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\FeriadosLey;
use App\Repositories\AuditoriaRepository;
use App\Repositories\FeriadosRepository;
use App\Services\FeriadosService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FeriadosServiceCargaTest extends TestCase
{
    /** @param string[] $existentes fechas ya registradas */
    private function service(array $existentes, ?FeriadosRepository &$repo = null, ?AuditoriaRepository $auditoria = null): FeriadosService
    {
        $repo = $this->createMock(FeriadosRepository::class);
        $repo->method('findEntre')->willReturn(array_map(static fn (string $f) => ['fecha' => $f], $existentes));
        return new FeriadosService($repo, $auditoria ?? $this->createMock(AuditoriaRepository::class), new FeriadosLey());
    }

    public function testFaltantesSaltaLasFechasQueYaExisten(): void
    {
        $faltantes = $this->service(['2027-01-01', '2027-03-25'])->faltantesDelAnio(2027);

        self::assertCount(10, $faltantes);
        self::assertSame('2027-03-26', $faltantes[0]['fecha']);
    }

    public function testCargarAnioInsertaLasFaltantesYAuditaCadaUna(): void
    {
        $auditoria = $this->createMock(AuditoriaRepository::class);
        $auditoria->expects(self::exactly(11))->method('insert');
        $service = $this->service(['2028-12-25'], $repo, $auditoria);
        $repo->expects(self::exactly(11))->method('insert')->willReturn(1);

        self::assertSame(11, $service->cargarAnio(2028, 1, '127.0.0.1'));
    }

    public function testCargarAnioCompletoLanza(): void
    {
        $todas = array_column((new FeriadosLey())->delAnio(2026), 'fecha');
        $service = $this->service($todas, $repo);
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ya están registrados todos los feriados de 2026');

        $service->cargarAnio(2026, 1, '127.0.0.1');
    }

    public function testAniosCargablesSonElActualYElSiguiente(): void
    {
        self::assertSame([2026, 2027], $this->service([])->aniosCargables(new DateTimeImmutable('2026-10-26')));
    }
}
