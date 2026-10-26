<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AguinaldoRepository;
use App\Repositories\AuditoriaRepository;
use App\Services\AguinaldoService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AguinaldoServicePagarTodosTest extends TestCase
{
    public function testPagarTodosMarcaLosCalculadosDelAnioYAuditaCadaUno(): void
    {
        $repo = $this->createMock(AguinaldoRepository::class);
        $repo->method('idsPorAnioYEstado')->with(2026, 'calculado')->willReturn([3, 5, 8]);
        $repo->expects(self::once())->method('marcarPagadosPorAnio')->with(2026, '2026-12-15')->willReturn(3);

        $auditoria = $this->createMock(AuditoriaRepository::class);
        $auditoria->expects(self::exactly(3))->method('insert')
            ->with('UPDATE', 1, 'aguinaldo', self::logicalOr(3, 5, 8), '127.0.0.1');

        $pagados = (new AguinaldoService($repo, $auditoria))->pagarTodos(2026, '2026-12-15', 1, '127.0.0.1');

        self::assertSame(3, $pagados);
    }

    public function testPagarTodosSinPendientesLanzaYNoCambiaNada(): void
    {
        $repo = $this->createMock(AguinaldoRepository::class);
        $repo->method('idsPorAnioYEstado')->willReturn([]);
        $repo->expects(self::never())->method('marcarPagadosPorAnio');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No hay aguinaldos por pagar en 2026');

        (new AguinaldoService($repo, $this->createMock(AuditoriaRepository::class)))
            ->pagarTodos(2026, '2026-12-15', 1, '127.0.0.1');
    }

    public function testResumenPorPagarCuentaYSumaSoloLosCalculados(): void
    {
        $service = new AguinaldoService(
            $this->createMock(AguinaldoRepository::class),
            $this->createMock(AuditoriaRepository::class)
        );

        $resumen = $service->resumenPorPagar([
            ['estado' => 'calculado', 'monto_aguinaldo' => '100000.10'],
            ['estado' => 'pagado',    'monto_aguinaldo' => '999999.00'],
            ['estado' => 'calculado', 'monto_aguinaldo' => '50000.20'],
        ]);

        self::assertSame(2, $resumen['cantidad']);
        self::assertSame(150000.3, $resumen['total']);
    }
}
