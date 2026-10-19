# Liquidación según el Código de Trabajo Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que la liquidación calcule preaviso, cesantía, vacaciones y aguinaldo con las reglas del Código de Trabajo (Arts. 28, 29, 30, 83-85, 157) y la Ley 2412, y guarde la base y los días que usó.

**Architecture:** Una función pura compartida `PromedioSalarial::diario` (bruto ÷ días cubiertos) sirve a la nómina (Art. 157) y a la liquidación (Art. 30). `LiquidacionCalculadora` se reescribe como cálculo puro que recibe todos los insumos; `LiquidacionService` reúne los datos (nóminas, saldo de vacaciones, aguinaldo acumulado) desde los repositorios. El esquema agrega dos motivos y cinco columnas de trazabilidad.

**Tech Stack:** PHP 8.1+, Slim 4, Twig, PHP-DI, PDO puro, PHPUnit 11 (`vendor/bin/phpunit`), MariaDB/MySQL (XAMPP).

**Spec:** `docs/superpowers/specs/2026-10-19-liquidacion-legal-design.md`

## Global Constraints

- Arquitectura Controller → Service → Repository; los Repository solo tienen SQL; los Helpers no tocan BD ni reloj.
- Todos los archivos PHP empiezan con `declare(strict_types=1);`.
- Ninguna columna acepta NULL: columnas nuevas `NOT NULL DEFAULT 0`.
- Todo cambio de esquema se refleja en `database/schema.sql` y en una migración en `database/migraciones/`.
- No se usa ORM, npm ni frameworks JS. Los textos al usuario van en español de Costa Rica (voseo no; registro neutro como el resto de la app).
- Tabla de cesantía Art. 29: 1 → 19,5; 2 → 20; 3 → 20,5; 4 → 21; 5 → 21,24; 6 → 21,5; 7 → 22; 8 → 22; 9 → 22; 10 → 21,5; 11 → 21; 12 → 20,5; 13 o más → 20. Tope 8 años.
- Límites: exactamente 6 meses → tramo de 3 a 6 meses; exactamente 1 año → tramo de "1 año o más".
- Motivos (valor → etiqueta): `renuncia` → "Renuncia", `despido_con_causa` → "Despido con causa", `despido_sin_causa` → "Despido sin causa", `renuncia_justificada` → "Renuncia justificada (Art. 83)", `mutuo_acuerdo` → "Mutuo acuerdo", `jubilacion` → "Jubilación o pensión", `muerte` → "Muerte del trabajador".
- Preaviso: `despido_sin_causa`, `renuncia_justificada`. Cesantía: `despido_sin_causa`, `renuncia_justificada`, `muerte`, `jubilacion`.
- Commits sobre la rama `feature/liquidacion-legal`; agregar archivos por nombre, nunca `git add -A`. Cada mensaje termina con `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Operaciones destructivas sobre la BD local (DROP, regenerar, ALTER) solo con el OK explícito de Gabriel en ese momento.

## Review Focus

1. Saldo de vacaciones negativo (vacaciones aprobadas a futuro mayores que lo ganado) → se pagan 0 días, nunca un monto negativo. Prueba en Task 4.
2. Salida en diciembre después del 1 → el aguinaldo empieza ese 1 de diciembre, no el del año anterior. Prueba en Task 4.
3. Ingreso posterior al 1 de diciembre (empleado nuevo) → el aguinaldo empieza en la fecha de ingreso. Prueba en Task 4.
4. Antigüedad menor de 3 meses o salida el mismo día del ingreso → preaviso y cesantía 0, sin división entre cero. Prueba en Task 2.
5. Días comerciales con fin de febrero y día 31 → febrero completo vale 30 días y el día 31 no cuenta. Prueba en Task 2.

---

### Task 1: `PromedioSalarial` compartido y la nómina lo usa

**Files:**
- Create: `src/Helpers/PromedioSalarial.php`
- Modify: `src/Services/NominasService.php` (bloque "Art. 157" dentro de `datosCalculo`, hoy líneas ~350-363; y el bloque `use`)
- Test: `tests/Helpers/PromedioSalarialTest.php`

**Interfaces:**
- Produces: `App\Helpers\PromedioSalarial::diario(float $bruto, string $desde, string $hasta, ?string $ingreso, ?string $primerInicioNomina, ?string $ultimoFinNomina = null, int $maxDias = 350): ?float` — promedio diario o `null` si el bruto es 0 o no hay días cubiertos. Las fechas son `YYYY-MM-DD`.

- [ ] **Step 1: Write the failing test**

`tests/Helpers/PromedioSalarialTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Helpers\PromedioSalarial;
use PHPUnit\Framework\TestCase;

final class PromedioSalarialTest extends TestCase
{
    public function testVentanaCompletaDivideEntreTodosLosDias(): void
    {
        self::assertEqualsWithDelta(350.0, PromedioSalarial::diario(3500.0, '2026-01-01', '2026-01-10', null, null), 0.001);
    }

    public function testIngresoDentroDeLaVentanaAcortaLosDias(): void
    {
        // del 06 al 10 = 5 días
        self::assertEqualsWithDelta(700.0, PromedioSalarial::diario(3500.0, '2026-01-01', '2026-01-10', '2026-01-06', null), 0.001);
    }

    public function testUsaElMayorEntreIngresoYPrimeraNomina(): void
    {
        self::assertEqualsWithDelta(1750.0, PromedioSalarial::diario(3500.0, '2026-01-01', '2026-01-10', '2025-06-01', '2026-01-09'), 0.001);
    }

    public function testUltimoFinDeNominaAcortaElFinal(): void
    {
        // del 01 al 05 = 5 días
        self::assertEqualsWithDelta(700.0, PromedioSalarial::diario(3500.0, '2026-01-01', '2026-01-10', null, null, '2026-01-05'), 0.001);
    }

    public function testRespetaElTopeDeDias(): void
    {
        self::assertEqualsWithDelta(10.0, PromedioSalarial::diario(3500.0, '2025-01-01', '2026-01-10', null, null, null, 350), 0.001);
    }

