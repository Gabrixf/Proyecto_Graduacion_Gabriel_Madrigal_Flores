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

    /** @return array{vinculado: bool, empleado: ?array<string, mixed>, bancos: array<int, array<string, mixed>>} */
    public function perfil(int $idUsuario): array
    {
        $empleado = $this->repo->perfil($idUsuario);
        return [
            'vinculado' => $empleado !== null,
            'empleado'  => $empleado,
            'bancos'    => $empleado !== null ? $this->repo->datosBancarios($idUsuario) : [],
        ];
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
}
