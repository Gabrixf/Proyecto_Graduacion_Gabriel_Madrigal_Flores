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

    // ── Vencimiento (Art. 26 y 27 del Código de Trabajo) ──

    private function repoParaCrear(): ContratosRepository
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('esEmpleadoActivo')->willReturn(true);
        $repo->method('findActivoPorEmpleado')->willReturn(null);
        return $repo;
    }

    public function testCrearPlazoFijoExigeFechaDeVencimiento(): void
    {
        $repo = $this->repoParaCrear();
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('fecha de vencimiento');

        $this->service($repo)->crear($this->datosValidos(['tipo_contrato' => 'plazo_fijo']), 1, '127.0.0.1');
    }

    public function testCrearRechazaVencimientoNoPosteriorAlInicio(): void
    {
        $repo = $this->repoParaCrear();
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('posterior a la fecha de inicio');

        $this->service($repo)->crear(
            $this->datosValidos(['tipo_contrato' => 'plazo_fijo', 'fecha_fin' => '2026-01-01']),
            1,
            '127.0.0.1'
        );
    }

    public function testCrearPlazoFijoAceptaHastaUnAnio(): void
    {
        $repo = $this->repoParaCrear();
        $repo->expects(self::once())->method('insert')
            ->with(self::callback(fn(array $d) =>
                $d['tipo_contrato'] === 'plazo_fijo' && $d['fecha_fin'] === '2026-12-31'
                && $d['requiere_preparacion_tecnica'] === 0
            ))
            ->willReturn(11);

        $this->service($repo)->crear(
            $this->datosValidos(['tipo_contrato' => 'plazo_fijo', 'fecha_fin' => '2026-12-31']),
            1,
            '127.0.0.1'
        );
    }

    public function testCrearPlazoFijoRechazaMasDeUnAnioSinPreparacionTecnica(): void
    {
        $repo = $this->repoParaCrear();
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Art. 26');

        $this->service($repo)->crear(
            $this->datosValidos(['tipo_contrato' => 'plazo_fijo', 'fecha_fin' => '2027-01-01']),
            1,
            '127.0.0.1'
        );
    }

    public function testCrearPlazoFijoConPreparacionTecnicaAceptaHastaCincoAnios(): void
    {
        $repo = $this->repoParaCrear();
        $repo->expects(self::once())->method('insert')
            ->with(self::callback(fn(array $d) =>
                $d['fecha_fin'] === '2030-12-31' && $d['requiere_preparacion_tecnica'] === 1
            ))
            ->willReturn(12);

        $this->service($repo)->crear(
            $this->datosValidos([
                'tipo_contrato' => 'plazo_fijo', 'fecha_fin' => '2030-12-31', 'requiere_preparacion_tecnica' => '1',
            ]),
            1,
            '127.0.0.1'
        );
    }

    public function testCrearPlazoFijoConPreparacionTecnicaRechazaMasDeCincoAnios(): void
    {
        $repo = $this->repoParaCrear();
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('5 años');

        $this->service($repo)->crear(
            $this->datosValidos([
                'tipo_contrato' => 'plazo_fijo', 'fecha_fin' => '2031-01-01', 'requiere_preparacion_tecnica' => '1',
            ]),
            1,
            '127.0.0.1'
        );
    }

    public function testCrearObraDeterminadaNoTieneTopeDeAnios(): void
    {
        $repo = $this->repoParaCrear();
        $repo->expects(self::once())->method('insert')
            ->with(self::callback(fn(array $d) =>
                $d['tipo_contrato'] === 'obra_determinada' && $d['fecha_fin'] === '2033-06-30'
                && $d['requiere_preparacion_tecnica'] === 0
            ))
            ->willReturn(13);

        $this->service($repo)->crear(
            $this->datosValidos([
                'tipo_contrato' => 'obra_determinada', 'fecha_fin' => '2033-06-30', 'requiere_preparacion_tecnica' => '1',
            ]),
            1,
            '127.0.0.1'
        );
    }

    public function testCrearIndefinidoIgnoraVencimientoYPreparacionTecnica(): void
    {
        $repo = $this->repoParaCrear();
        $repo->expects(self::once())->method('insert')
            ->with(self::callback(fn(array $d) =>
                $d['fecha_fin'] === '9999-12-31' && $d['requiere_preparacion_tecnica'] === 0
            ))
            ->willReturn(14);

        $this->service($repo)->crear(
            $this->datosValidos(['fecha_fin' => '2026-06-30', 'requiere_preparacion_tecnica' => '1']),
            1,
            '127.0.0.1'
        );
    }

    public function testActualizarGuardaVencimiento(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('findById')->willReturn(['id_contrato' => 3, 'estado' => 'activo']);
        $repo->expects(self::once())->method('update')
            ->with(3, self::callback(fn(array $c) =>
                $c['tipo_contrato'] === 'plazo_fijo' && $c['fecha_fin'] === '2026-06-30'
            ));

        $this->service($repo)->actualizar(
            3,
            $this->datosValidos(['tipo_contrato' => 'plazo_fijo', 'fecha_fin' => '2026-06-30']),
            1,
            '127.0.0.1'
        );
    }

    public function testActualizarContratoFinalizadoConservaSuFechaDeCierre(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('findById')->willReturn([
            'id_contrato' => 3, 'estado' => 'finalizado', 'fecha_fin' => '2025-12-31', 'requiere_preparacion_tecnica' => 0,
        ]);
        $repo->expects(self::once())->method('update')
            ->with(3, self::callback(fn(array $c) => $c['fecha_fin'] === '2025-12-31'));

        $this->service($repo)->actualizar(3, $this->datosValidos(['fecha_inicio' => '2024-01-01']), 1, '127.0.0.1');
    }

    public function testRenovarAPlazoFijoPasaElVencimientoDelNuevoContrato(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('findById')->willReturn([
            'id_contrato' => 1, 'id_empleado' => 7, 'estado' => 'activo', 'fecha_inicio' => '2026-01-01',
        ]);
        $repo->expects(self::once())->method('renovar')
            ->with(1, 7, '2026-02-28', self::callback(fn(array $c) =>
                $c['tipo_contrato'] === 'plazo_fijo' && $c['fecha_fin'] === '2026-08-31'
            ))
            ->willReturn(21);

        $this->service($repo)->renovar(
            1,
            $this->datosValidos(['tipo_contrato' => 'plazo_fijo', 'fecha_inicio' => '2026-03-01', 'fecha_fin' => '2026-08-31']),
            1,
            '127.0.0.1'
        );
    }

    public function testListarMarcaVencidosSoloLosActivosConVencimientoPasado(): void
    {
        $ayer   = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        $manana = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');

        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('findAll')->willReturn([
            ['id_contrato' => 1, 'tipo_contrato' => 'plazo_fijo',        'estado' => 'activo',     'fecha_fin' => $ayer],
            ['id_contrato' => 2, 'tipo_contrato' => 'plazo_fijo',        'estado' => 'activo',     'fecha_fin' => $manana],
            ['id_contrato' => 3, 'tipo_contrato' => 'plazo_fijo',        'estado' => 'finalizado', 'fecha_fin' => $ayer],
            ['id_contrato' => 4, 'tipo_contrato' => 'tiempo_indefinido', 'estado' => 'activo',     'fecha_fin' => '9999-12-31'],
            ['id_contrato' => 5, 'tipo_contrato' => 'obra_determinada',  'estado' => 'activo',     'fecha_fin' => $ayer],
        ]);

        $vencidos = array_column($this->service($repo)->listar(), 'vencido', 'id_contrato');

        self::assertSame([1 => true, 2 => false, 3 => false, 4 => false, 5 => true], $vencidos);
    }

    public function testListarMarcaPorVencerLosQueVencenEnLosProximosTreintaDias(): void
    {
        $en30 = (new \DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d');
        $en31 = (new \DateTimeImmutable('today'))->modify('+31 days')->format('Y-m-d');
        $ayer = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');

        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('findAll')->willReturn([
            ['id_contrato' => 1, 'tipo_contrato' => 'plazo_fijo',        'estado' => 'activo',     'fecha_fin' => $en30],
            ['id_contrato' => 2, 'tipo_contrato' => 'plazo_fijo',        'estado' => 'activo',     'fecha_fin' => $en31],
            ['id_contrato' => 3, 'tipo_contrato' => 'plazo_fijo',        'estado' => 'activo',     'fecha_fin' => $ayer],
            ['id_contrato' => 4, 'tipo_contrato' => 'tiempo_indefinido', 'estado' => 'activo',     'fecha_fin' => '9999-12-31'],
            ['id_contrato' => 5, 'tipo_contrato' => 'obra_determinada',  'estado' => 'finalizado', 'fecha_fin' => $en30],
        ]);

        $porVencer = array_column($this->service($repo)->listar(), 'por_vencer', 'id_contrato');

        self::assertSame([1 => true, 2 => false, 3 => false, 4 => false, 5 => false], $porVencer);
    }

    public function testListarNoAlertaContratosDeEmpleadosQueYaSalieron(): void
    {
        $ayer   = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        $manana = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');

        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('findAll')->willReturn([
            ['id_contrato' => 1, 'tipo_contrato' => 'plazo_fijo', 'estado' => 'activo', 'fecha_fin' => $ayer,   'salida_registrada' => 1],
            ['id_contrato' => 2, 'tipo_contrato' => 'plazo_fijo', 'estado' => 'activo', 'fecha_fin' => $manana, 'salida_registrada' => 1],
        ]);

        $filas = $this->service($repo)->listar();

        self::assertSame([false, false], array_column($filas, 'vencido'));
        self::assertSame([false, false], array_column($filas, 'por_vencer'));
    }

    public function testPasarAIndefinidoRechazaEmpleadoQueYaSalio(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('findById')->willReturn($this->contratoVencido(['salida_registrada' => 1]));
        $repo->expects(self::never())->method('renovar');

        $this->expectException(RuntimeException::class);

        $this->service($repo)->pasarAIndefinido(4, 1, '127.0.0.1');
    }

    // ── Pasar a indefinido (Art. 26) ──────────────────────

    /** @param array<string, mixed> $overrides */
    private function contratoVencido(array $overrides = []): array
    {
        return array_merge([
            'id_contrato' => 4, 'id_empleado' => 9, 'estado' => 'activo', 'tipo_contrato' => 'plazo_fijo',
            'salario_pactado' => '380000.00', 'jornada' => 'medio_tiempo',
            'fecha_inicio' => '2026-09-15', 'fecha_fin' => '2026-10-15', 'requiere_preparacion_tecnica' => 0,
        ], $overrides);
    }

    public function testPasarAIndefinidoRenuevaDesdeElDiaSiguienteConElMismoSalarioYJornada(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('findById')->willReturn($this->contratoVencido());
        $repo->expects(self::once())->method('renovar')
            ->with(4, 9, '2026-10-15', [
                'tipo_contrato'                => 'tiempo_indefinido',
                'salario_pactado'              => 380000.0,
                'jornada'                      => 'medio_tiempo',
                'fecha_inicio'                 => '2026-10-16',
                'fecha_fin'                    => '9999-12-31',
                'requiere_preparacion_tecnica' => 0,
            ])
            ->willReturn(30);

        $auditoria = $this->createMock(AuditoriaRepository::class);
        $auditoria->expects(self::exactly(2))->method('insert');

        $nuevoId = (new ContratosService($repo, $auditoria))->pasarAIndefinido(4, 1, '127.0.0.1');

        self::assertSame(30, $nuevoId);
    }

    public function testPasarAIndefinidoRechazaContratoQueNoHaVencido(): void
    {
        $manana = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('findById')->willReturn($this->contratoVencido(['fecha_fin' => $manana]));
        $repo->expects(self::never())->method('renovar');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('vencido');

        $this->service($repo)->pasarAIndefinido(4, 1, '127.0.0.1');
    }

    public function testPasarAIndefinidoRechazaContratoFinalizado(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('findById')->willReturn($this->contratoVencido(['estado' => 'finalizado']));
        $repo->expects(self::never())->method('renovar');

        $this->expectException(RuntimeException::class);

        $this->service($repo)->pasarAIndefinido(4, 1, '127.0.0.1');
    }

    public function testPasarAIndefinidoRechazaContratoQueYaEsIndefinido(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('findById')->willReturn(
            $this->contratoVencido(['tipo_contrato' => 'tiempo_indefinido', 'fecha_fin' => '9999-12-31'])
        );
        $repo->expects(self::never())->method('renovar');

        $this->expectException(RuntimeException::class);

        $this->service($repo)->pasarAIndefinido(4, 1, '127.0.0.1');
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
