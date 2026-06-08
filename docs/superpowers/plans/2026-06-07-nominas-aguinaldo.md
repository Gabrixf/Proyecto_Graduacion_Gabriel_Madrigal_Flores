# Nóminas + Aguinaldo — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementar el módulo de Nóminas (cálculo de planilla quincenal, flujo híbrido) y el de Aguinaldo (Ley 2412), llevando el sistema de ~62% a ~77%.

**Architecture:** Controller → Service → Repository (PDO puro), igual que los módulos existentes. La matemática legal vive en una clase pura `App\Helpers\NominaCalculadora` (sin BD ni Slim) para poder probarse con PHPUnit. Las tasas CCSS se leen de `config/settings.php`.

**Tech Stack:** PHP 8.1+, Slim 4, PDO/MySQL, Twig, PHP-DI, PHPUnit 11.

**Reference module:** `HorasExtra` (Controller/Service/Repository) es la plantilla de estilo. Las rutas viven en `config/routes.php`; el wiring en `config/dependencies.php`. Las vistas extienden `templates/layouts/base.html.twig`.

**Branch:** `feature/nominas` (ya creada).

**Commit convention:** mensajes en inglés, terminar con `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

---

## File Structure

| Archivo | Responsabilidad |
|---|---|
| `phpunit.xml` (crear) | Config PHPUnit: testsuite, bootstrap autoload |
| `tests/Helpers/NominaCalculadoraTest.php` (crear) | Pruebas del cálculo legal |
| `src/Helpers/NominaCalculadora.php` (crear) | Cálculo puro: valor hora, monto HE, totales, CCSS |
| `src/Repositories/NominasRepository.php` (crear) | SQL `nominas`/`ingresos_nomina`/`deducciones_nomina` |
| `src/Services/NominasService.php` (crear) | Orquestación + validaciones + auditoría |
| `src/Controllers/NominasController.php` (crear) | HTTP: index, generate, show, líneas, aprobar, pagar |
| `templates/nominas/index.html.twig` (crear) | Lista por periodo + botón generar |
| `templates/nominas/detalle.html.twig` (crear) | Detalle: ingresos, deducciones, totales, acciones |
| `src/Repositories/AguinaldoRepository.php` (crear) | SQL `aguinaldo` + suma de brutos |
| `src/Services/AguinaldoService.php` (crear) | Cálculo /12 + auditoría |
| `src/Controllers/AguinaldoController.php` (crear) | HTTP: index, calcular, pagar |
| `templates/aguinaldo/index.html.twig` (crear) | Lista por año + botón calcular |
| `config/dependencies.php` (modificar) | Registrar calculadora, repos, services, controllers |
| `config/routes.php` (modificar) | Rellenar grupo `/nominas`; agregar grupo `/aguinaldo` |

---

## Task 1: PHPUnit setup + NominaCalculadora (TDD)

**Files:**
- Create: `phpunit.xml`
- Create: `tests/Helpers/NominaCalculadoraTest.php`
- Create: `src/Helpers/NominaCalculadora.php`

- [ ] **Step 1: Create `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="unit">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 2: Write the failing test** — `tests/Helpers/NominaCalculadoraTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Helpers\NominaCalculadora;
use PHPUnit\Framework\TestCase;

final class NominaCalculadoraTest extends TestCase
{
    private function calc(): NominaCalculadora
    {
        return new NominaCalculadora(0.1067, 0.2633);
    }

    public function testValorHora(): void
    {
        // 240000 / 240 = 1000
        self::assertEqualsWithDelta(1000.0, $this->calc()->valorHora(240000.0), 0.001);
    }

    public function testMontoHorasExtraOrdinaria(): void
    {
        // 1000 * 3h * 1.5 = 4500
        self::assertEqualsWithDelta(4500.0, $this->calc()->montoHorasExtra(240000.0, 3.0, 1.5), 0.001);
    }

    public function testMontoHorasExtraFeriado(): void
    {
        // 1000 * 3h * 2.0 = 6000
        self::assertEqualsWithDelta(6000.0, $this->calc()->montoHorasExtra(240000.0, 3.0, 2.0), 0.001);
    }

    public function testDeduccionesCCSSDevuelveObreraYPatronal(): void
    {
        $ded = $this->calc()->deduccionesCCSS(124500.0);
        self::assertCount(2, $ded);
        self::assertSame('CCSS_empleado', $ded[0]['tipo']);
        self::assertFalse($ded[0]['informativa']);
        self::assertEqualsWithDelta(13284.15, $ded[0]['monto'], 0.01); // 124500 * 0.1067
        self::assertSame('CCSS_patronal', $ded[1]['tipo']);
        self::assertTrue($ded[1]['informativa']);
        self::assertEqualsWithDelta(32774.85, $ded[1]['monto'], 0.01); // 124500 * 0.2633
    }

    public function testCalcularTotalesExcluyeInformativaDelNeto(): void
    {
        $ingresos    = [['monto' => 120000.0], ['monto' => 4500.0]];
        $deducciones = $this->calc()->deduccionesCCSS(124500.0);
        $t = $this->calc()->calcularTotales($ingresos, $deducciones);

        self::assertEqualsWithDelta(124500.0, $t['salario_bruto'], 0.01);
        self::assertEqualsWithDelta(13284.15, $t['total_deducciones'], 0.01); // solo obrera
        self::assertEqualsWithDelta(111215.85, $t['salario_neto'], 0.01);     // bruto - obrera
    }
}
```

- [ ] **Step 3: Run the test, expect FAIL**

Run: `vendor\bin\phpunit --testdox`
Expected: FAIL — `Class "App\Helpers\NominaCalculadora" not found`.

- [ ] **Step 4: Implement `src/Helpers/NominaCalculadora.php`**

```php
<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * NominaCalculadora — cálculo puro de la planilla (sin BD ni HTTP).
 * Tasas inyectadas desde config/settings.php para no hardcodear.
 */
final class NominaCalculadora
{
    public function __construct(
        private readonly float $ccssObrera,   // 0.1067
        private readonly float $ccssPatronal  // 0.2633
    ) {}

    /** Valor de la hora ordinaria: salario mensual / 240 (30 días × 8 h). */
    public function valorHora(float $salarioMensual): float
    {
        return $salarioMensual / 240.0;
    }

    /** Monto de horas extra = valor hora × cantidad × factor de recargo. */
    public function montoHorasExtra(float $salarioMensual, float $horas, float $factor): float
    {
        return round($this->valorHora($salarioMensual) * $horas * $factor, 2);
    }

    /**
     * Líneas de deducción CCSS. La patronal se marca informativa (no reduce el neto).
     *
     * @return array<int, array{tipo:string, descripcion:string, porcentaje:float, monto:float, informativa:bool}>
     */
    public function deduccionesCCSS(float $bruto): array
    {
        return [
            [
                'tipo'        => 'CCSS_empleado',
                'descripcion' => 'CCSS cuota obrera',
                'porcentaje'  => round($this->ccssObrera * 100, 2),
                'monto'       => round($bruto * $this->ccssObrera, 2),
                'informativa' => false,
            ],
            [
                'tipo'        => 'CCSS_patronal',
                'descripcion' => 'CCSS cuota patronal (informativa)',
                'porcentaje'  => round($this->ccssPatronal * 100, 2),
                'monto'       => round($bruto * $this->ccssPatronal, 2),
                'informativa' => true,
            ],
        ];
    }

    /**
     * Totales de la nómina. Las deducciones con informativa=true se excluyen del neto.
     *
     * @param array<int, array{monto: float|int|string}> $ingresos
     * @param array<int, array{monto: float|int|string, informativa?: bool}> $deducciones
     * @return array{total_ingresos: float, total_deducciones: float, salario_bruto: float, salario_neto: float}
     */
    public function calcularTotales(array $ingresos, array $deducciones): array
    {
        $bruto = 0.0;
        foreach ($ingresos as $i) {
            $bruto += (float) $i['monto'];
        }

        $totalDed = 0.0;
        foreach ($deducciones as $d) {
            if (!empty($d['informativa'])) {
                continue; // CCSS patronal u otra línea informativa
            }
            $totalDed += (float) $d['monto'];
        }

        $bruto    = round($bruto, 2);
        $totalDed = round($totalDed, 2);

        return [
            'total_ingresos'    => $bruto,
            'total_deducciones' => $totalDed,
            'salario_bruto'     => $bruto,
            'salario_neto'      => round($bruto - $totalDed, 2),
        ];
    }
}
```

