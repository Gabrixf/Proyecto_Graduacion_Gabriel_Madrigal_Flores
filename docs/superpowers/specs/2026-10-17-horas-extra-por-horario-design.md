# Horas extra detectadas por horario — diseño

**Fecha:** 17/10/2026
**Estado:** aprobado en conversación, pendiente de revisión escrita
**Rama:** `feature/horas-extra-por-horario`

## 1. Objetivo

Hoy una hora extra es una solicitud manual con *fecha + cantidad* y un factor fijo (2.00 si la fecha
es feriado, 1.50 si no). El sistema no sabe a qué hora se trabajó ni qué día de descanso tiene cada
empleado.

Después de este cambio:

- El sistema **detecta las horas extra** comparando la **salida marcada** en la asistencia con el
  **horario** del empleado para ese día.
- El **factor se decide solo** según el tipo de día (normal, feriado o día de descanso).
- El empleado **justifica** por qué salió tarde (en el momento o después, hasta el cierre del periodo).
- El **admin aprueba** lo detectado, con la justificación a la vista, antes de que llegue a la nómina.
- El trabajo en feriado o día de descanso paga su **adicional sencillo** automáticamente.

**Criterios de éxito**

1. Marcar la salida 1 h 40 min después del horario en un día normal produce una solicitud de 1.67 h
   con factor 1.50; en un feriado, factor 3.00.
2. Ninguna hora extra se paga sin justificación del empleado y aprobación del admin.
3. Una solicitud no justificada al cierre del periodo queda rechazada y no se paga.
4. La nómina muestra cada línea con fecha, horas y factor, y la CCSS se calcula sobre ellas.

## 2. Base legal

| Regla | Fuente |
|---|---|
| Hora extra = salario + 50 % | Código de Trabajo, Art. 139; Constitución, Art. 58 |
| Jornada ordinaria + extra ≤ 12 h diarias | Código de Trabajo, Art. 140 |
| Jornada diurna ≤ 8 h/día, 48 h/semana; acumulativa hasta 10 h/día | Código de Trabajo, Art. 136 |
| Feriado trabajado se paga doble | Código de Trabajo, Art. 149 |
| Con pago mensual/quincenal el feriado ya está en el salario; si se trabaja se agrega un pago sencillo | MTSS DAJ-AE-184-11 |
| Horas extra en feriado = "tiempo y medio doble, o sea pago triple" | MTSS CP-005-2026; CARTA-MTSS-DAJ-AER-1076-2025 |
| Día de descanso trabajado se paga doble | Código de Trabajo, Art. 152; MTSS DAJ-AE-20-12 |
| Lista vigente de feriados (12 de octubre ya no es feriado) | Código de Trabajo, Art. 148 (Ley 10050 de 2021) |
| Valor hora = salario mensual ÷ 30 ÷ 8 (= ÷ 240) | MTSS DAJ-AE-31-05 |

**Por analogía, sin fuente directa:** horas extra en el día de descanso = factor 3.00 (mismo
razonamiento que el feriado: 1.5 × doble). Se documenta así en el TFG.

## 3. Decisiones tomadas

| Pregunta | Decisión |
|---|---|
| De dónde salen las horas | De la asistencia (salida marcada). |
| Desde cuándo una hora es extra | Después de la hora de salida del horario del empleado para ese día. |
| Papel de la aprobación | El sistema detecta; el admin aprueba lo detectado. |
| Justificación | Obligatoria para que se pague; puede escribirse después, hasta el cierre del periodo. |
| Factor en feriado | 3.00 (autorizado por Gabriel el 17/10/2026; reemplaza el 2.00 de `CLAUDE.md`). |

## 4. Diseño

### 4.1 Módulo Horarios

**Esquema**

- `horarios` conserva `id_horario`, `nombre` (UNIQUE) y `dias_semana` (texto descriptivo).
  Se eliminan `hora_entrada` y `hora_salida`: pasan a `horario_dias`.
