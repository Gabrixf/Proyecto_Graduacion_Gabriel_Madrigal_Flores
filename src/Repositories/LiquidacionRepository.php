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
                          l.total_liquidacion, l.fecha_calculo, per.nombre, per.apellidos
                     FROM liquidacion l
                     JOIN empleados e ON e.id_empleado = l.id_empleado
                     JOIN persona per ON per.id_persona = e.id_persona";
        $params = [];
        if ($q !== null && $q !== '') {
            $sql .= " WHERE CONCAT(per.nombre, ' ', per.apellidos) LIKE :q OR CONCAT(per.apellidos, ', ', per.nombre) LIKE :q";
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
            "SELECT l.*, per.nombre, per.apellidos, e.fecha_ingreso
               FROM liquidacion l
               JOIN empleados e ON e.id_empleado = l.id_empleado
               JOIN persona per ON per.id_persona = e.id_persona
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
            "SELECT e.id_empleado, per.nombre, per.apellidos
               FROM empleados e
               JOIN persona per ON per.id_persona = e.id_persona
              WHERE e.estado = 'activo'
              ORDER BY per.apellidos"
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

    /** Contrato vigente (estado='activo') del empleado: id, tipo y vencimiento, o null. */
    public function contratoVigente(int $idEmpleado): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id_contrato, tipo_contrato, fecha_fin FROM contratos
              WHERE id_empleado = :id AND estado = 'activo' LIMIT 1"
        );
        $stmt->execute([':id' => $idEmpleado]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** Último contrato del empleado finalizado justo en $fecha (el que cerró la liquidación), o null. */
    public function contratoCerradoEn(int $idEmpleado, string $fecha): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id_contrato, tipo_contrato, fecha_fin FROM contratos
              WHERE id_empleado = :id AND estado = 'finalizado' AND fecha_fin = :f
              ORDER BY id_contrato DESC LIMIT 1"
        );
        $stmt->execute([':id' => $idEmpleado, ':f' => $fecha]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function darDeBajaEmpleado(int $idEmpleado, string $fechaSalida): void
    {
        $this->pdo->prepare("UPDATE empleados SET estado = 'inactivo', fecha_salida = :f WHERE id_empleado = :id")
            ->execute([':f' => $fechaSalida, ':id' => $idEmpleado]);
    }

    public function reactivarEmpleado(int $idEmpleado): void
    {
        $this->pdo->prepare("UPDATE empleados SET estado = 'activo', fecha_salida = '9999-12-31' WHERE id_empleado = :id")
            ->execute([':id' => $idEmpleado]);
    }

    public function finalizarContrato(int $idContrato, string $fechaFin): void
    {
        $this->pdo->prepare("UPDATE contratos SET estado = 'finalizado', fecha_fin = :f WHERE id_contrato = :id")
            ->execute([':f' => $fechaFin, ':id' => $idContrato]);
    }

    public function reabrirContrato(int $idContrato, string $fechaFin): void
    {
        $this->pdo->prepare("UPDATE contratos SET estado = 'activo', fecha_fin = :f WHERE id_contrato = :id")
            ->execute([':f' => $fechaFin, ':id' => $idContrato]);
    }

    /** Inserta o actualiza la liquidación del empleado (UNIQUE id_empleado). */
    public function upsert(array $d): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO liquidacion
                (id_empleado, fecha_salida, motivo, salario_promedio, dias_preaviso, dias_cesantia, dias_vacaciones,
                 base_estimada, preaviso, cesantia, vacaciones_pendientes, aguinaldo_proporcional, total_liquidacion)
             VALUES (:e, :fs, :mo, :sp, :dp, :dc, :dv, :be, :pr, :ce, :va, :ag, :to)
             ON DUPLICATE KEY UPDATE
                fecha_salida = VALUES(fecha_salida),
                motivo = VALUES(motivo),
                salario_promedio = VALUES(salario_promedio),
                dias_preaviso = VALUES(dias_preaviso),
                dias_cesantia = VALUES(dias_cesantia),
                dias_vacaciones = VALUES(dias_vacaciones),
                base_estimada = VALUES(base_estimada),
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
            ':sp' => $d['salario_promedio'],
            ':dp' => $d['dias_preaviso'],
            ':dc' => $d['dias_cesantia'],
            ':dv' => $d['dias_vacaciones'],
            ':be' => $d['base_estimada'],
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