    public function testSinBrutoOSinDiasDevuelveNull(): void
    {
        self::assertNull(PromedioSalarial::diario(0.0, '2026-01-01', '2026-01-10', null, null));
        self::assertNull(PromedioSalarial::diario(3500.0, '2026-01-01', '2026-01-10', '2026-02-01', null));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Helpers/PromedioSalarialTest.php`
Expected: FAIL con `Class "App\Helpers\PromedioSalarial" not found`.

- [ ] **Step 3: Write minimal implementation**

`src/Helpers/PromedioSalarial.php`:

```php
<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;

/**
 * PromedioSalarial — promedio diario de lo devengado en una ventana, dividido entre los días que de
 * verdad cubren las nóminas (Código de Trabajo, Arts. 30 y 157). Un empleado reciente no se promedia
 * contra días en los que no trabajaba. Sin BD ni reloj.
 */
final class PromedioSalarial
{
    /**
     * $bruto ÷ días entre el mayor de ($desde, $ingreso, $primerInicioNomina) y el menor de
     * ($hasta, $ultimoFinNomina), con tope $maxDias. Null si el bruto es 0 o no hay días.
     */
    public static function diario(
        float $bruto,
        string $desde,
        string $hasta,
        ?string $ingreso,
        ?string $primerInicioNomina,
        ?string $ultimoFinNomina = null,
        int $maxDias = 350
    ): ?float {
        if ($bruto <= 0) {
            return null;
        }
        $inicio = $desde;
        foreach ([$ingreso, $primerInicioNomina] as $limite) {
            if ($limite !== null && $limite > $inicio) {
                $inicio = $limite;
            }
        }
        $fin = $hasta;
        if ($ultimoFinNomina !== null && $ultimoFinNomina < $fin) {
            $fin = $ultimoFinNomina;
        }
        if ($inicio > $fin) {
            return null;
        }
        $dias = min($maxDias, (new DateTimeImmutable($inicio))->diff(new DateTimeImmutable($fin))->days + 1);
        return $bruto / $dias;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Helpers/PromedioSalarialTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Use it in `NominasService`**

En `src/Services/NominasService.php` agregar `use App\Helpers\PromedioSalarial;` junto a los otros `use App\Helpers\...`. Reemplazar el bloque que empieza en el comentario `// Art. 157: promedio de las últimas 50 semanas` y termina en la línea `$promedio = $bruto50 > 0 && $diasCubiertos > 0 ? $bruto50 / $diasCubiertos : $salario / 30;` por:

```php
        // Art. 157: promedio de las últimas 50 semanas, dividido entre los días realmente cubiertos
        // (un empleado reciente no se promedia contra 350 días en los que no trabajaba).
        $inicio   = new DateTimeImmutable($ini);
        $desde    = $inicio->modify('-350 days')->format('Y-m-d');
        $hasta    = $inicio->modify('-1 day')->format('Y-m-d');
        $promedio = PromedioSalarial::diario(
            $this->repo->brutoEntre($idEmpleado, $desde, $hasta),
            $desde,
            $hasta,
            $this->repo->fechaIngreso($idEmpleado),
            $this->repo->primerInicioNominaEntre($idEmpleado, $desde, $hasta)
        ) ?? $salario / 30;
```

`$inicio` se sigue usando más abajo (bloque de renta); no lo borres.

- [ ] **Step 6: Run the payroll tests and the full suite**

Run: `vendor/bin/phpunit tests/Services/NominasServiceGeneracionTest.php` y luego `vendor/bin/phpunit`
Expected: todo verde, incluido `testPromedioArt157DivideEntreLosDiasRealmenteCubiertos` (el resultado de la nómina no cambia).

- [ ] **Step 7: Commit**

```bash
git add src/Helpers/PromedioSalarial.php tests/Helpers/PromedioSalarialTest.php src/Services/NominasService.php
git commit -m "refactor(nominas): promedio salarial por días cubiertos en un helper compartido

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `LiquidacionCalculadora` con las reglas del Código de Trabajo

**Files:**
- Modify (reescribir completo): `src/Helpers/LiquidacionCalculadora.php`
- Test (reescribir completo): `tests/Helpers/LiquidacionCalculadoraTest.php`

**Interfaces:**
- Produces:
  - `LiquidacionCalculadora::CON_PREAVISO` y `::CON_CESANTIA` (`string[]` públicos).
  - `antiguedad(string $fechaIngreso, string $fechaSalida): array{anios:int, meses:int, dias:int, mesesTotales:int, masDeSeisMeses:bool}`
  - `diasPreaviso(array $antiguedad): float`
  - `diasCesantia(array $antiguedad): array{dias:float, anios_contados:int, tasa:float}`
  - `tasaCesantia(int $aniosCompletos): float`
  - `static diasComerciales(string $desde, string $hasta): int` (mes comercial de 30 días)
  - `calcular(array $d): array` con entrada `{fechaIngreso, fechaSalida, motivo, salarioPromedio, salarioDiarioVacaciones, diasVacaciones, brutosAguinaldo}` y salida `{preaviso, cesantia, vacaciones_pendientes, aguinaldo_proporcional, total_liquidacion, dias_preaviso, dias_cesantia, dias_vacaciones, salario_promedio, anios_contados, tasa_cesantia}` (todos float salvo `anios_contados` int).

- [ ] **Step 1: Write the failing test**

Reemplazar todo `tests/Helpers/LiquidacionCalculadoraTest.php` por:

```php
<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Helpers\LiquidacionCalculadora;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LiquidacionCalculadoraTest extends TestCase
{
    private function calc(): LiquidacionCalculadora
    {
        return new LiquidacionCalculadora();
    }

    /** @return array<string, array{string, string, float, float}> ingreso, salida, preaviso, cesantía */
    public static function antiguedades(): array
    {
        return [
            'mismo día'          => ['2026-01-01', '2026-01-01', 0.0, 0.0],
            '2 meses'            => ['2026-01-01', '2026-03-01', 0.0, 0.0],
            '3 meses exactos'    => ['2026-01-01', '2026-04-01', 7.0, 7.0],
            '6 meses exactos'    => ['2026-01-01', '2026-07-01', 7.0, 7.0],
            '6 meses y 1 día'    => ['2026-01-01', '2026-07-02', 15.0, 14.0],
            '11 meses'           => ['2025-01-01', '2025-12-01', 15.0, 14.0],
            '1 año exacto'       => ['2025-01-01', '2026-01-01', 30.0, 19.5],
            '1 año y 8 meses'    => ['2024-01-01', '2025-09-01', 30.0, 39.0],
            '6 años y 6 meses'   => ['2020-01-01', '2026-07-01', 30.0, 129.0],
            '6 años y 7 meses'   => ['2020-01-01', '2026-08-01', 30.0, 150.5],
            '9 años'             => ['2017-01-01', '2026-01-01', 30.0, 176.0],
            '12 años'            => ['2014-01-01', '2026-01-01', 30.0, 164.0],
            '15 años'            => ['2011-01-01', '2026-01-01', 30.0, 160.0],
        ];
    }

    #[DataProvider('antiguedades')]
    public function testDiasDePreavisoYCesantiaSegunAntiguedad(string $ingreso, string $salida, float $preaviso, float $cesantia): void
    {
        $c   = $this->calc();
        $ant = $c->antiguedad($ingreso, $salida);
        self::assertEqualsWithDelta($preaviso, $c->diasPreaviso($ant), 0.001);
        self::assertEqualsWithDelta($cesantia, $c->diasCesantia($ant)['dias'], 0.001);
    }

    public function testCesantiaUsaUnaSolaTasaPorLaAntiguedadTotal(): void
    {
        // DAJ-AE-083-09: 1 año y 8 meses = 19,5 × 2, no 19,5 + 20
        $r = $this->calc()->diasCesantia($this->calc()->antiguedad('2024-01-01', '2025-09-01'));
        self::assertSame(2, $r['anios_contados']);
        self::assertEqualsWithDelta(19.5, $r['tasa'], 0.001);
    }

    public function testTasaDeLaTablaCompletaDelArticulo29(): void
    {
        $c = $this->calc();
        $esperado = [1 => 19.5, 2 => 20.0, 3 => 20.5, 4 => 21.0, 5 => 21.24, 6 => 21.5, 7 => 22.0,
                     8 => 22.0, 9 => 22.0, 10 => 21.5, 11 => 21.0, 12 => 20.5, 13 => 20.0, 20 => 20.0];
        foreach ($esperado as $anios => $tasa) {
            self::assertEqualsWithDelta($tasa, $c->tasaCesantia($anios), 0.001, "año $anios");
        }
    }

    /** @return array<string, array{string, bool, bool}> motivo, paga preaviso, paga cesantía */
    public static function motivos(): array
    {
        return [
            'despido sin causa'     => ['despido_sin_causa', true, true],
            'renuncia justificada'  => ['renuncia_justificada', true, true],
            'muerte'                => ['muerte', false, true],
            'jubilación'            => ['jubilacion', false, true],
            'renuncia'              => ['renuncia', false, false],
            'despido con causa'     => ['despido_con_causa', false, false],
            'mutuo acuerdo'         => ['mutuo_acuerdo', false, false],
        ];
    }

    #[DataProvider('motivos')]
    public function testRubrosSegunElMotivo(string $motivo, bool $preaviso, bool $cesantia): void
    {
        $r = $this->calc()->calcular($this->datos(['motivo' => $motivo]));
        self::assertSame($preaviso, $r['preaviso'] > 0, 'preaviso');
        self::assertSame($cesantia, $r['cesantia'] > 0, 'cesantía');
        self::assertGreaterThan(0, $r['vacaciones_pendientes']);
        self::assertGreaterThan(0, $r['aguinaldo_proporcional']);
    }

    public function testCalcularDespidoSinCausaIncluyePreavisoYCesantia(): void
    {
        // 6 años y 6 meses, base ₡300 000 (₡10 000 por día)
        $r = $this->calc()->calcular($this->datos());

        self::assertEqualsWithDelta(300000.0, $r['preaviso'], 0.01);        // 30 días
        self::assertEqualsWithDelta(1290000.0, $r['cesantia'], 0.01);       // 6 × 21,5 = 129 días
        self::assertEqualsWithDelta(100000.0, $r['vacaciones_pendientes'], 0.01);
        self::assertEqualsWithDelta(175000.0, $r['aguinaldo_proporcional'], 0.01);
        self::assertEqualsWithDelta(1865000.0, $r['total_liquidacion'], 0.01);
        self::assertEqualsWithDelta(30.0, $r['dias_preaviso'], 0.001);
        self::assertEqualsWithDelta(129.0, $r['dias_cesantia'], 0.001);
        self::assertEqualsWithDelta(10.0, $r['dias_vacaciones'], 0.001);
        self::assertEqualsWithDelta(300000.0, $r['salario_promedio'], 0.01);
        self::assertSame(6, $r['anios_contados']);
        self::assertEqualsWithDelta(21.5, $r['tasa_cesantia'], 0.001);
    }

    public function testVacacionesNegativasSePaganComoCero(): void
    {
        $r = $this->calc()->calcular($this->datos(['diasVacaciones' => -3.0]));
        self::assertEqualsWithDelta(0.0, $r['vacaciones_pendientes'], 0.001);
        self::assertEqualsWithDelta(0.0, $r['dias_vacaciones'], 0.001);
    }

    public function testDiasComerciales(): void
    {
        self::assertSame(15, LiquidacionCalculadora::diasComerciales('2026-08-16', '2026-08-31')); // el 31 no cuenta
        self::assertSame(30, LiquidacionCalculadora::diasComerciales('2026-02-01', '2026-02-28')); // febrero completo = 30
        self::assertSame(15, LiquidacionCalculadora::diasComerciales('2028-02-16', '2028-02-29')); // bisiesto
        self::assertSame(180, LiquidacionCalculadora::diasComerciales('2026-03-01', '2026-08-31'));
        self::assertSame(16, LiquidacionCalculadora::diasComerciales('2026-06-16', '2026-07-01'));
        self::assertSame(0, LiquidacionCalculadora::diasComerciales('2026-08-31', '2026-08-31'));  // solo un día 31
        self::assertSame(0, LiquidacionCalculadora::diasComerciales('2026-09-02', '2026-09-01'));
    }

    /** @param array<string, mixed> $cambios @return array<string, mixed> */
    private function datos(array $cambios = []): array
    {
        return $cambios + [
            'fechaIngreso'            => '2020-01-01',
            'fechaSalida'             => '2026-07-01',
            'motivo'                  => 'despido_sin_causa',
            'salarioPromedio'         => 300000.0,
            'salarioDiarioVacaciones' => 10000.0,
            'diasVacaciones'          => 10.0,
            'brutosAguinaldo'         => 2100000.0,
        ];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Helpers/LiquidacionCalculadoraTest.php`
Expected: FAIL (métodos con firmas distintas / `diasComerciales` no existe / montos de cesantía distintos).

- [ ] **Step 3: Write the implementation**

Reemplazar todo `src/Helpers/LiquidacionCalculadora.php` por:

```php
<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;

/**
 * LiquidacionCalculadora — cálculo puro de la liquidación laboral (Código de Trabajo CR, Arts. 28, 29,
 * 30, 83-85 y 157; Ley 2412). Recibe ya calculadas la base del Art. 30, el valor diario de las
 * vacaciones y los brutos del aguinaldo; aquí solo se aplican las reglas. Sin BD ni HTTP.
 */
final class LiquidacionCalculadora
{
    /** Art. 29 inciso 3: días por año según la antigüedad total (13 = "13 y siguientes"). */
    private const TASA_CESANTIA = [
        1 => 19.5, 2 => 20.0, 3 => 20.5, 4 => 21.0, 5 => 21.24, 6 => 21.5, 7 => 22.0,
        8 => 22.0, 9 => 22.0, 10 => 21.5, 11 => 21.0, 12 => 20.5, 13 => 20.0,
    ];

    /** Art. 29 inciso 4: nunca más que los últimos ocho años. */
    private const TOPE_ANIOS = 8;

    /** Motivos que pagan preaviso (Arts. 28, 83-84). */
    public const CON_PREAVISO = ['despido_sin_causa', 'renuncia_justificada'];

    /** Motivos que pagan cesantía (Arts. 29, 83-85). */
    public const CON_CESANTIA = ['despido_sin_causa', 'renuncia_justificada', 'muerte', 'jubilacion'];

    /** @return array{anios:int, meses:int, dias:int, mesesTotales:int, masDeSeisMeses:bool} */
    public function antiguedad(string $fechaIngreso, string $fechaSalida): array
    {
        $d = (new DateTimeImmutable($fechaIngreso))->diff(new DateTimeImmutable($fechaSalida));
        return [
            'anios'          => $d->y,
            'meses'          => $d->m,
            'dias'           => $d->d,
            'mesesTotales'   => $d->y * 12 + $d->m,
            // fracción del último año "superior a seis meses" (6 meses y al menos un día)
            'masDeSeisMeses' => $d->m > 6 || ($d->m === 6 && $d->d > 0),
        ];
    }

    /** Art. 28: 3 a 6 meses → 7; más de 6 meses y menos de 1 año → 15; 1 año o más → 30. */
    public function diasPreaviso(array $a): float
    {
        if ($a['anios'] >= 1) {
            return 30.0;
        }
        if ($a['mesesTotales'] < 3) {
            return 0.0;
        }
        return $a['masDeSeisMeses'] ? 15.0 : 7.0;
    }

    /**
     * Art. 29: antes del año, 7 o 14 días; desde el año, una sola tasa (la de los años completos)
     * × años contados (una fracción mayor de seis meses suma un año), con tope de 8 años.
     * @return array{dias:float, anios_contados:int, tasa:float}
     */
    public function diasCesantia(array $a): array
    {
        if ($a['anios'] >= 1) {
            $tasa    = $this->tasaCesantia($a['anios']);
            $contados = min($a['anios'] + ($a['masDeSeisMeses'] ? 1 : 0), self::TOPE_ANIOS);
            return ['dias' => round($tasa * $contados, 2), 'anios_contados' => $contados, 'tasa' => $tasa];
        }
        if ($a['mesesTotales'] < 3) {
            return ['dias' => 0.0, 'anios_contados' => 0, 'tasa' => 0.0];
        }
        return ['dias' => $a['masDeSeisMeses'] ? 14.0 : 7.0, 'anios_contados' => 0, 'tasa' => 0.0];
    }

    public function tasaCesantia(int $aniosCompletos): float
    {
        return self::TASA_CESANTIA[min(max($aniosCompletos, 1), 13)];
    }

    /**
     * Días entre dos fechas (inclusive) en el mes comercial de 30 días: el día 31 no cuenta y el último
     * día de febrero completa el mes hasta 30.
     */
    public static function diasComerciales(string $desde, string $hasta): int
    {
        $ini = new DateTimeImmutable($desde);
        if ((int) $ini->format('d') === 31) {
            $ini = $ini->modify('+1 day');
        }
        $fin = new DateTimeImmutable($hasta);
        if ($ini > $fin) {
            return 0;
        }
        $d1 = min((int) $ini->format('d'), 30);
        $d2 = $fin->format('d') === $fin->format('t') ? 30 : min((int) $fin->format('d'), 30);
        $dias = ((int) $fin->format('Y') - (int) $ini->format('Y')) * 360
              + ((int) $fin->format('n') - (int) $ini->format('n')) * 30
              + ($d2 - $d1) + 1;
        return max(0, $dias);
    }

    /**
     * @param array{fechaIngreso:string, fechaSalida:string, motivo:string, salarioPromedio:float,
     *              salarioDiarioVacaciones:float, diasVacaciones:float, brutosAguinaldo:float} $d
     * @return array<string, float|int>
     */
    public function calcular(array $d): array
    {
        $ant     = $this->antiguedad($d['fechaIngreso'], $d['fechaSalida']);
        $valorDia = $d['salarioPromedio'] / 30.0;   // Art. 30

        $diasPreaviso = in_array($d['motivo'], self::CON_PREAVISO, true) ? $this->diasPreaviso($ant) : 0.0;
        $ces = in_array($d['motivo'], self::CON_CESANTIA, true)
            ? $this->diasCesantia($ant)
            : ['dias' => 0.0, 'anios_contados' => 0, 'tasa' => 0.0];
        $diasVac = max(0.0, $d['diasVacaciones']);

        $preaviso   = round($diasPreaviso * $valorDia, 2);
        $cesantia   = round($ces['dias'] * $valorDia, 2);
        $vacaciones = round($diasVac * $d['salarioDiarioVacaciones'], 2);   // Art. 157
        $aguinaldo  = round($d['brutosAguinaldo'] / 12.0, 2);              // Ley 2412

        return [
            'preaviso'               => $preaviso,
            'cesantia'               => $cesantia,
            'vacaciones_pendientes'  => $vacaciones,
            'aguinaldo_proporcional' => $aguinaldo,
            'total_liquidacion'      => round($preaviso + $cesantia + $vacaciones + $aguinaldo, 2),
            'dias_preaviso'          => $diasPreaviso,
            'dias_cesantia'          => $ces['dias'],
            'dias_vacaciones'        => $diasVac,
            'salario_promedio'       => round($d['salarioPromedio'], 2),
            'anios_contados'         => $ces['anios_contados'],
            'tasa_cesantia'          => $ces['tasa'],
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Helpers/LiquidacionCalculadoraTest.php`
Expected: PASS (13 casos de antigüedad + 7 motivos + 5 pruebas).

- [ ] **Step 5: Commit**

`LiquidacionService` todavía llama la calculadora con la entrada vieja; eso se arregla en Task 4. Comprobar que el resto de la suite sigue verde salvo nada relacionado: `vendor/bin/phpunit` (no hay pruebas de `LiquidacionService` todavía).

```bash
git add src/Helpers/LiquidacionCalculadora.php tests/Helpers/LiquidacionCalculadoraTest.php
git commit -m "fix(liquidacion): cesantía con una sola tasa y tope de 8 años, preaviso en los límites y motivos del Art. 83-85

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Esquema, migración y lecturas en los repositorios

**Files:**
- Create: `database/migraciones/2026-10-19-liquidacion-legal.sql`
- Modify: `database/schema.sql` (tabla `liquidacion`, sección `-- 18. liquidacion`)
- Modify: `src/Repositories/LiquidacionRepository.php` (`upsert`)
- Modify: `src/Repositories/NominasRepository.php` (método nuevo después de `primerInicioNominaEntre`)
- Modify: `src/Repositories/AguinaldoRepository.php` (método nuevo después de `sumaBrutos`)

**Interfaces:**
- Produces:
  - `NominasRepository::ultimoFinNominaEntre(int $idEmpleado, string $desde, string $hasta): ?string` — mayor `fecha_fin` de período con nómina (cualquier estado) cuyo fin cae entre las fechas.
  - `AguinaldoRepository::ultimoFinNominaPagadaEntre(int $idEmpleado, string $desde, string $hasta): ?string` — mayor `fecha_fin` de las nóminas aprobadas o pagadas que `sumaBrutos` contaría (mismo filtro).
  - `LiquidacionRepository::upsert(array $d): int` acepta además `salario_promedio`, `dias_preaviso`, `dias_cesantia`, `dias_vacaciones`, `base_estimada`.

- [ ] **Step 1: Write the migration**

`database/migraciones/2026-10-19-liquidacion-legal.sql`:

```sql
-- Migración 2026-10-19: liquidación según el Código de Trabajo.
ALTER TABLE liquidacion
    MODIFY `motivo` ENUM('renuncia','despido_con_causa','despido_sin_causa','renuncia_justificada','mutuo_acuerdo','jubilacion','muerte') NOT NULL,
    ADD COLUMN `salario_promedio` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Base Art. 30: promedio mensual de los últimos 6 meses' AFTER `motivo`,
    ADD COLUMN `dias_preaviso`    DECIMAL(5,2)  NOT NULL DEFAULT 0.00 AFTER `salario_promedio`,
    ADD COLUMN `dias_cesantia`    DECIMAL(6,2)  NOT NULL DEFAULT 0.00 AFTER `dias_preaviso`,
    ADD COLUMN `dias_vacaciones`  DECIMAL(5,2)  NOT NULL DEFAULT 0.00 AFTER `dias_cesantia`,
    ADD COLUMN `base_estimada`    TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = sin nóminas en la ventana; se usó el salario del puesto' AFTER `dias_vacaciones`;
```

- [ ] **Step 2: Update `database/schema.sql`**

Reemplazar la línea del `motivo` de la tabla `liquidacion` y agregar las columnas justo después, para que quede:

```sql
    `motivo`                ENUM('renuncia','despido_con_causa','despido_sin_causa','renuncia_justificada','mutuo_acuerdo','jubilacion','muerte') NOT NULL,
    `salario_promedio`      DECIMAL(10,2)  NOT NULL DEFAULT 0.00 COMMENT 'Base Art. 30: promedio mensual de los últimos 6 meses',
    `dias_preaviso`         DECIMAL(5,2)   NOT NULL DEFAULT 0.00,
    `dias_cesantia`         DECIMAL(6,2)   NOT NULL DEFAULT 0.00,
    `dias_vacaciones`       DECIMAL(5,2)   NOT NULL DEFAULT 0.00,
    `base_estimada`         TINYINT(1)     NOT NULL DEFAULT 0 COMMENT '1 = sin nóminas en la ventana; se usó el salario del puesto',
```

Cambiar también el comentario de la sección a: `-- Liquidación laboral al término de la relación (Código de Trabajo Arts. 28-30, 83-85, 157; Ley 2412).`

- [ ] **Step 3: Update `LiquidacionRepository::upsert`**

Reemplazar el método completo por:

```php
    /** Inserta o actualiza la liquidación del empleado (UNIQUE id_empleado). */
    public function upsert(array $d): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO liquidacion
                (id_empleado, fecha_salida, motivo, salario_promedio, dias_preaviso, dias_cesantia, dias_vacaciones,
                 base_estimada, preaviso, cesantia, vacaciones_pendientes, aguinaldo_proporcional, total_liquidacion)
             VALUES (:e, :fs, :mo, :sp, :dp, :dc, :dv, :be, :pr, :ce, :va, :ag, :to)
             ON DUPLICATE KEY UPDATE
                fecha_salida = VALUES(fecha_salida),
                motivo = VALUES(motivo),
                salario_promedio = VALUES(salario_promedio),
                dias_preaviso = VALUES(dias_preaviso),
                dias_cesantia = VALUES(dias_cesantia),
                dias_vacaciones = VALUES(dias_vacaciones),
                base_estimada = VALUES(base_estimada),
                preaviso = VALUES(preaviso),
                cesantia = VALUES(cesantia),
                vacaciones_pendientes = VALUES(vacaciones_pendientes),
                aguinaldo_proporcional = VALUES(aguinaldo_proporcional),
                total_liquidacion = VALUES(total_liquidacion),
                fecha_calculo = CURRENT_TIMESTAMP"
        );
        $stmt->execute([
            ':e'  => $d['id_empleado'],
            ':fs' => $d['fecha_salida'],
            ':mo' => $d['motivo'],
            ':sp' => $d['salario_promedio'],
            ':dp' => $d['dias_preaviso'],
            ':dc' => $d['dias_cesantia'],
            ':dv' => $d['dias_vacaciones'],
            ':be' => $d['base_estimada'],
            ':pr' => $d['preaviso'],
            ':ce' => $d['cesantia'],
            ':va' => $d['vacaciones_pendientes'],
            ':ag' => $d['aguinaldo_proporcional'],
            ':to' => $d['total_liquidacion'],
        ]);
        // lastInsertId es 0 en UPDATE; recuperar el id real.
        $sel = $this->pdo->prepare('SELECT id_liquidacion FROM liquidacion WHERE id_empleado = :e LIMIT 1');
        $sel->execute([':e' => $d['id_empleado']]);
        return (int) $sel->fetchColumn();
    }
```

- [ ] **Step 4: Add `NominasRepository::ultimoFinNominaEntre`**

Después de `primerInicioNominaEntre`:

```php
    /** Fin del último período con nómina del empleado cuyo fin cae entre las fechas, o null si no hay. */
    public function ultimoFinNominaEntre(int $idEmpleado, string $desde, string $hasta): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT MAX(p.fecha_fin) FROM nominas n
               JOIN periodos_pago p ON p.id_periodo = n.id_periodo
              WHERE n.id_empleado = :e AND p.fecha_fin BETWEEN :d AND :h'
        );
        $stmt->execute([':e' => $idEmpleado, ':d' => $desde, ':h' => $hasta]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (string)$v;
    }
```

- [ ] **Step 5: Add `AguinaldoRepository::ultimoFinNominaPagadaEntre`**

Después de `sumaBrutos` (mismo filtro que `sumaBrutos`):

```php
    /** Fin del último período que suma `sumaBrutos` (nóminas aprobadas o pagadas dentro del rango), o null. */
    public function ultimoFinNominaPagadaEntre(int $idEmpleado, string $desde, string $hasta): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT MAX(p.fecha_fin)
               FROM nominas n
               JOIN periodos_pago p ON p.id_periodo = n.id_periodo
              WHERE n.id_empleado = :e
                AND n.estado IN ('aprobado','pagado')
                AND p.fecha_inicio >= :desde
                AND p.fecha_fin <= :hasta"
        );
        $stmt->execute([':e' => $idEmpleado, ':desde' => $desde, ':hasta' => $hasta]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }
```

- [ ] **Step 6: Check syntax and the schema tests**

Run: `php -l src/Repositories/LiquidacionRepository.php && php -l src/Repositories/NominasRepository.php && php -l src/Repositories/AguinaldoRepository.php` y `vendor/bin/phpunit tests/Services/ValoresSinNuloTest.php`
Expected: `No syntax errors detected` en los tres; prueba verde.

- [ ] **Step 7: Commit**

```bash
git add database/migraciones/2026-10-19-liquidacion-legal.sql database/schema.sql src/Repositories/LiquidacionRepository.php src/Repositories/NominasRepository.php src/Repositories/AguinaldoRepository.php
git commit -m "feat(liquidacion): motivos de los Arts. 83 y 85 y columnas de base y días en el esquema

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `LiquidacionService` reúne la base del Art. 30, las vacaciones y el aguinaldo

**Files:**
- Modify (reescribir completo): `src/Services/LiquidacionService.php`
- Modify: `config/dependencies.php` (fábrica de `LiquidacionService`, hoy ~líneas 493-499)
- Test: `tests/Services/LiquidacionServiceTest.php`

**Interfaces:**
- Consumes: `PromedioSalarial::diario` (Task 1); `LiquidacionCalculadora::calcular`, `::diasComerciales` (Task 2); `NominasRepository::brutoEntre/primerInicioNominaEntre/ultimoFinNominaEntre`, `AguinaldoRepository::sumaBrutos/ultimoFinNominaPagadaEntre`, `LiquidacionRepository::upsert/datosEmpleado` (Task 3); `SaldoVacacionesService::saldo(int, string, DateTimeImmutable): array{disponibles: float, ...}`.
- Produces:
  - Constructor `__construct(LiquidacionRepository $repo, AuditoriaRepository $auditoriaRepo, LiquidacionCalculadora $calc, NominasRepository $nominasRepo, AguinaldoRepository $aguinaldoRepo, SaldoVacacionesService $saldoVacaciones)`.
  - `public const MOTIVOS` (array valor → etiqueta, en el orden de Global Constraints).
  - `datosFormulario(): array{empleados: array, motivos: array<string,string>}`.
  - `calcular(array $datos, int $loggedInId, string $ip): int` (ya no lee `dias_vacaciones`).

- [ ] **Step 1: Write the failing test**

`tests/Services/LiquidacionServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\LiquidacionCalculadora;
use App\Repositories\AguinaldoRepository;
use App\Repositories\AuditoriaRepository;
use App\Repositories\LiquidacionRepository;
use App\Repositories\NominasRepository;
use App\Services\LiquidacionService;
use App\Services\SaldoVacacionesService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LiquidacionServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $guardado = [];
    /** @var string[] */
    private array $iniciosAguinaldo = [];
    /** @var string[] */
    private array $desdesBruto = [];

    /**
     * @param array{ingreso?:string, bruto30?:float, bruto157?:float, primer?:?string, ultimo?:?string,
     *              disponibles?:float, sumaAg?:float, ultimoPagado?:?string} $o
     */
    private function servicio(array $o = []): LiquidacionService
    {
        $o += ['ingreso' => '2020-01-01', 'bruto30' => 1800000.0, 'bruto157' => 3000000.0, 'primer' => '2026-01-01',
               'ultimo' => '2026-06-30', 'disponibles' => 10.0, 'sumaAg' => 1950000.0, 'ultimoPagado' => '2026-06-15'];

        $repo = $this->createMock(LiquidacionRepository::class);
        $repo->method('datosEmpleado')->willReturn(['id_empleado' => 7, 'fecha_ingreso' => $o['ingreso'], 'estado' => 'activo', 'salario_base' => 300000]);
        $repo->method('upsert')->willReturnCallback(function (array $d): int { $this->guardado = $d; return 11; });

        $nom = $this->createMock(NominasRepository::class);
        // La ventana del Art. 30 empieza 6 meses antes de la salida; la del Art. 157, 349 días antes.
        $nom->method('brutoEntre')->willReturnCallback(function (int $e, string $desde) use ($o): float {
            $this->desdesBruto[] = $desde;
            return $desde >= '2026-01-01' ? $o['bruto30'] : $o['bruto157'];
        });
        $nom->method('primerInicioNominaEntre')->willReturnCallback(
            fn (int $e, string $desde) => $o['primer'] === null ? null : ($desde >= '2026-01-01' ? $o['primer'] : '2025-07-01'));
        $nom->method('ultimoFinNominaEntre')->willReturn($o['ultimo']);

        $ag = $this->createMock(AguinaldoRepository::class);
        $ag->method('sumaBrutos')->willReturnCallback(function (int $e, string $desde) use ($o): float {
            $this->iniciosAguinaldo[] = $desde;
            return $o['sumaAg'];
        });
        $ag->method('ultimoFinNominaPagadaEntre')->willReturn($o['ultimoPagado']);

        $saldo = $this->createMock(SaldoVacacionesService::class);
        $saldo->method('saldo')->willReturn(['ganados' => 0.0, 'comprometidos' => 0.0, 'disponibles' => $o['disponibles'], 'fin_prueba' => '', 'en_prueba' => false]);

        return new LiquidacionService($repo, $this->createMock(AuditoriaRepository::class), new LiquidacionCalculadora(), $nom, $ag, $saldo);
    }

    private function calcular(LiquidacionService $s, string $motivo = 'despido_sin_causa', string $salida = '2026-07-01'): void
    {
        $s->calcular(['id_empleado' => '7', 'motivo' => $motivo, 'fecha_salida' => $salida], 1, '127.0.0.1');
    }

    public function testBaseDelArticulo30EsElPromedioDeLosUltimosSeisMeses(): void
    {
        $this->calcular($this->servicio());

        // 1 800 000 ÷ 180 días (02/01 al 30/06) × 30 = 300 000
        self::assertEqualsWithDelta(300000.0, $this->guardado['salario_promedio'], 0.01);
        self::assertSame(0, $this->guardado['base_estimada']);
        self::assertEqualsWithDelta(129.0, $this->guardado['dias_cesantia'], 0.001);
        self::assertEqualsWithDelta(1290000.0, $this->guardado['cesantia'], 0.01);
        self::assertEqualsWithDelta(300000.0, $this->guardado['preaviso'], 0.01);
    }

    public function testVacacionesConElSaldoDelSistemaYElMayorValorDiario(): void
    {
        $this->calcular($this->servicio());

        // promedio 157 = 3 000 000 ÷ 349 < 10 000, así que vale salario ÷ 30
        self::assertEqualsWithDelta(10.0, $this->guardado['dias_vacaciones'], 0.001);
        self::assertEqualsWithDelta(100000.0, $this->guardado['vacaciones_pendientes'], 0.01);
    }

    public function testAguinaldoSumaLoPagadoMasLosDiasSinNomina(): void
    {
        $this->calcular($this->servicio());

        // 1 950 000 + 16 días comerciales (16/06 al 01/07) × 10 000 = 2 110 000 ÷ 12
        self::assertSame('2025-12-01', $this->iniciosAguinaldo[0]);
        self::assertEqualsWithDelta(175833.33, $this->guardado['aguinaldo_proporcional'], 0.01);
    }

    public function testSinNominasUsaElSalarioDelPuestoYLoMarca(): void
    {
        $this->calcular($this->servicio(['ingreso' => '2026-03-01', 'bruto30' => 0.0, 'bruto157' => 0.0, 'primer' => null,
                                         'ultimo' => null, 'sumaAg' => 0.0, 'ultimoPagado' => null]), 'renuncia', '2026-08-31');

        self::assertSame('2026-03-01', $this->desdesBruto[0]);   // 31/08 − 6 meses = 28/02, + 1 día
        self::assertSame(1, $this->guardado['base_estimada']);
        self::assertEqualsWithDelta(300000.0, $this->guardado['salario_promedio'], 0.01);
        self::assertEqualsWithDelta(0.0, $this->guardado['preaviso'], 0.001);
        self::assertEqualsWithDelta(0.0, $this->guardado['cesantia'], 0.001);
        // empieza en el ingreso (posterior al 1 de diciembre): 180 días comerciales × 10 000 ÷ 12
        self::assertSame('2026-03-01', $this->iniciosAguinaldo[0]);
        self::assertEqualsWithDelta(150000.0, $this->guardado['aguinaldo_proporcional'], 0.01);
    }

    public function testSaldoNegativoPagaCeroDias(): void
    {
        $this->calcular($this->servicio(['disponibles' => -4.0]));

        self::assertEqualsWithDelta(0.0, $this->guardado['dias_vacaciones'], 0.001);
        self::assertEqualsWithDelta(0.0, $this->guardado['vacaciones_pendientes'], 0.001);
    }

    public function testSalidaEnDiciembreEmpiezaElAguinaldoEseMismoDiciembre(): void
    {
        $this->calcular($this->servicio(['ultimoPagado' => null]), 'renuncia', '2026-12-10');

        self::assertSame('2026-12-01', $this->iniciosAguinaldo[0]);
    }

    public function testMuerteYRenunciaJustificadaSonMotivosValidos(): void
    {
        $this->calcular($this->servicio(), 'muerte');
        self::assertSame('muerte', $this->guardado['motivo']);
        self::assertEqualsWithDelta(0.0, $this->guardado['preaviso'], 0.001);
        self::assertGreaterThan(0, $this->guardado['cesantia']);

        $this->calcular($this->servicio(), 'renuncia_justificada');
        self::assertGreaterThan(0, $this->guardado['preaviso']);
    }

    public function testMotivoInvalidoSeRechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calcular($this->servicio(), 'abandono');
    }

    public function testFormularioOfreceLosSieteMotivosConEtiqueta(): void
    {
        $motivos = $this->servicio()->datosFormulario()['motivos'];
        self::assertCount(7, $motivos);
        self::assertSame('Renuncia justificada (Art. 83)', $motivos['renuncia_justificada']);
        self::assertSame('Muerte del trabajador', $motivos['muerte']);
    }
}
```

Nota para quien implementa: el mock de `datosFormulario` necesita `empleadosActivos`; `createMock` devuelve `[]` por defecto, que basta.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Services/LiquidacionServiceTest.php`
Expected: FAIL (`ArgumentCountError` en el constructor: hoy recibe 3 argumentos).

- [ ] **Step 3: Write the implementation**

Reemplazar todo `src/Services/LiquidacionService.php` por:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\LiquidacionCalculadora;
use App\Helpers\PromedioSalarial;
use App\Repositories\AguinaldoRepository;
use App\Repositories\AuditoriaRepository;
use App\Repositories\LiquidacionRepository;
use App\Repositories\NominasRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * LiquidacionService — reúne los datos de la liquidación (base del Art. 30, saldo y valor de las
 * vacaciones del Art. 157, aguinaldo de la Ley 2412) y deja las reglas a LiquidacionCalculadora.
 */
class LiquidacionService
{
    public const MOTIVOS = [
        'renuncia'             => 'Renuncia',
        'despido_con_causa'    => 'Despido con causa',
        'despido_sin_causa'    => 'Despido sin causa',
        'renuncia_justificada' => 'Renuncia justificada (Art. 83)',
        'mutuo_acuerdo'        => 'Mutuo acuerdo',
        'jubilacion'           => 'Jubilación o pensión',
        'muerte'               => 'Muerte del trabajador',
    ];

    public function __construct(
        private readonly LiquidacionRepository  $repo,
        private readonly AuditoriaRepository    $auditoriaRepo,
        private readonly LiquidacionCalculadora $calc,
        private readonly NominasRepository      $nominasRepo,
        private readonly AguinaldoRepository    $aguinaldoRepo,
        private readonly SaldoVacacionesService $saldoVacaciones
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function listar(?string $q = null): array
    {
        return $this->repo->findAll($q);
    }

    /** @return array<string, mixed> */
    public function obtener(int $id): array
    {
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new RuntimeException('Liquidación no encontrada.');
        }
        return $row;
    }

    /** @return array{empleados: array<int, array<string, mixed>>, motivos: array<string, string>} */
    public function datosFormulario(): array
    {
        return ['empleados' => $this->repo->empleadosActivos(), 'motivos' => self::MOTIVOS];
    }

    /** Calcula y persiste la liquidación de un empleado. @return int id_liquidacion */
    public function calcular(array $datos, int $loggedInId, string $ip): int
    {
        $idEmpleado  = isset($datos['id_empleado']) && is_numeric($datos['id_empleado']) ? (int) $datos['id_empleado'] : 0;
        $motivo      = (string) ($datos['motivo'] ?? '');
        $fechaSalida = trim((string) ($datos['fecha_salida'] ?? ''));

        $errores = [];
        $emp = $idEmpleado > 0 ? $this->repo->datosEmpleado($idEmpleado) : null;
        if ($emp === null) {
            $errores[] = 'Debe seleccionar un empleado válido.';
        }
        if (!array_key_exists($motivo, self::MOTIVOS)) {
            $errores[] = 'El motivo seleccionado no es válido.';
        }
        if ($fechaSalida === '' || strtotime($fechaSalida) === false) {
            $errores[] = 'La fecha de salida no es válida.';
        } elseif ($emp !== null && $fechaSalida < (string) $emp['fecha_ingreso']) {
            $errores[] = 'La fecha de salida no puede ser anterior a la fecha de ingreso.';
        }
        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $ingreso = (string) $emp['fecha_ingreso'];
        $salario = (float) $emp['salario_base'];
        $salida  = new DateTimeImmutable($fechaSalida);

        // Art. 30: promedio de los salarios de los últimos seis meses (o del tiempo trabajado).
        $desde30  = $this->mesesAntes($salida, 6)->modify('+1 day')->format('Y-m-d');
        $diario30 = $this->promedioDiario($idEmpleado, $desde30, $fechaSalida, $ingreso, 184);
        $baseEstimada    = $diario30 === null;
        $salarioPromedio = $baseEstimada ? $salario : round($diario30 * 30, 2);

        // Art. 157: el día de vacaciones vale el mayor entre salario ÷ 30 y el promedio de 50 semanas.
        $desde157  = $salida->modify('-349 days')->format('Y-m-d');
        $diarioVac = max($salario / 30, $this->promedioDiario($idEmpleado, $desde157, $fechaSalida, $ingreso, 350) ?? 0.0);
        $diasVac   = (float) $this->saldoVacaciones->saldo($idEmpleado, $ingreso, $salida)['disponibles'];

        // Ley 2412: lo pagado desde el 1 de diciembre más los días que todavía no tienen nómina.
        $inicioAg  = $this->inicioAguinaldo($ingreso, $salida);
        $ultimoFin = $this->aguinaldoRepo->ultimoFinNominaPagadaEntre($idEmpleado, $inicioAg, $fechaSalida);
        $desdeSinNomina = $ultimoFin === null
            ? $inicioAg
            : (new DateTimeImmutable($ultimoFin))->modify('+1 day')->format('Y-m-d');
        $brutosAg = $this->aguinaldoRepo->sumaBrutos($idEmpleado, $inicioAg, $fechaSalida)
                  + LiquidacionCalculadora::diasComerciales($desdeSinNomina, $fechaSalida) * $salario / 30;

        $r = $this->calc->calcular([
            'fechaIngreso'            => $ingreso,
            'fechaSalida'             => $fechaSalida,
            'motivo'                  => $motivo,
            'salarioPromedio'         => $salarioPromedio,
            'salarioDiarioVacaciones' => $diarioVac,
            'diasVacaciones'          => $diasVac,
            'brutosAguinaldo'         => $brutosAg,
        ]);

        $id = $this->repo->upsert([
            'id_empleado'            => $idEmpleado,
            'fecha_salida'           => $fechaSalida,
            'motivo'                 => $motivo,
            'salario_promedio'       => $r['salario_promedio'],
            'dias_preaviso'          => $r['dias_preaviso'],
            'dias_cesantia'          => $r['dias_cesantia'],
            'dias_vacaciones'        => $r['dias_vacaciones'],
            'base_estimada'          => $baseEstimada ? 1 : 0,
            'preaviso'               => $r['preaviso'],
            'cesantia'               => $r['cesantia'],
            'vacaciones_pendientes'  => $r['vacaciones_pendientes'],
            'aguinaldo_proporcional' => $r['aguinaldo_proporcional'],
            'total_liquidacion'      => $r['total_liquidacion'],
        ]);
        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'liquidacion', $id, $ip);
        return $id;
    }

    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $this->obtener($id);
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'liquidacion', $id, $ip);
    }

    /** Promedio diario de lo devengado entre las fechas, por días cubiertos; null si no hay nóminas. */
    private function promedioDiario(int $idEmpleado, string $desde, string $hasta, string $ingreso, int $maxDias): ?float
    {
        return PromedioSalarial::diario(
            $this->nominasRepo->brutoEntre($idEmpleado, $desde, $hasta),
            $desde,
            $hasta,
            $ingreso,
            $this->nominasRepo->primerInicioNominaEntre($idEmpleado, $desde, $hasta),
            $this->nominasRepo->ultimoFinNominaEntre($idEmpleado, $desde, $hasta),
            $maxDias
        );
    }

    /** La misma fecha $n meses antes, sin desbordar: el 31/08 menos 6 meses es el 28/02, no el 03/03. */
    private function mesesAntes(DateTimeImmutable $fecha, int $n): DateTimeImmutable
    {
        $base = $fecha->modify('first day of -' . $n . ' months');
        return $base->setDate((int) $base->format('Y'), (int) $base->format('n'), min((int) $fecha->format('j'), (int) $base->format('t')));
    }

    /** 1 de diciembre anterior a la salida (o el de ese año si la salida es en diciembre), o el ingreso si es posterior. */
    private function inicioAguinaldo(string $ingreso, DateTimeImmutable $salida): string
    {
        $anio   = (int) $salida->format('Y');
        $inicio = $salida->format('m') === '12' ? $anio . '-12-01' : ($anio - 1) . '-12-01';
        return $ingreso > $inicio ? $ingreso : $inicio;
    }
}
```

- [ ] **Step 4: Update the DI factory**

En `config/dependencies.php`, reemplazar la fábrica de `\App\Services\LiquidacionService::class` por:

```php
    \App\Services\LiquidacionService::class => function (ContainerInterface $c) {
        return new \App\Services\LiquidacionService(
            $c->get(\App\Repositories\LiquidacionRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class),
            $c->get(\App\Helpers\LiquidacionCalculadora::class),
            $c->get(\App\Repositories\NominasRepository::class),
            $c->get(\App\Repositories\AguinaldoRepository::class),
            $c->get(\App\Services\SaldoVacacionesService::class)
        );
    },
```

(Conservar el nombre exacto de la clave de `AuditoriaRepository` que ya usa la fábrica actual.)

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit tests/Services/LiquidacionServiceTest.php` y luego `vendor/bin/phpunit`
Expected: PASS (9 pruebas) y suite completa verde.

- [ ] **Step 6: Commit**

```bash
git add src/Services/LiquidacionService.php tests/Services/LiquidacionServiceTest.php config/dependencies.php
git commit -m "feat(liquidacion): base del Art. 30, vacaciones con saldo y Art. 157, y aguinaldo con lo pagado

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Formulario y detalle

**Files:**
- Modify: `templates/liquidacion/form.html.twig` (selector de motivo y bloque de fecha/días)
- Modify: `templates/liquidacion/detalle.html.twig` (sección de datos y tabla de rubros)
- Modify: `src/Controllers/LiquidacionController.php` (`show` pasa `motivos`)
- Test: `tests/Templates/PantallasTest.php` (dos pruebas nuevas)

**Interfaces:**
- Consumes: `LiquidacionService::MOTIVOS` (Task 4) y las columnas de Task 3 (`salario_promedio`, `dias_preaviso`, `dias_cesantia`, `dias_vacaciones`, `base_estimada`).

- [ ] **Step 1: Write the failing tests**

Agregar al final de `tests/Templates/PantallasTest.php` (antes del `}` de la clase):

```php
    public function testFormularioDeLiquidacionNoPideDiasDeVacacionesYMuestraMotivosConEtiqueta(): void
    {
        $html = $this->render('liquidacion/form.html.twig', [
            'titulo' => 'Nueva Liquidación', 'empleados' => [], 'datos' => [], 'errores' => [],
            'motivos' => \App\Services\LiquidacionService::MOTIVOS,
        ]);

        self::assertStringNotContainsString('name="dias_vacaciones"', $html);
        self::assertStringContainsString('value="renuncia_justificada"', $html);
        self::assertStringContainsString('Renuncia justificada (Art. 83)', $html);
        self::assertStringContainsString('Muerte del trabajador', $html);
    }

    public function testDetalleDeLiquidacionMuestraBaseYDiasYAvisaSiLaBaseFueEstimada(): void
    {
        $html = $this->render('liquidacion/detalle.html.twig', [
            'motivos' => \App\Services\LiquidacionService::MOTIVOS,
            'liquidacion' => [
                'id_liquidacion' => 3, 'nombre' => 'Andrés', 'apellidos' => 'Rojas', 'motivo' => 'despido_sin_causa',
                'fecha_salida' => '2026-08-31', 'fecha_ingreso' => '2020-01-01', 'salario_promedio' => 300000,
                'dias_preaviso' => 30, 'dias_cesantia' => 129, 'dias_vacaciones' => 10, 'base_estimada' => 1,
                'preaviso' => 300000, 'cesantia' => 1290000, 'vacaciones_pendientes' => 100000,
                'aguinaldo_proporcional' => 175000, 'total_liquidacion' => 1865000,
            ],
        ]);

        self::assertStringContainsString('Despido sin causa', $html);
        self::assertStringContainsString('129 días', $html);
        self::assertStringContainsString('₡300,000.00', $html);
        self::assertStringContainsString('se usó el salario del puesto', $html);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Templates/PantallasTest.php`
Expected: FAIL (el formulario todavía tiene `dias_vacaciones`; el detalle no muestra días ni el aviso).

- [ ] **Step 3: Update the form**

En `templates/liquidacion/form.html.twig`, reemplazar el bucle del selector de motivo por:

```twig
                    {% for valor, etiqueta in motivos %}
                    <option value="{{ valor }}" {{ datos.motivo == valor ? 'selected' : '' }}>{{ etiqueta }}</option>
                    {% endfor %}
```

y reemplazar el bloque `<div class="row g-3">…</div>` (fecha de salida + días de vacaciones) por:

```twig
            <div class="mb-3">
                <label class="form-label fw-semibold">Fecha de salida</label>
                <input type="date" name="fecha_salida" value="{{ datos.fecha_salida }}" class="form-control" required>
                <div class="form-text">Los días de vacaciones pendientes salen del saldo del empleado a esa fecha.</div>
            </div>
```

- [ ] **Step 4: Update the detail page and the controller**

En `src/Controllers/LiquidacionController.php`, método `show`, agregar `'motivos' => \App\Services\LiquidacionService::MOTIVOS,` al arreglo que se pasa a `render`.

En `templates/liquidacion/detalle.html.twig`, reemplazar el `<div class="text-muted small">…</div>` de la sección "Datos de la salida" por:

```twig
        <div class="text-muted small">
            Motivo: {{ motivos[liquidacion.motivo] ?? liquidacion.motivo }} ·
            Salida: {{ liquidacion.fecha_salida }} ·
            Ingreso: {{ liquidacion.fecha_ingreso }} ·
            Base (Art. 30): ₡{{ liquidacion.salario_promedio|number_format(2, '.', ',') }}
        </div>
        {% if liquidacion.base_estimada %}
        <div class="alert alert-warning small mt-2 mb-0">
            Sin nóminas en los últimos seis meses: se usó el salario del puesto.
        </div>
        {% endif %}
```

y el `<tbody>` de la tabla de rubros por:

```twig
            <tbody>
                <tr><td>Preaviso (Art. 28)</td><td class="text-muted small">{{ liquidacion.dias_preaviso|number_format(2, ',', '')|replace({',00': ''}) }} días</td><td class="text-end">₡{{ liquidacion.preaviso|number_format(2, '.', ',') }}</td></tr>
                <tr><td>Cesantía (Art. 29)</td><td class="text-muted small">{{ liquidacion.dias_cesantia|number_format(2, ',', '')|replace({',00': ''}) }} días</td><td class="text-end">₡{{ liquidacion.cesantia|number_format(2, '.', ',') }}</td></tr>
                <tr><td>Vacaciones pendientes (Art. 157)</td><td class="text-muted small">{{ liquidacion.dias_vacaciones|number_format(2, ',', '')|replace({',00': ''}) }} días</td><td class="text-end">₡{{ liquidacion.vacaciones_pendientes|number_format(2, '.', ',') }}</td></tr>
                <tr><td>Aguinaldo proporcional (Ley 2412)</td><td class="text-muted small">lo devengado ÷ 12</td><td class="text-end">₡{{ liquidacion.aguinaldo_proporcional|number_format(2, '.', ',') }}</td></tr>
            </tbody>
            <tfoot>
                <tr class="fw-bold fs-5"><td colspan="2">Total liquidación</td><td class="text-end">₡{{ liquidacion.total_liquidacion|number_format(2, '.', ',') }}</td></tr>
            </tfoot>
```

(borrando el `<tfoot>` anterior, que tenía solo dos columnas).

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit tests/Templates/PantallasTest.php` y luego `vendor/bin/phpunit`
Expected: PASS; suite completa verde.

- [ ] **Step 6: Commit**

```bash
git add templates/liquidacion/form.html.twig templates/liquidacion/detalle.html.twig src/Controllers/LiquidacionController.php tests/Templates/PantallasTest.php
git commit -m "feat(liquidacion): motivos con etiqueta, sin días a mano y detalle con base y días por rubro

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Datos de demostración, `CLAUDE.md` y prueba en la base local

**Files:**
- Modify: `database/demo.php` (paso 8, llamada a `LiquidacionService::calcular`)
- Modify: `CLAUDE.md` (Grupo 5 `liquidacion`; bloque de reglas legales; conteo de tablas y `horario_dias` en Grupo 7)

- [ ] **Step 1: Update `demo.php`**

En el paso 8, quitar `'dias_vacaciones' => '6'` de la llamada (el servicio ya no lo lee):

```php
$c->get(App\Services\LiquidacionService::class)->calcular(
    ['id_empleado' => $andres, 'motivo' => 'renuncia', 'fecha_salida' => '2026-08-31'], $usrDaniela, $IP);
```

- [ ] **Step 2: Update `CLAUDE.md`**

- En la tabla del Grupo 5, fila `liquidacion`: `motivo` ENUM('renuncia','despido_con_causa','despido_sin_causa','renuncia_justificada','mutuo_acuerdo','jubilacion','muerte'). Base `salario_promedio` (Art. 30) y días por rubro.
- En el bloque de reglas legales, reemplazar `// Preaviso y cesantía: Código de Trabajo CR, Arts. 28-29` por:

```php
// Preaviso (Art. 28): 3-6 meses → 7 días; >6 meses y <1 año → 15; ≥1 año → 30.
// Cesantía (Art. 29): 3-6 meses → 7; >6 meses y <1 año → 14; desde 1 año: tasa del año de antigüedad
//   (19,5 … 22 … 20) × años contados (fracción >6 meses suma uno), tope 8 años (DAJ-AE-083-09).
// Base de ambos: promedio de los últimos 6 meses (Art. 30). Motivos con cesantía: despido sin causa,
//   renuncia justificada (Art. 83), muerte y jubilación (Art. 85); con preaviso: los dos primeros.
```

- Donde dice "29 tablas" (título de la sección de base de datos y nota de nulos), poner "30 tablas", y en la tabla del Grupo 7 agregar la fila: `| horario_dias | id_horario_dia | Días trabajados de cada horario: dia_semana (1=lunes…7=domingo), entrada, salida y minutos de almuerzo. UNIQUE(id_horario, dia_semana). Un día sin fila es descanso. |`

- [ ] **Step 3: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: todo verde.

- [ ] **Step 4: Apply to the local database (pedir OK a Gabriel antes)**

Con el OK explícito: aplicar `database/migraciones/2026-10-19-liquidacion-legal.sql` a `lubrimotos_nomina` (o regenerar con `schema.sql` + `seed.sql` + `php database/demo.php`). Luego, por HTTP con sesión de admin, calcular la liquidación de un empleado con nóminas (despido sin causa) y verificar en el detalle: base del Art. 30 distinta de cero, días de preaviso y cesantía según la tabla del Art. 29, días de vacaciones iguales al saldo del empleado, aviso de base estimada solo si no hay nóminas.

- [ ] **Step 5: Commit**

```bash
git add database/demo.php CLAUDE.md
git commit -m "docs(liquidacion): reglas de preaviso y cesantía en CLAUDE.md y demo sin días a mano

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
