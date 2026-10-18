# Nómina masiva por período — diseño

**Fecha:** 18/10/2026
**Estado:** aprobado en conversación, pendiente de revisión escrita
**Rama:** `feature/nomina-masiva` (desde `develop`, después de v1.3.0)

## 1. Objetivo

Compromiso con el tutor: *"Implementar la generación masiva de nómina por periodo, que contemple
vacaciones, incapacidades, permisos sin goce salarial y horas extra, y que totalice el monto pagado
en un reporte exportable. Mantener el detalle individual como consulta, incluyendo permisos,
incapacidades y horas extra."*

Hoy la nómina paga el salario completo aunque el empleado tenga incapacidad o permiso sin goce,
calcula la CCSS con tasas de 2023–2025, no retiene renta y obliga a aprobar y pagar una por una.

Después de este cambio:

- Al generar las nóminas de un período, cada una refleja **incapacidades** (con el pago patronal de
  los días 1–3, que no lleva cargas sociales ni renta), **permisos sin goce** (rebajo),
  **vacaciones** (Art. 157), **horas extra** y **días especiales**, la **CCSS con la tasa vigente** y
  la **renta** en la 2.ª quincena.
- El admin aprueba o paga **todo el período** con un botón y confirmación.
- Se exporta un **reporte del período** (Excel y PDF) con el total pagado, las cargas sociales y la
  renta retenida.
- El detalle individual muestra las ausencias usadas en el cálculo.

**Criterios de éxito**

1. Salario ₡480 000 e incapacidad CCSS de 5 días dentro de la quincena: 10 días laborados
   (₡160 000) + subsidio patronal 3 × ₡8 000 = ₡24 000; la CCSS se calcula solo sobre ₡160 000.
2. 2.ª quincena con ₡1 000 000 gravables en el mes, sin dependientes: renta ₡8 200.
3. Una nómina de un período de 2026 usa CCSS obrera 10,83 % y patronal 26,83 %; una de 2025 usa
   10,67 % y 26,67 %.
4. "Aprobar todas" cambia todas las nóminas en borrador del período en una sola acción, o ninguna si
   algo falla.
5. El reporte del período totaliza neto, CCSS (obrera + patronal) y renta, y coincide con la suma de
   las nóminas.

## 2. Base legal

| Regla | Fuente |
|---|---|
| CCSS paga subsidio desde el 4.º día de incapacidad; si se reincapacita dentro de 30 días, desde el 1.º | Reglamento del Seguro de Salud (CCSS), Art. 35 |
| El patrono paga al menos el 50 % del salario en los días 1–3 de una incapacidad CCSS | Código de Trabajo, Art. 79, según jurisprudencia recogida en MTSS DAJ-AE-221-04 y DAJ-AE-201-12 |
| Incapacidad INS: el INS paga desde el día 1; el patrono no paga subsidio. Médico particular: solo licencia | MTSS DAJ-AE-221-04 |
| El subsidio por incapacidad no es salario: no lleva cargas sociales ni renta | Sala Segunda, Voto 477-2012; MTSS DAJ-AE-201-12; Ley 7092, Art. 35 c) |
| Vacaciones se pagan con el promedio de lo devengado (ordinario y extraordinario) en las últimas 50 semanas | Código de Trabajo, Art. 157 |
| Las cuotas CCSS se calculan sobre el total de las remuneraciones | Ley Constitutiva de la CCSS, Art. 3 |
| Tasas 2026: IVM trabajador 4,33 %, patrono 5,58 % (obrera total 10,83 %, patronal 26,83 %) | Nota oficial CCSS, 18/12/2025 (desglose SEM/LPT y patronal: fuentes secundarias consistentes) |
| El patrono retiene el impuesto sobre la renta mensual del trabajador | Ley 7092, Arts. 32–33 |
| Tramos 2026: exento hasta ₡918 000; 10 % hasta ₡1 347 000; 15 % hasta ₡2 364 000; 20 % hasta ₡4 727 000; 25 % sobre el exceso. Créditos: ₡1 710 por hijo, ₡2 590 por cónyuge | Decreto Ejecutivo 45333-H (La Gaceta 229, 05/12/2025); Ley 7092, Art. 34 |

## 3. Decisiones tomadas

