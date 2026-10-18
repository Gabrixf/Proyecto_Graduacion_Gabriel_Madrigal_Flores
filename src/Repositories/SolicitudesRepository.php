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
    public function findAll(?string $tipo = null, ?string $estado = null, ?string $q = null, ?int $idEmpleado = null, ?string $origen = null): array
    {
        $sql = "SELECT s.id_solicitud, s.id_empleado, s.tipo, s.fecha_inicio, s.fecha_fin,
                       s.horas, s.motivo, s.estado, s.fecha_solicitud, s.fecha_resolucion,
                       s.observacion_admin, s.origen, per.nombre, per.apellidos
                  FROM solicitudes s
                  JOIN empleados e ON e.id_empleado = s.id_empleado
                  JOIN persona per ON per.id_persona = e.id_persona";
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
            $where[] = "(CONCAT(per.nombre, ' ', per.apellidos) LIKE :q OR CONCAT(per.apellidos, ', ', per.nombre) LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }
        if ($idEmpleado !== null) {
            $where[] = 's.id_empleado = :idEmpleado';
            $params[':idEmpleado'] = $idEmpleado;
        }
        if ($origen !== null) {
            $where[] = 's.origen = :origen';
            $params[':origen'] = $origen;
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
     * Si $idEmpleado se indica, la fila solo se devuelve cuando pertenece a ese
     * empleado (filtrado en la propia consulta, no después de traerla).
     * @return array<string, mixed>|null
     */
    public function findById(int $id, ?int $idEmpleado = null): ?array
    {
        $sql = "SELECT s.*, per.nombre, per.apellidos
                   FROM solicitudes s
                   JOIN empleados e ON e.id_empleado = s.id_empleado
                   JOIN persona per ON per.id_persona = e.id_persona
                  WHERE s.id_solicitud = :id";
        $params = [':id' => $id];
        if ($idEmpleado !== null) {
            $sql .= ' AND s.id_empleado = :idEmpleado';
            $params[':idEmpleado'] = $idEmpleado;
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Solicitudes de vacaciones ya comprometidas (pendientes + aprobadas) del empleado.
     * El conteo de días (hábiles, sin feriados) lo hace DiasHabilesService.
     * @return array<int, array{fecha_inicio: string, fecha_fin: string, horas: string}>
     */
    public function vacacionesComprometidas(int $idEmpleado, ?int $excluirSolicitud = null): array
    {
        $sql = "SELECT fecha_inicio, fecha_fin, horas
                  FROM solicitudes
                 WHERE id_empleado = :e AND tipo = 'vacaciones'
                   AND estado IN ('pendiente', 'aprobada')";
        $params = [':e' => $idEmpleado];
        if ($excluirSolicitud !== null) {
            $sql .= ' AND id_solicitud <> :x';
            $params[':x'] = $excluirSolicitud;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Solicitud de horas extra generada por la asistencia. */
    public function insertDetectada(int $idEmpleado, string $fecha, float $horas, string $motivo, string $estado): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO solicitudes (id_empleado, tipo, fecha_inicio, fecha_fin, horas, motivo, estado, origen)
             VALUES (:e, 'horas_extra', :f, :f2, :h, :m, :s, 'asistencia')"
        );
        $stmt->execute([':e' => $idEmpleado, ':f' => $fecha, ':f2' => $fecha, ':h' => $horas, ':m' => $motivo, ':s' => $estado]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findDetectada(int $idEmpleado, string $fecha): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM solicitudes
              WHERE id_empleado = :e AND fecha_inicio = :f AND tipo = 'horas_extra'
                AND origen = 'asistencia' AND estado <> 'rechazada'
              LIMIT 1"
        );
        $stmt->execute([':e' => $idEmpleado, ':f' => $fecha]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function actualizarHoras(int $id, float $horas): void
    {
        $this->pdo->prepare('UPDATE solicitudes SET horas = :h WHERE id_solicitud = :id')
            ->execute([':h' => $horas, ':id' => $id]);
    }

    public function justificar(int $id, string $motivo): void
    {
        $this->pdo->prepare("UPDATE solicitudes SET motivo = :m, estado = 'pendiente' WHERE id_solicitud = :id")
            ->execute([':m' => $motivo, ':id' => $id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function findPorJustificarEntre(string $desde, string $hasta): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id_solicitud FROM solicitudes
              WHERE estado = 'por_justificar' AND fecha_inicio BETWEEN :d AND :h"
        );
        $stmt->execute([':d' => $desde, ':h' => $hasta]);
        return $stmt->fetchAll();
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
