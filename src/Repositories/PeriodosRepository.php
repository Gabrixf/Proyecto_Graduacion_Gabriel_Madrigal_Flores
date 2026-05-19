<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class PeriodosRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findAll(): array
    {
        return $this->pdo->query(
            'SELECT id_periodo, fecha_inicio, fecha_fin, estado
             FROM periodos_pago ORDER BY fecha_inicio DESC'
        )->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_periodo, fecha_inicio, fecha_fin, estado
             FROM periodos_pago WHERE id_periodo = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function findLatest(): ?array
    {
        $row = $this->pdo->query(
            'SELECT fecha_inicio, fecha_fin FROM periodos_pago ORDER BY fecha_inicio DESC LIMIT 1'
        )->fetch();
        return $row !== false ? $row : null;
    }

    public function findOverlapping(string $fechaInicio, string $fechaFin, ?int $excludeId = null): array
    {
        $sql    = 'SELECT id_periodo FROM periodos_pago
                   WHERE fecha_inicio <= :fin AND fecha_fin >= :inicio';
        $params = [':inicio' => $fechaInicio, ':fin' => $fechaFin];

        if ($excludeId !== null) {
            $sql .= ' AND id_periodo != :excludeId';
            $params[':excludeId'] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function insert(string $fechaInicio, string $fechaFin): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO periodos_pago (fecha_inicio, fecha_fin, estado)
             VALUES (:inicio, :fin, :estado)'
        );
        $stmt->execute([':inicio' => $fechaInicio, ':fin' => $fechaFin, ':estado' => 'abierto']);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, string $fechaInicio, string $fechaFin, string $estado): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE periodos_pago
             SET fecha_inicio = :inicio, fecha_fin = :fin, estado = :estado
             WHERE id_periodo = :id'
        );
        $stmt->execute([
            ':inicio' => $fechaInicio,
            ':fin'    => $fechaFin,
            ':estado' => $estado,
            ':id'     => $id,
        ]);
    }

    public function hasLinkedRecords(int $id): bool
    {
        foreach (['asistencia', 'horas_extra', 'vacaciones', 'incapacidades', 'permisos'] as $table) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE id_periodo = :id");
            $stmt->execute([':id' => $id]);
            if ((int)$stmt->fetchColumn() > 0) {
                return true;
            }
        }
        return false;
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM periodos_pago WHERE id_periodo = :id');
        $stmt->execute([':id' => $id]);
    }
}
