# Marcar asistencia desde el portal — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el empleado marque su entrada y su salida desde el portal, corrija una salida olvidada, y vea su asistencia del mes en un calendario con un resumen (opción C).

**Architecture:**
- Reusa la tabla `asistencia` (una fila por empleado y día) y le agrega `salida_manual`.
- Las reglas viven en `MarcaAsistenciaService`. El calendario lo arma una función pura, `CalendarioAsistencia`.
- Un controller nuevo, `MiAsistenciaController`, atiende `/mi-asistencia` (GET más 3 POST).
- La pantalla es Twig con CSS propio y un poco de JS sin librerías.

**Tech Stack:** PHP 8.1+, Slim 4, PHP-DI, Twig 3, PDO/MySQL, PHPUnit 11, Bootstrap 5.

**Spec:** `docs/superpowers/specs/2026-10-12-marcar-asistencia-design.md`

## Global Constraints

- **Stack bloqueado:** sin ORM, npm, Node, frameworks JS ni librerías de gráficos.
- **Arquitectura:**
  - Controller → Service → Repository. Los Controllers no tocan PDO; los Repositories no tienen lógica.
  - Todos los archivos PHP llevan `declare(strict_types=1)`.
- **Esquema:** sin `NULL`. Las FK opcionales apuntan a la fila centinela `id=1` (`feriados.id_feriado = 1` es "No aplica").
- **"Sin salida"** = `hora_salida = '00:00:00'` y `horas_trabajadas = 0`.
- **Días hábiles:** lunes a sábado; el domingo es libre.
- **Hora:**
  - Siempre la del servidor (`America/Costa_Rica`, ya fijada en `public/index.php`).
  - Los services reciben `DateTimeImmutable $ahora` como parámetro.
- **Patrón de los POST:** POST → redirección a `portal.asistencia` → `$_SESSION['flash_success' | 'flash_error']`.
- **Empleado autenticado:** se obtiene de `$request->getAttribute('usuario')['id']`, nunca del formulario.
- **Mensajes de error:** exactamente los del spec (sección "Reglas").
- **Commits:**
  - En la rama `feature/marcar-asistencia`, nunca en `main`.
  - Terminan con `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Suite:** `vendor/bin/phpunit` (hoy 181 pruebas OK).

## Decisiones del plan (ajustes al spec)

1. **Controller:** las rutas de "Mi asistencia" van a un controller nuevo, `MiAsistenciaController`, no a `PortalController`.
   - **Motivo:** agregar una dependencia al constructor de `PortalController` rompe sus pruebas actuales, y ese controller ya es grande.
   - Se borran `PortalController::asistencia`, `PortalService::asistencia`, `PortalRepository::asistencia` y `PortalRepository::periodosConAsistencia`, que quedan sin uso.
2. **Datos del empleado:** el service obtiene estado y fecha de ingreso con `EmpleadosRepository::findById`, que ya existe. No se crea `PortalRepository::empleadoPorUsuario`. El controller sigue usando `PortalService::idEmpleado`.
3. **Error previo que se corrige (Task 1):** `AsistenciaService` guarda `id_feriado = NULL` cuando la fecha no es feriado, y la base lo rechaza. Comprobado en la base local: `ERROR 1048 Column 'id_feriado' cannot be null`. El módulo de admin no podía registrar ningún día normal.
4. **Etiqueta de feriado (Task 1):** la lista del admin mostraba la etiqueta "No aplica" para la fila centinela del feriado. `findAll` ya no une con `id_feriado = 1`.
5. **Choque de quincena (Task 3):** si al crear la quincena automática esta choca con otro período que no contiene hoy, se rechaza con *"No hay un período de pago para hoy; avise a RRHH."* El spec no lo cubría y crear un período encimado rompería la nómina.
6. **Doble clic (Task 3):** si dos entradas llegan al mismo tiempo, la regla UNIQUE lanza un `PDOException`. Se traduce a *"Ya marcó su entrada hoy."*

## Review Focus

1. **Doble clic en "Marcar entrada":** dos POST simultáneos. Se espera una sola fila y un mensaje claro, sin error 500. Lo cubre la Task 3 (`testMarcarEntradaTraduceDuplicadoDeLaBase`).
2. **Quincena automática que choca con un período existente que no contiene hoy:** no se crea un período encimado. Lo cubre la Task 3 (`testMarcarEntradaRechazaSiLaQuincenaChocaConOtroPeriodo`).
3. **`?mes=` inválido o fuera de rango** (`2026-13`, `abc`, un mes futuro, un mes antes del ingreso): se usa el mes actual, sin excepción. Lo cubre la Task 3 (`testVistaNormalizaMesInvalido`).
4. **Un registro de otro empleado en `corregirSalida`** (id manipulado en el formulario): se responde "Registro no encontrado." y no se escribe nada. Lo cubre la Task 3.
5. **Un día que el admin creó en un feriado y que además tiene registro:** el calendario lo muestra como asistió o sin salida, no como feriado. Lo cubre la Task 2 (`testRegistroGanaAlFeriado`).

---

### Task 1: Datos — columna `salida_manual`, repositorios y corrección del feriado nulo

**Files:**
- Create: `database/asistencia_salida_manual.sql`
- Modify: `database/schema.sql` (tabla `asistencia`, línea ~174)
- Modify: `src/Repositories/AsistenciaRepository.php`
- Modify: `src/Repositories/PeriodosRepository.php`
- Modify: `src/Repositories/FeriadosRepository.php`
- Modify: `src/Services/AsistenciaService.php` (método `validar`)
- Test: `tests/Services/AsistenciaServiceTest.php` (nuevo)

**Interfaces:**
- **Produces, `AsistenciaRepository`:**
  - `findByEmpleadoFecha(int $idEmpleado, string $fecha): ?array`
  - `findSinSalidaAntesDe(int $idEmpleado, string $fecha): array`
  - `findByEmpleadoMes(int $idEmpleado, string $desde, string $hasta): array`
  - `registrarSalida(int $id, string $horaSalida, float $horas, bool $manual): void`
  - `insert(array $d)` y `update(int $id, array $d)` aceptan la clave `salida_manual` (int 0/1; si falta, 0).
- **Produces, `PeriodosRepository`:** `findPorFecha(string $fecha): ?array` (claves `id_periodo`, `fecha_inicio`, `fecha_fin`, `estado`).
- **Produces, `FeriadosRepository`:** `findEntre(string $desde, string $hasta): array` (claves `fecha`, `nombre`).
- **Produces, `AsistenciaService`:** `crear`/`actualizar` guardan `id_feriado = 1` cuando no es feriado, y `salida_manual = 0`.

- [ ] **Step 1: Escribir la prueba que falla**

`tests/Services/AsistenciaServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AsistenciaRepository;
use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\PeriodosRepository;
use App\Services\AsistenciaService;
use PHPUnit\Framework\TestCase;

final class AsistenciaServiceTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $filaGuardada = null;

    private function service(?int $idFeriado): AsistenciaService
    {
        $repo = $this->createMock(AsistenciaRepository::class);
        $repo->method('findPeriodoAbiertoPorFecha')->willReturn(['id_periodo' => 4]);
        $repo->method('existsByEmpleadoFecha')->willReturn(false);
        $repo->method('findFeriadoIdPorFecha')->willReturn($idFeriado);
        $repo->method('findById')->willReturn(['id_asistencia' => 9]);
        $repo->method('insert')->willReturnCallback(function (array $d) {
            $this->filaGuardada = $d;
            return 9;
        });
        $repo->method('update')->willReturnCallback(function (int $id, array $d) {
            $this->filaGuardada = $d;
        });

        $empleados = $this->createMock(EmpleadosRepository::class);
        $empleados->method('findById')->willReturn(['id_empleado' => 2, 'estado' => 'activo']);

        return new AsistenciaService(
            $repo,
            $empleados,
            $this->createMock(PeriodosRepository::class),
            $this->createMock(AuditoriaRepository::class)
        );
    }

    /** @return array<string, string> */
    private function datos(): array
    {
        return ['id_empleado' => '2', 'fecha' => '2026-05-04', 'hora_entrada' => '08:00', 'hora_salida' => '17:00'];
    }

    public function testCrearEnDiaNormalUsaElFeriadoCentinela(): void
    {
        $this->service(null)->crear($this->datos(), 1, '127.0.0.1');

        self::assertSame(1, $this->filaGuardada['id_feriado']);
    }

    public function testCrearEnFeriadoUsaEseFeriado(): void
    {
        $this->service(5)->crear($this->datos(), 1, '127.0.0.1');

        self::assertSame(5, $this->filaGuardada['id_feriado']);
    }

    public function testActualizarDejaLaSalidaComoRevisadaPorRrhh(): void
    {
        $this->service(null)->actualizar(9, $this->datos(), 1, '127.0.0.1');

        self::assertSame(0, $this->filaGuardada['salida_manual']);
    }
}
```

- [ ] **Step 2: Correr y ver que falla**

Run: `vendor/bin/phpunit tests/Services/AsistenciaServiceTest.php`
Expected: FAIL. Las pruebas 1 y 3 fallan (`null` en vez de `1`; falta la clave `salida_manual`). La 2 pasa.

- [ ] **Step 3: Implementar en `AsistenciaService::validar`**

Reemplazar:

```php
        $idFeriado = $this->repo->findFeriadoIdPorFecha($fecha);
```

por:

```php
        // 1 = feriado centinela "No aplica" (el esquema no acepta NULL).
        $idFeriado = $this->repo->findFeriadoIdPorFecha($fecha) ?? 1;
```

y en el `return [...]` final agregar después de `'horas_trabajadas' => $horas,`:

```php
            'salida_manual'    => 0,
```

- [ ] **Step 4: Correr y ver que pasa**

Run: `vendor/bin/phpunit tests/Services/AsistenciaServiceTest.php`
Expected: `OK (3 tests, 3 assertions)`

- [ ] **Step 5: Esquema y migración**

En `database/schema.sql`, tabla `asistencia`, después de la línea de `horas_trabajadas` agregar:

```sql
    `salida_manual` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = la salida la escribió el empleado; 0 = la puso el servidor o RRHH',
```

Crear `database/asistencia_salida_manual.sql`:

```sql
-- ============================================================
-- Agrega asistencia.salida_manual (marcar asistencia desde el portal).
-- Ejecutar con: mysql -u root lubrimotos_nomina --default-character-set=utf8mb4 < asistencia_salida_manual.sql
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `asistencia`
    ADD COLUMN `salida_manual` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = la salida la escribió el empleado; 0 = la puso el servidor o RRHH'
        AFTER `horas_trabajadas`;
```

Aplicarla a la base local:

Run: `/c/xampp/mysql/bin/mysql.exe -h 127.0.0.1 -u root lubrimotos_nomina --default-character-set=utf8mb4 < database/asistencia_salida_manual.sql && /c/xampp/mysql/bin/mysql.exe -h 127.0.0.1 -u root lubrimotos_nomina -e "SHOW COLUMNS FROM asistencia LIKE 'salida_manual'"`
Expected: una fila con `salida_manual | tinyint(1) | NO | | 0`.

- [ ] **Step 6: Repositorios**

En `AsistenciaRepository`:

(a) **`findAll`:** en el SELECT agregar `a.salida_manual,` después de `a.horas_trabajadas,`, y cambiar el LEFT JOIN de feriados a:

```php
             LEFT JOIN feriados  f ON f.id_feriado  = a.id_feriado AND f.id_feriado <> 1";
