# Nómina masiva por período — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que la generación de nóminas de un período refleje incapacidades, permisos sin goce, vacaciones (Art. 157), CCSS con la tasa vigente y renta; que el admin apruebe/pague todo el período con un botón; y que se exporte un reporte del período con los totales.

**Architecture:** Un helper puro `CalculadoraNominaPeriodo` contiene todas las reglas (días laborados, subsidio de incapacidad, vacaciones, CCSS sobre cotizables, renta progresiva) y se prueba con montos exactos. `ParametrosLegalesService` entrega tasas y tablas vigentes por fecha desde `parametros_legales`. `NominasService` solo reúne datos (repositories) y persiste; el recálculo usa la misma regla de la calculadora.

**Tech Stack:** PHP 8.1+ (`declare(strict_types=1)`), Slim 4, Twig 3, PHP-DI 7, MySQL/MariaDB con PDO puro, PHPUnit 11, phpoffice/phpspreadsheet, dompdf.

**Spec:** `docs/superpowers/specs/2026-10-18-nomina-masiva-design.md`

## Global Constraints

- Stack bloqueado: no ORM, no frameworks JS, no npm/Node/Docker (`CLAUDE.md`).
- Controller → Service → Repository; Repositories solo SQL; Controllers leen `$request->getAttribute('usuario')`, nunca `$_SESSION['usuario_*']`.
- Ninguna columna acepta NULL; textos opcionales `DEFAULT ''`.
- Salario diario = salario mensual ÷ 30; la quincena se paga como 15 días.
- Subsidio patronal de incapacidad CCSS: días 1–3 al 50 % del salario diario, sin reincapacidad (otra CCSS terminada en los 30 días previos); no cotiza CCSS ni tributa renta.
- Tasas CCSS: 0.1067 / 0.2667 hasta 2025-12-31; 0.1083 / 0.2683 desde 2026-01-01 (autorizado por Gabriel el 18/10/2026).
- Renta 2026 (Decreto 45333-H): exento hasta ₡918 000; 10 % hasta ₡1 347 000; 15 % hasta ₡2 364 000; 20 % hasta ₡4 727 000; 25 % sobre el exceso; créditos ₡1 710 por hijo y ₡2 590 por cónyuge; solo en la 2.ª quincena (período que empieza el día 16), sobre lo gravable de las dos quincenas del mes.
- Si falta un parámetro legal vigente, la generación no crea ninguna nómina y muestra `Falta cargar el parámetro legal {clave} vigente al dd/mm/aaaa.`
- Cada cambio de esquema va en `database/schema.sql` **y** en `database/migraciones/2026-10-18-nomina-masiva.sql`.
- Git: rama `feature/nomina-masiva`; agregar solo los archivos de la tarea (nunca `git add -A`); mensajes en español con línea `Co-Authored-By` del modelo que hizo el commit.
- `vendor/bin/phpunit` en verde al final de cada tarea. Textos de UI en español con tuteo.

## Review Focus

1. **Incapacidad que cruza dos períodos**: el número de día se cuenta desde el inicio real, no desde el inicio del período. → prueba en Task 2.
2. **Ausencias que suman más de 15 días** (incapacidad larga de toda la 2.ª quincena de un mes de 31 días): días laborados = 0, nunca negativo. → prueba en Task 2.
3. **2.ª quincena sin nómina de 1.ª quincena** (empleado que ingresó el 16): renta solo sobre la 2.ª quincena, sin error. → prueba en Task 3.
4. **Período de 2027 sin tramos de renta cargados**: no se genera ninguna nómina y el mensaje dice qué falta. → prueba en Task 3.
5. **"Aprobar todas" con una nómina ya aprobada en el período**: solo cambian las de borrador; el conteo informado es exacto. → prueba en Task 7.

---

### Task 1: Parámetros legales por vigencia y tasas CCSS 2026

**Files:**
- Modify: `database/schema.sql` (tabla `parametros_legales`: `valor` → `DECIMAL(14,4)`)
- Create: `database/migraciones/2026-10-18-nomina-masiva.sql`
- Modify: `database/seed.sql` (filas de parámetros), `database/demo.php` (bloque de parámetros)
- Create: `src/Repositories/ParametrosLegalesRepository.php`, `src/Services/ParametrosLegalesService.php`
- Modify: `src/Helpers/NominaCalculadora.php`, `config/dependencies.php`, `config/settings.php`, `CLAUDE.md`
- Test: `tests/Services/ParametrosLegalesServiceTest.php`, `tests/Helpers/NominaCalculadoraTest.php`

**Interfaces:**
- Produces:
  - `ParametrosLegalesRepository::valorVigente(string $clave, string $fecha): ?float`
  - `ParametrosLegalesService::tasasCcss(string $fecha): array{ccss_obrera: float, ccss_patronal: float}`
  - `ParametrosLegalesService::tablaRenta(string $fecha): array{tramos: list<array{desde: float, tasa: float}>, credito_hijo: float, credito_conyuge: float}`
  - Ambos lanzan `RuntimeException('Falta cargar el parámetro legal {clave} vigente al dd/mm/aaaa.')`.
  - `NominaCalculadora::__construct(float $ccssObrera = 0.0, float $ccssPatronal = 0.0)` y `deduccionesCCSS(float $bruto, ?array $tasas = null): array` (si `$tasas` trae `ccss_obrera`/`ccss_patronal`, se usan esas).

- [ ] **Step 1: Esquema y migración**

En `schema.sql`, tabla `parametros_legales`: `` `valor` DECIMAL(14,4) NOT NULL, `` y comentario de `clave`: `'Ej: factor_he_ord, factor_he_especial, ccss_obrera, ccss_patronal, renta_tramo_1'`.

`database/migraciones/2026-10-18-nomina-masiva.sql`:

```sql
-- Migración 2026-10-18: nómina masiva por período.
-- Cada sección corresponde a una tarea del plan; se agregan en orden.

-- ── Task 1: parámetros legales con vigencia ────────────────────
ALTER TABLE parametros_legales MODIFY `valor` DECIMAL(14,4) NOT NULL;
-- deducciones_nomina.id_parametro tiene FK a esta tabla: desligar antes de borrar las filas viejas.
UPDATE deducciones_nomina SET id_parametro = 1
 WHERE id_parametro IN (SELECT id_parametro FROM (SELECT id_parametro FROM parametros_legales WHERE clave IN ('ccss_obrera', 'ccss_patronal')) AS x);
DELETE FROM parametros_legales WHERE clave IN ('ccss_obrera', 'ccss_patronal');
INSERT INTO parametros_legales (clave, valor, fecha_inicio, fecha_fin) VALUES
('ccss_obrera',   0.1067, '2023-01-01', '2025-12-31'),
('ccss_obrera',   0.1083, '2026-01-01', '9999-12-31'),
('ccss_patronal', 0.2667, '2023-01-01', '2025-12-31'),
('ccss_patronal', 0.2683, '2026-01-01', '9999-12-31'),
('renta_tramo_1', 918000,  '2026-01-01', '2026-12-31'),
('renta_tramo_2', 1347000, '2026-01-01', '2026-12-31'),
('renta_tramo_3', 2364000, '2026-01-01', '2026-12-31'),
('renta_tramo_4', 4727000, '2026-01-01', '2026-12-31'),
('renta_tasa_1', 0.10, '2026-01-01', '2026-12-31'),
('renta_tasa_2', 0.15, '2026-01-01', '2026-12-31'),
('renta_tasa_3', 0.20, '2026-01-01', '2026-12-31'),
('renta_tasa_4', 0.25, '2026-01-01', '2026-12-31'),
('renta_credito_hijo',    1710, '2026-01-01', '2026-12-31'),
('renta_credito_conyuge', 2590, '2026-01-01', '2026-12-31');
```

`seed.sql`: agregar después del INSERT del centinela de `parametros_legales` las mismas filas (sin el DELETE). `demo.php`: el `foreach` de parámetros deja solo `factor_he_ord` y `factor_he_especial` (las tasas y la renta ya vienen del seed).

- [ ] **Step 2: Pruebas que fallan**

`tests/Services/ParametrosLegalesServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\ParametrosLegalesRepository;
use App\Services\ParametrosLegalesService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ParametrosLegalesServiceTest extends TestCase
{
    private const VALORES = [
        '2025' => ['ccss_obrera' => 0.1067, 'ccss_patronal' => 0.2667],
        '2026' => [
            'ccss_obrera' => 0.1083, 'ccss_patronal' => 0.2683,
            'renta_tramo_1' => 918000.0, 'renta_tramo_2' => 1347000.0, 'renta_tramo_3' => 2364000.0, 'renta_tramo_4' => 4727000.0,
            'renta_tasa_1' => 0.10, 'renta_tasa_2' => 0.15, 'renta_tasa_3' => 0.20, 'renta_tasa_4' => 0.25,
            'renta_credito_hijo' => 1710.0, 'renta_credito_conyuge' => 2590.0,
        ],
    ];

    private function service(): ParametrosLegalesService
    {
        $repo = $this->createMock(ParametrosLegalesRepository::class);
        $repo->method('valorVigente')->willReturnCallback(
            fn (string $clave, string $fecha) => self::VALORES[substr($fecha, 0, 4)][$clave] ?? null
        );
        return new ParametrosLegalesService($repo);
    }

    public function testTasasCcssSegunLaFecha(): void
    {
        self::assertSame(['ccss_obrera' => 0.1067, 'ccss_patronal' => 0.2667], $this->service()->tasasCcss('2025-12-16'));
        self::assertSame(['ccss_obrera' => 0.1083, 'ccss_patronal' => 0.2683], $this->service()->tasasCcss('2026-01-01'));
    }

    public function testTablaRenta2026(): void
    {
        $t = $this->service()->tablaRenta('2026-10-16');
        self::assertSame([
            ['desde' => 918000.0, 'tasa' => 0.10], ['desde' => 1347000.0, 'tasa' => 0.15],
            ['desde' => 2364000.0, 'tasa' => 0.20], ['desde' => 4727000.0, 'tasa' => 0.25],
        ], $t['tramos']);
        self::assertSame(1710.0, $t['credito_hijo']);
        self::assertSame(2590.0, $t['credito_conyuge']);
    }

    public function testSinRenta2025ElMensajeDiceQueFalta(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Falta cargar el parámetro legal renta_tramo_1 vigente al 16/12/2025.');
        $this->service()->tablaRenta('2025-12-16');
    }
}
```

En `tests/Helpers/NominaCalculadoraTest.php` agregar:

```php
    public function testDeduccionesCcssConTasasDeLaFecha(): void
    {
        $d = (new NominaCalculadora())->deduccionesCCSS(100000.0, ['ccss_obrera' => 0.1083, 'ccss_patronal' => 0.2683]);
        self::assertSame(10830.0, $d[0]['monto']);
        self::assertSame(10.83, $d[0]['porcentaje']);
        self::assertSame(26830.0, $d[1]['monto']);
        self::assertTrue($d[1]['informativa']);
    }
```

Run: `vendor/bin/phpunit tests/Services/ParametrosLegalesServiceTest.php tests/Helpers/NominaCalculadoraTest.php` → Expected: ERROR/FAIL.

- [ ] **Step 3: Implementar**

`src/Repositories/ParametrosLegalesRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * ParametrosLegalesRepository — SQL de `parametros_legales`. Sin lógica de negocio.
 */
class ParametrosLegalesRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function valorVigente(string $clave, string $fecha): ?float
    {
        $stmt = $this->pdo->prepare(
            'SELECT valor FROM parametros_legales
              WHERE clave = :c AND :f BETWEEN fecha_inicio AND fecha_fin
              ORDER BY fecha_inicio DESC LIMIT 1'
        );
        $stmt->execute([':c' => $clave, ':f' => $fecha]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (float)$v;
    }
}
```

`src/Services/ParametrosLegalesService.php`:

```php
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
```

`NominaCalculadora`: constructor con valores por defecto `0.0` (comentario: "las tasas reales llegan por fecha desde ParametrosLegalesService"); `deduccionesCCSS(float $bruto, ?array $tasas = null)` usa `$tasas['ccss_obrera'] ?? $this->ccssObrera` y `$tasas['ccss_patronal'] ?? $this->ccssPatronal`.

`config/dependencies.php`: registrar `ParametrosLegalesRepository` (PDO) y `ParametrosLegalesService` (repo); `NominaCalculadora` pasa a `new \App\Helpers\NominaCalculadora()`.

`config/settings.php`: reemplazar las líneas `ccss_obrera`/`ccss_patronal` por el comentario
`// Tasas CCSS y tramos de renta: tabla parametros_legales con vigencia (ParametrosLegalesService).`

`CLAUDE.md`, bloque "Reglas legales de Costa Rica": reemplazar las dos líneas `ccss_*` por

```php
// CCSS (tabla parametros_legales, por vigencia; autorizado por Gabriel el 18/10/2026):
//   hasta 2025-12-31: obrera 10.67 %, patronal 26.67 %
//   desde 2026-01-01: obrera 10.83 %, patronal 26.83 % (nota CCSS 18/12/2025, IVM 4.33 % / 5.58 %)
// Renta 2026 (Decreto 45333-H): exento ₡918 000; 10/15/20/25 %; créditos ₡1 710 hijo, ₡2 590 cónyuge
```

