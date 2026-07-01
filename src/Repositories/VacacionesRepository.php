<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * VacacionesRepository — SQL para `vacaciones` y mantenimiento de `saldo_vacaciones`.
 * Las escrituras envuelven ambas tablas en una transacción (atomicidad de persistencia).
 */
class VacacionesRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(?int $idPeriodo = null, ?int $idEmpleado = null, ?string $q = null): array
    {
        $sql = "SELECT v.id_vacacion, v.id_solicitud, v.id_empleado, v.id_periodo,
                       v.fecha_inicio, v.fecha_fin, v.dias_tomados, e.nombre, e.apellidos
                  FROM vacaciones v
                  JOIN empleados e ON e.id_empleado = v.id_empleado";
        $where  = [];
        $params = [];
        if ($idPeriodo !== null) {
            $where[] = 'v.id_periodo = :periodo';
            $params[':periodo'] = $idPeriodo;
        }
        if ($idEmpleado !== null) {
            $where[] = 'v.id_empleado = :empleado';
            $params[':empleado'] = $idEmpleado;
        }
        if ($q !== null && $q !== '') {
            $where[] = "(CONCAT(e.nombre, ' ', e.apellidos) LIKE :q OR CONCAT(e.apellidos, ', ', e.nombre) LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY v.fecha_inicio DESC, e.apellidos ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT v.*, e.nombre, e.apellidos
               FROM vacaciones v
               JOIN empleados e ON e.id_empleado = v.id_empleado
              WHERE v.id_vacacion = :id
              LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function existsBySolicitud(int $idSolicitud, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM vacaciones WHERE id_solicitud = :s';
        $params = [':s' => $idSolicitud];
        if ($excludeId !== null) {
            $sql .= ' AND id_vacacion <> :id';
            $params[':id'] = $excludeId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findSolicitudesDisponibles(?int $incluirId = null): array
    {
        $sql = "SELECT s.id_solicitud, s.id_empleado, s.fecha_inicio, s.fecha_fin, e.nombre, e.apellidos
                  FROM solicitudes s
                  JOIN empleados e ON e.id_empleado = s.id_empleado
             LEFT JOIN vacaciones v ON v.id_solicitud = s.id_solicitud
                 WHERE s.tipo = 'vacaciones' AND s.estado = 'aprobada'
                   AND (v.id_vacacion IS NULL";
        $params = [];
        if ($incluirId !== null) {
            $sql .= ' OR s.id_solicitud = :incluir';
            $params[':incluir'] = $incluirId;
        }
        $sql .= ') ORDER BY s.fecha_inicio DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPeriodoAbiertoPorFecha(string $fecha): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id_periodo FROM periodos_pago
              WHERE :fecha BETWEEN fecha_inicio AND fecha_fin AND estado = 'abierto'
              LIMIT 1"
        );
        $stmt->execute([':fecha' => $fecha]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSaldo(int $idEmpleado, int $anio): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_saldo, dias_ganados, dias_disfrutados, dias_disponibles
               FROM saldo_vacaciones WHERE id_empleado = :e AND anio = :a LIMIT 1'
        );
        $stmt->execute([':e' => $idEmpleado, ':a' => $anio]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * @param array<string, mixed> $d  requiere claves: id_solicitud, id_empleado, id_periodo,
     *                                  fecha_inicio, fecha_fin, dias_tomados, anio
     */
    public function insert(array $d): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO vacaciones (id_solicitud, id_empleado, id_periodo, fecha_inicio, fecha_fin, dias_tomados)
                 VALUES (:id_solicitud, :id_empleado, :id_periodo, :fecha_inicio, :fecha_fin, :dias_tomados)'
            );
            $stmt->execute([
                ':id_solicitud' => $d['id_solicitud'],
                ':id_empleado'  => $d['id_empleado'],
                ':id_periodo'   => $d['id_periodo'],
                ':fecha_inicio' => $d['fecha_inicio'],
                ':fecha_fin'    => $d['fecha_fin'],
                ':dias_tomados' => $d['dias_tomados'],
            ]);
            $id = (int)$this->pdo->lastInsertId();
            $this->ajustarSaldo((int)$d['id_empleado'], (int)$d['anio'], (float)$d['dias_tomados']);
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function update(int $id, float $nuevoDias): void
    {
        $this->pdo->beginTransaction();
        try {
            $cur = $this->pdo->prepare(
                'SELECT id_empleado, fecha_inicio, dias_tomados FROM vacaciones WHERE id_vacacion = :id LIMIT 1'
            );
            $cur->execute([':id' => $id]);
            $row = $cur->fetch();
            if ($row === false) {
                throw new \RuntimeException('El registro de vacaciones ya no existe.');
            }
            $oldDias = (float)$row['dias_tomados'];
            $anio    = (int)substr((string)$row['fecha_inicio'], 0, 4);
            $upd = $this->pdo->prepare('UPDATE vacaciones SET dias_tomados = :d WHERE id_vacacion = :id');
            $upd->execute([':d' => $nuevoDias, ':id' => $id]);
            $this->ajustarSaldo((int)$row['id_empleado'], $anio, $nuevoDias - $oldDias);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        $this->pdo->beginTransaction();
        try {
            $cur = $this->pdo->prepare(
                'SELECT id_empleado, fecha_inicio, dias_tomados FROM vacaciones WHERE id_vacacion = :id LIMIT 1'
            );
            $cur->execute([':id' => $id]);
            $row = $cur->fetch();
            if ($row === false) {
                throw new \RuntimeException('El registro de vacaciones ya no existe.');
            }
            $anio = (int)substr((string)$row['fecha_inicio'], 0, 4);
            $del = $this->pdo->prepare('DELETE FROM vacaciones WHERE id_vacacion = :id');
            $del->execute([':id' => $id]);
            $this->ajustarSaldo((int)$row['id_empleado'], $anio, -(float)$row['dias_tomados']);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Upsert del saldo del año: suma delta a disfrutados y recalcula disponibles.
     * Debe llamarse dentro de una transacción activa.
     */
    private function ajustarSaldo(int $idEmpleado, int $anio, float $delta): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_saldo, dias_ganados, dias_disfrutados FROM saldo_vacaciones
              WHERE id_empleado = :e AND anio = :a LIMIT 1'
        );
        $stmt->execute([':e' => $idEmpleado, ':a' => $anio]);
        $row = $stmt->fetch();

        if ($row !== false) {
            $disfrutados = (float)$row['dias_disfrutados'] + $delta;
            if ($disfrutados < 0) {
                $disfrutados = 0.0;
            }
            $disponibles = (float)$row['dias_ganados'] - $disfrutados;
            $upd = $this->pdo->prepare(
                'UPDATE saldo_vacaciones SET dias_disfrutados = :d, dias_disponibles = :p WHERE id_saldo = :id'
            );
            $upd->execute([':d' => $disfrutados, ':p' => $disponibles, ':id' => (int)$row['id_saldo']]);
        } else {
            $disfrutados = $delta > 0 ? $delta : 0.0;
            $disponibles = 0.0 - $disfrutados;
            $ins = $this->pdo->prepare(
                'INSERT INTO saldo_vacaciones (id_empleado, anio, dias_ganados, dias_disfrutados, dias_disponibles)
                 VALUES (:e, :a, 0, :d, :p)'
            );
            $ins->execute([':e' => $idEmpleado, ':a' => $anio, ':d' => $disfrutados, ':p' => $disponibles]);
        }
    }
}