- [ ] **Step 5: Run the test, expect PASS**

Run: `vendor\bin\phpunit --testdox`
Expected: PASS — 5 tests, all green.

- [ ] **Step 6: Commit**

```bash
git add phpunit.xml tests/Helpers/NominaCalculadoraTest.php src/Helpers/NominaCalculadora.php
git commit -m "feat(nominas): add NominaCalculadora with unit tests" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: NominasRepository

**Files:**
- Create: `src/Repositories/NominasRepository.php`

Mirror the PDO style of `HorasExtraRepository` (constructor `private readonly PDO $pdo`, prepared statements, named params).

- [ ] **Step 1: Implement `src/Repositories/NominasRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * NominasRepository — SQL de nominas + ingresos_nomina + deducciones_nomina.
 */
class NominasRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int, array<string, mixed>> */
    public function findAll(?int $idPeriodo = null): array
    {
        $sql = "SELECT n.id_nomina, n.id_empleado, n.id_periodo, n.salario_base,
                       n.total_ingresos, n.total_deducciones, n.salario_bruto, n.salario_neto,
                       n.estado, n.fecha_calculo, e.nombre, e.apellidos
                  FROM nominas n
                  JOIN empleados e ON e.id_empleado = n.id_empleado";
        $params = [];
        if ($idPeriodo !== null) {
            $sql .= ' WHERE n.id_periodo = :periodo';
            $params[':periodo'] = $idPeriodo;
        }
        $sql .= ' ORDER BY e.apellidos ASC, e.nombre ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Encabezado + líneas. @return array<string, mixed>|null */
    public function findByIdConLineas(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT n.*, e.nombre, e.apellidos, p.fecha_inicio, p.fecha_fin
               FROM nominas n
               JOIN empleados e ON e.id_empleado = n.id_empleado
               JOIN periodos_pago p ON p.id_periodo = n.id_periodo
              WHERE n.id_nomina = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $nomina = $stmt->fetch();
        if ($nomina === false) {
            return null;
        }
        $ing = $this->pdo->prepare('SELECT * FROM ingresos_nomina WHERE id_nomina = :id ORDER BY id_ingreso');
        $ing->execute([':id' => $id]);
        $ded = $this->pdo->prepare('SELECT * FROM deducciones_nomina WHERE id_nomina = :id ORDER BY id_deduccion');
        $ded->execute([':id' => $id]);
        $nomina['ingresos']    = $ing->fetchAll();
        $nomina['deducciones'] = $ded->fetchAll();
        return $nomina;
    }

    public function existePeriodo(int $idPeriodo): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM periodos_pago WHERE id_periodo = :id');
        $stmt->execute([':id' => $idPeriodo]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function existeNominaEmpleadoPeriodo(int $idEmpleado, int $idPeriodo): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM nominas WHERE id_empleado = :e AND id_periodo = :p'
        );
        $stmt->execute([':e' => $idEmpleado, ':p' => $idPeriodo]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Empleados activos sin nómina en el periodo, con su salario mensual (del puesto).
     * @return array<int, array<string, mixed>>
     */
    public function empleadosActivosSinNomina(int $idPeriodo): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.id_empleado, e.nombre, e.apellidos, p.salario_base AS salario_mensual
               FROM empleados e
               JOIN puestos p ON p.id_puesto = e.id_puesto
              WHERE e.estado = 'activo'
                AND NOT EXISTS (
                    SELECT 1 FROM nominas n
                     WHERE n.id_empleado = e.id_empleado AND n.id_periodo = :periodo
                )
              ORDER BY e.apellidos ASC"
        );
        $stmt->execute([':periodo' => $idPeriodo]);
        return $stmt->fetchAll();
    }

    /** Salario mensual (del puesto) de un empleado. */
    public function salarioMensualEmpleado(int $idEmpleado): ?float
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.salario_base FROM empleados e
               JOIN puestos p ON p.id_puesto = e.id_puesto
              WHERE e.id_empleado = :id LIMIT 1'
        );
        $stmt->execute([':id' => $idEmpleado]);
        $val = $stmt->fetchColumn();
        return $val === false ? null : (float) $val;
    }

    /** Horas extra del empleado en el periodo. @return array<int, array<string, mixed>> */
    public function horasExtraDelPeriodo(int $idEmpleado, int $idPeriodo): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fecha, cantidad_horas, factor_recargo
               FROM horas_extra
              WHERE id_empleado = :e AND id_periodo = :p'
        );
        $stmt->execute([':e' => $idEmpleado, ':p' => $idPeriodo]);
        return $stmt->fetchAll();
    }

    /**
     * Inserta una nómina completa (encabezado + líneas) en una transacción.
     * @param array<string, mixed> $cab
     * @param array<int, array<string, mixed>> $ingresos
     * @param array<int, array<string, mixed>> $deducciones
     */
    public function insertNominaCompleta(array $cab, array $ingresos, array $deducciones): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO nominas
                    (id_empleado, id_periodo, salario_base, total_ingresos, total_deducciones,
                     salario_bruto, salario_neto, estado)
                 VALUES (:e, :p, :sb, :ti, :td, :br, :ne, :st)'
            );
            $stmt->execute([
                ':e'  => $cab['id_empleado'],
                ':p'  => $cab['id_periodo'],
                ':sb' => $cab['salario_base'],
                ':ti' => $cab['total_ingresos'],
                ':td' => $cab['total_deducciones'],
                ':br' => $cab['salario_bruto'],
                ':ne' => $cab['salario_neto'],
                ':st' => $cab['estado'] ?? 'borrador',
            ]);
            $idNomina = (int) $this->pdo->lastInsertId();

            foreach ($ingresos as $i) {
                $this->insertIngresoTx($idNomina, $i);
            }
            foreach ($deducciones as $d) {
                $this->insertDeduccionTx($idNomina, $d);
            }
            $this->pdo->commit();
            return $idNomina;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string, mixed> $i */
    private function insertIngresoTx(int $idNomina, array $i): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ingresos_nomina (id_nomina, tipo, descripcion, monto)
             VALUES (:n, :t, :d, :m)'
        );
        $stmt->execute([
            ':n' => $idNomina,
            ':t' => $i['tipo'],
            ':d' => $i['descripcion'] ?? null,
            ':m' => $i['monto'],
        ]);
    }

    /** @param array<string, mixed> $d */
    private function insertDeduccionTx(int $idNomina, array $d): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO deducciones_nomina (id_nomina, tipo, descripcion, porcentaje, monto)
             VALUES (:n, :t, :d, :pc, :m)'
        );
        $stmt->execute([
            ':n'  => $idNomina,
            ':t'  => $d['tipo'],
            ':d'  => $d['descripcion'] ?? null,
            ':pc' => $d['porcentaje'] ?? null,
            ':m'  => $d['monto'],
        ]);
    }

    /** @param array<string, mixed> $i */
    public function insertIngreso(int $idNomina, array $i): int
    {
        $this->insertIngresoTx($idNomina, $i);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $d */
    public function insertDeduccion(int $idNomina, array $d): int
    {
        $this->insertDeduccionTx($idNomina, $d);
        return (int) $this->pdo->lastInsertId();
    }

    public function deleteIngreso(int $idNomina, int $idIngreso): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ingresos_nomina WHERE id_ingreso = :i AND id_nomina = :n');
        $stmt->execute([':i' => $idIngreso, ':n' => $idNomina]);
    }

    public function deleteDeduccion(int $idNomina, int $idDeduccion): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM deducciones_nomina WHERE id_deduccion = :d AND id_nomina = :n');
        $stmt->execute([':d' => $idDeduccion, ':n' => $idNomina]);
    }

    /** @param array{total_ingresos:float,total_deducciones:float,salario_bruto:float,salario_neto:float} $t */
    public function updateTotales(int $id, array $t): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE nominas SET total_ingresos = :ti, total_deducciones = :td,
                    salario_bruto = :br, salario_neto = :ne
              WHERE id_nomina = :id'
        );
        $stmt->execute([
            ':ti' => $t['total_ingresos'],
            ':td' => $t['total_deducciones'],
            ':br' => $t['salario_bruto'],
            ':ne' => $t['salario_neto'],
            ':id' => $id,
        ]);
    }

    public function updateEstado(int $id, string $estado): void
    {
        $stmt = $this->pdo->prepare('UPDATE nominas SET estado = :e WHERE id_nomina = :id');
        $stmt->execute([':e' => $estado, ':id' => $id]);
    }

    public function delete(int $id): void
    {
        // ingresos_nomina y deducciones_nomina tienen ON DELETE CASCADE
        $stmt = $this->pdo->prepare('DELETE FROM nominas WHERE id_nomina = :id');
        $stmt->execute([':id' => $id]);
    }

    /** Periodos para el filtro. @return array<int, array<string, mixed>> */
    public function periodos(): array
    {
        return $this->pdo->query(
            'SELECT id_periodo, fecha_inicio, fecha_fin, estado FROM periodos_pago ORDER BY fecha_inicio DESC'
        )->fetchAll();
    }
}
```

- [ ] **Step 2: Lint check**

Run: `vendor\bin\phpunit --testdox` (asegura que el autoload sigue sano; aún no hay test del repo)
Expected: PASS (los 5 tests de Task 1 siguen verdes).

- [ ] **Step 3: Commit**

```bash
git add src/Repositories/NominasRepository.php
git commit -m "feat(nominas): add NominasRepository" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: NominasService

