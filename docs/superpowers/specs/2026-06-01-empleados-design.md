# Diseño — Módulo Empleados

- **Proyecto:** Sistema web de gestión de nómina — Lubrimotos del Sur (TFG, UIA)
- **Rama:** `feature/empleados`
- **Fecha:** 2026-06-01
- **Módulo:** #3 — Gestionar Empleados + `datos_bancarios`
- **Módulo de referencia:** Puestos / Feriados (patrón Controller → Service → Repository)

---

## 1. Alcance

CRUD de empleados accesible solo por rol `admin`. Incluye:

- Datos personales, de contacto, ubicación, contacto de emergencia, seguros (CCSS/INS) y datos laborales del empleado (tabla `empleados`, 23 columnas).
- Sección de **datos bancarios embebida en el mismo formulario**, asumiendo **una cuenta activa por empleado** (relación 1:1 práctica sobre la tabla `datos_bancarios`, que físicamente permite N por el flag `activa`).
- Vínculo **opcional** a una cuenta de usuario existente (`empleados.id_usuario`) mediante un selector de usuarios rol `empleado` aún no vinculados.
- **Desactivación (soft-delete)** en lugar de borrado físico, para preservar integridad referencial e historial de nómina.
- Registro en `auditoria` de toda operación de escritura (INSERT / UPDATE / DELETE), siguiendo el patrón de Feriados.

**Fuera de alcance:** portal del colaborador, gestión de múltiples cuentas bancarias por empleado, creación automática de cuentas de usuario.

---

## 2. Archivos (patrón de 7 pasos)

1. `src/Repositories/EmpleadosRepository.php`
2. `src/Services/EmpleadosService.php`
3. `src/Controllers/EmpleadosController.php`
4. `templates/empleados/index.html.twig`
5. `templates/empleados/form.html.twig`
6. Registro en `config/dependencies.php` (Repository → Service → Controller)
7. Rutas en `config/routes.php` (rellenar el grupo `/empleados` ya reservado)

---

## 3. Repository — SQL puro (sin lógica de negocio)

`EmpleadosRepository(private readonly PDO $pdo)`

| Método | Descripción |
|---|---|
| `findAll(?string $estado = null): array` | Lista empleados con `JOIN puestos` para el nombre del puesto. Filtra por `estado` ('activo'/'inactivo') o todos si es `null`. Orden por apellidos, nombre. |
| `findById(int $id): ?array` | Empleado + su fila **activa** de `datos_bancarios` (LEFT JOIN sobre `activa = 1`). Devuelve `null` si no existe. |
| `insert(array $empleado, ?array $bancario): int` | Dentro de una **transacción**: inserta en `empleados`; si `$bancario !== null`, inserta la cuenta bancaria. `commit`/`rollBack`. Devuelve `id_empleado`. |
| `update(int $id, array $empleado, ?array $bancario): void` | Transacción: actualiza `empleados`; *upsert* de la cuenta bancaria activa (UPDATE si existe, INSERT si no). |
| `deactivate(int $id, string $fechaSalida): void` | `UPDATE empleados SET estado='inactivo', fecha_salida=:f WHERE id_empleado=:id`. |
| `existsByCedula(string $cedula, ?int $excludeId = null): bool` | Verifica unicidad de cédula (normalizada), excluyendo un ID al editar. |
| `findUsuariosDisponibles(?int $currentUsuarioId = null): array` | Usuarios con `rol='empleado'` no vinculados a ningún empleado (más el actual, al editar), para el `<select>`. Consulta `usuarios LEFT JOIN empleados`. |

**Justificación de la transacción en el Repository:** la operación abarca dos tablas (`empleados` + `datos_bancarios`) y la atomicidad es una preocupación de persistencia, no de negocio. Mantenerla aquí respeta la regla de que el Service nunca toca PDO.

---

## 4. Service — lógica de negocio y validación

`EmpleadosService(private readonly EmpleadosRepository $repo, private readonly PuestosRepository $puestosRepo, private readonly AuditoriaRepository $auditoriaRepo)`

> `PuestosRepository` se **reutiliza** para poblar el dropdown de puestos del formulario.

