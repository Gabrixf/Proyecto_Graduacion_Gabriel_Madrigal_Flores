<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\DistritosRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\PuestosRepository;
use App\Services\EmpleadosService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EmpleadosServicePersonaMigrationTest extends TestCase
{
    private function service(EmpleadosRepository $repo, ?DistritosRepository $distritosRepo = null): EmpleadosService
    {
        $puestosRepo = $this->createMock(PuestosRepository::class);
        $puestosRepo->method('findById')->willReturn(['id_puesto' => 1, 'nombre' => 'Mecánico']);

        return new EmpleadosService(
            $repo,
            $puestosRepo,
            $distritosRepo ?? $this->createMock(DistritosRepository::class),
            $this->createMock(AuditoriaRepository::class)
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function datosValidos(array $overrides = []): array
    {
        return array_merge([
            'id_puesto'        => '1',
            'nombre'           => 'Juan',
            'apellidos'        => 'Pérez',
            'cedula'           => '123456789',
            'fecha_nacimiento' => '1990-01-01',
            'genero'           => 'masculino',
            'estado_civil'     => 'soltero',
            'nacionalidad'     => 'Costarricense',
            'fecha_ingreso'    => '2020-01-01',
            'correo'           => '',
            'estado'           => 'activo',
        ], $overrides);
    }

    public function testCrearArmaGruposPersonaEmpleadoSeparados(): void
    {
        $repo = $this->createMock(EmpleadosRepository::class);
        $repo->method('existsByCedula')->willReturn(false);
        $repo->expects(self::once())->method('insert')
            ->with(
                self::callback(fn(array $p) =>
                    $p['nombre'] === 'Juan' && $p['apellidos'] === 'Pérez' && $p['cedula'] === '123456789'
                    && $p['id_distrito'] === 1 && $p['id_distrito_alterno'] === 1
                ),
                self::callback(fn(array $e) =>
                    $e['id_puesto'] === 1 && $e['id_horario'] === 1 && $e['estado'] === 'activo'
                ),
                null
            )
            ->willReturn(42);

        $id = $this->service($repo)->crear($this->datosValidos(), 1, '127.0.0.1');

        self::assertSame(42, $id);
    }

    public function testCrearRechazaCedulaDuplicadaSinExcluirNinguna(): void
    {
        $repo = $this->createMock(EmpleadosRepository::class);
        $repo->expects(self::once())->method('existsByCedula')->with('123456789', null)->willReturn(true);
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ya existe un empleado con la cédula');

        $this->service($repo)->crear($this->datosValidos(), 1, '127.0.0.1');
    }

    public function testActualizarExcluyeSuPropioIdPersonaAlVerificarCedula(): void
    {
        $repo = $this->createMock(EmpleadosRepository::class);
        $repo->method('findById')->willReturn(['id_empleado' => 5, 'id_persona' => 9]);
        $repo->expects(self::once())->method('existsByCedula')->with('123456789', 9)->willReturn(false);
        $repo->expects(self::once())->method('update')
            ->with(5, 9, self::isType('array'), self::isType('array'), null);

        $this->service($repo)->actualizar(5, $this->datosValidos(), 1, '127.0.0.1');
    }

    public function testIdDistritoVacioUsaElCentinelaSinConsultarDistritosRepository(): void
    {
        $distritosRepo = $this->createMock(DistritosRepository::class);
        $distritosRepo->expects(self::never())->method('exists');

        $repo = $this->createMock(EmpleadosRepository::class);
        $repo->method('existsByCedula')->willReturn(false);
        $repo->expects(self::once())->method('insert')
            ->with(self::callback(fn(array $p) => $p['id_distrito'] === 1), self::isType('array'), null)
            ->willReturn(1);

        $this->service($repo, $distritosRepo)->crear($this->datosValidos(['id_distrito' => '']), 1, '127.0.0.1');
    }

    public function testIdDistritoInexistenteRechazaYNoInserta(): void
    {
        $distritosRepo = $this->createMock(DistritosRepository::class);
        $distritosRepo->method('exists')->with(999)->willReturn(false);

        $repo = $this->createMock(EmpleadosRepository::class);
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('distrito seleccionado no es válido');

        $this->service($repo, $distritosRepo)->crear($this->datosValidos(['id_distrito' => '999']), 1, '127.0.0.1');
    }

    public function testIdDistritoValidoSeConservaTalCual(): void
    {
        $distritosRepo = $this->createMock(DistritosRepository::class);
        $distritosRepo->method('exists')->with(5)->willReturn(true);

        $repo = $this->createMock(EmpleadosRepository::class);
        $repo->method('existsByCedula')->willReturn(false);
        $repo->expects(self::once())->method('insert')
            ->with(self::callback(fn(array $p) => $p['id_distrito'] === 5), self::isType('array'), null)
            ->willReturn(1);

        $this->service($repo, $distritosRepo)->crear($this->datosValidos(['id_distrito' => '5']), 1, '127.0.0.1');
    }

    public function testIdUsuarioVacioUsaElCentinelaNoNull(): void
    {
        $repo = $this->createMock(EmpleadosRepository::class);
        $repo->method('existsByCedula')->willReturn(false);
        $repo->expects(self::once())->method('insert')
            ->with(self::isType('array'), self::callback(fn(array $e) => $e['id_usuario'] === 1), null)
            ->willReturn(1);

        $this->service($repo)->crear($this->datosValidos(['id_usuario' => '']), 1, '127.0.0.1');
    }

    public function testCorreoVacioSeGuardaComoCadenaVaciaNoNull(): void
    {
        $repo = $this->createMock(EmpleadosRepository::class);
        $repo->method('existsByCedula')->willReturn(false);
        $repo->expects(self::once())->method('insert')
            ->with(self::callback(fn(array $p) => $p['correo'] === ''), self::isType('array'), null)
            ->willReturn(1);

        $this->service($repo)->crear($this->datosValidos(['correo' => '']), 1, '127.0.0.1');
    }
}
