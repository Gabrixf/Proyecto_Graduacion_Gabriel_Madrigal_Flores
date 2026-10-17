<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Repositories\FeriadosRepository;
use App\Services\DiasHabilesService;

/**
 * Ayudas de prueba para el conteo de días hábiles: un DiasHabilesService sin feriados y
 * filas de solicitudes comprometidas que suman exactamente N días (vía el override `horas`).
 */
final class DiasHabilesStub
{
    /** @param string[] $feriados fechas (Y-m-d) que el catálogo devuelve como feriadas */
    public static function servicio(array $feriados = []): DiasHabilesService
    {
        return new DiasHabilesService(new class ($feriados) extends FeriadosRepository {
            /** @param string[] $feriados */
            public function __construct(private readonly array $feriados) {}

            public function findEntre(string $desde, string $hasta): array
            {
                return array_map(static fn (string $f) => ['fecha' => $f, 'nombre' => 'Feriado'], $this->feriados);
            }
        });
    }

    /** @return array<int, array<string, mixed>> */
    public static function comprometidas(float $dias): array
    {
        return $dias > 0
            ? [['fecha_inicio' => '2000-01-03', 'fecha_fin' => '2000-01-03', 'horas' => $dias]]
            : [];
    }
}