| Método | Firma |
|---|---|
| `listar(?string $estado = null): array` | Delega en `repo->findAll`. |
| `obtener(int $id): array` | Lanza `RuntimeException('Empleado no encontrado.')` si no existe. |
| `datosFormulario(?int $currentUsuarioId = null): array` | Devuelve `['puestos' => ..., 'usuarios' => ...]` para los selects. |
| `crear(array $datos, int $loggedInId, string $ip): int` | Valida, separa datos de empleado y bancarios, inserta, audita `INSERT`. |
| `actualizar(int $id, array $datos, int $loggedInId, string $ip): void` | Confirma existencia, valida, actualiza, audita `UPDATE`. |
| `eliminar(int $id, int $loggedInId, string $ip): void` | Confirma existencia, desactiva (`estado='inactivo'`, `fecha_salida` = hoy), audita `DELETE`. |

### Reglas de validación (`validar(array $datos): array` de errores)

**Campos obligatorios:**
- `id_puesto` — entero, debe existir (`puestosRepo->findById`).
- `nombre`, `apellidos` — no vacíos, máx 100.
- `cedula` — ver regla de cédula abajo.
- `fecha_nacimiento` — formato `Y-m-d`, en el pasado, edad resultante ≥ 15 años (edad laboral mínima en Costa Rica).
- `genero` — ENUM `('masculino','femenino','otro')`.
- `estado_civil` — ENUM `('soltero','casado','union_libre','divorciado','viudo')`.
- `nacionalidad` — no vacía, máx 50.
- `fecha_ingreso` — formato `Y-m-d` válido.

**Campos opcionales (validados solo si se proveen):**
- `correo` — formato email (`filter_var ... FILTER_VALIDATE_EMAIL`), máx 150.
- `telefono`, `telefono_emergencia` — máx 20.
- `direccion` máx 255; `provincia`, `canton`, `distrito`, `nombre_contacto_emergencia` máx 100/150.
- `numero_asegurado_ccss` máx 20; `numero_poliza_ins` máx 30.
- `id_usuario` — si se provee, entero existente; el Repository garantiza que solo aparecen usuarios disponibles en el select.
- `estado` — ENUM `('activo','inactivo')`; por defecto `'activo'`. Editable en el form (permite reactivar; al volver a `'activo'` se limpia `fecha_salida`).

**Regla de cédula (opción B — CR o DIMEX):**
1. Normalizar: quitar guiones y espacios.
2. Aceptar **9 dígitos** (cédula nacional CR, primer dígito = provincia 1-9) **o** **11–12 dígitos** (DIMEX de extranjero).
3. Debe ser numérica tras normalizar; cualquier otra longitud → error.
4. Unicidad mediante `existsByCedula` (sobre el valor normalizado).

**Datos bancarios (opcionales en bloque):**
- Si **cualquier** campo bancario viene lleno, entonces `banco` (máx 100), `tipo_cuenta` (ENUM `('corriente','ahorros')`) y `numero_cuenta` (máx 30) pasan a ser obligatorios.
- `moneda` — ENUM `('CRC','USD')`, por defecto `'CRC'`.
- `numero_cuenta_iban` — opcional, máx 22.
- Si **ningún** campo bancario viene lleno, se omite la cuenta (`$bancario = null`).

El error de unicidad de cédula a nivel BD (código SQLSTATE `23xxx`) se captura y se traduce a `InvalidArgumentException` con mensaje claro, igual que en `FeriadosService`.

---

## 5. Controller — solo HTTP

`EmpleadosController(private readonly Twig $twig, private readonly EmpleadosService $service)`

Estructura idéntica a `FeriadosController`:

| Método | Ruta | Comportamiento |
|---|---|---|
| `index` | `GET /empleados` | Lee `?estado=` (activo por defecto). Renderiza tabla + flashes. |
| `create` | `GET /empleados/crear` | Form en blanco + `datosFormulario()`. |
| `store` | `POST /empleados/crear` | Lee body + `$_SESSION['usuario_id']` + IP. Éxito → flash + 302; `InvalidArgumentException` → re-render 422 con datos previos. |
| `edit` | `GET /empleados/{id}/editar` | Form poblado; `RuntimeException` → flash error + redirect. |
| `update` | `POST /empleados/{id}/editar` | Igual que `store` para edición. |
| `destroy` | `POST /empleados/{id}/eliminar` | Desactiva; flash success/error + redirect. |

