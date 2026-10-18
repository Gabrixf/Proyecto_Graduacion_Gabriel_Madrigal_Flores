<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ParametrosLegalesRepository;
use DateTimeImmutable;
use RuntimeException;

/**
 * ParametrosLegalesService — tasas y tablas legales vigentes a una fecha.
 * Fuentes: CCSS nota 18/12/2025 (tasas 2026); Decreto 45333-H (renta 2026).
 */
class ParametrosLegalesService
{
    public function __construct(private readonly ParametrosLegalesRepository $repo) {}

    /** @return array{ccss_obrera: float, ccss_patronal: float} */
    public function tasasCcss(string $fecha): array
    {
        return [
            'ccss_obrera'   => $this->valor('ccss_obrera', $fecha),
            'ccss_patronal' => $this->valor('ccss_patronal', $fecha),
        ];
    }

    /** @return array{tramos: list<array{desde: float, tasa: float}>, credito_hijo: float, credito_conyuge: float} */
    public function tablaRenta(string $fecha): array
    {
        $tramos = [];
        for ($i = 1; $i <= 4; $i++) {
            $tramos[] = ['desde' => $this->valor("renta_tramo_{$i}", $fecha), 'tasa' => $this->valor("renta_tasa_{$i}", $fecha)];
        }
        return [
            'tramos'          => $tramos,
            'credito_hijo'    => $this->valor('renta_credito_hijo', $fecha),
            'credito_conyuge' => $this->valor('renta_credito_conyuge', $fecha),
        ];
    }

    private function valor(string $clave, string $fecha): float
    {
        $v = $this->repo->valorVigente($clave, $fecha);
        if ($v === null) {
            throw new RuntimeException(sprintf(
                'Falta cargar el parámetro legal %s vigente al %s.',
                $clave,
                (new DateTimeImmutable($fecha))->format('d/m/Y')
            ));
        }
        return $v;
    }
}
