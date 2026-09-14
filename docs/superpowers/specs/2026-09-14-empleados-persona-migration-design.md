# Diseño — Migración de Empleados a `persona` (Fase 1)

- **Rama:** `feature/empleados-persona` (a partir de `develop`)
- **Fecha:** 2026-09-14
- **Rol:** Migra `EmpleadosRepository`/`EmpleadosService`/`EmpleadosController`/formulario para trabajar contra el esquema ya vigente en `develop`/`main` (Grupo 8: tabla `persona` + geografía normalizada). No crea tablas ni columnas nuevas — la BD ya tiene todo lo necesario.
- **Patrón:** Controller → Service → Repository (igual que el resto del proyecto).
- **Origen:** Retroalimentación del tutor ya implementada en el esquema (Grupo 7/8, 23/08/2026); esta es la fase de "Desarrollo del sistema" que CLAUDE.md dejó pendiente explícitamente para el código PHP de Empleados.

## 0. Contexto y motivo

El esquema de `empleados` se redujo de 23 columnas a 10 (Grupo 8): identidad y contacto se movieron a una tabla `persona` (supertipo 1:1, `empleados.id_persona` FK), y provincia/cantón/distrito pasaron de texto libre a una jerarquía normalizada (`provincias` → `cantones` → `distritos`). `EmpleadosRepository`/`EmpleadosService`/el formulario nunca se actualizaron — siguen leyendo/escribiendo columnas (`nombre`, `apellidos`, `cedula`, `direccion`, `provincia`, `canton`, `distrito`, etc.) que ya no existen en `empleados`. Esto es código roto en reposo: nunca se probó contra una base de datos real (los tests de Service mockean el Repository), así que nada lo detectó hasta ahora.

Además, 13 archivos más en el proyecto hacen `JOIN empleados e` y leen `e.nombre`/`e.apellidos` directamente — están fuera de alcance de este documento (Fase 2, spec propio).

## 1. Alcance

1. `src/Repositories/EmpleadosRepository.php` — reescribir `findAll`, `findById`, `existsByCedula`, `insert`, `update` para leer/escribir a través de `persona`. `findUsuariosDisponibles`, `deactivate`, `insertBancario` no cambian.
2. `src/Services/EmpleadosService.php` — `validar()` separa sus datos en dos grupos (`persona` / `empleado`); `datosFormulario()` agrega la lista de distritos.
3. Nuevo `src/Repositories/DistritosRepository.php` — catálogo de solo lectura (sin Service/Controller propio; es dato de referencia fijo, no un módulo administrable).
4. `templates/empleados/form.html.twig` — la sección "Ubicación" cambia de 6 campos de texto libre (dirección/provincia/cantón/distrito × principal+alterna) a: dirección (texto libre, sin cambio) + un `<select>` de distrito con `<optgroup>` por provincia/cantón, × principal y alterna.
5. `EmpleadosController` — sin cambios estructurales (ya reenvía lo que `datosFormulario()` devuelva).
6. `templates/empleados/index.html.twig` — sin cambios: ya usa claves genéricas (`e.nombre`, `e.apellidos`, `e.cedula`) que el Repository seguirá devolviendo con esos mismos nombres.

**Fuera de alcance (YAGNI), confirmado contigo:**
- Las otras 12 tablas/repositorios/plantillas que también hacen `JOIN empleados` por el nombre (Fase 2, spec separado).
- Reutilizar un `persona` existente por cédula duplicada (recontratación) — se rechaza igual que hoy, solo que contra `persona.cedula`. El sistema no tiene hoy ningún flujo de recontratación (el soft-delete solo desactiva).
- Selector de `id_horario` en el formulario — el catálogo `horarios` no tiene código PHP propio todavía (solo la fila centinela `id=1`, "Sin asignar"). Se envía siempre `1`; agregar el selector real es trivial cuando exista el módulo Horarios.
- `tests/Services/EmpleadosServiceContactosAlternosTest.php` (la feature "contactos alternos" que se dejó en espera) — sus escenarios quedan reemplazados por las pruebas nuevas de esta migración, no se preserva tal cual.

## 2. Nuevo `src/Repositories/DistritosRepository.php`

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

Registrar en `config/dependencies.php`, junto a la entrada de `PuestosRepository` (línea ~67):

```php
\App\Repositories\DistritosRepository::class => function (ContainerInterface $c) {
    return new \App\Repositories\DistritosRepository($c->get(PDO::class));
},
```

