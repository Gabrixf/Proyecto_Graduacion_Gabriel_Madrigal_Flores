<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AguinaldoRepository;
use App\Repositories\AuditoriaRepository;
use RuntimeException;

/**
 * AguinaldoService — cálculo anual del aguinaldo (Ley 2412).
 */
class AguinaldoService
{
    public function __construct(
        private readonly AguinaldoRepository $repo,
        private readonly AuditoriaRepository $auditoriaRepo
    ) {}

    /** Monto del aguinaldo = suma de brutos / 12. */
    public static function calcularMonto(float $salariosAcumulados): float
    {
        return round($salariosAcumulados / 12.0, 2);
    }

    /** @return array<int, array<string, mixed>> */
    public function listar(int $anio): array
    {
        return $this->repo->findAll($anio);
    }

    /**
     * Calcula el aguinaldo de todos los empleados activos para el año dado.
     * Periodo legal: 1-dic (año-1) a 30-nov (año).
     * @return int cantidad procesada
     */
    public function calcular(int $anio, int $loggedInId, string $ip): int
    {
        $desde = ($anio - 1) . '-12-01';
        $hasta = $anio . '-11-30';
        $n = 0;
        foreach ($this->repo->empleadosActivos() as $emp) {
            $idEmpleado = (int) $emp['id_empleado'];
            $suma  = $this->repo->sumaBrutos($idEmpleado, $desde, $hasta);
            $monto = self::calcularMonto($suma);
            $this->repo->upsert($idEmpleado, $anio, $suma, $monto);
            $this->auditoriaRepo->insert('INSERT', $loggedInId, 'aguinaldo', $idEmpleado, $ip);
            $n++;
        }
        return $n;
    }

    public function marcarPagado(int $id, string $fecha, int $loggedInId, string $ip): void
    {
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new RuntimeException('Aguinaldo no encontrado.');
        }
        if ($row['estado'] === 'pagado') {
            throw new RuntimeException('El aguinaldo ya está pagado.');
        }
        $this->repo->marcarPagado($id, $fecha);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'aguinaldo', $id, $ip);
    }
}
