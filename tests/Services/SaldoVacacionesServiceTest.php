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
}