| Pregunta | Decisión |
|---|---|
| Botón masivo | Dos botones con confirmación: "Aprobar todas" y "Marcar todas como pagadas". |
| Renta | Completa: tramos con vigencia, hijos y cónyuge en la ficha, cálculo en la 2.ª quincena. |
| Tasas CCSS | Actualizar a 10,83 % / 26,83 % desde 01/01/2026, versionadas en `parametros_legales`; autorizado por Gabriel el 18/10/2026 (reemplaza los valores de `CLAUDE.md`). |
| Vacaciones | Según Art. 157: línea de vacaciones al salario diario y diferencia si el promedio de 50 semanas es mayor. |
| Arquitectura | Calculadora pura `CalculadoraNominaPeriodo`; `NominasService` reúne datos y persiste. |

## 4. Diseño

### 4.1 `CalculadoraNominaPeriodo` (helper puro)

`src/Helpers/CalculadoraNominaPeriodo.php`, sin BD ni reloj.

**Entrada** (un arreglo o DTO simple):

- `salario_mensual` (float), `periodo` (`fecha_inicio`, `fecha_fin`), `segunda_quincena` (bool).
- `incapacidades`: lista de `['tipo' => 'CCSS'|'INS'|'particular', 'fecha_inicio', 'fecha_fin', 'reincapacidad' => bool]`
  (`reincapacidad` = hubo otra incapacidad CCSS que terminó en los 30 días anteriores a esta).
- `dias_permiso_sin_goce` (float): días hábiles sin goce dentro del período (ya contados con el horario del empleado).
- `dias_vacaciones` (float) dentro del período.
- `promedio_diario_50_semanas` (float): suma de `salario_bruto` de las nóminas de las últimas 50 semanas ÷ 350.
- `lineas_adicionales`: líneas ya armadas de horas extra y días especiales (`tipo`, `descripcion`, `monto`).
- `tasas` (`ccss_obrera`, `ccss_patronal`).
- `renta` (solo si `segunda_quincena`): `tramos` (lista de límites y tasas), `credito_hijo`, `credito_conyuge`,
  `hijos`, `conyuge`, `gravable_primera_quincena`.

**Salida:** `['ingresos' => [...], 'deducciones' => [...]]`; cada ingreso con `tipo`, `descripcion`, `monto`;
cada deducción con `tipo`, `descripcion`, `porcentaje`, `monto`, `informativa`.

**Reglas** (salario diario = mensual ÷ 30; la quincena se paga como 15 días):

| Concepto | Línea | Monto |
|---|---|---|
| Días laborados | `salario_base` "Salario quincenal: N de 15 días" | (15 − días de incapacidad − días de permiso sin goce − días de vacaciones) × diario, mínimo 0 |
| Vacaciones | `vacaciones` "Vacaciones: N días" | días de vacaciones × diario |
| Diferencia Art. 157 | `vacaciones` "Diferencia de vacaciones (Art. 157)" | días × (promedio diario − diario), solo si es > 0 |
| Incapacidad CCSS, días 1–3, no reincapacidad | `subsidio_incapacidad` "Subsidio patronal incapacidad dd/mm–dd/mm (50 %)" | días con número de día 1–3 que caen en el período × diario × 0,5 |
| Incapacidad CCSS desde el día 4, reincapacidad, INS, particular | sin línea | 0 (esos días solo restan de "días laborados") |
| Líneas adicionales (horas extra, feriado, descanso trabajado) | tal cual | — |
| CCSS obrera | `CCSS_empleado` | Σ ingresos cotizables × tasa |
| CCSS patronal | `CCSS_patronal`, informativa | Σ ingresos cotizables × tasa |
| Renta (solo 2.ª quincena) | `impuesto_renta` | impuesto progresivo sobre (gravable 1.ª + gravable 2.ª) − créditos, mínimo 0 |

- Días de incapacidad en el período = días naturales de la incapacidad que caen entre `fecha_inicio` y `fecha_fin` del período.
- El "número de día" de una incapacidad se cuenta desde su `fecha_inicio` real: una incapacidad que empieza el 14 tiene
  los días 1–2 en la 1.ª quincena y el día 3 el 16.