y la línea `// En config/settings.php — NO cambiar estos valores` por `// Valores legales — no cambiar sin fuente oficial y autorización`.

`NominasService` no se toca en esta tarea (sigue llamando `deduccionesCCSS` sin tasas). Para que entre esta tarea y la Task 3 las nóminas ya usen la tasa 2026, el DI construye `NominaCalculadora(0.1083, 0.2683)`; la Task 3 lo cambia a `new NominaCalculadora()` cuando las tasas lleguen por fecha. Escribir así el DI en este paso:

```php
    \App\Helpers\NominaCalculadora::class => function (ContainerInterface $c) {
        // Tasas por defecto hasta que NominasService las tome por fecha (Task 3 de nómina masiva).
        return new \App\Helpers\NominaCalculadora(0.1083, 0.2683);
    },
```

- [ ] **Step 4: Correr pruebas, aplicar migración (solo sección Task 1) y commit**

Run: `vendor/bin/phpunit` → OK. Aplicar la sección a la base local (copia temporal fuera del repo, `mysql --default-character-set=utf8mb4 -u root lubrimotos_nomina < archivo`) y verificar `SELECT clave, valor, fecha_inicio FROM parametros_legales ORDER BY clave, fecha_inicio`.

```bash
git add database/schema.sql database/seed.sql database/demo.php database/migraciones/2026-10-18-nomina-masiva.sql src/Repositories/ParametrosLegalesRepository.php src/Services/ParametrosLegalesService.php src/Helpers/NominaCalculadora.php config/dependencies.php config/settings.php CLAUDE.md tests/Services/ParametrosLegalesServiceTest.php tests/Helpers/NominaCalculadoraTest.php
git commit -m "feat(parametros): tasas CCSS 2026 y tramos de renta por vigencia"
```

---

### Task 2: `CalculadoraNominaPeriodo`

**Files:**
- Create: `src/Helpers/CalculadoraNominaPeriodo.php`
- Modify: `config/dependencies.php`
- Test: `tests/Helpers/CalculadoraNominaPeriodoTest.php`

**Interfaces:**
- Consumes: `NominaCalculadora::{deduccionesCCSS(float, ?array), calcularTotales(array, array)}` (Task 1).
- Produces:
  - `CalculadoraNominaPeriodo::__construct(NominaCalculadora $base)`
  - `static esCotizable(string $tipo): bool`, `static esGravable(string $tipo): bool` (falso solo para `subsidio_incapacidad`)
  - `calcular(array $e): array{ingresos: list<array>, deducciones: list<array>, totales: array, dias_laborados: float, gravable: float}` con `$e` = `salario_mensual` (float), `fecha_inicio`, `fecha_fin` (Y-m-d), `incapacidades` (list de `tipo`, `fecha_inicio`, `fecha_fin`, `reincapacidad` bool), `dias_permiso_sin_goce` (float), `dias_vacaciones` (float), `promedio_diario_50_semanas` (float), `lineas_adicionales` (list de `tipo`, `descripcion`, `monto`), `tasas` (`ccss_obrera`, `ccss_patronal`), `renta` (null o `['tabla' => tablaRenta, 'hijos' => int, 'conyuge' => bool, 'gravable_primera_quincena' => float]`)
  - `deduccionesLegales(array $ingresos, array $tasas, ?array $renta): list<array>` (CCSS sobre cotizables + renta si `$renta` no es null; lo usa el recálculo)
  - `impuestoRenta(float $baseMensual, array $tabla, int $hijos, bool $conyuge): float`
  - `diasIncapacidadEnPeriodo(array $inc, string $ini, string $fin): int`, `diasSubsidioEnPeriodo(array $inc, string $ini, string $fin): int`

- [ ] **Step 1: Pruebas que fallan**

`tests/Helpers/CalculadoraNominaPeriodoTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Helpers\CalculadoraNominaPeriodo;
use App\Helpers\NominaCalculadora;
use PHPUnit\Framework\TestCase;

final class CalculadoraNominaPeriodoTest extends TestCase
{
    private const TASAS = ['ccss_obrera' => 0.1083, 'ccss_patronal' => 0.2683];
    private const TABLA = [
        'tramos' => [['desde' => 918000.0, 'tasa' => 0.10], ['desde' => 1347000.0, 'tasa' => 0.15],
                     ['desde' => 2364000.0, 'tasa' => 0.20], ['desde' => 4727000.0, 'tasa' => 0.25]],
        'credito_hijo' => 1710.0, 'credito_conyuge' => 2590.0,
    ];

    private function c(): CalculadoraNominaPeriodo
    {
        return new CalculadoraNominaPeriodo(new NominaCalculadora());
    }

    /** Quincena 1–15 de setiembre 2026, salario 480 000 (diario 16 000). */
    private function base(array $cambios = []): array
    {
        return $cambios + [
            'salario_mensual' => 480000.0, 'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-15',
            'incapacidades' => [], 'dias_permiso_sin_goce' => 0.0, 'dias_vacaciones' => 0.0,
            'promedio_diario_50_semanas' => 16000.0, 'lineas_adicionales' => [], 'tasas' => self::TASAS, 'renta' => null,
        ];
    }

    private function linea(array $r, string $tipo): ?array
    {
        foreach ($r['ingresos'] as $i) { if ($i['tipo'] === $tipo) { return $i; } }
        foreach ($r['deducciones'] as $d) { if ($d['tipo'] === $tipo) { return $d; } }
        return null;
    }

    public function testQuincenaSinAusencias(): void
    {
        $r = $this->c()->calcular($this->base());
        self::assertSame(240000.0, $this->linea($r, 'salario_base')['monto']);
        self::assertSame('Salario quincenal: 15 de 15 días', $this->linea($r, 'salario_base')['descripcion']);
        self::assertSame(25992.0, $this->linea($r, 'CCSS_empleado')['monto']);   // 240 000 × 10.83 %
        self::assertSame(214008.0, $r['totales']['salario_neto']);
    }

    public function testIncapacidadCcssDeCincoDias(): void
    {
        $r = $this->c()->calcular($this->base(['incapacidades' => [
            ['tipo' => 'CCSS', 'fecha_inicio' => '2026-09-08', 'fecha_fin' => '2026-09-12', 'reincapacidad' => false],
        ]]));
        self::assertSame(160000.0, $this->linea($r, 'salario_base')['monto']);
        self::assertSame(24000.0, $this->linea($r, 'subsidio_incapacidad')['monto']);   // 3 × 8 000
        self::assertSame(17328.0, $this->linea($r, 'CCSS_empleado')['monto']);         // solo sobre 160 000
        self::assertSame(10.0, $r['dias_laborados']);
    }

    public function testIncapacidadQueCruzaPeriodosPagaSoloElDiaTresEnLaSegunda(): void
    {
        $r = $this->c()->calcular($this->base([
            'fecha_inicio' => '2026-09-16', 'fecha_fin' => '2026-09-30',
            'incapacidades' => [['tipo' => 'CCSS', 'fecha_inicio' => '2026-09-14', 'fecha_fin' => '2026-09-18', 'reincapacidad' => false]],
        ]));
        self::assertSame(8000.0, $this->linea($r, 'subsidio_incapacidad')['monto']);   // solo el 16 (día 3)
        self::assertSame(192000.0, $this->linea($r, 'salario_base')['monto']);         // 15 − 3 días (16, 17, 18)
    }

    public function testReincapacidadNoTieneSubsidioPatronal(): void
    {
        $r = $this->c()->calcular($this->base(['incapacidades' => [
            ['tipo' => 'CCSS', 'fecha_inicio' => '2026-09-08', 'fecha_fin' => '2026-09-10', 'reincapacidad' => true],
        ]]));
        self::assertNull($this->linea($r, 'subsidio_incapacidad'));
        self::assertSame(192000.0, $this->linea($r, 'salario_base')['monto']);
    }

    public function testIncapacidadInsYParticularSoloRebajan(): void
    {
        $r = $this->c()->calcular($this->base(['incapacidades' => [
            ['tipo' => 'INS', 'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-02', 'reincapacidad' => false],
            ['tipo' => 'particular', 'fecha_inicio' => '2026-09-10', 'fecha_fin' => '2026-09-10', 'reincapacidad' => false],
        ]]));
        self::assertNull($this->linea($r, 'subsidio_incapacidad'));
        self::assertSame(192000.0, $this->linea($r, 'salario_base')['monto']);   // 12 días
    }

    public function testPermisoSinGoceDeUnDia(): void
    {
        $r = $this->c()->calcular($this->base(['dias_permiso_sin_goce' => 1.0]));
        self::assertSame(224000.0, $this->linea($r, 'salario_base')['monto']);
        self::assertSame('Salario quincenal: 14 de 15 días', $this->linea($r, 'salario_base')['descripcion']);
    }

    public function testVacacionesSinDiferencia(): void
    {
        $r = $this->c()->calcular($this->base(['dias_vacaciones' => 3.0]));
        self::assertSame(192000.0, $this->linea($r, 'salario_base')['monto']);
        $vac = array_values(array_filter($r['ingresos'], fn ($i) => $i['tipo'] === 'vacaciones'));
        self::assertCount(1, $vac);
        self::assertSame(48000.0, $vac[0]['monto']);
        self::assertSame(240000.0, $r['totales']['salario_bruto']);
    }

    public function testVacacionesConDiferenciaArt157(): void
    {
        $r = $this->c()->calcular($this->base(['dias_vacaciones' => 3.0, 'promedio_diario_50_semanas' => 18000.0]));
        $vac = array_values(array_filter($r['ingresos'], fn ($i) => $i['tipo'] === 'vacaciones'));
        self::assertCount(2, $vac);
        self::assertSame('Diferencia de vacaciones (Art. 157)', $vac[1]['descripcion']);
        self::assertSame(6000.0, $vac[1]['monto']);   // 3 × (18 000 − 16 000)
    }

    public function testAusenciasDeMasDeQuinceDiasDejanCeroDiasLaborados(): void
    {
        $r = $this->c()->calcular($this->base([
            'fecha_inicio' => '2026-10-16', 'fecha_fin' => '2026-10-31',
            'incapacidades' => [['tipo' => 'INS', 'fecha_inicio' => '2026-10-10', 'fecha_fin' => '2026-11-05', 'reincapacidad' => false]],
        ]));
        self::assertSame(0.0, $this->linea($r, 'salario_base')['monto']);
        self::assertSame(0.0, $r['dias_laborados']);
    }

    public function testLasLineasAdicionalesCotizan(): void
    {
        $r = $this->c()->calcular($this->base(['lineas_adicionales' => [
            ['tipo' => 'horas_extra', 'descripcion' => 'Horas extra 10/09/2026: 2.00 h × 1.50', 'monto' => 6000.0],
        ]]));
        self::assertSame(26641.8, $this->linea($r, 'CCSS_empleado')['monto']);   // 246 000 × 10.83 %
    }

    public function testRentaSobreUnMillonAlMes(): void
    {
        self::assertSame(8200.0, $this->c()->impuestoRenta(1000000.0, self::TABLA, 0, false));
    }

    public function testRentaConDosHijosYConyuge(): void
    {
        self::assertSame(2190.0, $this->c()->impuestoRenta(1000000.0, self::TABLA, 2, true));
    }

    public function testRentaBajoElExentoEsCero(): void
    {
        self::assertSame(0.0, $this->c()->impuestoRenta(900000.0, self::TABLA, 0, false));
        self::assertSame(0.0, $this->c()->impuestoRenta(919000.0, self::TABLA, 1, false));   // 100 − 1 710 → 0
    }

    public function testRentaTodosLosTramos(): void
    {
        // 429 000 × 10 % + 1 017 000 × 15 % + 2 363 000 × 20 % + 273 000 × 25 %
        self::assertSame(42900.0 + 152550.0 + 472600.0 + 68250.0, $this->c()->impuestoRenta(5000000.0, self::TABLA, 0, false));
    }

    public function testSegundaQuincenaRetieneRentaSobreElMesYElSubsidioNoTributa(): void
    {
        $r = $this->c()->calcular($this->base([
            'salario_mensual' => 1000000.0, 'fecha_inicio' => '2026-09-16', 'fecha_fin' => '2026-09-30',
            'renta' => ['tabla' => self::TABLA, 'hijos' => 0, 'conyuge' => false, 'gravable_primera_quincena' => 500000.0],
        ]));
        self::assertSame(8200.0, $this->linea($r, 'impuesto_renta')['monto']);

        $conSubsidio = $this->c()->calcular($this->base([
            'salario_mensual' => 1000000.0, 'fecha_inicio' => '2026-09-16', 'fecha_fin' => '2026-09-30',
            'incapacidades' => [['tipo' => 'CCSS', 'fecha_inicio' => '2026-09-16', 'fecha_fin' => '2026-09-18', 'reincapacidad' => false]],
            'renta' => ['tabla' => self::TABLA, 'hijos' => 0, 'conyuge' => false, 'gravable_primera_quincena' => 500000.0],
        ]));
        // gravable 2.ª = 12 × 33 333.33 = 400 000; mes 900 000 → bajo el exento
        self::assertNull($this->linea($conSubsidio, 'impuesto_renta'));
    }

    public function testPrimeraQuincenaNoRetieneRenta(): void
    {
        $r = $this->c()->calcular($this->base(['salario_mensual' => 3000000.0]));
        self::assertNull($this->linea($r, 'impuesto_renta'));
    }

    public function testCotizableYGravable(): void
    {
        self::assertFalse(CalculadoraNominaPeriodo::esCotizable('subsidio_incapacidad'));
        self::assertFalse(CalculadoraNominaPeriodo::esGravable('subsidio_incapacidad'));
        self::assertTrue(CalculadoraNominaPeriodo::esCotizable('vacaciones'));
        self::assertTrue(CalculadoraNominaPeriodo::esGravable('horas_extra'));
    }
}
```

