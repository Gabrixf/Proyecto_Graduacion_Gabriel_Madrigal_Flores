# Diseño — Estilo visual con el orden de Frappe HRMS (subproyecto 1 de 4)

- **Rama:** `feature/ui-estilo-frappe` (a partir de `develop`, después de integrar `feature/vacaciones-antiguedad`)
- **Fecha:** 2026-10-11
- **Rol:** Primer subproyecto del rediseño de UI/UX inspirado en Frappe HRMS. Solo plantillas Twig y CSS; no cambia controllers, servicios, repositorios, rutas ni esquema.
- **Stack:** Twig 3 + Bootstrap 5 + `public/assets/css/app.css` (bloqueado; sin npm, Node ni frameworks JS).

## 0. Contexto y motivo

El tutor señaló que le preocupa que el sistema no se vea ni funcione como un sistema real. Como referencia se levantó localmente Frappe HRMS (`localhost:8001`), un sistema de RRHH y nómina de código abierto usado en producción. A Gabriel le gustó su orden y quiere llevarlo al sistema en cuatro frentes, cada uno con su propio spec, plan e implementación:

| # | Subproyecto | Estado |
|---|---|---|
| 1 | **Estilo visual** (este documento) | Diseño aprobado |
| 2 | Espacios de trabajo: sidebar con ~6 áreas y página de inicio por área | Pendiente |
| 3 | Listas: barra de filtros funcional, nombre como enlace, acciones fuera de la tabla | Pendiente |
| 4 | Ficha del empleado: pestañas y documentos relacionados | Pendiente; depende de las preguntas 40–47 de la entrevista de seguimiento |

**Decisión de color (validada con maquetas):** se mantienen los colores actuales de la marca (navy `#003876` y rojo `#E31837`, estilo "French Oil"). Se toma de Frappe el *orden y la presentación*, no su paleta gris y negra.

Hoy cada plantilla arma a mano su encabezado (breadcrumb + tarjeta con franja navy) y sus etiquetas de estado con colores sueltos de Bootstrap (`bg-success`, `bg-warning`, … en 25 plantillas). El resultado es que cada pantalla se ve un poco distinta y un mismo estado puede salir de colores diferentes según el módulo.

## 1. Alcance

Validado con maquetas en el compañero visual (`.superpowers/brainstorm/`, no versionado):

**Listas**
1. Encabezado de página con ruta pequeña (ej. `Plataforma › Empleados`), título y conteo de registros (`· 6 registros`), con los botones a la derecha.
2. Etiquetas de estado suaves: fondo claro, punto de color y texto.
3. Tabla plana con borde fino (en vez de tarjeta con sombra) y pie con el conteo.

**Formularios**
4. Encabezado fijo con la ruta, el título y una etiqueta de estado cuando aplica.
5. Botón Guardar arriba a la derecha, siempre visible.
6. Secciones como tarjetas planas con título, en vez de la franja navy del encabezado y las líneas de `legend`.

**Fuera de alcance (va en otros subproyectos):**
- Reorganizar el sidebar en áreas → subproyecto 2. Las rutas del encabezado usan por ahora los grupos actuales del sidebar (Plataforma, Operaciones, Nómina, Administración, Mi cuenta).
- Barra de filtros *funcional*, nombre como enlace a la ficha y quitar los íconos de editar/eliminar de la tabla → subproyecto 3. En este subproyecto las tablas conservan sus columnas y acciones actuales.
- Pestañas en formularios largos → subproyecto 4 (ficha del empleado).
- Modo oscuro, cambio de tipografía o de paleta.

## 2. Componentes

### 2.1 `templates/partials/ui.html.twig` (nuevo)

Cuatro macros (`encabezado_pagina`, `estado`, `etiqueta` y `seccion`, este último como plantilla embebible). Cada plantilla los importa con:

```twig
{% import 'partials/ui.html.twig' as ui %}
```

(En Twig 3 los macros importados en el layout no se heredan a los bloques de las plantillas hijas, así que el import va en cada plantilla.)

Los macros **no** llaman a `url_for`: las URL se resuelven en la plantilla y se pasan como parámetro. Así se pueden probar con Twig solo, sin bootstrapear Slim.

**`ui.encabezado_pagina(titulo, opciones)`**

| Opción | Tipo | Uso |
|---|---|---|
| `ruta` | lista de `{texto, url}` | Migas pequeñas sobre el título; el último elemento puede ir sin `url`. |
| `conteo` | int o null | Si viene, muestra `· N registros` junto al título. |
| `estado` | string o null | Si viene, muestra `ui.estado(estado)` junto al título (formularios y detalles). |
| `acciones` | lista de `{texto, url?, icono?, tipo?, form?, id?, atributos?}` | Botones a la derecha. `tipo` = `primario` (rojo, por defecto) o `secundario` (borde). Si trae `form`, se dibuja `<button type="submit" form="…">` en vez de un enlace. `id` se copia al botón (lo necesita `app.js`, ej. `btnEnviar`). `atributos` es un mapa de atributos extra (ej. `data-bs-toggle`/`data-bs-target` para abrir un modal); si trae `atributos` y no trae `url` ni `form`, se dibuja `<button type="button">`. |

