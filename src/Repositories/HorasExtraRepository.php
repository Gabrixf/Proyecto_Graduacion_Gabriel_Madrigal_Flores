<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * HorasExtraRepository — SQL para `horas_extra` y lookups asociados.
 */
class HorasExtraRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(?int $idPeriodo = null, ?int $idEmpleado = null): array
    {
        $sql = "SELECT he.id_hora_extra, he.id_solicitud, he.id_empleado, he.id_periodo,
                       he.fecha, he.cantidad_horas, he.factor_recargo, e.nombre, e.apellidos
                  FROM horas_extra he
                  JOIN empleados e ON e.id_empleado = he.id_empleado";
        $where  = [];
        $params = [];
        if ($idPeriodo !== null) {
            $where[] = 'he.id_periodo = :periodo';
            $params[':periodo'] = $idPeriodo;
        }
        if ($idEmpleado !== null) {
            $where[] = 'he.id_empleado = :empleado';
            $params[':empleado'] = $idEmpleado;
        }
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY he.fecha DESC, e.apellidos ASC';

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
            "SELECT he.*, e.nombre, e.apellidos
               FROM horas_extra he
               JOIN empleados e ON e.id_empleado = he.id_empleado
              WHERE he.id_hora_extra = :id
              LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function existsBySolicitud(int $idSolicitud, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM horas_extra WHERE id_solicitud = :s';
        $params = [':s' => $idSolicitud];
        if ($excludeId !== null) {
            $sql .= ' AND id_hora_extra <> :id';
            $params[':id'] = $excludeId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Solicitudes horas_extra aprobadas sin HE asociada (más la actual al editar).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findSolicitudesDisponibles(?int $incluirId = null): array
    {
        $sql = "SELECT s.id_solicitud, s.id_empleado, s.fecha_inicio, s.horas, e.nombre, e.apellidos
                  FROM solicitudes s
                  JOIN empleados e ON e.id_empleado = s.id_empleado
             LEFT JOIN horas_extra he ON he.id_solicitud = s.id_solicitud
                 WHERE s.tipo = 'horas_extra' AND s.estado = 'aprobada'
                   AND (he.id_hora_extra IS NULL";
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

    public function esFeriado(string $fecha): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM feriados WHERE fecha = :f');
        $stmt->execute([':f' => $fecha]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * @param array<string, mixed> $d
     */
    public function insert(array $d): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO horas_extra (id_solicitud, id_empleado, id_periodo, fecha, cantidad_horas, factor_recargo)
             VALUES (:id_solicitud, :id_empleado, :id_periodo, :fecha, :cantidad_horas, :factor_recargo)'
        );
        $stmt->execute([
            ':id_solicitud'   => $d['id_solicitud'],
            ':id_empleado'    => $d['id_empleado'],
            ':id_periodo'     => $d['id_periodo'],
            ':fecha'          => $d['fecha'],
            ':cantidad_horas' => $d['cantidad_horas'],
            ':factor_recargo' => $d['factor_recargo'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, float $cantidadHoras, float $factorRecargo): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE horas_extra SET cantidad_horas = :c, factor_recargo = :f WHERE id_hora_extra = :id'
        );
        $stmt->execute([':c' => $cantidadHoras, ':f' => $factorRecargo, ':id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM horas_extra WHERE id_hora_extra = :id');
        $stmt->execute([':id' => $id]);
    }
}