Run: `vendor/bin/phpunit tests/Helpers/CalculadoraNominaPeriodoTest.php` → Expected: ERROR class not found.

- [ ] **Step 2: Implementar**

```php
<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;

/**
 * CalculadoraNominaPeriodo — reglas legales de la nómina quincenal. Función pura: sin BD ni reloj.
 *
 * - Salario diario = mensual ÷ 30; la quincena se paga como 15 días.
 * - Incapacidad CCSS: el patrono paga el 50 % de los días 1–3 (Código de Trabajo Art. 79 según
 *   MTSS DAJ-AE-221-04); desde el día 4 paga la CCSS (Reglamento del Seguro de Salud Art. 35);
 *   reincapacidad dentro de 30 días: la CCSS paga desde el día 1. INS y particular: el patrono no paga.
 * - El subsidio no es salario: no cotiza CCSS ni tributa renta (Sala II Voto 477-2012; Ley 7092 Art. 35).
 * - Vacaciones al promedio de las últimas 50 semanas si es mayor (Código de Trabajo Art. 157).
 * - Renta mensual progresiva (Ley 7092 Arts. 32–34; Decreto 45333-H), retenida en la 2.ª quincena.
 */
final class CalculadoraNominaPeriodo
{
    public const DIAS_QUINCENA = 15.0;
    private const DIAS_SUBSIDIO_PATRONAL = 3;

    public function __construct(private readonly NominaCalculadora $base) {}

    public static function esCotizable(string $tipo): bool
    {
        return $tipo !== 'subsidio_incapacidad';
    }

    public static function esGravable(string $tipo): bool
    {
        return $tipo !== 'subsidio_incapacidad';
    }

    /**
     * @param array<string, mixed> $e ver interfaz en el plan
     * @return array{ingresos: list<array<string, mixed>>, deducciones: list<array<string, mixed>>,
     *               totales: array<string, float>, dias_laborados: float, gravable: float}
     */
    public function calcular(array $e): array
    {
        $diario = (float)$e['salario_mensual'] / 30;
        $ini    = (string)$e['fecha_inicio'];
        $fin    = (string)$e['fecha_fin'];

        $diasIncapacidad = 0;
        $subsidios       = [];
        foreach ($e['incapacidades'] as $inc) {
            $diasIncapacidad += $this->diasIncapacidadEnPeriodo($inc, $ini, $fin);
            $dias = $this->diasSubsidioEnPeriodo($inc, $ini, $fin);
            if ($dias > 0) {
                $subsidios[] = [
                    'tipo'        => 'subsidio_incapacidad',
                    'descripcion' => sprintf('Subsidio patronal incapacidad %s–%s (50 %%)',
                        (new DateTimeImmutable((string)$inc['fecha_inicio']))->format('d/m'),
                        (new DateTimeImmutable((string)$inc['fecha_fin']))->format('d/m')),
                    'monto'       => round($dias * $diario * 0.5, 2),
                ];
            }
        }

        $diasVacaciones = (float)$e['dias_vacaciones'];
        $laborados = max(0.0, self::DIAS_QUINCENA - $diasIncapacidad - (float)$e['dias_permiso_sin_goce'] - $diasVacaciones);

        $ingresos = [[
            'tipo'        => 'salario_base',
            'descripcion' => sprintf('Salario quincenal: %s de 15 días', $this->numero($laborados)),
            'monto'       => round($laborados * $diario, 2),
        ]];
        if ($diasVacaciones > 0) {
            $ingresos[] = [
                'tipo'        => 'vacaciones',
                'descripcion' => sprintf('Vacaciones: %s días', $this->numero($diasVacaciones)),
                'monto'       => round($diasVacaciones * $diario, 2),
            ];
            $diferencia = (float)$e['promedio_diario_50_semanas'] - $diario;
            if ($diferencia > 0.004) {
                $ingresos[] = [
                    'tipo'        => 'vacaciones',
                    'descripcion' => 'Diferencia de vacaciones (Art. 157)',
                    'monto'       => round($diasVacaciones * $diferencia, 2),
                ];
            }
        }
        foreach ($subsidios as $s) {
            $ingresos[] = $s;
        }
        foreach ($e['lineas_adicionales'] as $l) {
            $ingresos[] = ['tipo' => (string)$l['tipo'], 'descripcion' => (string)$l['descripcion'], 'monto' => round((float)$l['monto'], 2)];
        }

        $deducciones = $this->deduccionesLegales($ingresos, (array)$e['tasas'], $e['renta']);

        return [
            'ingresos'       => $ingresos,
            'deducciones'    => $deducciones,
            'totales'        => $this->base->calcularTotales($ingresos, $deducciones),
            'dias_laborados' => $laborados,
            'gravable'       => $this->suma($ingresos, [self::class, 'esGravable']),
        ];
    }

    /**
     * CCSS sobre los ingresos cotizables y, si `$renta` no es null, la renta del mes.
     * @param list<array<string, mixed>> $ingresos
     * @param array{ccss_obrera: float, ccss_patronal: float} $tasas
     * @param array{tabla: array, hijos: int, conyuge: bool, gravable_primera_quincena: float}|null $renta
     * @return list<array<string, mixed>>
     */
    public function deduccionesLegales(array $ingresos, array $tasas, ?array $renta): array
    {
        $deducciones = $this->base->deduccionesCCSS($this->suma($ingresos, [self::class, 'esCotizable']), $tasas);
        if ($renta !== null) {
            $mes      = round((float)$renta['gravable_primera_quincena'] + $this->suma($ingresos, [self::class, 'esGravable']), 2);
            $impuesto = $this->impuestoRenta($mes, $renta['tabla'], (int)$renta['hijos'], (bool)$renta['conyuge']);
            if ($impuesto > 0) {
                $deducciones[] = [
                    'tipo'        => 'impuesto_renta',
                    'descripcion' => sprintf('Impuesto sobre la renta (gravable del mes ₡%s)', number_format($mes, 2, '.', ',')),
                    'porcentaje'  => 0.0,
                    'monto'       => $impuesto,
                    'informativa' => false,
                ];
            }
        }
        return $deducciones;
    }

    /** @param array{tramos: list<array{desde: float, tasa: float}>, credito_hijo: float, credito_conyuge: float} $tabla */
    public function impuestoRenta(float $baseMensual, array $tabla, int $hijos, bool $conyuge): float
    {
        $impuesto = 0.0;
        $tramos   = $tabla['tramos'];
        foreach ($tramos as $i => $t) {
            $hasta = $tramos[$i + 1]['desde'] ?? INF;
            $impuesto += max(0.0, min($baseMensual, $hasta) - $t['desde']) * $t['tasa'];
        }
        $creditos = $hijos * $tabla['credito_hijo'] + ($conyuge ? $tabla['credito_conyuge'] : 0.0);
        return round(max(0.0, $impuesto - $creditos), 2);
    }

    /** @param array<string, mixed> $inc */
    public function diasIncapacidadEnPeriodo(array $inc, string $ini, string $fin): int
    {
        $desde = max((string)$inc['fecha_inicio'], $ini);
        $hasta = min((string)$inc['fecha_fin'], $fin);
        if ($desde > $hasta) {
            return 0;
        }
        return (new DateTimeImmutable($hasta))->diff(new DateTimeImmutable($desde))->days + 1;
    }

    /** Días 1–3 de una incapacidad CCSS (sin reincapacidad) que caen dentro del período. @param array<string, mixed> $inc */
    public function diasSubsidioEnPeriodo(array $inc, string $ini, string $fin): int
    {
        if ($inc['tipo'] !== 'CCSS' || !empty($inc['reincapacidad'])) {
            return 0;
        }
        $inicioInc = new DateTimeImmutable((string)$inc['fecha_inicio']);
        $dias = 0;
        for ($n = 0; $n < self::DIAS_SUBSIDIO_PATRONAL; $n++) {
            $f = $inicioInc->modify("+{$n} day")->format('Y-m-d');
            if ($f > (string)$inc['fecha_fin']) {
                break;
            }
            if ($f >= $ini && $f <= $fin) {
                $dias++;
            }
        }
        return $dias;
    }

    /** @param list<array<string, mixed>> $ingresos */
    private function suma(array $ingresos, callable $filtro): float
    {
        $s = 0.0;
        foreach ($ingresos as $i) {
            if ($filtro((string)$i['tipo'])) {
                $s += (float)$i['monto'];
            }
        }
        return round($s, 2);
    }

    private function numero(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
    }
}
```

`config/dependencies.php`:

```php
    \App\Helpers\CalculadoraNominaPeriodo::class => function (ContainerInterface $c) {
        return new \App\Helpers\CalculadoraNominaPeriodo($c->get(\App\Helpers\NominaCalculadora::class));
    },
```

Nota sobre el test de la quincena que cruza: el 14 y 15 son días 1 y 2 (1.ª quincena) y el 16 es día 3; en la 2.ª quincena hay 3 días de incapacidad (16, 17, 18) → 12 días laborados × 16 000 = 192 000.

Nota sobre `testSegundaQuincena…`: salario 1 000 000 → diario 33 333,33; con 3 días de incapacidad hay 12 laborados = 400 000,00 (redondeo de 12 × 33 333,333…). Si el redondeo da 399 999,96 o similar, el resultado sigue bajo el exento (≤ 918 000); la aserción `assertNull` no depende del centavo.

Run: `vendor/bin/phpunit tests/Helpers/CalculadoraNominaPeriodoTest.php` → OK (18 tests). Si algún monto exacto difiere solo por redondeo de un centavo, revisar el cálculo (no ajustar la aserción sin entender la causa).

- [ ] **Step 3: Suite y commit**

```bash
git add src/Helpers/CalculadoraNominaPeriodo.php tests/Helpers/CalculadoraNominaPeriodoTest.php config/dependencies.php
git commit -m "feat(nominas): calculadora de la nómina del período con incapacidades, vacaciones y renta"
```

---

### Task 3: Lecturas del período y nueva generación en `NominasService`

**Files:**
- Modify: `database/schema.sql`, `database/migraciones/2026-10-18-nomina-masiva.sql` (enum `ingresos_nomina.tipo`; columnas `empleados.renta_hijos`, `renta_conyuge`)
- Modify: `src/Repositories/NominasRepository.php`, `src/Services/NominasService.php`, `config/dependencies.php`
- Test: `tests/Services/NominasServiceGeneracionTest.php`

**Interfaces:**
- Consumes: `CalculadoraNominaPeriodo::calcular` (Task 2); `ParametrosLegalesService::{tasasCcss, tablaRenta}` (Task 1); `DiasHabilesService::contar(string $inicio, string $fin, ?int $idEmpleado = null): float` (existente).
- Produces (repository, solo SQL):
  - `empleadosActivosSinNomina(int $idPeriodo)` ahora también devuelve `renta_hijos`, `renta_conyuge`
  - `incapacidadesDelPeriodo(int $idEmpleado, string $ini, string $fin): array` (filas `tipo, fecha_inicio, fecha_fin, dias, fin_ccss_anterior` — `fin_ccss_anterior` = mayor `fecha_fin` de otra incapacidad CCSS del empleado que terminó antes del inicio de esta, o `'0000-00-00'`)
  - `permisosSinGoceDelPeriodo(int $idEmpleado, string $ini, string $fin): array` (`fecha_inicio, fecha_fin`)
  - `diasVacacionesDelPeriodo(int $idEmpleado, int $idPeriodo): float`
  - `brutoEntre(int $idEmpleado, string $desde, string $hasta): float` (suma de `salario_bruto` de nóminas cuyo período termina entre las fechas)
  - `lineasPrimeraQuincena(int $idEmpleado, string $inicioMes): array` (filas `tipo, monto` de los ingresos de la nómina del período que empieza `$inicioMes`)
  - `datosRentaEmpleado(int $idEmpleado): array` (`renta_hijos`, `renta_conyuge`)
- Produces (service):
  - `NominasService::__construct(NominasRepository, AuditoriaRepository, NominaCalculadora, ?DeteccionHorasExtraService = null, ?ParametrosLegalesService = null, ?DiasHabilesService = null, ?CalculadoraNominaPeriodo = null)`
  - `NominasService::datosCalculo(int $idEmpleado, int $idPeriodo, float $salario, array $periodo, array $tasas, ?array $tablaRenta, array $empleado): array` (privado; arma la entrada de la calculadora)

- [ ] **Step 1: Esquema**

