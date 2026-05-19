<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Fábrica de conexión PDO.
 *
 * Usa el patrón Singleton por proceso (una sola instancia compartida)
 * para evitar abrir múltiples conexiones en la misma petición HTTP.
 */
final class Connection
{
    private static ?PDO $instance = null;

    /**
     * No permitir instanciación directa.
     */
    private function __construct() {}

    /**
     * Retorna la instancia PDO configurada.
     *
     * @param array<string,string> $config Arreglo con host, port, dbname, user, pass, charset
     */
    public static function create(array $config): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['host']    ?? '127.0.0.1',
                $config['port']    ?? '3306',
                $config['dbname']  ?? 'lubrimotos_nomina',
                $config['charset'] ?? 'utf8mb4'
            );

            try {
                self::$instance = new PDO(
                    $dsn,
                    $config['user'] ?? 'root',
                    $config['pass'] ?? '',
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                    ]
                );
            } catch (PDOException $e) {
                // No exponer credenciales al usuario; sólo loguear internamente
                throw new RuntimeException(
                    'No se pudo conectar a la base de datos. Verifique la configuración en .env.',
                    (int)$e->getCode(),
                    $e
                );
            }
        }

        return self::$instance;
    }

    /**
     * Resetear la instancia (útil en tests).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
