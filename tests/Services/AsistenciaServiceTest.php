<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AsistenciaRepository;
use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\PeriodosRepository;
use App\Services\AsistenciaService;
use PHPUnit\Framework\TestCase;

final class AsistenciaServiceTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $filaGuardada = null;

    private function service(?int $idFeriado): AsistenciaService
    {
        $repo = $this->createMock(AsistenciaRepository::class);
        $repo->method('findPeriodoAbiertoPorFecha')->willReturn(['id_periodo' => 4]);
        $repo->method('existsByEmpleadoFecha')->willReturn(false);
        $repo->method('findFeriadoIdPorFecha')->willReturn($idFeriado);
        $repo->method('findById')->willReturn(['id_asistencia' => 9]);
        $repo->method('insert')->willReturnCallback(function (array $d) {
            $this->filaGuardada = $d;
            return 9;
        });
        $repo->method('update')->willReturnCallback(function (int $id, array $d) {
            $this->filaGuardada = $d;
        });

        $empleados = $this->createMock(EmpleadosRepository::class);
        $empleados->method('findById')->willReturn(['id_empleado' => 2, 'estado' => 'activo']);

        return new AsistenciaService(
            $repo,
            $empleados,
            $this->createMock(PeriodosRepository::class),
            $this->createMock(AuditoriaRepository::class)
        );
    }

    /** @return array<string, string> */
    private function datos(): array
    {
        return ['id_empleado' => '2', 'fecha' => '2026-05-04', 'hora_entrada' => '08:00', 'hora_salida' => '17:00'];
    }

    public function testCrearEnDiaNormalUsaElFeriadoCentinela(): void
    {
        $this->service(null)->crear($this->datos(), 1, '127.0.0.1');

        self::assertSame(1, $this->filaGuardada['id_feriado']);
    }

    public function testCrearEnFeriadoUsaEseFeriado(): void
    {
        $this->service(5)->crear($this->datos(), 1, '127.0.0.1');

        self::assertSame(5, $this->filaGuardada['id_feriado']);
    }

    public function testActualizarDejaLaSalidaComoRevisadaPorRrhh(): void
    {
        $this->service(null)->actualizar(9, $this->datos(), 1, '127.0.0.1');

        self::assertSame(0, $this->filaGuardada['salida_manual']);
    }
}