- **Cotizable / gravable** por tipo, en un solo lugar: `CalculadoraNominaPeriodo::esCotizable(string $tipo)` y
  `esGravable(string $tipo)`. Todos los tipos de ingreso cotizan y tributan excepto `subsidio_incapacidad`.
- Si los días de ausencia superan 15, "días laborados" es 0 (nunca negativo).
- Montos redondeados a 2 decimales en cada línea.

### 4.2 Datos y esquema

- `ingresos_nomina.tipo` ENUM agrega `vacaciones` y `subsidio_incapacidad`.
- `empleados`: `renta_hijos TINYINT NOT NULL DEFAULT 0`, `renta_conyuge TINYINT(1) NOT NULL DEFAULT 0`.
- `parametros_legales` (ya existe): filas

| clave | valor | fecha_inicio | fecha_fin |
|---|---|---|---|
| ccss_obrera | 0.1067 | 2023-01-01 | 2025-12-31 |
| ccss_obrera | 0.1083 | 2026-01-01 | 9999-12-31 |
| ccss_patronal | 0.2667 | 2023-01-01 | 2025-12-31 |
| ccss_patronal | 0.2683 | 2026-01-01 | 9999-12-31 |
| renta_tramo_1 … renta_tramo_4 | 918000 / 1347000 / 2364000 / 4727000 | 2026-01-01 | 2026-12-31 |
| renta_tasa_1 … renta_tasa_4 | 0.10 / 0.15 / 0.20 / 0.25 | 2026-01-01 | 2026-12-31 |
| renta_credito_hijo | 1710 | 2026-01-01 | 2026-12-31 |
| renta_credito_conyuge | 2590 | 2026-01-01 | 2026-12-31 |

  Interpretación de la tabla de renta: hasta `renta_tramo_1` exento; sobre el exceso de `tramo_k` hasta `tramo_{k+1}`
  se aplica `tasa_k`; sobre el exceso de `tramo_4` se aplica `tasa_4`.
- Código nuevo: `ParametrosLegalesRepository::valorVigente(string $clave, string $fecha): ?float` y
  `ParametrosLegalesService::tasasCcss(string $fecha): array`, `tablaRenta(string $fecha): array`. Si falta un
  parámetro vigente lanzan `RuntimeException("Falta cargar el parámetro legal {clave} vigente al dd/mm/aaaa.")`.
- `NominaCalculadora` deja de recibir tasas fijas de `config/settings.php`; las tasas llegan por fecha. El bloque
  `ccss_*` de `settings.php` se reemplaza por un comentario que apunta a `parametros_legales`. Se actualiza la nota de
  `CLAUDE.md` (reglas legales) con las tasas 2026 y la fuente.
- Lecturas nuevas en `NominasRepository` (solo SQL): incapacidades que se cruzan con el período + la última
  incapacidad CCSS anterior de cada una (para la regla de 30 días); permisos sin goce que se cruzan con el período;
  vacaciones del período; suma de `salario_bruto` de las nóminas con fecha de período en las últimas 50 semanas;
  ingresos gravables de la nómina de la 1.ª quincena del mismo mes.
- Migración `database/migraciones/2026-10-18-nomina-masiva.sql` + `schema.sql` + filas en `seed.sql` y `demo.php`.

### 4.3 Flujo en `NominasService`

- `generarPeriodo`: carga parámetros vigentes a la `fecha_inicio` del período (tasas; tabla de renta si es
  2.ª quincena). Si falta alguno, no genera nada. Por cada empleado activo sin nómina reúne datos, llama a la
  calculadora y guarda en transacción (`insertNominaCompleta`). Horas extra y días especiales siguen saliendo de
  `lineaHorasExtra` y `adicionalesDiaEspecial`.
- 2.ª quincena = período cuyo `fecha_inicio` es el día 16. Si no existe nómina de la 1.ª quincena del mes para ese
  empleado, `gravable_primera_quincena` = 0.
- `recalcular`: CCSS sobre la suma de ingresos cotizables con las tasas vigentes a la fecha del período; si es
  2.ª quincena, recalcula también la renta; conserva embargo/otro.
- `regenerar(int $idNomina, ...)`: solo en borrador; borra y vuelve a generar esa nómina (se pierden líneas manuales;
  el modal lo advierte).
