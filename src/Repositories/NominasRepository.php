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
    public function findAll(?int $idPeriodo = null, ?string $estado = null): array
    {
        $sql = "SELECT n.id_nomina, n.id_empleado, n.id_periodo, n.salario_base,
                       n.total_ingresos, n.total_deducciones, n.salario_bruto, n.salario_neto,
                       n.estado, n.fecha_calculo, per.nombre, per.apellidos,
                       p.fecha_inicio, p.fecha_fin
                  FROM nominas n
                  JOIN empleados e ON e.id_empleado = n.id_empleado
                  JOIN persona per ON per.id_persona = e.id_persona
                  JOIN periodos_pago p ON p.id_periodo = n.id_periodo
                 WHERE 1 = 1";
        $params = [];
        if ($idPeriodo !== null) {
            $sql .= ' AND n.id_periodo = :periodo';
            $params[':periodo'] = $idPeriodo;
        }
        if ($estado !== null) {
            $sql .= ' AND n.estado = :estado';
            $params[':estado'] = $estado;
        }
        $sql .= ' ORDER BY p.fecha_inicio DESC, per.apellidos ASC, per.nombre ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Encabezado + líneas. @return array<string, mixed>|null */
    public function findByIdConLineas(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT n.*, per.nombre, per.apellidos, p.fecha_inicio, p.fecha_fin
               FROM nominas n
               JOIN empleados e ON e.id_empleado = n.id_empleado
               JOIN persona per ON per.id_persona = e.id_persona
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
            "SELECT e.id_empleado, per.nombre, per.apellidos, p.salario_base AS salario_mensual,
                    e.renta_hijos, e.renta_conyuge
               FROM empleados e
               JOIN persona per ON per.id_persona = e.id_persona
               JOIN puestos p ON p.id_puesto = e.id_puesto
              WHERE e.estado = 'activo'
                AND NOT EXISTS (
                    SELECT 1 FROM nominas n
                     WHERE n.id_empleado = e.id_empleado AND n.id_periodo = :periodo
                )
              ORDER BY per.apellidos ASC"
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
            'SELECT id_hora_extra, fecha, cantidad_horas, factor_recargo
               FROM horas_extra
              WHERE id_empleado = :e AND id_periodo = :p'
        );
        $stmt->execute([':e' => $idEmpleado, ':p' => $idPeriodo]);
        return $stmt->fetchAll();
    }

    /** Asistencias con salida marcada del empleado en el periodo. @return array<int, array<string, mixed>> */
    public function asistenciasDelPeriodo(int $idEmpleado, int $idPeriodo): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT fecha, hora_entrada, hora_salida FROM asistencia
              WHERE id_empleado = :e AND id_periodo = :p AND hora_salida <> '00:00:00'
              ORDER BY fecha"
        );
        $stmt->execute([':e' => $idEmpleado, ':p' => $idPeriodo]);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function periodo(int $idPeriodo): ?array
    {
        $stmt = $this->pdo->prepare('SELECT fecha_inicio, fecha_fin FROM periodos_pago WHERE id_periodo = :p LIMIT 1');
        $stmt->execute([':p' => $idPeriodo]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
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
            ':d' => $i['descripcion'] ?? '',
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
            ':d'  => $d['descripcion'] ?? '',
            ':pc' => $d['porcentaje'] ?? 0.0,
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

    public function deleteDeduccionesCCSS(int $idNomina): void
    {
        $this->pdo->prepare(
            "DELETE FROM deducciones_nomina WHERE id_nomina = :n AND tipo IN ('CCSS_empleado', 'CCSS_patronal')"
        )->execute([':n' => $idNomina]);
    }

    /** @return array<string, mixed>|null */
    public function findCabeceraEmpleadoPeriodo(int $idEmpleado, int $idPeriodo): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id_nomina, estado FROM nominas WHERE id_empleado = :e AND id_periodo = :p LIMIT 1');
        $stmt->execute([':e' => $idEmpleado, ':p' => $idPeriodo]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function incapacidadesDelPeriodo(int $idEmpleado, string $ini, string $fin): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT i.tipo, i.fecha_inicio, i.fecha_fin, i.dias,
                    COALESCE((SELECT MAX(a.fecha_fin) FROM incapacidades a
                               WHERE a.id_empleado = i.id_empleado AND a.tipo = 'CCSS'
                                 AND a.id_incapacidad <> i.id_incapacidad AND a.fecha_fin < i.fecha_inicio), '0000-00-00') AS fin_ccss_anterior
               FROM incapacidades i
              WHERE i.id_empleado = :e AND i.fecha_inicio <= :fin AND i.fecha_fin >= :ini
              ORDER BY i.fecha_inicio"
        );
        $stmt->execute([':e' => $idEmpleado, ':ini' => $ini, ':fin' => $fin]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function permisosSinGoceDelPeriodo(int $idEmpleado, string $ini, string $fin): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fecha_inicio, fecha_fin FROM permisos
              WHERE id_empleado = :e AND con_goce_salarial = 0 AND fecha_inicio <= :fin AND fecha_fin >= :ini
              ORDER BY fecha_inicio'
        );
        $stmt->execute([':e' => $idEmpleado, ':ini' => $ini, ':fin' => $fin]);
        return $stmt->fetchAll();
    }

    public function diasVacacionesDelPeriodo(int $idEmpleado, int $idPeriodo): float
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(dias_tomados), 0) FROM vacaciones WHERE id_empleado = :e AND id_periodo = :p');
        $stmt->execute([':e' => $idEmpleado, ':p' => $idPeriodo]);
        return (float)$stmt->fetchColumn();
    }

    public function brutoEntre(int $idEmpleado, string $desde, string $hasta): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(n.salario_bruto), 0) FROM nominas n
               JOIN periodos_pago p ON p.id_periodo = n.id_periodo
              WHERE n.id_empleado = :e AND p.fecha_fin BETWEEN :d AND :h'
        );
        $stmt->execute([':e' => $idEmpleado, ':d' => $desde, ':h' => $hasta]);
        return (float)$stmt->fetchColumn();
    }

    /** @return array<int, array<string, mixed>> */
    public function lineasPrimeraQuincena(int $idEmpleado, string $inicioMes): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.tipo, i.monto FROM ingresos_nomina i
               JOIN nominas n ON n.id_nomina = i.id_nomina
               JOIN periodos_pago p ON p.id_periodo = n.id_periodo
              WHERE n.id_empleado = :e AND p.fecha_inicio = :m'
        );
        $stmt->execute([':e' => $idEmpleado, ':m' => $inicioMes]);
        return $stmt->fetchAll();
    }

    /** @return array{renta_hijos: int, renta_conyuge: int} */
    public function datosRentaEmpleado(int $idEmpleado): array
    {
        $stmt = $this->pdo->prepare('SELECT renta_hijos, renta_conyuge FROM empleados WHERE id_empleado = :e LIMIT 1');
        $stmt->execute([':e' => $idEmpleado]);
        $r = $stmt->fetch();
        return ['renta_hijos' => (int)($r['renta_hijos'] ?? 0), 'renta_conyuge' => (int)($r['renta_conyuge'] ?? 0)];
    }
}
