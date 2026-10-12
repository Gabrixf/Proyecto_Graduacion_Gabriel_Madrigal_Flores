# Marcar asistencia desde el portal — Diseño

**Fecha:** 2026-10-12
**Rama:** `feature/marcar-asistencia`
**Alcance:** parte 1 de 3. Las partes 2 (horarios y tolerancia de 15 min) y 3 (deducción en la nómina) tienen su propio ciclo.

## Objetivo

Que cada empleado registre su entrada y su salida desde el portal, y que vea su asistencia del mes en un calendario con un resumen. Hoy la asistencia solo la registra RRHH desde el módulo de admin, y el portal solo la muestra en una tabla.

## Decisiones tomadas con Gabriel

| Tema | Decisión |
|---|---|
| Vista | Opción C: botón de marcar, tres números del mes y calendario mensual |
| Desde dónde se marca | Desde cualquier lugar, con el usuario del empleado; la hora la pone el servidor |
| Días hábiles | Lunes a sábado (domingo libre), mientras no existan horarios |
| Sin período de pago para la fecha | El sistema abre la quincena automáticamente |
| Salida olvidada | El empleado la escribe después; queda marcada como manual |
| Enfoque | Reusar la tabla `asistencia` (una fila por empleado y día) |

## Fuera de alcance

- Horarios, tardías y tolerancia de 15 minutos (parte 2).
- Deducción proporcional en la nómina (parte 3). La nómina hoy no usa la asistencia y no cambia.
- Varias marcas por día (por ejemplo, almuerzo).
- Restringir el lugar o la red desde donde se marca.
- Turnos que pasan de medianoche.

## Datos

Un solo cambio de esquema:

```sql
ALTER TABLE `asistencia`
    ADD COLUMN `salida_manual` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = la salida la escribió el empleado; 0 = la puso el servidor o RRHH'
        AFTER `horas_trabajadas`;
```

- Se actualiza `database/schema.sql` y se agrega la migración `database/asistencia_salida_manual.sql`.
- **Un día "sin salida"** es una fila con `hora_salida = '00:00:00'` y `horas_trabajadas = 0`. Así ya funciona el esquema, que no acepta nulos.
- La regla UNIQUE(`id_empleado`, `fecha`) que ya existe impide marcar dos entradas el mismo día.

## Piezas

Mismo patrón que el resto del sistema: Controller → Service → Repository. Todos los archivos usan `declare(strict_types=1)`.

### `App\Helpers\CalendarioAsistencia` (función pura)

```php
public function construir(
    string $mes,                     // 'YYYY-MM'
    array $registros,                // filas de asistencia del mes: fecha, hora_entrada, hora_salida, horas_trabajadas, salida_manual
    array $feriados,                 // ['YYYY-MM-DD' => 'Nombre', ...]
    DateTimeImmutable $ingreso,
    DateTimeImmutable $hoy
): array  // ['semanas' => [[dia|null x7], ...], 'resumen' => ['dias_asistidos'=>int,'horas'=>float,'sin_registro'=>int]]
```

Cada día lleva `fecha`, `numero` y `estado`, más los datos del registro si lo hay. El orden de prioridad del estado es este:

1. `antes_ingreso`: la fecha es anterior al ingreso.
2. `futuro`: la fecha es posterior a hoy.
3. `asistio`: tiene entrada y salida.
4. `sin_salida`: tiene entrada sin salida. Si es hoy, se muestra como "en curso".
5. `feriado`: la fecha está en feriados y no tiene registro.
6. `libre`: es domingo y no tiene registro.
7. `hoy`: es hoy, día hábil, y todavía no tiene registro.
8. `sin_registro`: día hábil pasado sin registro.

El resumen cuenta así:
- `dias_asistidos` y `horas`: solo los días en estado `asistio`.
- `sin_registro`: los días en estado `sin_registro`.

La cuadrícula va de lunes a domingo, con `null` en las casillas que quedan fuera del mes.

### `App\Services\MarcaAsistenciaService`

Dependencias: `AsistenciaRepository`, `PeriodosRepository`, `PortalRepository` (empleado del usuario), `AuditoriaRepository`. La hora actual (`DateTimeImmutable $ahora`) llega como parámetro y nunca se lee del reloj adentro.