- Tabla nueva:

```sql
CREATE TABLE IF NOT EXISTS `horario_dias` (
    `id_horario_dia` INT     NOT NULL AUTO_INCREMENT,
    `id_horario`     INT     NOT NULL,
    `dia_semana`     TINYINT NOT NULL COMMENT '1 = lunes ... 7 = domingo (ISO-8601)',
    `hora_entrada`   TIME    NOT NULL,
    `hora_salida`    TIME    NOT NULL,
    PRIMARY KEY (`id_horario_dia`),
    UNIQUE KEY `uq_horario_dia` (`id_horario`, `dia_semana`),
    CONSTRAINT `fk_horario_dias_horario` FOREIGN KEY (`id_horario`) REFERENCES `horarios` (`id_horario`)
);
```

- Un día **sin fila** en `horario_dias` es **día de descanso** para ese horario.
- El horario centinela `id_horario = 1` ("Sin asignar") no tiene días. Un empleado con ese horario
  **no genera detección**; la ficha del empleado y la pantalla de asistencia lo advierten.

**Código** (patrón del módulo Puestos)

- `HorariosRepository`, `HorariosService`, `HorariosController`, `templates/horarios/{index,form}.html.twig`.
- Rutas en el grupo `/mantenimientos` (solo `super_admin`).
- Formulario: 7 filas (lunes a domingo), cada una con check "trabaja", entrada y salida.
- Validaciones en `HorariosService`:
  - al menos un día trabajado;
  - salida posterior a la entrada en cada día;
  - máximo 10 h en un día (Art. 136, jornada acumulativa);
  - máximo 48 h en la semana (Art. 136);
  - no se puede eliminar un horario asignado a empleados.
- El horario se asigna en la ficha del empleado (`empleados.id_horario`, ya existe).

**Efectos en lo ya construido**

- `DiasHabiles` / `DiasHabilesService` (vacaciones) y `CalendarioAsistencia` reciben los días de
  descanso del horario del empleado en lugar de suponer el domingo. Sin horario asignado se mantiene
  el domingo como descanso.

### 4.2 `ClasificadorHoras` (regla única)

Helper puro en `src/Helpers/ClasificadorHoras.php`, sin BD ni reloj.

**Entrada:** fecha, hora de entrada y de salida marcadas, horario del día (`entrada`, `salida`) o
`null` si es día de descanso, y si la fecha es feriado.

**Salida:**

| Campo | Significado |
|---|---|
| `tipo_dia` | `normal`, `feriado` o `descanso` |
| `horas_ordinarias` | dentro del horario |
| `horas_dia_especial` | horas ordinarias trabajadas en feriado o descanso (llevan adicional sencillo) |
| `horas_extra` | ver tabla |
| `factor_extra` | 1.50 o 3.00 |
| `excede_12h` | ordinarias + extra > 12 |

| Tipo de día | `horas_dia_especial` | `horas_extra` | `factor_extra` |
|---|---|---|---|
| Normal (día con horario, no feriado) | 0 | salida marcada − salida del horario | 1.50 |
| Feriado en día con horario | horas hasta la salida del horario | salida marcada − salida del horario | 3.00 |
| Descanso, o feriado en día sin horario | mín(horas trabajadas, 8) | máx(horas trabajadas − 8, 0) | 3.00 |

Reglas comunes:

- **Tolerancia:** si `horas_extra` < 0.25 (15 min) se devuelve 0.
- Las horas se calculan en minutos y se redondean a 2 decimales (1 h 40 min = 1.67).
- Llegar antes de la entrada del horario **no** cuenta como extra (fuera de alcance).
- Los factores se inyectan desde `config/settings.php`: `factor_he_ord` = 1.50 y
  `factor_he_especial` = 3.00 (antes `factor_he_feri` = 2.00). Las claves equivalentes en
  `parametros_legales` se actualizan para que `horas_extra.id_parametro` siga resolviendo.