## 3. `EmpleadosRepository` — reescritura completa

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class EmpleadosRepository
{
    public function __construct(private readonly PDO $pdo) {}

    // ── Lectura ───────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
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

    /** @return array<string, mixed>|null */
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

Nota sobre `:id_persona` en `bindEmpleado($empleado) + [':id_persona' => $idPersona]`: el operador `+` de arrays de PHP no sobrescribe claves existentes del lado izquierdo — como `bindEmpleado()` nunca produce `:id_persona`, esto simplemente la agrega. Igual de seguro que un `array_merge`, más corto.

`persona.telefono`/`correo`/`direccion`/etc. son `NOT NULL DEFAULT ''` (no `NULL`) — el Service (sección 4) debe enviar cadena vacía `''`, nunca `null`, para esas columnas. `numero_asegurado_ccss`/`numero_poliza_ins` en `empleados` son igual `NOT NULL DEFAULT ''`.

## 4. `EmpleadosService`

```php
public function __construct(
    private readonly EmpleadosRepository $repo,
    private readonly PuestosRepository    $puestosRepo,
    private readonly DistritosRepository  $distritosRepo,
    private readonly AuditoriaRepository  $auditoriaRepo
) {}
```

Y en `config/dependencies.php` (línea ~142), agregar `DistritosRepository` como tercer argumento, antes de `AuditoriaRepository`:

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

`datosFormulario()` agrega la tercera clave:

```php
public function datosFormulario(?int $currentUsuarioId = null): array
{
    return [
        'puestos'   => $this->puestosRepo->findAll(),
        'usuarios'  => $this->repo->findUsuariosDisponibles($currentUsuarioId),
        'distritos' => $this->distritosRepo->findAllConJerarquia(),
    ];
}
```

`crear()`/`actualizar()` pasan los dos grupos separados:

```php
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
```

`validar()` — mismo cuerpo de validaciones que hoy para nombre/apellidos/cédula/fecha_nacimiento/género/estado_civil/nacionalidad/correo/correo_alterno (sin cambios en las reglas, solo en dónde va cada campo al final), reemplazando el chequeo de cédula por `existsByCedula($cedula, $excludePersonaId)`, y agregando validación de `id_distrito`/`id_distrito_alterno`:

```php
private function validar(array $d, ?int $excludePersonaId): array
{
    $errores = [];

    // ... (id_puesto, nombre, apellidos sin cambios) ...

    $cedulaRaw = (string)($d['cedula'] ?? '');
    $cedula    = preg_replace('/[\s-]/', '', $cedulaRaw) ?? '';
    $longitud  = strlen($cedula);
    if ($cedula === '' || !ctype_digit($cedula) || !($longitud === 9 || $longitud === 11 || $longitud === 12)) {
        $errores[] = 'La cédula debe ser nacional (9 dígitos) o DIMEX (11-12 dígitos).';
    } elseif ($this->repo->existsByCedula($cedula, $excludePersonaId)) {
        $errores[] = "Ya existe un empleado con la cédula {$cedula}.";
    }

    // ... (fecha_nacimiento, genero, estado_civil, nacionalidad, fecha_ingreso, correo, correo_alterno sin cambios) ...

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
        'id_puesto'              => $idPuesto,
        'id_usuario'             => $this->idUsuarioOpcional($d),
        'id_horario'             => 1, // catálogo Horarios aún no tiene módulo propio (ver Alcance)
        'numero_asegurado_ccss'  => $this->textoVacio($d, 'numero_asegurado_ccss', 20),
        'numero_poliza_ins'      => $this->textoVacio($d, 'numero_poliza_ins', 30),
        'fecha_ingreso'          => $fechaIngreso,
        'fecha_salida'           => $fechaSalida,
        'estado'                 => $estado,
    ];

    return ['persona' => $persona, 'empleado' => $empleado, 'bancario' => $bancario];
}
```

`correo`/`correo_alterno` ya se validan hoy con el mismo bloque (`filter_var`); solo cambia que ahora se guardan como `''` en vez de `null` cuando vienen vacíos (columna `NOT NULL DEFAULT ''`) — ajustar esas dos líneas a `$correo` / `$correoAlterno` directamente (ya son `string`, nunca `null`, tras `trim((string)(...))`).

Dos helpers nuevos/ajustados:

```php
/**
 * Como textoOpcional() pero devuelve '' en vez de null (columnas NOT NULL DEFAULT '').
 */
private function textoVacio(array $d, string $clave, int $max): string
{
    $valor = trim((string)($d[$clave] ?? ''));
    return mb_substr($valor, 0, $max);
}

/**
 * @param string[] $errores referencia acumuladora
 */
private function idDistritoValidado(array $d, string $clave, array &$errores): int
{
    $valor = trim((string)($d[$clave] ?? ''));
    if ($valor === '') {
        return 1; // centinela "No aplica"
    }
    if (!ctype_digit($valor) || !$this->distritosRepo->exists((int)$valor)) {
        $errores[] = 'El distrito seleccionado no es válido.';
        return 1;
    }
    return (int)$valor;
}
```

`textoOpcional()` (la versión que devuelve `null`) queda sin uso en este archivo tras la migración — eliminarla si nada más la usa (verificar con una búsqueda antes de borrar).

## 5. `EmpleadosController`

Sin cambios de código — `create()`/`store()` (error path)/`edit()`/`update()` (error path) ya reenvían el array completo de `datosFormulario()` a la plantilla; `distritos` llega automáticamente en ese array.

## 6. `templates/empleados/form.html.twig` — sección "Ubicación"

Reemplaza el fieldset completo (hoy: dirección/provincia/cantón/distrito × principal y alterna, 8 inputs de texto) por:

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

Twig no permite cerrar una etiqueta HTML condicionalmente "a mitad" de forma más limpia sin una macro; dado que `distritos` es una lista pequeña (subconjunto representativo, no las ~500 reales), este patrón de "abrir/cerrar optgroup al cambiar de grupo" es aceptable y ya sigue el estilo de control de flujo que el resto de plantillas de este proyecto usa directamente en Twig (sin macros).

**Teléfono/correo/teléfono alterno/correo alterno/contacto de emergencia/seguros/datos laborales/cuenta de usuario/datos bancarios:** sin cambios — siguen siendo los mismos campos, el Service solo los reetiqueta internamente al grupo `persona` o `empleado` según corresponda.

## 7. Sin cambios en

- `templates/empleados/index.html.twig` — usa `e.nombre`/`e.apellidos`/`e.cedula` genéricos, que el Repository sigue devolviendo con esos nombres.
- `EmpleadosController` — reenvía lo que el Service le da.
- `findUsuariosDisponibles`, `deactivate`, `insertBancario`, la lógica de `datos_bancarios` completa.
- Las 12 tablas/repositorios/plantillas que también hacen `JOIN empleados` por nombre — Fase 2.
- Esquema de base de datos — ya existe todo lo necesario (`persona`, `distritos`, `cantones`, `provincias`), cero cambios de DDL.

## 8. Pruebas nuevas a cubrir

Reemplazan `tests/Services/EmpleadosServiceContactosAlternosTest.php` (se elimina; sus escenarios de contactos alternos quedan cubiertos aquí como parte normal de `persona`):

- `EmpleadosService::crear()`: arma correctamente los grupos `persona`/`empleado`/`bancario` a partir de un input plano; llama `repo->insert()` con esos tres grupos.
- `EmpleadosService::crear()` rechaza cédula duplicada — verifica que `existsByCedula` se llama con `excludePersonaId = null`.
- `EmpleadosService::actualizar()` llama `existsByCedula` con el `id_persona` del registro actual (obtenido vía `obtener()`), no con `null` — así no se rechaza a sí mismo.
- `EmpleadosService::actualizar()` llama `repo->update()` con `(id_empleado, id_persona, persona, empleado, bancario)` en ese orden.
- `id_distrito`/`id_distrito_alterno`: vacío → `1` sin error; valor no numérico o inexistente → error "distrito seleccionado no es válido" y no se llama `repo->insert()`/`update()`; valor válido → se conserva.
- `id_horario` siempre sale como `1` en el grupo `empleado` (no hay forma de que el request lo cambie).
- Campos `NOT NULL DEFAULT ''` en `persona` (`telefono`, `correo`, `direccion`, `telefono_alterno`, `correo_alterno`, `direccion_alterna`, `telefono_emergencia`, `nombre_contacto_emergencia`): un valor vacío en el request debe producir `''` en el grupo `persona`, nunca `null` — a diferencia de la versión anterior de `EmpleadosService`, que sí usaba `null` para `correo`/`correo_alterno` (columnas que antes aceptaban `NULL`). Cubrir con una prueba explícita para `correo` vacío.

## 9. Convenciones

`RouteContext::fromRequest(...)->getRouteParser()->urlFor()` sin cambios (el Controller no se toca). Nombres de columnas `snake_case`, clases `PascalCase`, métodos `camelCase` — igual que el resto del proyecto. `declare(strict_types=1)` en todo archivo nuevo/modificado.