**Files:**
- Create: `src/Services/NominasService.php`

Mirror `HorasExtraService` (constructor inyecta repos + `AuditoriaRepository`; lanza `InvalidArgumentException`/`RuntimeException`; registra auditoría en cada mutación).

- [ ] **Step 1: Implement `src/Services/NominasService.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\NominaCalculadora;
use App\Repositories\AuditoriaRepository;
use App\Repositories\NominasRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * NominasService — generación y gestión de nóminas quincenales.
 */
class NominasService
{
    public function __construct(
        private readonly NominasRepository  $repo,
        private readonly AuditoriaRepository $auditoriaRepo,
        private readonly NominaCalculadora  $calc
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function listar(?int $idPeriodo = null): array
    {
        return $this->repo->findAll($idPeriodo);
    }

    /** @return array<int, array<string, mixed>> */
    public function periodos(): array
    {
        return $this->repo->periodos();
    }

    /** @return array<string, mixed> */
    public function obtener(int $id): array
    {
        $n = $this->repo->findByIdConLineas($id);
        if ($n === null) {
            throw new RuntimeException('Nómina no encontrada.');
        }
        return $n;
    }

    /**
     * Genera borradores de nómina para todos los empleados activos sin nómina en el periodo.
     * @return int cantidad generada
     */
    public function generarPeriodo(int $idPeriodo, int $loggedInId, string $ip): int
    {
        if (!$this->repo->existePeriodo($idPeriodo)) {
            throw new InvalidArgumentException('El periodo seleccionado no existe.');
        }
        $empleados = $this->repo->empleadosActivosSinNomina($idPeriodo);
        $generadas = 0;
        foreach ($empleados as $emp) {
            $idNomina = $this->generarUno((int) $emp['id_empleado'], $idPeriodo, (float) $emp['salario_mensual']);
            $this->auditoriaRepo->insert('INSERT', $loggedInId, 'nominas', $idNomina, $ip);
            $generadas++;
        }
        return $generadas;
    }

    /** Construye y persiste una nómina para un empleado. */
    private function generarUno(int $idEmpleado, int $idPeriodo, float $salarioMensual): int
    {
        $salarioQuincena = round($salarioMensual / 2.0, 2);

        $ingresos = [[
            'tipo'        => 'salario_base',
            'descripcion' => 'Salario base quincenal',
            'monto'       => $salarioQuincena,
        ]];

        foreach ($this->repo->horasExtraDelPeriodo($idEmpleado, $idPeriodo) as $he) {
            $monto = $this->calc->montoHorasExtra(
                $salarioMensual,
                (float) $he['cantidad_horas'],
                (float) $he['factor_recargo']
            );
            $ingresos[] = [
                'tipo'        => 'horas_extra',
                'descripcion' => 'Horas extra ' . $he['fecha'] . ' (x' . $he['factor_recargo'] . ')',
                'monto'       => $monto,
            ];
        }

        $bruto       = array_sum(array_map(static fn ($i) => (float) $i['monto'], $ingresos));
        $deducciones = $this->calc->deduccionesCCSS(round($bruto, 2));
        $totales     = $this->calc->calcularTotales($ingresos, $deducciones);

        return $this->repo->insertNominaCompleta(
            [
                'id_empleado'       => $idEmpleado,
                'id_periodo'        => $idPeriodo,
                'salario_base'      => $salarioQuincena,
                'total_ingresos'    => $totales['total_ingresos'],
                'total_deducciones' => $totales['total_deducciones'],
                'salario_bruto'     => $totales['salario_bruto'],
                'salario_neto'      => $totales['salario_neto'],
                'estado'            => 'borrador',
            ],
            $ingresos,
            $deducciones
        );
    }

    /** Agrega una línea de ingreso manual y recalcula. */
    public function agregarIngreso(int $idNomina, array $datos, int $loggedInId, string $ip): void
    {
        $nomina = $this->obtener($idNomina);
        $this->assertBorrador($nomina);

        $tipo  = in_array($datos['tipo'] ?? '', ['bonificacion', 'comision', 'feriado', 'otro'], true)
            ? $datos['tipo'] : 'otro';
        $monto = $this->montoValido($datos['monto'] ?? '');

        $this->repo->insertIngreso($idNomina, [
            'tipo'        => $tipo,
            'descripcion' => trim((string) ($datos['descripcion'] ?? '')) ?: null,
            'monto'       => $monto,
        ]);
        $this->recalcular($idNomina);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'nominas', $idNomina, $ip);
    }

    /** Agrega una línea de deducción manual y recalcula. */
    public function agregarDeduccion(int $idNomina, array $datos, int $loggedInId, string $ip): void
    {
        $nomina = $this->obtener($idNomina);
        $this->assertBorrador($nomina);

        $tipo  = in_array($datos['tipo'] ?? '', ['embargo', 'otro'], true) ? $datos['tipo'] : 'otro';
        $monto = $this->montoValido($datos['monto'] ?? '');

        $this->repo->insertDeduccion($idNomina, [
            'tipo'        => $tipo,
            'descripcion' => trim((string) ($datos['descripcion'] ?? '')) ?: null,
            'porcentaje'  => null,
            'monto'       => $monto,
        ]);
        $this->recalcular($idNomina);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'nominas', $idNomina, $ip);
    }

    public function quitarLinea(int $idNomina, string $tipoLinea, int $idLinea, int $loggedInId, string $ip): void
    {
        $nomina = $this->obtener($idNomina);
        $this->assertBorrador($nomina);
        if ($tipoLinea === 'ingreso') {
            $this->repo->deleteIngreso($idNomina, $idLinea);
        } elseif ($tipoLinea === 'deduccion') {
            $this->repo->deleteDeduccion($idNomina, $idLinea);
        } else {
            throw new InvalidArgumentException('Tipo de línea inválido.');
        }
        $this->recalcular($idNomina);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'nominas', $idNomina, $ip);
    }

    public function aprobar(int $id, int $loggedInId, string $ip): void
    {
        $nomina = $this->obtener($id);
        if ($nomina['estado'] !== 'borrador') {
            throw new RuntimeException('Solo se puede aprobar una nómina en borrador.');
        }
        $this->repo->updateEstado($id, 'aprobado');
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'nominas', $id, $ip);
    }

    public function marcarPagado(int $id, int $loggedInId, string $ip): void
    {
        $nomina = $this->obtener($id);
        if ($nomina['estado'] !== 'aprobado') {
            throw new RuntimeException('Solo se puede pagar una nómina aprobada.');
        }
        $this->repo->updateEstado($id, 'pagado');
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'nominas', $id, $ip);
    }

    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $nomina = $this->obtener($id);
        $this->assertBorrador($nomina);
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'nominas', $id, $ip);
    }

    /** Recalcula totales a partir de las líneas actuales. */
    private function recalcular(int $idNomina): void
    {
        $n = $this->repo->findByIdConLineas($idNomina);
        if ($n === null) {
            return;
        }
        $deducciones = array_map(
            static fn ($d) => [
                'monto'       => (float) $d['monto'],
                'informativa' => $d['tipo'] === 'CCSS_patronal',
            ],
            $n['deducciones']
        );
        $totales = $this->calc->calcularTotales($n['ingresos'], $deducciones);
        $this->repo->updateTotales($idNomina, $totales);
    }

    /** @param array<string, mixed> $nomina */
    private function assertBorrador(array $nomina): void
    {
        if (($nomina['estado'] ?? '') !== 'borrador') {
            throw new RuntimeException('La nómina no está en borrador; no se puede modificar.');
        }
    }

    private function montoValido(mixed $raw): float
    {
        if (!is_numeric($raw) || (float) $raw <= 0) {
            throw new InvalidArgumentException('El monto debe ser un número mayor a 0.');
        }
        return round((float) $raw, 2);
    }
}
```