| Método | Qué hace |
|---|---|
| `estadoDeHoy(int $idEmpleado, DateTimeImmutable $ahora): array` | `['estado' => 'sin_entrada'\|'con_entrada'\|'completo', 'registro' => ?array, 'pendientes' => array]`. `pendientes` son los días anteriores a hoy con entrada y sin salida. |
| `marcarEntrada(int $idEmpleado, DateTimeImmutable $ahora, int $idUsuario, string $ip): void` | Crea la fila de hoy. |
| `marcarSalida(int $idEmpleado, DateTimeImmutable $ahora, int $idUsuario, string $ip): void` | Completa la fila de hoy. |
| `corregirSalida(int $idEmpleado, int $idAsistencia, string $horaSalida, DateTimeImmutable $ahora, int $idUsuario, string $ip): void` | Escribe la salida de un día pasado, con `salida_manual = 1`. |

Todos los errores se lanzan como `InvalidArgumentException` con un mensaje para el usuario. Cada escritura deja un registro en `auditoria` (INSERT o UPDATE sobre `asistencia`, y sobre `periodos_pago` cuando se crea un período).

### Reglas

**Comunes**
- El empleado tiene que estar activo. Si no: *"Su empleado está inactivo; no puede marcar asistencia."*

**Período de pago** (al marcar entrada)
1. Si existe un período **abierto** que contiene la fecha, se usa ese.
2. Si existe uno **cerrado** que contiene la fecha, se lanza *"El período de pago de hoy ya está cerrado; avise a RRHH."*
3. Si no existe ninguno, se crea la quincena: del 1 al 15, o del 16 al último día del mes. Se usa `PeriodosRepository::insert` y se registra en auditoría.

**Marcar entrada**
- Si ya hay registro hoy, se lanza *"Ya marcó su entrada hoy a las HH:MM."*
- Se inserta: `fecha` = hoy, `hora_entrada` = ahora (HH:MM:00), `hora_salida = '00:00:00'`, `horas_trabajadas = 0`, `salida_manual = 0`, el período del paso anterior y `id_feriado` según `findFeriadoIdPorFecha`.

**Marcar salida**
- Si no hay registro hoy: *"Primero marque su entrada."*
- Si ya tiene salida: *"Ya marcó su salida hoy a las HH:MM."*
- Si la salida no es posterior a la entrada: *"Espere al menos un minuto para marcar la salida."*
- Se actualiza `hora_salida` = ahora y `horas_trabajadas` = (salida − entrada) en horas, redondeado a 2 decimales.

**Corregir salida**
- El registro tiene que existir y ser del mismo empleado. Si no: *"Registro no encontrado."*
- La fecha tiene que ser anterior a hoy. Si no: *"La salida de hoy se marca con el botón."*
- El registro tiene que seguir sin salida. Si no: *"Ese día ya tiene salida."*
- `horaSalida` tiene que ser HH:MM válido y posterior a la entrada. Si no: *"La hora de salida debe ser posterior a la entrada (HH:MM)."*
- El período del registro tiene que estar abierto. Si no: *"Ese período ya se cerró; pida a RRHH que lo corrija."*
- Se actualiza `hora_salida`, `horas_trabajadas` y `salida_manual = 1`.

### Repositorios

- **`AsistenciaRepository`:**
  - `findByEmpleadoFecha(int $idEmpleado, string $fecha): ?array`
  - `findSinSalidaAntesDe(int $idEmpleado, string $fecha): array`
  - `findByEmpleadoMes(int $idEmpleado, string $desde, string $hasta): array`
  - `registrarSalida(int $id, string $horaSalida, float $horas, bool $manual): void`
  - `insert()` y `update()` incluyen `salida_manual`.
  - `findById` y `findAll` devuelven `salida_manual`.
- **`PeriodosRepository`:** `findPorFecha(string $fecha): ?array`, que devuelve el período abierto o cerrado que contiene la fecha.
- **`FeriadosRepository`:** `findEntre(string $desde, string $hasta): array`.
- **`PortalRepository`:** `empleadoPorUsuario(int $idUsuario): ?array`, que devuelve `id_empleado`, `estado` y `fecha_ingreso`. El controller lo usa para obtener el empleado, saber si está activo y armar el calendario desde el ingreso.

### Controller y rutas

En `PortalController` (todas con `AuthMiddleware`, igual que las demás rutas del portal):

