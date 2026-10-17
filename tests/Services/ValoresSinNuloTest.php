<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\EvaluacionCalculadora;
use App\Helpers\NominaCalculadora;
use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\EvaluacionesRepository;
use App\Repositories\HorasExtraRepository;
use App\Repositories\IncapacidadesRepository;
use App\Repositories\NominasRepository;
use App\Repositories\PeriodosRepository;
use App\Repositories\SolicitudesRepository;
use App\Services\EvaluacionesService;
use App\Services\HorasExtraService;
use App\Services\IncapacidadesService;
use App\Services\NominasService;
use App\Services\SaldoVacacionesService;
use App\Services\SolicitudesService;
use PHPUnit\Framework\TestCase;
use Tests\Support\DiasHabilesStub;

/**
 * El esquema no acepta NULL en ninguna columna (instrucción del tutor, 23/08/2026).
 * Estas pruebas fijan que cada service mande un valor vacío o el centinela, nunca null.
 */
final class ValoresSinNuloTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $fila = null;

    private function capturar(): \Closure
    {
        return function (...$args) {
            foreach ($args as $a) {
                if (is_array($a)) {
                    $this->fila = $a;
                }
            }
            return 1;
        };
    }

    // ── Solicitudes ──────────────────────────────────────────

    private function solicitudes(): SolicitudesService
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->method('insert')->willReturnCallback($this->capturar());
        $empleados = $this->createMock(EmpleadosRepository::class);
        $empleados->method('findById')->willReturn(['id_empleado' => 2, 'estado' => 'activo', 'fecha_ingreso' => '2020-03-01']);

        return new SolicitudesService($repo, $empleados, $this->createMock(AuditoriaRepository::class),
            $this->createMock(SaldoVacacionesService::class), DiasHabilesStub::servicio());
    }

    public function testVacacionesDeDiasCompletosGuardanHorasEnCero(): void
    {
        $this->solicitudes()->crear(['id_empleado' => 2, 'tipo' => 'vacaciones', 'fecha_inicio' => '2030-11-04',
            'fecha_fin' => '2030-11-05', 'motivo' => 'Viaje'], 1, 'ip');

        self::assertSame(0.0, $this->fila['horas']);
    }

    public function testPermisoGuardaHorasEnCero(): void
    {
        $this->solicitudes()->crear(['id_empleado' => 2, 'tipo' => 'permiso', 'fecha_inicio' => '2030-11-04',
            'fecha_fin' => '2030-11-04', 'motivo' => 'Cita'], 1, 'ip');

        self::assertSame(0.0, $this->fila['horas']);
    }

    public function testMedioDiaSigueGuardandoMedioDia(): void
    {
        $this->solicitudes()->crear(['id_empleado' => 2, 'tipo' => 'vacaciones', 'fecha_inicio' => '2030-11-04',
            'fecha_fin' => '2030-11-04', 'medio_dia' => '1'], 1, 'ip');

        self::assertSame(0.5, $this->fila['horas']);
    }

    public function testSolicitudSinMotivoGuardaTextoVacio(): void
    {
        $this->solicitudes()->crear(['id_empleado' => 2, 'tipo' => 'horas_extra', 'fecha_inicio' => '2030-11-04',
            'horas' => '2'], 1, 'ip');

        self::assertSame('', $this->fila['motivo']);
        self::assertNotNull($this->fila['fecha_fin']);
    }

    // ── Horas extra ──────────────────────────────────────────

    private function horasExtra(bool $feriado, ?int $parametro): HorasExtraService
    {
        $repo = $this->createMock(HorasExtraRepository::class);
        $repo->method('existsBySolicitud')->willReturn(false);
        $repo->method('findPeriodoAbiertoPorFecha')->willReturn(['id_periodo' => 3]);
        $repo->method('esFeriado')->willReturn($feriado);
        $repo->method('findParametroId')->willReturnCallback(
            fn (string $clave, string $fecha) => $parametro === null ? null : $parametro + ($clave === 'factor_he_feri' ? 1 : 0)
        );
        $repo->method('insert')->willReturnCallback($this->capturar());
        $solicitudes = $this->createMock(SolicitudesRepository::class);
        $solicitudes->method('findById')->willReturn(['id_solicitud' => 8, 'id_empleado' => 2, 'tipo' => 'horas_extra',
            'estado' => 'aprobada', 'fecha_inicio' => '2026-10-06', 'horas' => '2.00']);

        return new HorasExtraService($repo, $solicitudes, $this->createMock(EmpleadosRepository::class),
            $this->createMock(PeriodosRepository::class), $this->createMock(AuditoriaRepository::class));
    }

    public function testHorasExtraUsanElParametroLegalVigenteDelFactor(): void
    {
        $this->horasExtra(false, 4)->crear(['id_solicitud' => 8, 'cantidad_horas' => '', 'factor_recargo' => ''], 1, 'ip');
        self::assertSame(4, $this->fila['id_parametro']);

        $this->horasExtra(true, 4)->crear(['id_solicitud' => 8, 'cantidad_horas' => '', 'factor_recargo' => ''], 1, 'ip');
        self::assertSame(5, $this->fila['id_parametro']);
    }

    public function testHorasExtraSinParametroCargadoUsanElCentinela(): void
    {
        $this->horasExtra(false, null)->crear(['id_solicitud' => 8, 'cantidad_horas' => '', 'factor_recargo' => ''], 1, 'ip');

        self::assertSame(1, $this->fila['id_parametro']);
    }

    // ── Evaluaciones ─────────────────────────────────────────

    public function testEvaluacionSinObservacionesGuardaTextoVacio(): void
    {
        $repo = $this->createMock(EvaluacionesRepository::class);
        $repo->method('esEmpleadoActivo')->willReturn(true);
        $repo->method('insertConDetalles')->willReturnCallback(function (array $cabecera) {
            $this->fila = $cabecera;
            return 1;
        });
        $criterios = [['criterio' => 'Puntualidad', 'peso' => 50.0], ['criterio' => 'Calidad', 'peso' => 50.0]];
        $svc = new EvaluacionesService($repo, $this->createMock(AuditoriaRepository::class), new EvaluacionCalculadora(), $criterios);

        $svc->crear(['id_empleado' => 2, 'periodo_evaluado' => 2025, 'fecha_evaluacion' => '2026-01-20',
            'criterio' => ['Puntualidad', 'Calidad'], 'peso' => [50, 50], 'puntaje' => [80, 90]], 1, 'ip');

        self::assertSame('', $this->fila['observaciones']);
    }

    // ── Incapacidades ────────────────────────────────────────

    public function testIncapacidadSinDocumentoGuardaTextoVacio(): void
    {
        $repo = $this->createMock(IncapacidadesRepository::class);
        $repo->method('insert')->willReturnCallback($this->capturar());
        $repo->method('findPeriodoAbiertoPorFecha')->willReturn(['id_periodo' => 3]);
        $empleados = $this->createMock(EmpleadosRepository::class);
        $empleados->method('findById')->willReturn(['id_empleado' => 2, 'estado' => 'activo']);
        $svc = new IncapacidadesService($repo, $empleados, $this->createMock(PeriodosRepository::class),
            $this->createMock(AuditoriaRepository::class));

        $svc->crear(['id_empleado' => 2, 'tipo' => 'CCSS', 'fecha_inicio' => '2026-05-04', 'fecha_fin' => '2026-05-05', 'dias' => ''], 1, 'ip');

        self::assertSame('', $this->fila['documento_respaldo']);
    }

    // ── Líneas manuales de nómina ────────────────────────────

    private function nominas(): NominasService
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('findByIdConLineas')->willReturn(['id_nomina' => 2, 'estado' => 'borrador', 'ingresos' => [], 'deducciones' => []]);
        $repo->method('insertIngreso')->willReturnCallback($this->capturar());
        $repo->method('insertDeduccion')->willReturnCallback($this->capturar());

        return new NominasService($repo, $this->createMock(AuditoriaRepository::class), new NominaCalculadora(0.1067, 0.2633));
    }

    public function testIngresoManualSinDescripcionGuardaTextoVacio(): void
    {
        $this->nominas()->agregarIngreso(2, ['tipo' => 'bonificacion', 'monto' => '1000'], 1, 'ip');

        self::assertSame('', $this->fila['descripcion']);
    }

    public function testDeduccionManualGuardaPorcentajeCeroYDescripcionVacia(): void
    {
        $this->nominas()->agregarDeduccion(2, ['tipo' => 'embargo', 'monto' => '1000'], 1, 'ip');

        self::assertSame(0.0, $this->fila['porcentaje']);
        self::assertSame('', $this->fila['descripcion']);
    }
}