`schema.sql`:
- `ingresos_nomina.tipo`: `ENUM('salario_base','horas_extra','bonificacion','feriado','descanso_trabajado','vacaciones','subsidio_incapacidad','otro') NOT NULL`
- `empleados`, después de `estado`: `` `renta_hijos` TINYINT NOT NULL DEFAULT 0 COMMENT 'Hijos para crédito de renta (Ley 7092 Art. 34)', `` y `` `renta_conyuge` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = aplica crédito por cónyuge', ``

Migración:

```sql
-- ── Task 3: tipos de ingreso y datos de renta ─────────────────
ALTER TABLE ingresos_nomina
    MODIFY `tipo` ENUM('salario_base','horas_extra','bonificacion','feriado','descanso_trabajado','vacaciones','subsidio_incapacidad','otro') NOT NULL;
ALTER TABLE empleados
    ADD COLUMN `renta_hijos` TINYINT NOT NULL DEFAULT 0 COMMENT 'Hijos para crédito de renta (Ley 7092 Art. 34)',
    ADD COLUMN `renta_conyuge` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = aplica crédito por cónyuge';
```

- [ ] **Step 2: Pruebas que fallan**

Reemplazar el contenido de `tests/Services/NominasServiceGeneracionTest.php` por (conserva los dos casos existentes y agrega los nuevos):

```php
<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\CalculadoraNominaPeriodo;
use App\Helpers\NominaCalculadora;
use App\Repositories\AuditoriaRepository;
use App\Repositories\NominasRepository;
use App\Services\DeteccionHorasExtraService;
use App\Services\DiasHabilesService;
use App\Services\NominasService;
use App\Services\ParametrosLegalesService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NominasServiceGeneracionTest extends TestCase
{
    private NominasRepository&MockObject $repo;
    private ParametrosLegalesService&MockObject $parametros;
    private DeteccionHorasExtraService&MockObject $deteccion;
    private DiasHabilesService&MockObject $diasHabiles;
    /** @var list<array<string, mixed>> */
    private array $ingresos = [];
    /** @var list<array<string, mixed>> */
    private array $deducciones = [];

    protected function setUp(): void
    {
        $this->repo        = $this->createMock(NominasRepository::class);
        $this->parametros  = $this->createMock(ParametrosLegalesService::class);
        $this->deteccion   = $this->createMock(DeteccionHorasExtraService::class);
        $this->diasHabiles = $this->createMock(DiasHabilesService::class);
        $this->repo->method('existePeriodo')->willReturn(true);
        $this->parametros->method('tasasCcss')->willReturn(['ccss_obrera' => 0.1083, 'ccss_patronal' => 0.2683]);
        $this->parametros->method('tablaRenta')->willReturn([
            'tramos' => [['desde' => 918000.0, 'tasa' => 0.10], ['desde' => 1347000.0, 'tasa' => 0.15],
                         ['desde' => 2364000.0, 'tasa' => 0.20], ['desde' => 4727000.0, 'tasa' => 0.25]],
            'credito_hijo' => 1710.0, 'credito_conyuge' => 2590.0,
        ]);
        $this->repo->method('insertNominaCompleta')->willReturnCallback(function (array $cab, array $i, array $d) {
            $this->ingresos = $i;
            $this->deducciones = $d;
            return 1;
        });
    }

    private function service(): NominasService
    {
        $calc = new NominaCalculadora();
        return new NominasService($this->repo, $this->createMock(AuditoriaRepository::class), $calc,
            $this->deteccion, $this->parametros, $this->diasHabiles, new CalculadoraNominaPeriodo($calc));
    }

    private function empleado(float $salario = 480000.0, int $hijos = 0, int $conyuge = 0): void
    {
        $this->repo->method('empleadosActivosSinNomina')->willReturn([
            ['id_empleado' => 5, 'salario_mensual' => (string)$salario, 'renta_hijos' => $hijos, 'renta_conyuge' => $conyuge],
        ]);
    }

    private function tipo(string $tipo): array
    {
        return array_values(array_filter(array_merge($this->ingresos, $this->deducciones), fn ($l) => $l['tipo'] === $tipo));
    }

    public function testFeriadoTrabajadoAgregaAdicionalSencilloYCotiza(): void
    {
        $this->repo->method('periodo')->willReturn(['fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-15']);
        $this->empleado();
        $this->repo->method('asistenciasDelPeriodo')->willReturn([
            ['fecha' => '2026-09-15', 'hora_entrada' => '08:00:00', 'hora_salida' => '17:00:00'],
        ]);
        $this->deteccion->method('clasificar')->willReturn([
            'tipo_dia' => 'feriado', 'horas_dia_especial' => 8.0, 'horas_extra' => 0.0, 'factor_extra' => 3.0,
            'excede_12h' => false, 'horas_trabajadas' => 9.0, 'horas_ordinarias' => 9.0,
        ]);
        $this->service()->generarPeriodo(9, 1, 'ip');

        self::assertSame(16000.0, $this->tipo('feriado')[0]['monto']);
        self::assertSame(27724.8, $this->tipo('CCSS_empleado')[0]['monto']);   // (240 000 + 16 000) × 10.83 %
    }

    public function testHorasExtraDeOtraFechaSeMarcanComoPagoPosterior(): void
    {
        $this->repo->method('periodo')->willReturn(['fecha_inicio' => '2026-10-16', 'fecha_fin' => '2026-10-31']);
        $this->empleado();
        $this->repo->method('horasExtraDelPeriodo')->willReturn([['fecha' => '2026-10-14', 'cantidad_horas' => '2.00', 'factor_recargo' => '1.50']]);
        $this->service()->generarPeriodo(10, 1, 'ip');

        self::assertStringContainsString('(pago posterior)', $this->tipo('horas_extra')[0]['descripcion']);
    }

    public function testIncapacidadCcssGeneraRebajoYSubsidio(): void
    {
        $this->repo->method('periodo')->willReturn(['fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-15']);
        $this->empleado();
        $this->repo->method('incapacidadesDelPeriodo')->willReturn([
            ['tipo' => 'CCSS', 'fecha_inicio' => '2026-09-08', 'fecha_fin' => '2026-09-10', 'dias' => 3, 'fin_ccss_anterior' => '0000-00-00'],
        ]);
        $this->service()->generarPeriodo(9, 1, 'ip');

        self::assertSame(192000.0, $this->tipo('salario_base')[0]['monto']);
        self::assertSame(24000.0, $this->tipo('subsidio_incapacidad')[0]['monto']);
    }

    public function testReincapacidadDentroDeTreintaDiasNoTieneSubsidio(): void
    {
        $this->repo->method('periodo')->willReturn(['fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-15']);
        $this->empleado();
        $this->repo->method('incapacidadesDelPeriodo')->willReturn([
            ['tipo' => 'CCSS', 'fecha_inicio' => '2026-09-08', 'fecha_fin' => '2026-09-10', 'dias' => 3, 'fin_ccss_anterior' => '2026-08-20'],
        ]);
        $this->service()->generarPeriodo(9, 1, 'ip');

        self::assertSame([], $this->tipo('subsidio_incapacidad'));
    }

    public function testPermisoSinGoceUsaLosDiasHabilesDelEmpleado(): void
    {
        $this->repo->method('periodo')->willReturn(['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-15']);
        $this->empleado();
        $this->repo->method('permisosSinGoceDelPeriodo')->willReturn([['fecha_inicio' => '2026-10-02', 'fecha_fin' => '2026-10-02']]);
        $this->diasHabiles->expects(self::once())->method('contar')->with('2026-10-02', '2026-10-02', 5)->willReturn(1.0);
        $this->service()->generarPeriodo(12, 1, 'ip');

        self::assertSame(224000.0, $this->tipo('salario_base')[0]['monto']);
    }

    public function testSegundaQuincenaRetieneRentaConLaPrimeraDelMes(): void
    {
        $this->repo->method('periodo')->willReturn(['fecha_inicio' => '2026-09-16', 'fecha_fin' => '2026-09-30']);
        $this->empleado(1000000.0);
        $this->repo->method('lineasPrimeraQuincena')->with(5, '2026-09-01')->willReturn([['tipo' => 'salario_base', 'monto' => '500000.00']]);
        $this->service()->generarPeriodo(11, 1, 'ip');

        self::assertSame(8200.0, $this->tipo('impuesto_renta')[0]['monto']);
    }

    public function testSegundaQuincenaSinPrimeraNoFalla(): void
    {
        $this->repo->method('periodo')->willReturn(['fecha_inicio' => '2026-09-16', 'fecha_fin' => '2026-09-30']);
        $this->empleado(1000000.0);
        $this->repo->method('lineasPrimeraQuincena')->willReturn([]);
        $this->service()->generarPeriodo(11, 1, 'ip');

        self::assertSame([], $this->tipo('impuesto_renta'));   // solo 500 000 en el mes
    }

    public function testSinParametrosVigentesNoSeGeneraNinguna(): void
    {
        $parametros = $this->createMock(ParametrosLegalesService::class);
        $parametros->method('tasasCcss')->willReturn(['ccss_obrera' => 0.1083, 'ccss_patronal' => 0.2683]);
        $parametros->method('tablaRenta')->willThrowException(new RuntimeException('Falta cargar el parámetro legal renta_tramo_1 vigente al 16/01/2027.'));
        $this->parametros = $parametros;
        $this->repo->method('periodo')->willReturn(['fecha_inicio' => '2027-01-16', 'fecha_fin' => '2027-01-31']);
        $this->empleado();
        $this->repo->expects(self::never())->method('insertNominaCompleta');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('renta_tramo_1');
        $this->service()->generarPeriodo(40, 1, 'ip');
    }
}
```

Run: `vendor/bin/phpunit tests/Services/NominasServiceGeneracionTest.php` → Expected: FAIL.

- [ ] **Step 3: Repository**

En `NominasRepository`:
- `empleadosActivosSinNomina`: agregar `e.renta_hijos, e.renta_conyuge` al SELECT.
- Métodos nuevos:

```php
    /** @return array<int, array<string, mixed>> */
    public function incapacidadesDelPeriodo(int $idEmpleado, string $ini, string $fin): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT i.tipo, i.fecha_inicio, i.fecha_fin, i.dias,
                    COALESCE((SELECT MAX(a.fecha_fin) FROM incapacidades a
                               WHERE a.id_empleado = i.id_empleado AND a.tipo = 'CCSS'
                                 AND a.id_incapacidad <> i.id_incapacidad AND a.fecha_fin < i.fecha_inicio), '0000-00-00') AS fin_ccss_anterior
               FROM incapacidades i
              WHERE i.id_empleado = :e AND i.fecha_inicio <= :fin AND i.fecha_fin >= :ini
              ORDER BY i.fecha_inicio"
        );
        $stmt->execute([':e' => $idEmpleado, ':ini' => $ini, ':fin' => $fin]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function permisosSinGoceDelPeriodo(int $idEmpleado, string $ini, string $fin): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fecha_inicio, fecha_fin FROM permisos
              WHERE id_empleado = :e AND con_goce_salarial = 0 AND fecha_inicio <= :fin AND fecha_fin >= :ini
              ORDER BY fecha_inicio'
        );
        $stmt->execute([':e' => $idEmpleado, ':ini' => $ini, ':fin' => $fin]);
        return $stmt->fetchAll();
    }

    public function diasVacacionesDelPeriodo(int $idEmpleado, int $idPeriodo): float
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(dias_tomados), 0) FROM vacaciones WHERE id_empleado = :e AND id_periodo = :p');
        $stmt->execute([':e' => $idEmpleado, ':p' => $idPeriodo]);
        return (float)$stmt->fetchColumn();
    }

    public function brutoEntre(int $idEmpleado, string $desde, string $hasta): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(n.salario_bruto), 0) FROM nominas n
               JOIN periodos_pago p ON p.id_periodo = n.id_periodo
              WHERE n.id_empleado = :e AND p.fecha_fin BETWEEN :d AND :h'
        );
        $stmt->execute([':e' => $idEmpleado, ':d' => $desde, ':h' => $hasta]);
        return (float)$stmt->fetchColumn();
    }

    /** @return array<int, array<string, mixed>> */
    public function lineasPrimeraQuincena(int $idEmpleado, string $inicioMes): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.tipo, i.monto FROM ingresos_nomina i
               JOIN nominas n ON n.id_nomina = i.id_nomina
               JOIN periodos_pago p ON p.id_periodo = n.id_periodo
              WHERE n.id_empleado = :e AND p.fecha_inicio = :m'
        );
        $stmt->execute([':e' => $idEmpleado, ':m' => $inicioMes]);
        return $stmt->fetchAll();
    }

    /** @return array{renta_hijos: int, renta_conyuge: int} */
    public function datosRentaEmpleado(int $idEmpleado): array
    {
        $stmt = $this->pdo->prepare('SELECT renta_hijos, renta_conyuge FROM empleados WHERE id_empleado = :e LIMIT 1');
        $stmt->execute([':e' => $idEmpleado]);
        $r = $stmt->fetch();
        return ['renta_hijos' => (int)($r['renta_hijos'] ?? 0), 'renta_conyuge' => (int)($r['renta_conyuge'] ?? 0)];
    }
```