- Detalle (`obtener`): agrega `ausencias` (incapacidades con tipo, fechas, días en el período y quién paga; permisos
  sin goce; vacaciones) para el bloque "Ausencias del período" de la colilla.

### 4.4 Botones masivos

- `NominasService::aprobarPeriodo(int $idPeriodo, int $idUsuario, string $ip): int` — todas las de borrador pasan a
  aprobado, en una transacción (todas o ninguna), con auditoría por nómina.
- `NominasService::pagarPeriodo(...)`: todas las aprobadas pasan a pagado, igual.
- Rutas `POST /nominas/periodo/{id}/aprobar` y `POST /nominas/periodo/{id}/pagar` (grupo admin). Botones visibles
  solo si hay nóminas en el estado de origen; modal con cantidad y total neto.

### 4.5 Reporte exportable

- `GET /nominas/exportar/{formato}?periodo={id}` con `formato` = `xlsx` | `pdf`; reutiliza `ExcelExportHelper` y
  `PdfExportHelper`.
- Columnas: Empleado, Cédula, Salario base, Horas extra, Otros ingresos, Subsidio incap., Bruto, CCSS obrera, Renta,
  Otras deducc., Neto, CCSS patronal, Estado. Fila de totales y resumen: total pagado (neto), cargas a pagar a la
  CCSS (obrera + patronal), renta retenida.
- Encabezado: taller, período, fecha de generación; aviso "Incluye nóminas en borrador" si aplica.

## 5. Pruebas

- `CalculadoraNominaPeriodoTest` (salario ₡480 000, diario ₡16 000): sin ausencias; incapacidad CCSS de 5 días
  (10 días + subsidio ₡24 000, CCSS sobre ₡160 000); incapacidad que cruza períodos (empieza el 14 → la 2.ª quincena
  paga solo el día 3 al 50 %); reincapacidad (sin subsidio); INS y particular (solo rebajo); permiso sin goce de
  1 día (14 días); vacaciones sin diferencia; vacaciones con diferencia Art. 157; ausencias > 15 días (0 días
  laborados); renta ₡1 000 000 → ₡8 200; con 2 hijos y cónyuge → ₡2 190; bajo el exento → 0; 1.ª quincena → sin renta;
  subsidio no tributa.
- `ParametrosLegalesServiceTest`: tasas por fecha (2025 vs 2026), error si falta un parámetro.
- `NominasService`: generación usa la calculadora y los parámetros; `aprobarPeriodo`/`pagarPeriodo` solo cambian el
  estado correcto y son todo-o-nada; `recalcular` incluye renta en 2.ª quincena; `regenerar` solo en borrador.
- Exportación: el reporte tiene una fila por nómina y los totales coinciden.
- Prueba de punta a punta (HTTP) tras regenerar la base local (con permiso de Gabriel): generar → detalle → aprobar
  todas → pagar todas → exportar xlsx y pdf.

## 6. Demostración

`demo.php`: parámetros 2023–2026; un empleado con hijos/cónyuge; un salario que pase el exento (₡1 000 000) para que
haya una retención de renta real; las incapacidades existentes (María CCSS 08–10/09, Luis INS 22–24/06) aparecen como
rebajo y subsidio.

## 7. Orden de implementación

Rama `feature/nomina-masiva`, checkpoint por tarea:

1. Parámetros legales (esquema, repository, service, tasas CCSS 2026, `CLAUDE.md`).
2. `CalculadoraNominaPeriodo` con sus pruebas.
3. Lecturas de ausencias en el repository y nueva generación en `NominasService`.
4. Recálculo con CCSS por cotizables y renta; botón "Regenerar".
5. Hijos y cónyuge en la ficha del empleado.
6. Bloque "Ausencias del período" en el detalle.
7. Botones "Aprobar todas" y "Marcar todas como pagadas".
8. Reporte exportable (Excel y PDF).
9. Demostración y prueba de punta a punta.

## 8. Fuera de alcance

- Cesantía y promedios de la liquidación (otra fase).
- Exención del INA para patronos con menos de 5 trabajadores (no confirmada en fuente primaria).
- Retención de renta en la 1.ª quincena.
- Tramos de renta 2027 (se cargan cuando Hacienda los publique).
