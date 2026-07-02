# Diseño — Autoservicio de Solicitudes (Portal del Empleado)

- **Rama:** `feature/portal-solicitudes` (a partir de `feature/reportes`)
- **Fecha:** 2026-06-30
- **Rol:** Extensión del módulo Solicitudes (`2026-06-01-solicitudes-design.md`) y del Portal (`2026-06-11-reportes-portal-design.md`). No crea tablas nuevas.
- **Patrón:** Controller → Service → Repository (igual que el resto del proyecto).

## 0. Contexto y motivo

Hoy solo el administrador crea y resuelve solicitudes (`solicitudes/*`, admin-only). El empleado únicamente consulta resultados (Portal, solo lectura). Se detectó que:

- El Alcance Funcional (Capítulo I del TFG) ya describe autoservicio: *"El empleado realizará la solicitud a través del sistema... El administrador revisará la solicitud y podrá aprobarla o rechazarla."* (Horas Extra, Vacaciones, Permisos).
- Los Casos de Uso CU-07 (Horas Extra) y CU-08 (Vacaciones) en Capítulo V contradicen eso: `Actores: Administrador`, con el administrador como único actor del flujo normal.
- Los dos TFG de referencia consultados (Bach-INF-SO-26013) y el software comercial de RRHH para pymes (Odoo, Woffu, Cezanne HR, PayFit) usan el patrón estándar: empleado solicita → responsable aprueba/rechaza.

Se decidió alinear código y documento hacia autoservicio, ya que es el patrón esperado y el que el propio Capítulo I ya describe.

## 1. Alcance

El empleado, desde el Portal, puede:
1. Ver únicamente sus propias solicitudes (horas_extra/vacaciones/permiso), cualquier estado.
2. Crear una solicitud nueva de cualquiera de los tres tipos.
3. Editar o cancelar (eliminar) una solicitud propia **mientras siga `pendiente`**.

El administrador **no cambia**: sigue viendo y resolviendo (aprobar/rechazar) todas las solicitudes de todos los empleados desde `/solicitudes`, incluidas las que el empleado cree por sí mismo — no hay bandeja separada.

**Fuera de alcance (YAGNI):** notificaciones push/email (no hay infraestructura de correo en el stack; "notificar" = aparecer en el listado de pendientes del admin, como ya ocurre hoy), doble nivel de aprobación, autoaprobación de ciertos tipos.

## 2. Base de datos

Sin cambios. `solicitudes` ya tiene todas las columnas necesarias (`id_empleado`, `tipo`, `estado`, `fecha_inicio`, `fecha_fin`, `horas`, `motivo`).

## 3. Repository — cambios

**`SolicitudesRepository::findAll`** — agregar parámetro opcional `?int $idEmpleado = null` al final de la firma; cuando no es null, agrega `AND s.id_empleado = :idEmpleado` al WHERE existente. Filtros `tipo`/`estado`/`q` se mantienen intactos y combinables.

No se requieren más cambios: `insert`, `update`, `delete`, `findById` ya operan por `id_empleado`/`id_solicitud` sin distinguir quién los llama.

## 4. Service — cambios

**`SolicitudesService`**
- `listar(?string $tipo, ?string $estado, ?string $q, ?int $idEmpleado = null)`: pasa `$idEmpleado` a `findAll`.
- `actualizar(int $id, array $datos, int $loggedInId, string $ip, ?int $ownerIdEmpleado = null)`: después de `obtener($id)`, si `$ownerIdEmpleado !== null` y `(int)$actual['id_empleado'] !== $ownerIdEmpleado` → `throw new RuntimeException('No tiene permiso para modificar esta solicitud.')`, **antes** de la validación del estado `pendiente`.
- `eliminar(int $id, int $loggedInId, string $ip, ?int $ownerIdEmpleado = null)`: mismo chequeo de dueño al inicio, antes de las reglas existentes (`estado==='aprobada'`, `countDependientes`).
- `crear`, `obtener`, `datosFormulario`: sin cambios de firma. El Portal nunca deja que el usuario elija `id_empleado`: el controller lo inyecta en `$datos['id_empleado']` antes de llamar a `crear()`, sobrescribiendo cualquier valor que llegara en el POST.

Admin sigue llamando estos mismos métodos con `$ownerIdEmpleado = null` (sin restricción) — cero cambios en `SolicitudesController`.

**`PortalService`** — un método nuevo, delgado:
```php
public function idEmpleado(int $idUsuario): ?int
{
    return $this->repo->idEmpleadoPorUsuario($idUsuario);
}
```
(El repository ya expone `idEmpleadoPorUsuario`; solo faltaba exponerlo a través del Service para que el Controller pueda usarlo — Controllers no tocan Repository directamente.)

## 5. Controller — `PortalController`, métodos nuevos

Todos resuelven `$idEmpleado = $this->portalService->idEmpleado((int) $_SESSION['usuario_id'])` primero; si es `null` (usuario sin empleado vinculado) → flash_error + redirect a `portal.solicitudes` en vez de 500.