```

(b) **`insert`:** columnas y valores:

```php
            'INSERT INTO asistencia
                (id_empleado, id_periodo, id_feriado, fecha, hora_entrada, hora_salida, horas_trabajadas, salida_manual)
             VALUES
                (:id_empleado, :id_periodo, :id_feriado, :fecha, :hora_entrada, :hora_salida, :horas_trabajadas, :salida_manual)'
```

(c) **`update`:** agregar `, salida_manual = :salida_manual` después de `horas_trabajadas = :horas_trabajadas`.

(d) **`bind`:** agregar:

```php
            ':salida_manual'    => (int)($d['salida_manual'] ?? 0),
```

(e) **Métodos nuevos** (antes de `bind`):

```php
    /**
     * @return array<string, mixed>|null
     */
    public function findByEmpleadoFecha(int $idEmpleado, string $fecha): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM asistencia WHERE id_empleado = :e AND fecha = :f LIMIT 1'
        );
        $stmt->execute([':e' => $idEmpleado, ':f' => $fecha]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Días anteriores a `$fecha` con entrada y sin salida.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findSinSalidaAntesDe(int $idEmpleado, string $fecha): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM asistencia
              WHERE id_empleado = :e AND fecha < :f AND hora_salida = '00:00:00'
              ORDER BY fecha"
        );
        $stmt->execute([':e' => $idEmpleado, ':f' => $fecha]);
        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByEmpleadoMes(int $idEmpleado, string $desde, string $hasta): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM asistencia
              WHERE id_empleado = :e AND fecha BETWEEN :desde AND :hasta
              ORDER BY fecha'
        );
        $stmt->execute([':e' => $idEmpleado, ':desde' => $desde, ':hasta' => $hasta]);
        return $stmt->fetchAll();
    }

    public function registrarSalida(int $id, string $horaSalida, float $horas, bool $manual): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE asistencia
                SET hora_salida = :salida, horas_trabajadas = :horas, salida_manual = :manual
              WHERE id_asistencia = :id'
        );
        $stmt->execute([':salida' => $horaSalida, ':horas' => $horas, ':manual' => $manual ? 1 : 0, ':id' => $id]);
    }
```

En `PeriodosRepository`, después de `findById`:

```php
    /**
     * Período (abierto o cerrado) que contiene la fecha, o null.
     *
     * @return array<string, mixed>|null
     */
    public function findPorFecha(string $fecha): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_periodo, fecha_inicio, fecha_fin, estado
               FROM periodos_pago
              WHERE :fecha BETWEEN fecha_inicio AND fecha_fin
              ORDER BY estado = \'abierto\' DESC
              LIMIT 1'
        );
        $stmt->execute([':fecha' => $fecha]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }
```

En `FeriadosRepository`, después de `findById`:

```php
    /**
     * @return array<int, array<string, mixed>>
     */
    public function findEntre(string $desde, string $hasta): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fecha, nombre FROM feriados WHERE fecha BETWEEN :desde AND :hasta ORDER BY fecha'
        );
        $stmt->execute([':desde' => $desde, ':hasta' => $hasta]);
        return $stmt->fetchAll();
    }
