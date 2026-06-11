<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * ReportesRepository — SQL de solo lectura para los reportes administrativos.
 * Sin escrituras; los agregados (SUM, GROUP BY) viven aquí, no en el Service.
 */
class ReportesRepository
{
    public function __construct(private readonly PDO $pdo) {}

    // ── Catálogos para los filtros ────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function periodos(): array
    {
        return $this->pdo->query(
            'SELECT id_periodo, fecha_inicio, fecha_fin, estado
               FROM periodos_pago ORDER BY fecha_inicio DESC'
        )->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function empleados(): array
    {
        return $this->pdo->query(
            'SELECT id_empleado, nombre, apellidos FROM empleados ORDER BY apellidos'
        )->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function usuarios(): array
    {
        return $this->pdo->query(
            'SELECT id_usuario, nombre_usuario FROM usuarios ORDER BY nombre_usuario'
        )->fetchAll();
    }

    // ── Planilla por período ──────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function planillaPorPeriodo(int $idPeriodo): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT n.id_nomina, n.estado, n.salario_bruto, n.total_deducciones, n.salario_neto,
                    e.nombre, e.apellidos, e.cedula
               FROM nominas n
               JOIN empleados e ON e.id_empleado = n.id_empleado
              WHERE n.id_periodo = :p
              ORDER BY e.apellidos'
        );
        $stmt->execute([':p' => $idPeriodo]);
        return $stmt->fetchAll();
    }

    // ── Historial por empleado (rango de fechas) ──────────

    /** @return array<int, array<string, mixed>> */
    public function nominasEmpleado(int $idEmpleado, string $desde, string $hasta): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT n.id_nomina, n.salario_bruto, n.total_deducciones, n.salario_neto, n.estado,
                    p.fecha_inicio, p.fecha_fin
               FROM nominas n
               JOIN periodos_pago p ON p.id_periodo = n.id_periodo
              WHERE n.id_empleado = :e AND p.fecha_fin >= :d AND p.fecha_inicio <= :h
              ORDER BY p.fecha_inicio DESC'
        );
        $stmt->execute([':e' => $idEmpleado, ':d' => $desde, ':h' => $hasta]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function horasExtraEmpleado(int $idEmpleado, string $desde, string $hasta): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fecha, cantidad_horas, factor_recargo
               FROM horas_extra
              WHERE id_empleado = :e AND fecha BETWEEN :d AND :h
              ORDER BY fecha DESC'
        );
        $stmt->execute([':e' => $idEmpleado, ':d' => $desde, ':h' => $hasta]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function vacacionesEmpleado(int $idEmpleado, string $desde, string $hasta): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fecha_inicio, fecha_fin, dias_tomados
               FROM vacaciones
              WHERE id_empleado = :e AND fecha_fin >= :d AND fecha_inicio <= :h
              ORDER BY fecha_inicio DESC'
        );
        $stmt->execute([':e' => $idEmpleado, ':d' => $desde, ':h' => $hasta]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function incapacidadesEmpleado(int $idEmpleado, string $desde, string $hasta): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fecha_inicio, fecha_fin, dias, tipo
               FROM incapacidades
              WHERE id_empleado = :e AND fecha_fin >= :d AND fecha_inicio <= :h
              ORDER BY fecha_inicio DESC'
        );
        $stmt->execute([':e' => $idEmpleado, ':d' => $desde, ':h' => $hasta]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function permisosEmpleado(int $idEmpleado, string $desde, string $hasta): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fecha_inicio, fecha_fin, con_goce_salarial
               FROM permisos
              WHERE id_empleado = :e AND fecha_fin >= :d AND fecha_inicio <= :h
              ORDER BY fecha_inicio DESC'
        );
        $stmt->execute([':e' => $idEmpleado, ':d' => $desde, ':h' => $hasta]);
        return $stmt->fetchAll();
    }

    // ── Costos patronales por período ─────────────────────

    /** @return array<int, array<string, mixed>> */
    public function costosPorPeriodo(int $idPeriodo): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.nombre, e.apellidos, n.salario_bruto,
                    COALESCE(SUM(CASE WHEN d.tipo = 'CCSS_patronal' THEN d.monto END), 0) AS ccss_patronal,
                    ROUND(n.salario_bruto / 12, 2) AS provision_aguinaldo
               FROM nominas n
               JOIN empleados e ON e.id_empleado = n.id_empleado
               LEFT JOIN deducciones_nomina d ON d.id_nomina = n.id_nomina
              WHERE n.id_periodo = :p
              GROUP BY n.id_nomina, e.nombre, e.apellidos, n.salario_bruto
              ORDER BY e.apellidos"
        );
        $stmt->execute([':p' => $idPeriodo]);
        return $stmt->fetchAll();
    }

    // ── Bitácora de auditoría ─────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function auditoria(?string $accion, ?int $idUsuario, ?string $desde, ?string $hasta, int $limite): array
    {
        $sql = 'SELECT a.id_auditoria, a.fecha_accion, a.accion, a.tabla_afectada,
                       a.id_registro, a.ip_origen, a.detalle, u.nombre_usuario
                  FROM auditoria a
                  JOIN usuarios u ON u.id_usuario = a.id_usuario
                 WHERE 1 = 1';
        $params = [];
        if ($accion !== null) {
            $sql .= ' AND a.accion = :a';
            $params[':a'] = $accion;
        }
        if ($idUsuario !== null) {
            $sql .= ' AND a.id_usuario = :u';
            $params[':u'] = $idUsuario;
        }
        if ($desde !== null) {
            $sql .= ' AND a.fecha_accion >= :d';
            $params[':d'] = $desde . ' 00:00:00';
        }
        if ($hasta !== null) {
            $sql .= ' AND a.fecha_accion <= :h';
            $params[':h'] = $hasta . ' 23:59:59';
        }
        $sql .= ' ORDER BY a.fecha_accion DESC LIMIT ' . $limite;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
