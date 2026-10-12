# Diseño — Módulo Contratos

- **Rama:** `feature/contratos-module` (a partir de `develop`)
- **Fecha:** 2026-09-14
- **Rol:** Módulo nuevo completo (Repository → Service → Controller → rutas → plantillas). La tabla `contratos` ya existe en `database/schema.sql` (Grupo 7, agregada 23/08/2026) — **cero cambios de DDL**, solo el código PHP que faltaba.
- **Patrón:** Controller → Service → Repository, copiando la estructura de referencia del módulo `Puestos` (CLAUDE.md), con el añadido de una acción de negocio propia ("Renovar").
- **Origen:** Retroalimentación del tutor Braulio Sandí Morales (primera solicitud de esta serie de cambios): "los contratos con los nuevos empleados" no existían en el sistema. El esquema se preparó en Grupo 7 pero CLAUDE.md dejó el código explícitamente pendiente para la fase de "Desarrollo del sistema".
- **Decisiones confirmadas contigo (2026-09-14):**
  1. RBAC: `/contratos` exige **`super_admin`** (no `admin`) — igual que Puestos/Períodos/Feriados/Usuarios.
  2. Renovación: acción **"Renovar" dedicada** — cierra el contrato vigente y abre uno nuevo en una transacción; el Service impide más de un contrato `activo` por empleado a la vez.

## 0. Contexto y motivo

`contratos` (`id_contrato` PK, FK a `empleados`, 1:N — permite historial con renovaciones) existe en el esquema desde el 23/08/2026 pero nunca tuvo Repository/Service/Controller/vistas. Es un registro operativo por empleado (como Empleados, Asistencia, etc.), no un catálogo de configuración — pero por decisión tuya queda bajo `super_admin`, igual que Usuarios y los catálogos de `/mantenimientos`.

Columnas de `contratos` (sin cambios, ya en `schema.sql`):

```sql
CREATE TABLE IF NOT EXISTS `contratos` (
    `id_contrato`     INT            NOT NULL AUTO_INCREMENT,
    `id_empleado`     INT            NOT NULL,
    `tipo_contrato`   ENUM('tiempo_indefinido','plazo_fijo','obra_determinada') NOT NULL,
    `salario_pactado` DECIMAL(10,2)  NOT NULL,
    `jornada`         ENUM('tiempo_completo','medio_tiempo') NOT NULL DEFAULT 'tiempo_completo',
    `fecha_inicio`    DATE           NOT NULL,
    `fecha_fin` DATE NOT NULL DEFAULT '9999-12-31'  -- 9999-12-31 = contrato aún vigente
    `estado`          ENUM('activo','finalizado') NOT NULL DEFAULT 'activo',
    PRIMARY KEY (`id_contrato`),
    CONSTRAINT `fk_contratos_empleado` FOREIGN KEY (`id_empleado`) REFERENCES `empleados` (`id_empleado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

No hay `UNIQUE` sobre `id_empleado`: la BD permite varios contratos por empleado (historial), pero nada impide hoy que existan dos `estado='activo'` simultáneos para el mismo empleado — esa invariante la impone el **Service**, no la BD (igual que otras reglas de negocio del proyecto, ej. "no puede quedar cero super_admin activos").

`salario_pactado` es independiente de `puestos.salario_base`: registra lo realmente pactado en ese contrato (puede diferir del salario base del puesto). **No se usa para calcular nóminas** — Nóminas/Liquidación siguen leyendo `puestos.salario_base` como hoy; conectar `contratos.salario_pactado` al cálculo de planilla es un cambio de alcance mucho mayor, no pedido, y queda fuera de este documento.

## 1. Alcance

Archivos nuevos:
1. `src/Repositories/ContratosRepository.php`
2. `src/Services/ContratosService.php`
3. `src/Controllers/ContratosController.php`
4. `templates/contratos/index.html.twig`
5. `templates/contratos/form.html.twig`
6. `tests/Services/ContratosServiceTest.php`

Archivos modificados:
7. `config/dependencies.php` — registrar Repository/Service/Controller.
8. `config/routes.php` — nuevo bloque de rutas dentro del grupo `/mantenimientos`.
9. `templates/layouts/base.html.twig` — link "Contratos" en el grupo "Administración" (solo `super_admin`).
10. `database/seed.sql` — un contrato activo de ejemplo por cada uno de los 3 empleados sembrados.
11. `CLAUDE.md` — marcar el código de Contratos como construido (Grupo 7), actualizar la nota de RBAC y la tabla de "Módulos — estado de desarrollo".

