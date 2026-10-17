<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\CalendarioAsistencia;
use App\Repositories\AsistenciaRepository;
use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\FeriadosRepository;
use App\Repositories\PeriodosRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

/**
 * MarcaAsistenciaService — el empleado marca su entrada y su salida desde el portal.
 * La hora siempre llega como parámetro ($ahora): es la del servidor y nunca la del formulario,
 * salvo en corregirSalida, donde el empleado escribe la salida que olvidó (queda salida_manual = 1).
 */
class MarcaAsistenciaService
{
    /** Cuántos meses hacia adelante se puede navegar el calendario. */
    private const MESES_ADELANTE = 12;

    private const MESES = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                           'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    public function __construct(
        private readonly AsistenciaRepository $repo,
        private readonly PeriodosRepository   $periodosRepo,
        private readonly EmpleadosRepository  $empleadosRepo,
        private readonly FeriadosRepository   $feriadosRepo,
        private readonly AuditoriaRepository  $auditoriaRepo,
        private readonly CalendarioAsistencia $calendario
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function vista(int $idEmpleado, ?string $mes, DateTimeImmutable $ahora): array
    {
        $empleado   = $this->empleado($idEmpleado);
        $ingreso    = new DateTimeImmutable((string)$empleado['fecha_ingreso']);
        $mesActual  = $ahora->format('Y-m');
        $mesIngreso = $ingreso->format('Y-m');
        // Hacia adelante se puede ver hasta un año, para consultar feriados que vienen.
        $mesMaximo  = $ahora->modify('first day of +' . self::MESES_ADELANTE . ' months')->format('Y-m');

        $valido = $mes !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) === 1
            && $mes <= $mesMaximo && $mes >= $mesIngreso;
        $mes = $valido ? $mes : $mesActual;

        $primero = new DateTimeImmutable($mes . '-01');
        $desde   = $primero->format('Y-m-d');
        $hasta   = $primero->format('Y-m-t');

        $feriados = [];
        foreach ($this->feriadosRepo->findEntre($desde, $hasta) as $f) {
            $feriados[(string)$f['fecha']] = (string)$f['nombre'];
        }

        $anterior  = $primero->modify('-1 month')->format('Y-m');
        $siguiente = $primero->modify('+1 month')->format('Y-m');