El botón Guardar de los formularios usa `form: '<id del form>'`. Si el `<form>` ya tiene `id` (ej. `formPuesto`, que usa su propio script), se conserva ese; si no, se le agrega `id="form-<modulo>"`. Con el atributo HTML `form` el botón envía el formulario aunque esté fuera de él, sin JavaScript.

**`ui.estado(valor)`**

Dibuja `<span class="lb-estado lb-estado--<tono>"><span class="lb-estado__punto"></span>Texto</span>`. El tono sale de un único mapa dentro del macro:

| Tono | Valores | Dónde aparecen |
|---|---|---|
| `verde` | `activo`, `aprobada`, `pagado` | empleados, usuarios, contratos, solicitudes, nóminas, aguinaldo |
| `ambar` | `pendiente`, `borrador` | solicitudes, nóminas |
| `azul` (navy) | `abierto`, `aprobado`, `calculado` | períodos, nóminas, aguinaldo |
| `rojo` | `rechazada` | solicitudes |
| `gris` | `inactivo`, `cerrado`, `finalizado` | empleados, usuarios, períodos, contratos |

El texto visible es el valor con la primera letra en mayúscula (`aprobada` → `Aprobada`). Un valor que no está en el mapa sale en gris con su propio texto: nunca una etiqueta vacía ni un error. El color nunca va solo; el texto siempre está.

**`ui.etiqueta(texto, tono = 'gris')`**

No todas las etiquetas de hoy son estados: también hay tipos y roles (tipo de feriado, CCSS/INS/Particular, Con goce/Sin goce, Super Admin/Admin/Empleado, acción de auditoría, "Feriado" en horas extra). Para esas, `ui.etiqueta` dibuja la misma etiqueta suave con un tono elegido por la plantilla. `ui.estado` está construido sobre `ui.etiqueta`. Un tono que no existe cae en `gris`.

`templates/partials/eval_badge.html.twig` se mantiene (es una calificación por rango, no un estado), pero se reescribe con las mismas clases `.lb-estado` para que se vea igual que el resto.

**`ui.seccion(titulo)`**

Tarjeta plana con título para agrupar campos. Como necesita contenido adentro, se implementa como plantilla embebible (`templates/partials/seccion.html.twig` con un `{% block cuerpo %}`) y se usa con:

```twig
{% embed 'partials/seccion.html.twig' with {titulo: 'Datos personales'} %}
    {% block cuerpo %} … campos … {% endblock %}
{% endembed %}
```

### 2.2 `public/assets/css/app.css` (se amplía)

Clases nuevas, construidas sobre los tokens existentes (`--lb-navy`, `--lb-red`, `--lb-border`, `--lb-text-2`, …); no se cambia ningún token de color:

- `.lb-page-header` (+ `__ruta`, `__titulo`, `__conteo`, `__acciones`): vive dentro de `.lb-topbar` y ocupa el espacio entre el botón Volver y el chip del usuario. En pantallas angostas (< 576 px) el título queda arriba y los botones abajo a todo el ancho, y el chip del usuario se oculta. Esto importa porque, según la entrevista de seguimiento (P4), los colaboradores usarían el portal desde el celular.
- `.lb-estado` y sus modificadores `--verde`, `--ambar`, `--azul`, `--rojo`, `--gris` (fondo claro, texto oscuro del mismo tono, punto de 6 px).
- `.lb-seccion` (+ `__titulo`): fondo blanco, borde de 1 px `--lb-border`, radio 8 px, sin sombra.
- `.lb-tabla`: tabla plana con encabezado gris claro, borde fino y `.lb-tabla__pie` para el conteo.

## 3. Uso en el layout

Hoy la barra superior del layout (`.lb-topbar`, que ya es `position: sticky`) dibuja el bloque `breadcrumb` y un `<h1>` con el bloque `titulo`. Si el encabezado nuevo fuera en el contenido, el título saldría dos veces. Por eso:

- En `templates/layouts/base.html.twig` se envuelve el `<div>` que contiene `.lb-crumb` y `.lb-title` en un bloque nuevo `{% block encabezado %}…{% endblock %}`. Su contenido por defecto es exactamente el de hoy, así que las plantillas no migradas se ven igual.
- Cada plantilla migrada sobrescribe `{% block encabezado %}` con `ui.encabezado_pagina(…)` y deja de definir `breadcrumb`. Como la barra superior es fija, el encabezado (y su botón Guardar) queda siempre visible al hacer scroll: eso cumple el punto 4 del alcance sin CSS extra.
- El botón de menú móvil, el botón Volver y el chip del usuario de la barra superior no cambian. El bloque `titulo` (el `<title>` de la pestaña) tampoco.
- Al cerrar el grupo 6 ninguna plantilla define ya `breadcrumb`; el bloque se elimina del layout.
- Sidebar, mensajes flash y bloque `scripts` quedan igual.

El conteo sale de la lista que cada controller ya entrega a la vista (`registros|length` o equivalente); no se agrega ningún dato nuevo a los controllers.

## 4. Migración por grupos

Un commit por grupo en `feature/ui-estilo-frappe`. Entre grupo y grupo: capturas + prueba manual de Gabriel en XAMPP antes de seguir.

| # | Grupo | Plantillas |
|---|---|---|
| 1 | Base y Puestos (piloto) | `partials/ui.html.twig`, `partials/seccion.html.twig`, `app.css`, `puestos/index`, `puestos/form` |
| 2 | Mantenimientos | `periodos/*`, `feriados/*`, `contratos/*`, `usuarios/*` |
| 3 | Personal | `empleados/*`, `evaluaciones/*` (incluye `mis_evaluaciones`, `detalle`) y `partials/eval_badge` |
| 4 | Tiempo y ausencias | `solicitudes/*`, `asistencia/*`, `horas_extra/*`, `vacaciones/*`, `incapacidades/*`, `permisos/*` |
| 5 | Nómina | `nominas/*` (incluye `detalle`), `aguinaldo/*`, `liquidacion/*` (incluye `detalle`) |
| 6 | Resto | `dashboard/index`, `reportes/*`, `portal/*` (8 plantillas), `auth/login` |

Puestos va primero porque es el módulo de referencia del proyecto (CLAUDE.md); si el patrón funciona ahí, se copia.

## 5. Pruebas

1. **`tests/Templates/UiMacrosTest.php` (nuevo).** Crea un `Twig\Environment` con `FilesystemLoader('templates')` y renderiza los macros:
   - cada valor del mapa de estados sale con su clase de tono;
   - un valor desconocido sale con `lb-estado--gris` y su propio texto;
   - `encabezado_pagina` con una acción `{form: 'formPuesto'}` dibuja `<button type="submit" form="formPuesto">`, y una acción con `id: 'btnEnviar'` conserva ese `id`;
   - `etiqueta` con un tono inexistente cae en `gris`;
   - con `conteo: 6` aparece `6 registros`; sin `conteo` no aparece;
   - `seccion` dibuja su título y el contenido del bloque.
2. **Suite actual:** `vendor/bin/phpunit` debe seguir en verde en cada grupo (hoy: 141 tests).
3. **Manual, por grupo, en XAMPP:** el Guardar de arriba envía el formulario; los errores de validación (422) se siguen viendo; el estado de cada registro sale con el tono del mapa; la pantalla se ve bien a 375 px de ancho.
4. **Cierre del grupo 6:** `grep -rnE "badge (bg|text-bg)-|badge-fo badge-(ok|pend)|block breadcrumb" templates` no devuelve nada; todos los estados pasan por `ui.estado`, las demás etiquetas por `ui.etiqueta` o `eval_badge`, y ninguna plantilla usa ya el bloque `breadcrumb`. (Las clases `badge-admin`/`badge-emp`/`badge-super` del chip de usuario del layout no cuentan: son el rol del usuario conectado y se quedan.)

## 6. Riesgos y dependencias

- **Rama de partida.** El cambio de vacaciones por antigüedad y la eliminación de `comision` siguen sin commit en `feature/vacaciones-antiguedad`. Hay que probarlos, commitearlos e integrarlos a `develop` antes de crear `feature/ui-estilo-frappe`; si no, las ramas se mezclan.
- **Plantillas especiales.** Dashboard, reportes, colilla y login no son ni lista ni formulario; en el grupo 6 se adaptan caso por caso, usando los mismos componentes donde aplican.
- **JS existente.** `public/assets/js/app.js` busca elementos por `id` (por ejemplo `btnEnviar` y `campoDias` en `portal/solicitud_form`). Al migrar esas plantillas se conservan los `id` existentes.

## 7. TFG

- Las capturas del Cap. V (prototipos y figuras "Pantalla de…") se rehacen **una sola vez**, al terminar los cuatro subproyectos.
- La decisión de diseño se documenta en el Cap. V (Diseño de entradas y de salidas): se tomó Frappe HRMS como referencia de un sistema real de RRHH y nómina, conservando la identidad visual de la empresa.
