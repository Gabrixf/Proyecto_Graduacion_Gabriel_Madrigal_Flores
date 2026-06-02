# Empleados Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Empleados CRUD module (employee records + embedded bank account + optional user-account link + soft-delete), following the project's strict Controller → Service → Repository pattern.

**Architecture:** Controller handles HTTP only and passes `loggedInId`/`ip` to the Service. The Service holds all validation and business rules and orchestrates reads/writes. The Repository contains raw PDO SQL, including a transaction spanning `empleados` + `datos_bancarios`. Twig templates extend `layouts/base.html.twig`. All writes are audited via `AuditoriaRepository`.

**Tech Stack:** PHP 8.1, Slim 4, raw PDO (MySQL 8), Twig 3, PHP-DI 7, Bootstrap 5 (CDN).

**Testing note:** This project has no automated test suite (`tests/` is empty; the design spec defers PHPUnit tests to a future branch). Verification in this plan uses `php -l` syntax linting plus a manual smoke-test checklist (Task 8), matching how the Puestos/Feriados modules were built. Validation helpers in the Service are written as pure functions to make future unit testing easy.

**Reference files (read before starting):**
- `src/Repositories/PuestosRepository.php`, `src/Repositories/AuditoriaRepository.php`
- `src/Services/FeriadosService.php` (audit + `loggedInId`/`ip` pattern)
- `src/Controllers/FeriadosController.php`
- `templates/feriados/index.html.twig`, `templates/feriados/form.html.twig`
- `config/dependencies.php`, `config/routes.php`, `templates/layouts/base.html.twig`
- Design spec: `docs/superpowers/specs/2026-06-01-empleados-design.md`

**Schema reference (from `database/schema.sql`):**
- `empleados` insertable columns: `id_puesto, id_usuario, nombre, apellidos, cedula, fecha_nacimiento, genero, estado_civil, nacionalidad, telefono, correo, direccion, provincia, canton, distrito, telefono_emergencia, nombre_contacto_emergencia, numero_asegurado_ccss, numero_poliza_ins, fecha_ingreso, fecha_salida, estado`
- `datos_bancarios` insertable columns: `id_empleado, banco, tipo_cuenta, numero_cuenta, numero_cuenta_iban, moneda, activa`
- `usuarios`: `id_usuario, nombre_usuario, rol` (rol ENUM `admin`/`empleado`)
- ENUMs: `genero('masculino','femenino','otro')`, `estado_civil('soltero','casado','union_libre','divorciado','viudo')`, `empleados.estado('activo','inactivo')`, `tipo_cuenta('corriente','ahorros')`, `moneda('CRC','USD')`

---

## File Structure

| File | Responsibility |
|---|---|
| `src/Repositories/EmpleadosRepository.php` | All SQL for `empleados` + `datos_bancarios`; transaction; dropdown reads. |
| `src/Services/EmpleadosService.php` | Validation, cédula normalization, business rules, audit calls. |
| `src/Controllers/EmpleadosController.php` | HTTP parse/render/redirect; passes session id + IP to Service. |
| `templates/empleados/index.html.twig` | Employee list with estado filter + deactivate modal. |
| `templates/empleados/form.html.twig` | Create/edit form, 8 fieldsets. |
| `config/dependencies.php` | Register Repository → Service → Controller. |
| `config/routes.php` | Fill the `/empleados` route group. |
| `templates/layouts/base.html.twig` | Point the navbar "Empleados" link to `empleados.index`. |

---

## Task 1: EmpleadosRepository

**Files:**
- Create: `src/Repositories/EmpleadosRepository.php`

- [ ] **Step 1: Write the repository**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use Throwable;

