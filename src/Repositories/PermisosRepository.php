<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * PermisosRepository — SQL para `permisos`. Sin lógica de negocio.
 */
class PermisosRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(?int $idPeriodo = null, ?int $idEmpleado = null, ?string $q = null): array
    {
        $sql = "SELECT p.id_permiso, p.id_solicitud, p.id_empleado, p.id_periodo,
                       p.fecha_inicio, p.fecha_fin, p.con_goce_salarial, e.nombre, e.apellidos
                  FROM permisos p
                  JOIN empleados e ON e.id_empleado = p.id_empleado";
        $where  = [];
        $params = [];
        if ($idPeriodo !== null) {
            $where[] = 'p.id_periodo = :periodo';
            $params[':periodo'] = $idPeriodo;
        }
        if ($idEmpleado !== null) {
            $where[] = 'p.id_empleado = :empleado';
            $params[':empleado'] = $idEmpleado;
        }
        if ($q !== null && $q !== '') {
            $where[] = "(CONCAT(e.nombre, ' ', e.apellidos) LIKE :q OR CONCAT(e.apellidos, ', ', e.nombre) LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY p.fecha_inicio DESC, e.apellidos ASC';

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
            "SELECT p.*, e.nombre, e.apellidos
               FROM permisos p
               JOIN empleados e ON e.id_empleado = p.id_empleado
              WHERE p.id_permiso = :id
              LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function existsBySolicitud(int $idSolicitud, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM permisos WHERE id_solicitud = :s';
        $params = [':s' => $idSolicitud];
        if ($excludeId !== null) {
            $sql .= ' AND id_permiso <> :id';
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
        $sql = "SELECT s.id_solicitud, s.id_empleado, s.fecha_inicio, s.fecha_fin, s.motivo, e.nombre, e.apellidos
                  FROM solicitudes s
                  JOIN empleados e ON e.id_empleado = s.id_empleado
             LEFT JOIN permisos p ON p.id_solicitud = s.id_solicitud
                 WHERE s.tipo = 'permiso' AND s.estado = 'aprobada'
                   AND (p.id_permiso IS NULL";
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
     * @param array<string, mixed> $d
     */
    public function insert(array $d): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO permisos (id_solicitud, id_empleado, id_periodo, fecha_inicio, fecha_fin, con_goce_salarial)
             VALUES (:id_solicitud, :id_empleado, :id_periodo, :fecha_inicio, :fecha_fin, :con_goce_salarial)'
        );
        $stmt->execute([
            ':id_solicitud'      => $d['id_solicitud'],
            ':id_empleado'       => $d['id_empleado'],
            ':id_periodo'        => $d['id_periodo'],
            ':fecha_inicio'      => $d['fecha_inicio'],
            ':fecha_fin'         => $d['fecha_fin'],
            ':con_goce_salarial' => $d['con_goce_salarial'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, int $conGoce): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE permisos SET con_goce_salarial = :g WHERE id_permiso = :id'
        );
        $stmt->execute([':g' => $conGoce, ':id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM permisos WHERE id_permiso = :id');
        $stmt->execute([':id' => $id]);
    }
}
