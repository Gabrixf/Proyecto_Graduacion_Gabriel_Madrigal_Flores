<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\UsuariosRepository;
use InvalidArgumentException;

class AuthService
{
    public function __construct(
        private readonly UsuariosRepository  $usuariosRepo,
        private readonly AuditoriaRepository $auditoriaRepo
    ) {}

    public function verificarCredenciales(string $usuario, string $password, string $ip): array
    {
        $row = $this->usuariosRepo->findByNombreUsuario($usuario);

        if ($row === null || !(bool)$row['activo']) {
            throw new InvalidArgumentException('Credenciales incorrectas.');
        }

        if (!password_verify($password, $row['contrasena_hash'])) {
            throw new InvalidArgumentException('Credenciales incorrectas.');
        }

        $this->auditoriaRepo->insert(
            'LOGIN',
            (int)$row['id_usuario'],
            'usuarios',
            (int)$row['id_usuario'],
            $ip
        );

        return [
            'id_usuario'     => (int)$row['id_usuario'],
            'nombre_usuario' => $row['nombre_usuario'],
            'rol'            => $row['rol'],
        ];
    }

    public function cerrarSesion(int $userId, string $ip): void
    {
        $this->auditoriaRepo->insert('LOGOUT', $userId, 'usuarios', $userId, $ip);
    }
}