- [ ] **Step 4: Service**

`NominasService`:
- Constructor: agregar al final `private readonly ?ParametrosLegalesService $parametros = null, private readonly ?DiasHabilesService $diasHabiles = null, private readonly ?CalculadoraNominaPeriodo $calculadora = null` (y los `use`).
- `generarPeriodo` (reemplazo):

```php
    public function generarPeriodo(int $idPeriodo, int $loggedInId, string $ip): int
    {
        if (!$this->repo->existePeriodo($idPeriodo)) {
            throw new InvalidArgumentException('El periodo seleccionado no existe.');
        }
        $periodo = $this->repo->periodo($idPeriodo);
        if ($periodo === null) {
            throw new InvalidArgumentException('El periodo seleccionado no existe.');
        }
        // Parámetros antes de crear nada: si falta uno, no se genera ninguna nómina.
        $tasas      = $this->tasas((string)$periodo['fecha_inicio']);
        $tablaRenta = $this->esSegundaQuincena($periodo) && $this->parametros !== null
            ? $this->parametros->tablaRenta((string)$periodo['fecha_inicio'])
            : null;

        $generadas = 0;
        foreach ($this->repo->empleadosActivosSinNomina($idPeriodo) as $emp) {
            $idNomina = $this->generarUno($emp, $idPeriodo, $periodo, $tasas, $tablaRenta);
            $this->auditoriaRepo->insert('INSERT', $loggedInId, 'nominas', $idNomina, $ip);
            $generadas++;
        }
        return $generadas;
    }
```

- `generarUno` (reemplazo):

```php
    /**
     * @param array<string, mixed> $emp     fila de empleadosActivosSinNomina
     * @param array<string, mixed> $periodo fecha_inicio, fecha_fin
     */
    private function generarUno(array $emp, int $idPeriodo, array $periodo, array $tasas, ?array $tablaRenta): int
    {
        $idEmpleado = (int)$emp['id_empleado'];
        $salario    = (float)$emp['salario_mensual'];

        $adicionales = [];
        foreach ($this->repo->horasExtraDelPeriodo($idEmpleado, $idPeriodo) as $he) {
            $fecha     = (string)$he['fecha'];
            $posterior = $fecha < (string)$periodo['fecha_inicio'] || $fecha > (string)$periodo['fecha_fin'];
            $adicionales[] = $this->lineaHorasExtra($fecha, (float)$he['cantidad_horas'], (float)$he['factor_recargo'], $salario, $posterior);
        }
        foreach ($this->adicionalesDiaEspecial($idEmpleado, $idPeriodo, $salario) as $linea) {
            $adicionales[] = $linea;
        }

        $r = $this->calculadora()->calcular(
            $this->datosCalculo($idEmpleado, $idPeriodo, $salario, $periodo, $tasas, $tablaRenta, $emp) + ['lineas_adicionales' => $adicionales]
        );

        return $this->repo->insertNominaCompleta(
            [
                'id_empleado'       => $idEmpleado,
                'id_periodo'        => $idPeriodo,
                'salario_base'      => round($salario / 2.0, 2),
                'total_ingresos'    => $r['totales']['total_ingresos'],
                'total_deducciones' => $r['totales']['total_deducciones'],
                'salario_bruto'     => $r['totales']['salario_bruto'],
                'salario_neto'      => $r['totales']['salario_neto'],
                'estado'            => 'borrador',
            ],
            $r['ingresos'],
            $r['deducciones']
        );
    }

    /** Entrada de CalculadoraNominaPeriodo sin las líneas adicionales. @return array<string, mixed> */
    private function datosCalculo(int $idEmpleado, int $idPeriodo, float $salario, array $periodo, array $tasas, ?array $tablaRenta, array $emp): array
    {
        $ini = (string)$periodo['fecha_inicio'];
        $fin = (string)$periodo['fecha_fin'];

        $incapacidades = array_map(static function (array $i): array {
            $anterior = (string)$i['fin_ccss_anterior'];
            $reinc = $i['tipo'] === 'CCSS' && $anterior !== '0000-00-00'
                && (new DateTimeImmutable((string)$i['fecha_inicio']))->diff(new DateTimeImmutable($anterior))->days <= 30;
            return ['tipo' => (string)$i['tipo'], 'fecha_inicio' => (string)$i['fecha_inicio'],
                    'fecha_fin' => (string)$i['fecha_fin'], 'reincapacidad' => $reinc];
        }, $this->repo->incapacidadesDelPeriodo($idEmpleado, $ini, $fin));

        $diasPermiso = 0.0;
        foreach ($this->repo->permisosSinGoceDelPeriodo($idEmpleado, $ini, $fin) as $p) {
            $desde = max((string)$p['fecha_inicio'], $ini);
            $hasta = min((string)$p['fecha_fin'], $fin);
            $diasPermiso += $this->diasHabiles !== null
                ? $this->diasHabiles->contar($desde, $hasta, $idEmpleado)
                : (float)((new DateTimeImmutable($hasta))->diff(new DateTimeImmutable($desde))->days + 1);
        }

        $inicio  = new DateTimeImmutable($ini);
        $bruto50 = $this->repo->brutoEntre($idEmpleado, $inicio->modify('-350 days')->format('Y-m-d'), $inicio->modify('-1 day')->format('Y-m-d'));
        $promedio = $bruto50 > 0 ? $bruto50 / 350 : $salario / 30;

        $renta = null;
        if ($tablaRenta !== null) {
            $gravable1 = 0.0;
            foreach ($this->repo->lineasPrimeraQuincena($idEmpleado, $inicio->format('Y-m-01')) as $l) {
                if (CalculadoraNominaPeriodo::esGravable((string)$l['tipo'])) {
                    $gravable1 += (float)$l['monto'];
                }
            }
            $renta = [
                'tabla'                     => $tablaRenta,
                'hijos'                     => (int)($emp['renta_hijos'] ?? 0),
                'conyuge'                   => (int)($emp['renta_conyuge'] ?? 0) === 1,
                'gravable_primera_quincena' => round($gravable1, 2),
            ];
        }

        return [
            'salario_mensual'            => $salario,
            'fecha_inicio'               => $ini,
            'fecha_fin'                  => $fin,
            'incapacidades'              => $incapacidades,
            'dias_permiso_sin_goce'      => $diasPermiso,
            'dias_vacaciones'            => $this->repo->diasVacacionesDelPeriodo($idEmpleado, $idPeriodo),
            'promedio_diario_50_semanas' => $promedio,
            'tasas'                      => $tasas,
            'renta'                      => $renta,
        ];
    }

    /** 2.ª quincena = período que empieza el día 16. @param array<string, mixed> $periodo */
    private function esSegundaQuincena(array $periodo): bool
    {
        return (int)substr((string)$periodo['fecha_inicio'], 8, 2) === 16;
    }

    /** @return array{ccss_obrera: float, ccss_patronal: float} */
    private function tasas(string $fecha): array
    {
        return $this->parametros !== null
            ? $this->parametros->tasasCcss($fecha)
            : ['ccss_obrera' => null, 'ccss_patronal' => null];   // sin servicio: la calculadora usa sus tasas por defecto
    }

    private function calculadora(): CalculadoraNominaPeriodo
    {
        return $this->calculadora ?? new CalculadoraNominaPeriodo($this->calc);
    }
```

(Para que `['ccss_obrera' => null]` funcione, `NominaCalculadora::deduccionesCCSS` ya usa `??` con sus valores del constructor — Task 1.)

`config/dependencies.php`: `NominasService` recibe además `ParametrosLegalesService`, `DiasHabilesService` y `CalculadoraNominaPeriodo` (en ese orden, después de `DeteccionHorasExtraService`); `NominaCalculadora` vuelve a `new \App\Helpers\NominaCalculadora()` (quitar las tasas por defecto puestas en Task 1).

- [ ] **Step 5: Correr, aplicar migración (sección Task 3) y commit**

Run: `vendor/bin/phpunit` → OK (ajustar `NominasServiceListadoTest`/`ValoresSinNuloTest`/`NominasServiceRecalculoTest` solo si dejan de compilar; sus constructores de 3 argumentos siguen siendo válidos).

```bash
git add database/schema.sql database/migraciones/2026-10-18-nomina-masiva.sql src/Repositories/NominasRepository.php src/Services/NominasService.php config/dependencies.php tests/Services/NominasServiceGeneracionTest.php
git commit -m "feat(nominas): generación con incapacidades, permisos sin goce, vacaciones, CCSS por vigencia y renta"
```

---

### Task 4: Recálculo con cotizables y renta, y botón "Regenerar"

**Files:**
- Modify: `src/Services/NominasService.php`, `src/Controllers/NominasController.php`, `config/routes.php`, `templates/nominas/detalle.html.twig`
- Test: `tests/Services/NominasServiceRecalculoTest.php`

**Interfaces:**
- Consumes: `CalculadoraNominaPeriodo::deduccionesLegales(array $ingresos, array $tasas, ?array $renta)` (Task 2); `NominasRepository::{lineasPrimeraQuincena, datosRentaEmpleado}` (Task 3); `ParametrosLegalesService` (Task 1).
- Produces:
  - `NominasService::regenerar(int $idNomina, int $idUsuario, string $ip): int` (devuelve el id nuevo; solo borrador)
  - `NominasRepository::deleteDeduccionesLegales(int $idNomina): void` (borra `CCSS_empleado`, `CCSS_patronal`, `impuesto_renta`)
  - Ruta `POST /nominas/{id}/regenerar` → `nominas.regenerar`

- [ ] **Step 1: Pruebas que fallan**

En `tests/Services/NominasServiceRecalculoTest.php`, agregar:

```php
    public function testElSubsidioNoEntraEnLaBaseDeLaCcssAlRecalcular(): void
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('findByIdConLineas')->willReturn([
            'id_nomina' => 3, 'id_empleado' => 5, 'estado' => 'borrador', 'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-15',
            'ingresos'    => [['tipo' => 'salario_base', 'monto' => '160000.00'], ['tipo' => 'subsidio_incapacidad', 'monto' => '24000.00']],
            'deducciones' => [],
        ]);
        $insertadas = [];
        $repo->method('insertDeduccion')->willReturnCallback(function (int $id, array $d) use (&$insertadas) { $insertadas[$d['tipo']] = $d['monto']; return 1; });
        $parametros = $this->createMock(\App\Services\ParametrosLegalesService::class);
        $parametros->method('tasasCcss')->willReturn(['ccss_obrera' => 0.1083, 'ccss_patronal' => 0.2683]);
        $calc = new NominaCalculadora();
        $svc = new NominasService($repo, $this->createMock(AuditoriaRepository::class), $calc, null, $parametros, null,
            new \App\Helpers\CalculadoraNominaPeriodo($calc));

        $svc->agregarIngreso(3, ['tipo' => 'bonificacion', 'monto' => '1000'], 1, 'ip');

        self::assertSame(17328.0, $insertadas['CCSS_empleado']);   // 160 000 × 10.83 %, sin el subsidio
    }

    public function testEnSegundaQuincenaElRecalculoIncluyeLaRenta(): void
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('findByIdConLineas')->willReturn([
            'id_nomina' => 4, 'id_empleado' => 5, 'estado' => 'borrador', 'fecha_inicio' => '2026-09-16', 'fecha_fin' => '2026-09-30',
            'ingresos' => [['tipo' => 'salario_base', 'monto' => '500000.00']], 'deducciones' => [],
        ]);
        $repo->method('lineasPrimeraQuincena')->willReturn([['tipo' => 'salario_base', 'monto' => '500000.00']]);
        $repo->method('datosRentaEmpleado')->willReturn(['renta_hijos' => 0, 'renta_conyuge' => 0]);
        $insertadas = [];
        $repo->method('insertDeduccion')->willReturnCallback(function (int $id, array $d) use (&$insertadas) { $insertadas[$d['tipo']] = $d['monto']; return 1; });
        $parametros = $this->createMock(\App\Services\ParametrosLegalesService::class);
        $parametros->method('tasasCcss')->willReturn(['ccss_obrera' => 0.1083, 'ccss_patronal' => 0.2683]);
        $parametros->method('tablaRenta')->willReturn([
            'tramos' => [['desde' => 918000.0, 'tasa' => 0.10], ['desde' => 1347000.0, 'tasa' => 0.15],
                         ['desde' => 2364000.0, 'tasa' => 0.20], ['desde' => 4727000.0, 'tasa' => 0.25]],
            'credito_hijo' => 1710.0, 'credito_conyuge' => 2590.0,
        ]);
        $calc = new NominaCalculadora();
        $svc = new NominasService($repo, $this->createMock(AuditoriaRepository::class), $calc, null, $parametros, null,
            new \App\Helpers\CalculadoraNominaPeriodo($calc));

        $svc->agregarIngreso(4, ['tipo' => 'bonificacion', 'monto' => '100000'], 1, 'ip');

        // mes = 500 000 + 500 000 (findByIdConLineas devuelve la nómina antes del ingreso) → 8 200
        self::assertSame(8200.0, $insertadas['impuesto_renta']);
    }

    public function testRegenerarSoloEnBorrador(): void
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('findByIdConLineas')->willReturn(['id_nomina' => 3, 'id_empleado' => 5, 'id_periodo' => 9, 'estado' => 'aprobado', 'ingresos' => [], 'deducciones' => []]);
        $repo->expects(self::never())->method('delete');
        $svc = new NominasService($repo, $this->createMock(AuditoriaRepository::class), new NominaCalculadora());

        $this->expectException(\RuntimeException::class);
        $svc->regenerar(3, 1, 'ip');
    }
```

