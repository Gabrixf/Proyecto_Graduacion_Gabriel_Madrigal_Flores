<?php

declare(strict_types=1);

namespace Tests\Repositories;

use App\Repositories\AuditoriaRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AuditoriaRepositoryTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $params = null;

    private function repo(): AuditoriaRepository
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturnCallback(function (array $p) {
            $this->params = $p;
            return true;
        });
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        return new AuditoriaRepository($pdo, $this->createMock(LoggerInterface::class));
    }

    public function testSinDetalleGuardaTextoVacio(): void
    {
        // El esquema no acepta NULL: antes la fila se perdía y solo quedaba un error en el log.
        $this->repo()->insert('INSERT', 3, 'asistencia', 30, '127.0.0.1');

        self::assertSame('', $this->params[':detalle']);
    }

    public function testSinRegistroGuardaCero(): void
    {
        $this->repo()->insert('LOGIN', 3, 'usuarios', null, '127.0.0.1');

        self::assertSame(0, $this->params[':id_registro']);
        self::assertSame('', $this->params[':detalle']);
    }

    public function testConDetalleLoConserva(): void
    {
        $this->repo()->insert('UPDATE', 2, 'contratos', 1, '127.0.0.1', 'Cierre por renovación');

        self::assertSame('Cierre por renovación', $this->params[':detalle']);
    }
}
