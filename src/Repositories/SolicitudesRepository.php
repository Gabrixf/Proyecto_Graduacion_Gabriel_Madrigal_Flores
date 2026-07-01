<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * SolicitudesRepository — SQL para la tabla `solicitudes`. Sin lógica de negocio.
 */
class SolicitudesRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(?string $tipo = null, ?string $estado = null, ?string $q = null): array
    {
        $sql = "SELECT s.id_solicitud, s.id_empleado, s.tipo, s.fecha_inicio, s.fecha_fin,
                       s.horas, s.motivo, s.estado, s.fecha_solicitud, s.fecha_resolucion,
                       s.observacion_admin, e.nombre, e.apellidos
                  FROM solicitudes s
                  JOIN empleados e ON e.id_empleado = s.id_empleado";
        $where  = [];
        $params = [];
        if ($tipo !== null) {
            $where[] = 's.tipo = :tipo';
            $params[':tipo'] = $tipo;
        }
        if ($estado !== null) {
            $where[] = 's.estado = :estado';
            $params[':estado'] = $estado;
        }
        if ($q !== null && $q !== '') {
            $where[] = "(CONCAT(e.nombre, ' ', e.apellidos) LIKE :q OR CONCAT(e.apellidos, ', ', e.nombre) LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY s.fecha_solicitud DESC';

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
            "SELECT s.*, e.nombre, e.apellidos
               FROM solicitudes s
               JOIN empleados e ON e.id_empleado = s.id_empleado
              WHERE s.id_solicitud = :id
              LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * @param array<string, mixed> $d
     */
    public function insert(array $d): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO solicitudes (id_empleado, tipo, fecha_inicio, fecha_fin, horas, motivo)
             VALUES (:id_empleado, :tipo, :fecha_inicio, :fecha_fin, :horas, :motivo)'
        );
        $stmt->execute([
            ':id_empleado'  => $d['id_empleado'],
            ':tipo'         => $d['tipo'],
            ':fecha_inicio' => $d['fecha_inicio'],
            ':fecha_fin'    => $d['fecha_fin'],
            ':horas'        => $d['horas'],
            ':motivo'       => $d['motivo'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $d
     */
    public function update(int $id, array $d): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE solicitudes SET
                id_empleado = :id_empleado, tipo = :tipo, fecha_inicio = :fecha_inicio,
                fecha_fin = :fecha_fin, horas = :horas, motivo = :motivo
              WHERE id_solicitud = :id'
        );
        $stmt->execute([
            ':id_empleado'  => $d['id_empleado'],
            ':tipo'         => $d['tipo'],
            ':fecha_inicio' => $d['fecha_inicio'],
            ':fecha_fin'    => $d['fecha_fin'],
            ':horas'        => $d['horas'],
            ':motivo'       => $d['motivo'],
            ':id'           => $id,
        ]);
    }

    public function resolver(int $id, string $estado, int $idUsuario, ?string $obs): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE solicitudes SET
                estado = :estado, fecha_resolucion = NOW(),
                id_usuario_resuelve = :usuario, observacion_admin = :obs
              WHERE id_solicitud = :id'
        );
        $stmt->execute([
            ':estado'  => $estado,
            ':usuario' => $idUsuario,
            ':obs'     => $obs,
            ':id'      => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM solicitudes WHERE id_solicitud = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * Cuenta registros derivados (HE/vacaciones/permisos) que referencian la solicitud.
     */
    public function countDependientes(int $id): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM horas_extra WHERE id_solicitud = :id1) +
                (SELECT COUNT(*) FROM vacaciones  WHERE id_solicitud = :id2) +
                (SELECT COUNT(*) FROM permisos    WHERE id_solicitud = :id3) AS total'
        );
        $stmt->execute([':id1' => $id, ':id2' => $id, ':id3' => $id]);
        return (int)$stmt->fetchColumn();
    }
}