(Actualizar la prueba existente `testRecalcularConservaElEmbargoYCalculaElNeto` si cambia el nombre del método de borrado: ahora se llama `deleteDeduccionesLegales`.)

Run → FAIL.

- [ ] **Step 2: Implementar**

`NominasRepository`: renombrar `deleteDeduccionesCCSS` a `deleteDeduccionesLegales` e incluir `impuesto_renta`:

```php
    public function deleteDeduccionesLegales(int $idNomina): void
    {
        $this->pdo->prepare(
            "DELETE FROM deducciones_nomina WHERE id_nomina = :n AND tipo IN ('CCSS_empleado', 'CCSS_patronal', 'impuesto_renta')"
        )->execute([':n' => $idNomina]);
    }
```

(Buscar con `grep -rn deleteDeduccionesCCSS src tests` y actualizar todos los usos.)

`NominasService::recalcular` (reemplazo):

```php
    /** Recalcula CCSS (solo cotizables) y, en 2.ª quincena, la renta; conserva embargo/otro. */
    private function recalcular(int $idNomina): void
    {
        $n = $this->repo->findByIdConLineas($idNomina);
        if ($n === null) {
            return;
        }
        $ini   = (string)($n['fecha_inicio'] ?? date('Y-m-d'));
        $renta = null;
        if ($this->parametros !== null && (int)substr($ini, 8, 2) === 16) {
            $gravable1 = 0.0;
            foreach ($this->repo->lineasPrimeraQuincena((int)$n['id_empleado'], substr($ini, 0, 8) . '01') as $l) {
                if (CalculadoraNominaPeriodo::esGravable((string)$l['tipo'])) {
                    $gravable1 += (float)$l['monto'];
                }
            }
            $datos = $this->repo->datosRentaEmpleado((int)$n['id_empleado']);
            $renta = [
                'tabla'                     => $this->parametros->tablaRenta($ini),
                'hijos'                     => $datos['renta_hijos'],
                'conyuge'                   => $datos['renta_conyuge'] === 1,
                'gravable_primera_quincena' => round($gravable1, 2),
            ];
        }
        $legales = $this->calculadora()->deduccionesLegales($n['ingresos'], $this->tasas($ini), $renta);

        $this->repo->deleteDeduccionesLegales($idNomina);
        foreach ($legales as $d) {
            $this->repo->insertDeduccion($idNomina, $d);
        }
        $otras = array_filter($n['deducciones'], static fn ($d) => !in_array($d['tipo'], ['CCSS_empleado', 'CCSS_patronal', 'impuesto_renta'], true));
        $deducciones = array_merge(
            array_map(static fn ($d) => ['monto' => (float)$d['monto'], 'informativa' => false], array_values($otras)),
            $legales
        );
        $this->repo->updateTotales($idNomina, $this->calc->calcularTotales($n['ingresos'], $deducciones));
    }
```

Nota: `recalcular` vuelve a leer la nómina con `findByIdConLineas` **después** del insert de la línea nueva en `agregarIngreso`, así que en producción la línea nueva sí entra; en la prueba el mock devuelve siempre la misma fila (sin la línea nueva), por eso la renta esperada es 8 200.

`NominasService::regenerar`:

```php
    /** Vuelve a calcular una nómina en borrador desde cero (se pierden las líneas manuales). */
    public function regenerar(int $idNomina, int $idUsuario, string $ip): int
    {
        $n = $this->obtener($idNomina);
        $this->assertBorrador($n);
        $idPeriodo = (int)$n['id_periodo'];
        $periodo   = $this->repo->periodo($idPeriodo);
        if ($periodo === null) {
            throw new RuntimeException('El periodo de la nómina no existe.');
        }
        $tasas      = $this->tasas((string)$periodo['fecha_inicio']);
        $tablaRenta = $this->esSegundaQuincena($periodo) && $this->parametros !== null
            ? $this->parametros->tablaRenta((string)$periodo['fecha_inicio'])
            : null;
        $emp = ['id_empleado' => (int)$n['id_empleado'], 'salario_mensual' => $this->repo->salarioMensualEmpleado((int)$n['id_empleado']) ?? 0.0]
             + $this->repo->datosRentaEmpleado((int)$n['id_empleado']);

        $this->repo->delete($idNomina);
        $nuevo = $this->generarUno($emp, $idPeriodo, $periodo, $tasas, $tablaRenta);
        $this->auditoriaRepo->insert('DELETE', $idUsuario, 'nominas', $idNomina, $ip);
        $this->auditoriaRepo->insert('INSERT', $idUsuario, 'nominas', $nuevo, $ip);
        return $nuevo;
    }
```

`NominasController::regenerar`:

```php
    public function regenerar(Request $request, Response $response, array $args): Response
    {
        try {
            $nuevo = $this->service->regenerar((int)$args['id'], $this->usuarioId($request), $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            $_SESSION['flash_success'] = 'Nómina recalculada desde cero.';
            return $this->redirect($request, $response, 'nominas.show', ['id' => $nuevo]);
        } catch (RuntimeException | InvalidArgumentException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $this->redirect($request, $response, 'nominas.show', ['id' => (int)$args['id']]);
        }
    }
```

(Verificar la firma real de `redirect()` en `NominasController`; si no acepta argumentos de ruta, construir la URL con `RouteContext::fromRequest($request)->getRouteParser()->urlFor('nominas.show', ['id' => ...])`.)

Ruta (grupo `/nominas`, antes de `/{id}/eliminar`):

```php
        $group->post('/{id}/regenerar', [\App\Controllers\NominasController::class, 'regenerar'])->setName('nominas.regenerar');
```

`templates/nominas/detalle.html.twig`: si `nomina.estado == 'borrador'`, agregar a `acciones_nomina` `{texto: 'Regenerar', icono: 'arrow-repeat', tipo: 'secundario', atributos: {'data-bs-toggle': 'modal', 'data-bs-target': '#modalRegenerar'}}` y un modal:

```twig
<div class="modal fade" id="modalRegenerar" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Regenerar nómina</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <p>Se vuelve a calcular con las incapacidades, permisos, vacaciones, horas extra y asistencia actuales.</p>
      <p class="text-danger small mb-0">Se pierden las líneas agregadas a mano (bonos, embargos).</p>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
      <form method="POST" action="{{ url_for('nominas.regenerar', {id: nomina.id_nomina}) }}"><button class="btn btn-primary">Regenerar</button></form>
    </div>
  </div></div>
</div>
```

- [ ] **Step 3: Suite y commit**

```bash
git add src/Services/NominasService.php src/Repositories/NominasRepository.php src/Controllers/NominasController.php config/routes.php templates/nominas/detalle.html.twig tests
git commit -m "feat(nominas): recálculo con CCSS sobre cotizables y renta, y botón Regenerar"
```

---

### Task 5: Hijos y cónyuge en la ficha del empleado

**Files:**
- Modify: `src/Services/EmpleadosService.php`, `src/Repositories/EmpleadosRepository.php`, `templates/empleados/form.html.twig`
- Test: `tests/Services/EmpleadosServicePersonaMigrationTest.php`

**Interfaces:**
- Consumes: columnas `empleados.renta_hijos`, `renta_conyuge` (Task 3).
- Produces: el grupo `empleado` de `EmpleadosService::validar` incluye `renta_hijos` (int 0–20) y `renta_conyuge` (0|1); `EmpleadosRepository` los inserta, actualiza y devuelve en `findById`.

- [ ] **Step 1: Pruebas que fallan**

En `tests/Services/EmpleadosServicePersonaMigrationTest.php` (usar el helper `datosValidos()` existente):

```php
    public function testGuardaLosDatosDeRenta(): void
    {
        $repo = $this->createMock(EmpleadosRepository::class);
        $repo->method('existsByCedula')->willReturn(false);
        $repo->expects(self::once())->method('insert')
            ->with(self::anything(), self::callback(fn (array $e) => $e['renta_hijos'] === 2 && $e['renta_conyuge'] === 1), null)
            ->willReturn(42);
        $this->service($repo)->crear($this->datosValidos(['renta_hijos' => '2', 'renta_conyuge' => '1']), 1, '127.0.0.1');
    }

    public function testSinDatosDeRentaQuedanEnCero(): void
    {
        $repo = $this->createMock(EmpleadosRepository::class);
        $repo->method('existsByCedula')->willReturn(false);
        $repo->expects(self::once())->method('insert')
            ->with(self::anything(), self::callback(fn (array $e) => $e['renta_hijos'] === 0 && $e['renta_conyuge'] === 0), null)
            ->willReturn(42);
        $this->service($repo)->crear($this->datosValidos(), 1, '127.0.0.1');
    }

    public function testHijosFueraDeRangoSeRechaza(): void
    {
        $repo = $this->createMock(EmpleadosRepository::class);
        $repo->method('existsByCedula')->willReturn(false);
        $repo->expects(self::never())->method('insert');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hijos');
        $this->service($repo)->crear($this->datosValidos(['renta_hijos' => '25']), 1, '127.0.0.1');
    }
```

Run → FAIL.

- [ ] **Step 2: Implementar**

`EmpleadosService::validar` (antes de armar `$empleado`, siguiendo el patrón de `$errores`):

```php
        $hijos = isset($d['renta_hijos']) && $d['renta_hijos'] !== '' ? $d['renta_hijos'] : '0';
        if (!ctype_digit((string)$hijos) || (int)$hijos > 20) {
            $errores[] = 'La cantidad de hijos para la renta debe ser un número entre 0 y 20.';
        }
        $conyuge = (($d['renta_conyuge'] ?? '0') === '1') ? 1 : 0;
```

y en `$empleado`: `'renta_hijos' => (int)$hijos, 'renta_conyuge' => $conyuge,`.

`EmpleadosRepository`: agregar `e.renta_hijos, e.renta_conyuge` al SELECT de `findById` (y de `findAll` si la vista lo usa), y las columnas/params en `insert` y `update` (`:renta_hijos`, `:renta_conyuge` en el mapeo de parámetros que ya arma con `$e[...]`).

`templates/empleados/form.html.twig`, nueva sección después de los datos laborales:

```twig
            {% embed 'partials/seccion.html.twig' with {titulo: 'Datos para el impuesto sobre la renta'} %}
            {% block cuerpo %}
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="renta_hijos" class="form-label">Hijos</label>
                        <input type="number" min="0" max="20" class="form-control" id="renta_hijos" name="renta_hijos" value="{{ empleado.renta_hijos ?? 0 }}">
                        <div class="form-text">Menores, con discapacidad o estudiantes hasta 25 años (Art. 34, Ley 7092).</div>
                    </div>
                    <div class="col-md-8 d-flex align-items-center">
                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" id="renta_conyuge" name="renta_conyuge" value="1" {{ (empleado.renta_conyuge ?? 0) == 1 ? 'checked' : '' }}>
                            <label class="form-check-label" for="renta_conyuge">Aplica crédito por cónyuge (solo uno de los dos si ambos trabajan)</label>
                        </div>
                    </div>
                </div>
            {% endblock %}
            {% endembed %}
```

(Ubicarla dentro del `<form>` respetando cómo están embebidas las otras secciones del formulario.)

- [ ] **Step 3: Suite y commit**

```bash
git add src/Services/EmpleadosService.php src/Repositories/EmpleadosRepository.php templates/empleados/form.html.twig tests/Services/EmpleadosServicePersonaMigrationTest.php
git commit -m "feat(empleados): hijos y cónyuge para el crédito de renta"
```

---

### Task 6: "Ausencias del período" en el detalle de la nómina

**Files:**
- Modify: `src/Services/NominasService.php` (`obtener`), `templates/nominas/detalle.html.twig`
- Test: `tests/Services/NominasServiceAusenciasTest.php`

**Interfaces:**
- Consumes: `NominasRepository::{incapacidadesDelPeriodo, permisosSinGoceDelPeriodo, diasVacacionesDelPeriodo}` (Task 3); `CalculadoraNominaPeriodo::{diasIncapacidadEnPeriodo, diasSubsidioEnPeriodo}` (Task 2).
- Produces: `NominasService::obtener()` agrega `ausencias` = `['incapacidades' => list<array{tipo, fecha_inicio, fecha_fin, dias_en_periodo, quien_paga}>, 'permisos_sin_goce' => list<array{fecha_inicio, fecha_fin}>, 'dias_vacaciones' => float]`. `quien_paga`: `'Patrono 50 % (días 1–3) y CCSS'`, `'CCSS desde el día 1 (reincapacidad)'`, `'INS'` o `'Sin pago (médico particular)'`.

- [ ] **Step 1: Prueba que falla**

```php
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
```

Run → FAIL.

- [ ] **Step 2: Implementar**

`NominasService::obtener`:

