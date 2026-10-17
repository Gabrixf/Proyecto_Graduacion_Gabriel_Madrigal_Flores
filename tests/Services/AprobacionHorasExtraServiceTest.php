<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AsistenciaRepository;
use App\Repositories\AuditoriaRepository;
use App\Repositories\HorasExtraRepository;
use App\Repositories\NominasRepository;
use App\Repositories\PeriodosRepository;
use App\Repositories\SolicitudesRepository;
use App\Services\AprobacionHorasExtraService;
use App\Services\DeteccionHorasExtraService;
use App\Services\NominasService;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AprobacionHorasExtraServiceTest extends TestCase
{
    private SolicitudesRepository&MockObject $sol;
    private AsistenciaRepository&MockObject $asis;
    private HorasExtraRepository&MockObject $he;
    private PeriodosRepository&MockObject $periodos;
    private NominasRepository&MockObject $nomRepo;
    private NominasService&MockObject $nominas;
    private DeteccionHorasExtraService&MockObject $deteccion;

    protected function setUp(): void
    {
        $this->sol       = $this->createMock(SolicitudesRepository::class);
        $this->asis      = $this->createMock(AsistenciaRepository::class);
        $this->he        = $this->createMock(HorasExtraRepository::class);
        $this->periodos  = $this->createMock(PeriodosRepository::class);
        $this->nomRepo   = $this->createMock(NominasRepository::class);
        $this->nominas   = $this->createMock(NominasService::class);
        $this->deteccion = $this->createMock(DeteccionHorasExtraService::class);

        $this->sol->method('findById')->willReturn([
            'id_solicitud' => 70, 'id_empleado' => 5, 'tipo' => 'horas_extra', 'origen' => 'asistencia',
            'estado' => 'pendiente', 'fecha_inicio' => '2026-10-15', 'horas' => '1.67',
        ]);
        $this->asis->method('findByEmpleadoFecha')->willReturn(['hora_entrada' => '07:55:00', 'hora_salida' => '18:40:00']);
        $this->deteccion->method('clasificar')->willReturn([
            'tipo_dia' => 'normal', 'horas_extra' => 1.67, 'factor_extra' => 1.5, 'excede_12h' => false,
            'horas_trabajadas' => 10.75, 'horas_ordinarias' => 9.08, 'horas_dia_especial' => 0.0,
        ]);
        $this->periodos->method('findPorFecha')->willReturn(['id_periodo' => 12, 'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-15', 'estado' => 'abierto']);
        $this->he->method('findParametroId')->willReturn(7);
    }

    private function service(): AprobacionHorasExtraService
    {
        return new AprobacionHorasExtraService(
            $this->sol, $this->asis, $this->he, $this->periodos, $this->nomRepo,
            $this->nominas, $this->deteccion, $this->createMock(AuditoriaRepository::class)
        );
    }

    public function testAprobarSinNominaCreaHorasExtraEnElPeriodoDeLaFecha(): void
    {
        $this->nomRepo->method('findCabeceraEmpleadoPeriodo')->willReturn(null);
        $this->sol->expects(self::once())->method('resolver')->with(70, 'aprobada', 1, null);
        $this->he->expects(self::once())->method('insert')->with([
            'id_solicitud' => 70, 'id_empleado' => 5, 'id_periodo' => 12, 'fecha' => '2026-10-15',
            'cantidad_horas' => 1.0, 'factor_recargo' => 1.5, 'id_parametro' => 7,
        ])->willReturn(40);
        $this->nominas->expects(self::never())->method('agregarHorasExtra');
        $this->service()->aprobar(70, 1.0, 1, 'ip');
    }

    public function testConNominaEnBorradorAgregaLaLinea(): void
    {
        $this->nomRepo->method('findCabeceraEmpleadoPeriodo')->willReturn(['id_nomina' => 88, 'estado' => 'borrador']);
        $this->he->method('insert')->willReturn(40);
        $this->nominas->expects(self::once())->method('agregarHorasExtra')->with(88, '2026-10-15', 1.67, 1.5, false, 1, 'ip');
        $this->service()->aprobar(70, 1.67, 1, 'ip');
    }

    public function testConNominaAprobadaVaAlSiguientePeriodoAbierto(): void
    {
        $this->nomRepo->method('findCabeceraEmpleadoPeriodo')->willReturnMap([
            [5, 12, ['id_nomina' => 88, 'estado' => 'aprobado']],
            [5, 13, ['id_nomina' => 95, 'estado' => 'borrador']],
        ]);
        $this->periodos->method('findSiguienteAbierto')->with('2026-10-15')->willReturn(['id_periodo' => 13]);
        $this->he->expects(self::once())->method('insert')->with(self::callback(fn ($d) => $d['id_periodo'] === 13))->willReturn(40);
        $this->nominas->expects(self::once())->method('agregarHorasExtra')->with(95, '2026-10-15', 1.67, 1.5, true, 1, 'ip');
        $this->service()->aprobar(70, 1.67, 1, 'ip');
    }

    public function testSinPeriodoAbiertoSiguienteNoApruebaNada(): void
    {
        $this->nomRepo->method('findCabeceraEmpleadoPeriodo')->willReturn(['id_nomina' => 88, 'estado' => 'pagado']);
        $this->periodos->method('findSiguienteAbierto')->willReturn(null);
        $this->sol->expects(self::never())->method('resolver');
        $this->he->expects(self::never())->method('insert');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('periodo abierto');
        $this->service()->aprobar(70, 1.67, 1, 'ip');
    }

    public function testNoSePuedeAprobarMasDeLoDetectado(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('1.67');
        $this->service()->aprobar(70, 2.0, 1, 'ip');
    }

    public function testNoSeApruebaUnaYaResuelta(): void
    {
        $sol = $this->createMock(SolicitudesRepository::class);
        $sol->method('findById')->willReturn(['id_solicitud' => 70, 'id_empleado' => 5, 'tipo' => 'horas_extra',
            'origen' => 'asistencia', 'estado' => 'aprobada', 'fecha_inicio' => '2026-10-15', 'horas' => '1.67']);
        $this->sol = $sol;
        $this->expectException(RuntimeException::class);
        $this->service()->aprobar(70, 1.0, 1, 'ip');
    }

    public function testSaltaPeriodosSiguientesConNominaCerrada(): void
    {
        $this->nomRepo->method('findCabeceraEmpleadoPeriodo')->willReturnMap([
            [5, 12, ['id_nomina' => 88, 'estado' => 'aprobado']],
            [5, 13, ['id_nomina' => 95, 'estado' => 'aprobado']],
            [5, 14, ['id_nomina' => 99, 'estado' => 'borrador']],
        ]);
        $this->periodos->method('findSiguienteAbierto')->willReturnMap([
            ['2026-10-15', ['id_periodo' => 13, 'fecha_fin' => '2026-10-31']],
            ['2026-10-31', ['id_periodo' => 14, 'fecha_fin' => '2026-11-15']],
        ]);
        $this->he->expects(self::once())->method('insert')->with(self::callback(fn ($d) => $d['id_periodo'] === 14))->willReturn(40);
        $this->nominas->expects(self::once())->method('agregarHorasExtra')->with(99, '2026-10-15', 1.67, 1.5, true, 1, 'ip');
        $this->service()->aprobar(70, 1.67, 1, 'ip');
    }

    public function testTodosLosSiguientesCerradosNoEscribeNada(): void
    {
        $this->nomRepo->method('findCabeceraEmpleadoPeriodo')->willReturn(['id_nomina' => 88, 'estado' => 'pagado']);
        $this->periodos->method('findSiguienteAbierto')->willReturnMap([
            ['2026-10-15', ['id_periodo' => 13, 'fecha_fin' => '2026-10-31']],
            ['2026-10-31', null],
        ]);
        $this->sol->expects(self::never())->method('resolver');
        $this->he->expects(self::never())->method('insert');
        $this->expectException(RuntimeException::class);
        $this->service()->aprobar(70, 1.67, 1, 'ip');
    }
}
