<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\AguinaldoService;
use PHPUnit\Framework\TestCase;

final class AguinaldoCalculoTest extends TestCase
{
    public function testMontoEsSumaEntreDoce(): void
    {
        // Método estático puro para el cálculo legal del aguinaldo.
        self::assertEqualsWithDelta(100000.0, AguinaldoService::calcularMonto(1200000.0), 0.01);
        self::assertEqualsWithDelta(0.0, AguinaldoService::calcularMonto(0.0), 0.01);
    }
}