```php
    /** @return array<string, mixed> */
    public function obtener(int $id): array
    {
        $n = $this->repo->findByIdConLineas($id);
        if ($n === null) {
            throw new RuntimeException('Nómina no encontrada.');
        }
        if (isset($n['fecha_inicio'], $n['fecha_fin'])) {
            $n['ausencias'] = $this->ausencias((int)$n['id_empleado'], (int)($n['id_periodo'] ?? 0), (string)$n['fecha_inicio'], (string)$n['fecha_fin']);
        }
        return $n;
    }

    /** @return array<string, mixed> */
    private function ausencias(int $idEmpleado, int $idPeriodo, string $ini, string $fin): array
    {
        $calc = $this->calculadora();
        $incapacidades = [];
        foreach ($this->repo->incapacidadesDelPeriodo($idEmpleado, $ini, $fin) as $i) {
            $anterior = (string)$i['fin_ccss_anterior'];
            $reinc = $i['tipo'] === 'CCSS' && $anterior !== '0000-00-00'
                && (new DateTimeImmutable((string)$i['fecha_inicio']))->diff(new DateTimeImmutable($anterior))->days <= 30;
            $quien = match (true) {
                $i['tipo'] === 'INS'        => 'INS',
                $i['tipo'] === 'particular' => 'Sin pago (médico particular)',
                $reinc                      => 'CCSS desde el día 1 (reincapacidad)',
                default                     => 'Patrono 50 % (días 1–3) y CCSS',
            };
            $incapacidades[] = [
                'tipo'            => (string)$i['tipo'],
                'fecha_inicio'    => (string)$i['fecha_inicio'],
                'fecha_fin'       => (string)$i['fecha_fin'],
                'dias_en_periodo' => $calc->diasIncapacidadEnPeriodo($i, $ini, $fin),
                'quien_paga'      => $quien,
            ];
        }
        return [
            'incapacidades'     => $incapacidades,
            'permisos_sin_goce' => $this->repo->permisosSinGoceDelPeriodo($idEmpleado, $ini, $fin),
            'dias_vacaciones'   => $this->repo->diasVacacionesDelPeriodo($idEmpleado, $idPeriodo),
        ];
    }
```

La lógica de reincapacidad queda duplicada con `datosCalculo`: extraerla a un método privado `esReincapacidad(array $i): bool` usado por los dos.

`templates/nominas/detalle.html.twig`, debajo de las tablas de ingresos/deducciones:

```twig
{% set aus = nomina.ausencias ?? null %}
{% if aus and (aus.incapacidades is not empty or aus.permisos_sin_goce is not empty or aus.dias_vacaciones > 0) %}
{% embed 'partials/seccion.html.twig' with {titulo: 'Ausencias del período'} %}
{% block cuerpo %}
    <ul class="mb-0">
    {% for i in aus.incapacidades %}
        <li>Incapacidad {{ i.tipo }} del {{ i.fecha_inicio|date('d/m/Y') }} al {{ i.fecha_fin|date('d/m/Y') }} — {{ i.dias_en_periodo }} día(s) en este período · paga: {{ i.quien_paga }}</li>
    {% endfor %}
    {% for p in aus.permisos_sin_goce %}
        <li>Permiso sin goce del {{ p.fecha_inicio|date('d/m/Y') }} al {{ p.fecha_fin|date('d/m/Y') }}</li>
    {% endfor %}
    {% if aus.dias_vacaciones > 0 %}<li>Vacaciones: {{ aus.dias_vacaciones }} día(s)</li>{% endif %}
    </ul>
{% endblock %}
{% endembed %}
{% endif %}
```

- [ ] **Step 3: Suite y commit**

```bash
git add src/Services/NominasService.php templates/nominas/detalle.html.twig tests/Services/NominasServiceAusenciasTest.php
git commit -m "feat(nominas): ausencias del período en el detalle"
```

---

### Task 7: "Aprobar todas" y "Marcar todas como pagadas"

**Files:**
- Modify: `src/Repositories/NominasRepository.php`, `src/Services/NominasService.php`, `src/Controllers/NominasController.php`, `config/routes.php`, `templates/nominas/index.html.twig`
- Test: `tests/Services/NominasServiceMasivoTest.php`

**Interfaces:**
- Produces:
  - `NominasRepository::idsPorPeriodoYEstado(int $idPeriodo, string $estado): array` (list<int>)
  - `NominasRepository::cambiarEstadoPorPeriodo(int $idPeriodo, string $desde, string $hasta): int` (un solo UPDATE; devuelve filas cambiadas)
  - `NominasService::aprobarPeriodo(int $idPeriodo, int $idUsuario, string $ip): int`, `pagarPeriodo(...)`: int
  - Rutas `POST /nominas/periodo/{id}/aprobar` (`nominas.aprobarPeriodo`) y `POST /nominas/periodo/{id}/pagar` (`nominas.pagarPeriodo`)

- [ ] **Step 1: Prueba que falla**

```php
<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\NominaCalculadora;
use App\Repositories\AuditoriaRepository;
use App\Repositories\NominasRepository;
use App\Services\NominasService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NominasServiceMasivoTest extends TestCase
{
    public function testAprobarTodasSoloCambiaLasDeBorradorYAuditaCadaUna(): void
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('idsPorPeriodoYEstado')->with(12, 'borrador')->willReturn([3, 4]);
        $repo->expects(self::once())->method('cambiarEstadoPorPeriodo')->with(12, 'borrador', 'aprobado')->willReturn(2);
        $audit = $this->createMock(AuditoriaRepository::class);
        $audit->expects(self::exactly(2))->method('insert');

        self::assertSame(2, (new NominasService($repo, $audit, new NominaCalculadora()))->aprobarPeriodo(12, 1, 'ip'));
    }

    public function testPagarTodasSoloCambiaLasAprobadas(): void
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('idsPorPeriodoYEstado')->with(12, 'aprobado')->willReturn([3]);
        $repo->expects(self::once())->method('cambiarEstadoPorPeriodo')->with(12, 'aprobado', 'pagado')->willReturn(1);

        self::assertSame(1, (new NominasService($repo, $this->createMock(AuditoriaRepository::class), new NominaCalculadora()))->pagarPeriodo(12, 1, 'ip'));
    }

    public function testSinNominasEnEseEstadoAvisa(): void
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('idsPorPeriodoYEstado')->willReturn([]);
        $repo->expects(self::never())->method('cambiarEstadoPorPeriodo');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No hay nóminas en borrador');
        (new NominasService($repo, $this->createMock(AuditoriaRepository::class), new NominaCalculadora()))->aprobarPeriodo(12, 1, 'ip');
    }
}
```

Run → FAIL.

- [ ] **Step 2: Implementar**

`NominasRepository`:

```php
    /** @return list<int> */
    public function idsPorPeriodoYEstado(int $idPeriodo, string $estado): array
    {
        $stmt = $this->pdo->prepare('SELECT id_nomina FROM nominas WHERE id_periodo = :p AND estado = :s ORDER BY id_nomina');
        $stmt->execute([':p' => $idPeriodo, ':s' => $estado]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Un solo UPDATE: todas las nóminas del período en `$desde` pasan a `$hasta` (atómico). */
    public function cambiarEstadoPorPeriodo(int $idPeriodo, string $desde, string $hasta): int
    {
        $stmt = $this->pdo->prepare('UPDATE nominas SET estado = :h WHERE id_periodo = :p AND estado = :d');
        $stmt->execute([':h' => $hasta, ':p' => $idPeriodo, ':d' => $desde]);
        return $stmt->rowCount();
    }
```

`NominasService`:

```php
    public function aprobarPeriodo(int $idPeriodo, int $idUsuario, string $ip): int
    {
        return $this->cambiarEstadoPeriodo($idPeriodo, 'borrador', 'aprobado', $idUsuario, $ip);
    }

    public function pagarPeriodo(int $idPeriodo, int $idUsuario, string $ip): int
    {
        return $this->cambiarEstadoPeriodo($idPeriodo, 'aprobado', 'pagado', $idUsuario, $ip);
    }

    private function cambiarEstadoPeriodo(int $idPeriodo, string $desde, string $hasta, int $idUsuario, string $ip): int
    {
        $ids = $this->repo->idsPorPeriodoYEstado($idPeriodo, $desde);
        if ($ids === []) {
            throw new RuntimeException(sprintf('No hay nóminas en %s en este período.', $desde === 'borrador' ? 'borrador' : 'estado aprobado'));
        }
        $cambiadas = $this->repo->cambiarEstadoPorPeriodo($idPeriodo, $desde, $hasta);
        foreach ($ids as $id) {
            $this->auditoriaRepo->insert('UPDATE', $idUsuario, 'nominas', $id, $ip);
        }
        return $cambiadas;
    }
```

`NominasController`:

```php
    public function aprobarPeriodo(Request $request, Response $response, array $args): Response
    {
        return $this->cambiarPeriodo($request, $response, (int)$args['id'], 'aprobar');
    }

    public function pagarPeriodo(Request $request, Response $response, array $args): Response
    {
        return $this->cambiarPeriodo($request, $response, (int)$args['id'], 'pagar');
    }

    private function cambiarPeriodo(Request $request, Response $response, int $idPeriodo, string $accion): Response
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $n = $accion === 'aprobar'
                ? $this->service->aprobarPeriodo($idPeriodo, $this->usuarioId($request), $ip)
                : $this->service->pagarPeriodo($idPeriodo, $this->usuarioId($request), $ip);
            $_SESSION['flash_success'] = sprintf($accion === 'aprobar' ? 'Se aprobaron %d nóminas.' : 'Se marcaron %d nóminas como pagadas.', $n);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        $url = $this->urlFor($request, 'nominas.index') . '?periodo=' . $idPeriodo;
        return $response->withHeader('Location', $url)->withStatus(302);
    }
```

Rutas (grupo `/nominas`, **antes** de `/{id}`):

```php
        $group->post('/periodo/{id}/aprobar', [\App\Controllers\NominasController::class, 'aprobarPeriodo'])->setName('nominas.aprobarPeriodo');
        $group->post('/periodo/{id}/pagar',   [\App\Controllers\NominasController::class, 'pagarPeriodo'])->setName('nominas.pagarPeriodo');
```

`templates/nominas/index.html.twig`: calcular en Twig, si hay `periodo`, cuántas nóminas listadas están en borrador / aprobado y su neto (`nominas|filter(n => n.estado == 'borrador')`). Agregar a las `acciones` del encabezado (junto a "Generar nóminas del periodo"):
- `{texto: 'Aprobar todas', icono: 'check2-all', atributos: {'data-bs-toggle': 'modal', 'data-bs-target': '#modalAprobarTodas'}}` si hay borradores;
- `{texto: 'Marcar todas como pagadas', icono: 'cash-stack', atributos: {'data-bs-toggle': 'modal', 'data-bs-target': '#modalPagarTodas'}}` si hay aprobadas.

Y dos modales con el texto `Se aprobarán N nóminas del dd/mm/aaaa al dd/mm/aaaa · total neto ₡X` (y el equivalente para pagar), cada uno con un `<form method="POST" action="{{ url_for('nominas.aprobarPeriodo', {id: periodo.id_periodo}) }}">`. Si hay un filtro de estado activo, los conteos deben salir de todas las nóminas del período: pasar desde `vistaListado` un `conteoPeriodo` = `['borrador' => [n, neto], 'aprobado' => [n, neto]]` calculado con `$this->repo->findAll($idPeriodo, null)` cuando hay período (agregarlo al arreglo de `vistaListado` y a su prueba en `NominasServiceListadoTest` si se requiere).

- [ ] **Step 3: Suite y commit**

```bash
git add src/Repositories/NominasRepository.php src/Services/NominasService.php src/Controllers/NominasController.php config/routes.php templates/nominas/index.html.twig tests/Services/NominasServiceMasivoTest.php tests/Services/NominasServiceListadoTest.php
git commit -m "feat(nominas): aprobar y pagar todas las nóminas del período"
```

---

### Task 8: Reporte exportable del período (Excel y PDF)

**Files:**
- Modify: `src/Repositories/NominasRepository.php`, `src/Services/NominasService.php`, `src/Controllers/NominasController.php`, `config/routes.php`, `config/dependencies.php`, `templates/nominas/index.html.twig`
- Create: `templates/nominas/pdf/periodo.html.twig`
- Test: `tests/Services/NominasServiceReporteTest.php`

**Interfaces:**
- Consumes: `ExcelExportHelper::{build(string $titulo, array $encabezados, array $filas): Spreadsheet, toBinaryString(Spreadsheet): string}`, `PdfExportHelper::fromHtml(string): string` (existentes).
- Produces:
  - `NominasRepository::reportePeriodo(int $idPeriodo): array` (una fila por nómina con columnas agregadas por tipo)
  - `NominasService::reportePeriodo(int $idPeriodo): array{periodo: array, filas: list<array>, totales: array, incluye_borrador: bool}`
  - Ruta `GET /nominas/exportar/{formato}` (`nominas.exportar`), `formato` ∈ `xlsx|pdf`, query `periodo`

- [ ] **Step 1: Prueba que falla**

