# Horas extra detectadas por horario — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el sistema detecte las horas extra comparando la salida marcada con el horario de cada empleado, decida el factor según el tipo de día (1.50 / 3.00), pida justificación al empleado, las haga aprobar por el admin y pague en la nómina el adicional sencillo por feriado o descanso trabajado.

**Architecture:** Un helper puro `ClasificadorHoras` concentra la regla (tipo de día, horas ordinarias / especiales / extra, factor). Un módulo nuevo `Horarios` (con tabla de detalle `horario_dias`) dice qué días y horas trabaja cada empleado. `DeteccionHorasExtraService` crea solicitudes `origen = asistencia` al marcar o corregir la salida; `AprobacionHorasExtraService` las convierte en `horas_extra` y las lleva a la nómina correcta. Todo sigue el patrón Controller → Service → Repository con PDO puro.

**Tech Stack:** PHP 8.1+ (`declare(strict_types=1)`), Slim 4, Twig 3, PHP-DI 7, MySQL/MariaDB con PDO puro, PHPUnit 11. Sin ORM, sin npm.

**Spec:** `docs/superpowers/specs/2026-10-17-horas-extra-por-horario-design.md`

## Global Constraints

- Stack bloqueado: no ORM, no frameworks JS, no npm/Node/Docker (`CLAUDE.md`).
- Controllers nunca tocan PDO ni `$_SESSION['usuario_*']`; leen `$request->getAttribute('usuario')`.
- Repositories solo SQL; la lógica va en Services/Helpers.
- Ninguna columna acepta NULL: FK opcionales apuntan a la fila centinela `id=1`; textos opcionales `DEFAULT ''`.
- Rutas solo GET/POST; formularios con patrón POST-Redirect-GET y flash `flash_success` / `flash_error`.
- Factores: `factor_he_ord` = 1.50, `factor_he_especial` = 3.00 (autorizado por Gabriel el 17/10/2026).
- Tolerancia de detección: 15 minutos (0.25 h). Tope diario legal: 12 h (solo aviso). Descanso trabajado: primeras 8 h especiales, el resto extra.
- Horarios: jornada del día = salida − entrada − minutos de almuerzo; máximo 10 h/día y 48 h/semana.
- Cada cambio de esquema se refleja en `database/schema.sql` **y** en `database/migraciones/2026-10-17-horas-extra-por-horario.sql`.
- Git: cada tarea se commitea en `feature/horas-extra-por-horario` (rama desde `develop`); mensajes en español con `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Pruebas: `vendor/bin/phpunit` debe quedar en verde al final de cada tarea.
- Textos de la UI en español con tuteo (como el resto de la app).

## Review Focus

1. **Salida marcada después de medianoche o antes de la entrada** (horas negativas): el clasificador debe devolver 0 horas extra, nunca un número negativo. → prueba en Task 5.
2. **Empleado sin horario asignado (`id_horario = 1`)**: marcar la salida no debe crear solicitudes ni romper; el calendario y las vacaciones siguen usando el domingo. → pruebas en Tasks 4 y 7.
3. **Doble marcado o corrección repetida de la misma fecha**: nunca debe haber dos solicitudes detectadas para el mismo empleado y fecha. → prueba en Task 7.
4. **Aprobar más horas de las detectadas o una solicitud ya resuelta**: debe rechazarse con un mensaje claro. → prueba en Task 10.
5. **Aprobación cuando la nómina del periodo ya está aprobada y no hay periodo abierto siguiente**: error claro, la solicitud sigue pendiente. → prueba en Task 10.

---

### Task 1: Corrección de feriados (Art. 148 vigente)

**Files:**
- Modify: `database/seed.sql` (bloques de feriados 2026 y 2027)
- Create: `database/migraciones/2026-10-17-horas-extra-por-horario.sql`

**Interfaces:**
- Consumes: nada.
- Produces: catálogo de feriados correcto (lo usan `DiasHabiles`, `CalendarioAsistencia` y `ClasificadorHoras`).

- [ ] **Step 1: Corregir `seed.sql`**

En el bloque `-- ── Feriados 2026 (Costa Rica)`:
- Reemplazar el comentario por:
```sql
-- Tipos según el Art. 148 del Código de Trabajo (texto vigente, Ley 10050 de 2021):
-- 2 ago, 31 ago y 1 dic son de pago no obligatorio; el resto, obligatorio.
-- El 12 de octubre dejó de ser feriado (Ley 9803 de 2020). Semana Santa 2026: Pascua el 5 de abril.
```
- Cambiar `('2026-04-09', 'Jueves Santo', ...)` por `('2026-04-02', 'Jueves Santo', ...)` y `('2026-04-10', 'Viernes Santo', ...)` por `('2026-04-03', 'Viernes Santo', ...)`.
- Borrar la fila `('2026-10-12', 'Día de las Culturas', 'no_obligatorio_pago'),`.

En el bloque `-- ── Feriados 2027`:
- Reemplazar el comentario por el mismo texto, con "Semana Santa 2027: Pascua el 28 de marzo".
- Borrar la fila `('2027-10-12', 'Día de las Culturas', 'no_obligatorio_pago'),`.

- [ ] **Step 2: Crear el script de migración con la sección de feriados**

```sql
-- Migración 2026-10-17: horas extra detectadas por horario.
-- Aplicar sobre una base existente (la que ya tiene schema.sql + seed.sql + demo.php).
-- Cada sección corresponde a una tarea del plan; se agregan en orden.

-- ── Task 1: feriados (Art. 148 vigente) ────────────────────────
UPDATE asistencia a JOIN feriados f ON f.id_feriado = a.id_feriado
   SET a.id_feriado = 1
 WHERE f.fecha IN ('2026-10-12', '2027-10-12', '2026-04-09', '2026-04-10');
DELETE FROM feriados WHERE fecha IN ('2026-10-12', '2027-10-12');
UPDATE feriados SET fecha = '2026-04-02' WHERE fecha = '2026-04-09' AND nombre = 'Jueves Santo';
UPDATE feriados SET fecha = '2026-04-03' WHERE fecha = '2026-04-10' AND nombre = 'Viernes Santo';
```

- [ ] **Step 3: Aplicar a la base local y verificar**

Run: `"/c/xampp/mysql/bin/mysql.exe" -u root lubrimotos_nomina < database/migraciones/2026-10-17-horas-extra-por-horario.sql && "/c/xampp/mysql/bin/mysql.exe" -u root lubrimotos_nomina -N -e "SELECT fecha, nombre FROM feriados WHERE fecha LIKE '%-04-0%' OR fecha LIKE '%-10-12'"`
Expected: solo `2026-04-02 Jueves Santo` y `2026-04-03 Viernes Santo`; ninguna fila del 12 de octubre.

- [ ] **Step 4: Correr la suite**

Run: `vendor/bin/phpunit`
Expected: OK (ninguna prueba depende de estas fechas).

- [ ] **Step 5: Commit**

```bash
git add database/seed.sql database/migraciones/2026-10-17-horas-extra-por-horario.sql
git commit -m "fix(datos): 12 de octubre no es feriado y Semana Santa 2026 el 2-3 de abril

Art. 148 vigente (Ley 10050 de 2021); el 12 de octubre lo eliminó la
Ley 9803 de 2020. Agrega el script de migración para bases existentes.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Esquema de Horarios + HorariosRepository + HorariosService

**Files:**
- Modify: `database/schema.sql` (tabla `horarios` y nueva `horario_dias`)
- Modify: `database/migraciones/2026-10-17-horas-extra-por-horario.sql`
- Create: `src/Repositories/HorariosRepository.php`
- Create: `src/Services/HorariosService.php`
- Modify: `config/dependencies.php`
- Test: `tests/Services/HorariosServiceTest.php`

**Interfaces:**
- Consumes: `AuditoriaRepository::insert(string $accion, int $idUsuario, string $tabla, int $idRegistro, string $ip)`.
- Produces:
  - `HorariosRepository::findAll(): array` (filas `id_horario, nombre, dias_semana`, sin el centinela)
  - `HorariosRepository::findById(int $id): ?array`
  - `HorariosRepository::findDias(int $idHorario): array` (filas `dia_semana, hora_entrada, hora_salida, minutos_almuerzo`, ordenadas por día)
  - `HorariosRepository::findDiasPorEmpleado(int $idEmpleado): array` (mismas columnas, del horario asignado al empleado)
  - `HorariosRepository::existsByNombre(string $nombre, ?int $excludeId = null): bool`
  - `HorariosRepository::insert(string $nombre, string $diasSemana, array $dias): int`
  - `HorariosRepository::update(int $id, string $nombre, string $diasSemana, array $dias): void`
  - `HorariosRepository::delete(int $id): void`
  - `HorariosRepository::hasEmpleados(int $id): bool`
  - `HorariosService::listar(): array` (cada fila con clave extra `resumen`, ej. `"L–V 08:00–17:00 · S 08:00–12:00"`)
  - `HorariosService::obtener(int $id): array` (con clave `dias`: `array<int dia, array{entrada:string, salida:string, almuerzo:int}>`)
  - `HorariosService::crear(array $datos, int $idUsuario, string $ip): int`
  - `HorariosService::actualizar(int $id, array $datos, int $idUsuario, string $ip): void`
  - `HorariosService::eliminar(int $id, int $idUsuario, string $ip): void`
  - `HorariosService::horarioDelDia(int $idEmpleado, string $fecha): ?array` → `array{entrada:string 'H:i:s', salida:string 'H:i:s'}` o `null` si ese día no trabaja
  - `HorariosService::tieneHorario(int $idEmpleado): bool`
  - `HorariosService::diasDescanso(int $idEmpleado): int[]` (ISO 1–7; `[7]` si no tiene horario)
  - Formato de `$datos` del formulario: `['nombre' => string, 'dias' => [1 => ['trabaja' => '1', 'entrada' => '08:00', 'salida' => '17:00', 'almuerzo' => '60'], ...]]`

- [ ] **Step 1: Esquema en `schema.sql`**

Reemplazar la definición de `horarios` (grupo 7) y su INSERT centinela por:

```sql
CREATE TABLE IF NOT EXISTS `horarios` (
    `id_horario`   INT          NOT NULL AUTO_INCREMENT,
    `nombre`       VARCHAR(100) NOT NULL,
    `dias_semana`  VARCHAR(50)  NOT NULL COMMENT 'Resumen descriptivo, ej. L-V + S medio día',
    PRIMARY KEY (`id_horario`),
    UNIQUE KEY `uq_horarios_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Horario centinela (id=1): "sin horario asignado". No tiene días.
INSERT INTO `horarios` (`id_horario`, `nombre`, `dias_semana`) VALUES
(1, 'Sin asignar', 'N/A');