        return [
            'hoy'          => $this->estadoDeHoy($idEmpleado, $ahora),
            'calendario'   => $this->calendario->construir(
                $mes, $this->repo->findByEmpleadoMes($idEmpleado, $desde, $hasta), $feriados, $ingreso, $ahora
            ),
            'mes'          => $mes,
            'mesTexto'     => self::MESES[(int)$primero->format('n')] . ' ' . $primero->format('Y'),
            'mesAnterior'  => $anterior >= $mesIngreso ? $anterior : null,
            'mesSiguiente' => $siguiente <= $mesMaximo ? $siguiente : null,
            'mesActual'    => $mes !== $mesActual ? $mesActual : null,
            'activo'       => ($empleado['estado'] ?? '') === 'activo',
        ];
    }

    /**
     * @return array{estado: string, registro: array<string, mixed>|null, pendientes: array<int, array<string, mixed>>}
     */
    public function estadoDeHoy(int $idEmpleado, DateTimeImmutable $ahora): array
    {
        $hoy = $ahora->format('Y-m-d');
        $reg = $this->repo->findByEmpleadoFecha($idEmpleado, $hoy);

        return [
            'estado'     => $reg === null ? 'sin_entrada' : (CalendarioAsistencia::tieneSalida($reg) ? 'completo' : 'con_entrada'),
            'registro'   => $reg,
            // Solo se pueden corregir desde el portal los días cuyo período sigue abierto.
            'pendientes' => array_map(
                static fn (array $p): array => $p + ['corregible' => ($p['periodo_estado'] ?? '') === 'abierto'],
                $this->repo->findSinSalidaAntesDe($idEmpleado, $hoy)
            ),
        ];
    }

    public function marcarEntrada(int $idEmpleado, DateTimeImmutable $ahora, int $idUsuario, string $ip): void
    {
        $this->exigirActivo($idEmpleado);
        $hoy = $ahora->format('Y-m-d');

        $existente = $this->repo->findByEmpleadoFecha($idEmpleado, $hoy);
        if ($existente !== null) {
            throw new InvalidArgumentException(
                sprintf('Ya marcó su entrada hoy a las %s.', substr((string)$existente['hora_entrada'], 0, 5))
            );
        }

        $idPeriodo = $this->periodoParaHoy($ahora, $idUsuario, $ip);

        try {
            $id = $this->repo->insert([
                'id_empleado'      => $idEmpleado,
                'id_periodo'       => $idPeriodo,
                'id_feriado'       => $this->repo->findFeriadoIdPorFecha($hoy) ?? 1,
                'fecha'            => $hoy,
                'hora_entrada'     => $ahora->format('H:i:00'),
                'hora_salida'      => '00:00:00',
                'horas_trabajadas' => 0.0,
                'salida_manual'    => 0,
            ]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                throw new InvalidArgumentException('Ya marcó su entrada hoy.');
            }
            throw $e;
        }
        $this->auditoriaRepo->insert('INSERT', $idUsuario, 'asistencia', $id, $ip);
    }

    public function marcarSalida(int $idEmpleado, DateTimeImmutable $ahora, int $idUsuario, string $ip): void
    {
        $this->exigirActivo($idEmpleado);

        $reg = $this->repo->findByEmpleadoFecha($idEmpleado, $ahora->format('Y-m-d'));
        if ($reg === null) {
            throw new InvalidArgumentException('Primero marque su entrada.');
        }
        if (CalendarioAsistencia::tieneSalida($reg)) {
            throw new InvalidArgumentException(
                sprintf('Ya marcó su salida hoy a las %s.', substr((string)$reg['hora_salida'], 0, 5))
            );
        }

        $salida = $ahora->format('H:i:00');
        $horas  = $this->horas((string)$reg['hora_entrada'], $salida);
        if ($horas <= 0) {
            throw new InvalidArgumentException('Espere al menos un minuto para marcar la salida.');
        }
        $this->exigirPeriodoAbierto((int)$reg['id_periodo']);

        $this->repo->registrarSalida((int)$reg['id_asistencia'], $salida, $horas, false);
        $this->auditoriaRepo->insert('UPDATE', $idUsuario, 'asistencia', (int)$reg['id_asistencia'], $ip);
    }

    public function corregirSalida(
        int $idEmpleado,
        int $idAsistencia,
        string $horaSalida,
        DateTimeImmutable $ahora,
        int $idUsuario,
        string $ip
    ): void {
        $this->exigirActivo($idEmpleado);

        $reg = $this->repo->findById($idAsistencia);
        if ($reg === null || (int)$reg['id_empleado'] !== $idEmpleado) {
            throw new InvalidArgumentException('Registro no encontrado.');
        }
        if ((string)$reg['fecha'] >= $ahora->format('Y-m-d')) {
            throw new InvalidArgumentException('La salida de hoy se marca con el botón.');
        }
        if (CalendarioAsistencia::tieneSalida($reg)) {
            throw new InvalidArgumentException('Ese día ya tiene salida.');
        }

        $horaSalida = trim($horaSalida);
        $horas = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaSalida) === 1
            ? $this->horas((string)$reg['hora_entrada'], $horaSalida . ':00')
            : 0.0;
        if ($horas <= 0) {
            throw new InvalidArgumentException('La hora de salida debe ser posterior a la entrada (HH:MM).');
        }

        $this->exigirPeriodoAbierto((int)$reg['id_periodo']);

        $this->repo->registrarSalida($idAsistencia, $horaSalida . ':00', $horas, true);
        $this->auditoriaRepo->insert('UPDATE', $idUsuario, 'asistencia', $idAsistencia, $ip);
    }

    /** @return array<string, mixed> */
    private function empleado(int $idEmpleado): array
    {
        $empleado = $this->empleadosRepo->findById($idEmpleado);
        if ($empleado === null) {
            throw new RuntimeException('Empleado no encontrado.');
        }
        return $empleado;
    }

    private function exigirPeriodoAbierto(int $idPeriodo): void
    {
        $periodo = $this->periodosRepo->findById($idPeriodo);
        if ($periodo === null || $periodo['estado'] !== 'abierto') {
            throw new InvalidArgumentException('Ese período ya se cerró; pida a RRHH que lo corrija.');
        }
    }

    private function exigirActivo(int $idEmpleado): void
    {
        if (($this->empleado($idEmpleado)['estado'] ?? '') !== 'activo') {
            throw new InvalidArgumentException('Su empleado está inactivo; no puede marcar asistencia.');
        }
    }

    /**
     * Período abierto que contiene hoy; si no existe ninguno, abre la quincena (1–15 o 16–fin de mes).
     */
    private function periodoParaHoy(DateTimeImmutable $ahora, int $idUsuario, string $ip): int
    {
        $periodo = $this->periodosRepo->findPorFecha($ahora->format('Y-m-d'));
        if ($periodo !== null) {
            if ($periodo['estado'] !== 'abierto') {
                throw new InvalidArgumentException('El período de pago de hoy ya está cerrado; avise a RRHH.');
            }
            return (int)$periodo['id_periodo'];
        }

        $primeraQuincena = (int)$ahora->format('j') <= 15;
        $inicio = $ahora->format('Y-m-') . ($primeraQuincena ? '01' : '16');
        $fin    = $primeraQuincena ? $ahora->format('Y-m-15') : $ahora->format('Y-m-t');

        if ($this->periodosRepo->findOverlapping($inicio, $fin) !== []) {
            throw new InvalidArgumentException('No hay un período de pago para hoy; avise a RRHH.');
        }

        $id = $this->periodosRepo->insert($inicio, $fin);
        $this->auditoriaRepo->insert('INSERT', $idUsuario, 'periodos_pago', $id, $ip);
        return $id;
    }

    /** Horas entre dos horas del mismo día (H:i:s), redondeadas a 2 decimales. */
    private function horas(string $entrada, string $salida): float
    {
        $e = DateTimeImmutable::createFromFormat('!H:i:s', $entrada);
        $s = DateTimeImmutable::createFromFormat('!H:i:s', $salida);
        if ($e === false || $s === false) {
            return 0.0;
        }
        return round(($s->getTimestamp() - $e->getTimestamp()) / 3600, 2);
    }
}
