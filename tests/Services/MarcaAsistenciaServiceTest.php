<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\CalendarioAsistencia;
use App\Repositories\AsistenciaRepository;
use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\FeriadosRepository;
use App\Repositories\PeriodosRepository;
use App\Services\DeteccionHorasExtraService;
use App\Services\MarcaAsistenciaService;
use DateTimeImmutable;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MarcaAsistenciaServiceTest extends TestCase
{
    private AsistenciaRepository&MockObject $asistencia;
    private PeriodosRepository&MockObject $periodos;
    private EmpleadosRepository&MockObject $empleados;
    private FeriadosRepository&MockObject $feriados;
    private AuditoriaRepository&MockObject $auditoria;
    private DeteccionHorasExtraService&MockObject $deteccion;

    protected function setUp(): void
    {
        $this->asistencia = $this->createMock(AsistenciaRepository::class);
        $this->periodos   = $this->createMock(PeriodosRepository::class);
        $this->empleados  = $this->createMock(EmpleadosRepository::class);
        $this->feriados   = $this->createMock(FeriadosRepository::class);
        $this->auditoria  = $this->createMock(AuditoriaRepository::class);
        $this->deteccion  = $this->createMock(DeteccionHorasExtraService::class);
        $this->empleados->method('findById')->willReturn(
            ['id_empleado' => 5, 'estado' => 'activo', 'fecha_ingreso' => '2019-02-04']
        );
    }

    private function service(): MarcaAsistenciaService
    {
        return new MarcaAsistenciaService(
            $this->asistencia, $this->periodos, $this->empleados,
            $this->feriados, $this->auditoria, new CalendarioAsistencia(), $this->deteccion
        );
    }

    private function ahora(string $cuando = '2026-10-12 07:58:30'): DateTimeImmutable
    {
        return new DateTimeImmutable($cuando);
    }

    /** @return array<string, mixed> */
    private function registro(array $cambios = []): array
    {
        return $cambios + ['id_asistencia' => 30, 'id_empleado' => 5, 'id_periodo' => 9, 'fecha' => '2026-10-12',
                           'hora_entrada' => '07:58:00', 'hora_salida' => '00:00:00', 'horas_trabajadas' => '0.00',
                           'salida_manual' => 0];
    }

    public function testMarcarSalidaRegistraLaDeteccionConLaOpcion(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn($this->registro());
        $this->periodos->method('findById')->willReturn(['id_periodo' => 9, 'estado' => 'abierto']);
        $this->deteccion->expects(self::once())->method('validarJustificacion')->with('horas_extra', 'Moto de cliente');
        $this->deteccion->expects(self::once())->method('registrar')
            ->with(5, '2026-10-12', '07:58:00', '18:40:00', 'horas_extra', 'Moto de cliente', 1, 'ip');
        $this->service()->marcarSalida(5, $this->ahora('2026-10-12 18:40:10'), 1, 'ip', 'horas_extra', 'Moto de cliente');
    }

    public function testJustificacionInvalidaNoGuardaLaSalida(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn($this->registro());
        $this->deteccion->method('validarJustificacion')->willThrowException(new InvalidArgumentException('Cuenta qué hiciste'));
        $this->asistencia->expects(self::never())->method('registrarSalida');
        $this->expectException(InvalidArgumentException::class);
        $this->service()->marcarSalida(5, $this->ahora('2026-10-12 18:40:10'), 1, 'ip', 'horas_extra', '');
    }

    // ── Entrada ──────────────────────────────────────────────

    public function testMarcarEntradaGuardaLaHoraDelServidorEnElPeriodoAbierto(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn(null);
        $this->asistencia->method('findFeriadoIdPorFecha')->willReturn(null);
        $this->periodos->method('findPorFecha')->willReturn(['id_periodo' => 9, 'estado' => 'abierto']);
        $this->periodos->expects(self::never())->method('insert');
        $this->asistencia->expects(self::once())->method('insert')->with([
            'id_empleado' => 5, 'id_periodo' => 9, 'id_feriado' => 1, 'fecha' => '2026-10-12',
            'hora_entrada' => '07:58:00', 'hora_salida' => '00:00:00', 'horas_trabajadas' => 0.0, 'salida_manual' => 0,
        ])->willReturn(30);
        $this->auditoria->expects(self::once())->method('insert')->with('INSERT', 3, 'asistencia', 30, '1.2.3.4');

        $this->service()->marcarEntrada(5, $this->ahora(), 3, '1.2.3.4');
    }

    public function testMarcarEntradaDuplicadaSeRechaza(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn($this->registro());
        $this->asistencia->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ya marcó su entrada hoy a las 07:58.');
        $this->service()->marcarEntrada(5, $this->ahora(), 3, 'ip');
    }

    public function testMarcarEntradaTraduceDuplicadoDeLaBase(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn(null);
        $this->periodos->method('findPorFecha')->willReturn(['id_periodo' => 9, 'estado' => 'abierto']);
        $this->asistencia->method('insert')->willThrowException(new PDOException('Duplicate entry', 23000));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ya marcó su entrada hoy.');
        $this->service()->marcarEntrada(5, $this->ahora(), 3, 'ip');
    }

    public function testMarcarEntradaCreaLaPrimeraQuincena(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn(null);
        $this->periodos->method('findPorFecha')->willReturn(null);
        $this->periodos->method('findOverlapping')->willReturn([]);
        $this->periodos->expects(self::once())->method('insert')->with('2026-10-01', '2026-10-15')->willReturn(12);
        $this->asistencia->expects(self::once())->method('insert')
            ->with(self::callback(fn (array $d) => $d['id_periodo'] === 12))->willReturn(30);

        $this->service()->marcarEntrada(5, $this->ahora(), 3, 'ip');
    }

    public function testMarcarEntradaCreaLaSegundaQuincenaHastaFinDeMes(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn(null);
        $this->periodos->method('findPorFecha')->willReturn(null);
        $this->periodos->method('findOverlapping')->willReturn([]);
        $this->periodos->expects(self::once())->method('insert')->with('2026-02-16', '2026-02-28')->willReturn(13);
        $this->asistencia->method('insert')->willReturn(30);

        $this->service()->marcarEntrada(5, $this->ahora('2026-02-20 08:00:00'), 3, 'ip');
    }

    public function testMarcarEntradaRechazaPeriodoCerrado(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn(null);
        $this->periodos->method('findPorFecha')->willReturn(['id_periodo' => 9, 'estado' => 'cerrado']);
        $this->asistencia->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('El período de pago de hoy ya está cerrado; avise a RRHH.');
        $this->service()->marcarEntrada(5, $this->ahora(), 3, 'ip');
    }

    public function testMarcarEntradaRechazaSiLaQuincenaChocaConOtroPeriodo(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn(null);
        $this->periodos->method('findPorFecha')->willReturn(null);
        $this->periodos->method('findOverlapping')->willReturn([['id_periodo' => 7]]);
        $this->periodos->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No hay un período de pago para hoy; avise a RRHH.');
        $this->service()->marcarEntrada(5, $this->ahora(), 3, 'ip');
    }

    public function testEmpleadoInactivoNoPuedeMarcar(): void
    {
        $empleados = $this->createMock(EmpleadosRepository::class);
        $empleados->method('findById')->willReturn(['id_empleado' => 5, 'estado' => 'inactivo', 'fecha_ingreso' => '2019-02-04']);
        $this->empleados = $empleados;
        $this->asistencia->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Su empleado está inactivo; no puede marcar asistencia.');
        $this->service()->marcarEntrada(5, $this->ahora(), 3, 'ip');
    }

    // ── Salida ───────────────────────────────────────────────

    public function testMarcarSalidaCalculaLasHoras(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn($this->registro());
        $this->periodos->method('findById')->with(9)->willReturn(['id_periodo' => 9, 'estado' => 'abierto']);
        $this->asistencia->expects(self::once())->method('registrarSalida')->with(30, '17:13:00', 9.25, false);
        $this->auditoria->expects(self::once())->method('insert')->with('UPDATE', 3, 'asistencia', 30, 'ip');

        $this->service()->marcarSalida(5, $this->ahora('2026-10-12 17:13:40'), 3, 'ip');
    }

    public function testMarcarSalidaRechazaPeriodoCerrado(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn($this->registro());
        $this->periodos->method('findById')->willReturn(['id_periodo' => 9, 'estado' => 'cerrado']);
        $this->asistencia->expects(self::never())->method('registrarSalida');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ese período ya se cerró; pida a RRHH que lo corrija.');
        $this->service()->marcarSalida(5, $this->ahora('2026-10-12 17:13:40'), 3, 'ip');
    }

    public function testMarcarSalidaSinEntrada(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Primero marque su entrada.');
        $this->service()->marcarSalida(5, $this->ahora(), 3, 'ip');
    }

    public function testMarcarSalidaDosVeces(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn($this->registro(['hora_salida' => '17:00:00']));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ya marcó su salida hoy a las 17:00.');
        $this->service()->marcarSalida(5, $this->ahora(), 3, 'ip');
    }

    public function testMarcarSalidaEnElMismoMinuto(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn($this->registro());
        $this->asistencia->expects(self::never())->method('registrarSalida');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Espere al menos un minuto para marcar la salida.');
        $this->service()->marcarSalida(5, $this->ahora('2026-10-12 07:58:59'), 3, 'ip');
    }

    // ── Corregir salida olvidada ─────────────────────────────

    private function diaOlvidado(array $cambios = []): void
    {
        $this->asistencia->method('findById')->willReturn($this->registro($cambios + ['fecha' => '2026-10-08']));
        $this->periodos->method('findById')->willReturn(['id_periodo' => 9, 'estado' => 'abierto']);
    }

    public function testCorregirSalidaGuardaComoManual(): void
    {
        $this->diaOlvidado();
        $this->asistencia->expects(self::once())->method('registrarSalida')->with(30, '17:00:00', 9.03, true);
        $this->auditoria->expects(self::once())->method('insert')->with('UPDATE', 3, 'asistencia', 30, 'ip');

        $this->service()->corregirSalida(5, 30, '17:00', $this->ahora(), 3, 'ip');
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function correccionesInvalidas(): array
    {
        return [
            'de otro empleado'   => [['id_empleado' => 99], '17:00', 'Registro no encontrado.'],
            'del día de hoy'     => [['fecha' => '2026-10-12'], '17:00', 'La salida de hoy se marca con el botón.'],
            'ya tiene salida'    => [['hora_salida' => '16:00:00'], '17:00', 'Ese día ya tiene salida.'],
            'antes de entrada'   => [[], '07:30', 'La hora de salida debe ser posterior a la entrada (HH:MM).'],
            'formato inválido'   => [[], '25:00', 'La hora de salida debe ser posterior a la entrada (HH:MM).'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('correccionesInvalidas')]
    public function testCorregirSalidaRechaza(array $cambios, string $hora, string $mensaje): void
    {
        $this->diaOlvidado($cambios);
        $this->asistencia->expects(self::never())->method('registrarSalida');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($mensaje);
        $this->service()->corregirSalida(5, 30, $hora, $this->ahora(), 3, 'ip');
    }

    public function testCorregirSalidaRechazaPeriodoCerrado(): void
    {
        $this->asistencia->method('findById')->willReturn($this->registro(['fecha' => '2026-10-08']));
        $this->periodos->method('findById')->willReturn(['id_periodo' => 9, 'estado' => 'cerrado']);
        $this->asistencia->expects(self::never())->method('registrarSalida');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ese período ya se cerró; pida a RRHH que lo corrija.');
        $this->service()->corregirSalida(5, 30, '17:00', $this->ahora(), 3, 'ip');
    }

    public function testCorregirSalidaDeRegistroInexistente(): void
    {
        $this->asistencia->method('findById')->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Registro no encontrado.');
        $this->service()->corregirSalida(5, 999, '17:00', $this->ahora(), 3, 'ip');
    }

    // ── Estado de hoy y vista ────────────────────────────────

    public function testEstadoDeHoy(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturnOnConsecutiveCalls(
            null, $this->registro(), $this->registro(['hora_salida' => '17:00:00'])
        );
        $this->asistencia->method('findSinSalidaAntesDe')->willReturn([$this->registro(['fecha' => '2026-10-08'])]);
        $s = $this->service();

        self::assertSame('sin_entrada', $s->estadoDeHoy(5, $this->ahora())['estado']);
        self::assertSame('con_entrada', $s->estadoDeHoy(5, $this->ahora())['estado']);
        $completo = $s->estadoDeHoy(5, $this->ahora());
        self::assertSame('completo', $completo['estado']);
        self::assertCount(1, $completo['pendientes']);
    }

    public function testPendientesIndicanSiSePuedenCorregir(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn(null);
        $this->asistencia->method('findSinSalidaAntesDe')->willReturn([
            $this->registro(['id_asistencia' => 21, 'fecha' => '2026-10-08', 'periodo_estado' => 'abierto']),
            $this->registro(['id_asistencia' => 22, 'fecha' => '2026-09-29', 'periodo_estado' => 'cerrado']),
        ]);

        $pendientes = $this->service()->estadoDeHoy(5, $this->ahora())['pendientes'];

        self::assertTrue($pendientes[0]['corregible']);
        self::assertFalse($pendientes[1]['corregible']);
    }

    public function testVistaArmaElMesActualConNavegacion(): void
    {
        $this->asistencia->method('findByEmpleadoMes')->with(5, '2026-10-01', '2026-10-31')->willReturn([]);
        $this->asistencia->method('findSinSalidaAntesDe')->willReturn([]);
        $this->feriados->method('findEntre')->willReturn([['fecha' => '2026-10-05', 'nombre' => 'Prueba']]);

        $v = $this->service()->vista(5, null, $this->ahora());

        self::assertSame('2026-10', $v['mes']);
        self::assertSame('Octubre 2026', $v['mesTexto']);
        self::assertSame('2026-09', $v['mesAnterior']);
        self::assertSame('2026-11', $v['mesSiguiente']);
        self::assertNull($v['mesActual']);
        self::assertTrue($v['activo']);
        self::assertArrayHasKey('resumen', $v['calendario']);
    }

    /** @return array<string, array{?string}> */
    public static function mesesInvalidos(): array
    {
        return ['texto' => ['abc'], 'mes 13' => ['2026-13'], 'más de un año adelante' => ['2027-11'], 'antes del ingreso' => ['2019-01']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mesesInvalidos')]
    public function testVistaNormalizaMesInvalido(?string $mes): void
    {
        $this->asistencia->method('findByEmpleadoMes')->willReturn([]);
        $this->asistencia->method('findSinSalidaAntesDe')->willReturn([]);
        $this->feriados->method('findEntre')->willReturn([]);

        self::assertSame('2026-10', $this->service()->vista(5, $mes, $this->ahora())['mes']);
    }

    public function testVistaNoOfreceMesAnteriorAlIngreso(): void
    {
        $empleados = $this->createMock(EmpleadosRepository::class);
        $empleados->method('findById')->willReturn(['id_empleado' => 5, 'estado' => 'activo', 'fecha_ingreso' => '2026-09-15']);
        $this->empleados = $empleados;
        $this->asistencia->method('findByEmpleadoMes')->willReturn([]);
        $this->asistencia->method('findSinSalidaAntesDe')->willReturn([]);
        $this->feriados->method('findEntre')->willReturn([]);

        $v = $this->service()->vista(5, '2026-09', $this->ahora());

        self::assertSame('2026-09', $v['mes']);
        self::assertNull($v['mesAnterior']);
        self::assertSame('2026-10', $v['mesSiguiente']);
    }

    public function testVistaPermiteVerHastaUnAnioAdelante(): void
    {
        $this->asistencia->method('findByEmpleadoMes')->willReturn([]);
        $this->asistencia->method('findSinSalidaAntesDe')->willReturn([]);
        $this->feriados->method('findEntre')->willReturn([]);

        $v = $this->service()->vista(5, '2027-10', $this->ahora());

        self::assertSame('2027-10', $v['mes']);
        self::assertSame('2027-09', $v['mesAnterior']);
        self::assertNull($v['mesSiguiente']);
        self::assertSame('2026-10', $v['mesActual']);
    }
}
