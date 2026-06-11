# Diseño — Módulo 13: Consultas / Reportes + Portal del Empleado

**Fecha:** 2026-06-11
**Rama:** `feature/reportes`
**Estado de aprobación:** aprobado por Gabriel (sesión 2026-06-11)

## 1. Objetivo

Cerrar el último módulo del sistema: consultas y reportes administrativos de solo
lectura, más el portal de autoconsulta del empleado (los tres enlaces pendientes del
sidebar). Con esto los 13 módulos del TFG quedan implementados.

## 2. Alcance

Dos módulos nuevos, ambos de **solo lectura**:

1. **Reportes** (solo admin) — 4 reportes bajo `/reportes`.
2. **Portal** (cualquier usuario autenticado) — Mi Perfil, Mis Colillas, Mis Vacaciones.

No hay escrituras, por lo que **no se registra auditoría**: la bitácora audita
INSERT/UPDATE/DELETE/LOGIN/LOGOUT, no consultas.

**Fuera de alcance:** exportación CSV, PDF generado en servidor, gráficos,
paginación de reportes, comparativas de evaluaciones entre años.

## 3. Reportes admin

Rutas bajo `/reportes`, envueltas en `RoleMiddleware('admin')` + `AuthMiddleware`.
Los filtros viajan por **GET** (query string): las consultas deben poder recargarse,
compartirse e imprimirse desde la URL.

| Ruta | Nombre de ruta | Reporte | Filtros |
|---|---|---|---|
| `GET /reportes` | `reportes.index` | Hub con 4 tarjetas | — |
| `GET /reportes/planilla` | `reportes.planilla` | Planilla por período | `periodo` (id) |
| `GET /reportes/historial` | `reportes.historial` | Historial por empleado | `empleado` (id), `desde`, `hasta` |
| `GET /reportes/costos` | `reportes.costos` | Costos patronales | `periodo` (id) |
| `GET /reportes/auditoria` | `reportes.auditoria` | Bitácora de auditoría | `accion`, `usuario` (id), `desde`, `hasta` |

### 3.1 Planilla por período

Una fila por empleado con nómina en el período seleccionado:
salario bruto (SUM de `ingresos_nomina`), total deducciones (SUM de
`deducciones_nomina` **excluyendo** `CCSS_patronal`, que no se descuenta al
empleado), salario neto (bruto − deducciones) y estado de la nómina.
Fila final de totales. Sin período seleccionado se muestra el formulario de filtro
con la tabla vacía.

Fuente: `nominas` JOIN `empleados`, agregados de `ingresos_nomina` y
`deducciones_nomina`.

### 3.2 Historial por empleado

Para un empleado y un rango de fechas (por defecto: año actual), secciones:

- **Nóminas**: por período (fechas del período dentro del rango), bruto/deducciones/neto/estado.
- **Horas extra**: fecha, cantidad, factor de recargo, monto.
- **Vacaciones**: rango, días tomados.
- **Incapacidades**: rango, tipo.
- **Permisos**: fecha, con/sin goce salarial.

Cada sección con su tabla; las vacías muestran "Sin registros en el rango".

### 3.3 Costos patronales

Una fila por empleado con nómina en el período: salario bruto, CCSS patronal
(SUM de `deducciones_nomina` tipo `CCSS_patronal`), provisión de aguinaldo
(bruto ÷ 12, calculado en SQL con ROUND(...,2)), costo total
(bruto + CCSS patronal + provisión). Fila de totales.

### 3.4 Bitácora de auditoría

Tabla filtrable de `auditoria` JOIN `usuarios`: fecha, usuario, acción, tabla
afectada, registro, IP, detalle. Limitada a los **200 registros más recientes**
que cumplan los filtros (sin paginación — suficiente para la PyME y el TFG).

## 4. Portal del empleado

Rutas con solo `AuthMiddleware`. Toda consulta se resuelve **filtrando por el
`id_usuario` de la sesión en el propio SQL** (`empleados.id_usuario = :u`):
un empleado no puede ver datos de otro aunque manipule IDs. Si el usuario no
tiene empleado vinculado (p. ej. un admin), se muestra un estado vacío amigable.

