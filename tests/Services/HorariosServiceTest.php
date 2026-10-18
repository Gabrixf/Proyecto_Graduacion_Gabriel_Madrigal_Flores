<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\HorariosRepository;
use App\Services\HorariosService;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HorariosServiceTest extends TestCase
{
    private HorariosRepository&MockObject $repo;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(HorariosRepository::class);
    }

    private function service(): HorariosService
    {
        return new HorariosService($this->repo, $this->createMock(AuditoriaRepository::class));
    }

    /** L–V 08:00–17:00 con 60 min de almuerzo y S 08:00–12:00: 44 h. */
    private function datosTaller(array $cambiosDias = []): array
    {
        $dias = [];
        for ($d = 1; $d <= 5; $d++) {
            $dias[$d] = ['trabaja' => '1', 'entrada' => '08:00', 'salida' => '17:00', 'almuerzo' => '60'];
        }
        $dias[6] = ['trabaja' => '1', 'entrada' => '08:00', 'salida' => '12:00', 'almuerzo' => '0'];
        $dias[7] = ['trabaja' => '0', 'entrada' => '', 'salida' => '', 'almuerzo' => '0'];
        return ['nombre' => 'Taller', 'dias' => array_replace($dias, $cambiosDias)];
    }

    public function testCrearHorarioDelTallerGuardaSeisDias(): void
    {
        $this->repo->method('existsByNombre')->willReturn(false);
        $this->repo->expects(self::once())->method('insert')
            ->with('Taller', 'L–V 08:00–17:00 · S 08:00–12:00', self::callback(function (array $dias): bool {
                return count($dias) === 6
                    && $dias[0] === ['dia_semana' => 1, 'hora_entrada' => '08:00:00', 'hora_salida' => '17:00:00', 'minutos_almuerzo' => 60]
                    && $dias[5]['dia_semana'] === 6;
            }))
            ->willReturn(4);

        self::assertSame(4, $this->service()->crear($this->datosTaller(), 1, 'ip'));
    }

    public function testResumenUsaLasLetrasCostarricenses(): void
    {
        // Martes = K y miércoles = M (convención del calendario de la app).
        $soloMartesYMiercoles = [
            1 => ['trabaja' => '0', 'entrada' => '', 'salida' => '', 'almuerzo' => '0'],
            2 => ['trabaja' => '1', 'entrada' => '08:00', 'salida' => '12:00', 'almuerzo' => '0'],
            3 => ['trabaja' => '1', 'entrada' => '13:00', 'salida' => '17:00', 'almuerzo' => '0'],
            4 => ['trabaja' => '0', 'entrada' => '', 'salida' => '', 'almuerzo' => '0'],
            5 => ['trabaja' => '0', 'entrada' => '', 'salida' => '', 'almuerzo' => '0'],
            6 => ['trabaja' => '0', 'entrada' => '', 'salida' => '', 'almuerzo' => '0'],
            7 => ['trabaja' => '0', 'entrada' => '', 'salida' => '', 'almuerzo' => '0'],
        ];
        $this->repo->method('existsByNombre')->willReturn(false);
        $this->repo->expects(self::once())->method('insert')
            ->with('Martes y miércoles', 'K 08:00–12:00 · M 13:00–17:00', self::anything())
            ->willReturn(5);

        self::assertSame(5, $this->service()->crear(['nombre' => 'Martes y miércoles', 'dias' => $soloMartesYMiercoles], 1, 'ip'));
    }

    public function testRechazaSinDiasTrabajados(): void
    {
        $sinDias = [];
        for ($d = 1; $d <= 7; $d++) {
            $sinDias[$d] = ['trabaja' => '0', 'entrada' => '', 'salida' => '', 'almuerzo' => '0'];
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('al menos un día');
        $this->service()->crear(['nombre' => 'Vacío', 'dias' => $sinDias], 1, 'ip');
    }

    public function testRechazaSalidaAntesDeLaEntrada(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Lunes');
        $this->service()->crear($this->datosTaller([1 => ['trabaja' => '1', 'entrada' => '17:00', 'salida' => '08:00', 'almuerzo' => '0']]), 1, 'ip');
    }

    public function testRechazaJornadaDeMasDeDiezHoras(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('10 h');
        // 07:00–18:00 con 30 min = 10.5 h
        $this->service()->crear($this->datosTaller([2 => ['trabaja' => '1', 'entrada' => '07:00', 'salida' => '18:00', 'almuerzo' => '30']]), 1, 'ip');
    }

    public function testRechazaMasDeCuarentaYOchoHorasSemanales(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('48 h');
        // Sin almuerzo L–V son 9 h × 5 = 45 + 4 = 49 h
        $sinAlmuerzo = [];
        for ($d = 1; $d <= 5; $d++) {
            $sinAlmuerzo[$d] = ['trabaja' => '1', 'entrada' => '08:00', 'salida' => '17:00', 'almuerzo' => '0'];
        }
        $this->service()->crear($this->datosTaller($sinAlmuerzo), 1, 'ip');
    }

    public function testRechazaAlmuerzoMayorQueLaJornada(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('almuerzo');
        $this->service()->crear($this->datosTaller([6 => ['trabaja' => '1', 'entrada' => '08:00', 'salida' => '09:00', 'almuerzo' => '90']]), 1, 'ip');
    }

    public function testRechazaNombreRepetido(): void
    {
        $this->repo->method('existsByNombre')->willReturn(true);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ya existe');
        $this->service()->crear($this->datosTaller(), 1, 'ip');
    }

    public function testNoEliminaUnHorarioAsignado(): void
    {
        $this->repo->method('findById')->willReturn(['id_horario' => 4, 'nombre' => 'Taller', 'dias_semana' => 'x']);
        $this->repo->method('hasEmpleados')->willReturn(true);
        $this->repo->expects(self::never())->method('delete');
        $this->expectException(RuntimeException::class);
        $this->service()->eliminar(4, 1, 'ip');
    }

    public function testNoSeEditaNiEliminaElCentinela(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service()->eliminar(1, 1, 'ip');
    }

    public function testHorarioDelDiaDevuelveEntradaYSalidaDeEseDia(): void
    {
        $this->repo->method('findDiasPorEmpleado')->willReturn([
            ['dia_semana' => 1, 'hora_entrada' => '08:00:00', 'hora_salida' => '17:00:00', 'minutos_almuerzo' => 60],
            ['dia_semana' => 6, 'hora_entrada' => '08:00:00', 'hora_salida' => '12:00:00', 'minutos_almuerzo' => 0],
        ]);
        // 2026-10-17 es sábado; 2026-10-18 domingo
        self::assertSame(['entrada' => '08:00:00', 'salida' => '12:00:00', 'almuerzo' => 0], $this->service()->horarioDelDia(5, '2026-10-17'));
        self::assertNull($this->service()->horarioDelDia(5, '2026-10-18'));
    }

    public function testDiasDescansoSonLosQueNoTienenFila(): void
    {
        $this->repo->method('findDiasPorEmpleado')->willReturn([
            ['dia_semana' => 1, 'hora_entrada' => '08:00:00', 'hora_salida' => '17:00:00', 'minutos_almuerzo' => 60],
            ['dia_semana' => 2, 'hora_entrada' => '08:00:00', 'hora_salida' => '17:00:00', 'minutos_almuerzo' => 60],
            ['dia_semana' => 3, 'hora_entrada' => '08:00:00', 'hora_salida' => '17:00:00', 'minutos_almuerzo' => 60],
            ['dia_semana' => 4, 'hora_entrada' => '08:00:00', 'hora_salida' => '17:00:00', 'minutos_almuerzo' => 60],
            ['dia_semana' => 5, 'hora_entrada' => '08:00:00', 'hora_salida' => '17:00:00', 'minutos_almuerzo' => 60],
        ]);
        self::assertSame([6, 7], $this->service()->diasDescanso(5));
        self::assertTrue($this->service()->tieneHorario(5));
    }

    public function testSinHorarioElDescansoEsElDomingo(): void
    {
        $this->repo->method('findDiasPorEmpleado')->willReturn([]);
        self::assertSame([7], $this->service()->diasDescanso(5));
        self::assertFalse($this->service()->tieneHorario(5));
    }
}
