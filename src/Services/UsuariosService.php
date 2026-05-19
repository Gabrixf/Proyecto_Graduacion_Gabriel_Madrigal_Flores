<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\UsuariosRepository;
use InvalidArgumentException;
use RuntimeException;

class UsuariosService
{
    private const ROLES_VALIDOS = ['admin', 'empleado'];

    public function __construct(
        private readonly UsuariosRepository  $repo,
        private readonly AuditoriaRepository $auditoriaRepo
    ) {}

    public function listar(): array
    {
        return $this->repo->findAll();
    }

    public function obtener(int $id): array
    {
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new RuntimeException('Usuario no encontrado.');
        }
        return $row;
    }

    public function crear(array $datos, int $loggedInId, string $ip): void
    {
        $nombre   = trim($datos['nombre_usuario'] ?? '');
        $rol      = trim($datos['rol'] ?? '');
        $password = $datos['contrasena'] ?? '';

        if ($nombre === '') {
            throw new InvalidArgumentException('El nombre de usuario es obligatorio.');
        }
        if (!in_array($rol, self::ROLES_VALIDOS, true)) {
            throw new InvalidArgumentException('El rol seleccionado no es válido.');
        }
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('La contraseña debe tener al menos 8 caracteres.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        try {
            $newId = $this->repo->insert($nombre, $hash, $rol);
        } catch (\PDOException $e) {
            if (str_starts_with((string)$e->getCode(), '23')) {
                throw new InvalidArgumentException('El nombre de usuario ya existe.');
            }
            throw $e;
        }

        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'usuarios', $newId, $ip);
    }

    public function actualizar(int $id, array $datos, int $loggedInId, string $ip): void
    {
        $current = $this->obtener($id);

        $nombre = trim($datos['nombre_usuario'] ?? '');
        $rol    = trim($datos['rol'] ?? '');
        $activo = (int)($datos['activo'] ?? 0);

        if ($nombre === '') {
            throw new InvalidArgumentException('El nombre de usuario es obligatorio.');
        }
        if (!in_array($rol, self::ROLES_VALIDOS, true)) {
            throw new InvalidArgumentException('El rol seleccionado no es válido.');
        }
        if ($id === $loggedInId) {
            if ($rol !== $current['rol'] || $activo !== (int)$current['activo']) {
                throw new InvalidArgumentException('No puedes modificar tu propio rol o estado.');
            }
        }

        try {
            $this->repo->update($id, $nombre, $rol, $activo);
        } catch (\PDOException $e) {
            if (str_starts_with((string)$e->getCode(), '23')) {
                throw new InvalidArgumentException('El nombre de usuario ya existe.');
            }
            throw $e;
        }

        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'usuarios', $id, $ip);
    }

    public function resetearPassword(int $id, array $datos, int $loggedInId, string $ip): void
    {
        $this->obtener($id);

        $password = $datos['contrasena'] ?? '';
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('La contraseña debe tener al menos 8 caracteres.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->repo->updatePassword($id, $hash);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'usuarios', $id, $ip, 'password_reset');
    }

    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $this->obtener($id);

        if ($id === $loggedInId) {
            throw new InvalidArgumentException('No puedes eliminar tu propia cuenta.');
        }
        if ($this->repo->hasLinkedEmpleado($id)) {
            throw new InvalidArgumentException('No se puede eliminar: el usuario tiene un empleado asociado.');
        }

        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'usuarios', $id, $ip);
    }
}
