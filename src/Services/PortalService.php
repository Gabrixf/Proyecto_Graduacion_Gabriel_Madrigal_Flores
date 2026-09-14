<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\PortalRepository;
use RuntimeException;

/**
 * PortalService — autoconsulta del empleado autenticado (solo lectura).
 * Si el usuario no tiene empleado vinculado, las vistas reciben vinculado=false.
 */
class PortalService
{
    public function __construct(private readonly PortalRepository $repo) {}

    public function idEmpleado(int $idUsuario): ?int
    {
        return $this->repo->idEmpleadoPorUsuario($idUsuario);
    }

    /** Saldo del empleado para un año puntual, o null si no hay fila para ese año. */
    public function saldoVacacionesAnio(int $idUsuario, int $anio): ?array
    {
        foreach ($this->repo->saldosVacaciones($idUsuario) as $s) {
            if ((int) $s['anio'] === $anio) {
                return $s;
            }
        }
        return null;
    }

    /** @return array{vinculado: bool, empleado: ?array<string, mixed>, bancos: array<int, array<string, mixed>>} */
    public function perfil(int $idUsuario): array
    {
        $empleado = $this->repo->perfil($idUsuario);

        if ($empleado !== null && !empty($empleado['fecha_ingreso'])) {
            $ingreso   = new \DateTime($empleado['fecha_ingreso']);
            $diff      = (new \DateTime())->diff($ingreso);
            $anios     = $diff->y;
            $meses     = $diff->m;
            $empleado['antiguedad'] = $anios . ' año' . ($anios !== 1 ? 's' : '')
                . ', ' . $meses . ' mes' . ($meses !== 1 ? 'es' : '');
        }

        return [
            'vinculado' => $empleado !== null,
            'empleado'  => $empleado,
            'bancos'    => $empleado !== null ? $this->repo->datosBancarios($idUsuario) : [],
        ];
    }

    /**
     * Cambia la contraseña del usuario autenticado.
     * @throws \InvalidArgumentException si la validación falla.
     */
    public function changePassword(int $idUsuario, string $actual, string $nueva, string $confirmar): void
    {
        if ($actual === '' || $nueva === '' || $confirmar === '') {
            throw new \InvalidArgumentException('Todos los campos son obligatorios.');
        }
        if (strlen($nueva) < 8) {
            throw new \InvalidArgumentException('La nueva contraseña debe tener al menos 8 caracteres.');
        }
        if ($nueva !== $confirmar) {
            throw new \InvalidArgumentException('La nueva contraseña y su confirmación no coinciden.');
        }

        $hash = $this->repo->getPasswordHash($idUsuario);
        if ($hash === null || !password_verify($actual, $hash)) {
            throw new \InvalidArgumentException('La contraseña actual es incorrecta.');
        }

        $nuevoHash = password_hash($nueva, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->repo->updatePassword($idUsuario, $nuevoHash);
    }

    /** @return array{vinculado: bool, colillas: array<int, array<string, mixed>>} */
    public function colillas(int $idUsuario): array
    {
        $vinculado = $this->repo->idEmpleadoPorUsuario($idUsuario) !== null;
        return [
            'vinculado' => $vinculado,
            'colillas'  => $vinculado ? $this->repo->colillas($idUsuario) : [],
        ];
    }

    /**
     * Colilla propia con líneas de ingresos y deducciones.
     *
     * @return array<string, mixed>
     * @throws RuntimeException si la colilla no existe o no pertenece al usuario.
     */
    public function colilla(int $idNomina, int $idUsuario): array
    {
        $cab = $this->repo->colilla($idNomina, $idUsuario);
        if ($cab === null) {
            throw new RuntimeException('Colilla no encontrada.');
        }
        $cab['ingresos']    = $this->repo->ingresosColilla($idNomina, $idUsuario);
        $cab['deducciones'] = $this->repo->deduccionesColilla($idNomina, $idUsuario);
        return $cab;
    }

    /** @return array{vinculado: bool, saldos: array<int, array<string, mixed>>, historial: array<int, array<string, mixed>>} */
    public function vacaciones(int $idUsuario): array
    {
        $vinculado = $this->repo->idEmpleadoPorUsuario($idUsuario) !== null;
        return [
            'vinculado' => $vinculado,
            'saldos'    => $vinculado ? $this->repo->saldosVacaciones($idUsuario) : [],
            'historial' => $vinculado ? $this->repo->vacacionesDisfrutadas($idUsuario) : [],
        ];
    }

    /** @return array{vinculado: bool, registros: array<int, array<string, mixed>>, periodos: array<int, array<string, mixed>>, periodoActual: int|null, totalHoras: float} */
    public function asistencia(int $idUsuario, ?int $idPeriodo = null): array
    {
        $vinculado = $this->repo->idEmpleadoPorUsuario($idUsuario) !== null;
        $registros = $vinculado ? $this->repo->asistencia($idUsuario, $idPeriodo) : [];
        $totalHoras = array_sum(array_column($registros, 'horas_trabajadas'));

        return [
            'vinculado'    => $vinculado,
            'registros'    => $registros,
            'periodos'     => $vinculado ? $this->repo->periodosConAsistencia($idUsuario) : [],
            'periodoActual'=> $idPeriodo,
            'totalHoras'   => $totalHoras,
        ];
    }
}
