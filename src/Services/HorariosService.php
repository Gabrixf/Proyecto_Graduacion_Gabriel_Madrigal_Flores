<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\HorariosRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * HorariosService — horarios de trabajo por día de la semana.
 *
 * Reglas (Código de Trabajo, Art. 136):
 *  - jornada del día = salida − entrada − minutos de almuerzo; máximo 10 h (jornada acumulativa);
 *  - suma semanal de jornadas máxima 48 h;
 *  - un día sin horario es día de descanso.
 */
class HorariosService
{
    private const NOMBRES = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
    private const LETRAS  = [1 => 'L', 2 => 'K', 3 => 'M', 4 => 'J', 5 => 'V', 6 => 'S', 7 => 'D'];
    private const MAX_HORAS_DIA    = 10.0;
    private const MAX_HORAS_SEMANA = 48.0;
    private const MAX_ALMUERZO     = 120;

    public function __construct(
        private readonly HorariosRepository  $repo,
        private readonly AuditoriaRepository $auditoriaRepo
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function listar(): array
    {
        return array_map(
            fn (array $h): array => $h + ['resumen' => $this->resumen($this->repo->findDias((int)$h['id_horario']))],
            $this->repo->findAll()
        );
    }

    /** @return array<string, mixed> */
    public function obtener(int $id): array
    {
        $h = $id !== 1 ? $this->repo->findById($id) : null;
        if ($h === null) {
            throw new RuntimeException('Horario no encontrado.');
        }
        $dias = [];
        foreach ($this->repo->findDias($id) as $d) {
            $dias[(int)$d['dia_semana']] = [
                'entrada'  => substr((string)$d['hora_entrada'], 0, 5),
                'salida'   => substr((string)$d['hora_salida'], 0, 5),
                'almuerzo' => (int)$d['minutos_almuerzo'],
            ];
        }
        return $h + ['dias' => $dias];
    }

    /** Opciones para la ficha del empleado (incluye el centinela "Sin asignar"). @return array<int, array<string, mixed>> */
    public function opciones(): array
    {
        return array_merge([['id_horario' => 1, 'nombre' => 'Sin asignar', 'resumen' => '']], $this->listar());
    }

    public function crear(array $datos, int $idUsuario, string $ip): int
    {
        [$nombre, $dias] = $this->validar($datos, null);
        $id = $this->repo->insert($nombre, $this->resumen($dias), $dias);
        $this->auditoriaRepo->insert('INSERT', $idUsuario, 'horarios', $id, $ip);
        return $id;
    }

    public function actualizar(int $id, array $datos, int $idUsuario, string $ip): void
    {
        $this->obtener($id);
        [$nombre, $dias] = $this->validar($datos, $id);
        $this->repo->update($id, $nombre, $this->resumen($dias), $dias);
        $this->auditoriaRepo->insert('UPDATE', $idUsuario, 'horarios', $id, $ip);
    }

    public function eliminar(int $id, int $idUsuario, string $ip): void
    {
        $this->obtener($id);
        if ($this->repo->hasEmpleados($id)) {
            throw new RuntimeException('No se puede eliminar: hay empleados con este horario.');
        }
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $idUsuario, 'horarios', $id, $ip);
    }

    /** @return array{entrada: string, salida: string, almuerzo: int}|null null si ese día no trabaja */
    public function horarioDelDia(int $idEmpleado, string $fecha): ?array
    {
        $dia = (int)(new DateTimeImmutable($fecha))->format('N');
        foreach ($this->repo->findDiasPorEmpleado($idEmpleado) as $d) {
            if ((int)$d['dia_semana'] === $dia) {
                return [
                    'entrada'  => (string)$d['hora_entrada'],
                    'salida'   => (string)$d['hora_salida'],
                    'almuerzo' => (int)$d['minutos_almuerzo'],
                ];
            }
        }
        return null;
    }

    public function tieneHorario(int $idEmpleado): bool
    {
        return $this->repo->findDiasPorEmpleado($idEmpleado) !== [];
    }

