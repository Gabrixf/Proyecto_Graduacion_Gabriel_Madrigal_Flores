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

    /**
     * Contratos vigentes con vencimiento (plazo fijo / obra determinada) que vencen
     * a más tardar en $hasta, incluidos los ya vencidos, de empleados activos sin
     * liquidación. Del más urgente al menos.
     *
     * @return array<int, array<string, mixed>>
     */
    public function contratosConVencimientoHasta(string $hasta): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id_contrato, c.tipo_contrato, c.fecha_fin, per.nombre, per.apellidos
               FROM contratos c
               JOIN empleados e ON e.id_empleado = c.id_empleado
               JOIN persona per ON per.id_persona = e.id_persona
              WHERE c.estado = 'activo'
                AND c.tipo_contrato <> 'tiempo_indefinido'
                AND c.fecha_fin <= :hasta
                AND e.estado = 'activo'
                AND NOT EXISTS (SELECT 1 FROM liquidacion l WHERE l.id_empleado = c.id_empleado)
              ORDER BY c.fecha_fin"
        );
        $stmt->execute([':hasta' => $hasta]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Empleado vinculado al usuario (id y fecha de ingreso), o null. */
    public function empleadoPorUsuario(int $idUsuario): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_empleado, fecha_ingreso FROM empleados WHERE id_usuario = :uid LIMIT 1'
        );
        $stmt->execute([':uid' => $idUsuario]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function kpisEmpleado(int $idUsuario): array
    {
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
            'ultimo_salario'  => $ultimoSalario,
            'colillas'        => $colillas,
        ];
    }
}