-- Días trabajados de cada horario. Un día sin fila es día de descanso.
CREATE TABLE IF NOT EXISTS `horario_dias` (
    `id_horario_dia`   INT      NOT NULL AUTO_INCREMENT,
    `id_horario`       INT      NOT NULL,
    `dia_semana`       TINYINT  NOT NULL COMMENT '1 = lunes ... 7 = domingo (ISO-8601)',
    `hora_entrada`     TIME     NOT NULL,
    `hora_salida`      TIME     NOT NULL,
    `minutos_almuerzo` SMALLINT NOT NULL DEFAULT 0 COMMENT 'Pausa no remunerada; no es jornada',
    PRIMARY KEY (`id_horario_dia`),
    UNIQUE KEY `uq_horario_dia` (`id_horario`, `dia_semana`),
    CONSTRAINT `fk_horario_dias_horario` FOREIGN KEY (`id_horario`) REFERENCES `horarios` (`id_horario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Sección de migración**

Agregar al final de `database/migraciones/2026-10-17-horas-extra-por-horario.sql`:

```sql
-- ── Task 2: horarios con detalle por día ──────────────────────
CREATE TABLE IF NOT EXISTS `horario_dias` (
    `id_horario_dia`   INT      NOT NULL AUTO_INCREMENT,
    `id_horario`       INT      NOT NULL,
    `dia_semana`       TINYINT  NOT NULL COMMENT '1 = lunes ... 7 = domingo (ISO-8601)',
    `hora_entrada`     TIME     NOT NULL,
    `hora_salida`      TIME     NOT NULL,
    `minutos_almuerzo` SMALLINT NOT NULL DEFAULT 0 COMMENT 'Pausa no remunerada; no es jornada',
    PRIMARY KEY (`id_horario_dia`),
    UNIQUE KEY `uq_horario_dia` (`id_horario`, `dia_semana`),
    CONSTRAINT `fk_horario_dias_horario` FOREIGN KEY (`id_horario`) REFERENCES `horarios` (`id_horario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Horario de Lubrimotos: L–V 08:00–17:00 (60 min almuerzo) y S 08:00–12:00.
INSERT INTO horarios (nombre, dias_semana)
SELECT 'Taller L-V y sábado medio día', 'L-V + S medio día'
 WHERE NOT EXISTS (SELECT 1 FROM horarios WHERE nombre = 'Taller L-V y sábado medio día');
SET @h := (SELECT id_horario FROM horarios WHERE nombre = 'Taller L-V y sábado medio día');
INSERT IGNORE INTO horario_dias (id_horario, dia_semana, hora_entrada, hora_salida, minutos_almuerzo) VALUES
(@h, 1, '08:00:00', '17:00:00', 60), (@h, 2, '08:00:00', '17:00:00', 60), (@h, 3, '08:00:00', '17:00:00', 60),
(@h, 4, '08:00:00', '17:00:00', 60), (@h, 5, '08:00:00', '17:00:00', 60), (@h, 6, '08:00:00', '12:00:00', 0);
UPDATE empleados SET id_horario = @h;
DELETE FROM horarios WHERE id_horario NOT IN (1, @h) AND id_horario NOT IN (SELECT id_horario FROM empleados);
ALTER TABLE horarios DROP COLUMN hora_entrada, DROP COLUMN hora_salida;
```

- [ ] **Step 3: Escribir las pruebas que fallan**

`tests/Services/HorariosServiceTest.php`:

```php
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
        self::assertSame(['entrada' => '08:00:00', 'salida' => '12:00:00'], $this->service()->horarioDelDia(5, '2026-10-17'));
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
```

- [ ] **Step 4: Correr y verificar que falla**

Run: `vendor/bin/phpunit tests/Services/HorariosServiceTest.php`
Expected: ERROR `Class "App\Repositories\HorariosRepository" not found`.

- [ ] **Step 5: Implementar `HorariosRepository`**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * HorariosRepository — SQL de `horarios` y su detalle `horario_dias`. Sin lógica de negocio.
 */
class HorariosRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** Horarios sin el centinela. @return array<int, array<string, mixed>> */
    public function findAll(): array
    {
        return $this->pdo->query(
            'SELECT id_horario, nombre, dias_semana FROM horarios WHERE id_horario <> 1 ORDER BY nombre'
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id_horario, nombre, dias_semana FROM horarios WHERE id_horario = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function findDias(int $idHorario): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT dia_semana, hora_entrada, hora_salida, minutos_almuerzo
               FROM horario_dias WHERE id_horario = :h ORDER BY dia_semana'
        );
        $stmt->execute([':h' => $idHorario]);
        return $stmt->fetchAll();
    }

    /** Días del horario asignado al empleado. @return array<int, array<string, mixed>> */
    public function findDiasPorEmpleado(int $idEmpleado): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.dia_semana, d.hora_entrada, d.hora_salida, d.minutos_almuerzo
               FROM empleados e
               JOIN horario_dias d ON d.id_horario = e.id_horario
              WHERE e.id_empleado = :e
              ORDER BY d.dia_semana'
        );
        $stmt->execute([':e' => $idEmpleado]);
        return $stmt->fetchAll();
    }

    public function existsByNombre(string $nombre, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM horarios WHERE nombre = :n';
        $params = [':n' => $nombre];
        if ($excludeId !== null) {
            $sql .= ' AND id_horario <> :id';
            $params[':id'] = $excludeId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /** @param array<int, array<string, mixed>> $dias filas para horario_dias */
    public function insert(string $nombre, string $diasSemana, array $dias): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('INSERT INTO horarios (nombre, dias_semana) VALUES (:n, :d)');
            $stmt->execute([':n' => $nombre, ':d' => $diasSemana]);
            $id = (int)$this->pdo->lastInsertId();
            $this->insertDias($id, $dias);
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<int, array<string, mixed>> $dias */
    public function update(int $id, string $nombre, string $diasSemana, array $dias): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('UPDATE horarios SET nombre = :n, dias_semana = :d WHERE id_horario = :id');
            $stmt->execute([':n' => $nombre, ':d' => $diasSemana, ':id' => $id]);
            $this->pdo->prepare('DELETE FROM horario_dias WHERE id_horario = :id')->execute([':id' => $id]);
            $this->insertDias($id, $dias);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM horario_dias WHERE id_horario = :id')->execute([':id' => $id]);
            $this->pdo->prepare('DELETE FROM horarios WHERE id_horario = :id')->execute([':id' => $id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function hasEmpleados(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM empleados WHERE id_horario = :id');
        $stmt->execute([':id' => $id]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /** @param array<int, array<string, mixed>> $dias */
    private function insertDias(int $idHorario, array $dias): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO horario_dias (id_horario, dia_semana, hora_entrada, hora_salida, minutos_almuerzo)
             VALUES (:h, :d, :e, :s, :a)'
        );
        foreach ($dias as $d) {
            $stmt->execute([
                ':h' => $idHorario,
                ':d' => $d['dia_semana'],
                ':e' => $d['hora_entrada'],
                ':s' => $d['hora_salida'],
                ':a' => $d['minutos_almuerzo'],
            ]);
        }
    }
}
```

- [ ] **Step 6: Implementar `HorariosService`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\HorariosRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * HorariosService — horarios de trabajo por día de la semana.
 *
 * Reglas (Código de Trabajo, Art. 136):
 *  - jornada del día = salida − entrada − minutos de almuerzo; máximo 10 h (jornada acumulativa);
 *  - suma semanal de jornadas máxima 48 h;
 *  - un día sin horario es día de descanso.
 */
class HorariosService
{
    private const NOMBRES = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
    private const LETRAS  = [1 => 'L', 2 => 'K', 3 => 'M', 4 => 'J', 5 => 'V', 6 => 'S', 7 => 'D'];
    private const MAX_HORAS_DIA    = 10.0;
    private const MAX_HORAS_SEMANA = 48.0;
    private const MAX_ALMUERZO     = 120;

    public function __construct(
        private readonly HorariosRepository  $repo,
        private readonly AuditoriaRepository $auditoriaRepo
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function listar(): array
    {
        return array_map(
            fn (array $h): array => $h + ['resumen' => $this->resumen($this->repo->findDias((int)$h['id_horario']))],
            $this->repo->findAll()
        );
    }

    /** @return array<string, mixed> */
    public function obtener(int $id): array
    {
        $h = $id !== 1 ? $this->repo->findById($id) : null;
        if ($h === null) {
            throw new RuntimeException('Horario no encontrado.');
        }
        $dias = [];
        foreach ($this->repo->findDias($id) as $d) {
            $dias[(int)$d['dia_semana']] = [
                'entrada'  => substr((string)$d['hora_entrada'], 0, 5),
                'salida'   => substr((string)$d['hora_salida'], 0, 5),
                'almuerzo' => (int)$d['minutos_almuerzo'],
            ];
        }
        return $h + ['dias' => $dias];
    }

    /** Opciones para la ficha del empleado (incluye el centinela "Sin asignar"). @return array<int, array<string, mixed>> */
    public function opciones(): array
    {
        return array_merge([['id_horario' => 1, 'nombre' => 'Sin asignar', 'resumen' => '']], $this->listar());
    }

    public function crear(array $datos, int $idUsuario, string $ip): int
    {
        [$nombre, $dias] = $this->validar($datos, null);
        $id = $this->repo->insert($nombre, $this->resumen($dias), $dias);
        $this->auditoriaRepo->insert('INSERT', $idUsuario, 'horarios', $id, $ip);
        return $id;
    }

    public function actualizar(int $id, array $datos, int $idUsuario, string $ip): void
    {
        $this->obtener($id);
        [$nombre, $dias] = $this->validar($datos, $id);
        $this->repo->update($id, $nombre, $this->resumen($dias), $dias);
        $this->auditoriaRepo->insert('UPDATE', $idUsuario, 'horarios', $id, $ip);
    }

    public function eliminar(int $id, int $idUsuario, string $ip): void
    {
        $this->obtener($id);
        if ($this->repo->hasEmpleados($id)) {
            throw new RuntimeException('No se puede eliminar: hay empleados con este horario.');
        }
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $idUsuario, 'horarios', $id, $ip);
    }

    /** @return array{entrada: string, salida: string}|null null si ese día no trabaja */
    public function horarioDelDia(int $idEmpleado, string $fecha): ?array
    {
        $dia = (int)(new DateTimeImmutable($fecha))->format('N');
        foreach ($this->repo->findDiasPorEmpleado($idEmpleado) as $d) {
            if ((int)$d['dia_semana'] === $dia) {
                return ['entrada' => (string)$d['hora_entrada'], 'salida' => (string)$d['hora_salida']];
            }
        }
        return null;
    }

    public function tieneHorario(int $idEmpleado): bool
    {
        return $this->repo->findDiasPorEmpleado($idEmpleado) !== [];
    }

    /** Días ISO (1–7) de descanso. Sin horario asignado: solo el domingo. @return int[] */
    public function diasDescanso(int $idEmpleado): array
    {
        $trabajados = array_map(static fn ($d) => (int)$d['dia_semana'], $this->repo->findDiasPorEmpleado($idEmpleado));
        if ($trabajados === []) {
            return [7];
        }
        return array_values(array_diff(range(1, 7), $trabajados));
    }

    /**
     * @param array<string, mixed> $datos
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function validar(array $datos, ?int $excludeId): array
    {
        $nombre = trim((string)($datos['nombre'] ?? ''));
        if ($nombre === '' || mb_strlen($nombre) > 100) {
            throw new InvalidArgumentException('El nombre es obligatorio (máximo 100 caracteres).');
        }
        if ($this->repo->existsByNombre($nombre, $excludeId)) {
            throw new InvalidArgumentException("Ya existe un horario con el nombre \"{$nombre}\".");
        }

        $filas   = [];
        $semana  = 0.0;
        $entrada = (array)($datos['dias'] ?? []);
        for ($d = 1; $d <= 7; $d++) {
            $f = (array)($entrada[$d] ?? []);
            if (($f['trabaja'] ?? '0') !== '1') {
                continue;
            }
            $e = $this->hora((string)($f['entrada'] ?? ''));
            $s = $this->hora((string)($f['salida'] ?? ''));
            if ($e === null || $s === null || $s <= $e) {
                throw new InvalidArgumentException(self::NOMBRES[$d] . ': la salida debe ser posterior a la entrada (HH:MM).');
            }
            $almuerzo = is_numeric($f['almuerzo'] ?? '') ? (int)$f['almuerzo'] : 0;
            if ($almuerzo < 0 || $almuerzo > self::MAX_ALMUERZO || $almuerzo >= ($s - $e)) {
                throw new InvalidArgumentException(self::NOMBRES[$d] . ': el almuerzo debe estar entre 0 y 120 minutos y ser menor que la jornada.');
            }
            $horas = ($s - $e - $almuerzo) / 60;
            if ($horas > self::MAX_HORAS_DIA) {
                throw new InvalidArgumentException(self::NOMBRES[$d] . ': la jornada no puede pasar de 10 h (Art. 136).');
            }
            $semana += $horas;
            $filas[] = [
                'dia_semana'       => $d,
                'hora_entrada'     => sprintf('%02d:%02d:00', intdiv($e, 60), $e % 60),
                'hora_salida'      => sprintf('%02d:%02d:00', intdiv($s, 60), $s % 60),
                'minutos_almuerzo' => $almuerzo,
            ];
        }
        if ($filas === []) {
            throw new InvalidArgumentException('El horario debe tener al menos un día trabajado.');
        }
        if ($semana > self::MAX_HORAS_SEMANA) {
            throw new InvalidArgumentException(sprintf('La jornada semanal suma %.1f h y no puede pasar de 48 h (Art. 136).', $semana));
        }
        return [$nombre, $filas];
    }

    /** Minutos desde medianoche de una hora HH:MM o HH:MM:SS; null si es inválida. */
    private function hora(string $valor): ?int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', trim($valor), $m) !== 1) {
            return null;
        }
        return (int)$m[1] * 60 + (int)$m[2];
    }

    /**
     * Resumen legible: agrupa días consecutivos con el mismo horario.
     * @param array<int, array<string, mixed>> $dias
     */
    private function resumen(array $dias): string
    {
        $grupos = [];
        foreach ($dias as $d) {
            $rango = substr((string)$d['hora_entrada'], 0, 5) . '–' . substr((string)$d['hora_salida'], 0, 5);
            $dia   = (int)$d['dia_semana'];
            $ult   = count($grupos) - 1;
            if ($ult >= 0 && $grupos[$ult]['rango'] === $rango && $grupos[$ult]['hasta'] === $dia - 1) {
                $grupos[$ult]['hasta'] = $dia;
            } else {
                $grupos[] = ['desde' => $dia, 'hasta' => $dia, 'rango' => $rango];
            }
        }
        return implode(' · ', array_map(
            static fn ($g) => self::LETRAS[$g['desde']] . ($g['hasta'] !== $g['desde'] ? '–' . self::LETRAS[$g['hasta']] : '') . ' ' . $g['rango'],
            $grupos
        ));
    }
}
```

- [ ] **Step 7: Registrar en `config/dependencies.php`**

Junto a `PuestosRepository` y `PuestosService`:

```php
    \App\Repositories\HorariosRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\HorariosRepository($c->get(PDO::class));
    },
```

```php
    \App\Services\HorariosService::class => function (ContainerInterface $c) {
        return new \App\Services\HorariosService(
            $c->get(\App\Repositories\HorariosRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },
```

- [ ] **Step 8: Correr las pruebas**

Run: `vendor/bin/phpunit tests/Services/HorariosServiceTest.php` → Expected: OK (13 tests).
Run: `vendor/bin/phpunit` → Expected: OK.

- [ ] **Step 9: Aplicar la migración (solo la sección Task 2) y commit**

Run: copiar la sección "Task 2" del script a un archivo temporal en el scratchpad y ejecutarla con `mysql -u root lubrimotos_nomina < archivo`; luego `mysql ... -e "SELECT * FROM horario_dias"` → 6 filas.

```bash
git add database/schema.sql database/migraciones/2026-10-17-horas-extra-por-horario.sql src/Repositories/HorariosRepository.php src/Services/HorariosService.php config/dependencies.php tests/Services/HorariosServiceTest.php
git commit -m "feat(horarios): horario por día de la semana con almuerzo y topes del Art. 136

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Pantalla de Horarios y asignación en la ficha del empleado

**Files:**
- Create: `src/Controllers/HorariosController.php`
- Create: `templates/horarios/index.html.twig`, `templates/horarios/form.html.twig`
- Modify: `config/routes.php` (grupo `/mantenimientos`), `config/dependencies.php`, `templates/layouts/base.html.twig` (menú Administración)
- Modify: `src/Services/EmpleadosService.php` (`datosFormulario` y `validar`: `id_horario` real en vez de `1`), `templates/empleados/form.html.twig`
- Modify: `config/dependencies.php` (EmpleadosService recibe `HorariosRepository`)
- Test: `tests/Services/EmpleadosServicePersonaMigrationTest.php` (ajustar constructor)

**Interfaces:**
- Consumes: `HorariosService::{listar, obtener, crear, actualizar, eliminar, opciones}` (Task 2), `HorariosRepository::findById` (Task 2).
- Produces: rutas `horarios.index|create|store|edit|update|destroy`; `EmpleadosService::datosFormulario()` devuelve además `'horarios' => HorariosService::opciones()`-equivalente.

- [ ] **Step 1: Controller** (patrón exacto de `PuestosController`)

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\HorariosService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

/**
 * HorariosController — solo HTTP; la lógica está en HorariosService.
 * Rutas bajo /mantenimientos/horarios (super_admin).
 */
class HorariosController
{
    public function __construct(
        private readonly Twig            $twig,
        private readonly HorariosService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'horarios/index.html.twig', [
            'titulo'       => 'Horarios',
            'horarios'     => $this->service->listar(),
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->form($response, 'crear', ['dias' => []], []);
    }

    public function store(Request $request, Response $response): Response
    {
        $datos = (array)$request->getParsedBody();
        try {
            $this->service->crear($datos, $this->usuarioId($request), $this->ip());
            $_SESSION['flash_success'] = 'Horario creado.';
            return $this->redirect($request, $response, 'horarios.index');
        } catch (InvalidArgumentException $e) {
            return $this->form($response->withStatus(422), 'crear', $datos, [$e->getMessage()]);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            return $this->form($response, 'editar', $this->service->obtener((int)$args['id']), []);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $this->redirect($request, $response, 'horarios.index');
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id    = (int)$args['id'];
        $datos = (array)$request->getParsedBody();
        try {
            $this->service->actualizar($id, $datos, $this->usuarioId($request), $this->ip());
            $_SESSION['flash_success'] = 'Horario actualizado.';
            return $this->redirect($request, $response, 'horarios.index');
        } catch (InvalidArgumentException $e) {
            return $this->form($response->withStatus(422), 'editar', ['id_horario' => $id] + $datos, [$e->getMessage()]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $this->redirect($request, $response, 'horarios.index');
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        try {
            $this->service->eliminar((int)$args['id'], $this->usuarioId($request), $this->ip());
            $_SESSION['flash_success'] = 'Horario eliminado.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $this->redirect($request, $response, 'horarios.index');
    }

    /** @param array<string, mixed> $horario @param string[] $errores */
    private function form(Response $response, string $accion, array $horario, array $errores): Response
    {
        return $this->twig->render($response, 'horarios/form.html.twig', [
            'titulo'  => $accion === 'crear' ? 'Nuevo horario' : 'Editar horario',
            'accion'  => $accion,
            'horario' => $horario,
            'errores' => $errores,
            'nombresDias' => [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'],
        ]);
    }

    private function redirect(Request $request, Response $response, string $routeName): Response
    {
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor($routeName);
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

- [ ] **Step 2: Plantillas**

`templates/horarios/index.html.twig`:

```twig
{% extends 'layouts/base.html.twig' %}
{% import 'partials/ui.html.twig' as ui %}

{% block titulo %}Horarios{% endblock %}

{% block encabezado %}
{{ ui.encabezado_pagina('Horarios', {
    ruta: [{texto: 'Administración'}, {texto: 'Horarios'}],
    conteo: horarios|length,
    acciones: [{texto: 'Nuevo horario', icono: 'plus-lg', url: url_for('horarios.create')}]
}) }}
{% endblock %}

{% block contenido %}
{% if horarios is empty %}
<div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>No hay horarios. Crea el primero con <strong>Nuevo horario</strong>.</div>
{% else %}
<div class="lb-tabla">
    <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead><tr><th>Nombre</th><th>Días y horas</th><th class="text-center">Acciones</th></tr></thead>
        <tbody>
        {% for h in horarios %}
            <tr>
                <td class="fw-semibold">{{ h.nombre }}</td>
                <td>{{ h.resumen }}</td>
                <td class="text-center">
                    <a href="{{ url_for('horarios.edit', {id: h.id_horario}) }}" class="btn btn-sm btn-outline-primary me-1" title="Editar"><i class="bi bi-pencil-square"></i></a>
                    <form method="POST" action="{{ url_for('horarios.destroy', {id: h.id_horario}) }}" class="d-inline"
                          onsubmit="return confirm('¿Eliminar el horario {{ h.nombre|e('js') }}?');">
                        <button class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="bi bi-trash3"></i></button>
                    </form>
                </td>
            </tr>
        {% endfor %}
        </tbody>
    </table>
    </div>
    <div class="lb-tabla__pie">{{ horarios|length }} {{ horarios|length == 1 ? 'horario' : 'horarios' }}</div>
</div>
{% endif %}
{% endblock %}
```

`templates/horarios/form.html.twig`:

```twig
{% extends 'layouts/base.html.twig' %}
{% import 'partials/ui.html.twig' as ui %}

{% block titulo %}{{ titulo }}{% endblock %}

{% block encabezado %}
{{ ui.encabezado_pagina(titulo, {
    ruta: [{texto: 'Administración'}, {texto: 'Horarios', url: url_for('horarios.index')}, {texto: accion == 'crear' ? 'Nuevo' : 'Editar'}],
    acciones: [
        {texto: 'Cancelar', tipo: 'secundario', url: url_for('horarios.index')},
        {texto: 'Guardar horario', icono: 'floppy', form: 'formHorario'}
    ]
}) }}
{% endblock %}

{% block contenido %}
<div class="row justify-content-center"><div class="col-lg-9">
    {% if errores is not empty %}
    <div class="alert alert-danger"><ul class="mb-0">{% for e in errores %}<li>{{ e }}</li>{% endfor %}</ul></div>
    {% endif %}

    <form method="POST" id="formHorario"
          action="{{ accion == 'crear' ? url_for('horarios.store') : url_for('horarios.update', {id: horario.id_horario}) }}">
        {% embed 'partials/seccion.html.twig' with {titulo: 'Datos del horario'} %}
        {% block cuerpo %}
            <div class="mb-3">
                <label for="nombre" class="form-label fw-semibold">Nombre <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="nombre" name="nombre" maxlength="100" required
                       value="{{ horario.nombre ?? '' }}" placeholder="Ej: Taller L-V y sábado medio día">
            </div>
            <p class="small text-muted mb-2">Un día sin marcar es día de descanso. El almuerzo no cuenta como jornada (máx. 10 h por día y 48 h por semana, Art. 136).</p>
            <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Día</th><th>Trabaja</th><th>Entrada</th><th>Salida</th><th>Almuerzo (min)</th></tr></thead>
                <tbody>
                {% for n, nombreDia in nombresDias %}
                    {% set d = horario.dias[n] ?? null %}
                    {% set trabaja = d is not null and ((d.trabaja ?? '1') == '1') %}
                    <tr>
                        <td class="fw-semibold">{{ nombreDia }}</td>
                        <td><input type="checkbox" class="form-check-input" name="dias[{{ n }}][trabaja]" value="1" {{ trabaja ? 'checked' : '' }}></td>
                        <td><input type="time" class="form-control form-control-sm" name="dias[{{ n }}][entrada]" value="{{ d.entrada ?? '' }}"></td>
                        <td><input type="time" class="form-control form-control-sm" name="dias[{{ n }}][salida]" value="{{ d.salida ?? '' }}"></td>
                        <td><input type="number" class="form-control form-control-sm" min="0" max="120" step="5" name="dias[{{ n }}][almuerzo]" value="{{ d.almuerzo ?? 0 }}"></td>
                    </tr>
                {% endfor %}
                </tbody>
            </table>
            </div>
        {% endblock %}
        {% endembed %}
    </form>
</div></div>
{% endblock %}
```

- [ ] **Step 3: Rutas, DI y menú**

`config/routes.php`, dentro del grupo `/mantenimientos`, después de Feriados (y agregar `use App\Controllers\HorariosController;` arriba con los demás `use`):

```php
        // Horarios
        $group->get('/horarios',                [HorariosController::class, 'index'])->setName('horarios.index');
        $group->get('/horarios/crear',          [HorariosController::class, 'create'])->setName('horarios.create');
        $group->post('/horarios/crear',         [HorariosController::class, 'store'])->setName('horarios.store');
        $group->get('/horarios/{id}/editar',    [HorariosController::class, 'edit'])->setName('horarios.edit');
        $group->post('/horarios/{id}/editar',   [HorariosController::class, 'update'])->setName('horarios.update');
        $group->post('/horarios/{id}/eliminar', [HorariosController::class, 'destroy'])->setName('horarios.destroy');
```

`config/dependencies.php`:

```php
    \App\Controllers\HorariosController::class => function (ContainerInterface $c) {
        return new \App\Controllers\HorariosController(
            $c->get(Twig::class),
            $c->get(\App\Services\HorariosService::class)
        );
    },
```

`templates/layouts/base.html.twig`, en el grupo Administración, después de Feriados:

```twig
            <a class="sb-item" href="{{ url_for('horarios.index') }}"><i class="bi bi-clock"></i><span>Horarios</span></a>
```

- [ ] **Step 4: Horario en la ficha del empleado — prueba que falla**

En `tests/Services/EmpleadosServicePersonaMigrationTest.php`:

1. Cambiar el helper `service()` para que reciba y pase el repositorio de horarios como último argumento:

```php
    private function service(EmpleadosRepository $repo, ?DistritosRepository $distritosRepo = null, ?HorariosRepository $horariosRepo = null): EmpleadosService
    {
        $puestosRepo = $this->createMock(PuestosRepository::class);
        $puestosRepo->method('findById')->willReturn(['id_puesto' => 1, 'nombre' => 'Mecánico']);

        return new EmpleadosService(
            $repo,
            $puestosRepo,
            $distritosRepo ?? $this->createMock(DistritosRepository::class),
            $this->createMock(AuditoriaRepository::class),
            $horariosRepo ?? $this->createMock(HorariosRepository::class)
        );
    }
```

(agregar `use App\Repositories\HorariosRepository;` y `use InvalidArgumentException;` si no está).

2. Pruebas nuevas:

```php
    public function testGuardaElHorarioElegidoEnLaFicha(): void
    {
        $horarios = $this->createMock(HorariosRepository::class);
        $horarios->method('findById')->with(4)->willReturn(['id_horario' => 4, 'nombre' => 'Taller', 'dias_semana' => 'x']);
        $repo = $this->createMock(EmpleadosRepository::class);
        $repo->method('existsByCedula')->willReturn(false);
        $repo->expects(self::once())->method('insert')
            ->with(self::anything(), self::callback(fn (array $e) => $e['id_horario'] === 4), null)
            ->willReturn(42);

        $this->service($repo, null, $horarios)->crear($this->datosValidos(['id_horario' => '4']), 1, '127.0.0.1');
    }

    public function testHorarioInexistenteEsRechazado(): void
    {
        $horarios = $this->createMock(HorariosRepository::class);
        $horarios->method('findById')->willReturn(null);
        $repo = $this->createMock(EmpleadosRepository::class);
        $repo->method('existsByCedula')->willReturn(false);
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('horario');
        $this->service($repo, null, $horarios)->crear($this->datosValidos(['id_horario' => '999']), 1, '127.0.0.1');
    }
```

La prueba existente `testCrearArmaGruposPersonaEmpleadoSeparados` (sin `id_horario` en los datos) debe seguir pasando con `id_horario === 1`.

Run: `vendor/bin/phpunit tests/Services/EmpleadosServicePersonaMigrationTest.php` → Expected: FAIL (id_horario sigue en 1).

- [ ] **Step 5: Implementar en `EmpleadosService`**

- Constructor: agregar `private readonly HorariosRepository $horariosRepo` como último parámetro (y `use App\Repositories\HorariosRepository;`).
- `datosFormulario()`: agregar `'horarios' => $this->horariosRepo->findAll(),`.
- En `validar`, antes de armar `$empleado`:

```php
        $idHorario = isset($d['id_horario']) && is_numeric($d['id_horario']) ? (int)$d['id_horario'] : 1;
        if ($idHorario !== 1 && $this->horariosRepo->findById($idHorario) === null) {
            $errores[] = 'Debe seleccionar un horario válido.';
        }
```

  (Si `validar` lanza los errores acumulados al final, esto entra en ese mismo arreglo; si lanza en el momento, usar `throw new InvalidArgumentException('Debe seleccionar un horario válido.');`.)
- Reemplazar `'id_horario' => 1,` por `'id_horario' => $idHorario,`.
- `config/dependencies.php`: pasar `$c->get(\App\Repositories\HorariosRepository::class)` como último argumento de `EmpleadosService`.

`templates/empleados/form.html.twig`, después del select de Puesto:

```twig
                    <div class="col-md-6">
                        <label for="id_horario" class="form-label">Horario</label>
                        <select class="form-select" id="id_horario" name="id_horario">
                            <option value="1">— Sin asignar (no se detectan horas extra) —</option>
                            {% for h in horarios %}
                            <option value="{{ h.id_horario }}" {{ (empleado.id_horario ?? 1) == h.id_horario ? 'selected' : '' }}>{{ h.nombre }}</option>
                            {% endfor %}
                        </select>
                    </div>
```

Verificar en `EmpleadosController` que `create/edit/store/update` pasan `datosFormulario()` completo a la vista (si pasan claves sueltas, agregar `'horarios' => $datosForm['horarios']`).

- [ ] **Step 6: Correr pruebas y probar en la app**

Run: `vendor/bin/phpunit` → OK.
Manual: entrar como `superadmin` (`Test1234!`) en `http://localhost/lubrimotos/public/mantenimientos/horarios`, ver el horario del taller con resumen `L–V 08:00–17:00 · S 08:00–12:00`, editarlo y guardar sin cambios; intentar quitar el almuerzo del lunes a viernes → error de 48 h. En Empleados, abrir una ficha y ver el horario seleccionado.

- [ ] **Step 7: Commit**

```bash
git add src/Controllers/HorariosController.php templates/horarios config/routes.php config/dependencies.php templates/layouts/base.html.twig src/Services/EmpleadosService.php templates/empleados/form.html.twig src/Controllers/EmpleadosController.php tests/Services/EmpleadosServicePersonaMigrationTest.php
git commit -m "feat(horarios): pantalla de horarios y horario en la ficha del empleado

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Día de descanso del horario en vacaciones y calendario

**Files:**
- Modify: `src/Helpers/DiasHabiles.php`, `src/Services/DiasHabilesService.php`, `src/Services/SaldoVacacionesService.php`, `src/Services/SolicitudesService.php`, `src/Services/VacacionesService.php`
- Modify: `src/Helpers/CalendarioAsistencia.php`, `src/Services/MarcaAsistenciaService.php`
- Modify: `config/dependencies.php`
- Test: `tests/Helpers/DiasHabilesTest.php`, `tests/Helpers/CalendarioAsistenciaTest.php`, `tests/Support/DiasHabilesStub.php`, `tests/Services/MarcaAsistenciaServiceTest.php`

**Interfaces:**
- Consumes: `HorariosService::diasDescanso(int $idEmpleado): int[]` (Task 2).
- Produces:
  - `DiasHabiles::contar(string $inicio, string $fin, array $feriados, array $diasDescanso = [7]): int`
  - `DiasHabilesService::contar(string $inicio, string $fin, ?int $idEmpleado = null): float`
  - `DiasHabilesService::diasDeSolicitud(array $solicitud): float` (usa `$solicitud['id_empleado']` si existe)
  - `CalendarioAsistencia::construir(string $mes, array $registros, array $feriados, DateTimeImmutable $ingreso, DateTimeImmutable $hoy, array $diasDescanso = [7]): array`

- [ ] **Step 1: Pruebas que fallan**

En `tests/Helpers/DiasHabilesTest.php`:

```php
    public function testConDescansoSabadoYDomingoCuentaCincoDias(): void
    {
        // 2026-10-12 (lunes) a 2026-10-18 (domingo), horario L–V
        self::assertSame(5, (new DiasHabiles())->contar('2026-10-12', '2026-10-18', [], [6, 7]));
    }
```

En `tests/Helpers/CalendarioAsistenciaTest.php` (usar el helper `octubre()`/`dias()` existente; si `octubre()` no acepta días de descanso, agregarle un tercer parámetro `array $descanso = [7]` que pase a `construir`):

```php
    public function testElSabadoEsLibreSiElHorarioNoLoIncluye(): void
    {
        $dias = $this->dias($this->octubre([], [], [6, 7]));
        self::assertSame('libre', $dias['2026-10-03']['estado']);   // sábado
        self::assertSame('libre', $dias['2026-10-04']['estado']);   // domingo
    }
```

Run: `vendor/bin/phpunit tests/Helpers` → Expected: FAIL.

- [ ] **Step 2: Implementar en los helpers**

`DiasHabiles::contar` — nueva firma y condición:

```php
    /**
     * @param string[] $feriados     fechas (Y-m-d) feriadas dentro del rango
     * @param int[]    $diasDescanso días ISO (1 = lunes ... 7 = domingo) de descanso del empleado
     */
    public function contar(string $inicio, string $fin, array $feriados, array $diasDescanso = [7]): int
    {
        ...
        $descanso  = array_flip($diasDescanso);
        ...
            if (!isset($descanso[(int)$dia->format('N')]) && !isset($esFeriado[$dia->format('Y-m-d')])) {
```

Actualizar el docblock de la clase: "Días hábiles: los que el horario del empleado trabaja (por defecto lunes a sábado)".

`CalendarioAsistencia::construir` — agregar el parámetro `array $diasDescanso = [7]` al final, pasarlo a `estado()` y en `estado()` cambiar `if ($diaSemana === 7)` por `if (in_array($diaSemana, $diasDescanso, true))`.

- [ ] **Step 3: Implementar en los services**

`DiasHabilesService`:
- Constructor: `private readonly FeriadosRepository $feriadosRepo, private readonly ?HorariosService $horarios = null, private readonly DiasHabiles $diasHabiles = new DiasHabiles()`.
- `contar(string $inicio, string $fin, ?int $idEmpleado = null): float` → `$descanso = ($idEmpleado !== null && $this->horarios !== null) ? $this->horarios->diasDescanso($idEmpleado) : [7];` y pasarlo a `$this->diasHabiles->contar(...)`.
- `diasDeSolicitud`: `return $this->contar($inicio, $fin, isset($solicitud['id_empleado']) ? (int)$solicitud['id_empleado'] : null);`

Llamadores:
- `SaldoVacacionesService::saldo`: `$this->diasHabiles->diasDeSolicitud($s + ['id_empleado' => $idEmpleado])`.
- `SolicitudesService::validar`: las dos llamadas `contar($fechaInicio, $fechaFin)` pasan `$idEmpleado` como tercer argumento.
- `VacacionesService::resolverDias`: agregar parámetro `int $idEmpleado` y pasarlo; en `crear` usar `$idEmpleado`, en `actualizar` usar `(int)$vac['id_empleado']`.
- `MarcaAsistenciaService`: constructor agrega `private readonly ?HorariosService $horarios = null` al final; en `vista()` pasar `$this->horarios?->diasDescanso($idEmpleado) ?? [7]` como último argumento de `construir`.
- `config/dependencies.php`: `DiasHabilesService` recibe `$c->get(\App\Services\HorariosService::class)` como segundo argumento; `MarcaAsistenciaService` lo recibe como último argumento.

- [ ] **Step 4: Ajustar pruebas existentes y correr**

`tests/Support/DiasHabilesStub::servicio` sigue funcionando (los nuevos parámetros son opcionales). Agregar en `tests/Services/SolicitudesServiceDiasSolicitadosTest.php`:

```php
    public function testUsaElDescansoDelHorarioDelEmpleado(): void
    {
        $horarios = $this->createMock(\App\Services\HorariosService::class);
        $horarios->method('diasDescanso')->with(9)->willReturn([6, 7]);
        $feriados = $this->createStub(\App\Repositories\FeriadosRepository::class);
        $feriados->method('findEntre')->willReturn([]);
        $service = new SolicitudesService(
            $this->createMock(SolicitudesRepository::class),
            $this->createMock(EmpleadosRepository::class),
            $this->createMock(AuditoriaRepository::class),
            $this->createMock(SaldoVacacionesService::class),
            new \App\Services\DiasHabilesService($feriados, $horarios)
        );
        // lunes 2026-10-12 a domingo 2026-10-18 con horario L–V → 5
        self::assertSame(5.0, $service->diasSolicitados([
            'tipo' => 'vacaciones', 'id_empleado' => 9, 'fecha_inicio' => '2026-10-12', 'fecha_fin' => '2026-10-18', 'horas' => '0.00',
        ]));
    }
```

Run: `vendor/bin/phpunit` → Expected: OK.

- [ ] **Step 5: Commit**

```bash
git add src/Helpers/DiasHabiles.php src/Helpers/CalendarioAsistencia.php src/Services/DiasHabilesService.php src/Services/SaldoVacacionesService.php src/Services/SolicitudesService.php src/Services/VacacionesService.php src/Services/MarcaAsistenciaService.php config/dependencies.php tests
git commit -m "feat(horarios): vacaciones y calendario usan el día de descanso del horario

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: `ClasificadorHoras` y factor 3.00

**Files:**
- Create: `src/Helpers/ClasificadorHoras.php`
- Modify: `config/settings.php`, `config/dependencies.php`, `src/Services/HorasExtraService.php`, `database/demo.php` (parámetros legales), `database/schema.sql` (comentario de `horas_extra.factor_recargo`), `CLAUDE.md`
- Modify: `database/migraciones/2026-10-17-horas-extra-por-horario.sql`
- Test: `tests/Helpers/ClasificadorHorasTest.php`

**Interfaces:**
- Consumes: nada.
- Produces:
  - `ClasificadorHoras::__construct(float $factorOrdinario, float $factorEspecial)`
  - `ClasificadorHoras::clasificar(string $entrada, string $salida, ?array $horarioDia, bool $esFeriado): array` con claves `tipo_dia` (`'normal'|'feriado'|'descanso'`), `horas_trabajadas`, `horas_ordinarias`, `horas_dia_especial`, `horas_extra`, `factor_extra` (float), `excede_12h` (bool). `$horarioDia` = `['entrada' => 'H:i:s', 'salida' => 'H:i:s']` o `null`.
  - Constantes `ClasificadorHoras::TOLERANCIA_HORAS = 0.25`, `MAX_DIA_ESPECIAL = 8.0`, `MAX_JORNADA = 12.0`.

- [ ] **Step 1: Pruebas que fallan**

`tests/Helpers/ClasificadorHorasTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Helpers\ClasificadorHoras;
use PHPUnit\Framework\TestCase;

final class ClasificadorHorasTest extends TestCase
{
    private const HORARIO = ['entrada' => '08:00:00', 'salida' => '17:00:00'];

    private function c(): ClasificadorHoras
    {
        return new ClasificadorHoras(1.50, 3.00);
    }

    public function testDiaNormalConSalidaTardeEsExtraAUnoYMedio(): void
    {
        $r = $this->c()->clasificar('07:55:00', '18:40:00', self::HORARIO, false);
        self::assertSame('normal', $r['tipo_dia']);
        self::assertSame(1.67, $r['horas_extra']);
        self::assertSame(1.5, $r['factor_extra']);
        self::assertSame(0.0, $r['horas_dia_especial']);
        self::assertSame(9.08, $r['horas_ordinarias']);
    }

    public function testCatorceMinutosNoCuentan(): void
    {
        self::assertSame(0.0, $this->c()->clasificar('08:00:00', '17:14:00', self::HORARIO, false)['horas_extra']);
    }

    public function testQuinceMinutosSiCuentan(): void
    {
        self::assertSame(0.25, $this->c()->clasificar('08:00:00', '17:15:00', self::HORARIO, false)['horas_extra']);
    }

    public function testSalidaExactaNoTieneExtra(): void
    {
        $r = $this->c()->clasificar('08:00:00', '17:00:00', self::HORARIO, false);
        self::assertSame(0.0, $r['horas_extra']);
        self::assertSame(9.0, $r['horas_ordinarias']);
    }

    public function testFeriadoConHorarioPagaEspecialYExtraTriple(): void
    {
        $r = $this->c()->clasificar('08:00:00', '19:00:00', self::HORARIO, true);
        self::assertSame('feriado', $r['tipo_dia']);
        self::assertSame(9.0, $r['horas_dia_especial']);
        self::assertSame(2.0, $r['horas_extra']);
        self::assertSame(3.0, $r['factor_extra']);
    }

    public function testDescansoTrabajadoOchoEspecialesYElRestoExtra(): void
    {
        $r = $this->c()->clasificar('08:00:00', '18:30:00', null, false);
        self::assertSame('descanso', $r['tipo_dia']);
        self::assertSame(8.0, $r['horas_dia_especial']);
        self::assertSame(2.5, $r['horas_extra']);
        self::assertSame(3.0, $r['factor_extra']);
        self::assertSame(0.0, $r['horas_ordinarias']);
    }

    public function testFeriadoEnDiaSinHorarioSeTrataComoDescansoPeroEsFeriado(): void
    {
        $r = $this->c()->clasificar('09:00:00', '13:00:00', null, true);
        self::assertSame('feriado', $r['tipo_dia']);
        self::assertSame(4.0, $r['horas_dia_especial']);
        self::assertSame(0.0, $r['horas_extra']);
    }

    public function testMasDeDoceHorasMarcaElAviso(): void
    {
        self::assertTrue($this->c()->clasificar('06:00:00', '18:30:00', self::HORARIO, false)['excede_12h']);
        self::assertFalse($this->c()->clasificar('08:00:00', '18:00:00', self::HORARIO, false)['excede_12h']);
    }

    public function testSalidaAntesDeLaEntradaNoDaHorasNegativas(): void
    {
        $r = $this->c()->clasificar('08:00:00', '07:00:00', self::HORARIO, false);
        self::assertSame(0.0, $r['horas_extra']);
        self::assertSame(0.0, $r['horas_trabajadas']);
        self::assertSame(0.0, $r['horas_ordinarias']);
    }

    public function testAceptaHorasSinSegundos(): void
    {
        self::assertSame(1.0, $this->c()->clasificar('08:00', '18:00', self::HORARIO, false)['horas_extra']);
    }
}
```

Run: `vendor/bin/phpunit tests/Helpers/ClasificadorHorasTest.php` → Expected: ERROR class not found.

- [ ] **Step 2: Implementar**

```php
<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * ClasificadorHoras — regla única para clasificar las horas de un día trabajado.
 * Función pura: no toca la base de datos ni el reloj.
 *
 * - Día normal: lo que pasa de la salida del horario es extra (×1.50, Art. 139).
 * - Feriado con horario: horas hasta la salida = día especial (adicional sencillo, Art. 149);
 *   lo que pasa de la salida es extra a pago triple (MTSS CP-005-2026).
 * - Descanso o feriado sin horario: primeras 8 h = día especial (Art. 152); el resto extra ×3.00
 *   (en descanso, por analogía con el feriado).
 * - Menos de 15 minutos de extra no cuentan. Más de 12 h en el día se marca (Art. 140).
 */
final class ClasificadorHoras
{
    public const TOLERANCIA_HORAS = 0.25;
    public const MAX_DIA_ESPECIAL = 8.0;
    public const MAX_JORNADA      = 12.0;

    public function __construct(
        private readonly float $factorOrdinario,
        private readonly float $factorEspecial
    ) {}

    /**
     * @param array{entrada: string, salida: string}|null $horarioDia null = ese día no tiene horario
     * @return array{tipo_dia: string, horas_trabajadas: float, horas_ordinarias: float,
     *               horas_dia_especial: float, horas_extra: float, factor_extra: float, excede_12h: bool}
     */
    public function clasificar(string $entrada, string $salida, ?array $horarioDia, bool $esFeriado): array
    {
        $trabajadas = max(0, $this->minutos($salida) - $this->minutos($entrada)) / 60;

        if ($horarioDia !== null) {
            $extra      = $this->tolerancia(max(0, $this->minutos($salida) - $this->minutos($horarioDia['salida'])) / 60);
            $extra      = min($extra, $trabajadas);
            $ordinarias = $trabajadas - $extra;
            $tipo       = $esFeriado ? 'feriado' : 'normal';
            $especial   = $esFeriado ? $ordinarias : 0.0;
            $factor     = $esFeriado ? $this->factorEspecial : $this->factorOrdinario;
        } else {
            $tipo       = $esFeriado ? 'feriado' : 'descanso';
            $especial   = min($trabajadas, self::MAX_DIA_ESPECIAL);
            $extra      = $this->tolerancia(max(0, $trabajadas - self::MAX_DIA_ESPECIAL));
            $ordinarias = 0.0;
            $factor     = $this->factorEspecial;
        }

        return [
            'tipo_dia'           => $tipo,
            'horas_trabajadas'   => round($trabajadas, 2),
            'horas_ordinarias'   => round($ordinarias, 2),
            'horas_dia_especial' => round($especial, 2),
            'horas_extra'        => round($extra, 2),
            'factor_extra'       => $factor,
            'excede_12h'         => $trabajadas > self::MAX_JORNADA,
        ];
    }

    private function tolerancia(float $horas): float
    {
        return $horas < self::TOLERANCIA_HORAS ? 0.0 : $horas;
    }

    /** Minutos desde medianoche de HH:MM o HH:MM:SS (los segundos se ignoran). */
    private function minutos(string $hora): int
    {
        [$h, $m] = array_map('intval', array_slice(explode(':', $hora), 0, 2)) + [0, 0];
        return $h * 60 + $m;
    }
}
```

Run: `vendor/bin/phpunit tests/Helpers/ClasificadorHorasTest.php` → Expected: OK (10 tests).

- [ ] **Step 3: Factor 3.00 en configuración y en `HorasExtraService`**

`config/settings.php`:

```php
        'factor_he_ord'      => 1.50,     // horas extra en día normal (Art. 139)
        'factor_he_especial' => 3.00,     // horas extra en feriado o descanso: pago triple (MTSS CP-005-2026)
```

`config/dependencies.php`:

```php
    \App\Helpers\ClasificadorHoras::class => function (ContainerInterface $c) {
        $cfg = $c->get('settings')['nomina'];
        return new \App\Helpers\ClasificadorHoras((float)$cfg['factor_he_ord'], (float)$cfg['factor_he_especial']);
    },
```

`src/Services/HorasExtraService.php` (registro manual del admin):
- `parametroDelFactor`: `$clave = $factor >= 2.0 ? 'factor_he_especial' : 'factor_he_ord';` y docblock "(1.50 ordinario / 3.00 feriado o descanso)".
- `resolverFactor`: aceptar `1.5` → `1.50` y `3.0` → `3.00`; mensaje `'El factor de recargo debe ser 1.50 o 3.00.'`; por defecto `return $this->repo->esFeriado($fecha) ? 3.00 : 1.50;`.
- Revisar `templates/horas_extra/form.html.twig`: si ofrece la opción `2.00`, cambiarla por `3.00` con texto "3.00 (feriado o descanso: pago triple)".

`database/schema.sql`: comentario de `horas_extra.factor_recargo` → `'1.50 día normal / 3.00 feriado o descanso (pago triple)'`.

`database/demo.php` línea de parámetros legales: `'factor_he_feri' => 2.00` → `'factor_he_especial' => 3.00`.

Migración (agregar sección):

```sql
-- ── Task 5: factor de horas extra en feriado/descanso = 3.00 ──
UPDATE parametros_legales SET clave = 'factor_he_especial', valor = 3.0000 WHERE clave = 'factor_he_feri';
```

`CLAUDE.md`, bloque "Reglas legales de Costa Rica": reemplazar la línea `'factor_he_feri' => 2.00,` por

```php
'factor_he_especial' => 3.00, // horas extra en feriado o día de descanso: pago triple
                              // (MTSS CP-005-2026; autorizado por Gabriel el 17/10/2026)
```

- [ ] **Step 4: Pruebas de `HorasExtraService` existentes**

Run: `grep -rn "2.00\|factor_he_feri" tests src templates` y actualizar a 3.00 / `factor_he_especial` donde corresponda (por ejemplo en `tests/Services/ValoresSinNuloTest.php` si verifica la clave del parámetro).
Run: `vendor/bin/phpunit` → Expected: OK.

- [ ] **Step 5: Commit**

```bash
git add src/Helpers/ClasificadorHoras.php tests/Helpers/ClasificadorHorasTest.php config/settings.php config/dependencies.php src/Services/HorasExtraService.php templates/horas_extra database/schema.sql database/demo.php database/migraciones/2026-10-17-horas-extra-por-horario.sql CLAUDE.md tests
git commit -m "feat(horas-extra): ClasificadorHoras y factor 3.00 en feriado o descanso

Horas extra en feriado se pagan triple (MTSS CP-005-2026). La regla
de tipo de día, tolerancia de 15 min y tope de 12 h vive en un helper puro.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Esquema de Solicitudes (`por_justificar`, `origen`) y métodos del Repository

**Files:**
- Modify: `database/schema.sql` (tabla `solicitudes`), `database/migraciones/2026-10-17-horas-extra-por-horario.sql`
- Modify: `src/Repositories/SolicitudesRepository.php`

**Interfaces:**
- Consumes: nada.
- Produces (en `SolicitudesRepository`):
  - `findAll(?string $tipo = null, ?string $estado = null, ?string $q = null, ?int $idEmpleado = null, ?string $origen = null): array` (ahora selecciona también `s.origen`)
  - `insertDetectada(int $idEmpleado, string $fecha, float $horas, string $motivo, string $estado): int`
  - `findDetectada(int $idEmpleado, string $fecha): ?array` (origen asistencia, tipo horas_extra, estado ≠ rechazada)
  - `actualizarHoras(int $id, float $horas): void`
  - `justificar(int $id, string $motivo): void` (estado → pendiente)
  - `findPorJustificarEntre(string $desde, string $hasta): array` (filas `id_solicitud`)

- [ ] **Step 1: Esquema**

En `schema.sql`, tabla `solicitudes`:

```sql
    `estado`             ENUM('pendiente','aprobada','rechazada','por_justificar') NOT NULL DEFAULT 'pendiente',
```

y después de `observacion_admin`:

```sql
    `origen` ENUM('empleado','asistencia') NOT NULL DEFAULT 'empleado'
        COMMENT 'asistencia = horas extra detectadas por la salida marcada',
```

Migración:

```sql
-- ── Task 6: solicitudes detectadas por asistencia ─────────────
ALTER TABLE solicitudes
    MODIFY `estado` ENUM('pendiente','aprobada','rechazada','por_justificar') NOT NULL DEFAULT 'pendiente',
    ADD COLUMN `origen` ENUM('empleado','asistencia') NOT NULL DEFAULT 'empleado'
        COMMENT 'asistencia = horas extra detectadas por la salida marcada' AFTER `observacion_admin`;
```

- [ ] **Step 2: Métodos del Repository**

En `findAll`: agregar el parámetro `?string $origen = null`, `s.origen` al SELECT y

```php
        if ($origen !== null) {
            $where[] = 's.origen = :origen';
            $params[':origen'] = $origen;
        }
```

Métodos nuevos:

```php
    /** Solicitud de horas extra generada por la asistencia. */
    public function insertDetectada(int $idEmpleado, string $fecha, float $horas, string $motivo, string $estado): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO solicitudes (id_empleado, tipo, fecha_inicio, fecha_fin, horas, motivo, estado, origen)
             VALUES (:e, 'horas_extra', :f, :f2, :h, :m, :s, 'asistencia')"
        );
        $stmt->execute([':e' => $idEmpleado, ':f' => $fecha, ':f2' => $fecha, ':h' => $horas, ':m' => $motivo, ':s' => $estado]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findDetectada(int $idEmpleado, string $fecha): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM solicitudes
              WHERE id_empleado = :e AND fecha_inicio = :f AND tipo = 'horas_extra'
                AND origen = 'asistencia' AND estado <> 'rechazada'
              LIMIT 1"
        );
        $stmt->execute([':e' => $idEmpleado, ':f' => $fecha]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function actualizarHoras(int $id, float $horas): void
    {
        $this->pdo->prepare('UPDATE solicitudes SET horas = :h WHERE id_solicitud = :id')
            ->execute([':h' => $horas, ':id' => $id]);
    }

    public function justificar(int $id, string $motivo): void
    {
        $this->pdo->prepare("UPDATE solicitudes SET motivo = :m, estado = 'pendiente' WHERE id_solicitud = :id")
            ->execute([':m' => $motivo, ':id' => $id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function findPorJustificarEntre(string $desde, string $hasta): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id_solicitud FROM solicitudes
              WHERE estado = 'por_justificar' AND fecha_inicio BETWEEN :d AND :h"
        );
        $stmt->execute([':d' => $desde, ':h' => $hasta]);
        return $stmt->fetchAll();
    }
```

- [ ] **Step 3: Aplicar la migración (sección Task 6), correr la suite y commit**

Run: ejecutar la sección en la base local; `vendor/bin/phpunit` → OK.

```bash
git add database/schema.sql database/migraciones/2026-10-17-horas-extra-por-horario.sql src/Repositories/SolicitudesRepository.php
git commit -m "feat(solicitudes): estado por_justificar y origen asistencia

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: `DeteccionHorasExtraService` y su uso al marcar, corregir y editar asistencia

**Files:**
- Create: `src/Services/DeteccionHorasExtraService.php`
- Modify: `src/Services/MarcaAsistenciaService.php`, `src/Services/AsistenciaService.php`, `config/dependencies.php`
- Test: `tests/Services/DeteccionHorasExtraServiceTest.php`, `tests/Services/MarcaAsistenciaServiceTest.php`, `tests/Services/AsistenciaServiceTest.php`

**Interfaces:**
- Consumes: `ClasificadorHoras::clasificar` (Task 5); `HorariosService::{horarioDelDia, tieneHorario}` (Task 2); `SolicitudesRepository::{insertDetectada, findDetectada, actualizarHoras, justificar, findPorJustificarEntre, findById, delete, resolver}` (Task 6 y existentes); `FeriadosRepository::findEntre`; `AuditoriaRepository::insert`.
- Produces:
  - `DeteccionHorasExtraService::OPCIONES = ['horas_extra', 'no_trabajo', 'despues']`
  - `validarJustificacion(string $opcion, string $motivo): void` (lanza `InvalidArgumentException`)
  - `clasificar(int $idEmpleado, string $fecha, string $entrada, string $salida): ?array` (null si el empleado no tiene horario)
  - `salidaProgramada(int $idEmpleado, string $fecha): ?string` (`'H:i'` o null)
  - `registrar(int $idEmpleado, string $fecha, string $entrada, string $salida, string $opcion, string $motivo, int $idUsuario, string $ip): void`
  - `recalcular(int $idEmpleado, string $fecha, string $entrada, string $salida, int $idUsuario, string $ip): void`
  - `justificar(int $idSolicitud, int $idEmpleado, string $motivo, int $idUsuario, string $ip): void`
  - `rechazarSinJustificar(string $desde, string $hasta, int $idUsuario, string $ip): int`
  - `MarcaAsistenciaService::marcarSalida(int $idEmpleado, DateTimeImmutable $ahora, int $idUsuario, string $ip, string $opcion = 'despues', string $motivo = ''): void`
  - `MarcaAsistenciaService::corregirSalida(..., string $ip, string $opcion = 'despues', string $motivo = ''): void`
  - `MarcaAsistenciaService::vista()` agrega `'salidaHorarioHoy' => ?string`

- [ ] **Step 1: Pruebas que fallan**

`tests/Services/DeteccionHorasExtraServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\ClasificadorHoras;
use App\Repositories\AuditoriaRepository;
use App\Repositories\FeriadosRepository;
use App\Repositories\SolicitudesRepository;
use App\Services\DeteccionHorasExtraService;
use App\Services\HorariosService;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DeteccionHorasExtraServiceTest extends TestCase
{
    private SolicitudesRepository&MockObject $sol;
    private HorariosService&MockObject $horarios;
    private FeriadosRepository&MockObject $feriados;

    protected function setUp(): void
    {
        $this->sol      = $this->createMock(SolicitudesRepository::class);
        $this->horarios = $this->createMock(HorariosService::class);
        $this->feriados = $this->createMock(FeriadosRepository::class);
        $this->horarios->method('tieneHorario')->willReturn(true);
        $this->horarios->method('horarioDelDia')->willReturn(['entrada' => '08:00:00', 'salida' => '17:00:00']);
        $this->feriados->method('findEntre')->willReturn([]);
    }

    private function service(): DeteccionHorasExtraService
    {
        return new DeteccionHorasExtraService(
            $this->sol, $this->horarios, $this->feriados, new ClasificadorHoras(1.50, 3.00),
            $this->createMock(AuditoriaRepository::class)
        );
    }

    public function testTrabajeHorasExtraCreaPendienteConElMotivo(): void
    {
        $this->sol->method('findDetectada')->willReturn(null);
        $this->sol->expects(self::once())->method('insertDetectada')
            ->with(5, '2026-10-15', 1.67, 'Terminar la moto de un cliente', 'pendiente')->willReturn(70);
        $this->service()->registrar(5, '2026-10-15', '07:55:00', '18:40:00', 'horas_extra', 'Terminar la moto de un cliente', 1, 'ip');
    }

    public function testJustificarDespuesCreaPorJustificar(): void
    {
        $this->sol->method('findDetectada')->willReturn(null);
        $this->sol->expects(self::once())->method('insertDetectada')
            ->with(5, '2026-10-15', 1.67, '', 'por_justificar')->willReturn(71);
        $this->service()->registrar(5, '2026-10-15', '07:55:00', '18:40:00', 'despues', '', 1, 'ip');
    }

    public function testNoFueTrabajoNoCreaNada(): void
    {
        $this->sol->expects(self::never())->method('insertDetectada');
        $this->service()->registrar(5, '2026-10-15', '07:55:00', '18:40:00', 'no_trabajo', '', 1, 'ip');
    }

    public function testSinHorasExtraNoCreaNada(): void
    {
        $this->sol->expects(self::never())->method('insertDetectada');
        $this->service()->registrar(5, '2026-10-15', '08:00:00', '17:10:00', 'horas_extra', 'x', 1, 'ip');
    }

    public function testSinHorarioAsignadoNoDetecta(): void
    {
        $horarios = $this->createMock(HorariosService::class);
        $horarios->method('tieneHorario')->willReturn(false);
        $this->horarios = $horarios;
        $this->sol->expects(self::never())->method('insertDetectada');
        self::assertNull($this->service()->clasificar(5, '2026-10-15', '08:00:00', '20:00:00'));
        $this->service()->registrar(5, '2026-10-15', '08:00:00', '20:00:00', 'horas_extra', 'x', 1, 'ip');
    }

    public function testNoDuplicaSiYaHayUnaDetectadaEseDia(): void
    {
        $this->sol->method('findDetectada')->willReturn(['id_solicitud' => 70, 'estado' => 'pendiente', 'horas' => '1.00']);
        $this->sol->expects(self::never())->method('insertDetectada');
        $this->sol->expects(self::once())->method('actualizarHoras')->with(70, 1.67);
        $this->service()->registrar(5, '2026-10-15', '07:55:00', '18:40:00', 'horas_extra', 'x', 1, 'ip');
    }

    public function testHorasExtraSinMotivoSeRechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('qué hiciste');
        $this->service()->validarJustificacion('horas_extra', '   ');
    }

    public function testOpcionDesconocidaSeRechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->validarJustificacion('otra', '');
    }

    public function testRecalcularActualizaPendiente(): void
    {
        $this->sol->method('findDetectada')->willReturn(['id_solicitud' => 70, 'estado' => 'por_justificar', 'horas' => '1.67']);
        $this->sol->expects(self::once())->method('actualizarHoras')->with(70, 2.0);
        $this->service()->recalcular(5, '2026-10-15', '08:00:00', '19:00:00', 1, 'ip');
    }

    public function testRecalcularBorraSiYaNoHayExtra(): void
    {
        $this->sol->method('findDetectada')->willReturn(['id_solicitud' => 70, 'estado' => 'pendiente', 'horas' => '1.67']);
        $this->sol->expects(self::once())->method('delete')->with(70);
        $this->service()->recalcular(5, '2026-10-15', '08:00:00', '17:00:00', 1, 'ip');
    }

    public function testRecalcularNoTocaAprobadas(): void
    {
        $this->sol->method('findDetectada')->willReturn(['id_solicitud' => 70, 'estado' => 'aprobada', 'horas' => '1.67']);
        $this->sol->expects(self::never())->method('actualizarHoras');
        $this->sol->expects(self::never())->method('delete');
        $this->service()->recalcular(5, '2026-10-15', '08:00:00', '17:00:00', 1, 'ip');
    }

    public function testRecalcularSinSolicitudCreaPorJustificar(): void
    {
        $this->sol->method('findDetectada')->willReturn(null);
        $this->sol->expects(self::once())->method('insertDetectada')->with(5, '2026-10-15', 2.0, '', 'por_justificar');
        $this->service()->recalcular(5, '2026-10-15', '08:00:00', '19:00:00', 1, 'ip');
    }

    public function testJustificarPasaAPendiente(): void
    {
        $this->sol->method('findById')->with(70, 5)->willReturn(['id_solicitud' => 70, 'estado' => 'por_justificar', 'origen' => 'asistencia']);
        $this->sol->expects(self::once())->method('justificar')->with(70, 'Inventario de repuestos');
        $this->service()->justificar(70, 5, ' Inventario de repuestos ', 1, 'ip');
    }

    public function testRechazarSinJustificarAlCierre(): void
    {
        $this->sol->method('findPorJustificarEntre')->with('2026-10-01', '2026-10-15')
            ->willReturn([['id_solicitud' => 70], ['id_solicitud' => 71]]);
        $this->sol->expects(self::exactly(2))->method('resolver')
            ->with(self::anything(), 'rechazada', 1, 'No se justificó antes del cierre del periodo');
        self::assertSame(2, $this->service()->rechazarSinJustificar('2026-10-01', '2026-10-15', 1, 'ip'));
    }
}
```

Run: `vendor/bin/phpunit tests/Services/DeteccionHorasExtraServiceTest.php` → Expected: ERROR class not found.

- [ ] **Step 2: Implementar `DeteccionHorasExtraService`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\ClasificadorHoras;
use App\Repositories\AuditoriaRepository;
use App\Repositories\FeriadosRepository;
use App\Repositories\SolicitudesRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * DeteccionHorasExtraService — detecta horas extra comparando la salida marcada con el horario
 * y las deja como solicitud (origen = asistencia) para que el empleado justifique y el admin apruebe.
 * No accede a PDO ni a $_SESSION.
 */
class DeteccionHorasExtraService
{
    public const OPCIONES = ['horas_extra', 'no_trabajo', 'despues'];
    private const OBS_CIERRE = 'No se justificó antes del cierre del periodo';

    public function __construct(
        private readonly SolicitudesRepository $solicitudesRepo,
        private readonly HorariosService       $horarios,
        private readonly FeriadosRepository    $feriadosRepo,
        private readonly ClasificadorHoras     $clasificador,
        private readonly AuditoriaRepository   $auditoriaRepo
    ) {}

    public function validarJustificacion(string $opcion, string $motivo): void
    {
        if (!in_array($opcion, self::OPCIONES, true)) {
            throw new InvalidArgumentException('Elige por qué saliste tarde.');
        }
        if ($opcion === 'horas_extra' && trim($motivo) === '') {
            throw new InvalidArgumentException('Cuenta qué hiciste en las horas extra.');
        }
        if (mb_strlen(trim($motivo)) > 255) {
            throw new InvalidArgumentException('La justificación no puede pasar de 255 caracteres.');
        }
    }

    /** @return array<string, mixed>|null null si el empleado no tiene horario asignado */
    public function clasificar(int $idEmpleado, string $fecha, string $entrada, string $salida): ?array
    {
        if (!$this->horarios->tieneHorario($idEmpleado)) {
            return null;
        }
        return $this->clasificador->clasificar(
            $entrada,
            $salida,
            $this->horarios->horarioDelDia($idEmpleado, $fecha),
            $this->feriadosRepo->findEntre($fecha, $fecha) !== []
        );
    }

    public function salidaProgramada(int $idEmpleado, string $fecha): ?string
    {
        $h = $this->horarios->horarioDelDia($idEmpleado, $fecha);
        return $h !== null ? substr($h['salida'], 0, 5) : null;
    }

    public function registrar(
        int $idEmpleado, string $fecha, string $entrada, string $salida,
        string $opcion, string $motivo, int $idUsuario, string $ip
    ): void {
        $c = $this->clasificar($idEmpleado, $fecha, $entrada, $salida);
        if ($c === null || $c['horas_extra'] <= 0 || $opcion === 'no_trabajo') {
            return;
        }
        $existente = $this->solicitudesRepo->findDetectada($idEmpleado, $fecha);
        if ($existente !== null) {
            if ($existente['estado'] !== 'aprobada') {
                $this->solicitudesRepo->actualizarHoras((int)$existente['id_solicitud'], $c['horas_extra']);
            }
            return;
        }
        $pendiente = $opcion === 'horas_extra';
        $id = $this->solicitudesRepo->insertDetectada(
            $idEmpleado, $fecha, $c['horas_extra'],
            $pendiente ? trim($motivo) : '',
            $pendiente ? 'pendiente' : 'por_justificar'
        );
        $this->auditoriaRepo->insert('INSERT', $idUsuario, 'solicitudes', $id, $ip);
    }

    /** Corrección de una salida ya marcada (RRHH o el empleado). No pide justificación. */
    public function recalcular(int $idEmpleado, string $fecha, string $entrada, string $salida, int $idUsuario, string $ip): void
    {
        $c         = $this->clasificar($idEmpleado, $fecha, $entrada, $salida);
        $horas     = $c['horas_extra'] ?? 0.0;
        $existente = $this->solicitudesRepo->findDetectada($idEmpleado, $fecha);

        if ($existente === null) {
            if ($horas > 0) {
                $id = $this->solicitudesRepo->insertDetectada($idEmpleado, $fecha, $horas, '', 'por_justificar');
                $this->auditoriaRepo->insert('INSERT', $idUsuario, 'solicitudes', $id, $ip);
            }
            return;
        }
        if ($existente['estado'] === 'aprobada') {
            return;   // el aviso de "asistencia cambió" se calcula al listar (Task 10)
        }
        $id = (int)$existente['id_solicitud'];
        if ($horas <= 0) {
            $this->solicitudesRepo->delete($id);
            $this->auditoriaRepo->insert('DELETE', $idUsuario, 'solicitudes', $id, $ip);
            return;
        }
        $this->solicitudesRepo->actualizarHoras($id, $horas);
        $this->auditoriaRepo->insert('UPDATE', $idUsuario, 'solicitudes', $id, $ip);
    }

    public function justificar(int $idSolicitud, int $idEmpleado, string $motivo, int $idUsuario, string $ip): void
    {
        $s = $this->solicitudesRepo->findById($idSolicitud, $idEmpleado);
        if ($s === null || ($s['origen'] ?? '') !== 'asistencia' || $s['estado'] !== 'por_justificar') {
            throw new RuntimeException('Esa solicitud no está esperando justificación.');
        }
        $this->validarJustificacion('horas_extra', $motivo);
        $this->solicitudesRepo->justificar($idSolicitud, trim($motivo));
        $this->auditoriaRepo->insert('UPDATE', $idUsuario, 'solicitudes', $idSolicitud, $ip);
    }

    /** Al cerrar un periodo: lo que siga por justificar queda rechazado. @return int cantidad */
    public function rechazarSinJustificar(string $desde, string $hasta, int $idUsuario, string $ip): int
    {
        $filas = $this->solicitudesRepo->findPorJustificarEntre($desde, $hasta);
        foreach ($filas as $f) {
            $this->solicitudesRepo->resolver((int)$f['id_solicitud'], 'rechazada', $idUsuario, self::OBS_CIERRE);
            $this->auditoriaRepo->insert('UPDATE', $idUsuario, 'solicitudes', (int)$f['id_solicitud'], $ip);
        }
        return count($filas);
    }
}
```

Registrar en `config/dependencies.php`:

```php
    \App\Services\DeteccionHorasExtraService::class => function (ContainerInterface $c) {
        return new \App\Services\DeteccionHorasExtraService(
            $c->get(\App\Repositories\SolicitudesRepository::class),
            $c->get(\App\Services\HorariosService::class),
            $c->get(\App\Repositories\FeriadosRepository::class),
            $c->get(\App\Helpers\ClasificadorHoras::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },
```

Run: `vendor/bin/phpunit tests/Services/DeteccionHorasExtraServiceTest.php` → Expected: OK (14 tests).

- [ ] **Step 3: Conectar en `MarcaAsistenciaService` — prueba que falla**

En `tests/Services/MarcaAsistenciaServiceTest.php`: agregar la propiedad `private DeteccionHorasExtraService&MockObject $deteccion;` creada en `setUp`, y pasarla en `service()` como argumento después de `new CalendarioAsistencia()` (el `HorariosService` de Task 4 queda último, `null`). Pruebas:

```php
    public function testMarcarSalidaRegistraLaDeteccionConLaOpcion(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn($this->registro());
        $this->periodos->method('findById')->willReturn(['id_periodo' => 9, 'estado' => 'abierto']);
        $this->deteccion->expects(self::once())->method('validarJustificacion')->with('horas_extra', 'Moto de cliente');
        $this->deteccion->expects(self::once())->method('registrar')
            ->with(5, '2026-10-12', '07:58:00', '18:40:00', 'horas_extra', 'Moto de cliente', 1, 'ip');
        $this->service()->marcarSalida(5, $this->ahora('2026-10-12 18:40:10'), 1, 'ip', 'horas_extra', 'Moto de cliente');
    }

    public function testJustificacionInvalidaNoGuardaLaSalida(): void
    {
        $this->asistencia->method('findByEmpleadoFecha')->willReturn($this->registro());
        $this->deteccion->method('validarJustificacion')->willThrowException(new InvalidArgumentException('Cuenta qué hiciste'));
        $this->asistencia->expects(self::never())->method('registrarSalida');
        $this->expectException(InvalidArgumentException::class);
        $this->service()->marcarSalida(5, $this->ahora('2026-10-12 18:40:10'), 1, 'ip', 'horas_extra', '');
    }
```

Run → Expected: FAIL.

- [ ] **Step 4: Implementar en `MarcaAsistenciaService`**

- Constructor: agregar `private readonly DeteccionHorasExtraService $deteccion` después de `CalendarioAsistencia $calendario` (antes del `?HorariosService $horarios = null` de Task 4).
- `marcarSalida(..., string $ip, string $opcion = 'despues', string $motivo = '')`: al inicio, después de `exigirActivo`, llamar `$this->deteccion->validarJustificacion($opcion, $motivo);`. Al final, después de auditar:

```php
        $this->deteccion->registrar(
            $idEmpleado, (string)$reg['fecha'], (string)$reg['hora_entrada'], $salida, $opcion, $motivo, $idUsuario, $ip
        );
```

- `corregirSalida(..., string $ip, string $opcion = 'despues', string $motivo = '')`: igual, con `$horaSalida . ':00'`.
- `vista()`: agregar `'salidaHorarioHoy' => $this->deteccion->salidaProgramada($idEmpleado, $ahora->format('Y-m-d')),`.
- `config/dependencies.php`: pasar `$c->get(\App\Services\DeteccionHorasExtraService::class)` en esa posición.

- [ ] **Step 5: Conectar en `AsistenciaService::actualizar` (corrección de RRHH)**

En `tests/Services/AsistenciaServiceTest.php`:

1. Agregar la propiedad y pasarla al constructor dentro del helper `service()`:

```php
    private ?DeteccionHorasExtraService $deteccion = null;
```

y en `service()` reemplazar el `return new AsistenciaService(...)` por:

```php
        return new AsistenciaService(
            $repo,
            $empleados,
            $this->createMock(PeriodosRepository::class),
            $this->createMock(AuditoriaRepository::class),
            $this->deteccion ?? $this->createMock(DeteccionHorasExtraService::class)
        );
```

(agregar `use App\Services\DeteccionHorasExtraService;`).

2. Prueba nueva:

```php
    public function testEditarRecalculaLasHorasExtraDetectadas(): void
    {
        $this->deteccion = $this->createMock(DeteccionHorasExtraService::class);
        $this->deteccion->expects(self::once())->method('recalcular')
            ->with(2, '2026-05-04', self::anything(), self::stringStartsWith('19:00'), 1, '127.0.0.1');

        $this->service(null)->actualizar(9, ['hora_salida' => '19:00'] + $this->datos(), 1, '127.0.0.1');
    }

    public function testCrearNoLlamaALaDeteccion(): void
    {
        $this->deteccion = $this->createMock(DeteccionHorasExtraService::class);
        $this->deteccion->expects(self::never())->method('recalcular');
        $this->service(null)->crear($this->datos(), 1, '127.0.0.1');
    }
```

Implementación: constructor de `AsistenciaService` recibe `private readonly DeteccionHorasExtraService $deteccion` como último parámetro; en `actualizar`, después de auditar:

```php
        $this->deteccion->recalcular(
            (int)$fila['id_empleado'], (string)$fila['fecha'], (string)$fila['hora_entrada'], (string)$fila['hora_salida'],
            $loggedInId, $ip
        );
```

(si las claves de `$fila` tienen otro nombre, usar las que devuelve `validar`). Registrar el argumento en `dependencies.php`.

- [ ] **Step 6: Suite y commit**

Run: `vendor/bin/phpunit` → OK.

```bash
git add src/Services/DeteccionHorasExtraService.php src/Services/MarcaAsistenciaService.php src/Services/AsistenciaService.php config/dependencies.php tests
git commit -m "feat(horas-extra): detectar horas extra al marcar, corregir o editar la salida

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Portal — aviso al marcar la salida, justificar después y sin horas extra manuales

**Files:**
- Modify: `src/Controllers/MiAsistenciaController.php`, `templates/portal/mi_asistencia.html.twig`, `public/assets/js/app.js`
- Modify: `src/Controllers/PortalController.php`, `templates/portal/mis_solicitudes.html.twig`, `templates/portal/solicitud_form.html.twig`, `config/routes.php`, `config/dependencies.php`
- Modify: `src/Services/SolicitudesService.php` (`crear` con `$desdePortal`)
- Test: `tests/Controllers/MiAsistenciaControllerTest.php`, `tests/Services/SolicitudesServiceOwnershipTest.php`

**Interfaces:**
- Consumes: `MarcaAsistenciaService::{marcarSalida, corregirSalida, vista}` (Task 7), `DeteccionHorasExtraService::justificar` (Task 7).
- Produces: ruta `POST /portal/solicitudes/{id}/justificar` → `portal.solicitudes.justificar`; `SolicitudesService::crear(array $datos, int $loggedInId, string $ip, bool $desdePortal = false): int`.

- [ ] **Step 1: Controller pasa la opción — prueba que falla**

En `tests/Controllers/MiAsistenciaControllerTest.php` (usar el helper `request()` existente):

```php
    public function testSalidaPasaLaOpcionYElMotivoAlService(): void
    {
        $marcas = $this->createMock(MarcaAsistenciaService::class);
        $marcas->expects(self::once())->method('marcarSalida')
            ->with(5, self::anything(), self::anything(), self::anything(), 'horas_extra', 'Moto de cliente');
        $this->controller(5, $marcas)->salida(
            $this->request('POST', ['opcion' => 'horas_extra', 'motivo' => 'Moto de cliente']),
            (new \Slim\Psr7\Factory\ResponseFactory())->createResponse()
        );
    }
```

Run → FAIL.

- [ ] **Step 2: Implementar en `MiAsistenciaController`**

```php
    public function salida(Request $request, Response $response): Response
    {
        $ahora = ($this->reloj)();
        [$opcion, $motivo] = $this->justificacion($request);
        return $this->ejecutar($request, $response,
            fn (int $idEmpleado) => $this->marcas->marcarSalida(
                $idEmpleado, $ahora, $this->usuarioId($request), $this->ip(), $opcion, $motivo
            ),
            'Salida registrada a las ' . $ahora->format('H:i') . '.'
        );
    }

    public function corregir(Request $request, Response $response, array $args): Response
    {
        $hora = (string)(((array)$request->getParsedBody())['hora_salida'] ?? '');
        [$opcion, $motivo] = $this->justificacion($request);
        return $this->ejecutar($request, $response,
            fn (int $idEmpleado) => $this->marcas->corregirSalida(
                $idEmpleado, (int)$args['id'], $hora, ($this->reloj)(), $this->usuarioId($request), $this->ip(), $opcion, $motivo
            ),
            'Salida guardada.'
        );
    }

    /** @return array{0: string, 1: string} */
    private function justificacion(Request $request): array
    {
        $body = (array)$request->getParsedBody();
        return [(string)($body['opcion'] ?? 'despues'), (string)($body['motivo'] ?? '')];
    }
```

Run → PASS.

- [ ] **Step 3: Aviso en `mi_asistencia.html.twig` + JS**

Reemplazar el `<form>` de "Marcar salida" por:

```twig
                <form method="POST" action="{{ url_for('portal.asistencia.salida') }}" id="formSalida"
                      data-salida-horario="{{ salidaHorarioHoy ?? '' }}"
                      data-entrada="{{ hoy.registro.hora_entrada|slice(0, 5) }}">
                    <input type="hidden" name="opcion" value="despues">
                    <input type="hidden" name="motivo" value="">
                    <button class="btn btn-primary"><i class="bi bi-box-arrow-right me-1"></i>Marcar salida</button>
                </form>
```

Agregar, antes de `{% endblock %}` del contenido, el modal:

```twig
<div class="modal fade" id="modalSalidaTarde" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-clock-history me-1"></i>Saliste después de tu horario</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <p id="salidaTardeTexto" class="mb-3"></p>
            <div class="form-check mb-2">
                <input class="form-check-input" type="radio" name="opcionSalida" id="opHoras" value="horas_extra">
                <label class="form-check-label" for="opHoras">Trabajé horas extra</label>
            </div>
            <textarea class="form-control mb-3 d-none" id="motivoSalida" rows="2" maxlength="255" placeholder="¿Qué hiciste? Ej: terminar el servicio de la moto de un cliente"></textarea>
            <div class="form-check mb-2">
                <input class="form-check-input" type="radio" name="opcionSalida" id="opNo" value="no_trabajo">
                <label class="form-check-label" for="opNo">No fue trabajo (me quedé por algo personal)</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="opcionSalida" id="opDespues" value="despues" checked>
                <label class="form-check-label" for="opDespues">Lo justifico después (en Mis solicitudes)</label>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-primary" id="btnConfirmarSalida">Marcar salida</button>
        </div>
    </div></div>
</div>
```

En el formulario de "Te faltó marcar la salida del …" (corrección), agregar antes del botón:

```twig
            <select name="opcion" class="form-select form-select-sm" style="max-width:200px">
                <option value="despues">Si salí tarde: lo justifico después</option>
                <option value="horas_extra">Si salí tarde: trabajé horas extra</option>
                <option value="no_trabajo">Si salí tarde: no fue trabajo</option>
            </select>
            <input type="text" name="motivo" maxlength="255" class="form-control form-control-sm" placeholder="Qué hiciste (si fueron horas extra)">
```

`public/assets/js/app.js`, al final:

```js
// ── Mi asistencia: si sale ≥15 min después del horario, pide justificación antes de marcar ──
function initSalidaTarde() {
    const form = document.getElementById('formSalida');
    const modalEl = document.getElementById('modalSalidaTarde');
    if (!form || !modalEl) {
        return;
    }
    const aMin = (hhmm) => { const [h, m] = hhmm.split(':').map(Number); return h * 60 + m; };
    const motivo = document.getElementById('motivoSalida');
    modalEl.querySelectorAll('input[name="opcionSalida"]').forEach((r) => r.addEventListener('change', () => {
        motivo.classList.toggle('d-none', r.value !== 'horas_extra' || !r.checked);
    }));

    form.addEventListener('submit', function (e) {
        if (form.dataset.confirmado === '1') {
            return;
        }
        const ahora = new Date();
        const minAhora = ahora.getHours() * 60 + ahora.getMinutes();
        const salida = form.dataset.salidaHorario;
        let extra = 0;
        let texto = '';
        if (salida) {
            extra = minAhora - aMin(salida);
            texto = 'Tu horario de hoy termina a las ' + salida + ' y estás marcando ' + Math.floor(extra / 60) + ' h ' + (extra % 60) + ' min después. ¿Por qué saliste tarde?';
        } else if (form.dataset.entrada) {
            const trabajados = minAhora - aMin(form.dataset.entrada);
            extra = trabajados - 8 * 60;
            texto = 'Hoy no tenés horario; trabajaste ' + Math.floor(trabajados / 60) + ' h ' + (trabajados % 60) + ' min y lo que pasa de 8 h cuenta como horas extra. ¿Por qué?';
        }
        if (extra < 15) {
            return;
        }
        e.preventDefault();
        document.getElementById('salidaTardeTexto').textContent = texto;
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    });

    document.getElementById('btnConfirmarSalida').addEventListener('click', function () {
        const opcion = modalEl.querySelector('input[name="opcionSalida"]:checked').value;
        if (opcion === 'horas_extra' && motivo.value.trim() === '') {
            motivo.classList.remove('d-none');
            motivo.focus();
            return;
        }
        form.querySelector('input[name="opcion"]').value = opcion;
        form.querySelector('input[name="motivo"]').value = motivo.value.trim();
        form.dataset.confirmado = '1';
        this.disabled = true;
        form.submit();
    });
}

document.addEventListener('DOMContentLoaded', initSalidaTarde);
```

(El servidor decide con su propio reloj; el JS solo decide si mostrar el aviso. Sin JS se envía `despues`.)

- [ ] **Step 4: Justificar después en Mis solicitudes**

`config/routes.php`, con las rutas del portal:

```php
    $app->post('/portal/solicitudes/{id}/justificar', [\App\Controllers\PortalController::class, 'justificarSolicitud'])
        ->setName('portal.solicitudes.justificar')->add(new AuthMiddleware());
```

`PortalController`: constructor agrega `private readonly DeteccionHorasExtraService $deteccion` (y registrar en `dependencies.php`); en `solicitudes()` aceptar `'por_justificar'` en el filtro de estado; método nuevo:

```php
    public function justificarSolicitud(Request $request, Response $response, array $args): Response
    {
        $idEmpleado = $this->idEmpleadoOFlash($request);
        if ($idEmpleado !== null) {
            $motivo = (string)(((array)$request->getParsedBody())['motivo'] ?? '');
            try {
                $this->deteccion->justificar((int)$args['id'], $idEmpleado, $motivo, $this->usuarioId($request), $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
                $_SESSION['flash_success'] = 'Justificación enviada. RRHH la revisará.';
            } catch (InvalidArgumentException | RuntimeException $e) {
                $_SESSION['flash_error'] = $e->getMessage();
            }
        }
        return $this->redirectToMisSolicitudes($request, $response);
    }
```

`templates/portal/mis_solicitudes.html.twig`:
- En el filtro de estado agregar `<option value="por_justificar" {{ filtroEstado == 'por_justificar' ? 'selected' : '' }}>Por justificar</option>`.
- En la columna de acciones, antes del bloque `{% if s.estado == 'pendiente' %}`:

```twig
                    {% if s.estado == 'por_justificar' %}
                    <form method="POST" action="{{ url_for('portal.solicitudes.justificar', {'id': s.id_solicitud}) }}" class="d-flex gap-1">
                        <input type="text" name="motivo" maxlength="255" required class="form-control form-control-sm" placeholder="¿Qué hiciste?">
                        <button class="btn btn-sm btn-primary text-nowrap">Justificar</button>
                    </form>
                    {% endif %}
```

- Ocultar los botones Editar/Eliminar para filas con `s.origen == 'asistencia'` (cambiar la condición a `{% if s.estado == 'pendiente' and s.origen != 'asistencia' %}`).

Revisar `templates/partials/ui.html.twig` macro `estado`: agregar `'por_justificar'` con tono ámbar y texto "Por justificar".

- [ ] **Step 5: El portal ya no ofrece horas extra manuales**

`templates/portal/solicitud_form.html.twig`: borrar la `<option value="horas_extra" …>` y agregar debajo del select `<div class="form-text">Las horas extra se registran solas cuando marcas la salida después de tu horario.</div>`.

`SolicitudesService::crear(array $datos, int $loggedInId, string $ip, bool $desdePortal = false): int`: al inicio

```php
        if ($desdePortal && trim((string)($datos['tipo'] ?? '')) === 'horas_extra') {
            throw new InvalidArgumentException('Las horas extra se registran al marcar la salida en Mi asistencia.');
        }
```

`PortalController::guardarSolicitud`: llamar `crear($datos, $loggedInId, $ip, true)`.

Prueba en `tests/Services/SolicitudesServiceOwnershipTest.php`:

```php
    public function testElPortalNoCreaHorasExtraManuales(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Mi asistencia');
        $this->service($this->createMock(SolicitudesRepository::class), $this->createMock(EmpleadosRepository::class))
            ->crear(['id_empleado' => 1, 'tipo' => 'horas_extra', 'fecha_inicio' => '2026-10-20', 'horas' => '2'], 1, 'ip', true);
    }
```

(ajustar al helper `service(...)` real del archivo).

- [ ] **Step 6: Suite, prueba manual y commit**

Run: `vendor/bin/phpunit` → OK.
Manual: como empleado (`lmora` / `Test1234!` o el usuario de Luis en la demo), marcar entrada; cambiar la hora del sistema no es necesario: en la base poner `hora_entrada` de hoy a las 06:00 y el horario de hoy con salida a una hora ya pasada; marcar salida → aparece el aviso; elegir "Lo justifico después" → en Mis solicitudes aparece "Por justificar" con el campo Justificar.

```bash
git add src/Controllers templates/portal templates/partials/ui.html.twig public/assets/js/app.js config/routes.php config/dependencies.php src/Services/SolicitudesService.php tests
git commit -m "feat(portal): justificar la salida tarde al marcar o después

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Rechazar lo no justificado al cerrar el periodo

**Files:**
- Modify: `src/Services/PeriodosService.php`, `config/dependencies.php`
- Test: `tests/Services/PeriodosServiceCierreTest.php`

**Interfaces:**
- Consumes: `DeteccionHorasExtraService::rechazarSinJustificar(string $desde, string $hasta, int $idUsuario, string $ip): int` (Task 7).
- Produces: `PeriodosService::__construct(PeriodosRepository $repo, AuditoriaRepository $auditoriaRepo, ?DeteccionHorasExtraService $deteccion = null)` (verificar el orden real de los dos primeros con `sed -n 15,20p src/Services/PeriodosService.php`).

- [ ] **Step 1: Prueba que falla**

```php
<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\PeriodosRepository;
use App\Services\DeteccionHorasExtraService;
use App\Services\PeriodosService;
use PHPUnit\Framework\TestCase;

final class PeriodosServiceCierreTest extends TestCase
{
    private function datos(string $estado): array
    {
        return ['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-15', 'estado' => $estado];
    }

    public function testCerrarRechazaLasPorJustificarDelPeriodo(): void
    {
        $repo = $this->createMock(PeriodosRepository::class);
        $repo->method('findById')->willReturn(['id_periodo' => 12, 'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-15', 'estado' => 'abierto']);
        $repo->method('findOverlapping')->willReturn([]);
        $deteccion = $this->createMock(DeteccionHorasExtraService::class);
        $deteccion->expects(self::once())->method('rechazarSinJustificar')->with('2026-10-01', '2026-10-15', 1, 'ip');

        (new PeriodosService($repo, $this->createMock(AuditoriaRepository::class), $deteccion))
            ->actualizar(12, $this->datos('cerrado'), 1, 'ip');
    }

    public function testDejarloAbiertoNoRechazaNada(): void
    {
        $repo = $this->createMock(PeriodosRepository::class);
        $repo->method('findById')->willReturn(['id_periodo' => 12, 'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-15', 'estado' => 'abierto']);
        $repo->method('findOverlapping')->willReturn([]);
        $deteccion = $this->createMock(DeteccionHorasExtraService::class);
        $deteccion->expects(self::never())->method('rechazarSinJustificar');

        (new PeriodosService($repo, $this->createMock(AuditoriaRepository::class), $deteccion))
            ->actualizar(12, $this->datos('abierto'), 1, 'ip');
    }
}
```

Run → FAIL.

- [ ] **Step 2: Implementar**

Constructor: agregar `private readonly ?DeteccionHorasExtraService $deteccion = null` al final. En `actualizar`, después de `$this->auditoriaRepo->insert(...)`:

```php
        if ($current['estado'] === 'abierto' && $nuevoEstado === 'cerrado' && $this->deteccion !== null) {
            $this->deteccion->rechazarSinJustificar($inicio, $fin, $loggedInId, $ip);
        }
```

`dependencies.php`: pasar `$c->get(\App\Services\DeteccionHorasExtraService::class)`.

- [ ] **Step 3: Suite y commit**

Run: `vendor/bin/phpunit` → OK.

```bash
git add src/Services/PeriodosService.php config/dependencies.php tests/Services/PeriodosServiceCierreTest.php
git commit -m "feat(periodos): al cerrar, las horas extra sin justificar quedan rechazadas

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Aprobación de horas extra detectadas (admin) y CCSS recalculada

**Files:**
- Create: `src/Services/AprobacionHorasExtraService.php`, `templates/solicitudes/detectadas.html.twig`
- Modify: `src/Repositories/PeriodosRepository.php` (`findSiguienteAbierto`), `src/Repositories/NominasRepository.php` (`findCabeceraEmpleadoPeriodo`, `deleteDeduccionesCCSS`, `horasExtraDelPeriodo` incluye `id_hora_extra`), `src/Services/NominasService.php` (`agregarHorasExtra`, `recalcular` con CCSS), `src/Services/SolicitudesService.php` (`rechazar` exige observación en detectadas), `src/Controllers/SolicitudesController.php`, `config/routes.php`, `config/dependencies.php`, `templates/solicitudes/index.html.twig` (enlace a la vista)
- Test: `tests/Services/AprobacionHorasExtraServiceTest.php`, `tests/Services/NominasServiceRecalculoTest.php`

**Interfaces:**
- Consumes: `DeteccionHorasExtraService::clasificar` (Task 7); `SolicitudesRepository::{findById, findAll(..., $origen), resolver}`; `AsistenciaRepository::findByEmpleadoFecha`; `HorasExtraRepository::{insert, findParametroId}`; `PeriodosRepository::findPorFecha`; `NominaCalculadora::valorHora`.
- Produces:
  - `PeriodosRepository::findSiguienteAbierto(string $despuesDe): ?array`
  - `NominasRepository::findCabeceraEmpleadoPeriodo(int $idEmpleado, int $idPeriodo): ?array` (`id_nomina, estado`)
  - `NominasRepository::deleteDeduccionesCCSS(int $idNomina): void`
  - `NominasService::agregarHorasExtra(int $idNomina, string $fecha, float $horas, float $factor, bool $posterior, int $idUsuario, string $ip): void`
  - `NominasService::lineaHorasExtra(string $fecha, float $horas, float $factor, float $salarioMensual, bool $posterior): array` (público, lo usa Task 11)
  - `AprobacionHorasExtraService::listar(?string $estado): array` (filas de solicitud + `clasificacion`, `monto_estimado`, `aviso_12h`, `asistencia_cambio`, `hora_entrada`, `hora_salida`, `salida_horario`)
  - `AprobacionHorasExtraService::aprobar(int $idSolicitud, float $horas, int $idUsuario, string $ip): void`
  - Rutas `GET /solicitudes/horas-extra-detectadas` (`solicitudes.detectadas`) y `POST /solicitudes/{id}/aprobar-detectada` (`solicitudes.aprobarDetectada`).

- [ ] **Step 1: Pruebas que fallan — CCSS recalculada**

`tests/Services/NominasServiceRecalculoTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\NominaCalculadora;
use App\Repositories\AuditoriaRepository;
use App\Repositories\NominasRepository;
use App\Services\NominasService;
use PHPUnit\Framework\TestCase;

final class NominasServiceRecalculoTest extends TestCase
{
    public function testAgregarIngresoRecalculaLaCcssSobreElNuevoBruto(): void
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('findByIdConLineas')->willReturn([
            'id_nomina' => 3, 'estado' => 'borrador',
            'ingresos'    => [['tipo' => 'salario_base', 'monto' => '200000.00'], ['tipo' => 'bonificacion', 'monto' => '10000.00']],
            'deducciones' => [['tipo' => 'CCSS_empleado', 'monto' => '21340.00'], ['tipo' => 'CCSS_patronal', 'monto' => '52660.00']],
        ]);
        $repo->expects(self::once())->method('deleteDeduccionesCCSS')->with(3);
        $insertadas = [];
        $repo->method('insertDeduccion')->willReturnCallback(function (int $id, array $d) use (&$insertadas) {
            $insertadas[$d['tipo']] = $d['monto'];
            return 1;
        });

        $svc = new NominasService($repo, $this->createMock(AuditoriaRepository::class), new NominaCalculadora(0.1067, 0.2633));
        $svc->agregarIngreso(3, ['tipo' => 'bonificacion', 'monto' => '10000'], 1, 'ip');

        self::assertSame(22407.0, $insertadas['CCSS_empleado']);   // 210 000 × 10.67 %
    }
}
```

Run → FAIL (`deleteDeduccionesCCSS` no existe).

- [ ] **Step 2: Implementar la CCSS recalculada**

`NominasRepository`:

```php
    public function deleteDeduccionesCCSS(int $idNomina): void
    {
        $this->pdo->prepare(
            "DELETE FROM deducciones_nomina WHERE id_nomina = :n AND tipo IN ('CCSS_empleado', 'CCSS_patronal')"
        )->execute([':n' => $idNomina]);
    }

    /** @return array<string, mixed>|null */
    public function findCabeceraEmpleadoPeriodo(int $idEmpleado, int $idPeriodo): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id_nomina, estado FROM nominas WHERE id_empleado = :e AND id_periodo = :p LIMIT 1');
        $stmt->execute([':e' => $idEmpleado, ':p' => $idPeriodo]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }
```

`NominasService::recalcular` (reemplazo completo):

```php
    /** Recalcula la CCSS sobre el bruto actual y luego los totales. */
    private function recalcular(int $idNomina): void
    {
        $n = $this->repo->findByIdConLineas($idNomina);
        if ($n === null) {
            return;
        }
        $bruto = round(array_sum(array_map(static fn ($i) => (float)$i['monto'], $n['ingresos'])), 2);
        $this->repo->deleteDeduccionesCCSS($idNomina);
        $ccss = $this->calc->deduccionesCCSS($bruto);
        foreach ($ccss as $d) {
            $this->repo->insertDeduccion($idNomina, $d);
        }
        $otras = array_filter($n['deducciones'], static fn ($d) => !in_array($d['tipo'], ['CCSS_empleado', 'CCSS_patronal'], true));
        $deducciones = array_merge(
            array_map(static fn ($d) => ['monto' => (float)$d['monto'], 'informativa' => false], array_values($otras)),
            $ccss
        );
        $this->repo->updateTotales($idNomina, $this->calc->calcularTotales($n['ingresos'], $deducciones));
    }
```

(`deduccionesCCSS` ya devuelve `informativa` para la patronal; `insertDeduccion` ignora esa clave.)

`NominasService` — métodos nuevos:

```php
    /** Línea de ingreso de horas extra. @return array{tipo: string, descripcion: string, monto: float} */
    public function lineaHorasExtra(string $fecha, float $horas, float $factor, float $salarioMensual, bool $posterior): array
    {
        $f = (new \DateTimeImmutable($fecha))->format('d/m/Y');
        return [
            'tipo'        => 'horas_extra',
            'descripcion' => sprintf('Horas extra %s: %s h × %s%s', $f, number_format($horas, 2), number_format($factor, 2), $posterior ? ' (pago posterior)' : ''),
            'monto'       => $this->calc->montoHorasExtra($salarioMensual, $horas, $factor),
        ];
    }

    public function agregarHorasExtra(int $idNomina, string $fecha, float $horas, float $factor, bool $posterior, int $idUsuario, string $ip): void
    {
        $nomina = $this->obtener($idNomina);
        $this->assertBorrador($nomina);
        $salario = $this->repo->salarioMensualEmpleado((int)$nomina['id_empleado']) ?? 0.0;
        $this->repo->insertIngreso($idNomina, $this->lineaHorasExtra($fecha, $horas, $factor, $salario, $posterior));
        $this->recalcular($idNomina);
        $this->auditoriaRepo->insert('UPDATE', $idUsuario, 'nominas', $idNomina, $ip);
    }
```

En `generarUno`, reemplazar la construcción de la línea de horas extra por `$ingresos[] = $this->lineaHorasExtra((string)$he['fecha'], (float)$he['cantidad_horas'], (float)$he['factor_recargo'], $salarioMensual, false);` (el pago posterior se distingue en Task 11).

Run: `vendor/bin/phpunit tests/Services/NominasServiceRecalculoTest.php` → PASS; `vendor/bin/phpunit` → OK.

- [ ] **Step 3: Pruebas que fallan — aprobación**

`tests/Services/AprobacionHorasExtraServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AsistenciaRepository;
use App\Repositories\AuditoriaRepository;
use App\Repositories\HorasExtraRepository;
use App\Repositories\NominasRepository;
use App\Repositories\PeriodosRepository;
use App\Repositories\SolicitudesRepository;
use App\Services\AprobacionHorasExtraService;
use App\Services\DeteccionHorasExtraService;
use App\Services\NominasService;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AprobacionHorasExtraServiceTest extends TestCase
{
    private SolicitudesRepository&MockObject $sol;
    private AsistenciaRepository&MockObject $asis;
    private HorasExtraRepository&MockObject $he;
    private PeriodosRepository&MockObject $periodos;
    private NominasRepository&MockObject $nomRepo;
    private NominasService&MockObject $nominas;
    private DeteccionHorasExtraService&MockObject $deteccion;

    protected function setUp(): void
    {
        $this->sol       = $this->createMock(SolicitudesRepository::class);
        $this->asis      = $this->createMock(AsistenciaRepository::class);
        $this->he        = $this->createMock(HorasExtraRepository::class);
        $this->periodos  = $this->createMock(PeriodosRepository::class);
        $this->nomRepo   = $this->createMock(NominasRepository::class);
        $this->nominas   = $this->createMock(NominasService::class);
        $this->deteccion = $this->createMock(DeteccionHorasExtraService::class);

        $this->sol->method('findById')->willReturn([
            'id_solicitud' => 70, 'id_empleado' => 5, 'tipo' => 'horas_extra', 'origen' => 'asistencia',
            'estado' => 'pendiente', 'fecha_inicio' => '2026-10-15', 'horas' => '1.67',
        ]);
        $this->asis->method('findByEmpleadoFecha')->willReturn(['hora_entrada' => '07:55:00', 'hora_salida' => '18:40:00']);
        $this->deteccion->method('clasificar')->willReturn([
            'tipo_dia' => 'normal', 'horas_extra' => 1.67, 'factor_extra' => 1.5, 'excede_12h' => false,
            'horas_trabajadas' => 10.75, 'horas_ordinarias' => 9.08, 'horas_dia_especial' => 0.0,
        ]);
        $this->periodos->method('findPorFecha')->willReturn(['id_periodo' => 12, 'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-15', 'estado' => 'abierto']);
        $this->he->method('findParametroId')->willReturn(7);
    }

    private function service(): AprobacionHorasExtraService
    {
        return new AprobacionHorasExtraService(
            $this->sol, $this->asis, $this->he, $this->periodos, $this->nomRepo,
            $this->nominas, $this->deteccion, $this->createMock(AuditoriaRepository::class)
        );
    }

    public function testAprobarSinNominaCreaHorasExtraEnElPeriodoDeLaFecha(): void
    {
        $this->nomRepo->method('findCabeceraEmpleadoPeriodo')->willReturn(null);
        $this->sol->expects(self::once())->method('resolver')->with(70, 'aprobada', 1, null);
        $this->he->expects(self::once())->method('insert')->with([
            'id_solicitud' => 70, 'id_empleado' => 5, 'id_periodo' => 12, 'fecha' => '2026-10-15',
            'cantidad_horas' => 1.0, 'factor_recargo' => 1.5, 'id_parametro' => 7,
        ])->willReturn(40);
        $this->nominas->expects(self::never())->method('agregarHorasExtra');
        $this->service()->aprobar(70, 1.0, 1, 'ip');
    }

    public function testConNominaEnBorradorAgregaLaLinea(): void
    {
        $this->nomRepo->method('findCabeceraEmpleadoPeriodo')->willReturn(['id_nomina' => 88, 'estado' => 'borrador']);
        $this->he->method('insert')->willReturn(40);
        $this->nominas->expects(self::once())->method('agregarHorasExtra')->with(88, '2026-10-15', 1.67, 1.5, false, 1, 'ip');
        $this->service()->aprobar(70, 1.67, 1, 'ip');
    }

    public function testConNominaAprobadaVaAlSiguientePeriodoAbierto(): void
    {
        $this->nomRepo->method('findCabeceraEmpleadoPeriodo')->willReturnMap([
            [5, 12, ['id_nomina' => 88, 'estado' => 'aprobado']],
            [5, 13, ['id_nomina' => 95, 'estado' => 'borrador']],
        ]);
        $this->periodos->method('findSiguienteAbierto')->with('2026-10-15')->willReturn(['id_periodo' => 13]);
        $this->he->expects(self::once())->method('insert')->with(self::callback(fn ($d) => $d['id_periodo'] === 13))->willReturn(40);
        $this->nominas->expects(self::once())->method('agregarHorasExtra')->with(95, '2026-10-15', 1.67, 1.5, true, 1, 'ip');
        $this->service()->aprobar(70, 1.67, 1, 'ip');
    }

    public function testSinPeriodoAbiertoSiguienteNoApruebaNada(): void
    {
        $this->nomRepo->method('findCabeceraEmpleadoPeriodo')->willReturn(['id_nomina' => 88, 'estado' => 'pagado']);
        $this->periodos->method('findSiguienteAbierto')->willReturn(null);
        $this->sol->expects(self::never())->method('resolver');
        $this->he->expects(self::never())->method('insert');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('periodo abierto');
        $this->service()->aprobar(70, 1.67, 1, 'ip');
    }

    public function testNoSePuedeAprobarMasDeLoDetectado(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('1.67');
        $this->service()->aprobar(70, 2.0, 1, 'ip');
    }

    public function testNoSeApruebaUnaYaResuelta(): void
    {
        $sol = $this->createMock(SolicitudesRepository::class);
        $sol->method('findById')->willReturn(['id_solicitud' => 70, 'id_empleado' => 5, 'tipo' => 'horas_extra',
            'origen' => 'asistencia', 'estado' => 'aprobada', 'fecha_inicio' => '2026-10-15', 'horas' => '1.67']);
        $this->sol = $sol;
        $this->expectException(RuntimeException::class);
        $this->service()->aprobar(70, 1.0, 1, 'ip');
    }
}
```

Run → FAIL.

- [ ] **Step 4: Implementar**

`PeriodosRepository`:

```php
    /** Primer periodo abierto que empieza después de la fecha. @return array<string, mixed>|null */
    public function findSiguienteAbierto(string $despuesDe): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM periodos_pago WHERE estado = 'abierto' AND fecha_inicio > :f ORDER BY fecha_inicio LIMIT 1"
        );
        $stmt->execute([':f' => $despuesDe]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }
```

`src/Services/AprobacionHorasExtraService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AsistenciaRepository;
use App\Repositories\AuditoriaRepository;
use App\Repositories\HorasExtraRepository;
use App\Repositories\NominasRepository;
use App\Repositories\PeriodosRepository;
use App\Repositories\SolicitudesRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * AprobacionHorasExtraService — el admin aprueba las horas extra detectadas por la asistencia.
 * Recalcula con la asistencia actual, crea el registro en `horas_extra` y lo lleva a la nómina
 * del periodo de la fecha (o al siguiente abierto si esa nómina ya se aprobó o pagó).
 */
class AprobacionHorasExtraService
{
    public function __construct(
        private readonly SolicitudesRepository      $solicitudesRepo,
        private readonly AsistenciaRepository       $asistenciaRepo,
        private readonly HorasExtraRepository       $horasExtraRepo,
        private readonly PeriodosRepository         $periodosRepo,
        private readonly NominasRepository          $nominasRepo,
        private readonly NominasService             $nominas,
        private readonly DeteccionHorasExtraService $deteccion,
        private readonly AuditoriaRepository        $auditoriaRepo
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function listar(?string $estado): array
    {
        $estado = in_array($estado, ['pendiente', 'aprobada', 'rechazada'], true) ? $estado : 'pendiente';
        $filas  = $this->solicitudesRepo->findAll('horas_extra', $estado, null, null, 'asistencia');
        foreach ($filas as &$f) {
            $asis = $this->asistenciaRepo->findByEmpleadoFecha((int)$f['id_empleado'], (string)$f['fecha_inicio']);
            $c    = $asis !== null
                ? $this->deteccion->clasificar((int)$f['id_empleado'], (string)$f['fecha_inicio'], (string)$asis['hora_entrada'], (string)$asis['hora_salida'])
                : null;
            $f['hora_entrada']      = $asis !== null ? substr((string)$asis['hora_entrada'], 0, 5) : '—';
            $f['hora_salida']       = $asis !== null ? substr((string)$asis['hora_salida'], 0, 5) : '—';
            $f['salida_horario']    = $this->deteccion->salidaProgramada((int)$f['id_empleado'], (string)$f['fecha_inicio']) ?? '—';
            $f['clasificacion']     = $c;
            $f['aviso_12h']         = $c !== null && $c['excede_12h'];
            $f['asistencia_cambio'] = $c !== null && abs($c['horas_extra'] - (float)$f['horas']) > 0.009;
            $salario                = $this->nominasRepo->salarioMensualEmpleado((int)$f['id_empleado']) ?? 0.0;
            $f['monto_estimado']    = $c !== null
                ? $this->nominas->lineaHorasExtra((string)$f['fecha_inicio'], (float)$f['horas'], $c['factor_extra'], $salario, false)['monto']
                : 0.0;
        }
        unset($f);
        return $filas;
    }

    public function aprobar(int $idSolicitud, float $horas, int $idUsuario, string $ip): void
    {
        $s = $this->solicitudesRepo->findById($idSolicitud);
        if ($s === null || ($s['origen'] ?? '') !== 'asistencia' || $s['tipo'] !== 'horas_extra') {
            throw new RuntimeException('Solicitud de horas extra detectadas no encontrada.');
        }
        if ($s['estado'] !== 'pendiente') {
            throw new RuntimeException('Solo se aprueban solicitudes pendientes.');
        }
        $idEmpleado = (int)$s['id_empleado'];
        $fecha      = (string)$s['fecha_inicio'];
        $asis = $this->asistenciaRepo->findByEmpleadoFecha($idEmpleado, $fecha);
        $c    = $asis !== null
            ? $this->deteccion->clasificar($idEmpleado, $fecha, (string)$asis['hora_entrada'], (string)$asis['hora_salida'])
            : null;
        if ($c === null || $c['horas_extra'] <= 0) {
            throw new RuntimeException('La asistencia de ese día ya no tiene horas extra; revísala.');
        }
        $horas = round($horas, 2);
        if ($horas <= 0 || $horas > $c['horas_extra']) {
            throw new InvalidArgumentException(sprintf('Puedes aprobar entre 0.01 y %s h (lo marcado).', number_format($c['horas_extra'], 2)));
        }

        [$idPeriodo, $nomina, $posterior] = $this->periodoDePago($idEmpleado, $fecha);

        $this->solicitudesRepo->resolver($idSolicitud, 'aprobada', $idUsuario, null);
        $clave = $c['factor_extra'] >= 2.0 ? 'factor_he_especial' : 'factor_he_ord';
        $idHe  = $this->horasExtraRepo->insert([
            'id_solicitud'   => $idSolicitud,
            'id_empleado'    => $idEmpleado,
            'id_periodo'     => $idPeriodo,
            'fecha'          => $fecha,
            'cantidad_horas' => $horas,
            'factor_recargo' => $c['factor_extra'],
            'id_parametro'   => $this->horasExtraRepo->findParametroId($clave, $fecha) ?? 1,
        ]);
        $this->auditoriaRepo->insert('UPDATE', $idUsuario, 'solicitudes', $idSolicitud, $ip);
        $this->auditoriaRepo->insert('INSERT', $idUsuario, 'horas_extra', $idHe, $ip);

        if ($nomina !== null && $nomina['estado'] === 'borrador') {
            $this->nominas->agregarHorasExtra((int)$nomina['id_nomina'], $fecha, $horas, $c['factor_extra'], $posterior, $idUsuario, $ip);
        }
    }

    /**
     * Periodo donde se pagan: el de la fecha si su nómina no existe o está en borrador;
     * si ya se aprobó o pagó, el siguiente periodo abierto.
     * @return array{0: int, 1: array<string, mixed>|null, 2: bool}
     */
    private function periodoDePago(int $idEmpleado, string $fecha): array
    {
        $periodo = $this->periodosRepo->findPorFecha($fecha);
        if ($periodo === null) {
            throw new RuntimeException('No hay periodo de pago para el ' . $fecha . '.');
        }
        $nomina = $this->nominasRepo->findCabeceraEmpleadoPeriodo($idEmpleado, (int)$periodo['id_periodo']);
        if ($nomina === null || $nomina['estado'] === 'borrador') {
            return [(int)$periodo['id_periodo'], $nomina, false];
        }
        $siguiente = $this->periodosRepo->findSiguienteAbierto((string)$periodo['fecha_fin']);
        if ($siguiente === null) {
            throw new RuntimeException('La nómina de ese periodo ya se cerró y no hay un periodo abierto siguiente para pagarlas.');
        }
        $idSig = (int)$siguiente['id_periodo'];
        return [$idSig, $this->nominasRepo->findCabeceraEmpleadoPeriodo($idEmpleado, $idSig), true];
    }
}
```

`SolicitudesService::rechazar`: antes de `resolverEstado`, si la solicitud es `origen = asistencia` y la observación está vacía:

```php
        $actual = $this->obtener($id);
        if (($actual['origen'] ?? '') === 'asistencia' && trim((string)$obs) === '') {
            throw new InvalidArgumentException('Escribe por qué se rechazan, para que el empleado lo vea.');
        }
```

(En `SolicitudesController::resolverAccion` capturar también `InvalidArgumentException` en el `catch`.)

`SolicitudesController`: constructor agrega `private readonly AprobacionHorasExtraService $aprobacion`; métodos:

```php
    public function detectadas(Request $request, Response $response): Response
    {
        $estado = (string)($request->getQueryParams()['estado'] ?? 'pendiente');
        return $this->twig->render($response, 'solicitudes/detectadas.html.twig', [
            'titulo'       => 'Horas extra detectadas',
            'filas'        => $this->aprobacion->listar($estado),
            'filtroEstado' => $estado,
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function aprobarDetectada(Request $request, Response $response, array $args): Response
    {
        $horas = (float)(((array)$request->getParsedBody())['horas'] ?? 0);
        try {
            $this->aprobacion->aprobar((int)$args['id'], $horas, $this->usuarioId($request), $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            $_SESSION['flash_success'] = 'Horas extra aprobadas.';
        } catch (InvalidArgumentException | RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $response->withHeader('Location', $this->urlFor($request, 'solicitudes.detectadas'))->withStatus(302);
    }
```

Rutas (dentro del grupo `/solicitudes`, **antes** de `/{id}/editar`):

```php
        $group->get('/horas-extra-detectadas',   [SolicitudesController::class, 'detectadas'])->setName('solicitudes.detectadas');
        $group->post('/{id}/aprobar-detectada',  [SolicitudesController::class, 'aprobarDetectada'])->setName('solicitudes.aprobarDetectada');
```

`dependencies.php`: registrar `AprobacionHorasExtraService` (8 argumentos en el orden del constructor) y pasarlo a `SolicitudesController`.

`templates/solicitudes/detectadas.html.twig`:

```twig
{% extends 'layouts/base.html.twig' %}
{% import 'partials/ui.html.twig' as ui %}

{% block titulo %}Horas extra detectadas{% endblock %}

{% block encabezado %}
{{ ui.encabezado_pagina('Horas extra detectadas', {
    ruta: [{texto: 'Solicitudes', url: url_for('solicitudes.index')}, {texto: 'Horas extra detectadas'}],
    conteo: filas|length
}) }}
{% endblock %}

{% block contenido %}
<form method="GET" class="mb-3">
    <select name="estado" class="form-select form-select-sm" style="max-width:220px" onchange="this.form.submit()">
        {% for e, t in {'pendiente': 'Pendientes', 'aprobada': 'Aprobadas', 'rechazada': 'Rechazadas'} %}
        <option value="{{ e }}" {{ filtroEstado == e ? 'selected' : '' }}>{{ t }}</option>
        {% endfor %}
    </select>
</form>

<div class="lb-tabla"><div class="table-responsive">
<table class="table table-hover align-middle mb-0">
    <thead><tr>
        <th>Empleado</th><th>Fecha</th><th>Horario / marcas</th><th>Día</th>
        <th class="text-end">Horas × factor</th><th class="text-end">Monto est.</th><th>Justificación</th><th></th>
    </tr></thead>
    <tbody>
    {% for f in filas %}
        <tr>
            <td class="fw-semibold">{{ f.apellidos }}, {{ f.nombre }}</td>
            <td>{{ f.fecha_inicio|date('d/m/Y') }}</td>
            <td class="small">Sale {{ f.salida_horario }} · marcó {{ f.hora_entrada }}–{{ f.hora_salida }}</td>
            <td>{{ ui.etiqueta((f.clasificacion.tipo_dia ?? '—')|capitalize, (f.clasificacion.tipo_dia ?? '') == 'normal' ? 'gris' : 'ambar') }}</td>
            <td class="text-end">{{ f.horas|number_format(2) }} h × {{ (f.clasificacion.factor_extra ?? 0)|number_format(2) }}</td>
            <td class="text-end">₡{{ f.monto_estimado|number_format(2, '.', ',') }}</td>
            <td class="small">
                {{ f.motivo }}
                {% if f.aviso_12h %}<div class="text-danger">⚠️ Excede la jornada máxima de 12 h (Art. 140)</div>{% endif %}
                {% if f.asistencia_cambio %}<div class="text-warning">⚠️ La asistencia cambió: hoy da {{ f.clasificacion.horas_extra|number_format(2) }} h</div>{% endif %}
            </td>
            <td class="text-end text-nowrap">
                {% if f.estado == 'pendiente' %}
                <form method="POST" action="{{ url_for('solicitudes.aprobarDetectada', {id: f.id_solicitud}) }}" class="d-inline-flex gap-1">
                    <input type="number" name="horas" step="0.01" min="0.01" max="{{ f.horas }}" value="{{ f.horas }}" class="form-control form-control-sm" style="width:80px">
                    <button class="btn btn-sm btn-success" title="Aprobar"><i class="bi bi-check-lg"></i></button>
                </form>
                <form method="POST" action="{{ url_for('solicitudes.rechazar', {id: f.id_solicitud}) }}" class="d-inline-flex gap-1 mt-1">
                    <input type="text" name="observacion" required maxlength="255" class="form-control form-control-sm" placeholder="Motivo del rechazo">
                    <button class="btn btn-sm btn-outline-danger" title="Rechazar"><i class="bi bi-x-lg"></i></button>
                </form>
                {% else %}{{ ui.estado(f.estado) }}{% endif %}
            </td>
        </tr>
    {% else %}
        <tr><td colspan="8" class="text-center text-muted py-4">No hay horas extra detectadas en este estado.</td></tr>
    {% endfor %}
    </tbody>
</table>
</div></div>
{% endblock %}
```

(Si la macro `ui.etiqueta` no tiene tono `ambar`, usar el tono de advertencia que exista en `partials/ui.html.twig`.) Tras rechazar desde esta vista, `SolicitudesController::rechazar` redirige a `solicitudes.index`; está bien (el flash informa).

`templates/solicitudes/index.html.twig`: agregar en las acciones del encabezado `{texto: 'Horas extra detectadas', icono: 'clock-history', tipo: 'secundario', url: url_for('solicitudes.detectadas')}`.

- [ ] **Step 5: Correr y commit**

Run: `vendor/bin/phpunit` → OK (actualizar `ValoresSinNuloTest`/`NominasServiceListadoTest` si usan los métodos tocados).

```bash
git add src/Services/AprobacionHorasExtraService.php src/Services/NominasService.php src/Services/SolicitudesService.php src/Repositories/PeriodosRepository.php src/Repositories/NominasRepository.php src/Controllers/SolicitudesController.php templates/solicitudes config/routes.php config/dependencies.php tests
git commit -m "feat(horas-extra): aprobación de horas detectadas y CCSS recalculada al cambiar líneas

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Adicional sencillo por feriado o descanso trabajado en la nómina

**Files:**
- Modify: `database/schema.sql` (`ingresos_nomina.tipo`), `database/migraciones/2026-10-17-horas-extra-por-horario.sql`
- Modify: `src/Repositories/NominasRepository.php` (`asistenciasDelPeriodo`, `horasExtraDelPeriodo` con fecha), `src/Services/NominasService.php`, `config/dependencies.php`
- Test: `tests/Services/NominasServiceGeneracionTest.php`

**Interfaces:**
- Consumes: `DeteccionHorasExtraService::clasificar` (Task 7); `NominaCalculadora::valorHora`; `NominasService::lineaHorasExtra` (Task 10).
- Produces:
  - `NominasRepository::asistenciasDelPeriodo(int $idEmpleado, int $idPeriodo): array` (filas `fecha, hora_entrada, hora_salida` con salida marcada)
  - `NominasRepository::periodo(int $idPeriodo): ?array` (`fecha_inicio, fecha_fin`)
  - `NominasService::__construct(NominasRepository, AuditoriaRepository, NominaCalculadora, ?DeteccionHorasExtraService $deteccion = null)`

- [ ] **Step 1: Esquema**

`schema.sql`: `` `tipo` ENUM('salario_base','horas_extra','bonificacion','feriado','descanso_trabajado','otro') NOT NULL, ``

Migración:

```sql
-- ── Task 11: adicional sencillo por descanso trabajado ────────
ALTER TABLE ingresos_nomina
    MODIFY `tipo` ENUM('salario_base','horas_extra','bonificacion','feriado','descanso_trabajado','otro') NOT NULL;
```

- [ ] **Step 2: Prueba que falla**

`tests/Services/NominasServiceGeneracionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\NominaCalculadora;
use App\Repositories\AuditoriaRepository;
use App\Repositories\NominasRepository;
use App\Services\DeteccionHorasExtraService;
use App\Services\NominasService;
use PHPUnit\Framework\TestCase;

final class NominasServiceGeneracionTest extends TestCase
{
    public function testFeriadoTrabajadoAgregaAdicionalSencilloYCotiza(): void
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('existePeriodo')->willReturn(true);
        $repo->method('periodo')->willReturn(['fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-15']);
        $repo->method('empleadosActivosSinNomina')->willReturn([['id_empleado' => 5, 'salario_mensual' => '480000']]);
        $repo->method('horasExtraDelPeriodo')->willReturn([]);
        $repo->method('asistenciasDelPeriodo')->willReturn([
            ['fecha' => '2026-09-15', 'hora_entrada' => '08:00:00', 'hora_salida' => '17:00:00'],
        ]);
        $deteccion = $this->createMock(DeteccionHorasExtraService::class);
        $deteccion->method('clasificar')->willReturn([
            'tipo_dia' => 'feriado', 'horas_dia_especial' => 8.0, 'horas_extra' => 0.0, 'factor_extra' => 3.0,
            'excede_12h' => false, 'horas_trabajadas' => 9.0, 'horas_ordinarias' => 9.0,
        ]);

        $ingresos = [];
        $repo->expects(self::once())->method('insertNominaCompleta')
            ->willReturnCallback(function (array $cab, array $i) use (&$ingresos) { $ingresos = $i; return 1; });

        (new NominasService($repo, $this->createMock(AuditoriaRepository::class), new NominaCalculadora(0.1083, 0.2683), $deteccion))
            ->generarPeriodo(9, 1, 'ip');

        $feriado = array_values(array_filter($ingresos, fn ($i) => $i['tipo'] === 'feriado'));
        self::assertCount(1, $feriado);
        self::assertSame(16000.0, $feriado[0]['monto']);              // 8 h × (480 000 / 240) × 1
        self::assertStringContainsString('Feriado trabajado 15/09/2026', $feriado[0]['descripcion']);
    }

    public function testHorasExtraDeOtraFechaSeMarcanComoPagoPosterior(): void
    {
        $repo = $this->createMock(NominasRepository::class);
        $repo->method('existePeriodo')->willReturn(true);
        $repo->method('periodo')->willReturn(['fecha_inicio' => '2026-10-16', 'fecha_fin' => '2026-10-31']);
        $repo->method('empleadosActivosSinNomina')->willReturn([['id_empleado' => 5, 'salario_mensual' => '480000']]);
        $repo->method('horasExtraDelPeriodo')->willReturn([['fecha' => '2026-10-14', 'cantidad_horas' => '2.00', 'factor_recargo' => '1.50']]);
        $repo->method('asistenciasDelPeriodo')->willReturn([]);
        $ingresos = [];
        $repo->method('insertNominaCompleta')->willReturnCallback(function (array $cab, array $i) use (&$ingresos) { $ingresos = $i; return 1; });

        (new NominasService($repo, $this->createMock(AuditoriaRepository::class), new NominaCalculadora(0.1083, 0.2683),
            $this->createMock(DeteccionHorasExtraService::class)))->generarPeriodo(10, 1, 'ip');

        $he = array_values(array_filter($ingresos, fn ($i) => $i['tipo'] === 'horas_extra'));
        self::assertStringContainsString('(pago posterior)', $he[0]['descripcion']);
    }
}
```

Run → FAIL.

- [ ] **Step 3: Implementar**

`NominasRepository`:

```php
    /** Asistencias con salida marcada del empleado en el periodo. @return array<int, array<string, mixed>> */
    public function asistenciasDelPeriodo(int $idEmpleado, int $idPeriodo): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT fecha, hora_entrada, hora_salida FROM asistencia
              WHERE id_empleado = :e AND id_periodo = :p AND hora_salida <> '00:00:00'
              ORDER BY fecha"
        );
        $stmt->execute([':e' => $idEmpleado, ':p' => $idPeriodo]);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function periodo(int $idPeriodo): ?array
    {
        $stmt = $this->pdo->prepare('SELECT fecha_inicio, fecha_fin FROM periodos_pago WHERE id_periodo = :p LIMIT 1');
        $stmt->execute([':p' => $idPeriodo]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }
```

`NominasService`:
- Constructor: agregar `private readonly ?DeteccionHorasExtraService $deteccion = null` al final.
- `generarPeriodo`: después de validar el periodo, `$periodo = $this->repo->periodo($idPeriodo);` y pasarlo a `generarUno($idEmpleado, $idPeriodo, $salario, $periodo)`.
- `generarUno(int $idEmpleado, int $idPeriodo, float $salarioMensual, array $periodo)`: reemplazar el `foreach` de horas extra por:

```php
        foreach ($this->repo->horasExtraDelPeriodo($idEmpleado, $idPeriodo) as $he) {
            $fecha     = (string)$he['fecha'];
            $posterior = $fecha < (string)$periodo['fecha_inicio'] || $fecha > (string)$periodo['fecha_fin'];
            $ingresos[] = $this->lineaHorasExtra($fecha, (float)$he['cantidad_horas'], (float)$he['factor_recargo'], $salarioMensual, $posterior);
        }
        foreach ($this->adicionalesDiaEspecial($idEmpleado, $idPeriodo, $salarioMensual) as $linea) {
            $ingresos[] = $linea;
        }
```

- Método nuevo:

```php
    /**
     * Adicional sencillo por cada feriado o día de descanso trabajado (Arts. 149 y 152; con salario
     * mensual el pago sencillo ya está en el salario y se agrega el otro para completar el doble).
     * @return array<int, array{tipo: string, descripcion: string, monto: float}>
     */
    private function adicionalesDiaEspecial(int $idEmpleado, int $idPeriodo, float $salarioMensual): array
    {
        if ($this->deteccion === null) {
            return [];
        }
        $lineas = [];
        foreach ($this->repo->asistenciasDelPeriodo($idEmpleado, $idPeriodo) as $a) {
            $c = $this->deteccion->clasificar($idEmpleado, (string)$a['fecha'], (string)$a['hora_entrada'], (string)$a['hora_salida']);
            if ($c === null || $c['horas_dia_especial'] <= 0) {
                continue;
            }
            $feriado  = $c['tipo_dia'] === 'feriado';
            $fecha    = (new \DateTimeImmutable((string)$a['fecha']))->format('d/m/Y');
            $lineas[] = [
                'tipo'        => $feriado ? 'feriado' : 'descanso_trabajado',
                'descripcion' => sprintf('%s %s: %s h', $feriado ? 'Feriado trabajado' : 'Día de descanso trabajado', $fecha, number_format($c['horas_dia_especial'], 2)),
                'monto'       => round($c['horas_dia_especial'] * $this->calc->valorHora($salarioMensual), 2),
            ];
        }
        return $lineas;
    }
```

- `dependencies.php`: pasar `$c->get(\App\Services\DeteccionHorasExtraService::class)` a `NominasService`.

Run: `vendor/bin/phpunit` → OK.

- [ ] **Step 4: Aplicar migración (sección Task 11) y commit**

```bash
git add database/schema.sql database/migraciones/2026-10-17-horas-extra-por-horario.sql src/Repositories/NominasRepository.php src/Services/NominasService.php config/dependencies.php tests/Services/NominasServiceGeneracionTest.php
git commit -m "feat(nominas): adicional sencillo por feriado o descanso trabajado

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Datos de demostración, prueba de punta a punta y documentación

**Files:**
- Modify: `database/demo.php`
- Modify: `CLAUDE.md` (tabla de módulos: Horarios; grupo 7 ya no dice "sin código")
- Modify: `docs/superpowers/specs/2026-10-17-horas-extra-por-horario-design.md` (estado: implementado)

**Interfaces:**
- Consumes: todo lo anterior.
- Produces: base de demostración con horario, horas extra detectadas (aprobada, pendiente, por justificar, rechazada) y un feriado trabajado.

- [ ] **Step 1: Horario de la demo**

Reemplazar en `demo.php` el INSERT de `horarios` (sección 1) por:

```php
q($pdo, "INSERT INTO horarios (nombre, dias_semana) VALUES ('Taller L-V y sábado medio día', 'L-V + S medio día')");
$horario = (int)$pdo->lastInsertId();
foreach ([1, 2, 3, 4, 5] as $dia) {
    q($pdo, "INSERT INTO horario_dias (id_horario, dia_semana, hora_entrada, hora_salida, minutos_almuerzo) VALUES (?, ?, '08:00:00', '17:00:00', 60)", [$horario, $dia]);
}
q($pdo, "INSERT INTO horario_dias (id_horario, dia_semana, hora_entrada, hora_salida, minutos_almuerzo) VALUES (?, 6, '08:00:00', '12:00:00', 0)", [$horario]);
```

- [ ] **Step 2: Horas extra desde la asistencia**

- En la lista `$extras` de la sección 5, dejar solo la de julio (`['Luis', '2026-07-10', 3, ...]`) como registro manual del admin (es anterior a la asistencia de la demo). Las de agosto a octubre se quitan de ahí.
- Después del bucle de asistencia (sección 6), agregar:

```php
paso('Horas extra detectadas por la asistencia');
$det = $c->get(App\Services\DeteccionHorasExtraService::class);
$apr = $c->get(App\Services\AprobacionHorasExtraService::class);
$solRepo = $c->get(App\Repositories\SolicitudesRepository::class);
$detectadas = [ // empleado, fecha, opción, motivo, acción del admin
    ['Juan',  '2026-08-22', 'horas_extra', 'Cierre de reparación de motor',      'aprobar'],
    ['María', '2026-09-15', 'horas_extra', 'Atención de clientes en feriado',     'aprobar'],
    ['Luis',  '2026-10-06', 'horas_extra', 'Diagnóstico eléctrico pendiente',    'pendiente'],
    ['Juan',  '2026-10-09', 'despues',     '',                                   'pendiente'],
    ['Luis',  '2026-09-03', 'horas_extra', 'Ordenar la bodega',                  'rechazar'],
];
foreach ($detectadas as [$n, $f, $op, $m, $accion]) {
    $a = $asis->findByEmpleadoFecha($emp[$n], $f);
    if ($a === null) { continue; }
    $det->registrar($emp[$n], $f, (string)$a['hora_entrada'], (string)$a['hora_salida'], $op, $m, $usrDaniela, $IP);
    $s = $solRepo->findDetectada($emp[$n], $f);
    if ($s === null) { continue; }
    if ($accion === 'aprobar')  { $apr->aprobar((int)$s['id_solicitud'], (float)$s['horas'], $usrDaniela, $IP); }
    if ($accion === 'rechazar') { $sol->rechazar((int)$s['id_solicitud'], $usrDaniela, $IP, 'Ordenar la bodega no requiere horas extra.'); }
}
```

- En el bucle de asistencia, `$extraPorDia` debe incluir estas fechas para que la salida marcada sea tarde: construirlo desde `$detectadas` además de `$extras` (`foreach ($detectadas as [$n, $f]) { $extraPorDia[$emp[$n]][$f] = 2; }`). Para el feriado del 15/09 de María, la entrada 08:00 y salida 08:00 + horas × 60 ya produce horas especiales; ajustar ese caso a `$salida = 19 * 60` para que tenga 2 h extra a ×3.
- El 2026-10-09 (Juan, "despues") queda por justificar dentro del periodo abierto; el resto queda en periodos que luego se cierran (sus `por_justificar`, si hubiera, se rechazan solos al cerrar).
- Mover la creación de `$detectadas` antes del bucle de asistencia (para poder usar `$extraPorDia`), y el registro/aprobación después del bucle.
- Quitar la solicitud manual de `horas_extra` de María del 2026-10-17 (sección 5: `$sol->crear([... 'tipo' => 'horas_extra' ...])`): ya no se crean a mano.
- Al cerrar los periodos en la sección 7 se hace con `UPDATE periodos_pago SET estado = 'cerrado'` directo; cambiarlo a `$c->get(App\Services\PeriodosService::class)->actualizar($id, ['fecha_inicio' => $p['fecha_inicio'], 'fecha_fin' => $p['fecha_fin'], 'estado' => 'cerrado'], $usrDaniela, $IP);` para que aplique el rechazo automático.

- [ ] **Step 3: Regenerar la base y verificar**

Run:
```bash
"/c/xampp/mysql/bin/mysql.exe" -u root -e "DROP DATABASE IF EXISTS lubrimotos_nomina; CREATE DATABASE lubrimotos_nomina CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
"/c/xampp/mysql/bin/mysql.exe" -u root lubrimotos_nomina < database/schema.sql
"/c/xampp/mysql/bin/mysql.exe" -u root lubrimotos_nomina < database/seed.sql
php database/demo.php
"/c/xampp/mysql/bin/mysql.exe" -u root lubrimotos_nomina -e "SELECT estado, COUNT(*) FROM solicitudes WHERE origen='asistencia' GROUP BY estado; SELECT fecha, cantidad_horas, factor_recargo FROM horas_extra ORDER BY fecha; SELECT tipo, descripcion, monto FROM ingresos_nomina WHERE tipo IN ('feriado','descanso_trabajado','horas_extra');"
```
Expected:
- solicitudes con `origen = asistencia`: `aprobada` 2 (Juan 22/08, María 15/09), `pendiente` 1 (Luis 06/10), `por_justificar` 1 (Juan 09/10), `rechazada` 1 (Luis 03/09);
- `horas_extra` del 2026-09-15 con `factor_recargo = 3.00` y la del 2026-08-22 con `1.50`;
- un ingreso tipo `feriado` "Feriado trabajado 15/09/2026" en la nómina de María de la 1.ª quincena de setiembre.

⚠️ Antes del DROP, confirmar con Gabriel que se puede regenerar la base local (borra cualquier dato cargado a mano).

- [ ] **Step 4: Prueba manual de punta a punta (XAMPP)**

1. Como `superadmin`: Mantenimientos → Horarios → ver el horario del taller.
2. Como empleado con horario: en la base, poner su `hora_entrada` de hoy a las 06:00 y temporalmente la salida del horario de hoy a una hora ya pasada; en Mi asistencia marcar salida → aparece el aviso → "Trabajé horas extra" con texto.
3. Como `admin`: Solicitudes → Horas extra detectadas → ver horario, marcas, factor, monto y justificación → aprobar 1 h.
4. Nóminas → periodo actual → ver la línea "Horas extra dd/mm/aaaa: 1.00 h × 1.50" y la CCSS recalculada.
5. Restaurar el horario de hoy.

- [ ] **Step 5: Documentación y commit**

`CLAUDE.md`:
- En la nota del Grupo 7, cambiar "Departamentos, Horarios y ParametrosLegales siguen solo en el esquema" por "Horarios tiene código completo (17/10/2026, ver `docs/superpowers/specs/2026-10-17-horas-extra-por-horario-design.md`); Departamentos y ParametrosLegales siguen solo en el esquema".
- En la tabla de `horarios`: "Catálogo de horarios; los días y horas (con minutos de almuerzo) están en `horario_dias`. Un día sin fila es descanso."
- Agregar fila 15 a la tabla de módulos: `| 15 | Horarios + horas extra detectadas | feature/horas-extra-por-horario | ✅ Completo (pendiente prueba manual) |`.

Spec: cambiar `**Estado:**` a "implementado (17/10/2026)".

```bash
git add database/demo.php CLAUDE.md docs/superpowers/specs/2026-10-17-horas-extra-por-horario-design.md
git commit -m "feat(datos): demo con horario del taller y horas extra detectadas

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
