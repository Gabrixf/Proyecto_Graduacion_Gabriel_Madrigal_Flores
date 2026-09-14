<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\ContratosRepository;
use App\Services\ContratosService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ContratosServiceTest extends TestCase
{
    private function service(ContratosRepository $repo): ContratosService
    {
        return new ContratosService($repo, $this->createMock(AuditoriaRepository::class));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function datosValidos(array $overrides = []): array
    {
        return array_merge([
            'id_empleado'      => '1',
            'tipo_contrato'    => 'tiempo_indefinido',
            'salario_pactado'  => '450000.00',
            'jornada'          => 'tiempo_completo',
            'fecha_inicio'     => '2026-01-01',
        ], $overrides);
    }

    public function testCrearInsertaComoActivoConFechaFinCentinela(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('esEmpleadoActivo')->willReturn(true);
        $repo->method('findActivoPorEmpleado')->willReturn(null);
        $repo->expects(self::once())->method('insert')
            ->with(self::callback(fn(array $d) =>
                $d['id_empleado'] === 1 && $d['estado'] === 'activo' && $d['fecha_fin'] === '9999-12-31'
                && $d['tipo_contrato'] === 'tiempo_indefinido' && $d['salario_pactado'] === 450000.0
            ))
            ->willReturn(10);

        $id = $this->service($repo)->crear($this->datosValidos(), 1, '127.0.0.1');

        self::assertSame(10, $id);
    }

    public function testCrearRechazaEmpleadoInexistenteOInactivo(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('esEmpleadoActivo')->willReturn(false);
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('empleado activo válido');

        $this->service($repo)->crear($this->datosValidos(), 1, '127.0.0.1');
    }

    public function testCrearRechazaEmpleadoConContratoYaActivo(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('esEmpleadoActivo')->willReturn(true);
        $repo->method('findActivoPorEmpleado')->willReturn(['id_contrato' => 5]);
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Renovar');

        $this->service($repo)->crear($this->datosValidos(), 1, '127.0.0.1');
    }

    public function testCrearRechazaTipoContratoInvalido(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('esEmpleadoActivo')->willReturn(true);
        $repo->method('findActivoPorEmpleado')->willReturn(null);
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);

        $this->service($repo)->crear($this->datosValidos(['tipo_contrato' => 'no_existe']), 1, '127.0.0.1');
    }

    public function testCrearRechazaSalarioNoPositivo(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('esEmpleadoActivo')->willReturn(true);
        $repo->method('findActivoPorEmpleado')->willReturn(null);
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);

        $this->service($repo)->crear($this->datosValidos(['salario_pactado' => '0']), 1, '127.0.0.1');
    }

    public function testActualizarLanzaSiNoExiste(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('update');

        $this->expectException(RuntimeException::class);

        $this->service($repo)->actualizar(99, $this->datosValidos(), 1, '127.0.0.1');
    }

    public function testRenovarLanzaSiElContratoNoEstaActivo(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('findById')->willReturn([
            'id_contrato' => 1, 'id_empleado' => 1, 'estado' => 'finalizado', 'fecha_inicio' => '2020-01-01',
        ]);
        $repo->expects(self::never())->method('renovar');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('vigente');

        $this->service($repo)->renovar(1, $this->datosValidos(['fecha_inicio' => '2026-01-01']), 1, '127.0.0.1');
    }

    public function testRenovarRechazaFechaInicioNoPosteriorALaActual(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('findById')->willReturn([
            'id_contrato' => 1, 'id_empleado' => 1, 'estado' => 'activo', 'fecha_inicio' => '2026-01-01',
        ]);
        $repo->expects(self::never())->method('renovar');

        $this->expectException(InvalidArgumentException::class);

        $this->service($repo)->renovar(1, $this->datosValidos(['fecha_inicio' => '2025-12-31']), 1, '127.0.0.1');
    }

    public function testRenovarCalculaFechaFinUnDiaAntesYAuditaDosVeces(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('findById')->willReturn([
            'id_contrato' => 1, 'id_empleado' => 7, 'estado' => 'activo', 'fecha_inicio' => '2026-01-01',
        ]);
        $repo->expects(self::once())->method('renovar')
            ->with(1, 7, '2026-02-28', self::callback(fn(array $c) => $c['fecha_inicio'] === '2026-03-01'))
            ->willReturn(20);

        $auditoria = $this->createMock(AuditoriaRepository::class);
        $auditoria->expects(self::exactly(2))->method('insert');

        $service = new ContratosService($repo, $auditoria);
        $nuevoId = $service->renovar(1, $this->datosValidos(['fecha_inicio' => '2026-03-01']), 1, '127.0.0.1');

        self::assertSame(20, $nuevoId);
    }

    public function testEliminarLanzaSiNoExiste(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('delete');

        $this->expectException(RuntimeException::class);

        $this->service($repo)->eliminar(99, 1, '127.0.0.1');
    }
}