| Ruta | Nombre de ruta | Vista |
|---|---|---|
| `GET /mi-perfil` | `portal.perfil` | Datos personales + puesto + datos bancarios (solo lectura; derecho de acceso, Ley 8968) |
| `GET /mis-colillas` | `portal.colillas` | Lista de nóminas propias en estado `aprobado` o `pagado` (los borradores no aparecen) |
| `GET /mis-colillas/{id}` | `portal.colilla` | Colilla: cabecera + líneas de ingresos y deducciones + botón Imprimir. 404→redirect a la lista si el id no es del empleado |
| `GET /mis-vacaciones` | `portal.vacaciones` | Saldo por año (`saldo_vacaciones`) + historial de vacaciones disfrutadas |

El grupo vacío `/mi-perfil` que hoy existe en `config/routes.php` se reemplaza por
estas rutas.

## 5. Arquitectura

Patrón estricto del proyecto (Controller → Service → Repository, PDO raw), dos tríos:

```
src/Repositories/ReportesRepository.php   ← SQL: JOINs y agregados (SUM, GROUP BY)
src/Services/ReportesService.php          ← valida filtros, arma estructuras para Twig
src/Controllers/ReportesController.php    ← lee query params, renderiza

src/Repositories/PortalRepository.php     ← SQL filtrado por id_usuario
src/Services/PortalService.php            ← reglas (estados visibles, saldos)
src/Controllers/PortalController.php      ← sesión → service → Twig
```

Templates:

```
templates/reportes/index.html.twig        ← hub de tarjetas
templates/reportes/planilla.html.twig
templates/reportes/historial.html.twig
templates/reportes/costos.html.twig
templates/reportes/auditoria.html.twig
templates/portal/mi_perfil.html.twig
templates/portal/mis_colillas.html.twig
templates/portal/colilla.html.twig
templates/portal/mis_vacaciones.html.twig
```

Sidebar (`templates/layouts/base.html.twig`): conectar el enlace "Reportes" (admin)
a `reportes.index`, y "Mi Perfil" / "Mis Colillas" / "Mis Vacaciones" (empleado) a
sus rutas.

### Validación de filtros (en los Services)

- IDs numéricos (`periodo`, `empleado`, `usuario`): si no son numéricos o no existen → se ignoran (reporte sin selección).
- Fechas `desde`/`hasta`: formato válido (`strtotime`); si `desde > hasta` se intercambian.
- `accion`: debe ser uno de los valores del ENUM de `auditoria`; otro valor → se ignora.
- Filtros inválidos nunca producen 500: la vista muestra el formulario con aviso y tabla vacía.

## 6. Vista imprimible

- Bloque `@media print` en `public/assets/css/app.css`: oculta `.sidebar`, `.topbar`,
  `.no-print` (botones, formularios de filtro) y expande el contenido a todo el ancho.
- Botón "Imprimir" (`onclick="window.print()"`, clase `no-print`) en cada reporte y
  en la colilla.
- "Guardar como PDF" del navegador cubre la necesidad de PDF. Cero dependencias nuevas.

## 7. Manejo de errores

- Período sin nóminas / rango sin datos → tabla vacía con aviso, nunca error.
- `mis-colillas/{id}` ajeno o inexistente → flash error + redirect a `portal.colillas`.
- Usuario sin empleado vinculado → tarjeta "Su usuario no está vinculado a un empleado".

## 8. Pruebas

No se introduce cálculo puro nuevo (la provisión bruto÷12 vive en SQL), por lo que
no hay clase calculadora ni tests unitarios nuevos. Verificación:

1. `php -l` en cada archivo PHP nuevo/modificado.
2. Suite PHPUnit completa sigue verde.
3. Smoke test: las 9 rutas nuevas responden 302 sin sesión (middleware activo, sin 500).
4. Prueba manual en navegador: queda en el backlog general del proyecto, como los
   demás módulos.

## 9. Cierre del proyecto

Al completar este módulo se actualiza la fila 13 de CLAUDE.md a
"✅ Completo (pendiente prueba manual)". Los 13 módulos quedan implementados;
el trabajo restante del TFG es la prueba manual integral y el documento académico.
