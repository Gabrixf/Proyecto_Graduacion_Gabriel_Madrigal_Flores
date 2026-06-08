# Liquidación — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementar el módulo de Liquidación laboral (preaviso, cesantía, vacaciones pendientes y aguinaldo proporcional; Código de Trabajo CR Arts. 28–29), siguiendo la arquitectura existente.

**Architecture:** Controller → Service → Repository (PDO puro). El cálculo legal vive en una clase pura `App\Helpers\LiquidacionCalculadora` (sin BD ni Slim), testeable con PHPUnit. Mismo patrón que el módulo de Nóminas (ya en `main`).

**Tech Stack:** PHP 8.1+, Slim 4, PDO/MySQL, Twig, PHP-DI, PHPUnit 11.

**Reference module:** `Nominas` (`src/Controllers/NominasController.php`, `src/Services/NominasService.php`, `src/Repositories/NominasRepository.php`, `src/Helpers/NominaCalculadora.php`) es la plantilla de estilo. Rutas en `config/routes.php`; wiring en `config/dependencies.php`. Las vistas extienden `templates/layouts/base.html.twig` y usan las clases `card-fo`, `badge-fo`.

**Branch:** `feature/liquidacion` (ya creada).

**Commit convention:** mensajes en inglés, terminar con `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
**PHP CLI:** usar `C:\xampp\php\php.exe`. Tests: `& "C:\xampp\php\php.exe" "vendor\bin\phpunit"`.

---

## File Structure

| Archivo | Responsabilidad |
|---|---|
| `tests/Helpers/LiquidacionCalculadoraTest.php` (crear) | Pruebas del cálculo legal |
| `src/Helpers/LiquidacionCalculadora.php` (crear) | Cálculo puro: antigüedad, preaviso, cesantía, vacaciones, aguinaldo, total por motivo |
| `src/Repositories/LiquidacionRepository.php` (crear) | SQL `liquidacion` + lookups (empleado, puesto) |
| `src/Services/LiquidacionService.php` (crear) | Orquestación + validaciones + auditoría |
| `src/Controllers/LiquidacionController.php` (crear) | HTTP: index, create, store, show, destroy |
| `templates/liquidacion/index.html.twig` (crear) | Lista + botón "Nueva liquidación" |
| `templates/liquidacion/form.html.twig` (crear) | Empleado, motivo, fecha_salida, días vacaciones |
| `templates/liquidacion/detalle.html.twig` (crear) | Desglose de rubros + total |
| `config/dependencies.php` (modificar) | Registrar Calculadora, Repository, Service, Controller |
| `config/routes.php` (modificar) | Agregar grupo `/liquidacion` |
| `templates/layouts/base.html.twig` (modificar) | Enlace "Liquidaciones" → `liquidacion.index` |

---

## Task 1: LiquidacionCalculadora (TDD)

**Files:**
- Create: `tests/Helpers/LiquidacionCalculadoraTest.php`
- Create: `src/Helpers/LiquidacionCalculadora.php`

- [ ] **Step 1: Write the failing test** — `tests/Helpers/LiquidacionCalculadoraTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Helpers\LiquidacionCalculadora;
use PHPUnit\Framework\TestCase;

final class LiquidacionCalculadoraTest extends TestCase
{
    private function calc(): LiquidacionCalculadora
    {
        return new LiquidacionCalculadora();
    }

    public function testDiasPreavisoPorAntiguedad(): void
    {
        $c = $this->calc();
        self::assertSame(0,  $c->diasPreaviso(2));   // < 3 meses
        self::assertSame(7,  $c->diasPreaviso(4));   // 3-6 meses
        self::assertSame(15, $c->diasPreaviso(8));   // 6-12 meses
        self::assertSame(30, $c->diasPreaviso(24));  // > 1 año
    }

    public function testDiasCesantiaUnAnio(): void
    {
        // 1 año exacto => 19.5 días
        self::assertEqualsWithDelta(19.5, $this->calc()->diasCesantia(1, 0.0, 12), 0.001);
    }

    public function testDiasCesantiaOchoAniosSumaTabla(): void
    {
        // 8 años => suma tabla 1..8 = 167.74 días
        self::assertEqualsWithDelta(167.74, $this->calc()->diasCesantia(8, 0.0, 96), 0.001);
    }

