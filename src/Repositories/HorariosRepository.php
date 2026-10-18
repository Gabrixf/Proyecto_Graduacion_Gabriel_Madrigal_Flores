<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * HorariosRepository — SQL de `horarios` y su detalle `horario_dias`. Sin lógica de negocio.
 */
class HorariosRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** Horarios sin el centinela. @return array<int, array<string, mixed>> */
    public function findAll(): array
    {
        return $this->pdo->query(
            'SELECT id_horario, nombre, dias_semana FROM horarios WHERE id_horario <> 1 ORDER BY nombre'
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id_horario, nombre, dias_semana FROM horarios WHERE id_horario = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function findDias(int $idHorario): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT dia_semana, hora_entrada, hora_salida, minutos_almuerzo
               FROM horario_dias WHERE id_horario = :h ORDER BY dia_semana'
        );
        $stmt->execute([':h' => $idHorario]);
        return $stmt->fetchAll();
    }

    /** Días del horario asignado al empleado. @return array<int, array<string, mixed>> */
    public function findDiasPorEmpleado(int $idEmpleado): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.dia_semana, d.hora_entrada, d.hora_salida, d.minutos_almuerzo
               FROM empleados e
               JOIN horario_dias d ON d.id_horario = e.id_horario
              WHERE e.id_empleado = :e
              ORDER BY d.dia_semana'
        );
        $stmt->execute([':e' => $idEmpleado]);
        return $stmt->fetchAll();
    }

    public function existsByNombre(string $nombre, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM horarios WHERE nombre = :n';
        $params = [':n' => $nombre];
        if ($excludeId !== null) {
            $sql .= ' AND id_horario <> :id';
            $params[':id'] = $excludeId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /** @param array<int, array<string, mixed>> $dias filas para horario_dias */
    public function insert(string $nombre, string $diasSemana, array $dias): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('INSERT INTO horarios (nombre, dias_semana) VALUES (:n, :d)');
            $stmt->execute([':n' => $nombre, ':d' => $diasSemana]);
            $id = (int)$this->pdo->lastInsertId();
            $this->insertDias($id, $dias);
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<int, array<string, mixed>> $dias */
    public function update(int $id, string $nombre, string $diasSemana, array $dias): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('UPDATE horarios SET nombre = :n, dias_semana = :d WHERE id_horario = :id');
            $stmt->execute([':n' => $nombre, ':d' => $diasSemana, ':id' => $id]);
            $this->pdo->prepare('DELETE FROM horario_dias WHERE id_horario = :id')->execute([':id' => $id]);
            $this->insertDias($id, $dias);
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
            $this->pdo->prepare('DELETE FROM horario_dias WHERE id_horario = :id')->execute([':id' => $id]);
            $this->pdo->prepare('DELETE FROM horarios WHERE id_horario = :id')->execute([':id' => $id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function hasEmpleados(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM empleados WHERE id_horario = :id');
        $stmt->execute([':id' => $id]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /** @param array<int, array<string, mixed>> $dias */
    private function insertDias(int $idHorario, array $dias): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO horario_dias (id_horario, dia_semana, hora_entrada, hora_salida, minutos_almuerzo)
             VALUES (:h, :d, :e, :s, :a)'
        );
        foreach ($dias as $d) {
            $stmt->execute([
                ':h' => $idHorario,
                ':d' => $d['dia_semana'],
                ':e' => $d['hora_entrada'],
                ':s' => $d['hora_salida'],
                ':a' => $d['minutos_almuerzo'],
            ]);
        }
    }
}