**Fuera de alcance (YAGNI), confirmado por el patrón ya establecido en este proyecto:**
- Departamentos, Horarios, ParametrosLegales — los otros 3 catálogos de Grupo 7 siguen sin código propio; no se tocan aquí.
- `id_horario` en `empleados` sigue hardcodeado a `1` (sentinela) — no se conecta con Contratos.
- Conectar `contratos.salario_pactado`/`tipo_contrato`/`jornada` al cálculo de Nóminas, Aguinaldo o Liquidación — estos siguen usando `puestos.salario_base` sin cambios.
- Ningún cambio en `EmpleadosController`/`EmpleadosService`/formulario de Empleados — Contratos es un módulo 100% independiente, alcanzable solo por su propia navegación y rutas.
- Alertas o notificaciones automáticas de "contrato por vencer" — no se pidió, y `fecha_fin` sentinela (`9999-12-31`) para contratos indefinidos hace que "vencimiento" ni siquiera aplique a la mayoría.
- Adjuntar el documento PDF/escaneado del contrato firmado — no hay manejo de archivos en ningún otro módulo del sistema; agregar upload de archivos sería una capacidad nueva no pedida.

## 2. Rutas y RBAC

Se anida dentro del grupo `/mantenimientos` ya existente (mismo middleware `RoleMiddleware('super_admin')`, mismo patrón visual en el sidebar bajo "Administración"), como una sección más junto a Puestos/Períodos/Feriados/Usuarios:

```php
// config/routes.php — dentro de $app->group('/mantenimientos', function (RouteCollectorProxy $group) { ... })

// Contratos
$group->get('/contratos',                [ContratosController::class, 'index'])->setName('contratos.index');
$group->get('/contratos/crear',          [ContratosController::class, 'create'])->setName('contratos.create');
$group->post('/contratos/crear',         [ContratosController::class, 'store'])->setName('contratos.store');
$group->get('/contratos/{id}/editar',    [ContratosController::class, 'edit'])->setName('contratos.edit');
$group->post('/contratos/{id}/editar',   [ContratosController::class, 'update'])->setName('contratos.update');
$group->post('/contratos/{id}/renovar',  [ContratosController::class, 'renovar'])->setName('contratos.renovar');
$group->post('/contratos/{id}/eliminar', [ContratosController::class, 'destroy'])->setName('contratos.destroy');
```

Agregar `use App\Controllers\ContratosController;` al bloque de `use` al inicio del archivo. El comentario del grupo pasa de:
```php
// ── Mantenimientos (solo super_admin) ─────────────────
```
a listar explícitamente el módulo nuevo, igual que ya documenta el bloque de `usuarios`.

**No** se crea un grupo `/contratos` separado — usar el grupo existente evita duplicar `->add(new RoleMiddleware('super_admin'))->add(new AuthMiddleware())` y refleja que, en la navegación, ya vive junto a los otros módulos de "Administración".

## 3. `src/Repositories/ContratosRepository.php`

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

## 4. `src/Services/ContratosService.php`

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

Nota sobre `renovar()` y auditoría: `auditoria.accion` es `ENUM('INSERT','UPDATE','DELETE','LOGIN','LOGOUT')` — no tiene un valor `'RENOVAR'` propio y agregarlo sería un cambio de esquema no pedido. Una renovación se registra fielmente como lo que realmente son dos escrituras: un `UPDATE` (cierre del contrato viejo) y un `INSERT` (alta del nuevo), cada una con su propio `id_registro` y un `detalle` que las distingue.

## 5. `src/Controllers/ContratosController.php`

Mismo patrón que `PuestosController` (helpers `usuarioId`/`urlFor`/`redirect`/`consumeFlash` como en `EmpleadosController`/`SolicitudesController`, ya que Contratos sí necesita `usuarioId()` para auditoría):

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

    // ── POST /mantenimientos/contratos/{id}/renovar ───────
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

## 6. `config/dependencies.php`

Tres bloques nuevos, siguiendo el mismo estilo que `PuestosRepository`/`PuestosService`/`PuestosController`:

```php
// Junto a PuestosRepository (bloque de Repositories):
\App\Repositories\ContratosRepository::class => function (ContainerInterface $c) {
    return new \App\Repositories\ContratosRepository($c->get(PDO::class));
},

// Junto a PuestosService (bloque de Services):
\App\Services\ContratosService::class => function (ContainerInterface $c) {
    return new \App\Services\ContratosService(
        $c->get(\App\Repositories\ContratosRepository::class),
        $c->get(\App\Repositories\AuditoriaRepository::class)
    );
},

// Junto a PuestosController (bloque de Controllers):
\App\Controllers\ContratosController::class => function (ContainerInterface $c) {
    return new \App\Controllers\ContratosController(
        $c->get(Twig::class),
        $c->get(\App\Services\ContratosService::class)
    );
},
```