- [ ] **Step 2: Syntax check**

Run: `C:\xampp\php\php.exe -l src\Services\NominasService.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/Services/NominasService.php
git commit -m "feat(nominas): add NominasService (generation, lines, states)" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: NominasController

**Files:**
- Create: `src/Controllers/NominasController.php`

Mirror `HorasExtraController` exactly for `urlFor`/`consumeFlash`/flash patterns.

- [ ] **Step 1: Implement `src/Controllers/NominasController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\NominasService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class NominasController
{
    public function __construct(
        private readonly Twig           $twig,
        private readonly NominasService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params    = $request->getQueryParams();
        $idPeriodo = isset($params['periodo']) && is_numeric($params['periodo']) ? (int) $params['periodo'] : null;

        return $this->twig->render($response, 'nominas/index.html.twig', [
            'titulo'        => 'Nóminas',
            'nominas'       => $this->service->listar($idPeriodo),
            'periodos'      => $this->service->periodos(),
            'filtroPeriodo' => $idPeriodo,
            'flashSuccess'  => $this->consumeFlash('flash_success'),
            'flashError'    => $this->consumeFlash('flash_error'),
        ]);
    }

    public function generate(Request $request, Response $response): Response
    {
        $datos      = (array) $request->getParsedBody();
        $idPeriodo  = isset($datos['id_periodo']) && is_numeric($datos['id_periodo']) ? (int) $datos['id_periodo'] : 0;
        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $n = $this->service->generarPeriodo($idPeriodo, $loggedInId, $ip);
            $_SESSION['flash_success'] = $n > 0
                ? "Se generaron {$n} nómina(s) en borrador."
                : 'No había empleados pendientes de nómina en ese periodo.';
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        $url = $this->urlFor($request, 'nominas.index') . ($idPeriodo > 0 ? "?periodo={$idPeriodo}" : '');
        return $response->withHeader('Location', $url)->withStatus(302);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        try {
            $nomina = $this->service->obtener((int) $args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Nómina no encontrada.';
            return $response->withHeader('Location', $this->urlFor($request, 'nominas.index'))->withStatus(302);
        }
        return $this->twig->render($response, 'nominas/detalle.html.twig', [
            'titulo'       => 'Detalle de Nómina',
            'nomina'       => $nomina,
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function addIngreso(Request $request, Response $response, array $args): Response
    {
        return $this->mutarLinea($request, $response, $args, 'ingreso');
    }

    public function addDeduccion(Request $request, Response $response, array $args): Response
    {
        return $this->mutarLinea($request, $response, $args, 'deduccion');
    }

    private function mutarLinea(Request $request, Response $response, array $args, string $tipo): Response
    {
        $id         = (int) $args['id'];
        $datos      = (array) $request->getParsedBody();
        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            if ($tipo === 'ingreso') {
                $this->service->agregarIngreso($id, $datos, $loggedInId, $ip);
            } else {
                $this->service->agregarDeduccion($id, $datos, $loggedInId, $ip);
            }
            $_SESSION['flash_success'] = 'Línea agregada.';
        } catch (InvalidArgumentException | RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $response->withHeader('Location', $this->urlFor($request, 'nominas.show', ['id' => $id]))->withStatus(302);
    }

    public function removeLinea(Request $request, Response $response, array $args): Response
    {
        $id         = (int) $args['id'];
        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->quitarLinea($id, (string) $args['tipo'], (int) $args['idLinea'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Línea eliminada.';
        } catch (InvalidArgumentException | RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $response->withHeader('Location', $this->urlFor($request, 'nominas.show', ['id' => $id]))->withStatus(302);
    }

    public function aprobar(Request $request, Response $response, array $args): Response
    {
        return $this->cambiarEstado($request, $response, (int) $args['id'], 'aprobar');
    }

    public function pagar(Request $request, Response $response, array $args): Response
    {
        return $this->cambiarEstado($request, $response, (int) $args['id'], 'pagar');
    }

    private function cambiarEstado(Request $request, Response $response, int $id, string $accion): Response
    {
        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            if ($accion === 'aprobar') {
                $this->service->aprobar($id, $loggedInId, $ip);
                $_SESSION['flash_success'] = 'Nómina aprobada.';
            } else {
                $this->service->marcarPagado($id, $loggedInId, $ip);
                $_SESSION['flash_success'] = 'Nómina marcada como pagada.';
            }
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $response->withHeader('Location', $this->urlFor($request, 'nominas.show', ['id' => $id]))->withStatus(302);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->eliminar((int) $args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Nómina eliminada.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $response->withHeader('Location', $this->urlFor($request, 'nominas.index'))->withStatus(302);
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

Run: `C:\xampp\php\php.exe -l src\Controllers\NominasController.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/Controllers/NominasController.php
git commit -m "feat(nominas): add NominasController" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: Wire DI + routes for Nóminas

**Files:**
- Modify: `config/dependencies.php`
- Modify: `config/routes.php`

- [ ] **Step 1: Register in `config/dependencies.php`**

Add these factory definitions inside the returned array (after the existing repository/service/controller blocks, following the same closure style). Read `nomina.ccss_obrera` / `nomina.ccss_patronal` from settings:

```php
    \App\Helpers\NominaCalculadora::class => function (ContainerInterface $c) {
        $cfg = $c->get('settings')['nomina'];
        return new \App\Helpers\NominaCalculadora(
            (float) $cfg['ccss_obrera'],
            (float) $cfg['ccss_patronal']
        );
    },

    \App\Repositories\NominasRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\NominasRepository($c->get(PDO::class));
    },

    \App\Services\NominasService::class => function (ContainerInterface $c) {
        return new \App\Services\NominasService(
            $c->get(\App\Repositories\NominasRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class),
            $c->get(\App\Helpers\NominaCalculadora::class)
        );
    },

    \App\Controllers\NominasController::class => function (ContainerInterface $c) {
        return new \App\Controllers\NominasController(
            $c->get(Twig::class),
            $c->get(\App\Services\NominasService::class)
        );
    },
```

- [ ] **Step 2: Fill the `/nominas` group in `config/routes.php`**

Replace the empty group (currently lines ~150-151) with:

```php
    // ── Nóminas (solo admin) ──────────────────────────────
    $app->group('/nominas', function (RouteCollectorProxy $group) {
        $group->get('',                              [\App\Controllers\NominasController::class, 'index'])->setName('nominas.index');
        $group->post('/generar',                     [\App\Controllers\NominasController::class, 'generate'])->setName('nominas.generate');
        $group->get('/{id}',                         [\App\Controllers\NominasController::class, 'show'])->setName('nominas.show');
        $group->post('/{id}/ingreso',                [\App\Controllers\NominasController::class, 'addIngreso'])->setName('nominas.addIngreso');
        $group->post('/{id}/deduccion',              [\App\Controllers\NominasController::class, 'addDeduccion'])->setName('nominas.addDeduccion');
        $group->post('/{id}/linea/{tipo}/{idLinea}/eliminar', [\App\Controllers\NominasController::class, 'removeLinea'])->setName('nominas.removeLinea');
        $group->post('/{id}/aprobar',                [\App\Controllers\NominasController::class, 'aprobar'])->setName('nominas.aprobar');
        $group->post('/{id}/pagar',                  [\App\Controllers\NominasController::class, 'pagar'])->setName('nominas.pagar');
        $group->post('/{id}/eliminar',               [\App\Controllers\NominasController::class, 'destroy'])->setName('nominas.destroy');
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());
```

> Note: `GET /{id}` must come AFTER the static `''` and `POST /generar`; Slim matches by method+path so this ordering is safe, but keep `/{id}` numeric usage consistent.

- [ ] **Step 3: Syntax check both files**

Run: `C:\xampp\php\php.exe -l config\dependencies.php` and `C:\xampp\php\php.exe -l config\routes.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit**

```bash
git add config/dependencies.php config/routes.php
git commit -m "feat(nominas): wire DI and routes" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 6: Nóminas templates

**Files:**
- Create: `templates/nominas/index.html.twig`
- Create: `templates/nominas/detalle.html.twig`

- [ ] **Step 1: Create `templates/nominas/index.html.twig`**

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Nóminas{% endblock %}
{% block breadcrumb %}Inicio / Nóminas{% endblock %}

{% block contenido %}
<div class="card-fo mb-4">
    <form method="GET" action="{{ url_for('nominas.index') }}" class="row g-2 align-items-end">
        <div class="col-sm-6 col-md-4">
            <label class="form-label small fw-semibold">Periodo de pago</label>
            <select name="periodo" class="form-select" onchange="this.form.submit()">
                <option value="">— Todos —</option>
                {% for p in periodos %}
                <option value="{{ p.id_periodo }}" {{ filtroPeriodo == p.id_periodo ? 'selected' : '' }}>
                    {{ p.fecha_inicio }} a {{ p.fecha_fin }} ({{ p.estado }})
                </option>
                {% endfor %}
            </select>
        </div>
        <div class="col-auto">
            <a href="{{ url_for('nominas.index') }}" class="btn btn-outline-primary">Limpiar</a>
        </div>
        <div class="col-auto ms-auto">
            {% if filtroPeriodo %}
            <form method="POST" action="{{ url_for('nominas.generate') }}" class="d-inline">
                <input type="hidden" name="id_periodo" value="{{ filtroPeriodo }}">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-gear-fill me-1"></i> Generar nóminas del periodo
                </button>
            </form>
            {% endif %}
        </div>
    </form>
</div>

<div class="card-fo">
    <table class="table align-middle">
        <thead>
            <tr>
                <th>Empleado</th><th class="text-end">Salario base</th>
                <th class="text-end">Bruto</th><th class="text-end">Deducciones</th>
                <th class="text-end">Neto</th><th>Estado</th><th></th>
            </tr>
        </thead>
        <tbody>
            {% for n in nominas %}
            <tr>
                <td class="fw-semibold">{{ n.nombre }} {{ n.apellidos }}</td>
                <td class="text-end">₡{{ n.salario_base|number_format(2, '.', ',') }}</td>
                <td class="text-end">₡{{ n.salario_bruto|number_format(2, '.', ',') }}</td>
                <td class="text-end">₡{{ n.total_deducciones|number_format(2, '.', ',') }}</td>
                <td class="text-end fw-bold">₡{{ n.salario_neto|number_format(2, '.', ',') }}</td>
                <td>
                    <span class="badge-fo {{ n.estado == 'pagado' ? 'badge-ok' : (n.estado == 'aprobado' ? 'badge-emp' : 'badge-pend') }}">
                        {{ n.estado }}
                    </span>
                </td>
                <td class="text-end">
                    <a href="{{ url_for('nominas.show', {id: n.id_nomina}) }}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-eye"></i> Ver
                    </a>
                </td>
            </tr>
            {% else %}
            <tr><td colspan="7" class="text-center text-muted py-4">
                {{ filtroPeriodo ? 'No hay nóminas en este periodo. Usa “Generar nóminas del periodo”.' : 'Selecciona un periodo para ver o generar nóminas.' }}
            </td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>
{% endblock %}
```

- [ ] **Step 2: Create `templates/nominas/detalle.html.twig`**

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Detalle de Nómina{% endblock %}
{% block breadcrumb %}Inicio / Nóminas / Detalle{% endblock %}

{% block contenido %}
{% set editable = nomina.estado == 'borrador' %}

<div class="card-fo mb-4">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h5 class="mb-1">{{ nomina.nombre }} {{ nomina.apellidos }}</h5>
            <div class="text-muted small">Periodo: {{ nomina.fecha_inicio }} a {{ nomina.fecha_fin }}</div>
            <span class="badge-fo mt-2 {{ nomina.estado == 'pagado' ? 'badge-ok' : (nomina.estado == 'aprobado' ? 'badge-emp' : 'badge-pend') }}">
                {{ nomina.estado }}
            </span>
        </div>
        <div class="text-end">
            <div class="text-muted small">Salario neto</div>
            <div class="fs-3 fw-bold">₡{{ nomina.salario_neto|number_format(2, '.', ',') }}</div>
        </div>
    </div>
    <div class="mt-3 d-flex gap-2">
        {% if nomina.estado == 'borrador' %}
        <form method="POST" action="{{ url_for('nominas.aprobar', {id: nomina.id_nomina}) }}">
            <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Aprobar</button>
        </form>
        {% elseif nomina.estado == 'aprobado' %}
        <form method="POST" action="{{ url_for('nominas.pagar', {id: nomina.id_nomina}) }}">
            <button class="btn btn-primary"><i class="bi bi-cash-coin me-1"></i>Marcar pagada</button>
        </form>
        {% endif %}
        <a href="{{ url_for('nominas.index') }}?periodo={{ nomina.id_periodo }}" class="btn btn-outline-primary">Volver</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card-fo h-100">
            <h6 class="fw-bold mb-3">Ingresos</h6>
            <table class="table table-sm align-middle">
                <tbody>
                {% for i in nomina.ingresos %}
                    <tr>
                        <td>{{ i.descripcion ?? i.tipo }}</td>
                        <td class="text-end">₡{{ i.monto|number_format(2, '.', ',') }}</td>
                        <td class="text-end" style="width:40px">
                            {% if editable and i.tipo != 'salario_base' %}
                            <form method="POST" action="{{ url_for('nominas.removeLinea', {id: nomina.id_nomina, tipo: 'ingreso', idLinea: i.id_ingreso}) }}">
                                <button class="btn btn-sm btn-link text-danger p-0" title="Quitar"><i class="bi bi-x-circle"></i></button>
                            </form>
                            {% endif %}
                        </td>
                    </tr>
                {% endfor %}
                </tbody>
                <tfoot>
                    <tr class="fw-bold"><td>Total ingresos (bruto)</td><td class="text-end">₡{{ nomina.salario_bruto|number_format(2, '.', ',') }}</td><td></td></tr>
                </tfoot>
            </table>
            {% if editable %}
            <form method="POST" action="{{ url_for('nominas.addIngreso', {id: nomina.id_nomina}) }}" class="row g-1 mt-2">
                <div class="col-4"><select name="tipo" class="form-select form-select-sm">
                    <option value="bonificacion">Bonificación</option>
                    <option value="comision">Comisión</option>
                    <option value="feriado">Feriado</option>
                    <option value="otro">Otro</option>
                </select></div>
                <div class="col-4"><input name="descripcion" class="form-control form-control-sm" placeholder="Descripción"></div>
                <div class="col-3"><input name="monto" type="number" step="0.01" min="0" class="form-control form-control-sm" placeholder="Monto" required></div>
                <div class="col-1"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-plus"></i></button></div>
            </form>
            {% endif %}
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card-fo h-100">
            <h6 class="fw-bold mb-3">Deducciones</h6>
            <table class="table table-sm align-middle">
                <tbody>
                {% for d in nomina.deducciones %}
                    <tr class="{{ d.tipo == 'CCSS_patronal' ? 'text-muted' : '' }}">
                        <td>{{ d.descripcion ?? d.tipo }}{% if d.tipo == 'CCSS_patronal' %} <span class="badge-fo badge-pend">informativa</span>{% endif %}</td>
                        <td class="text-end">₡{{ d.monto|number_format(2, '.', ',') }}</td>
                        <td class="text-end" style="width:40px">
                            {% if editable and d.tipo not in ['CCSS_empleado','CCSS_patronal'] %}
                            <form method="POST" action="{{ url_for('nominas.removeLinea', {id: nomina.id_nomina, tipo: 'deduccion', idLinea: d.id_deduccion}) }}">
                                <button class="btn btn-sm btn-link text-danger p-0" title="Quitar"><i class="bi bi-x-circle"></i></button>
                            </form>
                            {% endif %}
                        </td>
                    </tr>
                {% endfor %}
                </tbody>
                <tfoot>
                    <tr class="fw-bold"><td>Total deducciones (afectan neto)</td><td class="text-end">₡{{ nomina.total_deducciones|number_format(2, '.', ',') }}</td><td></td></tr>
                </tfoot>
            </table>
            {% if editable %}
            <form method="POST" action="{{ url_for('nominas.addDeduccion', {id: nomina.id_nomina}) }}" class="row g-1 mt-2">
                <div class="col-4"><select name="tipo" class="form-select form-select-sm">
                    <option value="embargo">Embargo</option>
                    <option value="otro">Otro</option>
                </select></div>
                <div class="col-4"><input name="descripcion" class="form-control form-control-sm" placeholder="Descripción"></div>
                <div class="col-3"><input name="monto" type="number" step="0.01" min="0" class="form-control form-control-sm" placeholder="Monto" required></div>
                <div class="col-1"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-plus"></i></button></div>
            </form>
            {% endif %}
        </div>
    </div>
</div>
{% endblock %}
```

- [ ] **Step 3: Manual smoke test (server running on :8080)**

Log in as `admin`/`password123`, open `/nominas`, choose a periodo, click "Generar nóminas del periodo", open a nómina detail, add a bonificación, approve, mark paid. Confirm totals update and states lock editing.

- [ ] **Step 4: Commit**

```bash
git add templates/nominas/
git commit -m "feat(nominas): add index and detalle templates" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 7: AguinaldoRepository

**Files:**
- Create: `src/Repositories/AguinaldoRepository.php`

- [ ] **Step 1: Implement `src/Repositories/AguinaldoRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * AguinaldoRepository — SQL de aguinaldo y suma de salarios brutos.
 */
class AguinaldoRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int, array<string, mixed>> */
    public function findAll(int $anio): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.id_aguinaldo, a.id_empleado, a.anio, a.salarios_acumulados,
                    a.monto_aguinaldo, a.estado, a.fecha_pago, e.nombre, e.apellidos
               FROM aguinaldo a
               JOIN empleados e ON e.id_empleado = a.id_empleado
              WHERE a.anio = :anio
              ORDER BY e.apellidos ASC"
        );
        $stmt->execute([':anio' => $anio]);
        return $stmt->fetchAll();
    }

    /** Empleados activos. @return array<int, array<string, mixed>> */
    public function empleadosActivos(): array
    {
        return $this->pdo->query(
            "SELECT id_empleado, nombre, apellidos FROM empleados WHERE estado = 'activo' ORDER BY apellidos"
        )->fetchAll();
    }

    /**
     * Suma de salarios brutos de nóminas aprobadas/pagadas cuyo periodo cae en [desde, hasta].
     */
    public function sumaBrutos(int $idEmpleado, string $desde, string $hasta): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(n.salario_bruto), 0)
               FROM nominas n
               JOIN periodos_pago p ON p.id_periodo = n.id_periodo
              WHERE n.id_empleado = :e
                AND n.estado IN ('aprobado','pagado')
                AND p.fecha_inicio >= :desde
                AND p.fecha_fin <= :hasta"
        );
        $stmt->execute([':e' => $idEmpleado, ':desde' => $desde, ':hasta' => $hasta]);
        return (float) $stmt->fetchColumn();
    }

    /** Inserta o actualiza el aguinaldo (UNIQUE id_empleado, anio). */
    public function upsert(int $idEmpleado, int $anio, float $salariosAcumulados, float $monto): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO aguinaldo (id_empleado, anio, salarios_acumulados, monto_aguinaldo, estado)
             VALUES (:e, :a, :s, :m, 'calculado')
             ON DUPLICATE KEY UPDATE
                salarios_acumulados = VALUES(salarios_acumulados),
                monto_aguinaldo     = VALUES(monto_aguinaldo),
                estado              = 'calculado',
                fecha_pago          = NULL"
        );
        $stmt->execute([':e' => $idEmpleado, ':a' => $anio, ':s' => $salariosAcumulados, ':m' => $monto]);
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM aguinaldo WHERE id_aguinaldo = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function marcarPagado(int $id, string $fecha): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE aguinaldo SET estado = 'pagado', fecha_pago = :f WHERE id_aguinaldo = :id"
        );
        $stmt->execute([':f' => $fecha, ':id' => $id]);
    }
}
```

- [ ] **Step 2: Syntax check**

Run: `C:\xampp\php\php.exe -l src\Repositories\AguinaldoRepository.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/Repositories/AguinaldoRepository.php
git commit -m "feat(aguinaldo): add AguinaldoRepository" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 8: AguinaldoService (+ test del cálculo /12)

**Files:**
- Create: `src/Services/AguinaldoService.php`
- Create: `tests/Services/AguinaldoCalculoTest.php`

- [ ] **Step 1: Write the failing test** — `tests/Services/AguinaldoCalculoTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\AguinaldoService;
use PHPUnit\Framework\TestCase;

final class AguinaldoCalculoTest extends TestCase
{
    public function testMontoEsSumaEntreDoce(): void
    {
        // Método estático puro para el cálculo legal del aguinaldo.
        self::assertEqualsWithDelta(100000.0, AguinaldoService::calcularMonto(1200000.0), 0.01);
        self::assertEqualsWithDelta(0.0, AguinaldoService::calcularMonto(0.0), 0.01);
    }
}
```

- [ ] **Step 2: Run the test, expect FAIL**

Run: `vendor\bin\phpunit --testdox`
Expected: FAIL — `Call to undefined method App\Services\AguinaldoService::calcularMonto()` (o clase no encontrada).

- [ ] **Step 3: Implement `src/Services/AguinaldoService.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AguinaldoRepository;
use App\Repositories\AuditoriaRepository;
use RuntimeException;

/**
 * AguinaldoService — cálculo anual del aguinaldo (Ley 2412).
 */
class AguinaldoService
{
    public function __construct(
        private readonly AguinaldoRepository $repo,
        private readonly AuditoriaRepository $auditoriaRepo
    ) {}

    /** Monto del aguinaldo = suma de brutos / 12. */
    public static function calcularMonto(float $salariosAcumulados): float
    {
        return round($salariosAcumulados / 12.0, 2);
    }

    /** @return array<int, array<string, mixed>> */
    public function listar(int $anio): array
    {
        return $this->repo->findAll($anio);
    }

    /**
     * Calcula el aguinaldo de todos los empleados activos para el año dado.
     * Periodo legal: 1-dic (año-1) a 30-nov (año).
     * @return int cantidad procesada
     */
    public function calcular(int $anio, int $loggedInId, string $ip): int
    {
        $desde = ($anio - 1) . '-12-01';
        $hasta = $anio . '-11-30';
        $n = 0;
        foreach ($this->repo->empleadosActivos() as $emp) {
            $idEmpleado = (int) $emp['id_empleado'];
            $suma  = $this->repo->sumaBrutos($idEmpleado, $desde, $hasta);
            $monto = self::calcularMonto($suma);
            $this->repo->upsert($idEmpleado, $anio, $suma, $monto);
            $this->auditoriaRepo->insert('INSERT', $loggedInId, 'aguinaldo', $idEmpleado, $ip);
            $n++;
        }
        return $n;
    }

    public function marcarPagado(int $id, string $fecha, int $loggedInId, string $ip): void
    {
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new RuntimeException('Aguinaldo no encontrado.');
        }
        if ($row['estado'] === 'pagado') {
            throw new RuntimeException('El aguinaldo ya está pagado.');
        }
        $this->repo->marcarPagado($id, $fecha);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'aguinaldo', $id, $ip);
    }
}
```

- [ ] **Step 4: Run the test, expect PASS**

Run: `vendor\bin\phpunit --testdox`
Expected: PASS — all tests green (6 + 1).

- [ ] **Step 5: Commit**

```bash
git add src/Services/AguinaldoService.php tests/Services/AguinaldoCalculoTest.php
git commit -m "feat(aguinaldo): add AguinaldoService with calc test" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 9: AguinaldoController + DI + routes + template

**Files:**
- Create: `src/Controllers/AguinaldoController.php`
- Modify: `config/dependencies.php`
- Modify: `config/routes.php`
- Create: `templates/aguinaldo/index.html.twig`

- [ ] **Step 1: Implement `src/Controllers/AguinaldoController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AguinaldoService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class AguinaldoController
{
    public function __construct(
        private readonly Twig             $twig,
        private readonly AguinaldoService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $anio   = isset($params['anio']) && is_numeric($params['anio'])
            ? (int) $params['anio']
            : (int) date('Y');

        return $this->twig->render($response, 'aguinaldo/index.html.twig', [
            'titulo'       => 'Aguinaldo',
            'anio'         => $anio,
            'aguinaldos'   => $this->service->listar($anio),
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function calcular(Request $request, Response $response): Response
    {
        $datos      = (array) $request->getParsedBody();
        $anio       = isset($datos['anio']) && is_numeric($datos['anio']) ? (int) $datos['anio'] : (int) date('Y');
        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $n = $this->service->calcular($anio, $loggedInId, $ip);
        $_SESSION['flash_success'] = "Aguinaldo calculado para {$n} empleado(s).";
        return $response->withHeader('Location', $this->urlFor($request, 'aguinaldo.index') . "?anio={$anio}")->withStatus(302);
    }

    public function pagar(Request $request, Response $response, array $args): Response
    {
        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->marcarPagado((int) $args['id'], date('Y-m-d'), $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Aguinaldo marcado como pagado.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        $anio = $request->getQueryParams()['anio'] ?? date('Y');
        return $response->withHeader('Location', $this->urlFor($request, 'aguinaldo.index') . "?anio={$anio}")->withStatus(302);
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

- [ ] **Step 2: Register DI in `config/dependencies.php`**

```php
    \App\Repositories\AguinaldoRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\AguinaldoRepository($c->get(PDO::class));
    },

    \App\Services\AguinaldoService::class => function (ContainerInterface $c) {
        return new \App\Services\AguinaldoService(
            $c->get(\App\Repositories\AguinaldoRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },

    \App\Controllers\AguinaldoController::class => function (ContainerInterface $c) {
        return new \App\Controllers\AguinaldoController(
            $c->get(Twig::class),
            $c->get(\App\Services\AguinaldoService::class)
        );
    },
```

- [ ] **Step 3: Add the `/aguinaldo` group in `config/routes.php`** (after the `/nominas` group)

```php
    // ── Aguinaldo (solo admin) ────────────────────────────
    $app->group('/aguinaldo', function (RouteCollectorProxy $group) {
        $group->get('',               [\App\Controllers\AguinaldoController::class, 'index'])->setName('aguinaldo.index');
        $group->post('/calcular',     [\App\Controllers\AguinaldoController::class, 'calcular'])->setName('aguinaldo.calcular');
        $group->post('/{id}/pagar',   [\App\Controllers\AguinaldoController::class, 'pagar'])->setName('aguinaldo.pagar');
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());
```

- [ ] **Step 4: Create `templates/aguinaldo/index.html.twig`**

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Aguinaldo{% endblock %}
{% block breadcrumb %}Inicio / Aguinaldo{% endblock %}

{% block contenido %}
<div class="card-fo mb-4">
    <form method="GET" action="{{ url_for('aguinaldo.index') }}" class="row g-2 align-items-end">
        <div class="col-auto">
            <label class="form-label small fw-semibold">Año</label>
            <input type="number" name="anio" value="{{ anio }}" class="form-control" style="width:120px">
        </div>
        <div class="col-auto"><button class="btn btn-outline-primary">Ver</button></div>
        <div class="col-auto ms-auto">
            <form method="POST" action="{{ url_for('aguinaldo.calcular') }}" class="d-inline">
                <input type="hidden" name="anio" value="{{ anio }}">
                <button class="btn btn-primary"><i class="bi bi-gift me-1"></i>Calcular aguinaldo {{ anio }}</button>
            </form>
        </div>
    </form>
    <p class="text-muted small mt-2 mb-0">
        Periodo legal (Ley 2412): 1-dic-{{ anio - 1 }} al 30-nov-{{ anio }}. Monto = suma de salarios brutos ÷ 12.
    </p>
</div>

<div class="card-fo">
    <table class="table align-middle">
        <thead>
            <tr><th>Empleado</th><th class="text-end">Salarios acumulados</th>
                <th class="text-end">Aguinaldo</th><th>Estado</th><th></th></tr>
        </thead>
        <tbody>
            {% for a in aguinaldos %}
            <tr>
                <td class="fw-semibold">{{ a.nombre }} {{ a.apellidos }}</td>
                <td class="text-end">₡{{ a.salarios_acumulados|number_format(2, '.', ',') }}</td>
                <td class="text-end fw-bold">₡{{ a.monto_aguinaldo|number_format(2, '.', ',') }}</td>
                <td><span class="badge-fo {{ a.estado == 'pagado' ? 'badge-ok' : 'badge-pend' }}">{{ a.estado }}</span></td>
                <td class="text-end">
                    {% if a.estado != 'pagado' %}
                    <form method="POST" action="{{ url_for('aguinaldo.pagar', {id: a.id_aguinaldo}) }}?anio={{ anio }}">
                        <button class="btn btn-sm btn-primary"><i class="bi bi-cash-coin me-1"></i>Pagar</button>
                    </form>
                    {% endif %}
                </td>
            </tr>
            {% else %}
            <tr><td colspan="5" class="text-center text-muted py-4">
                Sin aguinaldos calculados para {{ anio }}. Usa “Calcular aguinaldo”.
            </td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>
{% endblock %}
```

- [ ] **Step 5: Syntax check + run tests**

Run: `C:\xampp\php\php.exe -l src\Controllers\AguinaldoController.php` (expect no errors) and `vendor\bin\phpunit` (expect all green).

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/AguinaldoController.php config/dependencies.php config/routes.php templates/aguinaldo/
git commit -m "feat(aguinaldo): add controller, wiring and template" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 10: Add sidebar links + manual end-to-end verification

**Files:**
- Modify: `templates/layouts/base.html.twig` (the `Nómina` group links: point "Gestionar Nóminas" → `nominas.index`, "Aguinaldo" → `aguinaldo.index`)

- [ ] **Step 1: Update the two sidebar links in `templates/layouts/base.html.twig`**

In the `Nómina` group, change:
```twig
<a class="sb-item" href="#"><i class="bi bi-receipt"></i><span>Gestionar Nóminas</span></a>
<a class="sb-item" href="#"><i class="bi bi-gift"></i><span>Aguinaldo</span></a>
```
to:
```twig
<a class="sb-item" href="{{ url_for('nominas.index') }}"><i class="bi bi-receipt"></i><span>Gestionar Nóminas</span></a>
<a class="sb-item" href="{{ url_for('aguinaldo.index') }}"><i class="bi bi-gift"></i><span>Aguinaldo</span></a>
```

- [ ] **Step 2: Manual end-to-end test** (server on :8080, XAMPP MySQL up)

1. Log in as `admin`/`password123`.
2. Go to **Nóminas** → pick a periodo → **Generar nóminas del periodo** → verify drafts appear with CCSS deductions and correct neto.
3. Open a nómina → add a bonificación → verify totals recalc → **Aprobar** → confirm editing locks → **Marcar pagada**.
4. Go to **Aguinaldo** → pick the year → **Calcular** → verify amounts = Σ brutos ÷ 12 → **Pagar**.
5. Verify `auditoria` recorded the actions (optional: query the table).

- [ ] **Step 3: Run the full test suite**

Run: `vendor\bin\phpunit --testdox`
Expected: all tests PASS.

- [ ] **Step 4: Commit**

```bash
git add templates/layouts/base.html.twig
git commit -m "feat(nominas): link Nóminas and Aguinaldo in sidebar" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

- [ ] **Step 5: Update module status in `CLAUDE.md`** (mark módulos 9 y 10 as ✅) and commit:

```bash
git add CLAUDE.md
git commit -m "docs: mark Nóminas and Aguinaldo modules complete" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Self-Review notes

- **Spec coverage:** Calculadora (Task 1), Repository (2), Service (3), Controller (4), DI+routes (5), templates (6), Aguinaldo repo/service/controller/template (7–9), sidebar + E2E (10). All spec sections covered.
- **Types consistent:** `calcularTotales` keys (`total_ingresos/total_deducciones/salario_bruto/salario_neto`) used identically in Repository `updateTotales`, Service, and DB columns. `deduccionesCCSS` returns `informativa` flag consumed by `calcularTotales` and `recalcular`.
- **No placeholders:** every step has complete code/commands.
- **DB note:** `ingresos_nomina`/`deducciones_nomina` have `ON DELETE CASCADE`, so deleting a nómina cascades (Repository `delete`). `aguinaldo.upsert` relies on `UNIQUE(id_empleado, anio)`.
