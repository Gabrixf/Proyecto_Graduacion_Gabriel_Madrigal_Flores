<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use Psr\Log\LoggerInterface;

class AuditoriaRepository
{
    public function __construct(
        private readonly PDO             $pdo,
        private readonly LoggerInterface $logger
    ) {}

    public function insert(
        string  $accion,
        int     $idUsuario,
        string  $tablaAfectada,
        ?int    $idRegistro,
        string  $ipOrigen,
        ?string $detalle = null
    ): void {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO auditoria
                     (id_usuario, tabla_afectada, accion, id_registro, ip_origen, detalle)
                 VALUES
                     (:id_usuario, :tabla_afectada, :accion, :id_registro, :ip_origen, :detalle)'
            );
            $stmt->execute([
                ':id_usuario'     => $idUsuario,
                ':tabla_afectada' => $tablaAfectada,
                ':accion'         => $accion,
                ':id_registro'    => $idRegistro,
                ':ip_origen'      => $ipOrigen,
                ':detalle'        => $detalle,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Auditoria insert failed: ' . $e->getMessage(), [
                'accion'    => $accion,
                'idUsuario' => $idUsuario,
            ]);
        }
    }
}