/**
 * EmpleadosRepository
 *
 * Responsabilidad única: acceso a las tablas `empleados` y `datos_bancarios`
 * vía PDO. No contiene lógica de negocio.
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
    public function findAll(?string $estado = null): array
    {
        $sql = 'SELECT e.id_empleado, e.nombre, e.apellidos, e.cedula,
                       e.fecha_ingreso, e.estado, p.nombre AS puesto_nombre
                  FROM empleados e
                  JOIN puestos p ON p.id_puesto = e.id_puesto';
        $params = [];

        if ($estado !== null) {
            $sql .= ' WHERE e.estado = :estado';
            $params[':estado'] = $estado;
        }

        $sql .= ' ORDER BY e.apellidos ASC, e.nombre ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Empleado por ID + su cuenta bancaria activa (LEFT JOIN).
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.*,
                    b.id_datos_bancarios, b.banco, b.tipo_cuenta, b.numero_cuenta,
                    b.numero_cuenta_iban, b.moneda
               FROM empleados e
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
     * Verifica si ya existe la cédula (normalizada), excluyendo un ID al editar.
     */
    public function existsByCedula(string $cedula, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM empleados WHERE cedula = :cedula';
        $params = [':cedula' => $cedula];

        if ($excludeId !== null) {
            $sql .= ' AND id_empleado <> :id';
            $params[':id'] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Usuarios rol 'empleado' no vinculados a otro empleado (más el actual al editar).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findUsuariosDisponibles(?int $currentUsuarioId = null): array
    {
        $sql = "SELECT u.id_usuario, u.nombre_usuario
                  FROM usuarios u
                 WHERE u.rol = 'empleado'
                   AND (u.id_usuario NOT IN (
                           SELECT id_usuario FROM empleados WHERE id_usuario IS NOT NULL
                        )";
        $params = [];

        if ($currentUsuarioId !== null) {
            $sql .= ' OR u.id_usuario = :current';
            $params[':current'] = $currentUsuarioId;
        }

        $sql .= ') ORDER BY u.nombre_usuario ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ── Escritura ─────────────────────────────────────────

    /**
     * Inserta empleado y (opcionalmente) su cuenta bancaria en una transacción.
     *
     * @param array<string, mixed>      $e datos del empleado (claves = columnas)
     * @param array<string, mixed>|null $b datos bancarios o null
     * @return int id_empleado generado
     */
    public function insert(array $e, ?array $b): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO empleados
                    (id_puesto, id_usuario, nombre, apellidos, cedula, fecha_nacimiento,
                     genero, estado_civil, nacionalidad, telefono, correo, direccion,
                     provincia, canton, distrito, telefono_emergencia,
                     nombre_contacto_emergencia, numero_asegurado_ccss, numero_poliza_ins,
                     fecha_ingreso, fecha_salida, estado)
                 VALUES
                    (:id_puesto, :id_usuario, :nombre, :apellidos, :cedula, :fecha_nacimiento,
                     :genero, :estado_civil, :nacionalidad, :telefono, :correo, :direccion,
                     :provincia, :canton, :distrito, :telefono_emergencia,
                     :nombre_contacto_emergencia, :numero_asegurado_ccss, :numero_poliza_ins,
                     :fecha_ingreso, :fecha_salida, :estado)'
            );
            $stmt->execute($this->bindEmpleado($e));
            $id = (int)$this->pdo->lastInsertId();

            if ($b !== null) {
                $this->insertBancario($id, $b);
            }

            $this->pdo->commit();
            return $id;
        } catch (Throwable $ex) {
            $this->pdo->rollBack();
            throw $ex;
        }
    }

    /**
     * Actualiza empleado y hace upsert de su cuenta bancaria activa, en transacción.
     *
     * @param array<string, mixed>      $e
     * @param array<string, mixed>|null $b
     */
    public function update(int $id, array $e, ?array $b): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE empleados SET
                    id_puesto = :id_puesto, id_usuario = :id_usuario, nombre = :nombre,
                    apellidos = :apellidos, cedula = :cedula, fecha_nacimiento = :fecha_nacimiento,
                    genero = :genero, estado_civil = :estado_civil, nacionalidad = :nacionalidad,
                    telefono = :telefono, correo = :correo, direccion = :direccion,
                    provincia = :provincia, canton = :canton, distrito = :distrito,
                    telefono_emergencia = :telefono_emergencia,
                    nombre_contacto_emergencia = :nombre_contacto_emergencia,
                    numero_asegurado_ccss = :numero_asegurado_ccss,
                    numero_poliza_ins = :numero_poliza_ins,
                    fecha_ingreso = :fecha_ingreso, fecha_salida = :fecha_salida, estado = :estado
                  WHERE id_empleado = :id_empleado'
            );
            $params = $this->bindEmpleado($e);
            $params[':id_empleado'] = $id;
            $stmt->execute($params);

            if ($b !== null) {
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
                        ':banco'              => $b['banco'],
                        ':tipo_cuenta'        => $b['tipo_cuenta'],
                        ':numero_cuenta'      => $b['numero_cuenta'],
                        ':numero_cuenta_iban' => $b['numero_cuenta_iban'],
                        ':moneda'             => $b['moneda'],
                        ':idb'                => (int)$existing,
                    ]);
                } else {
                    $this->insertBancario($id, $b);
                }
            }

            $this->pdo->commit();
        } catch (Throwable $ex) {
            $this->pdo->rollBack();
            throw $ex;
        }
    }

    /**
     * Soft-delete: marca el empleado como inactivo y registra la fecha de salida.
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
     * Mapea el array de empleado a parámetros nombrados de PDO.
     *
     * @param array<string, mixed> $e
     * @return array<string, mixed>
     */
    private function bindEmpleado(array $e): array
    {
        return [
            ':id_puesto'                  => $e['id_puesto'],
            ':id_usuario'                 => $e['id_usuario'],
            ':nombre'                     => $e['nombre'],
            ':apellidos'                  => $e['apellidos'],
            ':cedula'                     => $e['cedula'],
            ':fecha_nacimiento'           => $e['fecha_nacimiento'],
            ':genero'                     => $e['genero'],
            ':estado_civil'               => $e['estado_civil'],
            ':nacionalidad'               => $e['nacionalidad'],
            ':telefono'                   => $e['telefono'],
            ':correo'                     => $e['correo'],
            ':direccion'                  => $e['direccion'],
            ':provincia'                  => $e['provincia'],
            ':canton'                     => $e['canton'],
            ':distrito'                   => $e['distrito'],
            ':telefono_emergencia'        => $e['telefono_emergencia'],
            ':nombre_contacto_emergencia' => $e['nombre_contacto_emergencia'],
            ':numero_asegurado_ccss'      => $e['numero_asegurado_ccss'],
            ':numero_poliza_ins'          => $e['numero_poliza_ins'],
            ':fecha_ingreso'              => $e['fecha_ingreso'],
            ':fecha_salida'               => $e['fecha_salida'],
            ':estado'                     => $e['estado'],
        ];
    }
}
```

- [ ] **Step 2: Lint the file**

Run: `php -l src/Repositories/EmpleadosRepository.php`
Expected: `No syntax errors detected in src/Repositories/EmpleadosRepository.php`

- [ ] **Step 3: Commit**

```bash
git add src/Repositories/EmpleadosRepository.php
git commit -m "feat: add EmpleadosRepository with transaction and dropdown reads"
```

---

## Task 2: EmpleadosService

**Files:**
- Create: `src/Services/EmpleadosService.php`

- [ ] **Step 1: Write the service**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;
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
        private readonly PuestosRepository   $puestosRepo,
        private readonly AuditoriaRepository $auditoriaRepo
    ) {}

    // ── Consultas ─────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listar(?string $estado = null): array
    {
        return $this->repo->findAll($estado);
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
     * @return array{puestos: array<int, array<string, mixed>>, usuarios: array<int, array<string, mixed>>}
     */
    public function datosFormulario(?int $currentUsuarioId = null): array
    {
        return [
            'puestos'  => $this->puestosRepo->findAll(),
            'usuarios' => $this->repo->findUsuariosDisponibles($currentUsuarioId),
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
        $empleado = $this->validar($datos, null);
        $bancario = $this->extraerBancario($datos);

        try {
            $id = $this->repo->insert($empleado, $bancario);
        } catch (PDOException $e) {
            throw $this->traducirPdoException($e);
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
        $this->obtener($id);
        $empleado = $this->validar($datos, $id);
        $bancario = $this->extraerBancario($datos);

        try {
            $this->repo->update($id, $empleado, $bancario);
        } catch (PDOException $e) {
            throw $this->traducirPdoException($e);
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
     * Valida y normaliza los datos del empleado.
     *
     * @param array<string, mixed> $d
     * @param int|null $excludeId ID a excluir en la verificación de cédula (al editar)
     * @return array<string, mixed> columnas listas para el Repository
     * @throws InvalidArgumentException con todos los errores concatenados
     */
    private function validar(array $d, ?int $excludeId): array
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

        // Cédula: normaliza y acepta CR (9 díg.) o DIMEX (11-12 díg.)
        $cedulaRaw  = (string)($d['cedula'] ?? '');
        $cedula     = preg_replace('/[\s-]/', '', $cedulaRaw) ?? '';
        $longitud   = strlen($cedula);
        if ($cedula === '' || !ctype_digit($cedula) || !($longitud === 9 || $longitud === 11 || $longitud === 12)) {
            $errores[] = 'La cédula debe ser nacional (9 dígitos) o DIMEX (11-12 dígitos).';
        } elseif ($this->repo->existsByCedula($cedula, $excludeId)) {
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
        }

        $estado = trim((string)($d['estado'] ?? 'activo'));
        if (!in_array($estado, self::ESTADOS, true)) {
            $estado = 'activo';
        }

        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        // fecha_salida: si el estado vuelve a 'activo' se limpia.
        $fechaSalida = $estado === 'inactivo'
            ? ($this->fechaOpcional((string)($d['fecha_salida'] ?? '')))
            : null;

        return [
            'id_puesto'                  => $idPuesto,
            'id_usuario'                 => $this->idUsuarioOpcional($d),
            'nombre'                     => $nombre,
            'apellidos'                  => $apellidos,
            'cedula'                     => $cedula,
            'fecha_nacimiento'           => $fechaNac,
            'genero'                     => $genero,
            'estado_civil'               => $estadoCivil,
            'nacionalidad'               => $nacionalidad,
            'telefono'                   => $this->textoOpcional($d, 'telefono', 20),
            'correo'                     => $correo !== '' ? $correo : null,
            'direccion'                  => $this->textoOpcional($d, 'direccion', 255),
            'provincia'                  => $this->textoOpcional($d, 'provincia', 100),
            'canton'                     => $this->textoOpcional($d, 'canton', 100),
            'distrito'                   => $this->textoOpcional($d, 'distrito', 100),
            'telefono_emergencia'        => $this->textoOpcional($d, 'telefono_emergencia', 20),
            'nombre_contacto_emergencia' => $this->textoOpcional($d, 'nombre_contacto_emergencia', 150),
            'numero_asegurado_ccss'      => $this->textoOpcional($d, 'numero_asegurado_ccss', 20),
            'numero_poliza_ins'          => $this->textoOpcional($d, 'numero_poliza_ins', 30),
            'fecha_ingreso'              => $fechaIngreso,
            'fecha_salida'               => $fechaSalida,
            'estado'                     => $estado,
        ];
    }

    /**
     * @param string[] $errores referencia acumuladora
     * @return string fecha normalizada o '' si inválida
     */
    private function validarFechaNacimiento(string $valor, array &$errores): string
    {
        $valor = trim($valor);
        $fecha = DateTimeImmutable::createFromFormat('Y-m-d', $valor);
        if ($fecha === false) {
            $errores[] = 'La fecha de nacimiento es obligatoria y debe tener formato válido.';
            return '';
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
     * Extrae y valida los datos bancarios. Devuelve null si el bloque viene vacío.
     *
     * @param array<string, mixed> $d
     * @return array<string, mixed>|null
     * @throws InvalidArgumentException
     */
    private function extraerBancario(array $d): ?array
    {
        $banco       = trim((string)($d['banco'] ?? ''));
        $tipoCuenta  = trim((string)($d['tipo_cuenta'] ?? ''));
        $numeroCta   = trim((string)($d['numero_cuenta'] ?? ''));
        $iban        = trim((string)($d['numero_cuenta_iban'] ?? ''));
        $moneda      = trim((string)($d['moneda'] ?? ''));

        $algunoLleno = ($banco !== '' || $tipoCuenta !== '' || $numeroCta !== '' || $iban !== '');
        if (!$algunoLleno) {
            return null;
        }

        $errores = [];
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

        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
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
     * @param array<string, mixed> $d
     */
    private function textoOpcional(array $d, string $clave, int $max): ?string
    {
        $valor = trim((string)($d[$clave] ?? ''));
        if ($valor === '') {
            return null;
        }
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

    private function traducirPdoException(PDOException $e): InvalidArgumentException
    {
        if (str_starts_with((string)$e->getCode(), '23')) {
            return new InvalidArgumentException('Ya existe un empleado con esa cédula.');
        }
        // Re-lanzar como genérico si no es una violación de integridad conocida.
        return new InvalidArgumentException('No se pudo guardar el empleado: ' . $e->getMessage());
    }
}
```

