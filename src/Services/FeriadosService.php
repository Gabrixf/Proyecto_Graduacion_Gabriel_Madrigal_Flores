<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\FeriadosRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

class FeriadosService
{
    private const TIPOS_VALIDOS = ['obligatorio_pago', 'no_obligatorio_pago'];

    public function __construct(
        private readonly FeriadosRepository  $repo,
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
            throw new RuntimeException('Feriado no encontrado.');
        }
        return $row;
    }

    public function crear(array $datos, int $loggedInId, string $ip): void
    {
        [$fecha, $nombre, $tipo] = $this->validar($datos);

        try {
            $newId = $this->repo->insert($fecha, $nombre, $tipo);
        } catch (\PDOException $e) {
            if (str_starts_with((string)$e->getCode(), '23')) {
                throw new InvalidArgumentException('Ya existe un feriado registrado para esa fecha.');
            }
            throw $e;
        }

        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'feriados', $newId, $ip);
    }

    public function actualizar(int $id, array $datos, int $loggedInId, string $ip): void
    {
        $this->obtener($id);
        [$fecha, $nombre, $tipo] = $this->validar($datos);

        try {
            $this->repo->update($id, $fecha, $nombre, $tipo);
        } catch (\PDOException $e) {
            if (str_starts_with((string)$e->getCode(), '23')) {
                throw new InvalidArgumentException('Ya existe un feriado registrado para esa fecha.');
            }
            throw $e;
        }

        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'feriados', $id, $ip);
    }

    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $this->obtener($id);
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'feriados', $id, $ip);
    }

    private function validar(array $datos): array
    {
        $fecha  = trim($datos['fecha']  ?? '');
        $nombre = trim($datos['nombre'] ?? '');
        $tipo   = trim($datos['tipo']   ?? '');

        if ($fecha === '') {
            throw new InvalidArgumentException('La fecha es obligatoria.');
        }
        if (DateTimeImmutable::createFromFormat('Y-m-d', $fecha) === false) {
            throw new InvalidArgumentException('Formato de fecha inválido. Use YYYY-MM-DD.');
        }
        if ($nombre === '') {
            throw new InvalidArgumentException('El nombre del feriado es obligatorio.');
        }
        if (mb_strlen($nombre) > 100) {
            throw new InvalidArgumentException('El nombre no puede exceder 100 caracteres.');
        }
        if (!in_array($tipo, self::TIPOS_VALIDOS, true)) {
            throw new InvalidArgumentException('El tipo de feriado no es válido.');
        }

        return [$fecha, $nombre, $tipo];
    }
}
