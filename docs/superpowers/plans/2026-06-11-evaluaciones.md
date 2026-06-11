# Evaluaciones (Módulo 12) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the Evaluar Rendimiento module: admin CRUD over `evaluaciones` + `detalle_evaluacion` with a weighted-score calculator, plus the app's first employee-facing view (`/mis-evaluaciones`).

**Architecture:** Controller → Service → Repository over raw PDO (project's strict pattern). The weighted average lives in a pure, unit-tested `EvaluacionCalculadora` (same pattern as `LiquidacionCalculadora`). One repository handles both tables transactionally; edit replaces detail rows (DELETE + re-INSERT inside the transaction).

**Tech Stack:** PHP 8.1 / Slim 4 / Twig 3 / PHP-DI 7 / PDO MySQL / PHPUnit 11. No ORM, no npm.

**Spec:** `docs/superpowers/specs/2026-06-10-evaluaciones-design.md`

**Branch:** `feature/evaluaciones` (already created). All commits go here, never to `main`.

**Conventions reminders (from CLAUDE.md):**
- Every PHP file starts with `declare(strict_types=1)`.
- Repositories: SQL only. Services: business rules + audit. Controllers: HTTP only.
- Audit signature: `AuditoriaRepository::insert(string $accion, int $idUsuario, string $tablaAfectada, ?int $idRegistro, string $ipOrigen, ?string $detalle = null)`.
- Run tests with `vendor\bin\phpunit` (Windows). Syntax-check files with `php -l <file>`.

---

### Task 1: Criteria template in settings

**Files:**
- Modify: `config/settings.php` (add key after the `'nomina'` block, line ~52)

- [ ] **Step 1: Add the `criterios_evaluacion` key**

In `config/settings.php`, immediately after the closing `],` of the `'nomina'` block, insert:

```php
    // ── Evaluación de rendimiento ─────────────────────────
    // Plantilla fija de criterios (los pesos porcentuales suman 100).
    'criterios_evaluacion' => [
        ['criterio' => 'Puntualidad',         'peso' => 20.0],
        ['criterio' => 'Calidad del trabajo', 'peso' => 25.0],
        ['criterio' => 'Productividad',       'peso' => 25.0],
        ['criterio' => 'Trabajo en equipo',   'peso' => 15.0],
        ['criterio' => 'Actitud',             'peso' => 15.0],
    ],
```

- [ ] **Step 2: Syntax check**

Run: `php -l config/settings.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add config/settings.php
git commit -m "feat(evaluaciones): add criteria template to settings"
```

---

### Task 2: EvaluacionCalculadora (TDD)

**Files:**
- Test: `tests/Helpers/EvaluacionCalculadoraTest.php`
- Create: `src/Helpers/EvaluacionCalculadora.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Helpers/EvaluacionCalculadoraTest.php`:

```php
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
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor\bin\phpunit --filter EvaluacionCalculadoraTest`
Expected: ERROR — `Class "App\Helpers\EvaluacionCalculadora" not found`

- [ ] **Step 3: Write the implementation**

Create `src/Helpers/EvaluacionCalculadora.php`:

```php
<?php

declare(strict_types=1);

namespace App\Helpers;

use InvalidArgumentException;

/**
 * EvaluacionCalculadora — cálculo puro del puntaje ponderado de una
 * evaluación de rendimiento (escala 1–100, pesos porcentuales). Sin BD ni HTTP.
 */
final class EvaluacionCalculadora
{
    private const TOLERANCIA_PESOS = 0.01;

    /** Los pesos porcentuales deben sumar 100 (tolerancia 0.01). */
    public function validarPesos(array $pesos): bool
    {
        return abs(array_sum($pesos) - 100.0) <= self::TOLERANCIA_PESOS;
    }

    /**
     * Promedio ponderado de los criterios, redondeado a 2 decimales solo al final.
     *
     * @param array<int, array{puntaje: float, peso: float}> $detalles
     */
    public function puntajeTotal(array $detalles): float
    {
        if ($detalles === []) {
            throw new InvalidArgumentException('La evaluación debe tener al menos un criterio.');
        }
        $pesos = array_map(static fn(array $d): float => (float) $d['peso'], $detalles);
        if (!$this->validarPesos($pesos)) {
            throw new InvalidArgumentException('Los pesos de los criterios deben sumar 100.');
        }
        $total = 0.0;
        foreach ($detalles as $d) {
            $puntaje = (float) $d['puntaje'];
            if ($puntaje < 1.0 || $puntaje > 100.0) {
                throw new InvalidArgumentException('Cada puntaje debe estar entre 1 y 100.');
            }
            $total += $puntaje * (float) $d['peso'];
        }
        return round($total / 100.0, 2);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor\bin\phpunit --filter EvaluacionCalculadoraTest`
Expected: `OK (8 tests, 11 assertions)` (assertion count may vary slightly; all green)

- [ ] **Step 5: Commit**

```bash
git add tests/Helpers/EvaluacionCalculadoraTest.php src/Helpers/EvaluacionCalculadora.php
git commit -m "feat(evaluaciones): add EvaluacionCalculadora with unit tests"
```

---

### Task 3: EvaluacionesRepository

**Files:**
- Create: `src/Repositories/EvaluacionesRepository.php`

- [ ] **Step 1: Write the repository**

Create `src/Repositories/EvaluacionesRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * EvaluacionesRepository — SQL de evaluaciones y detalle_evaluacion.
 * Las escrituras que tocan ambas tablas van en transacción.
 */
class EvaluacionesRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int, array<string, mixed>> */
    public function findAll(): array
    {
        return $this->pdo->query(
            "SELECT ev.id_evaluacion, ev.id_empleado, ev.fecha_evaluacion, ev.periodo_evaluado,
                    ev.puntaje_total, ev.observaciones, e.nombre, e.apellidos
               FROM evaluaciones ev
               JOIN empleados e ON e.id_empleado = ev.id_empleado
              ORDER BY ev.periodo_evaluado DESC, e.apellidos"
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT ev.*, e.nombre, e.apellidos
               FROM evaluaciones ev
               JOIN empleados e ON e.id_empleado = ev.id_empleado
              WHERE ev.id_evaluacion = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function findDetalles(int $idEvaluacion): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_detalle, criterio, puntaje, peso
               FROM detalle_evaluacion
              WHERE id_evaluacion = :id
              ORDER BY id_detalle'
        );
        $stmt->execute([':id' => $idEvaluacion]);
        return $stmt->fetchAll();
    }

    /** Evaluaciones del empleado vinculado a un usuario. @return array<int, array<string, mixed>> */
    public function findByEmpleadoUsuario(int $idUsuario): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT ev.id_evaluacion, ev.fecha_evaluacion, ev.periodo_evaluado,
                    ev.puntaje_total, ev.observaciones, e.nombre, e.apellidos
               FROM evaluaciones ev
               JOIN empleados e ON e.id_empleado = ev.id_empleado
              WHERE e.id_usuario = :u
              ORDER BY ev.periodo_evaluado DESC"
        );
        $stmt->execute([':u' => $idUsuario]);
        return $stmt->fetchAll();
    }

    public function existeParaEmpleadoAnio(int $idEmpleado, int $anio, ?int $excluirId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM evaluaciones WHERE id_empleado = :e AND periodo_evaluado = :a';
        $params = [':e' => $idEmpleado, ':a' => $anio];
        if ($excluirId !== null) {
            $sql .= ' AND id_evaluacion <> :id';
            $params[':id'] = $excluirId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function esEmpleadoActivo(int $idEmpleado): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM empleados WHERE id_empleado = :id AND estado = 'activo'"
        );
        $stmt->execute([':id' => $idEmpleado]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** Empleados activos para el formulario. @return array<int, array<string, mixed>> */
    public function empleadosActivos(): array
    {
        return $this->pdo->query(
            "SELECT id_empleado, nombre, apellidos FROM empleados WHERE estado = 'activo' ORDER BY apellidos"
        )->fetchAll();
    }

    /**
     * Inserta cabecera + detalles en una transacción.
     *
     * @param array{id_empleado:int, fecha_evaluacion:string, periodo_evaluado:int, puntaje_total:float, observaciones:?string} $cab
     * @param array<int, array{criterio:string, puntaje:float, peso:float}> $detalles
     */
    public function insertConDetalles(array $cab, array $detalles): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO evaluaciones
                    (id_empleado, fecha_evaluacion, periodo_evaluado, puntaje_total, observaciones)
                 VALUES (:e, :f, :p, :t, :o)'
            );
            $stmt->execute([
                ':e' => $cab['id_empleado'],
                ':f' => $cab['fecha_evaluacion'],
                ':p' => $cab['periodo_evaluado'],
                ':t' => $cab['puntaje_total'],
                ':o' => $cab['observaciones'],
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->insertDetalles($id, $detalles);
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Actualiza cabecera y reemplaza los detalles (DELETE + re-INSERT) en una transacción.
     *
     * @param array{id_empleado:int, fecha_evaluacion:string, periodo_evaluado:int, puntaje_total:float, observaciones:?string} $cab
     * @param array<int, array{criterio:string, puntaje:float, peso:float}> $detalles
     */
    public function updateConDetalles(int $id, array $cab, array $detalles): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE evaluaciones
                    SET id_empleado = :e, fecha_evaluacion = :f, periodo_evaluado = :p,
                        puntaje_total = :t, observaciones = :o
                  WHERE id_evaluacion = :id'
            );
            $stmt->execute([
                ':e'  => $cab['id_empleado'],
                ':f'  => $cab['fecha_evaluacion'],
                ':p'  => $cab['periodo_evaluado'],
                ':t'  => $cab['puntaje_total'],
                ':o'  => $cab['observaciones'],
                ':id' => $id,
            ]);
            $del = $this->pdo->prepare('DELETE FROM detalle_evaluacion WHERE id_evaluacion = :id');
            $del->execute([':id' => $id]);
            $this->insertDetalles($id, $detalles);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        // ON DELETE CASCADE elimina los detalles.
        $stmt = $this->pdo->prepare('DELETE FROM evaluaciones WHERE id_evaluacion = :id');
        $stmt->execute([':id' => $id]);
    }

    /** @param array<int, array{criterio:string, puntaje:float, peso:float}> $detalles */
    private function insertDetalles(int $idEvaluacion, array $detalles): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO detalle_evaluacion (id_evaluacion, criterio, puntaje, peso)
             VALUES (:id, :c, :pu, :pe)'
        );
        foreach ($detalles as $d) {
            $stmt->execute([
                ':id' => $idEvaluacion,
                ':c'  => $d['criterio'],
                ':pu' => $d['puntaje'],
                ':pe' => $d['peso'],
            ]);
        }
    }
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l src/Repositories/EvaluacionesRepository.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add src/Repositories/EvaluacionesRepository.php
git commit -m "feat(evaluaciones): add EvaluacionesRepository"
```

---

### Task 4: EvaluacionesService

**Files:**
- Create: `src/Services/EvaluacionesService.php`

- [ ] **Step 1: Write the service**

Create `src/Services/EvaluacionesService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\EvaluacionCalculadora;
use App\Repositories\AuditoriaRepository;
use App\Repositories\EvaluacionesRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * EvaluacionesService — reglas de negocio de las evaluaciones de rendimiento.
 * Una evaluación por empleado por año; puntaje total siempre calculado.
 */
class EvaluacionesService
{
    private const ANIO_MINIMO = 2000;
    private const MAX_OBSERVACIONES = 500;
    private const MAX_CRITERIO = 150;

    /** @param array<int, array{criterio:string, peso:float}> $criteriosPlantilla */
    public function __construct(
        private readonly EvaluacionesRepository $repo,
        private readonly AuditoriaRepository    $auditoriaRepo,
        private readonly EvaluacionCalculadora  $calc,
        private readonly array                  $criteriosPlantilla
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function listar(): array
    {
        return $this->repo->findAll();
    }

    /** Cabecera + detalles. @return array<string, mixed> */
    public function obtener(int $id): array
    {
        $cab = $this->repo->findById($id);
        if ($cab === null) {
            throw new RuntimeException('Evaluación no encontrada.');
        }
        $cab['detalles'] = $this->repo->findDetalles($id);
        return $cab;
    }

    /** @return array{empleados: array<int, array<string, mixed>>, criterios: array<int, array{criterio:string, peso:float}>} */
    public function datosFormulario(): array
    {
        return [
            'empleados' => $this->repo->empleadosActivos(),
            'criterios' => $this->criteriosPlantilla,
        ];
    }

    /** @return int id_evaluacion */
    public function crear(array $datos, int $loggedInId, string $ip): int
    {
        [$cabecera, $detalles] = $this->validar($datos);
        $id = $this->repo->insertConDetalles($cabecera, $detalles);
        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'evaluaciones', $id, $ip);
        return $id;
    }

    public function actualizar(int $id, array $datos, int $loggedInId, string $ip): void
    {
        $this->obtener($id); // lanza RuntimeException si no existe
        [$cabecera, $detalles] = $this->validar($datos, $id);
        $this->repo->updateConDetalles($id, $cabecera, $detalles);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'evaluaciones', $id, $ip);
    }

    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $this->obtener($id);
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'evaluaciones', $id, $ip);
    }

    /** Evaluaciones (con detalles) del empleado ligado al usuario autenticado. @return array<int, array<string, mixed>> */
    public function misEvaluaciones(int $idUsuario): array
    {
        $evaluaciones = $this->repo->findByEmpleadoUsuario($idUsuario);
        foreach ($evaluaciones as &$ev) {
            $ev['detalles'] = $this->repo->findDetalles((int) $ev['id_evaluacion']);
        }
        unset($ev);
        return $evaluaciones;
    }

    /**
     * Valida el input del formulario y arma cabecera + detalles.
     *
     * @return array{0: array<string, mixed>, 1: array<int, array{criterio:string, puntaje:float, peso:float}>}
     */
    private function validar(array $datos, ?int $excluirId = null): array
    {
        $idEmpleado = isset($datos['id_empleado']) && is_numeric($datos['id_empleado'])
            ? (int) $datos['id_empleado'] : 0;
        $anio = isset($datos['periodo_evaluado']) && is_numeric($datos['periodo_evaluado'])
            ? (int) $datos['periodo_evaluado'] : 0;
        $fecha     = trim((string) ($datos['fecha_evaluacion'] ?? ''));
        $obs       = trim((string) ($datos['observaciones'] ?? ''));
        $criterios = array_values((array) ($datos['criterio'] ?? []));
        $pesos     = array_values((array) ($datos['peso'] ?? []));
        $puntajes  = array_values((array) ($datos['puntaje'] ?? []));

        $errores = [];
        if ($idEmpleado <= 0 || !$this->repo->esEmpleadoActivo($idEmpleado)) {
            $errores[] = 'Debe seleccionar un empleado activo válido.';
        }
        $anioActual = (int) date('Y');
        if ($anio < self::ANIO_MINIMO || $anio > $anioActual) {
            $errores[] = "El año evaluado debe estar entre " . self::ANIO_MINIMO . " y {$anioActual}.";
        }
        if ($fecha === '' || strtotime($fecha) === false) {
            $errores[] = 'La fecha de evaluación no es válida.';
        }
        if (mb_strlen($obs) > self::MAX_OBSERVACIONES) {
            $errores[] = 'Las observaciones no pueden superar los ' . self::MAX_OBSERVACIONES . ' caracteres.';
        }
        $n = count($criterios);
        if ($n === 0 || $n !== count($pesos) || $n !== count($puntajes)) {
            $errores[] = 'Los criterios de la evaluación están incompletos.';
        } else {
            foreach ($criterios as $i => $criterio) {
                $criterio = trim((string) $criterio);
                if ($criterio === '' || mb_strlen($criterio) > self::MAX_CRITERIO) {
                    $errores[] = 'Cada criterio debe tener un nombre de 1 a ' . self::MAX_CRITERIO . ' caracteres.';
                    break;
                }
                if (!is_numeric($pesos[$i]) || !is_numeric($puntajes[$i])) {
                    $errores[] = 'Cada criterio debe tener peso y puntaje numéricos.';
                    break;
                }
                if ((float) $puntajes[$i] < 1 || (float) $puntajes[$i] > 100) {
                    $errores[] = 'Cada puntaje debe estar entre 1 y 100.';
                    break;
                }
            }
        }
        if ($errores === [] && $this->repo->existeParaEmpleadoAnio($idEmpleado, $anio, $excluirId)) {
            $errores[] = "Ya existe una evaluación de ese empleado para el año {$anio}.";
        }
        if ($errores !== []) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $detalles = [];
        foreach ($criterios as $i => $criterio) {
            $detalles[] = [
                'criterio' => trim((string) $criterio),
                'puntaje'  => round((float) $puntajes[$i], 2),
                'peso'     => round((float) $pesos[$i], 2),
            ];
        }
        // Lanza InvalidArgumentException si los pesos no suman 100.
        $total = $this->calc->puntajeTotal($detalles);

        $cabecera = [
            'id_empleado'      => $idEmpleado,
            'fecha_evaluacion' => $fecha,
            'periodo_evaluado' => $anio,
            'puntaje_total'    => $total,
            'observaciones'    => $obs !== '' ? $obs : null,
        ];
        return [$cabecera, $detalles];
    }
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l src/Services/EvaluacionesService.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add src/Services/EvaluacionesService.php
git commit -m "feat(evaluaciones): add EvaluacionesService"
```

---

### Task 5: EvaluacionesController

**Files:**
- Create: `src/Controllers/EvaluacionesController.php`

- [ ] **Step 1: Write the controller**

Create `src/Controllers/EvaluacionesController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\EvaluacionesService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class EvaluacionesController
{
    public function __construct(
        private readonly Twig                $twig,
        private readonly EvaluacionesService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'evaluaciones/index.html.twig', [
            'titulo'       => 'Evaluaciones',
            'evaluaciones' => $this->service->listar(),
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $form = $this->service->datosFormulario();
        return $this->twig->render($response, 'evaluaciones/form.html.twig', [
            'titulo'    => 'Nueva Evaluación',
            'accion'    => $this->urlFor($request, 'evaluaciones.store'),
            'empleados' => $form['empleados'],
            'detalles'  => $this->plantillaVacia($form['criterios']),
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
            $id = $this->service->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Evaluación registrada correctamente.';
            return $response->withHeader('Location', $this->urlFor($request, 'evaluaciones.show', ['id' => $id]))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $form = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'evaluaciones/form.html.twig', [
                'titulo'    => 'Nueva Evaluación',
                'accion'    => $this->urlFor($request, 'evaluaciones.store'),
                'empleados' => $form['empleados'],
                'detalles'  => $this->detallesDesdePost($datos, $form['criterios']),
                'datos'     => $datos,
                'errores'   => [$e->getMessage()],
            ]);
        }
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        try {
            $evaluacion = $this->service->obtener((int) $args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Evaluación no encontrada.';
            return $response->withHeader('Location', $this->urlFor($request, 'evaluaciones.index'))->withStatus(302);
        }
        return $this->twig->render($response, 'evaluaciones/detalle.html.twig', [
            'titulo'       => 'Detalle de Evaluación',
            'evaluacion'   => $evaluacion,
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            $evaluacion = $this->service->obtener((int) $args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Evaluación no encontrada.';
            return $response->withHeader('Location', $this->urlFor($request, 'evaluaciones.index'))->withStatus(302);
        }
        $form = $this->service->datosFormulario();
        return $this->twig->render($response, 'evaluaciones/form.html.twig', [
            'titulo'    => 'Editar Evaluación',
            'accion'    => $this->urlFor($request, 'evaluaciones.update', ['id' => $args['id']]),
            'empleados' => $form['empleados'],
            'detalles'  => $evaluacion['detalles'],
            'datos'     => $evaluacion,
            'errores'   => [],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id         = (int) $args['id'];
        $datos      = (array) $request->getParsedBody();
        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->actualizar($id, $datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Evaluación actualizada correctamente.';
            return $response->withHeader('Location', $this->urlFor($request, 'evaluaciones.show', ['id' => $id]))->withStatus(302);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Evaluación no encontrada.';
            return $response->withHeader('Location', $this->urlFor($request, 'evaluaciones.index'))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $form = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'evaluaciones/form.html.twig', [
                'titulo'    => 'Editar Evaluación',
                'accion'    => $this->urlFor($request, 'evaluaciones.update', ['id' => $id]),
                'empleados' => $form['empleados'],
                'detalles'  => $this->detallesDesdePost($datos, $form['criterios']),
                'datos'     => $datos,
                'errores'   => [$e->getMessage()],
            ]);
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->eliminar((int) $args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Evaluación eliminada.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $response->withHeader('Location', $this->urlFor($request, 'evaluaciones.index'))->withStatus(302);
    }

    /** Vista del empleado autenticado: sus evaluaciones con desglose. */
    public function misEvaluaciones(Request $request, Response $response): Response
    {
        $idUsuario = (int) $_SESSION['usuario_id'];
        return $this->twig->render($response, 'evaluaciones/mis_evaluaciones.html.twig', [
            'titulo'       => 'Mis Evaluaciones',
            'evaluaciones' => $this->service->misEvaluaciones($idUsuario),
        ]);
    }

    /** Filas del formulario nuevo: plantilla con puntajes vacíos. */
    private function plantillaVacia(array $criterios): array
    {
        return array_map(
            static fn(array $c): array => ['criterio' => $c['criterio'], 'peso' => $c['peso'], 'puntaje' => ''],
            $criterios
        );
    }

    /** Reconstruye las filas desde el POST fallido para no perder lo digitado. */
    private function detallesDesdePost(array $datos, array $plantilla): array
    {
        $criterios = array_values((array) ($datos['criterio'] ?? []));
        $pesos     = array_values((array) ($datos['peso'] ?? []));
        $puntajes  = array_values((array) ($datos['puntaje'] ?? []));
        if ($criterios === [] || count($criterios) !== count($pesos)) {
            return $this->plantillaVacia($plantilla);
        }
        $filas = [];
        foreach ($criterios as $i => $criterio) {
            $filas[] = [
                'criterio' => (string) $criterio,
                'peso'     => $pesos[$i],
                'puntaje'  => $puntajes[$i] ?? '',
            ];
        }
        return $filas;
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

Run: `php -l src/Controllers/EvaluacionesController.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add src/Controllers/EvaluacionesController.php
git commit -m "feat(evaluaciones): add EvaluacionesController"
```

---

### Task 6: Templates

**Files:**
- Create: `templates/partials/eval_badge.html.twig`
- Create: `templates/evaluaciones/index.html.twig`
- Create: `templates/evaluaciones/form.html.twig`
- Create: `templates/evaluaciones/detalle.html.twig`
- Create: `templates/evaluaciones/mis_evaluaciones.html.twig`

- [ ] **Step 1: Badge partial**

Create `templates/partials/eval_badge.html.twig`:

```twig
{# Badge de calificación por rango. Uso: include with {puntaje: x} only #}
{% if puntaje >= 90 %}<span class="badge text-bg-success">Excelente</span>
{% elseif puntaje >= 80 %}<span class="badge text-bg-primary">Muy bueno</span>
{% elseif puntaje >= 70 %}<span class="badge text-bg-warning">Bueno</span>
{% elseif puntaje >= 60 %}<span class="badge" style="background:#fd7e14;color:#fff">Regular</span>
{% else %}<span class="badge text-bg-danger">Deficiente</span>{% endif %}
```

- [ ] **Step 2: Index (admin list)**

Create `templates/evaluaciones/index.html.twig`:

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Evaluaciones{% endblock %}
{% block breadcrumb %}Inicio / Evaluaciones{% endblock %}

{% block contenido %}
<div class="d-flex justify-content-end mb-3">
    <a href="{{ url_for('evaluaciones.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i> Nueva evaluación
    </a>
</div>

<div class="card-fo">
    <table class="table align-middle">
        <thead>
            <tr><th>Empleado</th><th>Año evaluado</th><th>Fecha</th>
                <th class="text-end">Puntaje</th><th>Calificación</th><th></th></tr>
        </thead>
        <tbody>
            {% for ev in evaluaciones %}
            <tr>
                <td class="fw-semibold">{{ ev.nombre }} {{ ev.apellidos }}</td>
                <td>{{ ev.periodo_evaluado }}</td>
                <td>{{ ev.fecha_evaluacion }}</td>
                <td class="text-end fw-bold">{{ ev.puntaje_total|number_format(2, '.', ',') }}</td>
                <td>{% include 'partials/eval_badge.html.twig' with {puntaje: ev.puntaje_total} only %}</td>
                <td class="text-end">
                    <a href="{{ url_for('evaluaciones.show', {id: ev.id_evaluacion}) }}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-eye"></i> Ver
                    </a>
                </td>
            </tr>
            {% else %}
            <tr><td colspan="6" class="text-center text-muted py-4">
                No hay evaluaciones registradas. Usa “Nueva evaluación”.
            </td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>
{% endblock %}
```

- [ ] **Step 3: Form (shared create/edit)**

Create `templates/evaluaciones/form.html.twig`:

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}{{ titulo }}{% endblock %}
{% block breadcrumb %}Inicio / Evaluaciones / {{ titulo == 'Editar Evaluación' ? 'Editar' : 'Nueva' }}{% endblock %}

{% block contenido %}
<div class="card-fo" style="max-width:720px">
    {% if errores %}
    <div class="alert alert-danger">
        {% for e in errores %}<div><i class="bi bi-exclamation-triangle-fill me-1"></i>{{ e }}</div>{% endfor %}
    </div>
    {% endif %}

    <form method="POST" action="{{ accion }}">
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

        <div class="row g-3 mb-3">
            <div class="col-sm-6">
                <label class="form-label fw-semibold">Año evaluado</label>
                <input type="number" name="periodo_evaluado" min="2000" max="{{ "now"|date('Y') }}"
                       value="{{ datos.periodo_evaluado ?? "now"|date('Y') }}" class="form-control" required>
            </div>
            <div class="col-sm-6">
                <label class="form-label fw-semibold">Fecha de evaluación</label>
                <input type="date" name="fecha_evaluacion" value="{{ datos.fecha_evaluacion }}" class="form-control" required>
            </div>
        </div>

        <label class="form-label fw-semibold">Criterios (escala 1–100)</label>
        <table class="table align-middle mb-3">
            <thead>
                <tr><th>Criterio</th><th class="text-center" style="width:90px">Peso</th>
                    <th style="width:140px">Puntaje</th></tr>
            </thead>
            <tbody>
                {% for d in detalles %}
                <tr>
                    <td>
                        {{ d.criterio }}
                        <input type="hidden" name="criterio[]" value="{{ d.criterio }}">
                    </td>
                    <td class="text-center">
                        {{ d.peso|number_format(0) }} %
                        <input type="hidden" name="peso[]" value="{{ d.peso }}">
                    </td>
                    <td>
                        <input type="number" name="puntaje[]" value="{{ d.puntaje }}"
                               min="1" max="100" step="0.01" class="form-control" required>
                    </td>
                </tr>
                {% endfor %}
            </tbody>
        </table>

        <div class="mb-3">
            <label class="form-label fw-semibold">Observaciones <span class="text-muted fw-normal">(opcional)</span></label>
            <textarea name="observaciones" class="form-control" rows="3" maxlength="500">{{ datos.observaciones }}</textarea>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Guardar evaluación</button>
            <a href="{{ url_for('evaluaciones.index') }}" class="btn btn-outline-primary">Cancelar</a>
        </div>
    </form>
</div>
{% endblock %}
```

- [ ] **Step 4: Detalle (admin view)**

Create `templates/evaluaciones/detalle.html.twig`:

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Detalle de Evaluación{% endblock %}
{% block breadcrumb %}Inicio / Evaluaciones / Detalle{% endblock %}

{% block contenido %}
<div class="card-fo mb-4" style="max-width:720px">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
            <h5 class="mb-1">{{ evaluacion.nombre }} {{ evaluacion.apellidos }}</h5>
            <div class="text-muted small">
                Año evaluado: {{ evaluacion.periodo_evaluado }} ·
                Fecha: {{ evaluacion.fecha_evaluacion }}
            </div>
        </div>
        {% include 'partials/eval_badge.html.twig' with {puntaje: evaluacion.puntaje_total} only %}
    </div>

    <table class="table align-middle mb-3">
        <thead>
            <tr><th>Criterio</th><th class="text-center">Peso</th>
                <th class="text-end">Puntaje</th><th class="text-end">Aporte</th></tr>
        </thead>
        <tbody>
            {% for d in evaluacion.detalles %}
            <tr>
                <td>{{ d.criterio }}</td>
                <td class="text-center">{{ d.peso|number_format(0) }} %</td>
                <td class="text-end">{{ d.puntaje|number_format(2, '.', ',') }}</td>
                <td class="text-end">{{ (d.puntaje * d.peso / 100)|number_format(2, '.', ',') }}</td>
            </tr>
            {% endfor %}
        </tbody>
        <tfoot>
            <tr class="fw-bold fs-5"><td colspan="3">Puntaje total</td>
                <td class="text-end">{{ evaluacion.puntaje_total|number_format(2, '.', ',') }}</td></tr>
        </tfoot>
    </table>

    {% if evaluacion.observaciones %}
    <div class="mb-3">
        <div class="fw-semibold mb-1">Observaciones</div>
        <p class="text-muted mb-0">{{ evaluacion.observaciones }}</p>
    </div>
    {% endif %}

    <div class="d-flex gap-2">
        <a href="{{ url_for('evaluaciones.index') }}" class="btn btn-outline-primary">Volver</a>
        <a href="{{ url_for('evaluaciones.edit', {id: evaluacion.id_evaluacion}) }}" class="btn btn-primary">
            <i class="bi bi-pencil me-1"></i>Editar
        </a>
        <form method="POST" action="{{ url_for('evaluaciones.destroy', {id: evaluacion.id_evaluacion}) }}"
              onsubmit="return confirm('¿Eliminar esta evaluación?');">
            <button class="btn btn-outline-danger"><i class="bi bi-trash me-1"></i>Eliminar</button>
        </form>
    </div>
</div>
{% endblock %}
```

- [ ] **Step 5: Mis Evaluaciones (employee read-only view)**

Create `templates/evaluaciones/mis_evaluaciones.html.twig`:

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Mis Evaluaciones{% endblock %}
{% block breadcrumb %}Inicio / Mis Evaluaciones{% endblock %}

{% block contenido %}
{% for ev in evaluaciones %}
<div class="card-fo mb-4" style="max-width:720px">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
            <h5 class="mb-1">Evaluación {{ ev.periodo_evaluado }}</h5>
            <div class="text-muted small">Fecha: {{ ev.fecha_evaluacion }}</div>
        </div>
        {% include 'partials/eval_badge.html.twig' with {puntaje: ev.puntaje_total} only %}
    </div>

    <table class="table align-middle mb-3">
        <thead>
            <tr><th>Criterio</th><th class="text-center">Peso</th><th class="text-end">Puntaje</th></tr>
        </thead>
        <tbody>
            {% for d in ev.detalles %}
            <tr>
                <td>{{ d.criterio }}</td>
                <td class="text-center">{{ d.peso|number_format(0) }} %</td>
                <td class="text-end">{{ d.puntaje|number_format(2, '.', ',') }}</td>
            </tr>
            {% endfor %}
        </tbody>
        <tfoot>
            <tr class="fw-bold"><td colspan="2">Puntaje total</td>
                <td class="text-end">{{ ev.puntaje_total|number_format(2, '.', ',') }}</td></tr>
        </tfoot>
    </table>

    {% if ev.observaciones %}
    <div>
        <div class="fw-semibold mb-1">Observaciones</div>
        <p class="text-muted mb-0">{{ ev.observaciones }}</p>
    </div>
    {% endif %}
</div>
{% else %}
<div class="card-fo text-center text-muted py-5" style="max-width:720px">
    <i class="bi bi-star-half fs-2 d-block mb-2"></i>
    No hay evaluaciones registradas para su perfil.
</div>
{% endfor %}
{% endblock %}
```

- [ ] **Step 6: Commit**

```bash
git add templates/partials/eval_badge.html.twig templates/evaluaciones/
git commit -m "feat(evaluaciones): add index, form, detalle and mis_evaluaciones templates"
```

---

### Task 7: DI wiring, routes and sidebar

**Files:**
- Modify: `config/dependencies.php` (append before the closing `];`, after the Liquidación block ~line 364)
- Modify: `config/routes.php` (insert before the `// ── Portal colaborador` comment, ~line 178)
- Modify: `templates/layouts/base.html.twig` (lines 63 and 76-82)

