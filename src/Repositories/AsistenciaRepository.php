<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * AsistenciaRepository — acceso SQL a la tabla `asistencia` y lookups
 * de periodo/feriado por fecha. Sin lógica de negocio.
 */
class AsistenciaRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(?int $idPeriodo = null, ?int $idEmpleado = null): array
    {
        $sql = "SELECT a.id_asistencia, a.id_empleado, a.id_periodo, a.id_feriado,
                       a.fecha, a.hora_entrada, a.hora_salida, a.horas_trabajadas,
                       e.nombre, e.apellidos, f.nombre AS feriado_nombre
                  FROM asistencia a
                  JOIN empleados e ON e.id_empleado = a.id_empleado
             LEFT JOIN feriados  f ON f.id_feriado  = a.id_feriado";
        $where  = [];
        $params = [];
        if ($idPeriodo !== null) {
            $where[] = 'a.id_periodo = :periodo';
            $params[':periodo'] = $idPeriodo;
        }
        if ($idEmpleado !== null) {
            $where[] = 'a.id_empleado = :empleado';
            $params[':empleado'] = $idEmpleado;
        }
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY a.fecha DESC, e.apellidos ASC';

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
            "SELECT a.*, e.nombre, e.apellidos, f.nombre AS feriado_nombre
               FROM asistencia a
               JOIN empleados e ON e.id_empleado = a.id_empleado
          LEFT JOIN feriados  f ON f.id_feriado  = a.id_feriado
              WHERE a.id_asistencia = :id
              LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function existsByEmpleadoFecha(int $idEmpleado, string $fecha, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM asistencia WHERE id_empleado = :emp AND fecha = :fecha';
        $params = [':emp' => $idEmpleado, ':fecha' => $fecha];
        if ($excludeId !== null) {
            $sql .= ' AND id_asistencia <> :id';
            $params[':id'] = $excludeId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Periodo en estado 'abierto' que contiene la fecha, o null.
     *
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

    public function findFeriadoIdPorFecha(string $fecha): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id_feriado FROM feriados WHERE fecha = :fecha LIMIT 1');
        $stmt->execute([':fecha' => $fecha]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int)$val : null;
    }

    /**
     * @param array<string, mixed> $d
     */
    public function insert(array $d): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO asistencia
                (id_empleado, id_periodo, id_feriado, fecha, hora_entrada, hora_salida, horas_trabajadas)
             VALUES
                (:id_empleado, :id_periodo, :id_feriado, :fecha, :hora_entrada, :hora_salida, :horas_trabajadas)'
        );
        $stmt->execute($this->bind($d));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $d
     */
    public function update(int $id, array $d): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE asistencia SET
                id_empleado = :id_empleado, id_periodo = :id_periodo, id_feriado = :id_feriado,
                fecha = :fecha, hora_entrada = :hora_entrada, hora_salida = :hora_salida,
                horas_trabajadas = :horas_trabajadas
              WHERE id_asistencia = :id'
        );
        $params = $this->bind($d);
        $params[':id'] = $id;
        $stmt->execute($params);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM asistencia WHERE id_asistencia = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    private function bind(array $d): array
    {
        return [
            ':id_empleado'      => $d['id_empleado'],
            ':id_periodo'       => $d['id_periodo'],
            ':id_feriado'       => $d['id_feriado'],
            ':fecha'            => $d['fecha'],
            ':hora_entrada'     => $d['hora_entrada'],
            ':hora_salida'      => $d['hora_salida'],
            ':horas_trabajadas' => $d['horas_trabajadas'],
        ];
    }
}
