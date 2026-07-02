<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class DashboardRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function kpisAdmin(): array
    {
        $empleadosActivos = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM empleados WHERE estado = 'activo'")
            ->fetchColumn();

        $solicitudesPendientes = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM solicitudes WHERE estado = 'pendiente'")
            ->fetchColumn();

        $periodo = $this->pdo
            ->query("SELECT fecha_inicio, fecha_fin FROM periodos_pago
                     WHERE estado = 'abierto' ORDER BY fecha_inicio DESC LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC) ?: null;

        $nominasBorrador = 0;
        if ($periodo) {
            $nominasBorrador = (int) $this->pdo
                ->query("SELECT COUNT(*) FROM nominas n
                         JOIN periodos_pago p ON n.id_periodo = p.id_periodo
                         WHERE p.estado = 'abierto' AND n.estado = 'borrador'")
                ->fetchColumn();
        }

        return [
            'empleados_activos'      => $empleadosActivos,
            'solicitudes_pendientes' => $solicitudesPendientes,
            'nominas_borrador'       => $nominasBorrador,
            'periodo'                => $periodo,
        ];
    }

    public function kpisEmpleado(int $idUsuario): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(sv.dias_disponibles), 0)
             FROM saldo_vacaciones sv
             JOIN empleados e ON sv.id_empleado = e.id_empleado
             WHERE e.id_usuario = :uid AND sv.anio = YEAR(CURDATE())"
        );
        $stmt->execute([':uid' => $idUsuario]);
        $diasVacaciones = (float) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT n.salario_neto
             FROM nominas n
             JOIN empleados e ON n.id_empleado = e.id_empleado
             WHERE e.id_usuario = :uid AND n.estado = 'pagado'
             ORDER BY n.id_nomina DESC LIMIT 1"
        );
        $stmt->execute([':uid' => $idUsuario]);
        $ultimoSalario = $stmt->fetchColumn() ?: null;

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM nominas n
             JOIN empleados e ON n.id_empleado = e.id_empleado
             WHERE e.id_usuario = :uid AND n.estado = 'pagado'"
        );
        $stmt->execute([':uid' => $idUsuario]);
        $colillas = (int) $stmt->fetchColumn();

        return [
            'dias_vacaciones' => $diasVacaciones,
            'ultimo_salario'  => $ultimoSalario,
            'colillas'        => $colillas,
        ];
    }
}
