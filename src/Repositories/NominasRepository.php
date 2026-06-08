<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * NominasRepository — SQL de nominas + ingresos_nomina + deducciones_nomina.
 */
class NominasRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int, array<string, mixed>> */
    public function findAll(?int $idPeriodo = null): array
    {
        $sql = "SELECT n.id_nomina, n.id_empleado, n.id_periodo, n.salario_base,
                       n.total_ingresos, n.total_deducciones, n.salario_bruto, n.salario_neto,
                       n.estado, n.fecha_calculo, e.nombre, e.apellidos
                  FROM nominas n
                  JOIN empleados e ON e.id_empleado = n.id_empleado";
        $params = [];
        if ($idPeriodo !== null) {
            $sql .= ' WHERE n.id_periodo = :periodo';
            $params[':periodo'] = $idPeriodo;
        }
        $sql .= ' ORDER BY e.apellidos ASC, e.nombre ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Encabezado + líneas. @return array<string, mixed>|null */
    public function findByIdConLineas(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT n.*, e.nombre, e.apellidos, p.fecha_inicio, p.fecha_fin
               FROM nominas n
               JOIN empleados e ON e.id_empleado = n.id_empleado
               JOIN periodos_pago p ON p.id_periodo = n.id_periodo
              WHERE n.id_nomina = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $nomina = $stmt->fetch();
        if ($nomina === false) {
            return null;
        }
        $ing = $this->pdo->prepare('SELECT * FROM ingresos_nomina WHERE id_nomina = :id ORDER BY id_ingreso');
        $ing->execute([':id' => $id]);
        $ded = $this->pdo->prepare('SELECT * FROM deducciones_nomina WHERE id_nomina = :id ORDER BY id_deduccion');
        $ded->execute([':id' => $id]);
        $nomina['ingresos']    = $ing->fetchAll();
        $nomina['deducciones'] = $ded->fetchAll();
        return $nomina;
    }

    public function existePeriodo(int $idPeriodo): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM periodos_pago WHERE id_periodo = :id');
        $stmt->execute([':id' => $idPeriodo]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function existeNominaEmpleadoPeriodo(int $idEmpleado, int $idPeriodo): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM nominas WHERE id_empleado = :e AND id_periodo = :p'
        );
        $stmt->execute([':e' => $idEmpleado, ':p' => $idPeriodo]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Empleados activos sin nómina en el periodo, con su salario mensual (del puesto).
     * @return array<int, array<string, mixed>>
     */
    public function empleadosActivosSinNomina(int $idPeriodo): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.id_empleado, e.nombre, e.apellidos, p.salario_base AS salario_mensual
               FROM empleados e
               JOIN puestos p ON p.id_puesto = e.id_puesto
              WHERE e.estado = 'activo'
                AND NOT EXISTS (
                    SELECT 1 FROM nominas n
                     WHERE n.id_empleado = e.id_empleado AND n.id_periodo = :periodo
                )
              ORDER BY e.apellidos ASC"
        );
        $stmt->execute([':periodo' => $idPeriodo]);
        return $stmt->fetchAll();
    }

    /** Salario mensual (del puesto) de un empleado. */
    public function salarioMensualEmpleado(int $idEmpleado): ?float
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.salario_base FROM empleados e
               JOIN puestos p ON p.id_puesto = e.id_puesto
              WHERE e.id_empleado = :id LIMIT 1'
        );
        $stmt->execute([':id' => $idEmpleado]);
        $val = $stmt->fetchColumn();
        return $val === false ? null : (float) $val;
    }

    /** Horas extra del empleado en el periodo. @return array<int, array<string, mixed>> */
    public function horasExtraDelPeriodo(int $idEmpleado, int $idPeriodo): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fecha, cantidad_horas, factor_recargo
               FROM horas_extra
              WHERE id_empleado = :e AND id_periodo = :p'
        );
        $stmt->execute([':e' => $idEmpleado, ':p' => $idPeriodo]);
        return $stmt->fetchAll();
    }

    /**
     * Inserta una nómina completa (encabezado + líneas) en una transacción.
     * @param array<string, mixed> $cab
     * @param array<int, array<string, mixed>> $ingresos
     * @param array<int, array<string, mixed>> $deducciones
     */
    public function insertNominaCompleta(array $cab, array $ingresos, array $deducciones): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO nominas
                    (id_empleado, id_periodo, salario_base, total_ingresos, total_deducciones,
                     salario_bruto, salario_neto, estado)
                 VALUES (:e, :p, :sb, :ti, :td, :br, :ne, :st)'
            );
            $stmt->execute([
                ':e'  => $cab['id_empleado'],
                ':p'  => $cab['id_periodo'],
                ':sb' => $cab['salario_base'],
                ':ti' => $cab['total_ingresos'],
                ':td' => $cab['total_deducciones'],
                ':br' => $cab['salario_bruto'],
                ':ne' => $cab['salario_neto'],
                ':st' => $cab['estado'] ?? 'borrador',
            ]);
            $idNomina = (int) $this->pdo->lastInsertId();

            foreach ($ingresos as $i) {
                $this->insertIngresoTx($idNomina, $i);
            }
            foreach ($deducciones as $d) {
                $this->insertDeduccionTx($idNomina, $d);
            }
            $this->pdo->commit();
            return $idNomina;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string, mixed> $i */
    private function insertIngresoTx(int $idNomina, array $i): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ingresos_nomina (id_nomina, tipo, descripcion, monto)
             VALUES (:n, :t, :d, :m)'
        );
        $stmt->execute([
            ':n' => $idNomina,
            ':t' => $i['tipo'],
            ':d' => $i['descripcion'] ?? null,
            ':m' => $i['monto'],
        ]);
    }

    /** @param array<string, mixed> $d */
    private function insertDeduccionTx(int $idNomina, array $d): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO deducciones_nomina (id_nomina, tipo, descripcion, porcentaje, monto)
             VALUES (:n, :t, :d, :pc, :m)'
        );
        $stmt->execute([
            ':n'  => $idNomina,
            ':t'  => $d['tipo'],
            ':d'  => $d['descripcion'] ?? null,
            ':pc' => $d['porcentaje'] ?? null,
            ':m'  => $d['monto'],
        ]);
    }

    /** @param array<string, mixed> $i */
    public function insertIngreso(int $idNomina, array $i): int
    {
        $this->insertIngresoTx($idNomina, $i);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $d */
    public function insertDeduccion(int $idNomina, array $d): int
    {
        $this->insertDeduccionTx($idNomina, $d);
        return (int) $this->pdo->lastInsertId();
    }

    public function deleteIngreso(int $idNomina, int $idIngreso): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ingresos_nomina WHERE id_ingreso = :i AND id_nomina = :n');
        $stmt->execute([':i' => $idIngreso, ':n' => $idNomina]);
    }

    public function deleteDeduccion(int $idNomina, int $idDeduccion): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM deducciones_nomina WHERE id_deduccion = :d AND id_nomina = :n');
        $stmt->execute([':d' => $idDeduccion, ':n' => $idNomina]);
    }

    /** @param array{total_ingresos:float,total_deducciones:float,salario_bruto:float,salario_neto:float} $t */
    public function updateTotales(int $id, array $t): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE nominas SET total_ingresos = :ti, total_deducciones = :td,
                    salario_bruto = :br, salario_neto = :ne
              WHERE id_nomina = :id'
        );
        $stmt->execute([
            ':ti' => $t['total_ingresos'],
            ':td' => $t['total_deducciones'],
            ':br' => $t['salario_bruto'],
            ':ne' => $t['salario_neto'],
            ':id' => $id,
        ]);
    }

    public function updateEstado(int $id, string $estado): void
    {
        $stmt = $this->pdo->prepare('UPDATE nominas SET estado = :e WHERE id_nomina = :id');
        $stmt->execute([':e' => $estado, ':id' => $id]);
    }

    public function delete(int $id): void
    {
        // ingresos_nomina y deducciones_nomina tienen ON DELETE CASCADE
        $stmt = $this->pdo->prepare('DELETE FROM nominas WHERE id_nomina = :id');
        $stmt->execute([':id' => $id]);
    }

    /** Periodos para el filtro. @return array<int, array<string, mixed>> */
    public function periodos(): array
    {
        return $this->pdo->query(
            'SELECT id_periodo, fecha_inicio, fecha_fin, estado FROM periodos_pago ORDER BY fecha_inicio DESC'
        )->fetchAll();
    }
}
