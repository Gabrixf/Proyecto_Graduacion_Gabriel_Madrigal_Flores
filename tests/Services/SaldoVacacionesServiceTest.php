<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\AcumulacionVacaciones;
use App\Repositories\SolicitudesRepository;
use App\Services\SaldoVacacionesService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\DiasHabilesStub;

final class SaldoVacacionesServiceTest extends TestCase
{
    private function service(float $comprometidos = 0.0, ?int &$excluidoCapturado = null): SaldoVacacionesService
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->method('vacacionesComprometidas')->willReturnCallback(
            function (int $idEmpleado, ?int $excluir) use ($comprometidos, &$excluidoCapturado) {
                $excluidoCapturado = $excluir;
                return DiasHabilesStub::comprometidas($comprometidos);
            }
        );
        return new SaldoVacacionesService(new AcumulacionVacaciones(), $repo, DiasHabilesStub::servicio());
    }

    public function testSaldoRestaLosDiasComprometidosALosGanados(): void
    {
        $s = $this->service(comprometidos: 2.0)->saldo(7, '2025-01-10', new DateTimeImmutable('2025-07-10'));

        self::assertSame(6.0, $s['ganados']);
        self::assertSame(2.0, $s['comprometidos']);
        self::assertSame(4.0, $s['disponibles']);
        self::assertFalse($s['en_prueba']);
        self::assertSame('2025-04-10', $s['fin_prueba']);
    }

    public function testSaldoPuedeSerNegativoYNoSeAcota(): void
    {
        $s = $this->service(comprometidos: 5.0)->saldo(7, '2025-01-10', new DateTimeImmutable('2025-03-10'));
        self::assertSame(-3.0, $s['disponibles']);
        self::assertTrue($s['en_prueba']);
    }

    public function testValidarRechazaInicioDentroDeLaPrueba(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('período de prueba hasta el 15/06/2026');

        $this->service()->validarDisponibilidad(7, '2026-03-15', '2026-06-14', 1.0);
    }

    public function testValidarPermiteSolicitarElDiaQueTerminaLaPruebaConTresDias(): void
    {
        $this->service()->validarDisponibilidad(7, '2026-03-15', '2026-06-15', 3.0);
        $this->addToAssertionCount(1);
    }

    public function testValidarRechazaSiLosDiasSuperanElSaldo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Saldo de vacaciones insuficiente');

        $this->service()->validarDisponibilidad(7, '2026-03-15', '2026-06-15', 4.0);
    }

    public function testValidarDescuentaLoYaComprometidoPorOtrasSolicitudes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Saldo de vacaciones insuficiente');

        $this->service(comprometidos: 2.0)->validarDisponibilidad(7, '2026-03-15', '2026-06-15', 2.0);
    }

    public function testValidarPasaLaSolicitudAExcluirAlRepositorio(): void
    {
        $excluido = null;
        $this->service(0.0, $excluido)->validarDisponibilidad(7, '2025-01-10', '2025-07-10', 1.0, 55);
        self::assertSame(55, $excluido);
    }

    /** @param array<int, array<string, mixed>> $filas */
    private function serviceConFilas(array $filas): SaldoVacacionesService
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->method('vacacionesComprometidas')->willReturn($filas);
        return new SaldoVacacionesService(new AcumulacionVacaciones(), $repo, DiasHabilesStub::servicio());
    }

    public function testSaldoAlSalirIgnoraLasSolicitudesPosterioresALaSalida(): void
    {
        // ganados al 10/07/2025 desde el 10/01/2025 = 6; la solicitud del 04/08 es posterior, no resta
        $s = $this->serviceConFilas([['fecha_inicio' => '2025-08-04', 'fecha_fin' => '2025-08-09', 'horas' => 0]]);

        self::assertSame(6.0, $s->saldoAlSalir(7, '2025-01-10', new DateTimeImmutable('2025-07-10')));
    }

    public function testSaldoAlSalirRecortaLaSolicitudQueCruzaLaSalida(): void
    {
        // 07/07/2025 (lun) al 12/07 (sáb), recortada al 10/07 (jue) = lun, mar, mié, jue = 4 días; 6 − 4 = 2
        $s = $this->serviceConFilas([['fecha_inicio' => '2025-07-07', 'fecha_fin' => '2025-07-12', 'horas' => 0]]);

        self::assertSame(2.0, $s->saldoAlSalir(7, '2025-01-10', new DateTimeImmutable('2025-07-10')));
    }

    public function testSaldoAlSalirNoBajaDeCero(): void
    {
        // 30/06 (lun) al 05/07 (sáb) = 6 días + 07/07 al 10/07 = 4 días → 10 > 6 ganados
        $s = $this->serviceConFilas([
            ['fecha_inicio' => '2025-06-30', 'fecha_fin' => '2025-07-05', 'horas' => 0],
            ['fecha_inicio' => '2025-07-07', 'fecha_fin' => '2025-07-10', 'horas' => 0],
        ]);

        self::assertSame(0.0, $s->saldoAlSalir(7, '2025-01-10', new DateTimeImmutable('2025-07-10')));
    }

    public function testSaldoAlSalirConservaElOverrideDeHoras(): void
    {
        // medio día (horas = 0.5) que empieza antes de la salida resta 0.5; 6 − 0.5 = 5.5
        $s = $this->serviceConFilas([['fecha_inicio' => '2025-07-08', 'fecha_fin' => '2025-07-08', 'horas' => 0.5]]);

        self::assertSame(5.5, $s->saldoAlSalir(7, '2025-01-10', new DateTimeImmutable('2025-07-10')));
    }
}