- [ ] **Step 1: Register in the DI container**

In `config/dependencies.php`, after the `\App\Controllers\LiquidacionController::class` entry and before the final `];`, add:

```php
    // ── Evaluaciones ──────────────────────────────────────
    \App\Helpers\EvaluacionCalculadora::class => function (ContainerInterface $c) {
        return new \App\Helpers\EvaluacionCalculadora();
    },

    \App\Repositories\EvaluacionesRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\EvaluacionesRepository($c->get(PDO::class));
    },

    \App\Services\EvaluacionesService::class => function (ContainerInterface $c) {
        return new \App\Services\EvaluacionesService(
            $c->get(\App\Repositories\EvaluacionesRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class),
            $c->get(\App\Helpers\EvaluacionCalculadora::class),
            $c->get('settings')['criterios_evaluacion']
        );
    },

    \App\Controllers\EvaluacionesController::class => function (ContainerInterface $c) {
        return new \App\Controllers\EvaluacionesController(
            $c->get(Twig::class),
            $c->get(\App\Services\EvaluacionesService::class)
        );
    },
```

- [ ] **Step 2: Register routes**

In `config/routes.php`, insert before the `// ── Portal colaborador — pendiente` block:

```php
    // ── Evaluaciones (solo admin) ─────────────────────────
    $app->group('/evaluaciones', function (RouteCollectorProxy $group) {
        $group->get('',                [\App\Controllers\EvaluacionesController::class, 'index'])->setName('evaluaciones.index');
        $group->get('/crear',          [\App\Controllers\EvaluacionesController::class, 'create'])->setName('evaluaciones.create');
        $group->post('/crear',         [\App\Controllers\EvaluacionesController::class, 'store'])->setName('evaluaciones.store');
        $group->get('/{id}',           [\App\Controllers\EvaluacionesController::class, 'show'])->setName('evaluaciones.show');
        $group->get('/{id}/editar',    [\App\Controllers\EvaluacionesController::class, 'edit'])->setName('evaluaciones.edit');
        $group->post('/{id}/editar',   [\App\Controllers\EvaluacionesController::class, 'update'])->setName('evaluaciones.update');
        $group->post('/{id}/eliminar', [\App\Controllers\EvaluacionesController::class, 'destroy'])->setName('evaluaciones.destroy');
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());

    // ── Mis Evaluaciones (cualquier usuario autenticado) ──
    $app->get('/mis-evaluaciones', [\App\Controllers\EvaluacionesController::class, 'misEvaluaciones'])
        ->setName('evaluaciones.mis')
        ->add(new AuthMiddleware());
```