    /** Días ISO (1–7) de descanso. Sin horario asignado: solo el domingo. @return int[] */
    public function diasDescanso(int $idEmpleado): array
    {
        $trabajados = array_map(static fn ($d) => (int)$d['dia_semana'], $this->repo->findDiasPorEmpleado($idEmpleado));
        if ($trabajados === []) {
            return [7];
        }
        return array_values(array_diff(range(1, 7), $trabajados));
    }

    /**
     * @param array<string, mixed> $datos
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function validar(array $datos, ?int $excludeId): array
    {
        $nombre = trim((string)($datos['nombre'] ?? ''));
        if ($nombre === '' || mb_strlen($nombre) > 100) {
            throw new InvalidArgumentException('El nombre es obligatorio (máximo 100 caracteres).');
        }
        if ($this->repo->existsByNombre($nombre, $excludeId)) {
            throw new InvalidArgumentException("Ya existe un horario con el nombre \"{$nombre}\".");
        }

        $filas   = [];
        $semana  = 0.0;
        $entrada = (array)($datos['dias'] ?? []);
        for ($d = 1; $d <= 7; $d++) {
            $f = (array)($entrada[$d] ?? []);
            if (($f['trabaja'] ?? '0') !== '1') {
                continue;
            }
            $e = $this->hora((string)($f['entrada'] ?? ''));
            $s = $this->hora((string)($f['salida'] ?? ''));
            if ($e === null || $s === null || $s <= $e) {
                throw new InvalidArgumentException(self::NOMBRES[$d] . ': la salida debe ser posterior a la entrada (HH:MM).');
            }
            $almuerzo = is_numeric($f['almuerzo'] ?? '') ? (int)$f['almuerzo'] : 0;
            if ($almuerzo < 0 || $almuerzo > self::MAX_ALMUERZO || $almuerzo >= ($s - $e)) {
                throw new InvalidArgumentException(self::NOMBRES[$d] . ': el almuerzo debe estar entre 0 y 120 minutos y ser menor que la jornada.');
            }
            $horas = ($s - $e - $almuerzo) / 60;
            if ($horas > self::MAX_HORAS_DIA) {
                throw new InvalidArgumentException(self::NOMBRES[$d] . ': la jornada no puede pasar de 10 h (Art. 136).');
            }
            $semana += $horas;
            $filas[] = [
                'dia_semana'       => $d,
                'hora_entrada'     => sprintf('%02d:%02d:00', intdiv($e, 60), $e % 60),
                'hora_salida'      => sprintf('%02d:%02d:00', intdiv($s, 60), $s % 60),
                'minutos_almuerzo' => $almuerzo,
            ];
        }
        if ($filas === []) {
            throw new InvalidArgumentException('El horario debe tener al menos un día trabajado.');
        }
        if ($semana > self::MAX_HORAS_SEMANA) {
            throw new InvalidArgumentException(sprintf('La jornada semanal suma %.1f h y no puede pasar de 48 h (Art. 136).', $semana));
        }
        return [$nombre, $filas];
    }

    /** Minutos desde medianoche de una hora HH:MM o HH:MM:SS; null si es inválida. */
    private function hora(string $valor): ?int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', trim($valor), $m) !== 1) {
            return null;
        }
        return (int)$m[1] * 60 + (int)$m[2];
    }

    /**
     * Resumen legible: agrupa días consecutivos con el mismo horario.
     * @param array<int, array<string, mixed>> $dias
     */
    private function resumen(array $dias): string
    {
        $grupos = [];
        foreach ($dias as $d) {
            $rango = substr((string)$d['hora_entrada'], 0, 5) . '–' . substr((string)$d['hora_salida'], 0, 5);
            $dia   = (int)$d['dia_semana'];
            $ult   = count($grupos) - 1;
            if ($ult >= 0 && $grupos[$ult]['rango'] === $rango && $grupos[$ult]['hasta'] === $dia - 1) {
                $grupos[$ult]['hasta'] = $dia;
            } else {
                $grupos[] = ['desde' => $dia, 'hasta' => $dia, 'rango' => $rango];
            }
        }
        return implode(' · ', array_map(
            static fn ($g) => self::LETRAS[$g['desde']] . ($g['hasta'] !== $g['desde'] ? '–' . self::LETRAS[$g['hasta']] : '') . ' ' . $g['rango'],
            $grupos
        ));
    }
}
