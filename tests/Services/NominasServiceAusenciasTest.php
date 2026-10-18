<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\NominaCalculadora;
use App\Repositories\AuditoriaRepository;
use App\Repositories\NominasRepository;
use App\Services\NominasService;
use PHPUnit\Framework\TestCase;

final class NominasServiceAusenciasTest extends TestCase
{
    public function testObtenerIncluyeLasAusenciasConQuienPaga(): void
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('findByIdConLineas')->willReturn([
            'id_nomina' => 3, 'id_empleado' => 5, 'id_periodo' => 9, 'estado' => 'pagado',
            'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-15', 'ingresos' => [], 'deducciones' => [],
        ]);
        $repo->method('incapacidadesDelPeriodo')->willReturn([
            ['tipo' => 'CCSS', 'fecha_inicio' => '2026-09-08', 'fecha_fin' => '2026-09-10', 'dias' => 3, 'fin_ccss_anterior' => '0000-00-00'],
            ['tipo' => 'INS', 'fecha_inicio' => '2026-09-14', 'fecha_fin' => '2026-09-20', 'dias' => 7, 'fin_ccss_anterior' => '0000-00-00'],
        ]);
        $repo->method('permisosSinGoceDelPeriodo')->willReturn([['fecha_inicio' => '2026-09-02', 'fecha_fin' => '2026-09-02']]);
        $repo->method('diasVacacionesDelPeriodo')->willReturn(0.0);

        $n = (new NominasService($repo, $this->createMock(AuditoriaRepository::class), new NominaCalculadora()))->obtener(3);

        self::assertSame('Patrono 50 % (días 1–3) y CCSS', $n['ausencias']['incapacidades'][0]['quien_paga']);
        self::assertSame(3, $n['ausencias']['incapacidades'][0]['dias_en_periodo']);
        self::assertSame('INS', $n['ausencias']['incapacidades'][1]['quien_paga']);
        self::assertSame(2, $n['ausencias']['incapacidades'][1]['dias_en_periodo']);   // 14 y 15
        self::assertCount(1, $n['ausencias']['permisos_sin_goce']);
    }
}
