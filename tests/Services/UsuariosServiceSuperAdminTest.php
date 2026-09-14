<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\UsuariosRepository;
use App\Services\UsuariosService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UsuariosServiceSuperAdminTest extends TestCase
{
    private function service(UsuariosRepository $repo): UsuariosService
    {
        return new UsuariosService($repo, $this->createMock(AuditoriaRepository::class));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function usuarioActual(array $overrides = []): array
    {
        return array_merge([
            'id_usuario'     => 2,
            'nombre_usuario' => 'superadmin',
            'rol'            => 'super_admin',
            'activo'         => 1,
            'fecha_creacion' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    public function testCrearAceptaRolSuperAdminComoValido(): void
    {
        $repo = $this->createMock(UsuariosRepository::class);
        $repo->expects(self::once())->method('insert')
            ->with('nuevo_super', self::isType('string'), 'super_admin')
            ->willReturn(9);

        $this->service($repo)->crear(
            ['nombre_usuario' => 'nuevo_super', 'rol' => 'super_admin', 'contrasena' => 'password123'],
            1,
            '127.0.0.1'
        );

        $this->addToAssertionCount(1);
    }

    public function testActualizarBloqueaDegradarAlUltimoSuperAdminActivo(): void
    {
        $repo = $this->createMock(UsuariosRepository::class);
        $repo->method('findById')->willReturn($this->usuarioActual());
        $repo->method('contarSuperAdminsActivos')->willReturn(1);
        $repo->expects(self::never())->method('update');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Debe existir al menos un super_admin activo');

        $this->service($repo)->actualizar(
            2,
            ['nombre_usuario' => 'superadmin', 'rol' => 'admin', 'activo' => '1'],
            99,
            '127.0.0.1'
        );
    }

    public function testActualizarBloqueaDesactivarAlUltimoSuperAdminActivo(): void
    {
        $repo = $this->createMock(UsuariosRepository::class);
        $repo->method('findById')->willReturn($this->usuarioActual());
        $repo->method('contarSuperAdminsActivos')->willReturn(1);
        $repo->expects(self::never())->method('update');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Debe existir al menos un super_admin activo');

        $this->service($repo)->actualizar(
            2,
            ['nombre_usuario' => 'superadmin', 'rol' => 'super_admin', 'activo' => '0'],
            99,
            '127.0.0.1'
        );
    }

    public function testActualizarPermiteDegradarCuandoHayOtroSuperAdminActivo(): void
    {
        $repo = $this->createMock(UsuariosRepository::class);
        $repo->method('findById')->willReturn($this->usuarioActual());
        $repo->method('contarSuperAdminsActivos')->willReturn(2);
        $repo->expects(self::once())->method('update');

        $this->service($repo)->actualizar(
            2,
            ['nombre_usuario' => 'superadmin', 'rol' => 'admin', 'activo' => '1'],
            99,
            '127.0.0.1'
        );

        $this->addToAssertionCount(1);
    }

    public function testActualizarNoConsultaConteoSiElUsuarioActualNoEsSuperAdminActivo(): void
    {
        $repo = $this->createMock(UsuariosRepository::class);
        $repo->method('findById')->willReturn($this->usuarioActual(['rol' => 'admin']));
        $repo->expects(self::never())->method('contarSuperAdminsActivos');
        $repo->expects(self::once())->method('update');

        $this->service($repo)->actualizar(
            2,
            ['nombre_usuario' => 'admin', 'rol' => 'empleado', 'activo' => '1'],
            99,
            '127.0.0.1'
        );

        $this->addToAssertionCount(1);
    }

    public function testEliminarBloqueaAlUltimoSuperAdminActivo(): void
    {
        $repo = $this->createMock(UsuariosRepository::class);
        $repo->method('findById')->willReturn($this->usuarioActual());
        $repo->method('hasLinkedEmpleado')->willReturn(false);
        $repo->method('contarSuperAdminsActivos')->willReturn(1);
        $repo->expects(self::never())->method('delete');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Debe existir al menos un super_admin activo');

        $this->service($repo)->eliminar(2, 99, '127.0.0.1');
    }

    public function testEliminarPermiteCuandoHayOtroSuperAdminActivo(): void
    {
        $repo = $this->createMock(UsuariosRepository::class);
        $repo->method('findById')->willReturn($this->usuarioActual());
        $repo->method('hasLinkedEmpleado')->willReturn(false);
        $repo->method('contarSuperAdminsActivos')->willReturn(2);
        $repo->expects(self::once())->method('delete');

        $this->service($repo)->eliminar(2, 99, '127.0.0.1');

        $this->addToAssertionCount(1);
    }
}