## 7. Plantillas

### 7.1 `templates/contratos/index.html.twig`

Mismo esqueleto que `templates/puestos/index.html.twig` (breadcrumb Dashboard → Mantenimientos → Contratos, buscador `?q=`, tabla, modal de confirmación de borrado), con estas diferencias:

- Columnas: Empleado (`per.nombre` + `per.apellidos`), Tipo de contrato (badge por `tipo_contrato`), Salario pactado (₡, `number_format`), Jornada, Fecha inicio, Fecha fin (mostrar **"Vigente"** en vez de la fecha literal cuando `fecha_fin == '9999-12-31'`), Estado (badge verde `activo` / gris `finalizado`), Acciones.
- Filtros adicionales en la barra de búsqueda: `<select name="estado">` (Todos/Activo/Finalizado) y `<select name="tipo">` (Todos/Indefinido/Plazo fijo/Obra determinada), como `<select>` de envío automático (`onchange="this.form.submit()"`) — mismo patrón ya usado en `templates/asistencia/index.html.twig` o `templates/solicitudes/index.html.twig` para sus filtros de período/estado (revisar cuál usa exactamente ese patrón antes de copiar).
- Botón **"Renovar"** (ícono `bi-arrow-repeat`) visible solo cuando `contrato.estado == 'activo'`, junto a Editar/Eliminar. Abre `#modalRenovar` (ver abajo) — igual que Puestos abre `#modalEliminar`, o Solicitudes abre `#modalResolver`.
- Modal `#modalRenovar`: formulario POST a `/mantenimientos/contratos/{id}/renovar`, con los 4 campos del nuevo contrato (tipo_contrato, salario_pactado, jornada, fecha_inicio) — mismos `<select>`/`<input>` que en `form.html.twig` (sección 7.2), pero dentro del modal en vez de una página aparte. El JS del modal (`show.bs.modal`) fija la `action` del `<form>` con el `id_contrato` del botón que lo abrió, igual que `modalEliminar` en Puestos.
- Modal `#modalEliminar`: igual que Puestos.

### 7.2 `templates/contratos/form.html.twig`

Mismo esqueleto que `templates/puestos/form.html.twig` (card centrada, errores arriba, botones Cancelar/Guardar), con estos campos:

- **`id_empleado`** (`<select>`, solo en `accion == 'crear'`; en `editar` se muestra el nombre del empleado como texto fijo, no editable — `contrato.nombre ~ ' ' ~ contrato.apellidos`) — poblado desde `empleados` (pasado por el Controller).
- **`tipo_contrato`** (`<select>`: Tiempo indefinido / Plazo fijo / Obra determinada).
- **`salario_pactado`** (`<input type="number" step="0.01" min="0.01">`, con `₡` como en el de Puestos).
- **`jornada`** (`<select>`: Tiempo completo / Medio tiempo, default tiempo completo).
- **`fecha_inicio`** (`<input type="date">`).

No incluye `estado`/`fecha_fin` — esos campos nunca se editan directamente desde este formulario (ver sección 4, `actualizar()` / regla de negocio).

## 8. Navegación — `templates/layouts/base.html.twig`

Agregar el link dentro del bloque `{% if session.usuario_rol == 'super_admin' %}` (línea ~64-72), junto a Puestos/Períodos/Feriados/Usuarios:

```twig
{% if session.usuario_rol == 'super_admin' %}
<div class="sb-group">
    <div class="sb-label">Administración</div>
    <a class="sb-item" href="{{ url_for('puestos.index') }}"><i class="bi bi-briefcase"></i><span>Puestos</span></a>
    <a class="sb-item" href="{{ url_for('periodos.index') }}"><i class="bi bi-calendar-range"></i><span>Períodos de Pago</span></a>
    <a class="sb-item" href="{{ url_for('feriados.index') }}"><i class="bi bi-calendar-x"></i><span>Feriados</span></a>
    <a class="sb-item" href="{{ url_for('contratos.index') }}"><i class="bi bi-file-earmark-text"></i><span>Contratos</span></a>
    <a class="sb-item" href="{{ url_for('usuarios.index') }}"><i class="bi bi-person-gear"></i><span>Usuarios</span></a>
</div>
{% endif %}
```