    public function testDiasCesantiaTopeOchoAnios(): void
    {
        // 10 años => tope en 8 años => 167.74 días
        self::assertEqualsWithDelta(167.74, $this->calc()->diasCesantia(10, 0.0, 120), 0.001);
    }

    public function testDiasCesantiaConFraccion(): void
    {
        // 5 años 6 meses => suma 1..5 (102.24) + tabla[6]*0.5 (21.5*0.5=10.75) = 112.99
        self::assertEqualsWithDelta(112.99, $this->calc()->diasCesantia(5, 0.5, 66), 0.001);
    }

    public function testCesantiaMinimoTresMeses(): void
    {
        // < 3 meses => sin cesantía
        self::assertEqualsWithDelta(0.0, $this->calc()->diasCesantia(0, 0.16, 2), 0.001);
    }

    public function testMontoVacaciones(): void
    {
        // valorDia = 300000/30 = 10000; 10 días => 100000
        self::assertEqualsWithDelta(100000.0, $this->calc()->montoVacaciones(300000.0, 10.0), 0.01);
    }

    public function testCalcularDespidoConCausaSinPreavisoNiCesantia(): void
    {
        $r = $this->calc()->calcular([
            'salarioMensual' => 300000.0,
            'fechaIngreso'   => '2020-01-01',
            'fechaSalida'    => '2026-07-01',
            'motivo'         => 'despido_con_causa',
            'diasVacaciones' => 10.0,
        ]);
        self::assertEqualsWithDelta(0.0, $r['preaviso'], 0.01);
        self::assertEqualsWithDelta(0.0, $r['cesantia'], 0.01);
        self::assertGreaterThan(0.0, $r['vacaciones_pendientes']);
        self::assertGreaterThan(0.0, $r['aguinaldo_proporcional']);
        self::assertEqualsWithDelta(
            $r['preaviso'] + $r['cesantia'] + $r['vacaciones_pendientes'] + $r['aguinaldo_proporcional'],
            $r['total_liquidacion'],
            0.01
        );
    }

    public function testCalcularDespidoSinCausaIncluyePreavisoYCesantia(): void
    {
        $r = $this->calc()->calcular([
            'salarioMensual' => 300000.0,
            'fechaIngreso'   => '2020-01-01',
            'fechaSalida'    => '2026-07-01',
            'motivo'         => 'despido_sin_causa',
            'diasVacaciones' => 10.0,
        ]);
        self::assertGreaterThan(0.0, $r['preaviso']);
        self::assertGreaterThan(0.0, $r['cesantia']);
        self::assertEqualsWithDelta(
            $r['preaviso'] + $r['cesantia'] + $r['vacaciones_pendientes'] + $r['aguinaldo_proporcional'],
            $r['total_liquidacion'],
            0.01
        );
    }
}
```

- [ ] **Step 2: Run the test, expect FAIL**

Run: `& "C:\xampp\php\php.exe" "vendor\bin\phpunit" --filter Liquidacion`
Expected: FAIL — `Class "App\Helpers\LiquidacionCalculadora" not found`.

- [ ] **Step 3: Implement `src/Helpers/LiquidacionCalculadora.php`**

```php
<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;

/**
 * LiquidacionCalculadora — cálculo puro de la liquidación laboral
 * (Código de Trabajo CR, Arts. 28-29). Sin BD ni HTTP.
 */
final class LiquidacionCalculadora
{
    /** Tabla de cesantía Art. 29: días por año (1..8). Tope: 8 años. */
    private const CESANTIA = [
        1 => 19.5, 2 => 20.0, 3 => 20.5, 4 => 21.0,
        5 => 21.24, 6 => 21.5, 7 => 22.0, 8 => 22.0,
    ];

    /** Valor de un día: salario mensual / 30. */
    public function valorDia(float $salarioMensual): float
    {
        return $salarioMensual / 30.0;
    }

    /**
     * Antigüedad entre dos fechas (YYYY-MM-DD).
     * @return array{anios:int, meses:int, mesesTotales:int, fraccion:float}
     */
    public function antiguedad(string $fechaIngreso, string $fechaSalida): array
    {
        $ini  = new DateTimeImmutable($fechaIngreso);
        $fin  = new DateTimeImmutable($fechaSalida);
        $diff = $ini->diff($fin);
        $anios = $diff->y;
        $meses = $diff->m;
        return [
            'anios'        => $anios,
            'meses'        => $meses,
            'mesesTotales' => $anios * 12 + $meses,
            'fraccion'     => $meses / 12.0,
        ];
    }

