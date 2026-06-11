<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Helpers\EvaluacionCalculadora;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EvaluacionCalculadoraTest extends TestCase
{
    private function calc(): EvaluacionCalculadora
    {
        return new EvaluacionCalculadora();
    }

    /** @return array<int, array{puntaje: float, peso: float}> */
    private function detalles(array $puntajes, array $pesos): array
    {
        return array_map(
            static fn(float $puntaje, float $peso): array => ['puntaje' => $puntaje, 'peso' => $peso],
            $puntajes,
            $pesos
        );
    }

    public function testTodosLosPuntajesIgualesDevuelveEseValor(): void
    {
        $d = $this->detalles([80.0, 80.0, 80.0, 80.0, 80.0], [20.0, 25.0, 25.0, 15.0, 15.0]);
        self::assertEqualsWithDelta(80.0, $this->calc()->puntajeTotal($d), 0.001);
    }

    public function testCasoMixtoPonderado(): void
    {
        // 90×0.20 + 80×0.25 + 70×0.25 + 100×0.15 + 60×0.15 = 79.50
        $d = $this->detalles([90.0, 80.0, 70.0, 100.0, 60.0], [20.0, 25.0, 25.0, 15.0, 15.0]);
        self::assertEqualsWithDelta(79.50, $this->calc()->puntajeTotal($d), 0.001);
    }

    public function testRedondeoADosDecimales(): void
    {
        // 85.55×0.20 + 77.77×0.25 + 66.66×0.25 + 99.99×0.15 + 55.55×0.15 = 76.5485 → 76.55
        $d = $this->detalles([85.55, 77.77, 66.66, 99.99, 55.55], [20.0, 25.0, 25.0, 15.0, 15.0]);
        self::assertEqualsWithDelta(76.55, $this->calc()->puntajeTotal($d), 0.001);
    }

    public function testPesosQueNoSumanCienLanzaExcepcion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc()->puntajeTotal($this->detalles([80.0, 80.0], [50.0, 40.0]));
    }

    public function testPuntajeFueraDeRangoLanzaExcepcion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc()->puntajeTotal($this->detalles([101.0, 80.0], [50.0, 50.0]));
    }

    public function testPuntajeMenorAUnoLanzaExcepcion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc()->puntajeTotal($this->detalles([0.0, 80.0], [50.0, 50.0]));
    }

    public function testListaVaciaLanzaExcepcion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc()->puntajeTotal([]);
    }

    public function testValidarPesos(): void
    {
        self::assertTrue($this->calc()->validarPesos([20.0, 25.0, 25.0, 15.0, 15.0]));
        self::assertTrue($this->calc()->validarPesos([33.33, 33.33, 33.34]));
        self::assertFalse($this->calc()->validarPesos([50.0, 40.0]));
    }

    public function testPesoNoPositivoLanzaExcepcion(): void
    {
        // Suman 100, pero un peso negativo no es válido.
        $this->expectException(InvalidArgumentException::class);
        $this->calc()->puntajeTotal($this->detalles([100.0, 1.0], [150.0, -50.0]));
    }
}