`consumeFlash()` privado idéntico al patrón existente. El Controller nunca toca PDO ni contiene lógica de negocio; obtiene `loggedInId` e `ip` de `$_SESSION`/`$_SERVER` y los pasa al Service.

---

## 6. Templates (Twig, extienden `layouts/base.html.twig`)

### `index.html.twig`
- Tabla: **Nombre completo** · **Cédula** · **Puesto** · **Estado** (badge activo/inactivo) · **Fecha ingreso** · **Acciones** (Editar, Desactivar con confirmación).
- Toggle "Mostrar inactivos" (enlace que alterna `?estado=`).
- Botón "Nuevo empleado".
- Mensajes flash (los muestra el layout base).

### `form.html.twig` (compartido crear/editar)
Form `POST` con secciones agrupadas mediante `<fieldset>`:
1. **Datos personales** — nombre, apellidos, cédula, fecha_nacimiento, género, estado_civil, nacionalidad.
2. **Contacto** — teléfono, correo.
3. **Ubicación** — dirección, provincia, cantón, distrito.
4. **Contacto de emergencia** — nombre_contacto_emergencia, telefono_emergencia.
5. **Seguros** — numero_asegurado_ccss, numero_poliza_ins.
6. **Datos laborales** — id_puesto (select), fecha_ingreso, estado (select).
7. **Cuenta de usuario** — id_usuario (select con opción "— Sin cuenta —").
8. **Datos bancarios** — banco, tipo_cuenta (select), numero_cuenta, numero_cuenta_iban, moneda (select).

Reusa los valores previos (`empleado.*`) para repoblar tras un error 422. Bloque de `errores` arriba del form.

---

## 7. Rutas (`config/routes.php`)

Rellenar el grupo `/empleados` existente (ya envuelto en `RoleMiddleware('admin')` + `AuthMiddleware()`):

```php
$group->get('/empleados',                 [EmpleadosController::class, 'index'])->setName('empleados.index');
$group->get('/empleados/crear',           [EmpleadosController::class, 'create'])->setName('empleados.create');
$group->post('/empleados/crear',          [EmpleadosController::class, 'store'])->setName('empleados.store');
$group->get('/empleados/{id}/editar',     [EmpleadosController::class, 'edit'])->setName('empleados.edit');
$group->post('/empleados/{id}/editar',    [EmpleadosController::class, 'update'])->setName('empleados.update');
$group->post('/empleados/{id}/eliminar',  [EmpleadosController::class, 'destroy'])->setName('empleados.destroy');
```

(El navbar ya enlaza o se actualizará a `empleados.index`.)

---

## 8. Auditoría

Cada `crear`/`actualizar`/`eliminar` llama a `AuditoriaRepository::insert($accion, $loggedInId, 'empleados', $idRegistro, $ip)` con `$accion` ∈ `INSERT`/`UPDATE`/`DELETE`, conforme a Ley 8968 y al patrón de Feriados/Periodos.

---

## 9. Decisiones acordadas

| Decisión | Elección |
|---|---|
| Datos bancarios | Un solo formulario, una cuenta activa por empleado (1:1 práctico). |
| Vínculo a usuario | Selector de usuarios rol `empleado` no vinculados (opcional). |
| Borrado | Soft-delete: desactivar + `fecha_salida`. Sin borrado físico. |
| Validación de cédula | Opción B: cédula nacional CR (9 díg.) o DIMEX (11–12 díg.), normalizando guiones/espacios. |

---

## 10. Pruebas (futuras)

Aunque las pruebas no se implementan en este branch, los métodos de validación del `EmpleadosService` están diseñados como funciones puras (entrada `array` → salida lista de errores) para facilitar futuras pruebas unitarias con PHPUnit, alimentando el capítulo de Pruebas del TFG.
