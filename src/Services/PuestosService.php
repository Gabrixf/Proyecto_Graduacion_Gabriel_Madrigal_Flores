<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\PuestosRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * PuestosService
 *
 * Contiene toda la lógica de negocio relacionada con los puestos de trabajo.
 * El Controller nunca accede directamente al Repository.
 *
 * Reglas de negocio:
 *  - El nombre del puesto debe ser único.
 *  - El salario base debe ser mayor a 0.
 *  - No se puede eliminar un puesto si tiene empleados asociados.
 */
class PuestosService
{
    public function __construct(
        private readonly PuestosRepository $repository
    ) {}

    // ── Consultas ─────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listar(): array
    {
        return $this->repository->findAll();
    }

    /**
     * @return array<string, mixed>
     * @throws RuntimeException si el puesto no existe
     */
    public function obtener(int $id): array
    {
        $puesto = $this->repository->findById($id);
        if ($puesto === null) {
            throw new RuntimeException("El puesto con ID {$id} no existe.");
        }
        return $puesto;
    }

    // ── Comandos ──────────────────────────────────────────

    /**
     * Crea un nuevo puesto después de validar los datos.
     *
     * @param array<string, mixed> $datos
     * @throws InvalidArgumentException si la validación falla
     * @return int ID del nuevo puesto
     */
    public function crear(array $datos): int
    {
        $errores = $this->validar($datos);
        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $nombre      = trim((string)$datos['nombre']);
        $salarioBase = (float)$datos['salario_base'];
        $descripcion = isset($datos['descripcion']) && $datos['descripcion'] !== ''
            ? trim((string)$datos['descripcion'])
            : null;

        if ($this->repository->existsByNombre($nombre)) {
            throw new InvalidArgumentException("Ya existe un puesto con el nombre \"{$nombre}\".");
        }

        return $this->repository->insert($nombre, $salarioBase, $descripcion);
    }

    /**
     * Actualiza un puesto existente.
     *
     * @param array<string, mixed> $datos
     * @throws InvalidArgumentException si la validación falla
     * @throws RuntimeException si el puesto no existe
     */
    public function actualizar(int $id, array $datos): void
    {
        // Confirmar existencia
        $this->obtener($id);

        $errores = $this->validar($datos);
        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $nombre      = trim((string)$datos['nombre']);
        $salarioBase = (float)$datos['salario_base'];
        $descripcion = isset($datos['descripcion']) && $datos['descripcion'] !== ''
            ? trim((string)$datos['descripcion'])
            : null;

        if ($this->repository->existsByNombre($nombre, $id)) {
            throw new InvalidArgumentException("Ya existe otro puesto con el nombre \"{$nombre}\".");
        }

        $this->repository->update($id, $nombre, $salarioBase, $descripcion);
    }

    /**
     * Elimina un puesto.
     *
     * @throws RuntimeException si el puesto tiene empleados o no existe
     */
    public function eliminar(int $id): void
    {
        $this->obtener($id); // lanza excepción si no existe

        if ($this->repository->hasEmpleados($id)) {
            throw new RuntimeException(
                'No se puede eliminar el puesto porque tiene empleados asignados. ' .
                'Reasigne o desactive los empleados primero.'
            );
        }

        $this->repository->delete($id);
    }

    // ── Validación ────────────────────────────────────────

    /**
     * @param array<string, mixed> $datos
     * @return string[] Lista de mensajes de error (vacía si todo es válido)
     */
    private function validar(array $datos): array
    {
        $errores = [];

        $nombre = trim((string)($datos['nombre'] ?? ''));
        if ($nombre === '') {
            $errores[] = 'El nombre del puesto es obligatorio.';
        } elseif (mb_strlen($nombre) > 100) {
            $errores[] = 'El nombre no puede superar 100 caracteres.';
        }

        $salario = $datos['salario_base'] ?? '';
        if ($salario === '' || !is_numeric($salario)) {
            $errores[] = 'El salario base debe ser un número válido.';
        } elseif ((float)$salario <= 0) {
            $errores[] = 'El salario base debe ser mayor a ₡0.';
        }

        if (isset($datos['descripcion']) && mb_strlen((string)$datos['descripcion']) > 255) {
            $errores[] = 'La descripción no puede superar 255 caracteres.';
        }

        return $errores;
    }
}
