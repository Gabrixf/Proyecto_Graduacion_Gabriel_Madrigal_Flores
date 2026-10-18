<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * ParametrosLegalesRepository — SQL de `parametros_legales`. Sin lógica de negocio.
 */
class ParametrosLegalesRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function valorVigente(string $clave, string $fecha): ?float
    {
        $stmt = $this->pdo->prepare(
            'SELECT valor FROM parametros_legales
              WHERE clave = :c AND :f BETWEEN fecha_inicio AND fecha_fin
              ORDER BY fecha_inicio DESC LIMIT 1'
        );
        $stmt->execute([':c' => $clave, ':f' => $fecha]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (float)$v;
    }
}