| Método | Ruta | Nombre |
|---|---|---|
| GET | `/mi-asistencia?mes=YYYY-MM` | `portal.asistencia` (ya existe; ahora filtra por mes) |
| POST | `/mi-asistencia/entrada` | `portal.asistencia.entrada` |
| POST | `/mi-asistencia/salida` | `portal.asistencia.salida` |
| POST | `/mi-asistencia/{id}/salida` | `portal.asistencia.corregir` |

- Los POST siguen el patrón POST → redirección a `portal.asistencia` → mensaje flash.
- Un usuario sin empleado vinculado recibe un flash de error y no se guarda nada.
- El empleado se obtiene de `$request->getAttribute('usuario')`, nunca del formulario.
- El mes se valida con el formato `YYYY-MM`. Si es inválido, futuro o anterior al mes de ingreso, se usa el mes actual.

### Pantalla `portal/mi_asistencia.html.twig`

- **Encabezado:** `ui.encabezado_pagina('Mi asistencia', {ruta: Mi cuenta › Mi asistencia})`.
- **Avisos de días pendientes:** uno por día, con un pequeño formulario que lleva un campo `time` y el botón "Guardar salida".
- **Tarjeta de hoy:**
  - Fecha y estado.
  - El botón que corresponde: "Marcar entrada" o "Marcar salida", o el texto "Jornada completa: X h".
  - El botón se desactiva con `onsubmit` al primer envío.
- **Tres números:** días asistidos, horas del mes y días sin registro (en rojo si es mayor que 0).
- **Calendario:**
  - Navegación ‹ mes ›, sin flecha hacia meses futuros ni hacia antes del ingreso.
  - Una cuadrícula de 7 columnas con clases CSS por estado (`lb-dia--asistio`, etc.) y una leyenda debajo.
- **Detalle del día:**
  - Cada día con registro lleva `data-*` (entrada, salida, horas, manual).
  - `app.js` muestra el detalle en un panel debajo del calendario al tocar el día, sin librerías.
  - En celular (< 576 px) cada casilla muestra solo el número y un punto de color.
- Se quitan el filtro por período y la tabla del detalle.

### Módulo de Asistencia del admin

- En `asistencia/index`, la columna Salida muestra `ui.etiqueta('Sin salida', 'ambar')` cuando `hora_salida = '00:00:00'`, y agrega `ui.etiqueta('Escrita por el empleado', 'gris')` cuando `salida_manual = 1`.
- `AsistenciaService::actualizar` guarda `salida_manual = 0`, porque RRHH ya revisó la salida.
- Lo demás no cambia.

## Pruebas (TDD)

1. **`tests/Helpers/CalendarioAsistenciaTest.php`:**
   - cada estado y el orden de prioridad;
   - resumen;
   - mes que empieza en domingo;
   - febrero no bisiesto;
   - ingreso a mitad de mes;
   - hoy con entrada sin salida.
2. **`tests/Services/MarcaAsistenciaServiceTest.php`** (repositorios falsos o mocks, como en las pruebas actuales):
   - entrada normal y entrada duplicada;
   - creación de la quincena 1–15 y 16–fin;
   - período cerrado;
   - empleado inactivo;
   - salida normal, sin entrada, ya marcada y en el mismo minuto;
   - corrección normal (`salida_manual = 1`), de otro empleado, del día de hoy, de un día ya con salida, con hora anterior a la entrada y con período cerrado;
   - auditoría en cada escritura.
3. **`tests/Controllers/PortalControllerAsistenciaTest.php`:**
   - POST sin vínculo → flash de error y redirección;
   - POST correcto → flash de éxito;
   - el empleado sale del atributo `usuario`.
4. **`tests/Templates/PantallasTest.php`** (ampliar):
   - el calendario se ve con datos de ejemplo;
   - el admin muestra "Sin salida" y "Escrita por el empleado".

## Riesgos

- **Hora del servidor:** si la zona horaria cambia, las marcas quedan corridas. `public/index.php` ya fija `America/Costa_Rica` y en producción se verifica igual.
- **Períodos creados automáticamente:** pueden aparecer quincenas que RRHH no creó. Quedan en auditoría y se ven en Mantenimientos › Períodos.
- **Salida escrita por el empleado:** depende de su honestidad. Por eso queda marcada y visible para RRHH.

## Documento del TFG

Se agrega el caso de uso "Registrar asistencia (empleado)". El Cap. V se actualiza después de implementar, en un paso aparte.
