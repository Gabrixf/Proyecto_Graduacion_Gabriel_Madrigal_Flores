<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class FeriadosRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findAll(): array
    {
        return $this->pdo->query(
            'SELECT id_feriado, fecha, nombre, tipo FROM feriados ORDER BY fecha'
        )->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_feriado, fecha, nombre, tipo FROM feriados WHERE id_feriado = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function insert(string $fecha, string $nombre, string $tipo): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO feriados (fecha, nombre, tipo) VALUES (:fecha, :nombre, :tipo)'
        );
        $stmt->execute([':fecha' => $fecha, ':nombre' => $nombre, ':tipo' => $tipo]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, string $fecha, string $nombre, string $tipo): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE feriados SET fecha = :fecha, nombre = :nombre, tipo = :tipo
             WHERE id_feriado = :id'
        );
        $stmt->execute([':fecha' => $fecha, ':nombre' => $nombre, ':tipo' => $tipo, ':id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM feriados WHERE id_feriado = :id');
        $stmt->execute([':id' => $id]);
    }
}