```php
<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\NominaCalculadora;
use App\Repositories\AuditoriaRepository;
use App\Repositories\NominasRepository;
use App\Services\NominasService;
use PHPUnit\Framework\TestCase;

final class NominasServiceReporteTest extends TestCase
{
    public function testReporteTotalizaYMarcaBorradores(): void
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('periodo')->willReturn(['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-15']);
        $fila = fn (string $estado, float $bruto, float $obrera, float $renta, float $neto, float $patronal) => [
            'nombre' => 'A', 'apellidos' => 'B', 'cedula' => '1', 'estado' => $estado,
            'salario_base' => $bruto, 'horas_extra' => 0.0, 'otros_ingresos' => 0.0, 'subsidio' => 0.0,
            'salario_bruto' => $bruto, 'ccss_obrera' => $obrera, 'renta' => $renta, 'otras_deducciones' => 0.0,
            'salario_neto' => $neto, 'ccss_patronal' => $patronal,
        ];
        $repo->method('reportePeriodo')->willReturn([
            $fila('pagado', 240000.0, 25992.0, 0.0, 214008.0, 64392.0),
            $fila('borrador', 500000.0, 54150.0, 8200.0, 437650.0, 134150.0),
        ]);

        $r = (new NominasService($repo, $this->createMock(AuditoriaRepository::class), new NominaCalculadora()))->reportePeriodo(12);

        self::assertTrue($r['incluye_borrador']);
        self::assertSame(651658.0, $r['totales']['salario_neto']);
        self::assertSame(80142.0, $r['totales']['ccss_obrera']);
        self::assertSame(8200.0, $r['totales']['renta']);
        self::assertSame(80142.0 + 198542.0, $r['totales']['cargas_ccss']);
    }
}
```

Run → FAIL.

- [ ] **Step 2: Implementar**

`NominasRepository::reportePeriodo`:

```php
    /** @return array<int, array<string, mixed>> */
    public function reportePeriodo(int $idPeriodo): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT per.nombre, per.apellidos, per.cedula, n.estado, n.salario_bruto, n.salario_neto,
                    COALESCE((SELECT SUM(monto) FROM ingresos_nomina i WHERE i.id_nomina = n.id_nomina AND i.tipo = 'salario_base'), 0) AS salario_base,
                    COALESCE((SELECT SUM(monto) FROM ingresos_nomina i WHERE i.id_nomina = n.id_nomina AND i.tipo = 'horas_extra'), 0) AS horas_extra,
                    COALESCE((SELECT SUM(monto) FROM ingresos_nomina i WHERE i.id_nomina = n.id_nomina AND i.tipo = 'subsidio_incapacidad'), 0) AS subsidio,
                    COALESCE((SELECT SUM(monto) FROM ingresos_nomina i WHERE i.id_nomina = n.id_nomina AND i.tipo NOT IN ('salario_base','horas_extra','subsidio_incapacidad')), 0) AS otros_ingresos,
                    COALESCE((SELECT SUM(monto) FROM deducciones_nomina d WHERE d.id_nomina = n.id_nomina AND d.tipo = 'CCSS_empleado'), 0) AS ccss_obrera,
                    COALESCE((SELECT SUM(monto) FROM deducciones_nomina d WHERE d.id_nomina = n.id_nomina AND d.tipo = 'impuesto_renta'), 0) AS renta,
                    COALESCE((SELECT SUM(monto) FROM deducciones_nomina d WHERE d.id_nomina = n.id_nomina AND d.tipo IN ('embargo','otro')), 0) AS otras_deducciones,
                    COALESCE((SELECT SUM(monto) FROM deducciones_nomina d WHERE d.id_nomina = n.id_nomina AND d.tipo = 'CCSS_patronal'), 0) AS ccss_patronal
               FROM nominas n
               JOIN empleados e ON e.id_empleado = n.id_empleado
               JOIN persona per ON per.id_persona = e.id_persona
              WHERE n.id_periodo = :p
              ORDER BY per.apellidos, per.nombre"
        );
        $stmt->execute([':p' => $idPeriodo]);
        return $stmt->fetchAll();
    }
```

`NominasService::reportePeriodo`:

```php
    private const COLUMNAS_REPORTE = ['salario_base', 'horas_extra', 'otros_ingresos', 'subsidio', 'salario_bruto',
                                      'ccss_obrera', 'renta', 'otras_deducciones', 'salario_neto', 'ccss_patronal'];

    /** @return array{periodo: array<string, mixed>, filas: list<array<string, mixed>>, totales: array<string, float>, incluye_borrador: bool} */
    public function reportePeriodo(int $idPeriodo): array
    {
        $periodo = $this->repo->periodo($idPeriodo);
        if ($periodo === null) {
            throw new RuntimeException('El periodo seleccionado no existe.');
        }
        $filas   = $this->repo->reportePeriodo($idPeriodo);
        $totales = array_fill_keys(self::COLUMNAS_REPORTE, 0.0);
        foreach ($filas as $f) {
            foreach (self::COLUMNAS_REPORTE as $c) {
                $totales[$c] += (float)$f[$c];
            }
        }
        $totales = array_map(static fn ($v) => round($v, 2), $totales);
        $totales['cargas_ccss'] = round($totales['ccss_obrera'] + $totales['ccss_patronal'], 2);

        return [
            'periodo'          => $periodo,
            'filas'            => $filas,
            'totales'          => $totales,
            'incluye_borrador' => in_array('borrador', array_column($filas, 'estado'), true),
        ];
    }
```

`NominasController`: constructor agrega `private readonly ExcelExportHelper $excel, private readonly PdfExportHelper $pdf` (y `dependencies.php` se los pasa, igual que a `ReportesController`). Método:

```php
    public function exportar(Request $request, Response $response, array $args): Response
    {
        $formato   = (string)($args['formato'] ?? '');
        $idPeriodo = (int)($request->getQueryParams()['periodo'] ?? 0);
        if (!in_array($formato, ['xlsx', 'pdf'], true) || $idPeriodo <= 0) {
            return $response->withStatus(404);
        }
        try {
            $r = $this->service->reportePeriodo($idPeriodo);
        } catch (RuntimeException) {
            return $response->withStatus(404);
        }
        $titulo = sprintf('Planilla del %s al %s', (new \DateTimeImmutable((string)$r['periodo']['fecha_inicio']))->format('d/m/Y'),
            (new \DateTimeImmutable((string)$r['periodo']['fecha_fin']))->format('d/m/Y'));
        $archivo = 'planilla_' . $r['periodo']['fecha_inicio'] . '.' . $formato;

        if ($formato === 'xlsx') {
            $enc = ['Empleado', 'Cédula', 'Salario base', 'Horas extra', 'Otros ingresos', 'Subsidio incap.', 'Bruto',
                    'CCSS obrera', 'Renta', 'Otras deducc.', 'Neto', 'CCSS patronal', 'Estado'];
            $filas = array_map(static fn ($f) => [
                $f['apellidos'] . ', ' . $f['nombre'], $f['cedula'], (float)$f['salario_base'], (float)$f['horas_extra'],
                (float)$f['otros_ingresos'], (float)$f['subsidio'], (float)$f['salario_bruto'], (float)$f['ccss_obrera'],
                (float)$f['renta'], (float)$f['otras_deducciones'], (float)$f['salario_neto'], (float)$f['ccss_patronal'], $f['estado'],
            ], $r['filas']);
            $t = $r['totales'];
            $filas[] = ['TOTAL', '', $t['salario_base'], $t['horas_extra'], $t['otros_ingresos'], $t['subsidio'], $t['salario_bruto'],
                        $t['ccss_obrera'], $t['renta'], $t['otras_deducciones'], $t['salario_neto'], $t['ccss_patronal'], ''];
            $filas[] = [];
            $filas[] = ['Total pagado (neto)', '', $t['salario_neto']];
            $filas[] = ['Cargas sociales a pagar a la CCSS', '', $t['cargas_ccss']];
            $filas[] = ['Renta retenida a pagar a Hacienda', '', $t['renta']];
            if ($r['incluye_borrador']) {
                $filas[] = ['Incluye nóminas en borrador'];
            }
            $bytes = $this->excel->toBinaryString($this->excel->build($titulo, $enc, $filas));
            $tipo  = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        } else {
            $bytes = $this->pdf->fromHtml($this->twig->fetch('nominas/pdf/periodo.html.twig', ['titulo' => $titulo] + $r));
            $tipo  = 'application/pdf';
        }
        $response->getBody()->write($bytes);
        return $response->withHeader('Content-Type', $tipo)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $archivo . '"')
            ->withHeader('Content-Length', (string)strlen($bytes));
    }
```

(Si `ExcelExportHelper::build` no acepta filas de distinta longitud, rellenar las filas del resumen con `''` hasta 13 columnas.)

Ruta (grupo `/nominas`, antes de `/{id}`):

```php
        $group->get('/exportar/{formato}', [\App\Controllers\NominasController::class, 'exportar'])->setName('nominas.exportar');
```

`templates/nominas/pdf/periodo.html.twig` (seguir el estilo de `templates/reportes/pdf/planilla.html.twig`: mismo `<style>`, tabla con clase `num` para montos): encabezado "Lubrimotos del Sur — {{ titulo }}", fecha de generación `{{ 'now'|date('d/m/Y H:i') }}`, aviso si `incluye_borrador`, tabla con las 13 columnas y fila TOTAL, y el resumen de tres líneas.

`templates/nominas/index.html.twig`: si hay `periodo`, botones junto a "Periodo actual":

```twig
        {% if periodo %}
        <div class="col-auto">
            <a href="{{ url_for('nominas.exportar', {formato: 'xlsx'}) }}?periodo={{ periodo.id_periodo }}" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
            <a href="{{ url_for('nominas.exportar', {formato: 'pdf'}) }}?periodo={{ periodo.id_periodo }}" class="btn btn-outline-danger"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
        </div>
        {% endif %}
```

- [ ] **Step 3: Suite, prueba manual de descarga y commit**

Run: `vendor/bin/phpunit` → OK. Manual: con sesión de admin, `curl -b cookie "http://localhost/lubrimotos/public/nominas/exportar/xlsx?periodo=<id>" -o planilla.xlsx` y lo mismo con pdf; verificar con `file` que sean XLSX/PDF válidos (en `$TEMP`, borrarlos después).

```bash
git add src/Repositories/NominasRepository.php src/Services/NominasService.php src/Controllers/NominasController.php config/routes.php config/dependencies.php templates/nominas/index.html.twig templates/nominas/pdf/periodo.html.twig tests/Services/NominasServiceReporteTest.php
git commit -m "feat(nominas): reporte del período en Excel y PDF con totales"
```

---

### Task 9: Demostración y prueba de punta a punta

**Files:**
- Modify: `database/demo.php`, `CLAUDE.md` (tabla de módulos), `docs/superpowers/specs/2026-10-18-nomina-masiva-design.md` (estado)

- [ ] **Step 1: Demo**

En `database/demo.php` (leerlo completo antes):
- Subir el salario del puesto del empleado con mayor salario a ₡1 000 000 (`UPDATE puestos SET salario_base = 1000000 WHERE id_puesto = (SELECT id_puesto FROM empleados WHERE id_empleado = ?)`) **antes** de generar las nóminas, para que tenga retención de renta en las 2.ª quincenas.
- Dar `renta_hijos = 2, renta_conyuge = 1` a otro empleado.
- La sección de nóminas ya llama a `generarPeriodo` y aprueba/paga una por una: cambiar a `aprobarPeriodo` y `pagarPeriodo` del servicio.

- [ ] **Step 2: Regenerar en una base temporal y verificar**

Crear `lubrimotos_demo_test`, cargar `schema.sql` y `seed.sql` (quitando la línea `USE ...` en copias temporales), correr `php database/demo.php lubrimotos_demo_test`, y verificar:
- la nómina de María de la 1.ª quincena de setiembre tiene `salario_base` de 12 días y una línea `subsidio_incapacidad` (incapacidad CCSS 08–10/09);
- la nómina de Luis de la 2.ª quincena de junio tiene 12 días y **sin** subsidio (INS 22–24/06);
- las nóminas de 2026 tienen `CCSS_empleado` con `porcentaje = 10.83`;
- el empleado de ₡1 000 000 tiene `impuesto_renta = 8200` en cada 2.ª quincena completa.
Borrar la base temporal. **No tocar `lubrimotos_nomina` sin permiso de Gabriel.**

- [ ] **Step 3: Documentación y commit**

`CLAUDE.md`: fila nueva en la tabla de módulos `| 16 | Nómina masiva (incapacidades, permisos, vacaciones, renta, aprobar/pagar todas, reporte) | `feature/nomina-masiva` | ✅ Completo (pendiente prueba manual) |`. Spec: estado "implementado (fecha)".

```bash
git add database/demo.php CLAUDE.md docs/superpowers/specs/2026-10-18-nomina-masiva-design.md
git commit -m "feat(datos): demo con renta retenida, incapacidades en la nómina y aprobación masiva"
```

- [ ] **Step 4 (con permiso de Gabriel): base local y E2E**

Regenerar `lubrimotos_nomina` y probar por HTTP: generar el período abierto → abrir un detalle (ver "Ausencias del período") → "Aprobar todas" → "Marcar todas como pagadas" → descargar Excel y PDF.
