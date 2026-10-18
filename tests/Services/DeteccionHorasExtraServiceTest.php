<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\ClasificadorHoras;
use App\Repositories\AuditoriaRepository;
use App\Repositories\FeriadosRepository;
use App\Repositories\SolicitudesRepository;
use App\Services\DeteccionHorasExtraService;
use App\Services\HorariosService;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DeteccionHorasExtraServiceTest extends TestCase
{
    private SolicitudesRepository&MockObject $sol;
    private HorariosService&MockObject $horarios;
    private FeriadosRepository&MockObject $feriados;

    protected function setUp(): void
    {
        $this->sol      = $this->createMock(SolicitudesRepository::class);
        $this->horarios = $this->createMock(HorariosService::class);
        $this->feriados = $this->createMock(FeriadosRepository::class);
        $this->horarios->method('tieneHorario')->willReturn(true);
        $this->horarios->method('horarioDelDia')->willReturn(['entrada' => '08:00:00', 'salida' => '17:00:00']);
        $this->feriados->method('findEntre')->willReturn([]);
    }

    private function service(): DeteccionHorasExtraService
    {
        return new DeteccionHorasExtraService(
            $this->sol, $this->horarios, $this->feriados, new ClasificadorHoras(1.50, 3.00),
            $this->createMock(AuditoriaRepository::class)
        );
    }

    public function testTrabajeHorasExtraCreaPendienteConElMotivo(): void
    {
        $this->sol->method('findDetectada')->willReturn(null);
        $this->sol->expects(self::once())->method('insertDetectada')
            ->with(5, '2026-10-15', 1.67, 'Terminar la moto de un cliente', 'pendiente')->willReturn(70);
        $this->service()->registrar(5, '2026-10-15', '07:55:00', '18:40:00', 'horas_extra', 'Terminar la moto de un cliente', 1, 'ip');
    }

    public function testJustificarDespuesCreaPorJustificar(): void
    {
        $this->sol->method('findDetectada')->willReturn(null);
        $this->sol->expects(self::once())->method('insertDetectada')
            ->with(5, '2026-10-15', 1.67, '', 'por_justificar')->willReturn(71);
        $this->service()->registrar(5, '2026-10-15', '07:55:00', '18:40:00', 'despues', '', 1, 'ip');
    }

    public function testNoFueTrabajoNoCreaNada(): void
    {
        $this->sol->expects(self::never())->method('insertDetectada');
        $this->service()->registrar(5, '2026-10-15', '07:55:00', '18:40:00', 'no_trabajo', '', 1, 'ip');
    }

    public function testSinHorasExtraNoCreaNada(): void
    {
        $this->sol->expects(self::never())->method('insertDetectada');
        $this->service()->registrar(5, '2026-10-15', '08:00:00', '17:10:00', 'horas_extra', 'x', 1, 'ip');
    }

    public function testSinHorarioAsignadoNoDetecta(): void
    {
        $horarios = $this->createMock(HorariosService::class);
        $horarios->method('tieneHorario')->willReturn(false);
        $this->horarios = $horarios;
        $this->sol->expects(self::never())->method('insertDetectada');
        self::assertNull($this->service()->clasificar(5, '2026-10-15', '08:00:00', '20:00:00'));
        $this->service()->registrar(5, '2026-10-15', '08:00:00', '20:00:00', 'horas_extra', 'x', 1, 'ip');
    }

    public function testNoDuplicaSiYaHayUnaDetectadaEseDia(): void
    {
        $this->sol->method('findDetectada')->willReturn(['id_solicitud' => 70, 'estado' => 'pendiente', 'horas' => '1.00']);
        $this->sol->expects(self::never())->method('insertDetectada');
        $this->sol->expects(self::once())->method('actualizarHoras')->with(70, 1.67);
        $this->service()->registrar(5, '2026-10-15', '07:55:00', '18:40:00', 'horas_extra', 'x', 1, 'ip');
    }

    public function testHorasExtraSinMotivoSeRechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('qué hiciste');
        $this->service()->validarJustificacion('horas_extra', '   ');
    }

    public function testOpcionDesconocidaSeRechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->validarJustificacion('otra', '');
    }

    public function testRecalcularActualizaPendiente(): void
    {
        $this->sol->method('findDetectada')->willReturn(['id_solicitud' => 70, 'estado' => 'por_justificar', 'horas' => '1.67']);
        $this->sol->expects(self::once())->method('actualizarHoras')->with(70, 2.0);
        $this->service()->recalcular(5, '2026-10-15', '08:00:00', '19:00:00', 1, 'ip');
    }

    public function testRecalcularBorraSiYaNoHayExtra(): void
    {
        $this->sol->method('findDetectada')->willReturn(['id_solicitud' => 70, 'estado' => 'pendiente', 'horas' => '1.67']);
        $this->sol->expects(self::once())->method('delete')->with(70);
        $this->service()->recalcular(5, '2026-10-15', '08:00:00', '17:00:00', 1, 'ip');
    }

    public function testRecalcularNoTocaAprobadas(): void
    {
        $this->sol->method('findDetectada')->willReturn(['id_solicitud' => 70, 'estado' => 'aprobada', 'horas' => '1.67']);
        $this->sol->expects(self::never())->method('actualizarHoras');
        $this->sol->expects(self::never())->method('delete');
        $this->service()->recalcular(5, '2026-10-15', '08:00:00', '17:00:00', 1, 'ip');
    }

    public function testRecalcularSinSolicitudCreaPorJustificar(): void
    {
        $this->sol->method('findDetectada')->willReturn(null);
        $this->sol->expects(self::once())->method('insertDetectada')->with(5, '2026-10-15', 2.0, '', 'por_justificar');
        $this->service()->recalcular(5, '2026-10-15', '08:00:00', '19:00:00', 1, 'ip');
    }

    public function testJustificarPasaAPendiente(): void
    {
        $this->sol->method('findById')->with(70, 5)->willReturn(['id_solicitud' => 70, 'estado' => 'por_justificar', 'origen' => 'asistencia']);
        $this->sol->expects(self::once())->method('justificar')->with(70, 'Inventario de repuestos');
        $this->service()->justificar(70, 5, ' Inventario de repuestos ', 1, 'ip');
    }

    public function testRechazarSinJustificarAlCierre(): void
    {
        $this->sol->method('findPorJustificarEntre')->with('2026-10-01', '2026-10-15')
            ->willReturn([['id_solicitud' => 70], ['id_solicitud' => 71]]);
        $this->sol->expects(self::exactly(2))->method('resolver')
            ->with(self::anything(), 'rechazada', 1, 'No se justificó antes del cierre del periodo');
        self::assertSame(2, $this->service()->rechazarSinJustificar('2026-10-01', '2026-10-15', 1, 'ip'));
    }
}