(Colocado antes de Usuarios para mantener el orden temático "catálogos → registros → cuentas"; el orden exacto no es crítico.)

## 9. `database/seed.sql` — datos de prueba

Un contrato activo por cada uno de los 3 empleados ya sembrados (id_empleado 1=Emerson/Administrador, 2=Juan/Mecánico General, 3=María/Mecánico General), con `fecha_inicio = fecha_ingreso` del empleado y `salario_pactado` igual al `salario_base` de su puesto:

```sql
-- ── Contratos ─────────────────────────────────────────────
INSERT INTO `contratos` (`id_empleado`, `tipo_contrato`, `salario_pactado`, `jornada`, `fecha_inicio`, `fecha_fin`, `estado`) VALUES
(1, 'tiempo_indefinido', 750000.00, 'tiempo_completo', '2015-01-10', '9999-12-31', 'activo'),
(2, 'tiempo_indefinido', 450000.00, 'tiempo_completo', '2020-03-01', '9999-12-31', 'activo'),
(3, 'plazo_fijo',         450000.00, 'tiempo_completo', '2021-06-15', '9999-12-31', 'activo');
```

Colocar este bloque después de `-- ── Empleados ──` y antes de `-- ── Feriados 2026 ──` (mismo orden que respeta FKs: `empleados` ya existe cuando se insertan `contratos`).

## 10. `CLAUDE.md` — actualizaciones documentales

1. **Grupo 7** (nota existente, editar la primera oración): de "Solo existe en `database/schema.sql`... el código PHP... de estos 4 módulos todavía no está construido" a algo como: "Contratos ya tiene código completo (Repository/Service/Controller/vistas, 14/09/2026) — ver `docs/superpowers/specs/2026-09-14-contratos-module-design.md`. Departamentos, Horarios y ParametrosLegales siguen solo en el esquema."
2. **Sección RBAC**, línea `Solo el grupo /mantenimientos (Puestos, Períodos, Feriados, Usuarios) exige super_admin`: agregar Contratos a esa lista (y en la nota del tutor más abajo, que dice "Controla exclusivamente Usuarios y los catálogos del grupo /mantenimientos (Puestos, Períodos, Feriados)").
3. **Tabla "Módulos — estado de desarrollo"**: agregar fila `14 | Gestionar Contratos | feature/contratos-module | ✅ Completo (pendiente prueba manual)`.

## 11. Pruebas nuevas — `tests/Services/ContratosServiceTest.php`

Mockeando `ContratosRepository`/`AuditoriaRepository` (mismo estilo que `tests/Services/EmpleadosServicePersonaMigrationTest.php` u otro Service test existente — revisar cuál mockea con PHPUnit stubs/mocks antes de escribir, para no reinventar el estilo):

- `crear()` arma correctamente el array a insertar (`estado='activo'`, `fecha_fin='9999-12-31'`) y llama `auditoriaRepo->insert('INSERT', ...)`.
- `crear()` rechaza `id_empleado` inexistente/inactivo sin llamar `repo->insert()`.
- `crear()` rechaza un empleado que ya tiene un contrato `activo` (mockear `findActivoPorEmpleado()` devolviendo una fila) — mensaje debe mencionar "Renovar".
- `crear()` rechaza `tipo_contrato`/`jornada` fuera de las listas válidas, y `salario_pactado <= 0`.
- `actualizar()` lanza `RuntimeException` si el contrato no existe (antes de validar campos).
- `renovar()` lanza `RuntimeException` si el contrato no está `activo` (ya finalizado).
- `renovar()` rechaza `fecha_inicio` del nuevo contrato que no sea estrictamente posterior a la del contrato actual.
- `renovar()` en el camino feliz: llama `repo->renovar(idActual, idEmpleado, fechaFinCalculada, camposNuevos)` con `fechaFinCalculada` = un día antes de la nueva `fecha_inicio`, y hace **dos** llamadas a `auditoriaRepo->insert()` (una `UPDATE` sobre el id actual, una `INSERT` sobre el id nuevo).
- `eliminar()` llama `repo->delete()` y audita `DELETE`; lanza `RuntimeException` si no existe.

## 12. Convenciones

`RouteContext::fromRequest(...)->getRouteParser()->urlFor()` para todos los redirects (igual que el resto del proyecto). `declare(strict_types=1)` en todo archivo nuevo. Nombres de columnas `snake_case`, clases `PascalCase`, métodos `camelCase`. El Controller nunca toca PDO ni el Repository directamente — solo el Service.
