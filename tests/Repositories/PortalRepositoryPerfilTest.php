<?php

declare(strict_types=1);

namespace Tests\Repositories;

use App\Repositories\PortalRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Desde la migración a `persona` (14/09/2026) los datos de identidad y contacto ya no
 * están en `empleados`: Mi perfil debe leerlos de `persona`.
 */
final class PortalRepositoryPerfilTest extends TestCase
{
    private string $sql = '';

    public function testPerfilLeeLosDatosPersonalesDePersona(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($stmt) {
            $this->sql = $sql;
            return $stmt;
        });

        (new PortalRepository($pdo))->perfil(6);

        self::assertMatchesRegularExpression('/JOIN\s+persona\s+per\s+ON\s+per\.id_persona\s*=\s*e\.id_persona/i', $this->sql);
        foreach (['nombre', 'apellidos', 'cedula', 'fecha_nacimiento', 'genero', 'estado_civil', 'nacionalidad',
                  'telefono', 'correo', 'direccion'] as $campo) {
            self::assertStringContainsString('per.' . $campo, $this->sql, "Falta per.$campo");
        }
    }
}