    /** Días de preaviso (Art. 28) según meses totales de antigüedad. */
    public function diasPreaviso(int $mesesTotales): int
    {
        if ($mesesTotales < 3)  return 0;
        if ($mesesTotales < 6)  return 7;
        if ($mesesTotales < 12) return 15;
        return 30;
    }

    public function montoPreaviso(float $salarioMensual, int $mesesTotales): float
    {
        return round($this->diasPreaviso($mesesTotales) * $this->valorDia($salarioMensual), 2);
    }

    /** Días de cesantía (Art. 29) con tope de 8 años. Aplica solo con >= 3 meses. */
    public function diasCesantia(int $anios, float $fraccion, int $mesesTotales): float
    {
        if ($mesesTotales < 3) {
            return 0.0;
        }
        $n = min($anios, 8);
        $dias = 0.0;
        for ($i = 1; $i <= $n; $i++) {
            $dias += self::CESANTIA[$i];
        }
        if ($anios < 8) {
            $dias += self::CESANTIA[$n + 1] * $fraccion;
        }
        return round($dias, 2);
    }

    public function montoCesantia(float $salarioMensual, int $anios, float $fraccion, int $mesesTotales): float
    {
        return round($this->diasCesantia($anios, $fraccion, $mesesTotales) * $this->valorDia($salarioMensual), 2);
    }

    public function montoVacaciones(float $salarioMensual, float $diasPendientes): float
    {
        return round($diasPendientes * $this->valorDia($salarioMensual), 2);
    }

    /** Aguinaldo proporcional: salario × meses del 1-dic (o ingreso) a la salida ÷ 12. */
    public function aguinaldoProporcional(float $salarioMensual, string $fechaIngreso, string $fechaSalida): float
    {
        $fin  = new DateTimeImmutable($fechaSalida);
        $anio = (int) $fin->format('Y');
        $dicEsteAnio = new DateTimeImmutable($anio . '-12-01');
        $inicio = ($fin >= $dicEsteAnio)
            ? $dicEsteAnio
            : new DateTimeImmutable(($anio - 1) . '-12-01');

        $ingreso = new DateTimeImmutable($fechaIngreso);
        if ($ingreso > $inicio) {
            $inicio = $ingreso;
        }
        if ($inicio >= $fin) {
            return 0.0;
        }
        $d = $inicio->diff($fin);
        $meses = $d->y * 12 + $d->m + ($d->d / 30.0);
        return round($salarioMensual * $meses / 12.0, 2);
    }

    /**
     * Calcula todos los rubros aplicando el mapeo por motivo.
     *
     * @param array{salarioMensual:float, fechaIngreso:string, fechaSalida:string, motivo:string, diasVacaciones:float} $d
     * @return array{preaviso:float, cesantia:float, vacaciones_pendientes:float, aguinaldo_proporcional:float, total_liquidacion:float}
     */
    public function calcular(array $d): array
    {
        $ant    = $this->antiguedad($d['fechaIngreso'], $d['fechaSalida']);
        $motivo = $d['motivo'];

        $aplicaPreaviso = $motivo === 'despido_sin_causa';
        $aplicaCesantia = in_array($motivo, ['despido_sin_causa', 'jubilacion'], true);

        $preaviso = $aplicaPreaviso
            ? $this->montoPreaviso($d['salarioMensual'], $ant['mesesTotales'])
            : 0.0;
        $cesantia = $aplicaCesantia
            ? $this->montoCesantia($d['salarioMensual'], $ant['anios'], $ant['fraccion'], $ant['mesesTotales'])
            : 0.0;
        $vacaciones = $this->montoVacaciones($d['salarioMensual'], $d['diasVacaciones']);
        $aguinaldo  = $this->aguinaldoProporcional($d['salarioMensual'], $d['fechaIngreso'], $d['fechaSalida']);

        $total = round($preaviso + $cesantia + $vacaciones + $aguinaldo, 2);

        return [
            'preaviso'               => $preaviso,
            'cesantia'               => $cesantia,
            'vacaciones_pendientes'  => $vacaciones,
            'aguinaldo_proporcional' => $aguinaldo,
            'total_liquidacion'      => $total,
        ];
    }
}
```

- [ ] **Step 4: Run the test, expect PASS**

Run: `& "C:\xampp\php\php.exe" "vendor\bin\phpunit" --filter Liquidacion`
Expected: PASS — 9 tests green.

- [ ] **Step 5: Commit**

```bash
git add tests/Helpers/LiquidacionCalculadoraTest.php src/Helpers/LiquidacionCalculadora.php
git commit -m "feat(liquidacion): add LiquidacionCalculadora with unit tests" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: LiquidacionRepository

