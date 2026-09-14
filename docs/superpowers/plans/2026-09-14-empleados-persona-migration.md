# Empleados → persona Migration (Phase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `EmpleadosRepository`/`EmpleadosService`/the Empleados form work against the schema that's already live on `develop`/`main` (identity/contact fields on `persona`, geography normalized to `provincias`/`cantones`/`distritos`) instead of the old flat `empleados` columns that no longer exist.

**Architecture:** `EmpleadosRepository` gets a full rewrite of its read/write methods to `JOIN persona` and split its `insert()`/`update()` into a transaction across `persona` + `empleados` (+ optional `datos_bancarios`, unchanged). `EmpleadosService::validar()` splits its output into a `persona` group and an `empleado` group. A new read-only `DistritosRepository` feeds an optgroup-grouped `<select>` in the form, replacing 6 free-text location inputs.

**Tech Stack:** PHP 8.1 / Slim 4 / PDO raw / Twig 3 / PHPUnit 11 (existing stack, no new dependencies).

**Spec:** `docs/superpowers/specs/2026-09-14-empleados-persona-migration-design.md`

## Global Constraints

- `declare(strict_types=1)` at the top of every PHP file touched.
- Controllers never touch PDO directly; Repositories never contain business logic (from `CLAUDE.md`).
- No ORM, no new Composer packages.
- DB identifiers stay `snake_case`; PHP classes `PascalCase`, methods `camelCase`.
- `persona`'s contact columns (`telefono`, `correo`, `direccion`, `telefono_alterno`, `correo_alterno`, `direccion_alterna`, `telefono_emergencia`, `nombre_contacto_emergencia`) are `NOT NULL DEFAULT ''` — always send `''`, never `null`, for these when empty.
- This project has no Repository-level test suite anywhere (confirmed repeatedly: no `tests/Repositories` directory exists) — Repository correctness is exercised indirectly through Service tests with mocks, never against a real database. This is a pre-existing limitation, not something this plan fixes.
- This project has no Twig-rendering or Controller test suite either (no `tests/Controllers` directory) — template changes are verified by a syntax-only Twig parse check plus manual QA notes, matching the project's own established "pendiente prueba manual" convention.

---

### Task 1: Repository layer — `DistritosRepository` + `EmpleadosRepository` rewrite

