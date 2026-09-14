<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * DistritosRepository
 *
 * Catálogo geográfico de solo lectura (provincias/cantones/distritos ya
 * vienen sembrados en schema.sql/seed.sql). Sin Service ni Controller propio:
 * es dato de referencia fijo, no un módulo administrable.
 */
class DistritosRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Todos los distritos reales (excluye el centinela id=1), con el nombre
     * de su cantón y provincia, para agrupar el <select> del formulario.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllConJerarquia(): array
    {
        return $this->pdo->query(
            'SELECT d.id_distrito, d.nombre AS distrito_nombre,
                    c.nombre AS canton_nombre, p.nombre AS provincia_nombre
               FROM distritos d
               JOIN cantones c ON c.id_canton = d.id_canton
               JOIN provincias p ON p.id_provincia = c.id_provincia
              WHERE d.id_distrito <> 1
           ORDER BY p.nombre ASC, c.nombre ASC, d.nombre ASC'
        )->fetchAll();
    }

    /**
     * Existe el id_distrito (incluye el centinela id=1 — es una fila real).
     */
    public function exists(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM distritos WHERE id_distrito = :id');
        $stmt->execute([':id' => $id]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
