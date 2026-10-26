<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * AguinaldoRepository — SQL de aguinaldo y suma de salarios brutos.
 */
class AguinaldoRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int, array<string, mixed>> */
    public function findAll(int $anio): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.id_aguinaldo, a.id_empleado, a.anio, a.salarios_acumulados,
                    a.monto_aguinaldo, a.estado, a.fecha_pago, per.nombre, per.apellidos
               FROM aguinaldo a
               JOIN empleados e ON e.id_empleado = a.id_empleado
               JOIN persona per ON per.id_persona = e.id_persona
              WHERE a.anio = :anio
              ORDER BY per.apellidos ASC"
        );
        $stmt->execute([':anio' => $anio]);
        return $stmt->fetchAll();
    }

    /** Empleados activos. @return array<int, array<string, mixed>> */
    public function empleadosActivos(): array
    {
        return $this->pdo->query(
            "SELECT e.id_empleado, per.nombre, per.apellidos
               FROM empleados e
               JOIN persona per ON per.id_persona = e.id_persona
              WHERE e.estado = 'activo'
              ORDER BY per.apellidos"
        )->fetchAll();
    }

    /**
     * Suma de salarios brutos de nóminas aprobadas/pagadas cuyo periodo cae en [desde, hasta].
     * Excluye el subsidio de incapacidad: no es salario (Ley 2412, Art. 1).
     */
    public function sumaBrutos(int $idEmpleado, string $desde, string $hasta): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(n.salario_bruto - COALESCE((SELECT SUM(i.monto) FROM ingresos_nomina i WHERE i.id_nomina = n.id_nomina AND i.tipo = 'subsidio_incapacidad'), 0)), 0)
               FROM nominas n
               JOIN periodos_pago p ON p.id_periodo = n.id_periodo
              WHERE n.id_empleado = :e
                AND n.estado IN ('aprobado','pagado')
                AND p.fecha_inicio >= :desde
                AND p.fecha_fin <= :hasta"
        );
        $stmt->execute([':e' => $idEmpleado, ':desde' => $desde, ':hasta' => $hasta]);
        return (float) $stmt->fetchColumn();
    }

    /** Fin del último período que suma `sumaBrutos` (nóminas aprobadas o pagadas dentro del rango), o null. */
    public function ultimoFinNominaPagadaEntre(int $idEmpleado, string $desde, string $hasta): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT MAX(p.fecha_fin)
               FROM nominas n
               JOIN periodos_pago p ON p.id_periodo = n.id_periodo
              WHERE n.id_empleado = :e
                AND n.estado IN ('aprobado','pagado')
                AND p.fecha_inicio >= :desde
                AND p.fecha_fin <= :hasta"
        );
        $stmt->execute([':e' => $idEmpleado, ':desde' => $desde, ':hasta' => $hasta]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }

    /** Inserta o actualiza el aguinaldo (UNIQUE id_empleado, anio). */
    public function upsert(int $idEmpleado, int $anio, float $salariosAcumulados, float $monto): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO aguinaldo (id_empleado, anio, salarios_acumulados, monto_aguinaldo, estado)
             VALUES (:e, :a, :s, :m, 'calculado')
             ON DUPLICATE KEY UPDATE
                salarios_acumulados = VALUES(salarios_acumulados),
                monto_aguinaldo     = VALUES(monto_aguinaldo),
                estado              = 'calculado',
                fecha_pago          = '9999-12-31'"
        );
        $stmt->execute([':e' => $idEmpleado, ':a' => $anio, ':s' => $salariosAcumulados, ':m' => $monto]);
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM aguinaldo WHERE id_aguinaldo = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function marcarPagado(int $id, string $fecha): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE aguinaldo SET estado = 'pagado', fecha_pago = :f WHERE id_aguinaldo = :id"
        );
        $stmt->execute([':f' => $fecha, ':id' => $id]);
    }
}