**Files:**
- Create: `src/Repositories/LiquidacionRepository.php`

Mirror the PDO style of `NominasRepository` (constructor `private readonly PDO $pdo`, prepared statements, named params).

- [ ] **Step 1: Implement `src/Repositories/LiquidacionRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * LiquidacionRepository — SQL de la tabla liquidacion y lookups asociados.
 */
class LiquidacionRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int, array<string, mixed>> */
    public function findAll(): array
    {
        return $this->pdo->query(
            "SELECT l.id_liquidacion, l.id_empleado, l.fecha_salida, l.motivo,
                    l.preaviso, l.cesantia, l.vacaciones_pendientes, l.aguinaldo_proporcional,
                    l.total_liquidacion, l.fecha_calculo, e.nombre, e.apellidos
               FROM liquidacion l
               JOIN empleados e ON e.id_empleado = l.id_empleado
              ORDER BY l.fecha_calculo DESC"
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT l.*, e.nombre, e.apellidos, e.fecha_ingreso
               FROM liquidacion l
               JOIN empleados e ON e.id_empleado = l.id_empleado
              WHERE l.id_liquidacion = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** Empleados activos para el formulario. @return array<int, array<string, mixed>> */
    public function empleadosActivos(): array
    {
        return $this->pdo->query(
            "SELECT id_empleado, nombre, apellidos FROM empleados WHERE estado = 'activo' ORDER BY apellidos"
        )->fetchAll();
    }

    /** Salario del puesto + fecha de ingreso de un empleado. @return array<string, mixed>|null */
    public function datosEmpleado(int $idEmpleado): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.id_empleado, e.fecha_ingreso, e.estado, p.salario_base
               FROM empleados e
               JOIN puestos p ON p.id_puesto = e.id_puesto
              WHERE e.id_empleado = :id LIMIT 1"
        );
        $stmt->execute([':id' => $idEmpleado]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** Inserta o actualiza la liquidación del empleado (UNIQUE id_empleado). */
    public function upsert(array $d): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO liquidacion
                (id_empleado, fecha_salida, motivo, preaviso, cesantia,
                 vacaciones_pendientes, aguinaldo_proporcional, total_liquidacion)
             VALUES (:e, :fs, :mo, :pr, :ce, :va, :ag, :to)
             ON DUPLICATE KEY UPDATE
                fecha_salida = VALUES(fecha_salida),
                motivo = VALUES(motivo),
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

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM liquidacion WHERE id_liquidacion = :id');
        $stmt->execute([':id' => $id]);
    }
}
```

- [ ] **Step 2: Lint + ensure suite still green**

Run: `& "C:\xampp\php\php.exe" -l "src\Repositories\LiquidacionRepository.php"` (expect no errors)
Run: `& "C:\xampp\php\php.exe" "vendor\bin\phpunit"` (expect all green)

- [ ] **Step 3: Commit**

```bash
git add src/Repositories/LiquidacionRepository.php
git commit -m "feat(liquidacion): add LiquidacionRepository" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: LiquidacionService

**Files:**
- Create: `src/Services/LiquidacionService.php`

Mirror `NominasService` (inyecta repos + `AuditoriaRepository`; lanza `InvalidArgumentException`/`RuntimeException`; auditoría en cada mutación).

- [ ] **Step 1: Implement `src/Services/LiquidacionService.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\LiquidacionCalculadora;
use App\Repositories\AuditoriaRepository;
use App\Repositories\LiquidacionRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * LiquidacionService — cálculo y gestión de liquidaciones laborales.
 */
