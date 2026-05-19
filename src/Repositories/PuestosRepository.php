<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * PuestosRepository
 *
 * Responsabilidad única: acceso a la tabla `puestos` vía PDO.
 * No contiene lógica de negocio; eso pertenece al Service.
 *
 * Tabla: puestos
 *   id_puesto    INT PK AUTO_INCREMENT
 *   nombre       VARCHAR(100) UNIQUE NOT NULL
 *   salario_base DECIMAL(10,2) NOT NULL
 *   descripcion  VARCHAR(255) NULL
 */
class PuestosRepository
{
    public function __construct(private readonly PDO $pdo) {}

    // ── Lectura ───────────────────────────────────────────

    /**
     * Retorna todos los puestos ordenados por nombre.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id_puesto, nombre, salario_base, descripcion
               FROM puestos
              ORDER BY nombre ASC'
        );
        return $stmt->fetchAll();
    }

    /**
     * Retorna un puesto por su ID, o null si no existe.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_puesto, nombre, salario_base, descripcion
               FROM puestos
              WHERE id_puesto = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Verifica si ya existe un puesto con ese nombre (excluyendo un ID dado,
     * útil al editar para no chocar con uno mismo).
     */
    public function existsByNombre(string $nombre, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM puestos WHERE nombre = :nombre';
        $params = [':nombre' => $nombre];

        if ($excludeId !== null) {
            $sql .= ' AND id_puesto <> :id';
            $params[':id'] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    // ── Escritura ─────────────────────────────────────────

    /**
     * Inserta un nuevo puesto y retorna el ID generado.
     */
    public function insert(string $nombre, float $salarioBase, ?string $descripcion): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO puestos (nombre, salario_base, descripcion)
             VALUES (:nombre, :salario_base, :descripcion)'
        );
        $stmt->execute([
            ':nombre'       => $nombre,
            ':salario_base' => $salarioBase,
            ':descripcion'  => $descripcion,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Actualiza los datos de un puesto existente.
     * Retorna true si se modificó al menos 1 fila.
     */
    public function update(int $id, string $nombre, float $salarioBase, ?string $descripcion): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE puestos
                SET nombre       = :nombre,
                    salario_base = :salario_base,
                    descripcion  = :descripcion
              WHERE id_puesto    = :id'
        );
        $stmt->execute([
            ':nombre'       => $nombre,
            ':salario_base' => $salarioBase,
            ':descripcion'  => $descripcion,
            ':id'           => $id,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Elimina un puesto por ID.
     * ⚠ Verificar antes que no tenga empleados asociados (lo hace el Service).
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM puestos WHERE id_puesto = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Verifica si el puesto tiene empleados activos asignados
     * (restricción de integridad antes de eliminar).
     */
    public function hasEmpleados(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM empleados WHERE id_puesto = :id'
        );
        $stmt->execute([':id' => $id]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