### 4.3 Detección y justificación

**Cuándo:** después de guardar una salida se llama a `ClasificadorHoras` en tres puntos:

- `MarcaAsistenciaService::marcarSalida` (el empleado marca la salida);
- `MarcaAsistenciaService::corregirSalida` (el empleado escribe una salida que olvidó: se le pide la
  misma justificación del paso 1-2 de abajo);
- `AsistenciaService::actualizar` (RRHH corrige la asistencia: solo aplica la regla de "correcciones
  posteriores", sin pedir justificación).

**Esquema** (cambios en `solicitudes`):

- `estado` ENUM agrega `por_justificar`.
- Columna nueva `origen` ENUM('empleado','asistencia') NOT NULL DEFAULT 'empleado'.

**Flujo del empleado** (portal `/mi-asistencia`):

1. Al marcar la salida, si el clasificador devuelve `horas_extra` > 0, el portal muestra un aviso
   antes de confirmar. En un día normal o feriado con horario: *"Tu horario de hoy termina a las
   17:00 y estás marcando a las 18:40 (1 h 40 min después). ¿Por qué saliste tarde?"*. En un día de
   descanso (o feriado sin horario): *"Hoy no tenés horario; trabajaste 9 h 30 min y lo que pasa de
   8 h cuenta como horas extra. ¿Por qué?"*. Si `horas_extra` es 0, no se muestra aviso aunque sea un
   día especial (el adicional sencillo no necesita justificación).
2. Opciones:
   - **Trabajé horas extra** (texto obligatorio, máx. 255): crea la solicitud `pendiente`,
     `origen = asistencia`, `tipo = horas_extra`, `fecha_inicio = fecha_fin =` día trabajado,
     `horas` = horas extra detectadas, `motivo` = texto.
   - **No fue trabajo:** marca la salida y no crea solicitud.
   - **Justificar después:** crea la solicitud `por_justificar` con `motivo` vacío. En *Mis
     solicitudes* aparece con el botón **Justificar**, que pide el texto y la pasa a `pendiente`.
3. El adicional sencillo por feriado o descanso trabajado **no** depende de esta elección (ver 4.5).

**Correcciones posteriores de la asistencia:**

- Solicitud `por_justificar` o `pendiente` del mismo empleado y fecha con `origen = asistencia`:
  se recalculan las horas. Si la corrección deja 0 horas extra, la solicitud se elimina.
- Solicitud ya `aprobada`: no se modifica; se marca para que el admin vea el aviso
  *"La asistencia cambió después de aprobar"* (comparando las horas aprobadas con las que daría el
  clasificador hoy).

**Cierre de periodo:** al cerrar un periodo (`PeriodosService`), toda solicitud `por_justificar` con
fecha dentro del periodo pasa a `rechazada` con `observacion_admin = 'No se justificó antes del
cierre del periodo'` y queda auditada.

**Solicitud manual:** el formulario del portal deja de ofrecer el tipo `horas_extra` (las genera la
asistencia). El formulario de admin lo conserva para casos excepcionales (sin marca de asistencia).

### 4.4 Aprobación

En **Solicitudes** (admin) se agrega el filtro *"Horas extra detectadas"* (`origen = asistencia`,
excluye `por_justificar`). Cada fila muestra: empleado, fecha, horario del día, entrada/salida
marcadas, tipo de día, horas extra × factor, monto estimado, justificación y avisos (12 h, asistencia
corregida).

- **Aprobar:** el admin puede reducir las horas, nunca superar las detectadas. Al aprobar se vuelve a
  clasificar con la asistencia actual y se crea el registro en `horas_extra` con `factor_recargo`,
  `id_parametro` y `id_periodo`.
- **Rechazar:** observación obligatoria.

**Periodo en que se paga** (`horas_extra.id_periodo` = periodo de pago; `fecha` = día trabajado):

| Nómina del periodo de la fecha | Resultado |
|---|---|
| No generada | Se paga cuando se genere. |
| Borrador | Se agrega la línea a esa nómina y se recalcula (incluida la CCSS). |
| Aprobada o pagada | Se asigna al próximo periodo abierto; descripción "Horas extra del dd/mm/aaaa (pago posterior)". |

`excede_12h` se muestra como aviso; no bloquea (las horas ya se trabajaron y se deben pagar).

### 4.5 Nómina

- **Horas extra:** sin cambio de lógica (se leen de `horas_extra` por `id_periodo`). Descripción:
  *"Horas extra 15/10/2026: 1.67 h × 1.50"*.
- **Adicional sencillo:** al generar la nómina, por cada asistencia del periodo con
  `horas_dia_especial` > 0 se agrega un ingreso `horas_dia_especial × valor hora × 1`:
  - tipo `feriado` → *"Feriado trabajado 15/09/2026: 8 h"*;
  - tipo nuevo `descanso_trabajado` → *"Día de descanso trabajado 18/10/2026: 5 h"*.
- Ambas líneas son salario: entran en la base de la CCSS y en el aguinaldo.

**Esquema:** `ingresos_nomina.tipo` agrega `descanso_trabajado`.

## 5. Datos

- `database/schema.sql`: `horario_dias`; columnas eliminadas de `horarios`; `solicitudes.estado`
  con `por_justificar`; `solicitudes.origen`; `ingresos_nomina.tipo` con `descanso_trabajado`.
- `database/migraciones/2026-10-17-horas-extra-por-horario.sql`: aplica esos cambios sobre una base
  existente (incluye mover la entrada/salida actual de `horarios` a `horario_dias`).
- `database/seed.sql` (feriados, primer paso): eliminar `2026-10-12` y `2027-10-12`; Jueves y Viernes
  Santo 2026 = `2026-04-02` y `2026-04-03`; actualizar el comentario del Art. 148.
- `database/demo.php`: horario de Lubrimotos (L–V 08:00–17:00, S 08:00–12:00) asignado a todos;
  horas extra generadas desde la asistencia, con al menos un caso aprobado, uno por justificar, uno
  rechazado y un feriado trabajado.
- `CLAUDE.md`: actualizar `factor_he_feri` → `factor_he_especial = 3.00` con la fuente.

## 6. Pruebas

- `ClasificadorHorasTest`: cada fila de la tabla 4.2, tolerancia (14 y 15 min), salida exacta,
  feriado en día sin horario, sin horario asignado, tope de 12 h, redondeo de minutos.
- `HorariosServiceTest`: validaciones de 10 h/día y 48 h/semana, al menos un día, no eliminar
  asignado.
- Detección: crea `pendiente` / `por_justificar` / nada según la elección; recalcula al corregir;
  no toca las aprobadas.
- Cierre de periodo: `por_justificar` → `rechazada`.
- Aprobación: tope de horas, factor recalculado, asignación de periodo según el estado de la nómina.
- Nómina: líneas de horas extra y adicional sencillo; CCSS sobre el bruto que las incluye.
- Prueba manual de punta a punta en XAMPP: marcar tarde → justificar → aprobar → ver en la nómina.

## 7. Orden de implementación

Cada paso en su rama `feature/*` desde `develop`, con checkpoint antes del siguiente.

1. Corrección de feriados (12 de octubre, Semana Santa 2026).
2. Módulo Horarios + `horario_dias` + uso del día de descanso en vacaciones y calendario.
3. `ClasificadorHoras` + factor 3.00.
4. Detección, justificación y cierre de periodo.
5. Aprobación, pago posterior y adicional sencillo en la nómina.

## 8. Fuera de alcance

- Entrada anticipada como hora extra.
- Jornadas mixta y nocturna (Lubrimotos trabaja jornada diurna).
- Validar las 12 h antes de que ocurran (solo se avisa).
- Tasas de la CCSS 2026 y demás hallazgos de la auditoría legal: se tratan aparte.