(FastRoute matches the static `/crear` before `/{id}`, same as the existing nominas group — no conflict.)

- [ ] **Step 3: Wire the sidebar**

In `templates/layouts/base.html.twig`:

Replace line 63:
```twig
            <a class="sb-item" href="#"><i class="bi bi-star-half"></i><span>Evaluaciones</span></a>
```
with:
```twig
            <a class="sb-item" href="{{ url_for('evaluaciones.index') }}"><i class="bi bi-star-half"></i><span>Evaluaciones</span></a>
```

In the employee group (`{% else %}` branch, after the "Mis Vacaciones" line), add:
```twig
            <a class="sb-item" href="{{ url_for('evaluaciones.mis') }}"><i class="bi bi-star-half"></i><span>Mis Evaluaciones</span></a>
```

- [ ] **Step 4: Syntax checks and full test suite**

Run: `php -l config/dependencies.php; php -l config/routes.php`
Expected: `No syntax errors detected` (both)

Run: `vendor\bin\phpunit`
Expected: all tests green (existing suites + EvaluacionCalculadoraTest)

- [ ] **Step 5: Commit**

```bash
git add config/dependencies.php config/routes.php templates/layouts/base.html.twig
git commit -m "feat(evaluaciones): wire DI, routes and sidebar links"
```