- [ ] **Step 2: Lint the file**

Run: `php -l src/Services/EmpleadosService.php`
Expected: `No syntax errors detected in src/Services/EmpleadosService.php`

- [ ] **Step 3: Commit**

```bash
git add src/Services/EmpleadosService.php
git commit -m "feat: add EmpleadosService with validation, cedula CR/DIMEX, soft-delete and audit"
```

---

## Task 3: EmpleadosController

**Files:**
- Create: `src/Controllers/EmpleadosController.php`

- [ ] **Step 1: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\EmpleadosService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Views\Twig;

/**
 * EmpleadosController
 *
 * Solo HTTP: parsea input, llama al Service, renderiza Twig o redirige.
 * No contiene lógica de negocio ni accede a PDO.
 *
 * Rutas:
 *   GET    /empleados                → index
 *   GET    /empleados/crear          → create
 *   POST   /empleados/crear          → store
 *   GET    /empleados/{id}/editar    → edit
 *   POST   /empleados/{id}/editar    → update
 *   POST   /empleados/{id}/eliminar  → destroy (soft-delete)
 */
class EmpleadosController
{
    public function __construct(
        private readonly Twig             $twig,
        private readonly EmpleadosService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $estado = $params['estado'] ?? 'activo';
        // 'todos' => sin filtro; cualquier otro valor inválido cae a 'activo'.
        $filtro = $estado === 'todos' ? null : ($estado === 'inactivo' ? 'inactivo' : 'activo');

        return $this->twig->render($response, 'empleados/index.html.twig', [
            'titulo'       => 'Empleados',
            'empleados'    => $this->service->listar($filtro),
            'estadoActual' => $filtro === null ? 'todos' : $filtro,
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $datosForm = $this->service->datosFormulario();

        return $this->twig->render($response, 'empleados/form.html.twig', [
            'titulo'   => 'Nuevo Empleado',
            'accion'   => 'crear',
            'empleado' => [],
            'puestos'  => $datosForm['puestos'],
            'usuarios' => $datosForm['usuarios'],
            'errores'  => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $datos      = (array)$request->getParsedBody();
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Empleado registrado exitosamente.';
            return $response->withHeader('Location', '/empleados')->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $datosForm = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'empleados/form.html.twig', [
                'titulo'   => 'Nuevo Empleado',
                'accion'   => 'crear',
                'empleado' => $datos,
                'puestos'  => $datosForm['puestos'],
                'usuarios' => $datosForm['usuarios'],
                'errores'  => [$e->getMessage()],
            ]);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            $empleado = $this->service->obtener((int)$args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Empleado no encontrado.';
            return $response->withHeader('Location', '/empleados')->withStatus(302);
        }

        $currentUsuarioId = $empleado['id_usuario'] !== null ? (int)$empleado['id_usuario'] : null;
        $datosForm        = $this->service->datosFormulario($currentUsuarioId);

        return $this->twig->render($response, 'empleados/form.html.twig', [
            'titulo'   => 'Editar Empleado',
            'accion'   => 'editar',
            'empleado' => $empleado,
            'puestos'  => $datosForm['puestos'],
            'usuarios' => $datosForm['usuarios'],
            'errores'  => [],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id         = (int)$args['id'];
        $datos      = (array)$request->getParsedBody();
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->actualizar($id, $datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Empleado actualizado correctamente.';
            return $response->withHeader('Location', '/empleados')->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $currentUsuarioId = $this->idUsuarioDeDatos($datos);
            $datosForm        = $this->service->datosFormulario($currentUsuarioId);
            return $this->twig->render($response->withStatus(422), 'empleados/form.html.twig', [
                'titulo'   => 'Editar Empleado',
                'accion'   => 'editar',
                'empleado' => array_merge(['id_empleado' => $id], $datos),
                'puestos'  => $datosForm['puestos'],
                'usuarios' => $datosForm['usuarios'],
                'errores'  => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response->withHeader('Location', '/empleados')->withStatus(302);
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->eliminar((int)$args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Empleado desactivado correctamente.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response->withHeader('Location', '/empleados')->withStatus(302);
    }

    // ── Helpers ───────────────────────────────────────────

    /**
     * @param array<string, mixed> $datos
     */
    private function idUsuarioDeDatos(array $datos): ?int
    {
        $valor = $datos['id_usuario'] ?? '';
        return ($valor !== '' && is_numeric($valor)) ? (int)$valor : null;
    }

    private function consumeFlash(string $key): ?string
    {
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }
}
```

- [ ] **Step 2: Lint the file**

Run: `php -l src/Controllers/EmpleadosController.php`
Expected: `No syntax errors detected in src/Controllers/EmpleadosController.php`

- [ ] **Step 3: Commit**

```bash
git add src/Controllers/EmpleadosController.php
git commit -m "feat: add EmpleadosController with estado filter and 422 re-render"
```

---

## Task 4: index template

**Files:**
- Create: `templates/empleados/index.html.twig`

- [ ] **Step 1: Write the template**

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Empleados{% endblock %}

{% block breadcrumb %}
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ path_for('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item active">Empleados</li>
    </ol>
</nav>
{% endblock %}

{% block contenido %}
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0">
        <i class="bi bi-people me-2 text-primary"></i>Empleados
    </h2>
    <a href="{{ path_for('empleados.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Nuevo Empleado
    </a>
</div>

{# Filtro por estado #}
<div class="btn-group btn-group-sm mb-3" role="group">
    <a href="{{ path_for('empleados.index') }}?estado=activo"
       class="btn {{ estadoActual == 'activo' ? 'btn-primary' : 'btn-outline-primary' }}">Activos</a>
    <a href="{{ path_for('empleados.index') }}?estado=inactivo"
       class="btn {{ estadoActual == 'inactivo' ? 'btn-primary' : 'btn-outline-primary' }}">Inactivos</a>
    <a href="{{ path_for('empleados.index') }}?estado=todos"
       class="btn {{ estadoActual == 'todos' ? 'btn-primary' : 'btn-outline-primary' }}">Todos</a>
</div>

{% if empleados|length == 0 %}
<div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i>No hay empleados que coincidan con el filtro.
</div>
{% else %}
<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover table-striped align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <th>#</th>
                    <th>Nombre completo</th>
                    <th>Cédula</th>
                    <th>Puesto</th>
                    <th>Ingreso</th>
                    <th class="text-center">Estado</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                {% for e in empleados %}
                <tr>
                    <td class="text-muted small">{{ e.id_empleado }}</td>
                    <td class="fw-semibold">{{ e.apellidos }}, {{ e.nombre }}</td>
                    <td>{{ e.cedula }}</td>
                    <td>{{ e.puesto_nombre }}</td>
                    <td>{{ e.fecha_ingreso|date('d/m/Y') }}</td>
                    <td class="text-center">
                        {% if e.estado == 'activo' %}
                            <span class="badge bg-success">Activo</span>
                        {% else %}
                            <span class="badge bg-secondary">Inactivo</span>
                        {% endif %}
                    </td>
                    <td class="text-center">
                        <a href="{{ path_for('empleados.edit', {'id': e.id_empleado}) }}"
                           class="btn btn-sm btn-outline-primary me-1" title="Editar">
                            <i class="bi bi-pencil-square"></i>
                        </a>
                        {% if e.estado == 'activo' %}
                        <button type="button"
                                class="btn btn-sm btn-outline-danger"
                                title="Desactivar"
                                data-bs-toggle="modal"
                                data-bs-target="#modalEliminar"
                                data-id="{{ e.id_empleado }}"
                                data-nombre="{{ e.apellidos }}, {{ e.nombre }}">
                            <i class="bi bi-person-dash"></i>
                        </button>
                        {% endif %}
                    </td>
                </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
    <div class="card-footer text-muted small">
        Total: {{ empleados|length }} empleado(s).
    </div>
</div>
{% endif %}

<div class="modal fade" id="modalEliminar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle me-1"></i>Confirmar desactivación
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                ¿Desactivar al empleado <strong id="modalNombreEmpleado"></strong>?
                Su información se conserva, pero quedará marcado como inactivo.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <form id="formEliminar" method="POST" action="">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-person-dash me-1"></i>Desactivar
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
{% endblock %}

{% block scripts %}
<script>
document.getElementById('modalEliminar').addEventListener('show.bs.modal', function (event) {
    const btn    = event.relatedTarget;
    const id     = btn.getAttribute('data-id');
    const nombre = btn.getAttribute('data-nombre');
    document.getElementById('modalNombreEmpleado').textContent = '"' + nombre + '"';
    document.getElementById('formEliminar').action = '/empleados/' + id + '/eliminar';
});
</script>
{% endblock %}
```

- [ ] **Step 2: Commit**

```bash
git add templates/empleados/index.html.twig
git commit -m "feat: add empleados index template with estado filter and deactivate modal"
```

---

## Task 5: form template

**Files:**
- Create: `templates/empleados/form.html.twig`

- [ ] **Step 1: Write the template**

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}{{ titulo }}{% endblock %}

{% block breadcrumb %}
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ path_for('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="{{ path_for('empleados.index') }}">Empleados</a></li>
        <li class="breadcrumb-item active">{{ accion == 'crear' ? 'Nuevo' : 'Editar' }}</li>
    </ol>
</nav>
{% endblock %}

{% block contenido %}
<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-people me-2"></i>{{ titulo }}</h5>
            </div>
            <div class="card-body">

                {% if errores|length > 0 %}
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        {% for error in errores %}<li>{{ error }}</li>{% endfor %}
                    </ul>
                </div>
                {% endif %}

                {% if accion == 'crear' %}
                    {% set form_action = path_for('empleados.store') %}
                {% else %}
                    {% set form_action = path_for('empleados.update', {'id': empleado.id_empleado}) %}
                {% endif %}

                <form method="POST" action="{{ form_action }}" novalidate>

                    {# ── Datos personales ── #}
                    <fieldset class="mb-4">
                        <legend class="fs-6 fw-bold text-primary border-bottom pb-1">Datos personales</legend>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="nombre" class="form-label">Nombre <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="nombre" name="nombre"
                                       maxlength="100" required value="{{ empleado.nombre ?? '' }}">
                            </div>
                            <div class="col-md-6">
                                <label for="apellidos" class="form-label">Apellidos <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="apellidos" name="apellidos"
                                       maxlength="100" required value="{{ empleado.apellidos ?? '' }}">
                            </div>
                            <div class="col-md-4">
                                <label for="cedula" class="form-label">Cédula / DIMEX <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="cedula" name="cedula"
                                       maxlength="20" required value="{{ empleado.cedula ?? '' }}"
                                       placeholder="9 dígitos (CR) o 11-12 (DIMEX)">
                            </div>
                            <div class="col-md-4">
                                <label for="fecha_nacimiento" class="form-label">Fecha de nacimiento <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="fecha_nacimiento" name="fecha_nacimiento"
                                       required value="{{ empleado.fecha_nacimiento ?? '' }}">
                            </div>
                            <div class="col-md-4">
                                <label for="nacionalidad" class="form-label">Nacionalidad <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="nacionalidad" name="nacionalidad"
                                       maxlength="50" required value="{{ empleado.nacionalidad ?? 'Costarricense' }}">
                            </div>
                            <div class="col-md-6">
                                <label for="genero" class="form-label">Género <span class="text-danger">*</span></label>
                                <select class="form-select" id="genero" name="genero" required>
                                    <option value="">— Seleccione —</option>
                                    <option value="masculino" {{ (empleado.genero ?? '') == 'masculino' ? 'selected' : '' }}>Masculino</option>
                                    <option value="femenino"  {{ (empleado.genero ?? '') == 'femenino'  ? 'selected' : '' }}>Femenino</option>
                                    <option value="otro"      {{ (empleado.genero ?? '') == 'otro'      ? 'selected' : '' }}>Otro</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="estado_civil" class="form-label">Estado civil <span class="text-danger">*</span></label>
                                <select class="form-select" id="estado_civil" name="estado_civil" required>
                                    <option value="">— Seleccione —</option>
                                    <option value="soltero"     {{ (empleado.estado_civil ?? '') == 'soltero'     ? 'selected' : '' }}>Soltero(a)</option>
                                    <option value="casado"      {{ (empleado.estado_civil ?? '') == 'casado'      ? 'selected' : '' }}>Casado(a)</option>
                                    <option value="union_libre" {{ (empleado.estado_civil ?? '') == 'union_libre' ? 'selected' : '' }}>Unión libre</option>
                                    <option value="divorciado"  {{ (empleado.estado_civil ?? '') == 'divorciado'  ? 'selected' : '' }}>Divorciado(a)</option>
                                    <option value="viudo"       {{ (empleado.estado_civil ?? '') == 'viudo'       ? 'selected' : '' }}>Viudo(a)</option>
                                </select>
                            </div>
                        </div>
                    </fieldset>

                    {# ── Contacto ── #}
                    <fieldset class="mb-4">
                        <legend class="fs-6 fw-bold text-primary border-bottom pb-1">Contacto</legend>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="telefono" class="form-label">Teléfono</label>
                                <input type="text" class="form-control" id="telefono" name="telefono"
                                       maxlength="20" value="{{ empleado.telefono ?? '' }}">
                            </div>
                            <div class="col-md-6">
                                <label for="correo" class="form-label">Correo electrónico</label>
                                <input type="email" class="form-control" id="correo" name="correo"
                                       maxlength="150" value="{{ empleado.correo ?? '' }}">
                            </div>
                        </div>
                    </fieldset>

                    {# ── Ubicación ── #}
                    <fieldset class="mb-4">
                        <legend class="fs-6 fw-bold text-primary border-bottom pb-1">Ubicación</legend>
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="direccion" class="form-label">Dirección</label>
                                <input type="text" class="form-control" id="direccion" name="direccion"
                                       maxlength="255" value="{{ empleado.direccion ?? '' }}">
                            </div>
                            <div class="col-md-4">
                                <label for="provincia" class="form-label">Provincia</label>
                                <input type="text" class="form-control" id="provincia" name="provincia"
                                       maxlength="100" value="{{ empleado.provincia ?? '' }}">
                            </div>
                            <div class="col-md-4">
                                <label for="canton" class="form-label">Cantón</label>
                                <input type="text" class="form-control" id="canton" name="canton"
                                       maxlength="100" value="{{ empleado.canton ?? '' }}">
                            </div>
                            <div class="col-md-4">
                                <label for="distrito" class="form-label">Distrito</label>
                                <input type="text" class="form-control" id="distrito" name="distrito"
                                       maxlength="100" value="{{ empleado.distrito ?? '' }}">
                            </div>
                        </div>
                    </fieldset>

                    {# ── Contacto de emergencia ── #}
                    <fieldset class="mb-4">
                        <legend class="fs-6 fw-bold text-primary border-bottom pb-1">Contacto de emergencia</legend>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="nombre_contacto_emergencia" class="form-label">Nombre del contacto</label>
                                <input type="text" class="form-control" id="nombre_contacto_emergencia"
                                       name="nombre_contacto_emergencia" maxlength="150"
                                       value="{{ empleado.nombre_contacto_emergencia ?? '' }}">
                            </div>
                            <div class="col-md-6">
                                <label for="telefono_emergencia" class="form-label">Teléfono de emergencia</label>
                                <input type="text" class="form-control" id="telefono_emergencia"
                                       name="telefono_emergencia" maxlength="20"
                                       value="{{ empleado.telefono_emergencia ?? '' }}">
                            </div>
                        </div>
                    </fieldset>

                    {# ── Seguros ── #}
                    <fieldset class="mb-4">
                        <legend class="fs-6 fw-bold text-primary border-bottom pb-1">Seguros</legend>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="numero_asegurado_ccss" class="form-label">N.° asegurado CCSS</label>
                                <input type="text" class="form-control" id="numero_asegurado_ccss"
                                       name="numero_asegurado_ccss" maxlength="20"
                                       value="{{ empleado.numero_asegurado_ccss ?? '' }}">
                            </div>
                            <div class="col-md-6">
                                <label for="numero_poliza_ins" class="form-label">N.° póliza INS</label>
                                <input type="text" class="form-control" id="numero_poliza_ins"
                                       name="numero_poliza_ins" maxlength="30"
                                       value="{{ empleado.numero_poliza_ins ?? '' }}">
                            </div>
                        </div>
                    </fieldset>

                    {# ── Datos laborales ── #}
                    <fieldset class="mb-4">
                        <legend class="fs-6 fw-bold text-primary border-bottom pb-1">Datos laborales</legend>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="id_puesto" class="form-label">Puesto <span class="text-danger">*</span></label>
                                <select class="form-select" id="id_puesto" name="id_puesto" required>
                                    <option value="">— Seleccione —</option>
                                    {% for p in puestos %}
                                    <option value="{{ p.id_puesto }}"
                                        {{ (empleado.id_puesto ?? '') == p.id_puesto ? 'selected' : '' }}>
                                        {{ p.nombre }}
                                    </option>
                                    {% endfor %}
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="fecha_ingreso" class="form-label">Fecha de ingreso <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="fecha_ingreso" name="fecha_ingreso"
                                       required value="{{ empleado.fecha_ingreso ?? '' }}">
                            </div>
                            <div class="col-md-4">
                                <label for="estado" class="form-label">Estado</label>
                                <select class="form-select" id="estado" name="estado">
                                    <option value="activo"   {{ (empleado.estado ?? 'activo') == 'activo'   ? 'selected' : '' }}>Activo</option>
                                    <option value="inactivo" {{ (empleado.estado ?? '') == 'inactivo'       ? 'selected' : '' }}>Inactivo</option>
                                </select>
                            </div>
                        </div>
                    </fieldset>

                    {# ── Cuenta de usuario ── #}
                    <fieldset class="mb-4">
                        <legend class="fs-6 fw-bold text-primary border-bottom pb-1">Cuenta de usuario (portal)</legend>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="id_usuario" class="form-label">Usuario vinculado</label>
                                <select class="form-select" id="id_usuario" name="id_usuario">
                                    <option value="">— Sin cuenta —</option>
                                    {% for u in usuarios %}
                                    <option value="{{ u.id_usuario }}"
                                        {{ (empleado.id_usuario ?? '') == u.id_usuario ? 'selected' : '' }}>
                                        {{ u.nombre_usuario }}
                                    </option>
                                    {% endfor %}
                                </select>
                                <div class="form-text">Solo se listan usuarios con rol "empleado" no vinculados a otro empleado.</div>
                            </div>
                        </div>
                    </fieldset>

                    {# ── Datos bancarios ── #}
                    <fieldset class="mb-4">
                        <legend class="fs-6 fw-bold text-primary border-bottom pb-1">Datos bancarios (opcional)</legend>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="banco" class="form-label">Banco</label>
                                <input type="text" class="form-control" id="banco" name="banco"
                                       maxlength="100" value="{{ empleado.banco ?? '' }}">
                            </div>
                            <div class="col-md-3">
                                <label for="tipo_cuenta" class="form-label">Tipo de cuenta</label>
                                <select class="form-select" id="tipo_cuenta" name="tipo_cuenta">
                                    <option value="">— Seleccione —</option>
                                    <option value="corriente" {{ (empleado.tipo_cuenta ?? '') == 'corriente' ? 'selected' : '' }}>Corriente</option>
                                    <option value="ahorros"   {{ (empleado.tipo_cuenta ?? '') == 'ahorros'   ? 'selected' : '' }}>Ahorros</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="moneda" class="form-label">Moneda</label>
                                <select class="form-select" id="moneda" name="moneda">
                                    <option value="CRC" {{ (empleado.moneda ?? 'CRC') == 'CRC' ? 'selected' : '' }}>Colones (CRC)</option>
                                    <option value="USD" {{ (empleado.moneda ?? '') == 'USD' ? 'selected' : '' }}>Dólares (USD)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="numero_cuenta" class="form-label">Número de cuenta</label>
                                <input type="text" class="form-control" id="numero_cuenta" name="numero_cuenta"
                                       maxlength="30" value="{{ empleado.numero_cuenta ?? '' }}">
                            </div>
                            <div class="col-md-6">
                                <label for="numero_cuenta_iban" class="form-label">IBAN</label>
                                <input type="text" class="form-control" id="numero_cuenta_iban" name="numero_cuenta_iban"
                                       maxlength="22" value="{{ empleado.numero_cuenta_iban ?? '' }}">
                            </div>
                        </div>
                    </fieldset>

                    <div class="d-flex justify-content-between mt-4">
                        <a href="{{ path_for('empleados.index') }}" class="btn btn-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Cancelar
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-floppy me-1"></i>
                            {{ accion == 'crear' ? 'Guardar Empleado' : 'Actualizar Empleado' }}
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>
{% endblock %}
```

- [ ] **Step 2: Commit**

```bash
git add templates/empleados/form.html.twig
git commit -m "feat: add empleados form template with 8 fieldsets"
```

---

## Task 6: Register in dependencies.php

**Files:**
- Modify: `config/dependencies.php`

- [ ] **Step 1: Add the Repository registration**

In the `// ── Repositories ──` block, after the `FeriadosRepository::class` entry (around line 77), add:

```php
    \App\Repositories\EmpleadosRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\EmpleadosRepository($c->get(PDO::class));
    },
```

- [ ] **Step 2: Add the Service registration**

In the `// ── Services ──` block, after the `FeriadosService::class` entry (around line 112), add:

```php
    \App\Services\EmpleadosService::class => function (ContainerInterface $c) {
        return new \App\Services\EmpleadosService(
            $c->get(\App\Repositories\EmpleadosRepository::class),
            $c->get(\App\Repositories\PuestosRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },
```

- [ ] **Step 3: Add the Controller registration**

In the `// ── Controllers ──` block, after the `FeriadosController::class` entry (around line 152), add:

```php
    \App\Controllers\EmpleadosController::class => function (ContainerInterface $c) {
        return new \App\Controllers\EmpleadosController(
            $c->get(Twig::class),
            $c->get(\App\Services\EmpleadosService::class)
        );
    },
```

- [ ] **Step 4: Lint the file**

Run: `php -l config/dependencies.php`
Expected: `No syntax errors detected in config/dependencies.php`

- [ ] **Step 5: Commit**

```bash
git add config/dependencies.php
git commit -m "feat: wire EmpleadosRepository, Service and Controller in DI container"
```

---

## Task 7: Routes + navbar link

**Files:**
- Modify: `config/routes.php`
- Modify: `templates/layouts/base.html.twig:83-87`

- [ ] **Step 1: Add the controller import**

In `config/routes.php`, after the existing `use App\Controllers\FeriadosController;` line, add:

```php
use App\Controllers\EmpleadosController;
```

- [ ] **Step 2: Fill the `/empleados` route group**

Replace the empty `/empleados` group (currently lines 70-71):

```php
    // ── Empleados (solo admin) — pendiente ────────────────
    $app->group('/empleados', function (RouteCollectorProxy $group) {
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());
```

with:

```php
    // ── Empleados (solo admin) ────────────────────────────
    $app->group('/empleados', function (RouteCollectorProxy $group) {
        $group->get('',                  [EmpleadosController::class, 'index'])->setName('empleados.index');
        $group->get('/crear',            [EmpleadosController::class, 'create'])->setName('empleados.create');
        $group->post('/crear',           [EmpleadosController::class, 'store'])->setName('empleados.store');
        $group->get('/{id}/editar',      [EmpleadosController::class, 'edit'])->setName('empleados.edit');
        $group->post('/{id}/editar',     [EmpleadosController::class, 'update'])->setName('empleados.update');
        $group->post('/{id}/eliminar',   [EmpleadosController::class, 'destroy'])->setName('empleados.destroy');
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());
```

> Note: the index uses an **empty** pattern (`''`) so the full path is exactly `/empleados` (no trailing slash). This matches the controller redirects (`Location: /empleados`) and `path_for('empleados.index')`, avoiding Slim's strict trailing-slash 404. The other routes append their sub-paths (`/empleados/crear`, etc.).

- [ ] **Step 3: Point the navbar link to the route**

In `templates/layouts/base.html.twig`, replace the Empleados nav item (lines 83-87):

```twig
                <li class="nav-item">
                    <a class="nav-link" href="#">
                        <i class="bi bi-people me-1"></i>Empleados
                    </a>
                </li>
```

with:

```twig
                <li class="nav-item">
                    <a class="nav-link" href="{{ path_for('empleados.index') }}">
                        <i class="bi bi-people me-1"></i>Empleados
                    </a>
                </li>
```

- [ ] **Step 4: Lint the routes file**

Run: `php -l config/routes.php`
Expected: `No syntax errors detected in config/routes.php`

- [ ] **Step 5: Commit**

```bash
git add config/routes.php templates/layouts/base.html.twig
git commit -m "feat: register empleados routes and link navbar"
```

---

## Task 8: Manual smoke test

**Files:** none (verification only)

Start XAMPP (Apache + MySQL) and ensure `database/schema.sql` + `database/seed.sql` are imported. Log in as an admin user.

- [ ] **Step 1: List loads**

Navigate to `/empleados`. Expected: page renders, filter buttons (Activos/Inactivos/Todos) visible, no PHP errors. If no employees exist yet, the info alert shows.

- [ ] **Step 2: Create — happy path**

Click "Nuevo Empleado". Fill required fields (nombre, apellidos, cédula `1-1234-5678`, fecha_nacimiento e.g. `1995-05-10`, género, estado civil, nacionalidad, puesto, fecha_ingreso). Leave bank fields empty. Submit.
Expected: redirect to `/empleados`, green flash "Empleado registrado exitosamente.", row appears.

- [ ] **Step 3: Create — validation errors**

Click "Nuevo Empleado". Submit with an invalid cédula (e.g. `12345`) and a future fecha_nacimiento.
Expected: 422 re-render, red error box listing the cédula and birth-date messages, previously typed values preserved.

- [ ] **Step 4: Create — with bank data + user link**

Create another employee, this time filling banco, tipo_cuenta, numero_cuenta, and selecting a user from the dropdown.
Expected: success; editing the same employee shows the bank fields and the linked user repopulated.

- [ ] **Step 5: Edit**

Edit an employee, change the puesto and phone, submit.
Expected: success flash, changes reflected in the list.

- [ ] **Step 6: Deactivate (soft-delete)**

On an active employee, click the deactivate button, confirm in the modal.
Expected: flash "Empleado desactivado correctamente."; with the "Activos" filter the row disappears; with "Inactivos" it appears with a grey badge and no deactivate button.

- [ ] **Step 7: Audit trail**

In phpMyAdmin, check the `auditoria` table.
Expected: one `INSERT`, `UPDATE`, and `DELETE` row on `tabla_afectada = 'empleados'` for the actions performed, with the logged-in `id_usuario`.

- [ ] **Step 8: Final branch verification**

Run: `git log --oneline feature/empleados -8`
Expected: commits for repository, service, controller, both templates, DI wiring, and routes/navbar.

---

## Notes for the implementer

- **Do not** modify `database/schema.sql` — the module fits the existing tables.
- The Service never reads `$_SESSION`/`$_SERVER`; the Controller passes `loggedInId` and `ip`.
- The Repository owns the `empleados` + `datos_bancarios` transaction; the Service never calls PDO.
- Cédula is stored normalized (digits only). The `cedula` form field shows whatever the user typed on re-render after an error, but the stored value is normalized.
- If `php` is not on PATH on the Windows machine, use the XAMPP PHP binary, e.g. `C:\xampp\php\php.exe -l <file>`.