```

- [ ] **Step 7: Probar el SQL contra la base local** (los repositorios no tienen pruebas unitarias en este proyecto)

Crear `scratchpad/repo_check.php` en el scratchpad de la sesión (no en el repo):

```php
<?php
require 'C:/Users/Gmadr/OneDrive/Documentos/Proyecto_Graduacion/vendor/autoload.php';
$pdo = new PDO('mysql:host=127.0.0.1;dbname=lubrimotos_nomina;charset=utf8mb4', 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->beginTransaction();
$a = new App\Repositories\AsistenciaRepository($pdo);
$id = $a->insert(['id_empleado' => 2, 'id_periodo' => 1, 'id_feriado' => 1, 'fecha' => '2026-05-04',
    'hora_entrada' => '08:00:00', 'hora_salida' => '00:00:00', 'horas_trabajadas' => 0, 'salida_manual' => 0]);
var_dump($a->findByEmpleadoFecha(2, '2026-05-04')['id_asistencia'] == $id);
var_dump(count($a->findSinSalidaAntesDe(2, '2026-05-05')) === 1);
$a->registrarSalida($id, '17:00:00', 9.0, true);
var_dump($a->findById($id)['salida_manual'] == 1);
var_dump(count($a->findByEmpleadoMes(2, '2026-05-01', '2026-05-31')) === 1);
var_dump($a->findAll(null, 2)[0]['feriado_nombre'] === null);
var_dump((new App\Repositories\PeriodosRepository($pdo))->findPorFecha('2026-05-20')['estado'] === 'abierto');
var_dump(count((new App\Repositories\FeriadosRepository($pdo))->findEntre('2026-01-01', '2026-01-31')) >= 1);
$pdo->rollBack();
```

Run: `php <scratchpad>/repo_check.php`
Expected: 7 líneas `bool(true)`. La transacción se revierte, así que la base no cambia.

- [ ] **Step 8: Suite y commit**

Run: `vendor/bin/phpunit`
Expected: `OK (184 tests, ...)`

```bash
git add database/schema.sql database/asistencia_salida_manual.sql src/Repositories/AsistenciaRepository.php src/Repositories/PeriodosRepository.php src/Repositories/FeriadosRepository.php src/Services/AsistenciaService.php tests/Services/AsistenciaServiceTest.php
git commit -m "feat(asistencia): columna salida_manual, consultas por empleado y corrige el feriado nulo

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `CalendarioAsistencia` (función pura)

**Files:**
- Create: `src/Helpers/CalendarioAsistencia.php`
- Test: `tests/Helpers/CalendarioAsistenciaTest.php`

**Interfaces:**
- **Produces:**
  - `CalendarioAsistencia::construir(string $mes, array $registros, array $feriados, DateTimeImmutable $ingreso, DateTimeImmutable $hoy): array`
  - Devuelve `['semanas' => list<list<?array>>, 'resumen' => ['dias_asistidos' => int, 'horas' => float, 'sin_registro' => int]]`.
  - Cada día es `['fecha' => 'Y-m-d', 'numero' => int, 'estado' => string, 'es_hoy' => bool, 'registro' => ?array, 'feriado' => ?string]`.
  - Estados posibles: `antes_ingreso`, `futuro`, `asistio`, `sin_salida`, `feriado`, `libre`, `hoy`, `sin_registro`.
- **Produces:** `CalendarioAsistencia::tieneSalida(array $registro): bool` (estática). Es `true` si `hora_salida` no empieza con `00:00`.

- [ ] **Step 1: Escribir las pruebas que fallan**

`tests/Helpers/CalendarioAsistenciaTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Helpers\CalendarioAsistencia;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CalendarioAsistenciaTest extends TestCase
{
    /** @return array<string, mixed> */
    private function reg(string $fecha, string $salida = '17:00:00', string $horas = '9.00'): array
    {
        return ['id_asistencia' => 1, 'fecha' => $fecha, 'hora_entrada' => '08:00:00',
                'hora_salida' => $salida, 'horas_trabajadas' => $horas, 'salida_manual' => 0];
    }

    /** @return array<string, array<string, mixed>> fecha => día */
    private function dias(array $cal): array
    {
        $out = [];
        foreach ($cal['semanas'] as $semana) {
            foreach ($semana as $dia) {
                if ($dia !== null) {
                    $out[$dia['fecha']] = $dia;
                }
            }
        }
        return $out;
    }

    private function octubre(array $registros = [], array $feriados = [], string $ingreso = '2020-01-01'): array
    {
        return (new CalendarioAsistencia())->construir(
            '2026-10', $registros, $feriados,
            new DateTimeImmutable($ingreso), new DateTimeImmutable('2026-10-12')
        );
    }

    public function testOctubreEmpiezaEnJuevesConTresCasillasVacias(): void
    {
        $cal = $this->octubre();

        self::assertCount(5, $cal['semanas']);
        self::assertSame([null, null, null], array_slice($cal['semanas'][0], 0, 3));
        self::assertSame(1, $cal['semanas'][0][3]['numero']);
        foreach ($cal['semanas'] as $semana) {
            self::assertCount(7, $semana);
        }
    }

    public function testFebreroQueEmpiezaEnDomingo(): void
    {
        $cal = (new CalendarioAsistencia())->construir(
            '2026-02', [], [], new DateTimeImmutable('2020-01-01'), new DateTimeImmutable('2026-10-12')
        );
        $dias = $this->dias($cal);

        self::assertCount(28, $dias);
        self::assertSame(1, $cal['semanas'][0][6]['numero']);
        self::assertSame('libre', $dias['2026-02-01']['estado']);
    }

    public function testEstadosBasicos(): void
    {
        $dias = $this->dias($this->octubre(
            [$this->reg('2026-10-01'), $this->reg('2026-10-02', '00:00:00', '0.00')],
            ['2026-10-15' => 'Día de las Culturas']
        ));

        self::assertSame('asistio', $dias['2026-10-01']['estado']);
        self::assertSame('sin_salida', $dias['2026-10-02']['estado']);
        self::assertSame('sin_registro', $dias['2026-10-03']['estado']);   // sábado hábil
        self::assertSame('libre', $dias['2026-10-04']['estado']);          // domingo
        self::assertSame('hoy', $dias['2026-10-12']['estado']);
        self::assertTrue($dias['2026-10-12']['es_hoy']);
        self::assertSame('futuro', $dias['2026-10-13']['estado']);
        self::assertSame('futuro', $dias['2026-10-15']['estado']);        // el futuro gana al feriado
    }

    public function testFeriadoPasadoSinRegistro(): void
    {
        $dias = $this->dias($this->octubre([], ['2026-10-05' => 'Prueba']));

        self::assertSame('feriado', $dias['2026-10-05']['estado']);
        self::assertSame('Prueba', $dias['2026-10-05']['feriado']);
    }

    public function testRegistroGanaAlFeriado(): void
    {
        $dias = $this->dias($this->octubre([$this->reg('2026-10-05')], ['2026-10-05' => 'Prueba']));

        self::assertSame('asistio', $dias['2026-10-05']['estado']);
    }

    public function testIngresoAMitadDeMes(): void
    {
        $dias = $this->dias($this->octubre([], [], '2026-10-08'));

        self::assertSame('antes_ingreso', $dias['2026-10-07']['estado']);
        self::assertSame('sin_registro', $dias['2026-10-08']['estado']);
    }

    public function testHoyConEntradaSinSalidaEsSinSalida(): void
    {
        $dias = $this->dias($this->octubre([$this->reg('2026-10-12', '00:00:00', '0.00')]));

        self::assertSame('sin_salida', $dias['2026-10-12']['estado']);
        self::assertTrue($dias['2026-10-12']['es_hoy']);
    }

    public function testResumenCuentaSoloDiasCompletosYDiasSinRegistro(): void
    {
        $cal = $this->octubre(
            [$this->reg('2026-10-01', '17:00:00', '9.00'), $this->reg('2026-10-02', '12:00:00', '4.50'),
             $this->reg('2026-10-05', '00:00:00', '0.00')],
            [], '2026-10-01'
        );

        self::assertSame(2, $cal['resumen']['dias_asistidos']);
        self::assertSame(13.5, $cal['resumen']['horas']);
        // Hábiles pasados sin registro entre el 1 y el 11: 3, 6, 7, 8, 9, 10 = 6 (el 4 y el 11 son domingo).
        self::assertSame(6, $cal['resumen']['sin_registro']);
    }

    public function testTieneSalida(): void
    {
        self::assertTrue(CalendarioAsistencia::tieneSalida(['hora_salida' => '17:00:00']));
        self::assertFalse(CalendarioAsistencia::tieneSalida(['hora_salida' => '00:00:00']));
    }
}
```

- [ ] **Step 2: Correr y ver que falla**

Run: `vendor/bin/phpunit tests/Helpers/CalendarioAsistenciaTest.php`
Expected: ERROR `Class "App\Helpers\CalendarioAsistencia" not found` en las 9 pruebas.

- [ ] **Step 3: Implementar**

`src/Helpers/CalendarioAsistencia.php`:

```php
<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;

/**
 * Arma el calendario mensual de asistencia de un empleado (lunes a domingo)
 * y su resumen. Función pura: no toca la base de datos ni el reloj.
 * Días hábiles: lunes a sábado (el taller no tiene horarios todavía).
 */
final class CalendarioAsistencia
{
    /**
     * @param array<int, array<string, mixed>> $registros filas de asistencia del mes
     * @param array<string, string>            $feriados  fecha (Y-m-d) => nombre
     * @return array{semanas: array<int, array<int, array<string, mixed>|null>>,
     *               resumen: array{dias_asistidos: int, horas: float, sin_registro: int}}
     */
    public function construir(
        string $mes,
        array $registros,
        array $feriados,
        DateTimeImmutable $ingreso,
        DateTimeImmutable $hoy
    ): array {
        $primero    = new DateTimeImmutable($mes . '-01');
        $hoyStr     = $hoy->format('Y-m-d');
        $ingresoStr = $ingreso->format('Y-m-d');

        $porFecha = [];
        foreach ($registros as $r) {
            $porFecha[(string)$r['fecha']] = $r;
        }

        $celdas  = array_fill(0, (int)$primero->format('N') - 1, null);
        $resumen = ['dias_asistidos' => 0, 'horas' => 0.0, 'sin_registro' => 0];

        $total = (int)$primero->format('t');
        for ($n = 1; $n <= $total; $n++) {
            $dia    = $primero->setDate((int)$primero->format('Y'), (int)$primero->format('n'), $n);
            $fecha  = $dia->format('Y-m-d');
            $reg    = $porFecha[$fecha] ?? null;
            $estado = $this->estado($fecha, $reg, $feriados, $ingresoStr, $hoyStr, (int)$dia->format('N'));

            if ($estado === 'asistio') {
                $resumen['dias_asistidos']++;
                $resumen['horas'] += (float)$reg['horas_trabajadas'];
            } elseif ($estado === 'sin_registro') {
                $resumen['sin_registro']++;
            }

            $celdas[] = [
                'fecha'    => $fecha,
                'numero'   => $n,
                'estado'   => $estado,
                'es_hoy'   => $fecha === $hoyStr,
                'registro' => $reg,
                'feriado'  => $feriados[$fecha] ?? null,
            ];
        }

        while (count($celdas) % 7 !== 0) {
            $celdas[] = null;
        }
        $resumen['horas'] = round($resumen['horas'], 2);

        return ['semanas' => array_chunk($celdas, 7), 'resumen' => $resumen];
    }

    /** @param array<string, mixed> $registro */
    public static function tieneSalida(array $registro): bool
    {
        return substr((string)$registro['hora_salida'], 0, 5) !== '00:00';
    }

    /**
     * @param array<string, mixed>|null $reg
     * @param array<string, string>     $feriados
     */
    private function estado(string $fecha, ?array $reg, array $feriados, string $ingreso, string $hoy, int $diaSemana): string
    {
        if ($fecha < $ingreso) {
            return 'antes_ingreso';
        }
        if ($fecha > $hoy) {
            return 'futuro';
        }
        if ($reg !== null) {
            return self::tieneSalida($reg) ? 'asistio' : 'sin_salida';
        }
        if (isset($feriados[$fecha])) {
            return 'feriado';
        }
        if ($diaSemana === 7) {
            return 'libre';
        }
        return $fecha === $hoy ? 'hoy' : 'sin_registro';
    }
}
```

- [ ] **Step 4: Correr y ver que pasa**

Run: `vendor/bin/phpunit tests/Helpers/CalendarioAsistenciaTest.php`
Expected: `OK (9 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add src/Helpers/CalendarioAsistencia.php tests/Helpers/CalendarioAsistenciaTest.php
git commit -m "feat(asistencia): calendario mensual de asistencia con resumen

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `MarcaAsistenciaService` (reglas)

**Files:**
- Create: `src/Services/MarcaAsistenciaService.php`
- Modify: `config/dependencies.php` (registrar el service)
- Test: `tests/Services/MarcaAsistenciaServiceTest.php`

**Interfaces:**
- **Consumes:**
  - Task 1: métodos de `AsistenciaRepository` (`findByEmpleadoFecha`, `findSinSalidaAntesDe`, `findByEmpleadoMes`, `registrarSalida`, `insert`, `findById`, `findFeriadoIdPorFecha`), `PeriodosRepository::findPorFecha` / `findById` / `findOverlapping` / `insert`, y `FeriadosRepository::findEntre`.
  - Task 2: `CalendarioAsistencia::construir` y `tieneSalida`.
- **Produces** (clase **no** `final`, para poder simularla en la Task 4):
  - `MarcaAsistenciaService::vista(int $idEmpleado, ?string $mes, DateTimeImmutable $ahora): array`. Claves:
    - `hoy`: lo que devuelve `estadoDeHoy`.
    - `calendario`: lo que devuelve `construir`.
    - `mes`: `'Y-m'`.
    - `mesTexto`: por ejemplo `'Octubre 2026'`.
    - `mesAnterior` y `mesSiguiente`: `?string`.
    - `activo`: `bool`.
  - `estadoDeHoy(int $idEmpleado, DateTimeImmutable $ahora): array`. Claves: `estado` (`'sin_entrada'|'con_entrada'|'completo'`), `registro` (`?array`), `pendientes` (`array`).
  - `marcarEntrada(int $idEmpleado, DateTimeImmutable $ahora, int $idUsuario, string $ip): void`
  - `marcarSalida(int $idEmpleado, DateTimeImmutable $ahora, int $idUsuario, string $ip): void`
  - `corregirSalida(int $idEmpleado, int $idAsistencia, string $horaSalida, DateTimeImmutable $ahora, int $idUsuario, string $ip): void`
  - Los errores se lanzan como `InvalidArgumentException` con el mensaje del spec.

- [ ] **Step 1: Escribir las pruebas que fallan**

`tests/Services/MarcaAsistenciaServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\CalendarioAsistencia;
use App\Repositories\AsistenciaRepository;
use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\FeriadosRepository;
use App\Repositories\PeriodosRepository;
use App\Services\MarcaAsistenciaService;
use DateTimeImmutable;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MarcaAsistenciaServiceTest extends TestCase
{
    private AsistenciaRepository&MockObject $asistencia;
    private PeriodosRepository&MockObject $periodos;
    private EmpleadosRepository&MockObject $empleados;
    private FeriadosRepository&MockObject $feriados;
    private AuditoriaRepository&MockObject $auditoria;

    protected function setUp(): void
    {
        $this->asistencia = $this->createMock(AsistenciaRepository::class);
        $this->periodos   = $this->createMock(PeriodosRepository::class);
        $this->empleados  = $this->createMock(EmpleadosRepository::class);
        $this->feriados   = $this->createMock(FeriadosRepository::class);
        $this->auditoria  = $this->createMock(AuditoriaRepository::class);
        $this->empleados->method('findById')->willReturn(
            ['id_empleado' => 5, 'estado' => 'activo', 'fecha_ingreso' => '2019-02-04']
        );
    }

    private function service(): MarcaAsistenciaService
    {
        return new MarcaAsistenciaService(
            $this->asistencia, $this->periodos, $this->empleados,
            $this->feriados, $this->auditoria, new CalendarioAsistencia()
        );
    }

    private function ahora(string $cuando = '2026-10-12 07:58:30'): DateTimeImmutable
    {
        return new DateTimeImmutable($cuando);
    }

    /** @return array<string, mixed> */
    private function registro(array $cambios = []): array
    {
        return $cambios + ['id_asistencia' => 30, 'id_empleado' => 5, 'id_periodo' => 9, 'fecha' => '2026-10-12',
                           'hora_entrada' => '07:58:00', 'hora_salida' => '00:00:00', 'horas_trabajadas' => '0.00',
                           'salida_manual' => 0];
    }

    // ── Entrada ──────────────────────────────────────────────

    public function testMarcarEntradaGuardaLaHoraDelServidorEnElPeriodoAbierto(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn(null);
        $this->asistencia->method('findFeriadoIdPorFecha')->willReturn(null);
        $this->periodos->method('findPorFecha')->willReturn(['id_periodo' => 9, 'estado' => 'abierto']);
        $this->periodos->expects(self::never())->method('insert');
        $this->asistencia->expects(self::once())->method('insert')->with([
            'id_empleado' => 5, 'id_periodo' => 9, 'id_feriado' => 1, 'fecha' => '2026-10-12',
            'hora_entrada' => '07:58:00', 'hora_salida' => '00:00:00', 'horas_trabajadas' => 0.0, 'salida_manual' => 0,
        ])->willReturn(30);
        $this->auditoria->expects(self::once())->method('insert')->with('INSERT', 3, 'asistencia', 30, '1.2.3.4');

        $this->service()->marcarEntrada(5, $this->ahora(), 3, '1.2.3.4');
    }

    public function testMarcarEntradaDuplicadaSeRechaza(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn($this->registro());
        $this->asistencia->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ya marcó su entrada hoy a las 07:58.');
        $this->service()->marcarEntrada(5, $this->ahora(), 3, 'ip');
    }

    public function testMarcarEntradaTraduceDuplicadoDeLaBase(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn(null);
        $this->periodos->method('findPorFecha')->willReturn(['id_periodo' => 9, 'estado' => 'abierto']);
        $this->asistencia->method('insert')->willThrowException(new PDOException('Duplicate entry', 23000));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ya marcó su entrada hoy.');
        $this->service()->marcarEntrada(5, $this->ahora(), 3, 'ip');
    }

    public function testMarcarEntradaCreaLaPrimeraQuincena(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn(null);
        $this->periodos->method('findPorFecha')->willReturn(null);
        $this->periodos->method('findOverlapping')->willReturn([]);
        $this->periodos->expects(self::once())->method('insert')->with('2026-10-01', '2026-10-15')->willReturn(12);
        $this->asistencia->expects(self::once())->method('insert')
            ->with(self::callback(fn (array $d) => $d['id_periodo'] === 12))->willReturn(30);

        $this->service()->marcarEntrada(5, $this->ahora(), 3, 'ip');
    }

    public function testMarcarEntradaCreaLaSegundaQuincenaHastaFinDeMes(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn(null);
        $this->periodos->method('findPorFecha')->willReturn(null);
        $this->periodos->method('findOverlapping')->willReturn([]);
        $this->periodos->expects(self::once())->method('insert')->with('2026-02-16', '2026-02-28')->willReturn(13);
        $this->asistencia->method('insert')->willReturn(30);

        $this->service()->marcarEntrada(5, $this->ahora('2026-02-20 08:00:00'), 3, 'ip');
    }

    public function testMarcarEntradaRechazaPeriodoCerrado(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn(null);
        $this->periodos->method('findPorFecha')->willReturn(['id_periodo' => 9, 'estado' => 'cerrado']);
        $this->asistencia->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('El período de pago de hoy ya está cerrado; avise a RRHH.');
        $this->service()->marcarEntrada(5, $this->ahora(), 3, 'ip');
    }

    public function testMarcarEntradaRechazaSiLaQuincenaChocaConOtroPeriodo(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn(null);
        $this->periodos->method('findPorFecha')->willReturn(null);
        $this->periodos->method('findOverlapping')->willReturn([['id_periodo' => 7]]);
        $this->periodos->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No hay un período de pago para hoy; avise a RRHH.');
        $this->service()->marcarEntrada(5, $this->ahora(), 3, 'ip');
    }

    public function testEmpleadoInactivoNoPuedeMarcar(): void
    {
        $empleados = $this->createMock(EmpleadosRepository::class);
        $empleados->method('findById')->willReturn(['id_empleado' => 5, 'estado' => 'inactivo', 'fecha_ingreso' => '2019-02-04']);
        $this->empleados = $empleados;
        $this->asistencia->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Su empleado está inactivo; no puede marcar asistencia.');
        $this->service()->marcarEntrada(5, $this->ahora(), 3, 'ip');
    }

    // ── Salida ───────────────────────────────────────────────

    public function testMarcarSalidaCalculaLasHoras(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn($this->registro());
        $this->asistencia->expects(self::once())->method('registrarSalida')->with(30, '17:13:00', 9.25, false);
        $this->auditoria->expects(self::once())->method('insert')->with('UPDATE', 3, 'asistencia', 30, 'ip');

        $this->service()->marcarSalida(5, $this->ahora('2026-10-12 17:13:40'), 3, 'ip');
    }

    public function testMarcarSalidaSinEntrada(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Primero marque su entrada.');
        $this->service()->marcarSalida(5, $this->ahora(), 3, 'ip');
    }

    public function testMarcarSalidaDosVeces(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn($this->registro(['hora_salida' => '17:00:00']));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ya marcó su salida hoy a las 17:00.');
        $this->service()->marcarSalida(5, $this->ahora(), 3, 'ip');
    }

    public function testMarcarSalidaEnElMismoMinuto(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn($this->registro());
        $this->asistencia->expects(self::never())->method('registrarSalida');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Espere al menos un minuto para marcar la salida.');
        $this->service()->marcarSalida(5, $this->ahora('2026-10-12 07:58:59'), 3, 'ip');
    }

    // ── Corregir salida olvidada ─────────────────────────────

    private function diaOlvidado(array $cambios = []): void
    {
        $this->asistencia->method('findById')->willReturn($this->registro($cambios + ['fecha' => '2026-10-08']));
        $this->periodos->method('findById')->willReturn(['id_periodo' => 9, 'estado' => 'abierto']);
    }

    public function testCorregirSalidaGuardaComoManual(): void
    {
        $this->diaOlvidado();
        $this->asistencia->expects(self::once())->method('registrarSalida')->with(30, '17:00:00', 9.03, true);
        $this->auditoria->expects(self::once())->method('insert')->with('UPDATE', 3, 'asistencia', 30, 'ip');

        $this->service()->corregirSalida(5, 30, '17:00', $this->ahora(), 3, 'ip');
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function correccionesInvalidas(): array
    {
        return [
            'de otro empleado'   => [['id_empleado' => 99], '17:00', 'Registro no encontrado.'],
            'del día de hoy'     => [['fecha' => '2026-10-12'], '17:00', 'La salida de hoy se marca con el botón.'],
            'ya tiene salida'    => [['hora_salida' => '16:00:00'], '17:00', 'Ese día ya tiene salida.'],
            'antes de entrada'   => [[], '07:30', 'La hora de salida debe ser posterior a la entrada (HH:MM).'],
            'formato inválido'   => [[], '25:00', 'La hora de salida debe ser posterior a la entrada (HH:MM).'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('correccionesInvalidas')]
    public function testCorregirSalidaRechaza(array $cambios, string $hora, string $mensaje): void
    {
        $this->diaOlvidado($cambios);
        $this->asistencia->expects(self::never())->method('registrarSalida');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($mensaje);
        $this->service()->corregirSalida(5, 30, $hora, $this->ahora(), 3, 'ip');
    }

    public function testCorregirSalidaRechazaPeriodoCerrado(): void
    {
        $this->asistencia->method('findById')->willReturn($this->registro(['fecha' => '2026-10-08']));
        $this->periodos->method('findById')->willReturn(['id_periodo' => 9, 'estado' => 'cerrado']);
        $this->asistencia->expects(self::never())->method('registrarSalida');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ese período ya se cerró; pida a RRHH que lo corrija.');
        $this->service()->corregirSalida(5, 30, '17:00', $this->ahora(), 3, 'ip');
    }

    public function testCorregirSalidaDeRegistroInexistente(): void
    {
        $this->asistencia->method('findById')->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Registro no encontrado.');
        $this->service()->corregirSalida(5, 999, '17:00', $this->ahora(), 3, 'ip');
    }

    // ── Estado de hoy y vista ────────────────────────────────

    public function testEstadoDeHoy(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturnOnConsecutiveCalls(
            null, $this->registro(), $this->registro(['hora_salida' => '17:00:00'])
        );
        $this->asistencia->method('findSinSalidaAntesDe')->willReturn([$this->registro(['fecha' => '2026-10-08'])]);
        $s = $this->service();

        self::assertSame('sin_entrada', $s->estadoDeHoy(5, $this->ahora())['estado']);
        self::assertSame('con_entrada', $s->estadoDeHoy(5, $this->ahora())['estado']);
        $completo = $s->estadoDeHoy(5, $this->ahora());
        self::assertSame('completo', $completo['estado']);
        self::assertCount(1, $completo['pendientes']);
    }

    public function testVistaArmaElMesActualConNavegacion(): void
    {
        $this->asistencia->method('findByEmpleadoMes')->with(5, '2026-10-01', '2026-10-31')->willReturn([]);
        $this->asistencia->method('findSinSalidaAntesDe')->willReturn([]);
        $this->feriados->method('findEntre')->willReturn([['fecha' => '2026-10-05', 'nombre' => 'Prueba']]);

        $v = $this->service()->vista(5, null, $this->ahora());

        self::assertSame('2026-10', $v['mes']);
        self::assertSame('Octubre 2026', $v['mesTexto']);
        self::assertSame('2026-09', $v['mesAnterior']);
        self::assertNull($v['mesSiguiente']);
        self::assertTrue($v['activo']);
        self::assertArrayHasKey('resumen', $v['calendario']);
    }

    /** @return array<string, array{?string}> */
    public static function mesesInvalidos(): array
    {
        return ['texto' => ['abc'], 'mes 13' => ['2026-13'], 'futuro' => ['2026-11'], 'antes del ingreso' => ['2019-01']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mesesInvalidos')]
    public function testVistaNormalizaMesInvalido(?string $mes): void
    {
        $this->asistencia->method('findByEmpleadoMes')->willReturn([]);
        $this->asistencia->method('findSinSalidaAntesDe')->willReturn([]);
        $this->feriados->method('findEntre')->willReturn([]);

        self::assertSame('2026-10', $this->service()->vista(5, $mes, $this->ahora())['mes']);
    }

    public function testVistaNoOfreceMesAnteriorAlIngreso(): void
    {
        $empleados = $this->createMock(EmpleadosRepository::class);
        $empleados->method('findById')->willReturn(['id_empleado' => 5, 'estado' => 'activo', 'fecha_ingreso' => '2026-09-15']);
        $this->empleados = $empleados;
        $this->asistencia->method('findByEmpleadoMes')->willReturn([]);
        $this->asistencia->method('findSinSalidaAntesDe')->willReturn([]);
        $this->feriados->method('findEntre')->willReturn([]);

        $v = $this->service()->vista(5, '2026-09', $this->ahora());

        self::assertSame('2026-09', $v['mes']);
        self::assertNull($v['mesAnterior']);
        self::assertSame('2026-10', $v['mesSiguiente']);
    }
}
```

- [ ] **Step 2: Correr y ver que falla**

Run: `vendor/bin/phpunit tests/Services/MarcaAsistenciaServiceTest.php`
Expected: ERROR `Class "App\Services\MarcaAsistenciaService" not found`.

- [ ] **Step 3: Implementar**

`src/Services/MarcaAsistenciaService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\CalendarioAsistencia;
use App\Repositories\AsistenciaRepository;
use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\FeriadosRepository;
use App\Repositories\PeriodosRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

/**
 * MarcaAsistenciaService — el empleado marca su entrada y su salida desde el portal.
 * La hora siempre llega como parámetro ($ahora): es la del servidor y nunca la del formulario,
 * salvo en corregirSalida, donde el empleado escribe la salida que olvidó (queda salida_manual = 1).
 */
class MarcaAsistenciaService
{
    private const MESES = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                           'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    public function __construct(
        private readonly AsistenciaRepository $repo,
        private readonly PeriodosRepository   $periodosRepo,
        private readonly EmpleadosRepository  $empleadosRepo,
        private readonly FeriadosRepository   $feriadosRepo,
        private readonly AuditoriaRepository  $auditoriaRepo,
        private readonly CalendarioAsistencia $calendario
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function vista(int $idEmpleado, ?string $mes, DateTimeImmutable $ahora): array
    {
        $empleado   = $this->empleado($idEmpleado);
        $ingreso    = new DateTimeImmutable((string)$empleado['fecha_ingreso']);
        $mesActual  = $ahora->format('Y-m');
        $mesIngreso = $ingreso->format('Y-m');

        $valido = $mes !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) === 1
            && $mes <= $mesActual && $mes >= $mesIngreso;
        $mes = $valido ? $mes : $mesActual;

        $primero = new DateTimeImmutable($mes . '-01');
        $desde   = $primero->format('Y-m-d');
        $hasta   = $primero->format('Y-m-t');

        $feriados = [];
        foreach ($this->feriadosRepo->findEntre($desde, $hasta) as $f) {
            $feriados[(string)$f['fecha']] = (string)$f['nombre'];
        }

        $anterior  = $primero->modify('-1 month')->format('Y-m');
        $siguiente = $primero->modify('+1 month')->format('Y-m');

        return [
            'hoy'          => $this->estadoDeHoy($idEmpleado, $ahora),
            'calendario'   => $this->calendario->construir(
                $mes, $this->repo->findByEmpleadoMes($idEmpleado, $desde, $hasta), $feriados, $ingreso, $ahora
            ),
            'mes'          => $mes,
            'mesTexto'     => self::MESES[(int)$primero->format('n')] . ' ' . $primero->format('Y'),
            'mesAnterior'  => $anterior >= $mesIngreso ? $anterior : null,
            'mesSiguiente' => $siguiente <= $mesActual ? $siguiente : null,
            'activo'       => ($empleado['estado'] ?? '') === 'activo',
        ];
    }

    /**
     * @return array{estado: string, registro: array<string, mixed>|null, pendientes: array<int, array<string, mixed>>}
     */
    public function estadoDeHoy(int $idEmpleado, DateTimeImmutable $ahora): array
    {
        $hoy = $ahora->format('Y-m-d');
        $reg = $this->repo->findByEmpleadoFecha($idEmpleado, $hoy);

        return [
            'estado'     => $reg === null ? 'sin_entrada' : (CalendarioAsistencia::tieneSalida($reg) ? 'completo' : 'con_entrada'),
            'registro'   => $reg,
            'pendientes' => $this->repo->findSinSalidaAntesDe($idEmpleado, $hoy),
        ];
    }

    public function marcarEntrada(int $idEmpleado, DateTimeImmutable $ahora, int $idUsuario, string $ip): void
    {
        $this->exigirActivo($idEmpleado);
        $hoy = $ahora->format('Y-m-d');

        $existente = $this->repo->findByEmpleadoFecha($idEmpleado, $hoy);
        if ($existente !== null) {
            throw new InvalidArgumentException(
                sprintf('Ya marcó su entrada hoy a las %s.', substr((string)$existente['hora_entrada'], 0, 5))
            );
        }

        $idPeriodo = $this->periodoParaHoy($ahora, $idUsuario, $ip);

        try {
            $id = $this->repo->insert([
                'id_empleado'      => $idEmpleado,
                'id_periodo'       => $idPeriodo,
                'id_feriado'       => $this->repo->findFeriadoIdPorFecha($hoy) ?? 1,
                'fecha'            => $hoy,
                'hora_entrada'     => $ahora->format('H:i:00'),
                'hora_salida'      => '00:00:00',
                'horas_trabajadas' => 0.0,
                'salida_manual'    => 0,
            ]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                throw new InvalidArgumentException('Ya marcó su entrada hoy.');
            }
            throw $e;
        }
        $this->auditoriaRepo->insert('INSERT', $idUsuario, 'asistencia', $id, $ip);
    }

    public function marcarSalida(int $idEmpleado, DateTimeImmutable $ahora, int $idUsuario, string $ip): void
    {
        $this->exigirActivo($idEmpleado);

        $reg = $this->repo->findByEmpleadoFecha($idEmpleado, $ahora->format('Y-m-d'));
        if ($reg === null) {
            throw new InvalidArgumentException('Primero marque su entrada.');
        }
        if (CalendarioAsistencia::tieneSalida($reg)) {
            throw new InvalidArgumentException(
                sprintf('Ya marcó su salida hoy a las %s.', substr((string)$reg['hora_salida'], 0, 5))
            );
        }

        $salida = $ahora->format('H:i:00');
        $horas  = $this->horas((string)$reg['hora_entrada'], $salida);
        if ($horas <= 0) {
            throw new InvalidArgumentException('Espere al menos un minuto para marcar la salida.');
        }

        $this->repo->registrarSalida((int)$reg['id_asistencia'], $salida, $horas, false);
        $this->auditoriaRepo->insert('UPDATE', $idUsuario, 'asistencia', (int)$reg['id_asistencia'], $ip);
    }

    public function corregirSalida(
        int $idEmpleado,
        int $idAsistencia,
        string $horaSalida,
        DateTimeImmutable $ahora,
        int $idUsuario,
        string $ip
    ): void {
        $this->exigirActivo($idEmpleado);

        $reg = $this->repo->findById($idAsistencia);
        if ($reg === null || (int)$reg['id_empleado'] !== $idEmpleado) {
            throw new InvalidArgumentException('Registro no encontrado.');
        }
        if ((string)$reg['fecha'] >= $ahora->format('Y-m-d')) {
            throw new InvalidArgumentException('La salida de hoy se marca con el botón.');
        }
        if (CalendarioAsistencia::tieneSalida($reg)) {
            throw new InvalidArgumentException('Ese día ya tiene salida.');
        }

        $horaSalida = trim($horaSalida);
        $horas = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaSalida) === 1
            ? $this->horas((string)$reg['hora_entrada'], $horaSalida . ':00')
            : 0.0;
        if ($horas <= 0) {
            throw new InvalidArgumentException('La hora de salida debe ser posterior a la entrada (HH:MM).');
        }

        $periodo = $this->periodosRepo->findById((int)$reg['id_periodo']);
        if ($periodo === null || $periodo['estado'] !== 'abierto') {
            throw new InvalidArgumentException('Ese período ya se cerró; pida a RRHH que lo corrija.');
        }

        $this->repo->registrarSalida($idAsistencia, $horaSalida . ':00', $horas, true);
        $this->auditoriaRepo->insert('UPDATE', $idUsuario, 'asistencia', $idAsistencia, $ip);
    }

    /** @return array<string, mixed> */
    private function empleado(int $idEmpleado): array
    {
        $empleado = $this->empleadosRepo->findById($idEmpleado);
        if ($empleado === null) {
            throw new RuntimeException('Empleado no encontrado.');
        }
        return $empleado;
    }

    private function exigirActivo(int $idEmpleado): void
    {
        if (($this->empleado($idEmpleado)['estado'] ?? '') !== 'activo') {
            throw new InvalidArgumentException('Su empleado está inactivo; no puede marcar asistencia.');
        }
    }

    /**
     * Período abierto que contiene hoy; si no existe ninguno, abre la quincena (1–15 o 16–fin de mes).
     */
    private function periodoParaHoy(DateTimeImmutable $ahora, int $idUsuario, string $ip): int
    {
        $periodo = $this->periodosRepo->findPorFecha($ahora->format('Y-m-d'));
        if ($periodo !== null) {
            if ($periodo['estado'] !== 'abierto') {
                throw new InvalidArgumentException('El período de pago de hoy ya está cerrado; avise a RRHH.');
            }
            return (int)$periodo['id_periodo'];
        }

        $primeraQuincena = (int)$ahora->format('j') <= 15;
        $inicio = $ahora->format('Y-m-') . ($primeraQuincena ? '01' : '16');
        $fin    = $primeraQuincena ? $ahora->format('Y-m-15') : $ahora->format('Y-m-t');

        if ($this->periodosRepo->findOverlapping($inicio, $fin) !== []) {
            throw new InvalidArgumentException('No hay un período de pago para hoy; avise a RRHH.');
        }

        $id = $this->periodosRepo->insert($inicio, $fin);
        $this->auditoriaRepo->insert('INSERT', $idUsuario, 'periodos_pago', $id, $ip);
        return $id;
    }

    /** Horas entre dos horas del mismo día (H:i:s), redondeadas a 2 decimales. */
    private function horas(string $entrada, string $salida): float
    {
        $e = DateTimeImmutable::createFromFormat('!H:i:s', $entrada);
        $s = DateTimeImmutable::createFromFormat('!H:i:s', $salida);
        if ($e === false || $s === false) {
            return 0.0;
        }
        return round(($s->getTimestamp() - $e->getTimestamp()) / 3600, 2);
    }
}
```

- [ ] **Step 4: Correr y ver que pasa**

Run: `vendor/bin/phpunit tests/Services/MarcaAsistenciaServiceTest.php`
Expected: `OK` (las 24 ejecuciones, contando cada caso de los data providers).

- [ ] **Step 5: Registrar en el contenedor**

En `config/dependencies.php`, después de la entrada de `\App\Services\AsistenciaService::class`:

```php
    \App\Services\MarcaAsistenciaService::class => function (ContainerInterface $c) {
        return new \App\Services\MarcaAsistenciaService(
            $c->get(\App\Repositories\AsistenciaRepository::class),
            $c->get(\App\Repositories\PeriodosRepository::class),
            $c->get(\App\Repositories\EmpleadosRepository::class),
            $c->get(\App\Repositories\FeriadosRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class),
            new \App\Helpers\CalendarioAsistencia()
        );
    },
```

Run: `php -l config/dependencies.php && vendor/bin/phpunit`
Expected: `No syntax errors detected` y la suite en `OK`.

- [ ] **Step 6: Commit**

```bash
git add src/Services/MarcaAsistenciaService.php tests/Services/MarcaAsistenciaServiceTest.php config/dependencies.php
git commit -m "feat(asistencia): reglas para marcar entrada, salida y corregir salida olvidada

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `MiAsistenciaController` y rutas

**Files:**
- Create: `src/Controllers/MiAsistenciaController.php`
- Modify: `config/routes.php` (líneas 223-224)
- Modify: `config/dependencies.php` (registrar el controller)
- Modify: `src/Controllers/PortalController.php` (borrar el método `asistencia`)
- Modify: `src/Services/PortalService.php` (borrar el método `asistencia`)
- Modify: `src/Repositories/PortalRepository.php` (borrar `asistencia` y `periodosConAsistencia`)
- Test: `tests/Controllers/MiAsistenciaControllerTest.php`

**Interfaces:**
- **Consumes:**
  - `PortalService::idEmpleado(int $idUsuario): ?int`, que ya existe.
  - `MarcaAsistenciaService` de la Task 3.
- **Produces:**
  - **Rutas:** `portal.asistencia` (GET `/mi-asistencia`), `portal.asistencia.entrada` (POST `/mi-asistencia/entrada`), `portal.asistencia.salida` (POST `/mi-asistencia/salida`) y `portal.asistencia.corregir` (POST `/mi-asistencia/{id}/salida`).
  - **Variables para la Twig de la Task 5:** `titulo`, `vinculado` (bool), `flashSuccess`, `flashError`, y cuando hay vínculo, las claves de `vista()`: `hoy`, `calendario`, `mes`, `mesTexto`, `mesAnterior`, `mesSiguiente`, `activo`.

- [ ] **Step 1: Escribir las pruebas que fallan**

`tests/Controllers/MiAsistenciaControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\MiAsistenciaController;
use App\Services\MarcaAsistenciaService;
use App\Services\PortalService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Routing\RouteContext;
use Slim\Routing\RoutingResults;
use Slim\Views\Twig;

final class MiAsistenciaControllerTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $vista = null;

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    private function request(string $metodo, array $cuerpo = [], array $query = []): ServerRequestInterface
    {
        $parser = $this->createMock(RouteParserInterface::class);
        $parser->method('urlFor')->willReturnCallback(fn (string $nombre) => '/' . $nombre);

        return (new ServerRequestFactory())
            ->createServerRequest($metodo, '/mi-asistencia')
            ->withAttribute('usuario', ['id' => 7, 'nombre' => 'cjimenez', 'rol' => 'empleado'])
            ->withAttribute(RouteContext::ROUTE_PARSER, $parser)
            ->withAttribute(RouteContext::ROUTING_RESULTS, $this->createMock(RoutingResults::class))
            ->withParsedBody($cuerpo)
            ->withQueryParams($query);
    }

    private function controller(?int $idEmpleado, MarcaAsistenciaService $marcas): MiAsistenciaController
    {
        $portal = $this->createMock(PortalService::class);
        $portal->method('idEmpleado')->with(7)->willReturn($idEmpleado);

        $twig = $this->createMock(Twig::class);
        $twig->method('render')->willReturnCallback(function (ResponseInterface $r, string $tpl, array $datos) {
            $this->vista = $datos;
            return $r;
        });

        return new MiAsistenciaController($twig, $portal, $marcas, fn () => new DateTimeImmutable('2026-10-12 08:00:00'));
    }

    public function testIndexSinVinculoNoPideLaVista(): void
    {
        $marcas = $this->createMock(MarcaAsistenciaService::class);
        $marcas->expects(self::never())->method('vista');

        $this->controller(null, $marcas)->index($this->request('GET'), (new ResponseFactory())->createResponse());

        self::assertFalse($this->vista['vinculado']);
    }

    public function testIndexPasaElMesDeLaUrl(): void
    {
        $marcas = $this->createMock(MarcaAsistenciaService::class);
        $marcas->expects(self::once())->method('vista')
            ->with(4, '2026-09', self::isInstanceOf(DateTimeImmutable::class))
            ->willReturn(['mes' => '2026-09']);

        $this->controller(4, $marcas)->index($this->request('GET', [], ['mes' => '2026-09']), (new ResponseFactory())->createResponse());

        self::assertTrue($this->vista['vinculado']);
        self::assertSame('2026-09', $this->vista['mes']);
    }

    public function testEntradaUsaElEmpleadoDelUsuarioYRedirige(): void
    {
        $marcas = $this->createMock(MarcaAsistenciaService::class);
        $marcas->expects(self::once())->method('marcarEntrada')
            ->with(4, new DateTimeImmutable('2026-10-12 08:00:00'), 7, self::isString());

        $resp = $this->controller(4, $marcas)->entrada(
            $this->request('POST', ['id_empleado' => '1']),   // un id en el formulario se ignora
            (new ResponseFactory())->createResponse()
        );

        self::assertSame(302, $resp->getStatusCode());
        self::assertSame('/portal.asistencia', $resp->getHeaderLine('Location'));
        self::assertSame('Entrada registrada a las 08:00.', $_SESSION['flash_success']);
    }

    public function testSalidaConErrorDejaFlashDeError(): void
    {
        $marcas = $this->createMock(MarcaAsistenciaService::class);
        $marcas->method('marcarSalida')->willThrowException(new InvalidArgumentException('Primero marque su entrada.'));

        $resp = $this->controller(4, $marcas)->salida($this->request('POST'), (new ResponseFactory())->createResponse());

        self::assertSame(302, $resp->getStatusCode());
        self::assertSame('Primero marque su entrada.', $_SESSION['flash_error']);
    }

    public function testPostSinVinculoNoMarca(): void
    {
        $marcas = $this->createMock(MarcaAsistenciaService::class);
        $marcas->expects(self::never())->method('marcarEntrada');

        $resp = $this->controller(null, $marcas)->entrada($this->request('POST'), (new ResponseFactory())->createResponse());

        self::assertSame(302, $resp->getStatusCode());
        self::assertSame('Su usuario no está vinculado a un empleado.', $_SESSION['flash_error']);
    }

    public function testCorregirPasaElIdYLaHora(): void
    {
        $marcas = $this->createMock(MarcaAsistenciaService::class);
        $marcas->expects(self::once())->method('corregirSalida')
            ->with(4, 30, '17:05', self::isInstanceOf(DateTimeImmutable::class), 7, self::isString());

        $this->controller(4, $marcas)->corregir(
            $this->request('POST', ['hora_salida' => '17:05']),
            (new ResponseFactory())->createResponse(),
            ['id' => '30']
        );

        self::assertSame('Salida guardada.', $_SESSION['flash_success']);
    }
}
```

- [ ] **Step 2: Correr y ver que falla**

Run: `vendor/bin/phpunit tests/Controllers/MiAsistenciaControllerTest.php`
Expected: ERROR `Class "App\Controllers\MiAsistenciaController" not found`.

- [ ] **Step 3: Implementar el controller**

`src/Controllers/MiAsistenciaController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\MarcaAsistenciaService;
use App\Services\PortalService;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

/**
 * MiAsistenciaController — el empleado marca su asistencia y ve su calendario del mes.
 * El empleado sale del usuario autenticado, nunca del formulario.
 */
class MiAsistenciaController
{
    private readonly Closure $reloj;

    public function __construct(
        private readonly Twig                   $twig,
        private readonly PortalService          $portal,
        private readonly MarcaAsistenciaService $marcas,
        ?Closure $reloj = null
    ) {
        $this->reloj = $reloj ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public function index(Request $request, Response $response): Response
    {
        $base = [
            'titulo'       => 'Mi Asistencia',
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ];

        $idEmpleado = $this->portal->idEmpleado($this->usuarioId($request));
        if ($idEmpleado === null) {
            return $this->twig->render($response, 'portal/mi_asistencia.html.twig', $base + ['vinculado' => false]);
        }

        $mes = $request->getQueryParams()['mes'] ?? null;
        $vista = $this->marcas->vista($idEmpleado, is_string($mes) ? $mes : null, ($this->reloj)());

        return $this->twig->render($response, 'portal/mi_asistencia.html.twig', $base + ['vinculado' => true] + $vista);
    }

    public function entrada(Request $request, Response $response): Response
    {
        $ahora = ($this->reloj)();
        return $this->ejecutar($request, $response,
            fn (int $idEmpleado) => $this->marcas->marcarEntrada($idEmpleado, $ahora, $this->usuarioId($request), $this->ip()),
            'Entrada registrada a las ' . $ahora->format('H:i') . '.'
        );
    }

    public function salida(Request $request, Response $response): Response
    {
        $ahora = ($this->reloj)();
        return $this->ejecutar($request, $response,
            fn (int $idEmpleado) => $this->marcas->marcarSalida($idEmpleado, $ahora, $this->usuarioId($request), $this->ip()),
            'Salida registrada a las ' . $ahora->format('H:i') . '.'
        );
    }

    public function corregir(Request $request, Response $response, array $args): Response
    {
        $hora = (string)(((array)$request->getParsedBody())['hora_salida'] ?? '');
        return $this->ejecutar($request, $response,
            fn (int $idEmpleado) => $this->marcas->corregirSalida(
                $idEmpleado, (int)$args['id'], $hora, ($this->reloj)(), $this->usuarioId($request), $this->ip()
            ),
            'Salida guardada.'
        );
    }

    /**
     * POST → acción → flash → redirección a Mi asistencia.
     */
    private function ejecutar(Request $request, Response $response, callable $accion, string $exito): Response
    {
        $idEmpleado = $this->portal->idEmpleado($this->usuarioId($request));
        if ($idEmpleado === null) {
            $_SESSION['flash_error'] = 'Su usuario no está vinculado a un empleado.';
        } else {
            try {
                $accion($idEmpleado);
                $_SESSION['flash_success'] = $exito;
            } catch (InvalidArgumentException $e) {
                $_SESSION['flash_error'] = $e->getMessage();
            }
        }

        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('portal.asistencia');
        return $response->withHeader('Location', $url)->withStatus(302);
    }

    private function consumeFlash(string $key): ?string
    {
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }

    private function usuarioId(Request $request): int
    {
        return (int)$request->getAttribute('usuario')['id'];
    }

    private function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
```

- [ ] **Step 4: Correr y ver que pasa**

Run: `vendor/bin/phpunit tests/Controllers/MiAsistenciaControllerTest.php`
Expected: `OK (6 tests, ...)`

- [ ] **Step 5: Rutas, contenedor y borrar el código viejo**

En `config/routes.php`, reemplazar:

```php
    $app->get('/mi-asistencia', [\App\Controllers\PortalController::class, 'asistencia'])
        ->setName('portal.asistencia')->add(new AuthMiddleware());
```

por:

```php
    $app->get('/mi-asistencia', [\App\Controllers\MiAsistenciaController::class, 'index'])
        ->setName('portal.asistencia')->add(new AuthMiddleware());
    $app->post('/mi-asistencia/entrada', [\App\Controllers\MiAsistenciaController::class, 'entrada'])
        ->setName('portal.asistencia.entrada')->add(new AuthMiddleware());
    $app->post('/mi-asistencia/salida', [\App\Controllers\MiAsistenciaController::class, 'salida'])
        ->setName('portal.asistencia.salida')->add(new AuthMiddleware());
    $app->post('/mi-asistencia/{id:[0-9]+}/salida', [\App\Controllers\MiAsistenciaController::class, 'corregir'])
        ->setName('portal.asistencia.corregir')->add(new AuthMiddleware());
```

En `config/dependencies.php`, después de la entrada de `\App\Controllers\PortalController::class`:

```php
    \App\Controllers\MiAsistenciaController::class => function (ContainerInterface $c) {
        return new \App\Controllers\MiAsistenciaController(
            $c->get(Twig::class),
            $c->get(\App\Services\PortalService::class),
            $c->get(\App\Services\MarcaAsistenciaService::class)
        );
    },
```

Borrar:
- `PortalController::asistencia` (el método completo, líneas ~89-99).
- `PortalService::asistencia`, con su docblock.
- `PortalRepository::asistencia` y `PortalRepository::periodosConAsistencia`, con sus docblocks.

Run: `grep -rn -e "periodosConAsistencia" -e "service->asistencia(" -e "repo->asistencia(" src tests templates; php -l config/routes.php; php -l config/dependencies.php`
Expected: el grep no devuelve nada; los dos `php -l` dicen `No syntax errors detected`.

Run: `vendor/bin/phpunit`
Expected: `OK`.

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/MiAsistenciaController.php tests/Controllers/MiAsistenciaControllerTest.php config/routes.php config/dependencies.php src/Controllers/PortalController.php src/Services/PortalService.php src/Repositories/PortalRepository.php
git commit -m "feat(asistencia): rutas del portal para marcar entrada, salida y corregir

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Pantalla "Mi asistencia", CSS, JS y etiquetas del admin

**Files:**
- Modify (reescribir): `templates/portal/mi_asistencia.html.twig`
- Modify: `templates/asistencia/index.html.twig` (columna Salida, línea ~66)
- Modify: `public/assets/css/app.css` (agregar antes del bloque `@media print`)
- Modify: `public/assets/js/app.js` (agregar al final)
- Test: `tests/Templates/PantallasTest.php` (agregar pruebas)

**Interfaces:**
- **Consumes:** las variables de la Task 4 (`vinculado`, `hoy`, `calendario`, `mes`, `mesTexto`, `mesAnterior`, `mesSiguiente`, `activo`, flashes) y los nombres de ruta `portal.asistencia*`.
- **Produces:**
  - Las clases CSS `lb-resumen*`, `lb-cal*` y `lb-dia*`.
  - Los IDs `calAsistencia` y `detalleDia`, que usa `app.js`.

- [ ] **Step 1: Escribir las pruebas que fallan**

Agregar a `tests/Templates/PantallasTest.php` (antes de la llave final):

```php
    /** @return array<string, mixed> */
    private function vistaAsistencia(string $estadoHoy, array $pendientes = []): array
    {
        $registro = ['id_asistencia' => 30, 'fecha' => '2026-10-12', 'hora_entrada' => '07:58:00',
                     'hora_salida' => $estadoHoy === 'completo' ? '17:00:00' : '00:00:00',
                     'horas_trabajadas' => '9.03', 'salida_manual' => 0];
        $cal = (new \App\Helpers\CalendarioAsistencia())->construir(
            '2026-10', [$registro + ['fecha' => '2026-10-01', 'hora_salida' => '17:00:00']],
            ['2026-10-05' => 'Prueba'],
            new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-10-12')
        );
        return [
            'vinculado' => true, 'activo' => true, 'mes' => '2026-10', 'mesTexto' => 'Octubre 2026',
            'mesAnterior' => '2026-09', 'mesSiguiente' => null, 'calendario' => $cal,
            'hoy' => ['estado' => $estadoHoy, 'registro' => $estadoHoy === 'sin_entrada' ? null : $registro,
                      'pendientes' => $pendientes],
        ];
    }

    public function testMiAsistenciaSinEntradaOfreceMarcarEntrada(): void
    {
        $html = $this->render('portal/mi_asistencia.html.twig', $this->vistaAsistencia('sin_entrada'));

        self::assertStringContainsString('action="/portal.asistencia.entrada"', $html);
        self::assertStringContainsString('Marcar entrada', $html);
        self::assertStringContainsString('id="calAsistencia"', $html);
        self::assertStringContainsString('lb-dia--asistio', $html);
        self::assertStringContainsString('lb-dia--feriado', $html);
        self::assertStringContainsString('Octubre 2026', $html);
    }

    public function testMiAsistenciaConEntradaOfreceMarcarSalida(): void
    {
        $html = $this->render('portal/mi_asistencia.html.twig', $this->vistaAsistencia('con_entrada'));

        self::assertStringContainsString('action="/portal.asistencia.salida"', $html);
        self::assertStringContainsString('07:58', $html);
    }

    public function testMiAsistenciaCompletaNoMuestraBotones(): void
    {
        $html = $this->render('portal/mi_asistencia.html.twig', $this->vistaAsistencia('completo'));

        self::assertStringNotContainsString('portal.asistencia.entrada', $html);
        self::assertStringNotContainsString('portal.asistencia.salida"', $html);
        self::assertStringContainsString('Jornada completa', $html);
    }

    public function testMiAsistenciaMuestraSalidasPendientes(): void
    {
        $pendiente = ['id_asistencia' => 21, 'fecha' => '2026-10-08', 'hora_entrada' => '08:05:00'];
        $html = $this->render('portal/mi_asistencia.html.twig', $this->vistaAsistencia('sin_entrada', [$pendiente]));

        self::assertStringContainsString('Te faltó marcar la salida del 08/10/2026', $html);
        self::assertStringContainsString('action="/portal.asistencia.corregir"', $html);
        self::assertStringContainsString('name="hora_salida"', $html);
    }

    public function testMiAsistenciaSinVinculoNoMuestraCalendario(): void
    {
        $html = $this->render('portal/mi_asistencia.html.twig', ['vinculado' => false]);

        self::assertStringNotContainsString('calAsistencia', $html);
        self::assertStringContainsString('no está vinculado', $html);
    }

    public function testAdminAsistenciaMuestraSinSalidaYEscritaPorElEmpleado(): void
    {
        $fila = ['id_asistencia' => 1, 'nombre' => 'Luis', 'apellidos' => 'Rojas', 'fecha' => '2026-10-08',
                 'hora_entrada' => '08:00:00', 'horas_trabajadas' => '0.00', 'feriado_nombre' => null];
        $html = $this->render('asistencia/index.html.twig', [
            'asistencias' => [
                $fila + ['hora_salida' => '00:00:00', 'salida_manual' => 0],
                $fila + ['hora_salida' => '17:00:00', 'salida_manual' => 1],
            ],
            'empleados' => [], 'periodos' => [],
        ]);

        self::assertStringContainsString('Sin salida', $html);
        self::assertStringContainsString('Escrita por el empleado', $html);
    }
```

Nota: el `url_for` de prueba de `PantallasTest` devuelve `'/' . $nombre` sin argumentos. Por eso la ruta de corregir se ve como `/portal.asistencia.corregir`.

- [ ] **Step 2: Correr y ver que falla**

Run: `vendor/bin/phpunit tests/Templates/PantallasTest.php`
Expected: FAIL en las 6 pruebas nuevas, porque la plantilla actual usa `periodos` y `registros`. Las 5 pruebas anteriores siguen pasando.

- [ ] **Step 3: Reescribir `templates/portal/mi_asistencia.html.twig`**

```twig
{% extends 'layouts/base.html.twig' %}
{% import 'partials/ui.html.twig' as ui %}

{% block titulo %}Mi Asistencia{% endblock %}

{% block encabezado %}
{{ ui.encabezado_pagina('Mi asistencia', {
    ruta: [{texto: 'Mi cuenta'}, {texto: 'Mi asistencia'}]
}) }}
{% endblock %}

{% block contenido %}
{% if not vinculado %}
<div class="card-fo text-center text-muted py-5" style="max-width:720px">
    <i class="bi bi-person-x fs-2 d-block mb-2"></i>
    Su usuario no está vinculado a un empleado.
</div>
{% else %}
<div style="max-width:900px">

    {% if flashSuccess %}<div class="alert alert-success">{{ flashSuccess }}</div>{% endif %}
    {% if flashError %}<div class="alert alert-danger">{{ flashError }}</div>{% endif %}

    {# ── Salidas olvidadas ─────────────────────────────── #}
    {% for p in hoy.pendientes %}
    <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2">
        <div class="me-auto">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Te faltó marcar la salida del {{ p.fecha|date('d/m/Y') }} (entrada {{ p.hora_entrada|slice(0, 5) }}).
        </div>
        <form method="POST" action="{{ url_for('portal.asistencia.corregir', {id: p.id_asistencia}) }}"
              class="d-flex gap-2" onsubmit="this.querySelector('button').disabled = true;">
            <input type="time" name="hora_salida" class="form-control form-control-sm" required>
            <button class="btn btn-sm btn-primary">Guardar salida</button>
        </form>
    </div>
    {% endfor %}

    {# ── Hoy ───────────────────────────────────────────── #}
    {% embed 'partials/seccion.html.twig' with {titulo: 'Hoy, ' ~ 'now'|date('d/m/Y')} %}
    {% block cuerpo %}
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="text-muted">
                {% if hoy.estado == 'sin_entrada' %}
                    Todavía no ha marcado su entrada.
                {% elseif hoy.estado == 'con_entrada' %}
                    Entrada {{ hoy.registro.hora_entrada|slice(0, 5) }} · aún sin salida
                {% else %}
                    Entrada {{ hoy.registro.hora_entrada|slice(0, 5) }} · Salida {{ hoy.registro.hora_salida|slice(0, 5) }}
                {% endif %}
            </div>
            {% if not activo %}
                <span class="text-muted small">Su empleado está inactivo.</span>
            {% elseif hoy.estado == 'sin_entrada' %}
                <form method="POST" action="{{ url_for('portal.asistencia.entrada') }}" onsubmit="this.querySelector('button').disabled = true;">
                    <button class="btn btn-primary"><i class="bi bi-box-arrow-in-right me-1"></i>Marcar entrada</button>
                </form>
            {% elseif hoy.estado == 'con_entrada' %}
                <form method="POST" action="{{ url_for('portal.asistencia.salida') }}" onsubmit="this.querySelector('button').disabled = true;">
                    <button class="btn btn-primary"><i class="bi bi-box-arrow-right me-1"></i>Marcar salida</button>
                </form>
            {% else %}
                {{ ui.etiqueta('Jornada completa: ' ~ hoy.registro.horas_trabajadas|number_format(1) ~ ' h', 'verde') }}
            {% endif %}
        </div>
    {% endblock %}
    {% endembed %}

    {# ── Resumen del mes ───────────────────────────────── #}
    <div class="lb-resumen">
        <div class="lb-resumen__item">
            <div class="lb-resumen__label">Días asistidos</div>
            <div class="lb-resumen__valor">{{ calendario.resumen.dias_asistidos }}</div>
        </div>
        <div class="lb-resumen__item">
            <div class="lb-resumen__label">Horas del mes</div>
            <div class="lb-resumen__valor">{{ calendario.resumen.horas|number_format(1) }}</div>
        </div>
        <div class="lb-resumen__item">
            <div class="lb-resumen__label">Sin registro</div>
            <div class="lb-resumen__valor {{ calendario.resumen.sin_registro > 0 ? 'text-danger' : '' }}">{{ calendario.resumen.sin_registro }}</div>
        </div>
    </div>

    {# ── Calendario ────────────────────────────────────── #}
    <section class="lb-seccion">
        <div class="d-flex justify-content-between align-items-center mb-2">
            {% if mesAnterior %}
                <a href="{{ url_for('portal.asistencia') }}?mes={{ mesAnterior }}" class="btn btn-sm btn-outline-secondary" title="Mes anterior"><i class="bi bi-chevron-left"></i></a>
            {% else %}<span></span>{% endif %}
            <h2 class="lb-seccion__titulo mb-0">{{ mesTexto }}</h2>
            {% if mesSiguiente %}
                <a href="{{ url_for('portal.asistencia') }}?mes={{ mesSiguiente }}" class="btn btn-sm btn-outline-secondary" title="Mes siguiente"><i class="bi bi-chevron-right"></i></a>
            {% else %}<span></span>{% endif %}
        </div>

        <div class="lb-cal" id="calAsistencia">
            {% for cab in ['L', 'K', 'M', 'J', 'V', 'S', 'D'] %}<div class="lb-cal__cab">{{ cab }}</div>{% endfor %}
            {% for semana in calendario.semanas %}
                {% for dia in semana %}
                    {% if dia is null %}
                        <div class="lb-dia lb-dia--vacio"></div>
                    {% else %}
                        {% set clases = 'lb-dia lb-dia--' ~ dia.estado ~ (dia.es_hoy ? ' lb-dia--es-hoy' : '') %}
                        {% if dia.registro %}
                            <button type="button" class="{{ clases }}"
                                    data-fecha="{{ dia.fecha|date('d/m/Y') }}"
                                    data-entrada="{{ dia.registro.hora_entrada|slice(0, 5) }}"
                                    data-salida="{{ dia.estado == 'sin_salida' ? 'Sin salida' : dia.registro.hora_salida|slice(0, 5) }}"
                                    data-horas="{{ dia.registro.horas_trabajadas|number_format(2) }}"
                                    data-manual="{{ dia.registro.salida_manual ? '1' : '0' }}">
                                <span class="lb-dia__num">{{ dia.numero }}</span>
                                <span class="lb-dia__info">{{ dia.estado == 'sin_salida' ? (dia.es_hoy ? 'En curso' : 'Sin salida') : dia.registro.horas_trabajadas|number_format(1) ~ 'h' }}</span>
                            </button>
                        {% else %}
                            <div class="{{ clases }}" {% if dia.feriado %}title="{{ dia.feriado }}"{% endif %}>
                                <span class="lb-dia__num">{{ dia.numero }}</span>
                                {% if dia.estado == 'feriado' %}<span class="lb-dia__info">Feriado</span>{% endif %}
                            </div>
                        {% endif %}
                    {% endif %}
                {% endfor %}
            {% endfor %}
        </div>

        <div class="lb-cal__leyenda">
            <span><i class="lb-dia--asistio"></i>Asistió</span>
            <span><i class="lb-dia--sin_salida"></i>Sin salida</span>
            <span><i class="lb-dia--sin_registro"></i>Sin registro</span>
            <span><i class="lb-dia--feriado"></i>Feriado</span>
            <span><i class="lb-dia--libre"></i>Libre</span>
        </div>

        <div class="lb-dia-detalle d-none" id="detalleDia">
            <strong data-campo="fecha"></strong>
            <span>Entrada <b data-campo="entrada"></b></span>
            <span>Salida <b data-campo="salida"></b></span>
            <span>Horas <b data-campo="horas"></b></span>
            <span class="text-muted d-none" data-campo="manual">Salida escrita por usted</span>
        </div>
    </section>
</div>
{% endif %}
{% endblock %}
```

- [ ] **Step 4: CSS** (en `public/assets/css/app.css`, justo antes de la línea `@media print {`)

```css
/* ── Mi asistencia: resumen y calendario ───────────────── */
.lb-resumen { display: grid; grid-template-columns: repeat(3, 1fr); gap: .75rem; margin-bottom: 1rem; }
.lb-resumen__item { background: var(--lb-surface); border: 1px solid var(--lb-border); border-radius: 8px; padding: .75rem 1rem; }
.lb-resumen__label { font-size: .8rem; color: var(--lb-muted); }
.lb-resumen__valor { font-size: 1.5rem; font-weight: 700; }
.lb-cal { display: grid; grid-template-columns: repeat(7, 1fr); gap: .35rem; }
.lb-cal__cab { font-size: .75rem; font-weight: 600; color: var(--lb-muted); text-align: center; }
.lb-dia { min-height: 64px; border-radius: 6px; padding: .3rem .4rem; font-size: .8rem; position: relative;
          border: 1px solid transparent; background: var(--lb-surface); color: inherit; text-align: left; }
button.lb-dia { width: 100%; cursor: pointer; }
.lb-dia__num { font-weight: 700; }
.lb-dia__info { position: absolute; right: .4rem; bottom: .25rem; font-size: .72rem; }
.lb-dia--vacio { background: transparent; }
.lb-dia--asistio { background: #e7f6ee; color: #0f7a4a; }
.lb-dia--sin_salida { background: #fef3e2; color: #92400e; }
.lb-dia--sin_registro { background: #ffe6ec; color: var(--lb-red-700); }
.lb-dia--feriado { background: #e6f0ff; color: var(--lb-navy); }
.lb-dia--libre { background: var(--lb-bg); color: #9ca3af; }
.lb-dia--hoy, .lb-dia--futuro, .lb-dia--antes_ingreso { border: 1px dashed var(--lb-border); color: #9ca3af; }
.lb-dia--es-hoy { outline: 2px solid var(--lb-navy); outline-offset: -2px; }
.lb-dia--activo { box-shadow: 0 0 0 2px var(--lb-red); }
.lb-cal__leyenda { display: flex; flex-wrap: wrap; gap: .9rem; margin-top: .6rem; font-size: .78rem; color: var(--lb-muted); }
.lb-cal__leyenda i { display: inline-block; width: 11px; height: 11px; border-radius: 3px; margin-right: .3rem; vertical-align: -1px; }
.lb-dia-detalle { display: flex; flex-wrap: wrap; gap: 1rem; margin-top: .75rem; padding: .6rem .8rem;
                  border: 1px solid var(--lb-border); border-radius: 8px; font-size: .85rem; }
@media (max-width: 575.98px) {
    .lb-resumen { gap: .4rem; }
    .lb-resumen__item { padding: .5rem .6rem; }
    .lb-resumen__valor { font-size: 1.15rem; }
    .lb-cal { gap: .2rem; }
    .lb-dia { min-height: 40px; padding: .2rem .25rem; }
    .lb-dia__info { display: none; }
}
```

- [ ] **Step 5: JS** (al final de `public/assets/js/app.js`)

```js
// ── Mi asistencia: al tocar un día con registro, muestra su detalle debajo del calendario ──
function initCalendarioAsistencia() {
    const cal = document.getElementById('calAsistencia');
    const panel = document.getElementById('detalleDia');
    if (!cal || !panel) {
        return;
    }
    const campo = (nombre) => panel.querySelector('[data-campo="' + nombre + '"]');

    cal.addEventListener('click', function (e) {
        const dia = e.target.closest('button.lb-dia');
        if (!dia) {
            return;
        }
        cal.querySelectorAll('.lb-dia--activo').forEach((d) => d.classList.remove('lb-dia--activo'));
        dia.classList.add('lb-dia--activo');
        campo('fecha').textContent = dia.dataset.fecha;
        campo('entrada').textContent = dia.dataset.entrada;
        campo('salida').textContent = dia.dataset.salida;
        campo('horas').textContent = dia.dataset.horas;
        campo('manual').classList.toggle('d-none', dia.dataset.manual !== '1');
        panel.classList.remove('d-none');
    });
}

document.addEventListener('DOMContentLoaded', initCalendarioAsistencia);
```

- [ ] **Step 6: Etiquetas del admin** (`templates/asistencia/index.html.twig`)

Reemplazar:

```twig
                    <td>{{ a.hora_salida }}</td>
```

por:

```twig
                    <td>
                        {% if a.hora_salida starts with '00:00' %}
                            {{ ui.etiqueta('Sin salida', 'ambar') }}
                        {% else %}
                            {{ a.hora_salida|slice(0, 5) }}
                            {% if a.salida_manual %}{{ ui.etiqueta('Escrita por el empleado', 'gris') }}{% endif %}
                        {% endif %}
                    </td>
```

- [ ] **Step 7: Correr y ver que pasa**

Run: `vendor/bin/phpunit tests/Templates/PantallasTest.php`
Expected: `OK (11 tests, ...)`

Run: `vendor/bin/phpunit`
Expected: `OK`.

- [ ] **Step 8: Commit**

```bash
git add templates/portal/mi_asistencia.html.twig templates/asistencia/index.html.twig public/assets/css/app.css public/assets/js/app.js tests/Templates/PantallasTest.php
git commit -m "feat(asistencia): pantalla Mi asistencia con marcas, resumen y calendario

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Verificación de punta a punta y documentación

**Files:**
- Modify: `CLAUDE.md` (tabla `asistencia` en el Grupo 4 y la tabla de módulos)

- [ ] **Step 1: Prueba real contra la base local, sin servidor** (en el scratchpad, con transacción revertida)

Crear `scratchpad/e2e_marcas.php`:

```php
<?php
require 'C:/Users/Gmadr/OneDrive/Documentos/Proyecto_Graduacion/vendor/autoload.php';
date_default_timezone_set('America/Costa_Rica');
$pdo = new PDO('mysql:host=127.0.0.1;dbname=lubrimotos_nomina;charset=utf8mb4', 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->beginTransaction();
$s = new App\Services\MarcaAsistenciaService(
    new App\Repositories\AsistenciaRepository($pdo), new App\Repositories\PeriodosRepository($pdo),
    new App\Repositories\EmpleadosRepository($pdo), new App\Repositories\FeriadosRepository($pdo),
    new App\Repositories\AuditoriaRepository($pdo), new App\Helpers\CalendarioAsistencia()
);
$idCarlos = (int)$pdo->query("SELECT e.id_empleado FROM empleados e JOIN usuarios u ON u.id_usuario = e.id_usuario WHERE u.nombre_usuario = 'cjimenez'")->fetchColumn();
$s->marcarEntrada($idCarlos, new DateTimeImmutable('2026-10-12 07:58:00'), 1, '127.0.0.1');
echo $s->estadoDeHoy($idCarlos, new DateTimeImmutable('2026-10-12 12:00:00'))['estado'], "\n";
$s->marcarSalida($idCarlos, new DateTimeImmutable('2026-10-12 17:13:00'), 1, '127.0.0.1');
$v = $s->vista($idCarlos, null, new DateTimeImmutable('2026-10-12 17:14:00'));
echo $v['hoy']['estado'], ' ', $v['calendario']['resumen']['dias_asistidos'], ' ', $v['calendario']['resumen']['horas'], "\n";
echo $pdo->query("SELECT COUNT(*) FROM periodos_pago WHERE fecha_inicio = '2026-10-01' AND fecha_fin = '2026-10-15'")->fetchColumn(), "\n";
$pdo->rollBack();
```

Run: `php <scratchpad>/e2e_marcas.php`
Expected:

```
con_entrada
completo 1 9.25
1
```

- [ ] **Step 2: Vista previa sin servidor**

Usar `scratchpad/preview.php` (ya existe) con las variables de `vistaAsistencia('con_entrada')`. Tomar capturas con Chrome headless a 1400 px y dentro de un iframe de 375 px. Revisar a ojo:
- se ve el calendario completo;
- el día de hoy tiene borde azul;
- el feriado sale en azul claro;
- en celular no hay scroll horizontal.

- [ ] **Step 3: Actualizar `CLAUDE.md`**

En la tabla del Grupo 4, cambiar la fila de `asistencia` a:

```markdown
| `asistencia` | `id_asistencia` | UNIQUE(`id_empleado`, `fecha`). FK a `periodos_pago` y `feriados`. `salida_manual` = 1 si el empleado escribió la salida que olvidó. El empleado marca entrada/salida desde el portal (`/mi-asistencia`); sin salida = `hora_salida '00:00:00'`. |
```

En la tabla de módulos, cambiar la fila 4 a:

```markdown
| 4 | Gestionar Asistencia (+ marcar desde el portal) | `feature/asistencia`, `feature/marcar-asistencia` | ✅ Completo (pendiente prueba manual) |
```

- [ ] **Step 4: Suite final y commit**

Run: `vendor/bin/phpunit`
Expected: `OK`, con unas 225 pruebas o más.

```bash
git add CLAUDE.md
git commit -m "docs: CLAUDE.md con marcar asistencia desde el portal

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 5: Verificación manual (Gabriel, en XAMPP)**

1. Correr `database/asistencia_salida_manual.sql`. Si la Task 1 ya lo aplicó a la base local, este paso se salta.
2. Entrar como `cjimenez` → Mi asistencia → "Marcar entrada". Debe salir el mensaje verde con la hora. Si no había período, debe aparecer en Mantenimientos › Períodos la quincena del 1 al 15 de octubre.
3. "Marcar salida" → "Jornada completa".
4. Como admin, en Asistencia → editar el día de Carlos, borrar la hora de salida dejándola en 00:00 desde la base, o crear un día pasado con entrada → como `cjimenez` aparece el aviso "Te faltó marcar la salida…" → guardar una hora → el admin ve "Escrita por el empleado".
5. Tocar un día del calendario: se muestra el detalle.
6. Revisar en celular (F12 → Ctrl+Shift+M).
7. Como admin: registrar asistencia de un día normal. Antes fallaba por el feriado nulo; ahora debe guardar.