**Files:**
- Create: `src/Repositories/DistritosRepository.php`
- Modify: `src/Repositories/EmpleadosRepository.php` (full rewrite of `findAll`, `findById`, `existsByCedula`, `insert`, `update`; `findUsuariosDisponibles`, `deactivate` untouched)
- Modify: `config/dependencies.php:67` area (add `DistritosRepository` registration, alongside `PuestosRepository`'s)
- Delete: `tests/Services/EmpleadosServiceContactosAlternosTest.php` (untracked file — the held "contactos alternos" feature; its scenarios are superseded by Task 2's new tests against the real `persona` fields, and it would otherwise fail once this task's `EmpleadosRepository` signatures change while `EmpleadosService` — not yet migrated — still calls the old ones)

**Interfaces:**
- Produces: `DistritosRepository::findAllConJerarquia(): array` (rows: `id_distrito, distrito_nombre, canton_nombre, provincia_nombre`, real distritos only, ordered by provincia/cantón/distrito name) and `DistritosRepository::exists(int $id): bool` — both consumed only by Task 2's `EmpleadosService`. `EmpleadosRepository::insert(array $persona, array $empleado, ?array $bancario): int` and `EmpleadosRepository::update(int $id, int $idPersona, array $persona, array $empleado, ?array $bancario): void` — new signatures, consumed only by Task 2's `EmpleadosService`. `EmpleadosRepository::existsByCedula(string $cedula, ?int $excludePersonaId = null): bool` — parameter renamed/repurposed from excluding an `id_empleado` to excluding an `id_persona`.
- Consumes: nothing from other tasks.

This task has no automated test — see Global Constraints. Verification is `php -l`, a full suite run (expected green — see Step 5), and careful self-review of the SQL against the spec's exact column lists.

- [ ] **Step 0: Create the feature branch**

Per `CLAUDE.md`'s Gitflow convention (`feature/*` → `develop` → `main`):

Run: `git checkout -b feature/empleados-persona` (from `develop`)
Expected: `Switched to a new branch 'feature/empleados-persona'`. The currently-uncommitted "contactos alternos" work (`src/Repositories/EmpleadosRepository.php`, `src/Services/EmpleadosService.php`, `templates/empleados/form.html.twig`, `tests/Services/EmpleadosServiceContactosAlternosTest.php`) rides along in the working tree — this task replaces that Repository file's content entirely and deletes that test file (see Step 4).

- [ ] **Step 1: Create `src/Repositories/DistritosRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * DistritosRepository
 *
 * Catálogo geográfico de solo lectura (provincias/cantones/distritos ya
 * vienen sembrados en schema.sql/seed.sql). Sin Service ni Controller propio:
 * es dato de referencia fijo, no un módulo administrable.
 */
class DistritosRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Todos los distritos reales (excluye el centinela id=1), con el nombre
     * de su cantón y provincia, para agrupar el <select> del formulario.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllConJerarquia(): array
    {
        return $this->pdo->query(
            'SELECT d.id_distrito, d.nombre AS distrito_nombre,
                    c.nombre AS canton_nombre, p.nombre AS provincia_nombre
               FROM distritos d
               JOIN cantones c ON c.id_canton = d.id_canton
               JOIN provincias p ON p.id_provincia = c.id_provincia
              WHERE d.id_distrito <> 1
           ORDER BY p.nombre ASC, c.nombre ASC, d.nombre ASC'
        )->fetchAll();
    }

    /**
     * Existe el id_distrito (incluye el centinela id=1 — es una fila real).
     */
    public function exists(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM distritos WHERE id_distrito = :id');
        $stmt->execute([':id' => $id]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
```

- [ ] **Step 2: Register `DistritosRepository` in `config/dependencies.php`**

Add this entry next to the existing `PuestosRepository::class` entry (around line 67):

```php
\App\Repositories\DistritosRepository::class => function (ContainerInterface $c) {
    return new \App\Repositories\DistritosRepository($c->get(PDO::class));
},
```

- [ ] **Step 3: Replace `src/Repositories/EmpleadosRepository.php` entirely**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * EmpleadosRepository
 *
 * Responsabilidad única: acceso a las tablas `empleados`, `persona` y
 * `datos_bancarios` vía PDO. No contiene lógica de negocio.
 */
class EmpleadosRepository
{
    public function __construct(private readonly PDO $pdo) {}

    // ── Lectura ───────────────────────────────────────────

    /**
     * Lista empleados con el nombre de su puesto.
     * Filtra por estado ('activo'/'inactivo') o todos si es null.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(?string $estado = null, ?string $q = null): array
    {
        $sql = 'SELECT e.id_empleado, per.nombre, per.apellidos, per.cedula,
                       e.fecha_ingreso, e.estado, p.nombre AS puesto_nombre
                  FROM empleados e
                  JOIN persona per ON per.id_persona = e.id_persona
                  JOIN puestos p ON p.id_puesto = e.id_puesto';
        $params = [];
        $where  = [];

        if ($estado !== null) {
            $where[]           = 'e.estado = :estado';
            $params[':estado'] = $estado;
        }

        if ($q !== null && $q !== '') {
            $where[] = "(CONCAT(per.nombre, ' ', per.apellidos) LIKE :q
                         OR CONCAT(per.apellidos, ', ', per.nombre) LIKE :q
                         OR per.cedula LIKE :q
                         OR p.nombre LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY per.apellidos ASC, per.nombre ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Empleado por ID + datos de persona + su cuenta bancaria activa (LEFT JOIN).
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.id_empleado, e.id_persona, e.id_puesto, e.id_usuario, e.id_horario,
                    e.numero_asegurado_ccss, e.numero_poliza_ins,
                    e.fecha_ingreso, e.fecha_salida, e.estado,
                    per.cedula, per.nombre, per.apellidos, per.fecha_nacimiento,
                    per.genero, per.estado_civil, per.nacionalidad,
                    per.telefono, per.correo, per.direccion, per.id_distrito,
                    per.telefono_alterno, per.correo_alterno, per.direccion_alterna,
                    per.id_distrito_alterno,
                    per.telefono_emergencia, per.nombre_contacto_emergencia,
                    b.id_datos_bancarios, b.banco, b.tipo_cuenta, b.numero_cuenta,
                    b.numero_cuenta_iban, b.moneda
               FROM empleados e
               JOIN persona per ON per.id_persona = e.id_persona
          LEFT JOIN datos_bancarios b
                 ON b.id_empleado = e.id_empleado AND b.activa = 1
              WHERE e.id_empleado = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Verifica cédula duplicada en `persona`, excluyendo el propio id_persona al editar.
     */
    public function existsByCedula(string $cedula, ?int $excludePersonaId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM persona WHERE cedula = :cedula';
        $params = [':cedula' => $cedula];

        if ($excludePersonaId !== null) {
            $sql .= ' AND id_persona <> :id';
            $params[':id'] = $excludePersonaId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Usuarios rol 'empleado' no vinculados a otro empleado (más el actual al editar).
     * Sin cambios: nunca dependió de columnas movidas a persona.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findUsuariosDisponibles(?int $currentUsuarioId = null): array
    {
        $sql = "SELECT u.id_usuario, u.nombre_usuario
                  FROM usuarios u
                 WHERE u.rol = 'empleado'
                   AND u.id_usuario NOT IN (
                           SELECT id_usuario FROM empleados WHERE id_usuario IS NOT NULL
                   )";
        $params = [];

        if ($currentUsuarioId !== null) {
            $sql = "SELECT u.id_usuario, u.nombre_usuario
                      FROM usuarios u
                     WHERE u.rol = 'empleado'
                       AND (u.id_usuario NOT IN (
                               SELECT id_usuario FROM empleados WHERE id_usuario IS NOT NULL
                            )
                            OR u.id_usuario = :current)";
            $params[':current'] = $currentUsuarioId;
        }

        $sql .= ' ORDER BY u.nombre_usuario ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ── Escritura ─────────────────────────────────────────

    /**
     * Inserta persona + empleado y (opcionalmente) su cuenta bancaria, en una transacción.
     *
     * @param array<string, mixed>      $persona  datos de persona (claves = columnas de `persona`)
     * @param array<string, mixed>      $empleado datos de empleado (claves = columnas propias de `empleados`)
     * @param array<string, mixed>|null $bancario datos bancarios o null
     * @return int id_empleado generado
     */
    public function insert(array $persona, array $empleado, ?array $bancario): int
    {
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $idPersona = $this->insertPersona($persona);

            $stmt = $this->pdo->prepare(
                'INSERT INTO empleados
                    (id_persona, id_puesto, id_usuario, id_horario,
                     numero_asegurado_ccss, numero_poliza_ins,
                     fecha_ingreso, fecha_salida, estado)
                 VALUES
                    (:id_persona, :id_puesto, :id_usuario, :id_horario,
                     :numero_asegurado_ccss, :numero_poliza_ins,
                     :fecha_ingreso, :fecha_salida, :estado)'
            );
            $stmt->execute($this->bindEmpleado($empleado) + [':id_persona' => $idPersona]);
            $id = (int)$this->pdo->lastInsertId();

            if ($bancario !== null) {
                $this->insertBancario($id, $bancario);
            }

            if ($ownTransaction) {
                $this->pdo->commit();
            }
            return $id;
        } catch (\Throwable $ex) {
            if ($ownTransaction) {
                $this->pdo->rollBack();
            }
            throw $ex;
        }
    }

    /**
     * Actualiza persona + empleado y hace upsert de la cuenta bancaria activa, en transacción.
     *
     * @param array<string, mixed>      $persona
     * @param array<string, mixed>      $empleado
     * @param array<string, mixed>|null $bancario
     */
    public function update(int $id, int $idPersona, array $persona, array $empleado, ?array $bancario): void
    {
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->updatePersona($idPersona, $persona);

            $stmt = $this->pdo->prepare(
                'UPDATE empleados SET
                    id_puesto = :id_puesto, id_usuario = :id_usuario, id_horario = :id_horario,
                    numero_asegurado_ccss = :numero_asegurado_ccss,
                    numero_poliza_ins = :numero_poliza_ins,
                    fecha_ingreso = :fecha_ingreso, fecha_salida = :fecha_salida, estado = :estado
                  WHERE id_empleado = :id_empleado'
            );
            $params = $this->bindEmpleado($empleado);
            $params[':id_empleado'] = $id;
            $stmt->execute($params);

            if ($bancario !== null) {
                $chk = $this->pdo->prepare(
                    'SELECT id_datos_bancarios FROM datos_bancarios
                      WHERE id_empleado = :id AND activa = 1 LIMIT 1'
                );
                $chk->execute([':id' => $id]);
                $existing = $chk->fetchColumn();

                if ($existing !== false) {
                    $upd = $this->pdo->prepare(
                        'UPDATE datos_bancarios SET
                            banco = :banco, tipo_cuenta = :tipo_cuenta,
                            numero_cuenta = :numero_cuenta,
                            numero_cuenta_iban = :numero_cuenta_iban, moneda = :moneda
                          WHERE id_datos_bancarios = :idb'
                    );
                    $upd->execute([
                        ':banco'              => $bancario['banco'],
                        ':tipo_cuenta'        => $bancario['tipo_cuenta'],
                        ':numero_cuenta'      => $bancario['numero_cuenta'],
                        ':numero_cuenta_iban' => $bancario['numero_cuenta_iban'],
                        ':moneda'             => $bancario['moneda'],
                        ':idb'                => (int)$existing,
                    ]);
                } else {
                    $this->insertBancario($id, $bancario);
                }
            } else {
                $deact = $this->pdo->prepare(
                    'UPDATE datos_bancarios SET activa = 0
                      WHERE id_empleado = :id AND activa = 1'
                );
                $deact->execute([':id' => $id]);
            }

            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $ex) {
            if ($ownTransaction) {
                $this->pdo->rollBack();
            }
            throw $ex;
        }
    }

    /**
     * Soft-delete: marca el empleado como inactivo y registra la fecha de salida.
     * Sin cambios.
     */
    public function deactivate(int $id, string $fechaSalida): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE empleados SET estado = 'inactivo', fecha_salida = :f
              WHERE id_empleado = :id"
        );
        $stmt->execute([':f' => $fechaSalida, ':id' => $id]);
    }

    // ── Helpers privados ──────────────────────────────────

    /**
     * @param array<string, mixed> $p
     * @return int id_persona generado
     */
    private function insertPersona(array $p): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO persona
                (cedula, nombre, apellidos, fecha_nacimiento, genero, estado_civil,
                 nacionalidad, telefono, correo, direccion, id_distrito,
                 telefono_alterno, correo_alterno, direccion_alterna, id_distrito_alterno,
                 telefono_emergencia, nombre_contacto_emergencia)
             VALUES
                (:cedula, :nombre, :apellidos, :fecha_nacimiento, :genero, :estado_civil,
                 :nacionalidad, :telefono, :correo, :direccion, :id_distrito,
                 :telefono_alterno, :correo_alterno, :direccion_alterna, :id_distrito_alterno,
                 :telefono_emergencia, :nombre_contacto_emergencia)'
        );
        $stmt->execute($this->bindPersona($p));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $p
     */
    private function updatePersona(int $idPersona, array $p): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE persona SET
                cedula = :cedula, nombre = :nombre, apellidos = :apellidos,
                fecha_nacimiento = :fecha_nacimiento, genero = :genero, estado_civil = :estado_civil,
                nacionalidad = :nacionalidad, telefono = :telefono, correo = :correo,
                direccion = :direccion, id_distrito = :id_distrito,
                telefono_alterno = :telefono_alterno, correo_alterno = :correo_alterno,
                direccion_alterna = :direccion_alterna, id_distrito_alterno = :id_distrito_alterno,
                telefono_emergencia = :telefono_emergencia,
                nombre_contacto_emergencia = :nombre_contacto_emergencia
              WHERE id_persona = :id_persona'
        );
        $params = $this->bindPersona($p);
        $params[':id_persona'] = $idPersona;
        $stmt->execute($params);
    }

    /**
     * @param array<string, mixed> $b
     */
    private function insertBancario(int $idEmpleado, array $b): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO datos_bancarios
                (id_empleado, banco, tipo_cuenta, numero_cuenta, numero_cuenta_iban, moneda, activa)
             VALUES
                (:id_empleado, :banco, :tipo_cuenta, :numero_cuenta, :numero_cuenta_iban, :moneda, 1)'
        );
        $stmt->execute([
            ':id_empleado'        => $idEmpleado,
            ':banco'              => $b['banco'],
            ':tipo_cuenta'        => $b['tipo_cuenta'],
            ':numero_cuenta'      => $b['numero_cuenta'],
            ':numero_cuenta_iban' => $b['numero_cuenta_iban'],
            ':moneda'             => $b['moneda'],
        ]);
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function bindPersona(array $p): array
    {
        return [
            ':cedula'                     => $p['cedula'],
            ':nombre'                     => $p['nombre'],
            ':apellidos'                  => $p['apellidos'],
            ':fecha_nacimiento'           => $p['fecha_nacimiento'],
            ':genero'                     => $p['genero'],
            ':estado_civil'               => $p['estado_civil'],
            ':nacionalidad'               => $p['nacionalidad'],
            ':telefono'                   => $p['telefono'],
            ':correo'                     => $p['correo'],
            ':direccion'                  => $p['direccion'],
            ':id_distrito'                => $p['id_distrito'],
            ':telefono_alterno'           => $p['telefono_alterno'],
            ':correo_alterno'             => $p['correo_alterno'],
            ':direccion_alterna'          => $p['direccion_alterna'],
            ':id_distrito_alterno'        => $p['id_distrito_alterno'],
            ':telefono_emergencia'        => $p['telefono_emergencia'],
            ':nombre_contacto_emergencia' => $p['nombre_contacto_emergencia'],
        ];
    }

    /**
     * @param array<string, mixed> $e
     * @return array<string, mixed>
     */
    private function bindEmpleado(array $e): array
    {
        return [
            ':id_puesto'             => $e['id_puesto'],
            ':id_usuario'            => $e['id_usuario'],
            ':id_horario'            => $e['id_horario'],
            ':numero_asegurado_ccss' => $e['numero_asegurado_ccss'],
            ':numero_poliza_ins'     => $e['numero_poliza_ins'],
            ':fecha_ingreso'         => $e['fecha_ingreso'],
            ':fecha_salida'          => $e['fecha_salida'],
            ':estado'                => $e['estado'],
        ];
    }
}
```

- [ ] **Step 4: Delete the obsolete test file**

```bash
git rm -f tests/Services/EmpleadosServiceContactosAlternosTest.php
```

(It's untracked — `git rm -f` removes it from the working tree; there is nothing to unstage.) This test exercised the old flat-`empleados` "contactos alternos" columns that no longer exist; its scenarios are superseded by Task 2's new tests against real `persona` fields.

- [ ] **Step 5: Syntax-check and run the full suite**

Run: `php -l src/Repositories/DistritosRepository.php && php -l src/Repositories/EmpleadosRepository.php`
Expected: `No syntax errors detected` for both.

Run: `vendor/bin/phpunit`
Expected: green (`EmpleadosService` still calls the *old* two-argument `insert()`/`update()` signatures at this point — that's fine: no test currently exercises `EmpleadosService::crear()`/`actualizar()` now that Step 4 removed the only test that did, so nothing calls the now-mismatched code path. This inconsistency is resolved in Task 2, not before).

- [ ] **Step 6: Commit**

```bash
git add src/Repositories/DistritosRepository.php src/Repositories/EmpleadosRepository.php config/dependencies.php
git commit -m "feat(empleados): rewrite EmpleadosRepository to read/write through persona"
```

(The `git rm` from Step 4 is already staged from that command; it will be included in this commit.)

---

### Task 2: `EmpleadosService` rewrite (TDD)

**Files:**
- Modify: `src/Services/EmpleadosService.php` (full rewrite)
- Modify: `config/dependencies.php` (add `DistritosRepository` as the 3rd constructor argument to the `EmpleadosService` entry)
- Test: `tests/Services/EmpleadosServicePersonaMigrationTest.php` (new)

**Interfaces:**
- Consumes: `EmpleadosRepository::insert/update/existsByCedula` (Task 1's new signatures), `DistritosRepository::findAllConJerarquia()`/`exists()` (Task 1).
- Produces: `EmpleadosService::datosFormulario()` now also returns a `'distritos'` key. Consumed by Task 3's template.

- [ ] **Step 1: Write the failing tests**

Create `tests/Services/EmpleadosServicePersonaMigrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\DistritosRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\PuestosRepository;
use App\Services\EmpleadosService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EmpleadosServicePersonaMigrationTest extends TestCase
{
    private function service(EmpleadosRepository $repo, ?DistritosRepository $distritosRepo = null): EmpleadosService
    {
        $puestosRepo = $this->createMock(PuestosRepository::class);
        $puestosRepo->method('findById')->willReturn(['id_puesto' => 1, 'nombre' => 'Mecánico']);

        return new EmpleadosService(
            $repo,
            $puestosRepo,
            $distritosRepo ?? $this->createMock(DistritosRepository::class),
            $this->createMock(AuditoriaRepository::class)
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function datosValidos(array $overrides = []): array
    {
        return array_merge([
            'id_puesto'        => '1',
            'nombre'           => 'Juan',
            'apellidos'        => 'Pérez',
            'cedula'           => '123456789',
            'fecha_nacimiento' => '1990-01-01',
            'genero'           => 'masculino',
            'estado_civil'     => 'soltero',
            'nacionalidad'     => 'Costarricense',
            'fecha_ingreso'    => '2020-01-01',
            'correo'           => '',
            'estado'           => 'activo',
        ], $overrides);
    }

    public function testCrearArmaGruposPersonaEmpleadoSeparados(): void
    {
        $repo = $this->createMock(EmpleadosRepository::class);
        $repo->method('existsByCedula')->willReturn(false);
        $repo->expects(self::once())->method('insert')
            ->with(
                self::callback(fn(array $p) =>
                    $p['nombre'] === 'Juan' && $p['apellidos'] === 'Pérez' && $p['cedula'] === '123456789'
                    && $p['id_distrito'] === 1 && $p['id_distrito_alterno'] === 1
                ),
                self::callback(fn(array $e) =>
                    $e['id_puesto'] === 1 && $e['id_horario'] === 1 && $e['estado'] === 'activo'
                ),
                null
            )
            ->willReturn(42);

        $id = $this->service($repo)->crear($this->datosValidos(), 1, '127.0.0.1');

        self::assertSame(42, $id);
    }

    public function testCrearRechazaCedulaDuplicadaSinExcluirNinguna(): void
    {
        $repo = $this->createMock(EmpleadosRepository::class);
        $repo->expects(self::once())->method('existsByCedula')->with('123456789', null)->willReturn(true);
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ya existe un empleado con la cédula');

        $this->service($repo)->crear($this->datosValidos(), 1, '127.0.0.1');
    }

    public function testActualizarExcluyeSuPropioIdPersonaAlVerificarCedula(): void
    {
        $repo = $this->createMock(EmpleadosRepository::class);
        $repo->method('findById')->willReturn(['id_empleado' => 5, 'id_persona' => 9]);
        $repo->expects(self::once())->method('existsByCedula')->with('123456789', 9)->willReturn(false);
        $repo->expects(self::once())->method('update')
            ->with(5, 9, self::isType('array'), self::isType('array'), null);

        $this->service($repo)->actualizar(5, $this->datosValidos(), 1, '127.0.0.1');
    }

    public function testIdDistritoVacioUsaElCentinelaSinConsultarDistritosRepository(): void
    {
        $distritosRepo = $this->createMock(DistritosRepository::class);
        $distritosRepo->expects(self::never())->method('exists');

        $repo = $this->createMock(EmpleadosRepository::class);
        $repo->method('existsByCedula')->willReturn(false);
        $repo->expects(self::once())->method('insert')
            ->with(self::callback(fn(array $p) => $p['id_distrito'] === 1), self::isType('array'), null)
            ->willReturn(1);

        $this->service($repo, $distritosRepo)->crear($this->datosValidos(['id_distrito' => '']), 1, '127.0.0.1');
    }

    public function testIdDistritoInexistenteRechazaYNoInserta(): void
    {
        $distritosRepo = $this->createMock(DistritosRepository::class);
        $distritosRepo->method('exists')->with(999)->willReturn(false);

        $repo = $this->createMock(EmpleadosRepository::class);
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('distrito seleccionado no es válido');

        $this->service($repo, $distritosRepo)->crear($this->datosValidos(['id_distrito' => '999']), 1, '127.0.0.1');
    }

    public function testIdDistritoValidoSeConservaTalCual(): void
    {
        $distritosRepo = $this->createMock(DistritosRepository::class);
        $distritosRepo->method('exists')->with(5)->willReturn(true);

        $repo = $this->createMock(EmpleadosRepository::class);
        $repo->method('existsByCedula')->willReturn(false);
        $repo->expects(self::once())->method('insert')
            ->with(self::callback(fn(array $p) => $p['id_distrito'] === 5), self::isType('array'), null)
            ->willReturn(1);

        $this->service($repo, $distritosRepo)->crear($this->datosValidos(['id_distrito' => '5']), 1, '127.0.0.1');
    }

    public function testCorreoVacioSeGuardaComoCadenaVaciaNoNull(): void
    {
        $repo = $this->createMock(EmpleadosRepository::class);
        $repo->method('existsByCedula')->willReturn(false);
        $repo->expects(self::once())->method('insert')
            ->with(self::callback(fn(array $p) => $p['correo'] === ''), self::isType('array'), null)
            ->willReturn(1);

        $this->service($repo)->crear($this->datosValidos(['correo' => '']), 1, '127.0.0.1');
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Services/EmpleadosServicePersonaMigrationTest.php`
Expected: FAIL — the current `EmpleadosService` still calls `$this->repo->insert($validado['empleado'], $validado['bancario'])` with 2 arguments (no `persona` group at all), so every mock `->with(...)` expectation on 3 arguments will not match, and `existsByCedula` is still called with an `id_empleado`-shaped exclude value, not `id_persona`.

- [ ] **Step 3: Replace `src/Services/EmpleadosService.php` entirely**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\DistritosRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\PuestosRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

/**
 * EmpleadosService
 *
 * Lógica de negocio del módulo Empleados. El Controller nunca accede al
 * Repository ni a PDO. Las fechas y la sesión llegan como parámetros.
 */
class EmpleadosService
{
    private const GENEROS       = ['masculino', 'femenino', 'otro'];
    private const ESTADOS_CIVIL = ['soltero', 'casado', 'union_libre', 'divorciado', 'viudo'];
    private const ESTADOS       = ['activo', 'inactivo'];
    private const TIPOS_CUENTA  = ['corriente', 'ahorros'];
    private const MONEDAS       = ['CRC', 'USD'];

    public function __construct(
        private readonly EmpleadosRepository $repo,
        private readonly PuestosRepository    $puestosRepo,
        private readonly DistritosRepository  $distritosRepo,
        private readonly AuditoriaRepository  $auditoriaRepo
    ) {}

    // ── Consultas ─────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function listar(?string $estado = null, ?string $q = null): array
    {
        return $this->repo->findAll($estado, $q);
    }

    /**
     * @return array<string, mixed>
     * @throws RuntimeException si no existe
     */
    public function obtener(int $id): array
    {
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new RuntimeException('Empleado no encontrado.');
        }
        return $row;
    }

    /**
     * Datos para poblar los <select> del formulario.
     *
     * @return array{puestos: array<int, array<string, mixed>>, usuarios: array<int, array<string, mixed>>, distritos: array<int, array<string, mixed>>}
     */
    public function datosFormulario(?int $currentUsuarioId = null): array
    {
        return [
            'puestos'   => $this->puestosRepo->findAll(),
            'usuarios'  => $this->repo->findUsuariosDisponibles($currentUsuarioId),
            'distritos' => $this->distritosRepo->findAllConJerarquia(),
        ];
    }

    // ── Comandos ──────────────────────────────────────────

    /**
     * @param array<string, mixed> $datos
     * @throws InvalidArgumentException
     * @return int id del nuevo empleado
     */
    public function crear(array $datos, int $loggedInId, string $ip): int
    {
        $validado = $this->validar($datos, null);

        try {
            $id = $this->repo->insert($validado['persona'], $validado['empleado'], $validado['bancario']);
        } catch (PDOException $e) {
            if (str_starts_with((string)$e->getCode(), '23')) {
                throw new InvalidArgumentException('Ya existe un empleado con esa cédula.');
            }
            throw $e;
        }

        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'empleados', $id, $ip);
        return $id;
    }

    /**
     * @param array<string, mixed> $datos
     * @throws InvalidArgumentException
     * @throws RuntimeException si no existe
     */
    public function actualizar(int $id, array $datos, int $loggedInId, string $ip): void
    {
        $current  = $this->obtener($id);
        $validado = $this->validar($datos, (int)$current['id_persona']);

        try {
            $this->repo->update($id, (int)$current['id_persona'], $validado['persona'], $validado['empleado'], $validado['bancario']);
        } catch (PDOException $e) {
            if (str_starts_with((string)$e->getCode(), '23')) {
                throw new InvalidArgumentException('Ya existe un empleado con esa cédula.');
            }
            throw $e;
        }

        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'empleados', $id, $ip);
    }

    /**
     * Soft-delete: desactiva el empleado y registra la fecha de salida.
     *
     * @throws RuntimeException si no existe
     */
    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $this->obtener($id);
        $hoy = (new DateTimeImmutable('today'))->format('Y-m-d');
        $this->repo->deactivate($id, $hoy);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'empleados', $id, $ip);
    }

    // ── Validación (función pura: array → array de columnas) ──

    /**
     * Valida y normaliza los datos de persona/empleado y sus datos bancarios.
     * Acumula TODOS los errores en un solo mensaje.
     *
     * @param array<string, mixed> $d
     * @param int|null $excludePersonaId id_persona a excluir en la verificación de cédula (al editar)
     * @return array{persona: array<string, mixed>, empleado: array<string, mixed>, bancario: array<string, mixed>|null}
     * @throws InvalidArgumentException con todos los errores concatenados
     */
    private function validar(array $d, ?int $excludePersonaId): array
    {
        $errores = [];

        $idPuesto = isset($d['id_puesto']) && is_numeric($d['id_puesto']) ? (int)$d['id_puesto'] : 0;
        if ($idPuesto <= 0 || $this->puestosRepo->findById($idPuesto) === null) {
            $errores[] = 'Debe seleccionar un puesto válido.';
        }

        $nombre = trim((string)($d['nombre'] ?? ''));
        if ($nombre === '' || mb_strlen($nombre) > 100) {
            $errores[] = 'El nombre es obligatorio (máx. 100 caracteres).';
        }

        $apellidos = trim((string)($d['apellidos'] ?? ''));
        if ($apellidos === '' || mb_strlen($apellidos) > 100) {
            $errores[] = 'Los apellidos son obligatorios (máx. 100 caracteres).';
        }

        $cedulaRaw = (string)($d['cedula'] ?? '');
        $cedula    = preg_replace('/[\s-]/', '', $cedulaRaw) ?? '';
        $longitud  = strlen($cedula);
        if ($cedula === '' || !ctype_digit($cedula) || !($longitud === 9 || $longitud === 11 || $longitud === 12)) {
            $errores[] = 'La cédula debe ser nacional (9 dígitos) o DIMEX (11-12 dígitos).';
        } elseif ($this->repo->existsByCedula($cedula, $excludePersonaId)) {
            $errores[] = "Ya existe un empleado con la cédula {$cedula}.";
        }

        $fechaNac = $this->validarFechaNacimiento((string)($d['fecha_nacimiento'] ?? ''), $errores);

        $genero = trim((string)($d['genero'] ?? ''));
        if (!in_array($genero, self::GENEROS, true)) {
            $errores[] = 'El género seleccionado no es válido.';
        }

        $estadoCivil = trim((string)($d['estado_civil'] ?? ''));
        if (!in_array($estadoCivil, self::ESTADOS_CIVIL, true)) {
            $errores[] = 'El estado civil seleccionado no es válido.';
        }

        $nacionalidad = trim((string)($d['nacionalidad'] ?? ''));
        if ($nacionalidad === '' || mb_strlen($nacionalidad) > 50) {
            $errores[] = 'La nacionalidad es obligatoria (máx. 50 caracteres).';
        }

        $fechaIngreso = trim((string)($d['fecha_ingreso'] ?? ''));
        if (DateTimeImmutable::createFromFormat('Y-m-d', $fechaIngreso) === false) {
            $errores[] = 'La fecha de ingreso es obligatoria y debe tener formato válido.';
        }

        $correo = trim((string)($d['correo'] ?? ''));
        if ($correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            $errores[] = 'El correo electrónico no tiene un formato válido.';
        } elseif (mb_strlen($correo) > 150) {
            $errores[] = 'El correo electrónico no puede superar 150 caracteres.';
        }

        $correoAlterno = trim((string)($d['correo_alterno'] ?? ''));
        if ($correoAlterno !== '' && filter_var($correoAlterno, FILTER_VALIDATE_EMAIL) === false) {
            $errores[] = 'El correo electrónico alterno no tiene un formato válido.';
        } elseif (mb_strlen($correoAlterno) > 150) {
            $errores[] = 'El correo electrónico alterno no puede superar 150 caracteres.';
        }

        $idDistrito        = $this->idDistritoValidado($d, 'id_distrito', $errores);
        $idDistritoAlterno = $this->idDistritoValidado($d, 'id_distrito_alterno', $errores);

        $estado = trim((string)($d['estado'] ?? 'activo'));
        if (!in_array($estado, self::ESTADOS, true)) {
            $estado = 'activo';
        }

        $bancario = $this->extraerBancario($d, $errores);

        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $fechaSalida = null;
        if ($estado === 'inactivo') {
            $fechaSalida = $this->fechaOpcional((string)($d['fecha_salida'] ?? ''))
                ?? (new DateTimeImmutable('today'))->format('Y-m-d');
        }

        $persona = [
            'cedula'                     => $cedula,
            'nombre'                     => $nombre,
            'apellidos'                  => $apellidos,
            'fecha_nacimiento'           => $fechaNac,
            'genero'                     => $genero,
            'estado_civil'               => $estadoCivil,
            'nacionalidad'               => $nacionalidad,
            'telefono'                   => $this->textoVacio($d, 'telefono', 20),
            'correo'                     => $correo,
            'direccion'                  => $this->textoVacio($d, 'direccion', 255),
            'id_distrito'                => $idDistrito,
            'telefono_alterno'           => $this->textoVacio($d, 'telefono_alterno', 20),
            'correo_alterno'             => $correoAlterno,
            'direccion_alterna'          => $this->textoVacio($d, 'direccion_alterna', 255),
            'id_distrito_alterno'        => $idDistritoAlterno,
            'telefono_emergencia'        => $this->textoVacio($d, 'telefono_emergencia', 20),
            'nombre_contacto_emergencia' => $this->textoVacio($d, 'nombre_contacto_emergencia', 150),
        ];

        $empleado = [
            'id_puesto'             => $idPuesto,
            'id_usuario'            => $this->idUsuarioOpcional($d),
            'id_horario'            => 1,
            'numero_asegurado_ccss' => $this->textoVacio($d, 'numero_asegurado_ccss', 20),
            'numero_poliza_ins'     => $this->textoVacio($d, 'numero_poliza_ins', 30),
            'fecha_ingreso'         => $fechaIngreso,
            'fecha_salida'          => $fechaSalida,
            'estado'                => $estado,
        ];

        return ['persona' => $persona, 'empleado' => $empleado, 'bancario' => $bancario];
    }

    /**
     * @param string[] $errores referencia acumuladora
     * @return string|null fecha normalizada o null si inválida
     */
    private function validarFechaNacimiento(string $valor, array &$errores): ?string
    {
        $valor = trim($valor);
        $fecha = DateTimeImmutable::createFromFormat('Y-m-d', $valor);
        if ($fecha === false) {
            $errores[] = 'La fecha de nacimiento es obligatoria y debe tener formato válido.';
            return null;
        }
        $hoy = new DateTimeImmutable('today');
        if ($fecha >= $hoy) {
            $errores[] = 'La fecha de nacimiento debe estar en el pasado.';
            return $valor;
        }
        $edad = $hoy->diff($fecha)->y;
        if ($edad < 15) {
            $errores[] = 'El empleado debe tener al menos 15 años (edad laboral mínima en Costa Rica).';
        }
        return $valor;
    }

    /**
     * @param string[] $errores referencia acumuladora
     */
    private function idDistritoValidado(array $d, string $clave, array &$errores): int
    {
        $valor = trim((string)($d[$clave] ?? ''));
        if ($valor === '') {
            return 1;
        }
        if (!ctype_digit($valor) || !$this->distritosRepo->exists((int)$valor)) {
            $errores[] = 'El distrito seleccionado no es válido.';
            return 1;
        }
        return (int)$valor;
    }

    /**
     * Extrae y valida los datos bancarios. Devuelve null si el bloque viene vacío.
     * Los errores se acumulan en la lista compartida $errores (no lanza por sí mismo).
     *
     * @param array<string, mixed> $d
     * @param string[] $errores referencia acumuladora
     * @return array<string, mixed>|null
     */
    private function extraerBancario(array $d, array &$errores): ?array
    {
        $banco      = trim((string)($d['banco'] ?? ''));
        $tipoCuenta = trim((string)($d['tipo_cuenta'] ?? ''));
        $numeroCta  = trim((string)($d['numero_cuenta'] ?? ''));
        $iban       = trim((string)($d['numero_cuenta_iban'] ?? ''));
        $moneda     = trim((string)($d['moneda'] ?? ''));

        $algunoLleno = ($banco !== '' || $tipoCuenta !== '' || $numeroCta !== '' || $iban !== '');
        if (!$algunoLleno) {
            return null;
        }

        if ($banco === '' || mb_strlen($banco) > 100) {
            $errores[] = 'El banco es obligatorio cuando se registran datos bancarios (máx. 100).';
        }
        if (!in_array($tipoCuenta, self::TIPOS_CUENTA, true)) {
            $errores[] = 'Debe seleccionar un tipo de cuenta válido.';
        }
        if ($numeroCta === '' || mb_strlen($numeroCta) > 30) {
            $errores[] = 'El número de cuenta es obligatorio (máx. 30 caracteres).';
        }
        if ($iban !== '' && mb_strlen($iban) > 22) {
            $errores[] = 'El IBAN no puede superar 22 caracteres.';
        }
        if ($moneda === '') {
            $moneda = 'CRC';
        } elseif (!in_array($moneda, self::MONEDAS, true)) {
            $errores[] = 'La moneda seleccionada no es válida.';
        }

        return [
            'banco'              => $banco,
            'tipo_cuenta'        => $tipoCuenta,
            'numero_cuenta'      => $numeroCta,
            'numero_cuenta_iban' => $iban !== '' ? $iban : null,
            'moneda'             => $moneda,
        ];
    }

    // ── Helpers de normalización ──────────────────────────

    /**
     * Como un texto opcional, pero devuelve '' en vez de null
     * (columnas de persona/empleados son NOT NULL DEFAULT '').
     *
     * @param array<string, mixed> $d
     */
    private function textoVacio(array $d, string $clave, int $max): string
    {
        $valor = trim((string)($d[$clave] ?? ''));
        return mb_substr($valor, 0, $max);
    }

    private function fechaOpcional(string $valor): ?string
    {
        $valor = trim($valor);
        return DateTimeImmutable::createFromFormat('Y-m-d', $valor) !== false ? $valor : null;
    }

    /**
     * @param array<string, mixed> $d
     */
    private function idUsuarioOpcional(array $d): ?int
    {
        $valor = $d['id_usuario'] ?? '';
        return ($valor !== '' && is_numeric($valor)) ? (int)$valor : null;
    }
}
```

- [ ] **Step 4: Update `config/dependencies.php`**

Replace the `EmpleadosService::class` entry (around line 142):

```php
\App\Services\EmpleadosService::class => function (ContainerInterface $c) {
    return new \App\Services\EmpleadosService(
        $c->get(\App\Repositories\EmpleadosRepository::class),
        $c->get(\App\Repositories\PuestosRepository::class),
        $c->get(\App\Repositories\DistritosRepository::class),
        $c->get(\App\Repositories\AuditoriaRepository::class)
    );
},
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Services/EmpleadosServicePersonaMigrationTest.php`
Expected: all 7 tests PASS.

- [ ] **Step 6: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: OK, no failures.

- [ ] **Step 7: Commit**

```bash
git add src/Services/EmpleadosService.php config/dependencies.php tests/Services/EmpleadosServicePersonaMigrationTest.php
git commit -m "feat(empleados): rewrite EmpleadosService to validate/persist through persona"
```

---

### Task 3: Template — `empleados/form.html.twig` "Ubicación" section

**Files:**
- Modify: `templates/empleados/form.html.twig` (the "Ubicación" fieldset only — every other fieldset is untouched)

**Interfaces:**
- Consumes: `distritos` array from Task 2's `EmpleadosService::datosFormulario()` (rows: `id_distrito, distrito_nombre, canton_nombre, provincia_nombre`).
- Produces: nothing consumed by later tasks.

This project has no Twig-rendering test suite (confirmed: no `tests/Controllers` directory anywhere, and no template-testing pattern exists in this codebase). Verification here is a syntax-only Twig parse check (Step 2) plus manual QA notes (Step 3), matching the project's own established "pendiente prueba manual" convention.

- [ ] **Step 1: Replace the "Ubicación" fieldset**

In `templates/empleados/form.html.twig`, replace the entire `{# ── Ubicación ── #}` fieldset (the one currently containing `direccion`, `provincia`, `canton`, `distrito`, `direccion_alterna`, `provincia_alterna`, `canton_alterno`, `distrito_alterno`) with:

```twig
                    {# ── Ubicación ── #}
                    <fieldset class="mb-4">
                        <legend class="fs-6 fw-bold text-primary border-bottom pb-1">Ubicación</legend>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="direccion" class="form-label">Dirección</label>
                                <input type="text" class="form-control" id="direccion" name="direccion"
                                       maxlength="255" value="{{ empleado.direccion ?? '' }}">
                            </div>
                            <div class="col-md-6">
                                <label for="id_distrito" class="form-label">Distrito</label>
                                <select class="form-select" id="id_distrito" name="id_distrito">
                                    <option value="1" {{ (empleado.id_distrito ?? 1) == 1 ? 'selected' : '' }}>— No aplica —</option>
                                    {% set provinciaActual = null %}
                                    {% set cantonActual = null %}
                                    {% for d in distritos %}
                                        {% if d.provincia_nombre != provinciaActual or d.canton_nombre != cantonActual %}
                                            {% if provinciaActual is not null %}</optgroup>{% endif %}
                                            <optgroup label="{{ d.provincia_nombre }} — {{ d.canton_nombre }}">
                                            {% set provinciaActual = d.provincia_nombre %}
                                            {% set cantonActual = d.canton_nombre %}
                                        {% endif %}
                                        <option value="{{ d.id_distrito }}"
                                            {{ (empleado.id_distrito ?? 1) == d.id_distrito ? 'selected' : '' }}>
                                            {{ d.distrito_nombre }}
                                        </option>
                                    {% endfor %}
                                    {% if provinciaActual is not null %}</optgroup>{% endif %}
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="direccion_alterna" class="form-label">Dirección alterna</label>
                                <input type="text" class="form-control" id="direccion_alterna" name="direccion_alterna"
                                       maxlength="255" value="{{ empleado.direccion_alterna ?? '' }}">
                            </div>
                            <div class="col-md-6">
                                <label for="id_distrito_alterno" class="form-label">Distrito alterno</label>
                                <select class="form-select" id="id_distrito_alterno" name="id_distrito_alterno">
                                    <option value="1" {{ (empleado.id_distrito_alterno ?? 1) == 1 ? 'selected' : '' }}>— No aplica —</option>
                                    {% set provinciaActual2 = null %}
                                    {% set cantonActual2 = null %}
                                    {% for d in distritos %}
                                        {% if d.provincia_nombre != provinciaActual2 or d.canton_nombre != cantonActual2 %}
                                            {% if provinciaActual2 is not null %}</optgroup>{% endif %}
                                            <optgroup label="{{ d.provincia_nombre }} — {{ d.canton_nombre }}">
                                            {% set provinciaActual2 = d.provincia_nombre %}
                                            {% set cantonActual2 = d.canton_nombre %}
                                        {% endif %}
                                        <option value="{{ d.id_distrito }}"
                                            {{ (empleado.id_distrito_alterno ?? 1) == d.id_distrito ? 'selected' : '' }}>
                                            {{ d.distrito_nombre }}
                                        </option>
                                    {% endfor %}
                                    {% if provinciaActual2 is not null %}</optgroup>{% endif %}
                                </select>
                            </div>
                        </div>
                    </fieldset>
```

Every other fieldset (Datos personales, Contacto, Contacto de emergencia, Seguros, Datos laborales, Cuenta de usuario, Datos bancarios) is untouched.

- [ ] **Step 2: Syntax-check the template**

There's no Twig CLI lint in this project's `vendor/bin/`, but `slim/twig-view`'s underlying `twig/twig` package is already a dependency, so a one-off script can load and parse (not render) the template directly:

```bash
php -r '
require "vendor/autoload.php";
$loader = new \Twig\Loader\FilesystemLoader("templates");
$twig = new \Twig\Environment($loader);
try {
    $twig->parse($twig->tokenize(new \Twig\Source(file_get_contents("templates/empleados/form.html.twig"), "empleados/form.html.twig")));
    echo "OK: no syntax errors\n";
} catch (\Twig\Error\SyntaxError $e) {
    echo "SYNTAX ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
'
```

Expected: `OK: no syntax errors`. If it reports a syntax error, fix the reported line (a common mistake with this "open/close optgroup on group change" pattern is an unbalanced `</optgroup>` — count that every `<optgroup>` has exactly one matching `</optgroup>`, including the final one after the loop).

- [ ] **Step 3: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: OK, no failures (this task touches no PHP files).

- [ ] **Step 4: Manual QA (no automated view tests exist in this project)**

Using a local environment with the seed data imported, open `/empleados/crear` and confirm: the "Distrito"/"Distrito alterno" selects show every seeded provincia grouped correctly via `<optgroup>`, defaulting to "— No aplica —"; picking a real distrito and saving, then re-opening `/empleados/{id}/editar`, shows that same distrito pre-selected.

- [ ] **Step 5: Commit**

```bash
git add templates/empleados/form.html.twig
git commit -m "feat(empleados): replace free-text location fields with a distrito select"
```

---

### Task 4: Documentation — `CLAUDE.md`

**Files:**
- Modify: `CLAUDE.md` (Grupo 8 section)

**Interfaces:** none — documentation only.

- [ ] **Step 1: Update the Grupo 8 note**

In `CLAUDE.md`, find the Grupo 8 blockquote (starts `> Agregado 23/08/2026 a partir de retroalimentación del tutor: debe existir una tabla persona...`). Replace this sentence:

```markdown
> **Igual que el Grupo 7, esto solo existe en el esquema y en el documento — el código PHP
> (`EmpleadosRepository`/`EmpleadosService` van a necesitar JOIN con `persona`) se actualiza
> en la fase de "Desarrollo del sistema".** Datos geográficos: subconjunto representativo
```

with:

```markdown
> **`EmpleadosRepository`/`EmpleadosService`/el formulario ya fueron migrados (14/09/2026) para
> leer/escribir a través de `persona`** — ver `docs/superpowers/specs/2026-09-14-empleados-persona-migration-design.md`.
> Las otras 12 tablas/repositorios que también hacen `JOIN empleados` por nombre (Asistencia,
> Horas Extra, Vacaciones, Incapacidades, Permisos, Nóminas, Aguinaldo, Liquidación, Evaluaciones,
> Reportes, Solicitudes, Portal) siguen pendientes — Fase 2, spec separado. Datos geográficos: subconjunto representativo
```

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: mark the Empleados-to-persona migration complete, note Phase 2 still pending"
```
