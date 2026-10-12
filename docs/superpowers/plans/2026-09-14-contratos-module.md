# Módulo Contratos — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Contratos module end-to-end (Repository → Service → Controller → routes → templates) against the `contratos` table that already exists in `database/schema.sql` (Grupo 7, added 23/08/2026) but never got PHP code. Gated to `super_admin`, nested under the existing `/mantenimientos` route group.

**Architecture:** Standard 3-layer module copying the `Puestos` reference structure, plus one extra business action ("Renovar" — closes the employee's current active contract and opens a new one in a single DB transaction). `ContratosRepository` is self-contained (own `empleadosActivos()`/`esEmpleadoActivo()` helpers, no dependency on `EmpleadosRepository` — same pattern `EvaluacionesRepository`/`HorasExtraRepository` already use). `ContratosService` enforces the one-active-contract-per-employee invariant that the DB schema itself doesn't (no `UNIQUE` on `id_empleado`).

**Tech Stack:** PHP 8.1 / Slim 4 / PDO raw / Twig 3 / PHPUnit 11 (existing stack, no new dependencies).

**Spec:** `docs/superpowers/specs/2026-09-14-contratos-module-design.md`

## Global Constraints

- `declare(strict_types=1)` at the top of every PHP file created.
- Controllers never touch PDO directly; Repositories never contain business logic (from `CLAUDE.md`).
- No ORM, no new Composer packages.
- DB identifiers stay `snake_case`; PHP classes `PascalCase`, methods `camelCase`.
- Zero DDL changes — `contratos` already exists exactly as needed in `database/schema.sql`.
- `auditoria.accion` is `ENUM('INSERT','UPDATE','DELETE','LOGIN','LOGOUT')` — a renewal is logged as one `UPDATE` (closing the old contract) + one `INSERT` (the new one), never a new enum value.
- This project has no Repository-, Controller-, or Twig-rendering test suite (confirmed repeatedly across prior plans: no `tests/Repositories` or `tests/Controllers` directory exists anywhere). Only the Service layer gets automated tests; Repository/Controller/template correctness is verified via `php -l`, a Twig parse check, and manual QA against the real local database — matching this project's own established "pendiente prueba manual" convention.
- The local dev database (`lubrimotos_nomina`) is already reimported from `main` with the current `schema.sql`/`seed.sql` and reachable via the AMPPS MySQL client at `"/c/Program Files/Ampps/mysql/bin/mysql.exe" --user=root --password= --host=127.0.0.1`. A disposable PHP built-in server (`"/c/Program Files/Ampps/php/php.exe" -S 127.0.0.1:8899 -t public public/index.php`, requests prefixed with `/lubrimotos/public` per `.env`'s `APP_BASE_PATH`) is the established way to smoke-test routes end-to-end in this environment — used successfully for the Phase 2 verification earlier this session.

---

### Task 1: Repository layer — `ContratosRepository` + seed data

**Files:**
- Create: `src/Repositories/ContratosRepository.php`
- Modify: `config/dependencies.php` (register `ContratosRepository`, alongside `PuestosRepository`'s entry)
- Modify: `database/seed.sql` (3 example contracts, one per seeded employee)

**Interfaces:**
- Produces: `ContratosRepository::findAll/findById/empleadosActivos/esEmpleadoActivo/findActivoPorEmpleado/insert/update/delete/renovar` — all consumed only by Task 2's `ContratosService`.
- Consumes: nothing from other tasks.

This task has no automated test (see Global Constraints). Verification is `php -l`, a full suite run (expected unchanged/green — nothing calls this class yet), and a one-off manual smoke script against the real local DB.

- [ ] **Step 0: Create the feature branch**

Per `CLAUDE.md`'s Gitflow convention (`feature/*` → `develop` → `main`):

Run: `git checkout develop && git pull && git checkout -b feature/contratos-module`
Expected: `Switched to a new branch 'feature/contratos-module'`.

- [ ] **Step 1: Create `src/Repositories/ContratosRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * ContratosRepository — SQL para `contratos` (historial de contratos por empleado).
 * Sin lógica de negocio; solo acceso a datos.
 */
class ContratosRepository
{
    public function __construct(private readonly PDO $pdo) {}

    // ── Lectura ───────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function findAll(?int $idEmpleado = null, ?string $estado = null, ?string $tipo = null, ?string $q = null): array
    {
        $sql = "SELECT c.id_contrato, c.id_empleado, c.tipo_contrato, c.salario_pactado,
                       c.jornada, c.fecha_inicio, c.fecha_fin, c.estado,
                       per.nombre, per.apellidos
                  FROM contratos c
                  JOIN empleados e ON e.id_empleado = c.id_empleado
                  JOIN persona per ON per.id_persona = e.id_persona";
        $where  = [];
        $params = [];
        if ($idEmpleado !== null) {
            $where[] = 'c.id_empleado = :empleado';
            $params[':empleado'] = $idEmpleado;
        }
        if ($estado !== null) {
            $where[] = 'c.estado = :estado';
            $params[':estado'] = $estado;
        }
        if ($tipo !== null) {
            $where[] = 'c.tipo_contrato = :tipo';
            $params[':tipo'] = $tipo;
        }
        if ($q !== null && $q !== '') {
            $where[] = "(CONCAT(per.nombre, ' ', per.apellidos) LIKE :q OR CONCAT(per.apellidos, ', ', per.nombre) LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY c.fecha_inicio DESC, per.apellidos ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.*, per.nombre, per.apellidos
               FROM contratos c
               JOIN empleados e ON e.id_empleado = c.id_empleado
               JOIN persona per ON per.id_persona = e.id_persona
              WHERE c.id_contrato = :id
              LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** Empleados activos, para el <select> del formulario de creación. @return array<int, array<string, mixed>> */
    public function empleadosActivos(): array
    {
        return $this->pdo->query(
            "SELECT e.id_empleado, per.nombre, per.apellidos
               FROM empleados e
               JOIN persona per ON per.id_persona = e.id_persona
              WHERE e.estado = 'activo'
              ORDER BY per.apellidos"
        )->fetchAll();
    }

    public function esEmpleadoActivo(int $idEmpleado): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM empleados WHERE id_empleado = :id AND estado = 'activo'");
        $stmt->execute([':id' => $idEmpleado]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /** Contrato vigente (estado='activo') del empleado, o null si no tiene. @return array<string, mixed>|null */
    public function findActivoPorEmpleado(int $idEmpleado): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id_contrato FROM contratos WHERE id_empleado = :e AND estado = 'activo' LIMIT 1"
        );
        $stmt->execute([':e' => $idEmpleado]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    // ── Escritura ─────────────────────────────────────────

    /** @param array<string, mixed> $d */
    public function insert(array $d): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO contratos (id_empleado, tipo_contrato, salario_pactado, jornada, fecha_inicio, fecha_fin, estado)
             VALUES (:id_empleado, :tipo_contrato, :salario_pactado, :jornada, :fecha_inicio, :fecha_fin, :estado)'
        );
        $stmt->execute($this->bind($d));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Edita solo los datos "de hecho" del contrato (tipo/salario/jornada/fecha_inicio).
     * `estado`/`fecha_fin` no se editan aquí — solo cambian vía renovar().
     *
     * @param array<string, mixed> $d
     */
    public function update(int $id, array $d): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE contratos SET
                tipo_contrato = :tipo_contrato, salario_pactado = :salario_pactado,
                jornada = :jornada, fecha_inicio = :fecha_inicio
              WHERE id_contrato = :id'
        );
        $stmt->execute([
            ':tipo_contrato'   => $d['tipo_contrato'],
            ':salario_pactado' => $d['salario_pactado'],
            ':jornada'         => $d['jornada'],
            ':fecha_inicio'    => $d['fecha_inicio'],
            ':id'              => $id,
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM contratos WHERE id_contrato = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Renovación: cierra el contrato vigente (estado=finalizado, fecha_fin=$fechaFinAnterior)
     * y abre uno nuevo (estado=activo, fecha_fin=9999-12-31) para el mismo empleado — en una
     * sola transacción.
     *
     * @param array<string, mixed> $nuevo claves: tipo_contrato, salario_pactado, jornada, fecha_inicio
     * @return int id_contrato del nuevo contrato
     */
    public function renovar(int $idContratoActual, int $idEmpleado, string $fechaFinAnterior, array $nuevo): int
    {
        $this->pdo->beginTransaction();
        try {
            $cerrar = $this->pdo->prepare(
                "UPDATE contratos SET estado = 'finalizado', fecha_fin = :f WHERE id_contrato = :id"
            );
            $cerrar->execute([':f' => $fechaFinAnterior, ':id' => $idContratoActual]);

            $abrir = $this->pdo->prepare(
                "INSERT INTO contratos (id_empleado, tipo_contrato, salario_pactado, jornada, fecha_inicio, fecha_fin, estado)
                 VALUES (:id_empleado, :tipo_contrato, :salario_pactado, :jornada, :fecha_inicio, '9999-12-31', 'activo')"
            );
            $abrir->execute([
                ':id_empleado'     => $idEmpleado,
                ':tipo_contrato'   => $nuevo['tipo_contrato'],
                ':salario_pactado' => $nuevo['salario_pactado'],
                ':jornada'         => $nuevo['jornada'],
                ':fecha_inicio'    => $nuevo['fecha_inicio'],
            ]);
            $id = (int)$this->pdo->lastInsertId();

            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    private function bind(array $d): array
    {
        return [
            ':id_empleado'     => $d['id_empleado'],
            ':tipo_contrato'   => $d['tipo_contrato'],
            ':salario_pactado' => $d['salario_pactado'],
            ':jornada'         => $d['jornada'],
            ':fecha_inicio'    => $d['fecha_inicio'],
            ':fecha_fin'       => $d['fecha_fin'],
            ':estado'          => $d['estado'],
        ];
    }
}
```

- [ ] **Step 2: Register `ContratosRepository` in `config/dependencies.php`**

Add next to the existing `PuestosRepository::class` entry:

```php
\App\Repositories\ContratosRepository::class => function (ContainerInterface $c) {
    return new \App\Repositories\ContratosRepository($c->get(PDO::class));
},
```

- [ ] **Step 3: Add seed data to `database/seed.sql`**

Insert this block right after `-- ── Empleados ──` and before `-- ── Feriados 2026 ──` (FK order: `empleados` must already exist):

```sql
-- ── Contratos ─────────────────────────────────────────────
INSERT INTO `contratos` (`id_empleado`, `tipo_contrato`, `salario_pactado`, `jornada`, `fecha_inicio`, `fecha_fin`, `estado`) VALUES
(1, 'tiempo_indefinido', 750000.00, 'tiempo_completo', '2015-01-10', '9999-12-31', 'activo'),
(2, 'tiempo_indefinido', 450000.00, 'tiempo_completo', '2020-03-01', '9999-12-31', 'activo'),
(3, 'plazo_fijo',         450000.00, 'tiempo_completo', '2021-06-15', '9999-12-31', 'activo');
```

- [ ] **Step 4: Reimport the local DB and smoke-test the Repository directly**

Run:
```bash
"/c/Program Files/Ampps/mysql/bin/mysql.exe" --user=root --password= --host=127.0.0.1 -e "DROP DATABASE IF EXISTS lubrimotos_nomina; CREATE DATABASE lubrimotos_nomina CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"/c/Program Files/Ampps/mysql/bin/mysql.exe" --user=root --password= --host=127.0.0.1 lubrimotos_nomina < database/schema.sql
"/c/Program Files/Ampps/mysql/bin/mysql.exe" --user=root --password= --host=127.0.0.1 lubrimotos_nomina < database/seed.sql
```
Expected: both imports succeed with no errors (the new `contratos` INSERT is the only new content vs. what was already reimported today).

Then a one-off throwaway script (save under the session's scratchpad, not committed) to exercise every Repository method directly against PDO:

```php
<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $_ENV['DB_HOST'], $_ENV['DB_PORT'], $_ENV['DB_NAME']),
    $_ENV['DB_USER'], $_ENV['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$repo = new App\Repositories\ContratosRepository($pdo);

var_dump($repo->findAll());                     // expect 3 rows, newest fecha_inicio first
var_dump($repo->findById(1));                   // expect row + nombre/apellidos joined
var_dump($repo->empleadosActivos());            // expect 3 employees
var_dump($repo->esEmpleadoActivo(1));           // expect true
var_dump($repo->esEmpleadoActivo(9999));        // expect false
var_dump($repo->findActivoPorEmpleado(1));      // expect the seeded id_contrato
$nuevoId = $repo->renovar(1, 1, '2026-09-13', [
    'tipo_contrato' => 'tiempo_indefinido', 'salario_pactado' => 800000.00,
    'jornada' => 'tiempo_completo', 'fecha_inicio' => '2026-09-14',
]);
var_dump($nuevoId);                             // expect a new int id
var_dump($repo->findById(1)['estado']);         // expect 'finalizado'
var_dump($repo->findActivoPorEmpleado(1)['id_contrato'] ?? null); // expect $nuevoId
```

Expected: no PDOException; the assertions in the comments hold. Delete the scratchpad script when done (nothing to commit here).

- [ ] **Step 5: Syntax-check and run the full suite**

Run: `php -l src/Repositories/ContratosRepository.php`
Expected: `No syntax errors detected`.

Run: `vendor/bin/phpunit`
Expected: green, same 82 tests as before this task (nothing yet calls `ContratosRepository`).

- [ ] **Step 6: Commit**

```bash
git add src/Repositories/ContratosRepository.php config/dependencies.php database/seed.sql
git commit -m "feat(contratos): add ContratosRepository and seed example contracts"
```

---

### Task 2: `ContratosService` (TDD)

**Files:**
- Create: `src/Services/ContratosService.php`
- Modify: `config/dependencies.php` (register `ContratosService`)
- Create: `tests/Services/ContratosServiceTest.php`

**Interfaces:**
- Consumes: `ContratosRepository` (Task 1) — mocked in tests, real instance wired in `dependencies.php`.
- Produces: `ContratosService::listar/obtener/datosFormulario/crear/actualizar/renovar/eliminar` — all consumed only by Task 3's `ContratosController`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Services/ContratosServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\ContratosRepository;
use App\Services\ContratosService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ContratosServiceTest extends TestCase
{
    private function service(ContratosRepository $repo): ContratosService
    {
        return new ContratosService($repo, $this->createMock(AuditoriaRepository::class));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function datosValidos(array $overrides = []): array
    {
        return array_merge([
            'id_empleado'      => '1',
            'tipo_contrato'    => 'tiempo_indefinido',
            'salario_pactado'  => '450000.00',
            'jornada'          => 'tiempo_completo',
            'fecha_inicio'     => '2026-01-01',
        ], $overrides);
    }

    public function testCrearInsertaComoActivoConFechaFinCentinela(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('esEmpleadoActivo')->willReturn(true);
        $repo->method('findActivoPorEmpleado')->willReturn(null);
        $repo->expects(self::once())->method('insert')
            ->with(self::callback(fn(array $d) =>
                $d['id_empleado'] === 1 && $d['estado'] === 'activo' && $d['fecha_fin'] === '9999-12-31'
                && $d['tipo_contrato'] === 'tiempo_indefinido' && $d['salario_pactado'] === 450000.0
            ))
            ->willReturn(10);

        $id = $this->service($repo)->crear($this->datosValidos(), 1, '127.0.0.1');

        self::assertSame(10, $id);
    }

    public function testCrearRechazaEmpleadoInexistenteOInactivo(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('esEmpleadoActivo')->willReturn(false);
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('empleado activo válido');

        $this->service($repo)->crear($this->datosValidos(), 1, '127.0.0.1');
    }

    public function testCrearRechazaEmpleadoConContratoYaActivo(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('esEmpleadoActivo')->willReturn(true);
        $repo->method('findActivoPorEmpleado')->willReturn(['id_contrato' => 5]);
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Renovar');

        $this->service($repo)->crear($this->datosValidos(), 1, '127.0.0.1');
    }

    public function testCrearRechazaTipoContratoInvalido(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('esEmpleadoActivo')->willReturn(true);
        $repo->method('findActivoPorEmpleado')->willReturn(null);
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);

        $this->service($repo)->crear($this->datosValidos(['tipo_contrato' => 'no_existe']), 1, '127.0.0.1');
    }

    public function testCrearRechazaSalarioNoPositivo(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('esEmpleadoActivo')->willReturn(true);
        $repo->method('findActivoPorEmpleado')->willReturn(null);
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);

        $this->service($repo)->crear($this->datosValidos(['salario_pactado' => '0']), 1, '127.0.0.1');
    }

    public function testActualizarLanzaSiNoExiste(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('update');

        $this->expectException(RuntimeException::class);

        $this->service($repo)->actualizar(99, $this->datosValidos(), 1, '127.0.0.1');
    }

    public function testRenovarLanzaSiElContratoNoEstaActivo(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('findById')->willReturn([
            'id_contrato' => 1, 'id_empleado' => 1, 'estado' => 'finalizado', 'fecha_inicio' => '2020-01-01',
        ]);
        $repo->expects(self::never())->method('renovar');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('vigente');

        $this->service($repo)->renovar(1, $this->datosValidos(['fecha_inicio' => '2026-01-01']), 1, '127.0.0.1');
    }

    public function testRenovarRechazaFechaInicioNoPosteriorALaActual(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('findById')->willReturn([
            'id_contrato' => 1, 'id_empleado' => 1, 'estado' => 'activo', 'fecha_inicio' => '2026-01-01',
        ]);
        $repo->expects(self::never())->method('renovar');

        $this->expectException(InvalidArgumentException::class);

        $this->service($repo)->renovar(1, $this->datosValidos(['fecha_inicio' => '2025-12-31']), 1, '127.0.0.1');
    }

    public function testRenovarCalculaFechaFinUnDiaAntesYAuditaDosVeces(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('findById')->willReturn([
            'id_contrato' => 1, 'id_empleado' => 7, 'estado' => 'activo', 'fecha_inicio' => '2026-01-01',
        ]);
        $repo->expects(self::once())->method('renovar')
            ->with(1, 7, '2026-02-28', self::callback(fn(array $c) => $c['fecha_inicio'] === '2026-03-01'))
            ->willReturn(20);

        $auditoria = $this->createMock(AuditoriaRepository::class);
        $auditoria->expects(self::exactly(2))->method('insert');

        $service = new ContratosService($repo, $auditoria);
        $nuevoId = $service->renovar(1, $this->datosValidos(['fecha_inicio' => '2026-03-01']), 1, '127.0.0.1');

        self::assertSame(20, $nuevoId);
    }

    public function testEliminarLanzaSiNoExiste(): void
    {
        $repo = $this->createMock(ContratosRepository::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('delete');

        $this->expectException(RuntimeException::class);

        $this->service($repo)->eliminar(99, 1, '127.0.0.1');
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Services/ContratosServiceTest.php`
Expected: FAIL — `ContratosService` doesn't exist yet (class-not-found errors).

- [ ] **Step 3: Create `src/Services/ContratosService.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\ContratosRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * ContratosService
 *
 * Regla de negocio central: un empleado no puede tener más de un contrato
 * con estado='activo' a la vez. crear() la exige al dar de alta; renovar()
 * la mantiene (cierra el actual antes de abrir el nuevo, en una transacción).
 */
class ContratosService
{
    private const TIPOS    = ['tiempo_indefinido', 'plazo_fijo', 'obra_determinada'];
    private const JORNADAS = ['tiempo_completo', 'medio_tiempo'];

    public function __construct(
        private readonly ContratosRepository $repo,
        private readonly AuditoriaRepository  $auditoriaRepo
    ) {}

    // ── Consultas ─────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function listar(?int $idEmpleado = null, ?string $estado = null, ?string $tipo = null, ?string $q = null): array
    {
        return $this->repo->findAll($idEmpleado, $estado, $tipo, $q);
    }

    /**
     * @return array<string, mixed>
     * @throws RuntimeException si no existe
     */
    public function obtener(int $id): array
    {
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new RuntimeException('Contrato no encontrado.');
        }
        return $row;
    }

    /** @return array{empleados: array<int, array<string, mixed>>} */
    public function datosFormulario(): array
    {
        return ['empleados' => $this->repo->empleadosActivos()];
    }

    // ── Comandos ──────────────────────────────────────────

    /**
     * @param array<string, mixed> $datos
     * @throws InvalidArgumentException
     * @return int id_contrato del nuevo contrato
     */
    public function crear(array $datos, int $loggedInId, string $ip): int
    {
        $errores    = [];
        $idEmpleado = $this->idEmpleadoValidado($datos, $errores);
        $campos     = $this->validarCampos($datos, $errores);

        if ($idEmpleado !== null && $this->repo->findActivoPorEmpleado($idEmpleado) !== null) {
            $errores[] = 'Ese empleado ya tiene un contrato vigente. Use "Renovar" en la lista en vez de crear uno nuevo.';
        }

        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $id = $this->repo->insert([
            'id_empleado'     => $idEmpleado,
            'tipo_contrato'   => $campos['tipo_contrato'],
            'salario_pactado' => $campos['salario_pactado'],
            'jornada'         => $campos['jornada'],
            'fecha_inicio'    => $campos['fecha_inicio'],
            'fecha_fin'       => '9999-12-31',
            'estado'          => 'activo',
        ]);

        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'contratos', $id, $ip);
        return $id;
    }

    /**
     * Edita tipo/salario/jornada/fecha_inicio de un contrato existente.
     * No cambia `id_empleado` ni `estado`/`fecha_fin` (eso es exclusivo de renovar()).
     *
     * @param array<string, mixed> $datos
     * @throws InvalidArgumentException|RuntimeException
     */
    public function actualizar(int $id, array $datos, int $loggedInId, string $ip): void
    {
        $this->obtener($id); // valida que existe

        $errores = [];
        $campos  = $this->validarCampos($datos, $errores);
        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $this->repo->update($id, $campos);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'contratos', $id, $ip);
    }

    /**
     * Renueva el contrato vigente de un empleado: lo cierra (fecha_fin = un día
     * antes del nuevo inicio, estado = finalizado) y abre uno nuevo activo.
     *
     * @param array<string, mixed> $datos datos del NUEVO contrato
     * @throws InvalidArgumentException|RuntimeException
     * @return int id_contrato del nuevo contrato
     */
    public function renovar(int $idContratoActual, array $datos, int $loggedInId, string $ip): int
    {
        $actual = $this->obtener($idContratoActual);
        if ($actual['estado'] !== 'activo') {
            throw new RuntimeException('Solo se puede renovar un contrato vigente (activo).');
        }

        $errores = [];
        $campos  = $this->validarCampos($datos, $errores);

        if (empty($errores)) {
            $fechaInicioNueva  = new DateTimeImmutable($campos['fecha_inicio']);
            $fechaInicioActual = new DateTimeImmutable((string)$actual['fecha_inicio']);
            if ($fechaInicioNueva <= $fechaInicioActual) {
                $errores[] = 'La fecha de inicio del nuevo contrato debe ser posterior a la del contrato vigente.';
            }
        }

        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $fechaFinAnterior = (new DateTimeImmutable($campos['fecha_inicio']))
            ->modify('-1 day')->format('Y-m-d');

        $nuevoId = $this->repo->renovar(
            (int)$actual['id_contrato'],
            (int)$actual['id_empleado'],
            $fechaFinAnterior,
            $campos
        );

        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'contratos', $idContratoActual, $ip, 'Cierre por renovación');
        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'contratos', $nuevoId, $ip, 'Alta por renovación del contrato #' . $idContratoActual);

        return $nuevoId;
    }

    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $this->obtener($id); // valida que existe
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'contratos', $id, $ip);
    }

    // ── Validación ────────────────────────────────────────

    /**
     * @param array<string, mixed> $d
     * @param string[] $errores referencia acumuladora
     */
    private function idEmpleadoValidado(array $d, array &$errores): ?int
    {
        $valor = $d['id_empleado'] ?? '';
        $id    = is_numeric($valor) ? (int)$valor : 0;
        if ($id <= 0 || !$this->repo->esEmpleadoActivo($id)) {
            $errores[] = 'Debe seleccionar un empleado activo válido.';
            return null;
        }
        return $id;
    }

    /**
     * Valida tipo_contrato/salario_pactado/jornada/fecha_inicio — comunes a
     * crear/actualizar/renovar.
     *
     * @param array<string, mixed> $d
     * @param string[] $errores referencia acumuladora
     * @return array{tipo_contrato: string, salario_pactado: float, jornada: string, fecha_inicio: string}
     */
    private function validarCampos(array $d, array &$errores): array
    {
        $tipo = trim((string)($d['tipo_contrato'] ?? ''));
        if (!in_array($tipo, self::TIPOS, true)) {
            $errores[] = 'Debe seleccionar un tipo de contrato válido.';
        }

        $jornada = trim((string)($d['jornada'] ?? ''));
        if ($jornada === '') {
            $jornada = 'tiempo_completo';
        }
        if (!in_array($jornada, self::JORNADAS, true)) {
            $errores[] = 'Debe seleccionar una jornada válida.';
        }

        $salarioRaw = $d['salario_pactado'] ?? '';
        $salario    = is_numeric($salarioRaw) ? (float)$salarioRaw : -1.0;
        if ($salario <= 0 || $salario > 99999999.99) {
            $errores[] = 'El salario pactado debe ser un número mayor a 0.';
        }

        $fechaInicio = trim((string)($d['fecha_inicio'] ?? ''));
        if (DateTimeImmutable::createFromFormat('Y-m-d', $fechaInicio) === false) {
            $errores[] = 'La fecha de inicio es obligatoria y debe tener formato válido.';
        }

        return [
            'tipo_contrato'   => $tipo,
            'salario_pactado' => $salario,
            'jornada'         => $jornada,
            'fecha_inicio'    => $fechaInicio,
        ];
    }
}
```

- [ ] **Step 4: Register `ContratosService` in `config/dependencies.php`**

Add next to the existing `PuestosService::class` entry:

```php
\App\Services\ContratosService::class => function (ContainerInterface $c) {
    return new \App\Services\ContratosService(
        $c->get(\App\Repositories\ContratosRepository::class),
        $c->get(\App\Repositories\AuditoriaRepository::class)
    );
},
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Services/ContratosServiceTest.php`
Expected: all 10 tests PASS.

- [ ] **Step 6: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: OK, no failures (82 previous + 10 new = 92).

- [ ] **Step 7: Commit**

```bash
git add src/Services/ContratosService.php config/dependencies.php tests/Services/ContratosServiceTest.php
git commit -m "feat(contratos): add ContratosService with the renovar business rule (TDD)"
```

---

### Task 3: Controller, routes, templates, navigation

**Files:**
- Create: `src/Controllers/ContratosController.php`
- Modify: `config/dependencies.php` (register `ContratosController`)
- Modify: `config/routes.php` (new `use` import + 7 routes inside the existing `/mantenimientos` group)
- Create: `templates/contratos/index.html.twig`
- Create: `templates/contratos/form.html.twig`
- Modify: `templates/layouts/base.html.twig` (nav link under "Administración")

**Interfaces:**
- Consumes: `ContratosService` (Task 2).
- Produces: nothing consumed by later tasks (Task 4 is docs-only).

No automated test for this task (see Global Constraints — no Controller/Twig suite in this project). Verification is `php -l`, a Twig parse check on both new templates, the full PHPUnit suite (unchanged), and an end-to-end manual smoke test against the real local DB via the disposable PHP built-in server.

- [ ] **Step 1: Create `src/Controllers/ContratosController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ContratosService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class ContratosController
{
    public function __construct(
        private readonly Twig             $twig,
        private readonly ContratosService  $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $q      = trim($params['q'] ?? '');

        return $this->twig->render($response, 'contratos/index.html.twig', [
            'contratos'    => $this->service->listar(
                null,
                ($params['estado'] ?? '') !== '' ? $params['estado'] : null,
                ($params['tipo'] ?? '') !== '' ? $params['tipo'] : null,
                $q !== '' ? $q : null
            ),
            'q'            => $q,
            'estadoFiltro' => $params['estado'] ?? '',
            'tipoFiltro'   => $params['tipo'] ?? '',
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'contratos/form.html.twig', [
            'titulo'    => 'Nuevo Contrato',
            'accion'    => 'crear',
            'contrato'  => [],
            'errores'   => [],
            'empleados' => $this->service->datosFormulario()['empleados'],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $datos      = (array)$request->getParsedBody();
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Contrato creado exitosamente.';
            return $this->redirect($request, $response, 'contratos.index');
        } catch (InvalidArgumentException $e) {
            return $this->twig->render($response->withStatus(422), 'contratos/form.html.twig', [
                'titulo'    => 'Nuevo Contrato',
                'accion'    => 'crear',
                'contrato'  => $datos,
                'errores'   => [$e->getMessage()],
                'empleados' => $this->service->datosFormulario()['empleados'],
            ]);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            $contrato = $this->service->obtener((int)$args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Contrato no encontrado.';
            return $this->redirect($request, $response, 'contratos.index');
        }

        return $this->twig->render($response, 'contratos/form.html.twig', [
            'titulo'   => 'Editar Contrato',
            'accion'   => 'editar',
            'contrato' => $contrato,
            'errores'  => [],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id         = (int)$args['id'];
        $datos      = (array)$request->getParsedBody();
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->actualizar($id, $datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Contrato actualizado correctamente.';
            return $this->redirect($request, $response, 'contratos.index');
        } catch (InvalidArgumentException $e) {
            return $this->twig->render($response->withStatus(422), 'contratos/form.html.twig', [
                'titulo'   => 'Editar Contrato',
                'accion'   => 'editar',
                'contrato' => array_merge(['id_contrato' => $id], $datos),
                'errores'  => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $this->redirect($request, $response, 'contratos.index');
        }
    }

    public function renovar(Request $request, Response $response, array $args): Response
    {
        $id         = (int)$args['id'];
        $datos      = (array)$request->getParsedBody();
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->renovar($id, $datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Contrato renovado correctamente.';
        } catch (InvalidArgumentException|RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $this->redirect($request, $response, 'contratos.index');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->eliminar((int)$args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Contrato eliminado correctamente.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $this->redirect($request, $response, 'contratos.index');
    }

    // ── Helpers ───────────────────────────────────────────

    private function usuarioId(Request $request): int
    {
        return (int)$request->getAttribute('usuario')['id'];
    }

    private function urlFor(Request $request, string $routeName, array $args = []): string
    {
        return RouteContext::fromRequest($request)->getRouteParser()->urlFor($routeName, $args);
    }

    private function redirect(Request $request, Response $response, string $routeName, array $args = []): Response
    {
        return $response->withHeader('Location', $this->urlFor($request, $routeName, $args))->withStatus(302);
    }

    private function consumeFlash(string $key): ?string
    {
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }
}
```

- [ ] **Step 2: Register `ContratosController` in `config/dependencies.php`**

```php
\App\Controllers\ContratosController::class => function (ContainerInterface $c) {
    return new \App\Controllers\ContratosController(
        $c->get(Twig::class),
        $c->get(\App\Services\ContratosService::class)
    );
},
```

- [ ] **Step 3: Add routes to `config/routes.php`**

Add `use App\Controllers\ContratosController;` to the `use` block, then add these 7 lines inside the existing `$app->group('/mantenimientos', ...)` closure (after the Feriados block, before Usuarios — order not critical):

```php
// Contratos
$group->get('/contratos',                [ContratosController::class, 'index'])->setName('contratos.index');
$group->get('/contratos/crear',          [ContratosController::class, 'create'])->setName('contratos.create');
$group->post('/contratos/crear',         [ContratosController::class, 'store'])->setName('contratos.store');
$group->get('/contratos/{id}/editar',    [ContratosController::class, 'edit'])->setName('contratos.edit');
$group->post('/contratos/{id}/editar',   [ContratosController::class, 'update'])->setName('contratos.update');
$group->post('/contratos/{id}/renovar',  [ContratosController::class, 'renovar'])->setName('contratos.renovar');
$group->post('/contratos/{id}/eliminar', [ContratosController::class, 'destroy'])->setName('contratos.destroy');
```

- [ ] **Step 4: Create `templates/contratos/index.html.twig`**

Same skeleton as `templates/puestos/index.html.twig` (breadcrumb Dashboard → Mantenimientos → Contratos, `?q=` search form, table, delete-confirmation modal), adapted:

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Contratos{% endblock %}

{% block breadcrumb %}
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ url_for('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item">Mantenimientos</li>
        <li class="breadcrumb-item active">Contratos</li>
    </ol>
</nav>
{% endblock %}

{% block contenido %}
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-file-earmark-text me-2 text-primary"></i>Contratos</h2>
    <a href="{{ url_for('contratos.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Nuevo Contrato
    </a>
</div>

<form method="GET" action="{{ url_for('contratos.index') }}" class="d-flex gap-2 mb-3 flex-wrap">
    <input type="text" name="q" value="{{ q }}" class="form-control form-control-sm"
           placeholder="Buscar por empleado…" style="max-width:250px">
    <select name="estado" class="form-select form-select-sm" style="max-width:160px" onchange="this.form.submit()">
        <option value="">Todos los estados</option>
        <option value="activo"     {{ estadoFiltro == 'activo' ? 'selected' : '' }}>Activo</option>
        <option value="finalizado" {{ estadoFiltro == 'finalizado' ? 'selected' : '' }}>Finalizado</option>
    </select>
    <select name="tipo" class="form-select form-select-sm" style="max-width:200px" onchange="this.form.submit()">
        <option value="">Todos los tipos</option>
        <option value="tiempo_indefinido" {{ tipoFiltro == 'tiempo_indefinido' ? 'selected' : '' }}>Tiempo indefinido</option>
        <option value="plazo_fijo"        {{ tipoFiltro == 'plazo_fijo' ? 'selected' : '' }}>Plazo fijo</option>
        <option value="obra_determinada"  {{ tipoFiltro == 'obra_determinada' ? 'selected' : '' }}>Obra determinada</option>
    </select>
    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
    {% if q or estadoFiltro or tipoFiltro %}
    <a href="{{ url_for('contratos.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
    {% endif %}
</form>

{% if contratos|length == 0 %}
<div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i>No hay contratos registrados con ese filtro.
</div>
{% else %}
<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover table-striped align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <th>Empleado</th>
                    <th>Tipo</th>
                    <th class="text-end">Salario Pactado (₡)</th>
                    <th>Jornada</th>
                    <th>Inicio</th>
                    <th>Fin</th>
                    <th class="text-center">Estado</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                {% for c in contratos %}
                <tr>
                    <td class="fw-semibold">{{ c.nombre }} {{ c.apellidos }}</td>
                    <td>{{ c.tipo_contrato|replace({'_': ' '})|capitalize }}</td>
                    <td class="text-end font-monospace">{{ c.salario_pactado|number_format(2, '.', ',') }}</td>
                    <td>{{ c.jornada == 'tiempo_completo' ? 'Tiempo completo' : 'Medio tiempo' }}</td>
                    <td>{{ c.fecha_inicio }}</td>
                    <td>{{ c.fecha_fin == '9999-12-31' ? 'Vigente' : c.fecha_fin }}</td>
                    <td class="text-center">
                        <span class="badge {{ c.estado == 'activo' ? 'bg-success' : 'bg-secondary' }}">
                            {{ c.estado|capitalize }}
                        </span>
                    </td>
                    <td class="text-center text-nowrap">
                        <a href="{{ url_for('contratos.edit', {'id': c.id_contrato}) }}"
                           class="btn btn-sm btn-outline-primary me-1" title="Editar">
                            <i class="bi bi-pencil-square"></i>
                        </a>
                        {% if c.estado == 'activo' %}
                        <button type="button" class="btn btn-sm btn-outline-success me-1" title="Renovar"
                                data-bs-toggle="modal" data-bs-target="#modalRenovar"
                                data-id="{{ c.id_contrato }}" data-info="{{ c.nombre }} {{ c.apellidos }}">
                            <i class="bi bi-arrow-repeat"></i>
                        </button>
                        {% endif %}
                        <button type="button" class="btn btn-sm btn-outline-danger" title="Eliminar"
                                data-bs-toggle="modal" data-bs-target="#modalEliminar"
                                data-id="{{ c.id_contrato }}" data-info="{{ c.nombre }} {{ c.apellidos }}">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </td>
                </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
    <div class="card-footer text-muted small">Total: {{ contratos|length }} contrato(s).</div>
</div>
{% endif %}

{# ── Modal Renovar ── #}
<div class="modal fade" id="modalRenovar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="formRenovar" method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">Renovar contrato de <span id="modalRenovarInfo"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Tipo de contrato</label>
                        <select class="form-select" name="tipo_contrato" required>
                            <option value="tiempo_indefinido">Tiempo indefinido</option>
                            <option value="plazo_fijo">Plazo fijo</option>
                            <option value="obra_determinada">Obra determinada</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Salario pactado (₡)</label>
                        <input type="number" class="form-control" name="salario_pactado" min="0.01" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Jornada</label>
                        <select class="form-select" name="jornada" required>
                            <option value="tiempo_completo">Tiempo completo</option>
                            <option value="medio_tiempo">Medio tiempo</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Fecha de inicio del nuevo contrato</label>
                        <input type="date" class="form-control" name="fecha_inicio" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Renovar</button>
                </div>
            </form>
        </div>
    </div>
</div>

{# ── Modal Eliminar ── #}
<div class="modal fade" id="modalEliminar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-1"></i>Confirmar eliminación</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">¿Eliminar el contrato de <strong id="modalEliminarInfo"></strong>? Esta acción no se puede deshacer.</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <form id="formEliminar" method="POST" action="">
                    <button type="submit" class="btn btn-danger"><i class="bi bi-trash3 me-1"></i>Eliminar</button>
                </form>
            </div>
        </div>
    </div>
</div>
{% endblock %}

{% block scripts %}
<script>
document.getElementById('modalRenovar').addEventListener('show.bs.modal', function (event) {
    const btn = event.relatedTarget;
    document.getElementById('modalRenovarInfo').textContent = btn.getAttribute('data-info');
    document.getElementById('formRenovar').action = '/mantenimientos/contratos/' + btn.getAttribute('data-id') + '/renovar';
});
document.getElementById('modalEliminar').addEventListener('show.bs.modal', function (event) {
    const btn = event.relatedTarget;
    document.getElementById('modalEliminarInfo').textContent = btn.getAttribute('data-info');
    document.getElementById('formEliminar').action = '/mantenimientos/contratos/' + btn.getAttribute('data-id') + '/eliminar';
});
</script>
{% endblock %}
```

- [ ] **Step 5: Create `templates/contratos/form.html.twig`**

Same card skeleton as `templates/puestos/form.html.twig`:

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}{{ titulo }}{% endblock %}

{% block breadcrumb %}
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ url_for('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item">Mantenimientos</li>
        <li class="breadcrumb-item"><a href="{{ url_for('contratos.index') }}">Contratos</a></li>
        <li class="breadcrumb-item active">{{ accion == 'crear' ? 'Nuevo' : 'Editar' }}</li>
    </ol>
</nav>
{% endblock %}

{% block contenido %}
<div class="row justify-content-center">
    <div class="col-md-7 col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>{{ titulo }}</h5>
            </div>
            <div class="card-body">
                {% if errores|length > 0 %}
                <div class="alert alert-danger">
                    <ul class="mb-0">{% for error in errores %}<li>{{ error }}</li>{% endfor %}</ul>
                </div>
                {% endif %}

                {% if accion == 'crear' %}
                    {% set form_action = url_for('contratos.store') %}
                {% else %}
                    {% set form_action = url_for('contratos.update', {'id': contrato.id_contrato}) %}
                {% endif %}

                <form method="POST" action="{{ form_action }}" novalidate>
                    {% if accion == 'crear' %}
                    <div class="mb-3">
                        <label for="id_empleado" class="form-label fw-semibold">Empleado <span class="text-danger">*</span></label>
                        <select class="form-select" id="id_empleado" name="id_empleado" required>
                            <option value="">— Seleccione —</option>
                            {% for e in empleados %}
                            <option value="{{ e.id_empleado }}" {{ (contrato.id_empleado ?? '') == e.id_empleado ? 'selected' : '' }}>
                                {{ e.nombre }} {{ e.apellidos }}
                            </option>
                            {% endfor %}
                        </select>
                        <div class="form-text">Solo se listan empleados activos. Un empleado con un contrato vigente debe usar "Renovar" en vez de crear uno nuevo.</div>
                    </div>
                    {% else %}
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Empleado</label>
                        <input type="text" class="form-control" value="{{ contrato.nombre }} {{ contrato.apellidos }}" disabled>
                    </div>
                    {% endif %}

                    <div class="mb-3">
                        <label for="tipo_contrato" class="form-label fw-semibold">Tipo de contrato <span class="text-danger">*</span></label>
                        <select class="form-select" id="tipo_contrato" name="tipo_contrato" required>
                            <option value="tiempo_indefinido" {{ (contrato.tipo_contrato ?? '') == 'tiempo_indefinido' ? 'selected' : '' }}>Tiempo indefinido</option>
                            <option value="plazo_fijo" {{ (contrato.tipo_contrato ?? '') == 'plazo_fijo' ? 'selected' : '' }}>Plazo fijo</option>
                            <option value="obra_determinada" {{ (contrato.tipo_contrato ?? '') == 'obra_determinada' ? 'selected' : '' }}>Obra determinada</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="salario_pactado" class="form-label fw-semibold">Salario Pactado (₡) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">₡</span>
                            <input type="number" class="form-control" id="salario_pactado" name="salario_pactado"
                                   min="0.01" step="0.01" required value="{{ contrato.salario_pactado ?? '' }}">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="jornada" class="form-label fw-semibold">Jornada <span class="text-danger">*</span></label>
                        <select class="form-select" id="jornada" name="jornada" required>
                            <option value="tiempo_completo" {{ (contrato.jornada ?? 'tiempo_completo') == 'tiempo_completo' ? 'selected' : '' }}>Tiempo completo</option>
                            <option value="medio_tiempo" {{ (contrato.jornada ?? '') == 'medio_tiempo' ? 'selected' : '' }}>Medio tiempo</option>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label for="fecha_inicio" class="form-label fw-semibold">Fecha de Inicio <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" required
                               value="{{ contrato.fecha_inicio ?? '' }}">
                    </div>

                    <div class="d-flex justify-content-between">
                        <a href="{{ url_for('contratos.index') }}" class="btn btn-secondary"><i class="bi bi-arrow-left me-1"></i>Cancelar</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-floppy me-1"></i>{{ accion == 'crear' ? 'Guardar Contrato' : 'Actualizar Contrato' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
{% endblock %}
```

- [ ] **Step 6: Add the nav link in `templates/layouts/base.html.twig`**

Inside the `{% if session.usuario_rol == 'super_admin' %}` "Administración" group (around line 64-72), add before Usuarios:

```twig
<a class="sb-item" href="{{ url_for('contratos.index') }}"><i class="bi bi-file-earmark-text"></i><span>Contratos</span></a>
```

- [ ] **Step 7: Syntax-check everything**

Run: `php -l src/Controllers/ContratosController.php config/routes.php config/dependencies.php`
Expected: `No syntax errors detected` for each.

Run the Twig parse-check script (same technique as the Empleados-persona plan) against both new templates and the modified layout:
```bash
php -r '
require "vendor/autoload.php";
$loader = new \Twig\Loader\FilesystemLoader("templates");
$twig = new \Twig\Environment($loader);
foreach (["contratos/index.html.twig", "contratos/form.html.twig", "layouts/base.html.twig"] as $tpl) {
    try {
        $twig->parse($twig->tokenize(new \Twig\Source(file_get_contents("templates/$tpl"), $tpl)));
        echo "OK: $tpl\n";
    } catch (\Twig\Error\SyntaxError $e) {
        echo "SYNTAX ERROR in $tpl: " . $e->getMessage() . "\n";
        exit(1);
    }
}
'
```
Expected: `OK:` for all three.

- [ ] **Step 8: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: OK, no failures (this task adds no PHP unit-tested logic).

- [ ] **Step 9: End-to-end manual smoke test**

Start the disposable server: `"/c/Program Files/Ampps/php/php.exe" -S 127.0.0.1:8899 -t public public/index.php &`

Log in as `superadmin`/`password123` (same curl-cookie-jar technique used for the Phase 2 verification earlier this session — `POST /lubrimotos/public/login`), then:
- `GET /lubrimotos/public/mantenimientos/contratos` → expect 200, 3 seeded rows.
- `GET /lubrimotos/public/mantenimientos/contratos/crear` → expect 200, employee dropdown populated.
- `POST .../crear` with a **new** employee id that has no contract yet (none exist among the 3 seeded — pick any and first `DELETE` a seeded contract via the UI, or just confirm the "already has an active contract" rejection message appears when reusing id 1/2/3) → expect either success or the expected `InvalidArgumentException` message, never a 500.
- `POST /lubrimotos/public/mantenimientos/contratos/1/renovar` with a `fecha_inicio` after the seeded contract's → expect redirect + success flash; re-`GET` the index and confirm contract #1 now shows `estado=finalizado` and a new row with `estado=activo` exists for the same employee.
- `POST .../{id}/eliminar` on a `finalizado` contract → expect it disappears from the list.
- As `admin` (not `super_admin`): `GET /lubrimotos/public/mantenimientos/contratos` → expect a 302 redirect to `/dashboard` (RBAC still enforced), not a 500 or 200.

Stop the disposable server afterward (kill the `php.exe` process serving port 8899, same cleanup as earlier this session).

- [ ] **Step 10: Commit**

```bash
git add src/Controllers/ContratosController.php config/dependencies.php config/routes.php templates/contratos/index.html.twig templates/contratos/form.html.twig templates/layouts/base.html.twig
git commit -m "feat(contratos): add Controller, routes, and templates (super_admin only)"
```

---

### Task 4: Documentation — `CLAUDE.md`

**Files:**
- Modify: `CLAUDE.md`

**Interfaces:** none — documentation only.

- [ ] **Step 1: Update the Grupo 7 note**

Replace the first sentence of the Grupo 7 blockquote (currently: `**Solo existe en database/schema.sql y en el Capítulo V del documento (diseño/propuesta) — el código PHP (Repository/Service/Controller) de estos 4 módulos todavía no está construido.**`) with:

```markdown
> **Contratos ya tiene código completo (Repository/Service/Controller/vistas, 14/09/2026)** — ver
> `docs/superpowers/specs/2026-09-14-contratos-module-design.md`. Departamentos, Horarios y
> ParametrosLegales siguen solo en el esquema; el código PHP de esos 3 módulos todavía no está
> construido.
```

- [ ] **Step 2: Update the RBAC section**

In the "Autenticación y RBAC" section, the line:
```markdown
- `RoleMiddleware($rol)` — ... Solo el grupo `/mantenimientos` (Puestos, Períodos, Feriados, Usuarios) exige `super_admin`; ...
```
becomes:
```markdown
- `RoleMiddleware($rol)` — ... Solo el grupo `/mantenimientos` (Puestos, Períodos, Feriados, Contratos, Usuarios) exige `super_admin`; ...
```

And in the tutor-feedback note right below it:
```markdown
> ...Controla exclusivamente Usuarios y los catálogos del grupo `/mantenimientos` (Puestos, Períodos, Feriados) — ...
```
becomes:
```markdown
> ...Controla exclusivamente Usuarios y los catálogos del grupo `/mantenimientos` (Puestos, Períodos, Feriados, Contratos) — ...
```

- [ ] **Step 3: Add a row to "Módulos — estado de desarrollo"**

Add after row 13 (Consultas / Reportes):
```markdown
| 14 | Gestionar Contratos | `feature/contratos-module` | ✅ Completo (pendiente prueba manual) |
```

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: mark the Contratos module complete in CLAUDE.md"
```