- `solicitudes(Request, Response)`: lista propia. Query param opcional `?estado=`. Llama `$this->solicitudesService->listar(null, $estado, null, $idEmpleado)`.
- `crearSolicitud(Request, Response)`: GET, renderiza formulario vacío (sin selector de empleado).
- `guardarSolicitud(Request, Response)`: POST. `$datos = (array) $request->getParsedBody(); $datos['id_empleado'] = $idEmpleado;` (sobrescribe cualquier valor enviado) → `$this->solicitudesService->crear($datos, $loggedInId, $ip)`. Mismo patrón try/catch `InvalidArgumentException` que el resto del proyecto.
- `editarSolicitud(Request, Response, array $args)`: GET. `obtener($id)`; si `id_empleado !== $idEmpleado` o `estado !== 'pendiente'` → flash_error + redirect (no distinguir el motivo exacto al usuario, por seguridad — no revelar si la solicitud es de otro empleado o simplemente ya fue resuelta).
- `actualizarSolicitud(Request, Response, array $args)`: POST. `actualizar($id, $datos, $loggedInId, $ip, $idEmpleado)` (pasa `$idEmpleado` como `$ownerIdEmpleado`).
- `eliminarSolicitud(Request, Response, array $args)`: POST. `eliminar($id, $loggedInId, $ip, $idEmpleado)`.

`PortalController` necesita una nueva dependencia inyectada: `SolicitudesService` (agregar al constructor junto a `PortalService`).

## 6. Templates nuevas

- **`templates/portal/mis_solicitudes.html.twig`**: tabla *Tipo · Inicio · Fin · Horas · Estado (badge) · Motivo · Acciones*. Botón "Nueva solicitud" arriba. Acciones (Editar/Cancelar) solo visibles si `estado === 'pendiente'`. Sin columna Empleado (es siempre el mismo), sin acciones Aprobar/Rechazar (privilegio exclusivo del admin en `/solicitudes`).
- **`templates/portal/solicitud_form.html.twig`**: igual que `solicitudes/form.html.twig` pero **sin** el `<select>` de empleado. Campos: tipo, fecha_inicio, fecha_fin, horas, motivo. Mismas notas condicionales ("horas: solo horas extra", "fecha fin: vacaciones/permiso").

## 7. Rutas

Nuevo grupo bajo `/portal/solicitudes`, protegido solo por `AuthMiddleware` (no `RoleMiddleware('admin')` — está pensado para rol `empleado`, pero no se excluye admin explícitamente, igual que el resto del Portal):

```
GET  /portal/solicitudes              → portal.solicitudes
GET  /portal/solicitudes/crear        → portal.solicitudes.crear
POST /portal/solicitudes/crear        → portal.solicitudes.guardar
GET  /portal/solicitudes/{id}/editar  → portal.solicitudes.editar
POST /portal/solicitudes/{id}/editar  → portal.solicitudes.actualizar
POST /portal/solicitudes/{id}/eliminar→ portal.solicitudes.eliminar
```

Navbar del Portal: agregar enlace "Mis Solicitudes" junto a los existentes (Mi Perfil, Mis Colillas, Mis Vacaciones, Mi Asistencia).

## 8. Auditoría

Sin cambios: `crear`/`actualizar`/`eliminar` ya registran INSERT/UPDATE/DELETE sobre `'solicitudes'` vía `AuditoriaRepository`, independientemente de si el llamante es el Controller admin o el Portal.

## 9. Pruebas nuevas a cubrir

- `SolicitudesRepository::findAll` con `$idEmpleado` filtra correctamente y es combinable con `tipo`/`estado`.
- `SolicitudesService::actualizar`/`eliminar` lanzan `RuntimeException` cuando `$ownerIdEmpleado` no coincide con el dueño real, **incluso si la solicitud está pendiente** (el chequeo de dueño va antes que el de estado).
- `SolicitudesService::crear` ignora cualquier `id_empleado` que el Portal intente enviar manipulando el HTML — se verifica pasando un `$datos['id_empleado']` deliberadamente distinto al que el Controller inyecta y confirmando que se persiste el inyectado.
- `PortalController` con usuario sin empleado vinculado (`idEmpleado === null`) no rompe con 500 en ninguna de las 5 rutas nuevas.

## 10. Cambios pendientes en el documento del TFG (fuera de este código, a coordinar aparte)

- **CU-07 (Horas Extra)** y **CU-08 (Vacaciones)**: cambiar `Actores` de "Administrador" a "Empleado, Administrador"; reescribir "Flujo Normal" para que inicie con el empleado registrando la solicitud y continúe con el administrador aprobando/rechazando (mismo patrón narrativo que ya usa el Alcance Funcional).
- **Permisos no tiene Caso de Uso propio** — se verificaron los 13 CU existentes (CU-01 a CU-13) y ninguno cubre el módulo Permisos, pese a que sí tiene Alcance Funcional, controlador y servicio propios. Se recomienda agregar **CU-14: Gestionar permisos laborales** (`Actores: Empleado, Administrador`), siguiendo el mismo formato que CU-07/CU-08 ya corregidos.
- Revisar si el diagrama de secuencia "flujo de aprobación de solicitudes" (Capítulo V, Diseño) asume actor único admin y necesita un carril (lane) adicional para el empleado.
- La sección Programación no necesita cambios: las figuras de código ya existentes son a nivel de servicio y no dependen de qué controller las invoca.

## 11. Convenciones

`url_for`/`base_path` en plantillas; `RouteContext::fromRequest(...)->getRouteParser()->urlFor()` en el controller — igual que el resto del proyecto. Sin `path_for`/`base_url`/`Location` hardcodeado.
