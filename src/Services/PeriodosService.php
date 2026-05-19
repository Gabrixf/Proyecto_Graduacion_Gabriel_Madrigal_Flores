<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\PeriodosRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

class PeriodosService
{
    public function __construct(
        private readonly PeriodosRepository  $repo,
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
            throw new RuntimeException('Período no encontrado.');
        }
        return $row;
    }

    public function sugerirSiguiente(): array
    {
        $latest = $this->repo->findLatest();
        if ($latest === null) {
            return ['fecha_inicio' => date('Y-m-01'), 'fecha_fin' => date('Y-m-15')];
        }

        $next = (new DateTimeImmutable($latest['fecha_fin']))->modify('+1 day');
        $day  = (int)$next->format('j');

        if ($day === 1) {
            return ['fecha_inicio' => $next->format('Y-m-d'), 'fecha_fin' => $next->format('Y-m-15')];
        }

        return ['fecha_inicio' => $next->format('Y-m-d'), 'fecha_fin' => $next->format('Y-m-t')];
    }

    public function crear(array $datos, int $loggedInId, string $ip): void
    {
        ['fecha_inicio' => $inicio, 'fecha_fin' => $fin] = $this->validarRangoQuincenal($datos);
        $this->validarSinSolapamiento($inicio, $fin);

        $newId = $this->repo->insert($inicio, $fin);
        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'periodos_pago', $newId, $ip);
    }

    public function actualizar(int $id, array $datos, int $loggedInId, string $ip): void
    {
        $current     = $this->obtener($id);
        $nuevoEstado = trim($datos['estado'] ?? $current['estado']);

        if ($current['estado'] === 'cerrado' && $nuevoEstado === 'abierto') {
            throw new InvalidArgumentException('Un período cerrado no puede reabrirse.');
        }
        if (!in_array($nuevoEstado, ['abierto', 'cerrado'], true)) {
            throw new InvalidArgumentException('Estado no válido.');
        }

        ['fecha_inicio' => $inicio, 'fecha_fin' => $fin] = $this->validarRangoQuincenal($datos);
        $this->validarSinSolapamiento($inicio, $fin, $id);

        $this->repo->update($id, $inicio, $fin, $nuevoEstado);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'periodos_pago', $id, $ip);
    }

    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $current = $this->obtener($id);

        if ($current['estado'] === 'cerrado') {
            throw new InvalidArgumentException('No se puede eliminar un período cerrado.');
        }
        if ($this->repo->hasLinkedRecords($id)) {
            throw new InvalidArgumentException('No se puede eliminar: el período tiene registros asociados.');
        }

        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'periodos_pago', $id, $ip);
    }

    private function validarRangoQuincenal(array $datos): array
    {
        $inicio = trim($datos['fecha_inicio'] ?? '');
        $fin    = trim($datos['fecha_fin'] ?? '');

        if ($inicio === '' || $fin === '') {
            throw new InvalidArgumentException('Las fechas de inicio y fin son obligatorias.');
        }

        $dtInicio = DateTimeImmutable::createFromFormat('Y-m-d', $inicio);
        $dtFin    = DateTimeImmutable::createFromFormat('Y-m-d', $fin);

        if ($dtInicio === false || $dtFin === false) {
            throw new InvalidArgumentException('Formato de fecha inválido. Use YYYY-MM-DD.');
        }

        $dayInicio = (int)$dtInicio->format('j');
        $dayFin    = (int)$dtFin->format('j');
        $lastDay   = (int)$dtFin->format('t');
        $mismoMes  = $dtInicio->format('Y-m') === $dtFin->format('Y-m');

        $valido = $mismoMes && (
            ($dayInicio === 1  && $dayFin === 15)      ||
            ($dayInicio === 16 && $dayFin === $lastDay)
        );

        if (!$valido) {
            throw new InvalidArgumentException(
                'El período debe ser del 1 al 15 o del 16 al último día del mes.'
            );
        }

        return ['fecha_inicio' => $inicio, 'fecha_fin' => $fin];
    }

    private function validarSinSolapamiento(string $inicio, string $fin, ?int $excludeId = null): void
    {
        if ($this->repo->findOverlapping($inicio, $fin, $excludeId) !== []) {
            throw new InvalidArgumentException('Ya existe un período que se cruza con esas fechas.');
        }
    }
}
