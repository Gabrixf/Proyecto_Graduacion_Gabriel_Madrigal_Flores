<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\AcumulacionVacaciones;
use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\PeriodosRepository;
use App\Repositories\SolicitudesRepository;
use App\Repositories\VacacionesRepository;
use App\Services\SaldoVacacionesService;
use App\Services\VacacionesService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class VacacionesServiceSaldoTest extends TestCase
{
    private function service(
        VacacionesRepository $repo,
        SolicitudesRepository $solicitudesRepo,
        string $fechaIngreso = '2025-01-05',
        float $comprometidos = 0.0
    ): VacacionesService {
        $empleadosRepo = $this->createMock(EmpleadosRepository::class);
        $empleadosRepo->method('findById')->willReturn(['id_empleado' => 7, 'fecha_ingreso' => $fechaIngreso]);
        $solicitudesRepo->method('diasVacacionesComprometidos')->willReturn($comprometidos);

        return new VacacionesService(
            $repo,
            $solicitudesRepo,
            $empleadosRepo,
            $this->createMock(PeriodosRepository::class),
            $this->createMock(AuditoriaRepository::class),
            new SaldoVacacionesService(new AcumulacionVacaciones(), $solicitudesRepo)
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function solicitudAprobada(array $overrides = []): array
    {
        return array_merge([
            'id_solicitud' => 1,
            'tipo'         => 'vacaciones',
            'estado'       => 'aprobada',
            'fecha_inicio' => '2026-01-05',
            'fecha_fin'    => '2026-01-06',
            'id_empleado'  => 7,
        ], $overrides);
    }

    private function repoListoParaInsertar(): VacacionesRepository
    {
        $repo = $this->createMock(VacacionesRepository::class);
        $repo->method('existsBySolicitud')->willReturn(false);
        $repo->method('findPeriodoAbiertoPorFecha')->willReturn(['id_periodo' => 3]);
        return $repo;
    }

    public function testCrearRechazaSiLaVacacionIniciaEnPeriodoDePrueba(): void
    {
        $solicitudesRepo = $this->createMock(SolicitudesRepository::class);
        $solicitudesRepo->method('findById')->willReturn($this->solicitudAprobada());

        $repo = $this->repoListoParaInsertar();
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('período de prueba hasta el 05/04/2026');

        // Ingresó el 05/01/2026 → la prueba termina el 05/04/2026.
        $this->service($repo, $solicitudesRepo, '2026-01-05')->crear(
            ['id_solicitud' => 1, 'dias_tomados' => 1],
            1,
            '127.0.0.1'
        );
    }

    public function testCrearRechazaCuandoDiasSuperanElSaldoAcumulado(): void
    {
        $solicitudesRepo = $this->createMock(SolicitudesRepository::class);
        $solicitudesRepo->method('findById')->willReturn($this->solicitudAprobada());

        $repo = $this->repoListoParaInsertar();
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Saldo de vacaciones insuficiente');

        // Ingresó el 05/01/2025 → 12 días al 05/01/2026; se piden 13.
        $this->service($repo, $solicitudesRepo, '2025-01-05')->crear(
            ['id_solicitud' => 1, 'dias_tomados' => 13],
            1,
            '127.0.0.1'
        );
    }

    public function testCrearPermiteCuandoHaySaldoSuficiente(): void
    {
        $solicitudesRepo = $this->createMock(SolicitudesRepository::class);
        $solicitudesRepo->method('findById')->willReturn($this->solicitudAprobada());

        $repo = $this->repoListoParaInsertar();
        $repo->expects(self::once())->method('insert')->willReturn(50);

        $id = $this->service($repo, $solicitudesRepo, '2025-01-05')->crear(
            ['id_solicitud' => 1, 'dias_tomados' => 5],
            1,
            '127.0.0.1'
        );

        self::assertSame(50, $id);
    }

    public function testCrearDescuentaLosDiasComprometidosPorOtrasSolicitudes(): void
    {
        $solicitudesRepo = $this->createMock(SolicitudesRepository::class);
        $solicitudesRepo->method('findById')->willReturn($this->solicitudAprobada());

        $repo = $this->repoListoParaInsertar();
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Saldo de vacaciones insuficiente');

        // 12 días ganados, 10 ya comprometidos por otras solicitudes, se piden 5.
        $this->service($repo, $solicitudesRepo, '2025-01-05', comprometidos: 10.0)->crear(
            ['id_solicitud' => 1, 'dias_tomados' => 5],
            1,
            '127.0.0.1'
        );
    }

    public function testActualizarPermiteMismoTotalDeDiasYaRegistrados(): void
    {
        $repo = $this->createMock(VacacionesRepository::class);
        $repo->method('findById')->willReturn([
            'id_vacacion'  => 9,
            'id_solicitud' => 4,
            'id_empleado'  => 7,
            'fecha_inicio' => '2026-01-05',
            'fecha_fin'    => '2026-01-06',
            'dias_tomados' => 5.0,
        ]);
        $repo->expects(self::once())->method('update');

        // 12 días ganados; los 5 de esta solicitud se excluyen del comprometido.
        $this->service($repo, $this->createMock(SolicitudesRepository::class), '2025-01-05')->actualizar(
            9,
            ['dias_tomados' => 5],
            1,
            '127.0.0.1'
        );

        $this->addToAssertionCount(1);
    }

    public function testActualizarRechazaAumentoQueSuperaElSaldo(): void
    {
        $repo = $this->createMock(VacacionesRepository::class);
        $repo->method('findById')->willReturn([
            'id_vacacion'  => 9,
            'id_solicitud' => 4,
            'id_empleado'  => 7,
            'fecha_inicio' => '2026-01-05',
            'fecha_fin'    => '2026-01-06',
            'dias_tomados' => 5.0,
        ]);
        $repo->expects(self::never())->method('update');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Saldo de vacaciones insuficiente');

        $this->service($repo, $this->createMock(SolicitudesRepository::class), '2025-01-05')->actualizar(
            9,
            ['dias_tomados' => 13],
            1,
            '127.0.0.1'
        );
    }
}