---

### Task 8: Smoke test and mark module complete

**Files:**
- Modify: `CLAUDE.md` (module status table, row 12)

- [ ] **Step 1: Boot the app and smoke-test routes**

Run (background): `php -S localhost:8080 -t public public/index.php`

Then verify (e.g. with `curl.exe -s -o NUL -w "%{http_code}"`):
- `http://localhost:8080/evaluaciones` → 302 (redirect to /login — middleware active, route resolves)
- `http://localhost:8080/mis-evaluaciones` → 302 (same)

Expected: both return 302, not 500. (Full manual testing in the browser stays in the project-wide "prueba manual" backlog.)

Stop the server afterwards.

- [ ] **Step 2: Update module status in CLAUDE.md**

In the module table, change row 12 from:
```
| 12 | Evaluar Rendimiento | `feature/evaluaciones` | 🔲 Pendiente |
```
to:
```
| 12 | Evaluar Rendimiento | `feature/evaluaciones` | ✅ Completo (pendiente prueba manual) |
```

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: mark Evaluaciones module complete"
```

---

## Self-review notes

- Spec coverage: settings template (Task 1), calculator + tests (Task 2), repository (Task 3), service with all validations incl. one-per-year and audit (Task 4), controller incl. `misEvaluaciones` (Task 5), 4 templates + badge partial (Task 6), DI/routes/sidebar incl. employee link (Task 7).
- The form posts parallel arrays `criterio[]`/`peso[]`/`puntaje[]`; on edit, rows come from the stored detalles, so historical criteria survive template changes (spec §7).
- Weight-sum validation happens in the calculator (single source of truth); the service pre-validates ranges for friendlier messages.
