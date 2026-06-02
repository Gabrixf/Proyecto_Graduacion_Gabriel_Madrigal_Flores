<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * IncapacidadesRepository — SQL para `incapacidades`. Sin lógica de negocio.
 */
class IncapacidadesRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(?int $idPeriodo = null, ?int $idEmpleado = null, ?string $tipo = null): array
    {
        $sql = "SELECT i.id_incapacidad, i.id_empleado, i.id_periodo, i.tipo,
                       i.fecha_inicio, i.fecha_fin, i.dias, i.documento_respaldo,
                       e.nombre, e.apellidos
                  FROM incapacidades i
                  JOIN empleados e ON e.id_empleado = i.id_empleado";
        $where  = [];
        $params = [];
        if ($idPeriodo !== null) {
            $where[] = 'i.id_periodo = :periodo';
            $params[':periodo'] = $idPeriodo;
        }
        if ($idEmpleado !== null) {
            $where[] = 'i.id_empleado = :empleado';
            $params[':empleado'] = $idEmpleado;
        }
        if ($tipo !== null) {
            $where[] = 'i.tipo = :tipo';
            $params[':tipo'] = $tipo;
        }
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY i.fecha_inicio DESC, e.apellidos ASC';

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
            "SELECT i.*, e.nombre, e.apellidos
               FROM incapacidades i
               JOIN empleados e ON e.id_empleado = i.id_empleado
              WHERE i.id_incapacidad = :id
              LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
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
            'INSERT INTO incapacidades (id_empleado, id_periodo, tipo, fecha_inicio, fecha_fin, dias, documento_respaldo)
             VALUES (:id_empleado, :id_periodo, :tipo, :fecha_inicio, :fecha_fin, :dias, :documento_respaldo)'
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
            'UPDATE incapacidades SET
                id_empleado = :id_empleado, id_periodo = :id_periodo, tipo = :tipo,
                fecha_inicio = :fecha_inicio, fecha_fin = :fecha_fin, dias = :dias,
                documento_respaldo = :documento_respaldo
              WHERE id_incapacidad = :id'
        );
        $params = $this->bind($d);
        $params[':id'] = $id;
        $stmt->execute($params);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM incapacidades WHERE id_incapacidad = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    private function bind(array $d): array
    {
        return [
            ':id_empleado'        => $d['id_empleado'],
            ':id_periodo'         => $d['id_periodo'],
            ':tipo'               => $d['tipo'],
            ':fecha_inicio'       => $d['fecha_inicio'],
            ':fecha_fin'          => $d['fecha_fin'],
            ':dias'               => $d['dias'],
            ':documento_respaldo' => $d['documento_respaldo'],
        ];
    }
}