class LiquidacionService
{
    private const MOTIVOS = ['renuncia', 'despido_con_causa', 'despido_sin_causa', 'mutuo_acuerdo', 'jubilacion'];

    public function __construct(
        private readonly LiquidacionRepository $repo,
        private readonly AuditoriaRepository   $auditoriaRepo,
        private readonly LiquidacionCalculadora $calc
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function listar(): array
    {
        return $this->repo->findAll();
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

    /** @return array{empleados: array<int, array<string, mixed>>, motivos: string[]} */
    public function datosFormulario(): array
    {
        return ['empleados' => $this->repo->empleadosActivos(), 'motivos' => self::MOTIVOS];
    }

    /** Calcula y persiste la liquidación de un empleado. @return int id_liquidacion */
    public function calcular(array $datos, int $loggedInId, string $ip): int
    {
        $idEmpleado = isset($datos['id_empleado']) && is_numeric($datos['id_empleado']) ? (int) $datos['id_empleado'] : 0;
        $motivo     = (string) ($datos['motivo'] ?? '');
        $fechaSalida = trim((string) ($datos['fecha_salida'] ?? ''));
        $diasVac    = $datos['dias_vacaciones'] ?? '0';

        $errores = [];
        $emp = $idEmpleado > 0 ? $this->repo->datosEmpleado($idEmpleado) : null;
        if ($emp === null) {
            $errores[] = 'Debe seleccionar un empleado válido.';
        }
        if (!in_array($motivo, self::MOTIVOS, true)) {
            $errores[] = 'El motivo seleccionado no es válido.';
        }
        if ($fechaSalida === '' || strtotime($fechaSalida) === false) {
            $errores[] = 'La fecha de salida no es válida.';
        } elseif ($emp !== null && $fechaSalida < (string) $emp['fecha_ingreso']) {
            $errores[] = 'La fecha de salida no puede ser anterior a la fecha de ingreso.';
        }
        if (!is_numeric($diasVac) || (float) $diasVac < 0) {
            $errores[] = 'Los días de vacaciones pendientes deben ser un número mayor o igual a 0.';
        }
        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $montos = $this->calc->calcular([
            'salarioMensual' => (float) $emp['salario_base'],
            'fechaIngreso'   => (string) $emp['fecha_ingreso'],
            'fechaSalida'    => $fechaSalida,
            'motivo'         => $motivo,
            'diasVacaciones' => round((float) $diasVac, 2),
        ]);

        $id = $this->repo->upsert(array_merge(
            ['id_empleado' => $idEmpleado, 'fecha_salida' => $fechaSalida, 'motivo' => $motivo],
            $montos
        ));
        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'liquidacion', $id, $ip);
        return $id;
    }

    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $this->obtener($id);
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'liquidacion', $id, $ip);
    }
}
```

- [ ] **Step 2: Syntax check**

Run: `& "C:\xampp\php\php.exe" -l "src\Services\LiquidacionService.php"`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/Services/LiquidacionService.php
git commit -m "feat(liquidacion): add LiquidacionService" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: LiquidacionController

**Files:**
- Create: `src/Controllers/LiquidacionController.php`

Mirror `NominasController`/`HorasExtraController` for `urlFor`/`consumeFlash`/flash.

- [ ] **Step 1: Implement `src/Controllers/LiquidacionController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\LiquidacionService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class LiquidacionController
{
    public function __construct(
        private readonly Twig               $twig,
        private readonly LiquidacionService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'liquidacion/index.html.twig', [
            'titulo'        => 'Liquidaciones',
            'liquidaciones' => $this->service->listar(),
            'flashSuccess'  => $this->consumeFlash('flash_success'),
            'flashError'    => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $datos = $this->service->datosFormulario();
        return $this->twig->render($response, 'liquidacion/form.html.twig', [
            'titulo'    => 'Nueva Liquidación',
            'empleados' => $datos['empleados'],
            'motivos'   => $datos['motivos'],
            'datos'     => [],
            'errores'   => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $datos      = (array) $request->getParsedBody();
        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $id = $this->service->calcular($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Liquidación calculada correctamente.';
            return $response->withHeader('Location', $this->urlFor($request, 'liquidacion.show', ['id' => $id]))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $form = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'liquidacion/form.html.twig', [
                'titulo'    => 'Nueva Liquidación',
                'empleados' => $form['empleados'],
                'motivos'   => $form['motivos'],
                'datos'     => $datos,
                'errores'   => [$e->getMessage()],
            ]);
        }
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        try {
            $liquidacion = $this->service->obtener((int) $args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Liquidación no encontrada.';
            return $response->withHeader('Location', $this->urlFor($request, 'liquidacion.index'))->withStatus(302);
        }
        return $this->twig->render($response, 'liquidacion/detalle.html.twig', [
            'titulo'       => 'Detalle de Liquidación',
            'liquidacion'  => $liquidacion,
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->eliminar((int) $args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Liquidación eliminada.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $response->withHeader('Location', $this->urlFor($request, 'liquidacion.index'))->withStatus(302);
    }

    private function urlFor(Request $request, string $routeName, array $data = []): string
    {
        return RouteContext::fromRequest($request)->getRouteParser()->urlFor($routeName, $data);
    }

    private function consumeFlash(string $key): ?string
    {
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }
}
```

- [ ] **Step 2: Syntax check**

Run: `& "C:\xampp\php\php.exe" -l "src\Controllers\LiquidacionController.php"`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/Controllers/LiquidacionController.php
git commit -m "feat(liquidacion): add LiquidacionController" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: Wire DI + routes

**Files:**
- Modify: `config/dependencies.php`
- Modify: `config/routes.php`

- [ ] **Step 1: Register DI in `config/dependencies.php`**

Insert before the final `];` (after the Aguinaldo block), following the same closure style:

```php
    // ── Liquidación ───────────────────────────────────────
    \App\Helpers\LiquidacionCalculadora::class => function (ContainerInterface $c) {
        return new \App\Helpers\LiquidacionCalculadora();
    },

    \App\Repositories\LiquidacionRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\LiquidacionRepository($c->get(PDO::class));
    },

    \App\Services\LiquidacionService::class => function (ContainerInterface $c) {
        return new \App\Services\LiquidacionService(
            $c->get(\App\Repositories\LiquidacionRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class),
            $c->get(\App\Helpers\LiquidacionCalculadora::class)
        );
    },

    \App\Controllers\LiquidacionController::class => function (ContainerInterface $c) {
        return new \App\Controllers\LiquidacionController(
            $c->get(Twig::class),
            $c->get(\App\Services\LiquidacionService::class)
        );
    },
```

- [ ] **Step 2: Add the `/liquidacion` group in `config/routes.php`** (after the `/aguinaldo` group)

```php
    // ── Liquidación (solo admin) ──────────────────────────
    $app->group('/liquidacion', function (RouteCollectorProxy $group) {
        $group->get('',                [\App\Controllers\LiquidacionController::class, 'index'])->setName('liquidacion.index');
        $group->get('/crear',          [\App\Controllers\LiquidacionController::class, 'create'])->setName('liquidacion.create');
        $group->post('/crear',         [\App\Controllers\LiquidacionController::class, 'store'])->setName('liquidacion.store');
        $group->get('/{id}',           [\App\Controllers\LiquidacionController::class, 'show'])->setName('liquidacion.show');
        $group->post('/{id}/eliminar', [\App\Controllers\LiquidacionController::class, 'destroy'])->setName('liquidacion.destroy');
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());
```

- [ ] **Step 3: Syntax check + verify app boots**

Run: `& "C:\xampp\php\php.exe" -l "config\dependencies.php"` and `& "C:\xampp\php\php.exe" -l "config\routes.php"` (expect no errors).
Smoke (server on :8080): log in as admin/password123 and `GET /dashboard` → HTTP 200 (confirma que el contenedor + rutas no rompen).

- [ ] **Step 4: Commit**

```bash
git add config/dependencies.php config/routes.php
git commit -m "feat(liquidacion): wire DI and routes" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 6: Templates

**Files:**
- Create: `templates/liquidacion/index.html.twig`
- Create: `templates/liquidacion/form.html.twig`
- Create: `templates/liquidacion/detalle.html.twig`

- [ ] **Step 1: Create `templates/liquidacion/index.html.twig`**

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Liquidaciones{% endblock %}
{% block breadcrumb %}Inicio / Liquidaciones{% endblock %}

{% block contenido %}
<div class="d-flex justify-content-end mb-3">
    <a href="{{ url_for('liquidacion.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i> Nueva liquidación
    </a>
</div>

<div class="card-fo">
    <table class="table align-middle">
        <thead>
            <tr><th>Empleado</th><th>Motivo</th><th>Fecha salida</th>
                <th class="text-end">Total</th><th></th></tr>
        </thead>
        <tbody>
            {% for l in liquidaciones %}
            <tr>
                <td class="fw-semibold">{{ l.nombre }} {{ l.apellidos }}</td>
                <td>{{ l.motivo|replace({'_': ' '})|capitalize }}</td>
                <td>{{ l.fecha_salida }}</td>
                <td class="text-end fw-bold">₡{{ l.total_liquidacion|number_format(2, '.', ',') }}</td>
                <td class="text-end">
                    <a href="{{ url_for('liquidacion.show', {id: l.id_liquidacion}) }}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-eye"></i> Ver
                    </a>
                </td>
            </tr>
            {% else %}
            <tr><td colspan="5" class="text-center text-muted py-4">
                No hay liquidaciones registradas. Usa “Nueva liquidación”.
            </td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>
{% endblock %}
```

- [ ] **Step 2: Create `templates/liquidacion/form.html.twig`**

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Nueva Liquidación{% endblock %}
{% block breadcrumb %}Inicio / Liquidaciones / Nueva{% endblock %}

{% block contenido %}
<div class="card-fo" style="max-width:640px">
    {% if errores %}
    <div class="alert alert-danger">
        {% for e in errores %}<div><i class="bi bi-exclamation-triangle-fill me-1"></i>{{ e }}</div>{% endfor %}
    </div>
    {% endif %}

    <form method="POST" action="{{ url_for('liquidacion.store') }}">
        <div class="mb-3">
            <label class="form-label fw-semibold">Empleado</label>
            <select name="id_empleado" class="form-select" required>
                <option value="">— Seleccione —</option>
                {% for e in empleados %}
                <option value="{{ e.id_empleado }}" {{ datos.id_empleado == e.id_empleado ? 'selected' : '' }}>
                    {{ e.nombre }} {{ e.apellidos }}
                </option>
                {% endfor %}
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold">Motivo de salida</label>
            <select name="motivo" class="form-select" required>
                <option value="">— Seleccione —</option>
                {% for m in motivos %}
                <option value="{{ m }}" {{ datos.motivo == m ? 'selected' : '' }}>
                    {{ m|replace({'_': ' '})|capitalize }}
                </option>
                {% endfor %}
            </select>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-sm-6">
                <label class="form-label fw-semibold">Fecha de salida</label>
                <input type="date" name="fecha_salida" value="{{ datos.fecha_salida }}" class="form-control" required>
            </div>
            <div class="col-sm-6">
                <label class="form-label fw-semibold">Días de vacaciones pendientes</label>
                <input type="number" step="0.5" min="0" name="dias_vacaciones" value="{{ datos.dias_vacaciones ?? '0' }}" class="form-control" required>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-primary"><i class="bi bi-calculator me-1"></i>Calcular liquidación</button>
            <a href="{{ url_for('liquidacion.index') }}" class="btn btn-outline-primary">Cancelar</a>
        </div>
    </form>
</div>
{% endblock %}
```

- [ ] **Step 3: Create `templates/liquidacion/detalle.html.twig`**

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Detalle de Liquidación{% endblock %}
{% block breadcrumb %}Inicio / Liquidaciones / Detalle{% endblock %}

{% block contenido %}
<div class="card-fo mb-4" style="max-width:640px">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
            <h5 class="mb-1">{{ liquidacion.nombre }} {{ liquidacion.apellidos }}</h5>
            <div class="text-muted small">
                Motivo: {{ liquidacion.motivo|replace({'_': ' '})|capitalize }} ·
                Salida: {{ liquidacion.fecha_salida }} ·
                Ingreso: {{ liquidacion.fecha_ingreso }}
            </div>
        </div>
    </div>

    <table class="table align-middle mb-3">
        <tbody>
            <tr><td>Preaviso</td><td class="text-end">₡{{ liquidacion.preaviso|number_format(2, '.', ',') }}</td></tr>
            <tr><td>Cesantía</td><td class="text-end">₡{{ liquidacion.cesantia|number_format(2, '.', ',') }}</td></tr>
            <tr><td>Vacaciones pendientes</td><td class="text-end">₡{{ liquidacion.vacaciones_pendientes|number_format(2, '.', ',') }}</td></tr>
            <tr><td>Aguinaldo proporcional</td><td class="text-end">₡{{ liquidacion.aguinaldo_proporcional|number_format(2, '.', ',') }}</td></tr>
        </tbody>
        <tfoot>
            <tr class="fw-bold fs-5"><td>Total liquidación</td><td class="text-end">₡{{ liquidacion.total_liquidacion|number_format(2, '.', ',') }}</td></tr>
        </tfoot>
    </table>

    <div class="d-flex gap-2">
        <a href="{{ url_for('liquidacion.index') }}" class="btn btn-outline-primary">Volver</a>
        <form method="POST" action="{{ url_for('liquidacion.destroy', {id: liquidacion.id_liquidacion}) }}"
              onsubmit="return confirm('¿Eliminar esta liquidación?');">
            <button class="btn btn-outline-danger"><i class="bi bi-trash me-1"></i>Eliminar</button>
        </form>
    </div>
</div>
{% endblock %}
```

- [ ] **Step 4: Manual smoke test** (server on :8080, XAMPP MySQL up)

Log in as admin/password123 → **Liquidaciones** → **Nueva liquidación** → elegir un empleado, motivo `despido_sin_causa`, una fecha de salida y días de vacaciones → **Calcular** → verificar el desglose (preaviso + cesantía + vacaciones + aguinaldo = total). Repetir con `despido_con_causa` y confirmar que preaviso y cesantía quedan en ₡0.

- [ ] **Step 5: Commit**

```bash
git add templates/liquidacion/
git commit -m "feat(liquidacion): add index, form and detalle templates" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 7: Sidebar link + verification + mark module complete

**Files:**
- Modify: `templates/layouts/base.html.twig`
- Modify: `CLAUDE.md`

- [ ] **Step 1: Connect the sidebar link in `templates/layouts/base.html.twig`**

Change:
```twig
<a class="sb-item" href="#"><i class="bi bi-file-earmark-break"></i><span>Liquidaciones</span></a>
```
to:
```twig
<a class="sb-item" href="{{ url_for('liquidacion.index') }}"><i class="bi bi-file-earmark-break"></i><span>Liquidaciones</span></a>
```

- [ ] **Step 2: Full verification**

Run: `& "C:\xampp\php\php.exe" "vendor\bin\phpunit"` → all green.
Smoke (server :8080): log in and `GET /liquidacion`, `GET /liquidacion/crear` → HTTP 200; create one liquidación end-to-end and confirm the DB row in `liquidacion` has the expected totals.

- [ ] **Step 3: Mark module complete in `CLAUDE.md`**

Change the módulo 11 row to:
```
| 11 | Gestionar Liquidación | `feature/liquidacion` | ✅ Completo (pendiente prueba manual) |
```

- [ ] **Step 4: Commit**

```bash
git add templates/layouts/base.html.twig CLAUDE.md
git commit -m "feat(liquidacion): link in sidebar and mark module complete" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Self-Review notes

- **Spec coverage:** Calculadora + tablas legales (Task 1), Repository (2), Service con validaciones y mapeo por motivo (3), Controller (4), DI+routes (5), templates index/form/detalle (6), sidebar + E2E + estado (7). Todas las secciones del spec cubiertas.
- **Types consistent:** `calcular()` devuelve las claves `preaviso/cesantia/vacaciones_pendientes/aguinaldo_proporcional/total_liquidacion`, idénticas a las columnas usadas en `LiquidacionRepository::upsert` y en las plantillas. `diasCesantia(int, float, int)` y `montoCesantia(float, int, float, int)` coinciden entre la calculadora y sus pruebas.
- **No placeholders:** cada paso trae código/comandos completos.
- **DB note:** `liquidacion` tiene `UNIQUE(id_empleado)`; `upsert` usa `INSERT … ON DUPLICATE KEY UPDATE` y recupera el id con un SELECT (porque `lastInsertId` es 0 en UPDATE).
