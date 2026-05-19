<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class UsuariosRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findByNombreUsuario(string $nombreUsuario): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_usuario, nombre_usuario, contrasena_hash, rol, activo
             FROM usuarios WHERE nombre_usuario = :nombre LIMIT 1'
        );
        $stmt->execute([':nombre' => $nombreUsuario]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function findAll(): array
    {
        return $this->pdo->query(
            'SELECT id_usuario, nombre_usuario, rol, activo, fecha_creacion
             FROM usuarios ORDER BY nombre_usuario'
        )->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_usuario, nombre_usuario, rol, activo, fecha_creacion
             FROM usuarios WHERE id_usuario = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function insert(string $nombreUsuario, string $hash, string $rol): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO usuarios (nombre_usuario, contrasena_hash, rol)
             VALUES (:nombre, :hash, :rol)'
        );
        $stmt->execute([':nombre' => $nombreUsuario, ':hash' => $hash, ':rol' => $rol]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, string $nombreUsuario, string $rol, int $activo): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE usuarios
             SET nombre_usuario = :nombre, rol = :rol, activo = :activo
             WHERE id_usuario = :id'
        );
        $stmt->execute([
            ':nombre' => $nombreUsuario,
            ':rol'    => $rol,
            ':activo' => $activo,
            ':id'     => $id,
        ]);
    }

    public function updatePassword(int $id, string $hash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE usuarios SET contrasena_hash = :hash WHERE id_usuario = :id'
        );
        $stmt->execute([':hash' => $hash, ':id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM usuarios WHERE id_usuario = :id');
        $stmt->execute([':id' => $id]);
    }

    public function hasLinkedEmpleado(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM empleados WHERE id_usuario = :id'
        );
        $stmt->execute([':id' => $id]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
