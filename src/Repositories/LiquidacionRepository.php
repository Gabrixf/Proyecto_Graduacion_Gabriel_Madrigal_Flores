<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * LiquidacionRepository — SQL de la tabla liquidacion y lookups asociados.
 */
class LiquidacionRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int, array<string, mixed>> */
    public function findAll(?string $q = null): array
    {
        $sql    = "SELECT l.id_liquidacion, l.id_empleado, l.fecha_salida, l.motivo,
                          l.preaviso, l.cesantia, l.vacaciones_pendientes, l.aguinaldo_proporcional,
                          l.total_liquidacion, l.fecha_calculo, e.nombre, e.apellidos
                     FROM liquidacion l
                     JOIN empleados e ON e.id_empleado = l.id_empleado";
        $params = [];
        if ($q !== null && $q !== '') {
            $sql .= " WHERE CONCAT(e.nombre, ' ', e.apellidos) LIKE :q OR CONCAT(e.apellidos, ', ', e.nombre) LIKE :q";
            $params[':q'] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY l.fecha_calculo DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT l.*, e.nombre, e.apellidos, e.fecha_ingreso
               FROM liquidacion l
               JOIN empleados e ON e.id_empleado = l.id_empleado
              WHERE l.id_liquidacion = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** Empleados activos para el formulario. @return array<int, array<string, mixed>> */
    public function empleadosActivos(): array
    {
        return $this->pdo->query(
            "SELECT id_empleado, nombre, apellidos FROM empleados WHERE estado = 'activo' ORDER BY apellidos"
        )->fetchAll();
    }

    /** Salario del puesto + fecha de ingreso de un empleado. @return array<string, mixed>|null */
    public function datosEmpleado(int $idEmpleado): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.id_empleado, e.fecha_ingreso, e.estado, p.salario_base
               FROM empleados e
               JOIN puestos p ON p.id_puesto = e.id_puesto
              WHERE e.id_empleado = :id LIMIT 1"
        );
        $stmt->execute([':id' => $idEmpleado]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** Inserta o actualiza la liquidación del empleado (UNIQUE id_empleado). */
    public function upsert(array $d): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO liquidacion
                (id_empleado, fecha_salida, motivo, preaviso, cesantia,
                 vacaciones_pendientes, aguinaldo_proporcional, total_liquidacion)
             VALUES (:e, :fs, :mo, :pr, :ce, :va, :ag, :to)
             ON DUPLICATE KEY UPDATE
                fecha_salida = VALUES(fecha_salida),
                motivo = VALUES(motivo),
                preaviso = VALUES(preaviso),
                cesantia = VALUES(cesantia),
                vacaciones_pendientes = VALUES(vacaciones_pendientes),
                aguinaldo_proporcional = VALUES(aguinaldo_proporcional),
                total_liquidacion = VALUES(total_liquidacion),
                fecha_calculo = CURRENT_TIMESTAMP"
        );
        $stmt->execute([
            ':e'  => $d['id_empleado'],
            ':fs' => $d['fecha_salida'],
            ':mo' => $d['motivo'],
            ':pr' => $d['preaviso'],
            ':ce' => $d['cesantia'],
            ':va' => $d['vacaciones_pendientes'],
            ':ag' => $d['aguinaldo_proporcional'],
            ':to' => $d['total_liquidacion'],
        ]);
        // lastInsertId es 0 en UPDATE; recuperar el id real.
        $sel = $this->pdo->prepare('SELECT id_liquidacion FROM liquidacion WHERE id_empleado = :e LIMIT 1');
        $sel->execute([':e' => $d['id_empleado']]);
        return (int) $sel->fetchColumn();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM liquidacion WHERE id_liquidacion = :id');
        $stmt->execute([':id' => $id]);
    }
}
