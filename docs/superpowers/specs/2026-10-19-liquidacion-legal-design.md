# Liquidación según el Código de Trabajo — diseño

**Fecha:** 19/10/2026
**Estado:** propuesto
**Rama:** `feature/liquidacion-legal` (desde `develop`, después de v1.4.0)

## 1. Objetivo

La auditoría legal del 16/10/2026 encontró que la liquidación no calcula lo que exige el Código de Trabajo:

- La cesantía suma una tasa distinta por cada año (19,5 + 20 + 20,5…), prorratea fracciones menores de seis
  meses y no tiene los años 9 a 13 y siguientes de la tabla del Art. 29.
- El preaviso da 15 días con exactamente seis meses de antigüedad; la ley da 7.
- La base es el salario del puesto, no el promedio de los últimos seis meses (Art. 30).
- Faltan dos motivos de salida: renuncia justificada (despido indirecto) y muerte del trabajador.
- Las vacaciones pendientes se digitan a mano y se pagan al salario ÷ 30, sin el promedio del Art. 157.
- El aguinaldo proporcional se estima como salario × meses ÷ 12, en vez de sumar lo pagado (Ley 2412).

Después de este cambio la liquidación calcula cada rubro con su regla legal, guarda la base y los días que
usó, y el detalle muestra de dónde sale cada monto. El documento del TFG (CU-16, Figuras 46 y 58,
Prueba 2) se actualiza después con los montos nuevos.

**Criterios de éxito**

1. Antigüedad 1 año y 8 meses, despido sin causa → cesantía de 39 días (2 × 19,5) (DAJ-AE-083-09).
2. Antigüedad 6 años y 6 meses → cesantía de 129 días (6 × 21,5); con 6 años y 7 meses → 150,5 días (7 × 21,5).
3. Antigüedad 12 años → cesantía de 164 días (8 × 20,5, tope de 8 años).
4. Antigüedad exacta de 6 meses → preaviso de 7 días y cesantía de 7 días.
5. Con nóminas de los últimos seis meses, la base de preaviso y cesantía es su promedio mensual sin
   subsidio de incapacidad; sin nóminas, es el salario del puesto y el detalle lo indica.
6. Motivo `muerte` → cesantía sí, preaviso no; motivo `renuncia_justificada` → preaviso y cesantía.
7. Las vacaciones pendientes salen del saldo del sistema a la fecha de salida; el aguinaldo suma los brutos
   pagados desde el 1 de diciembre más los días todavía sin nómina.

## 2. Base legal

| Regla | Fuente |
|---|---|
| Preaviso: 3 a 6 meses → 1 semana; más de 6 meses hasta 1 año → 15 días; después de 1 año → 1 mes | Código de Trabajo, Art. 28 |
| Cesantía: 3 a 6 meses → 7 días; más de 6 meses y menos de 1 año → 14 días; desde 1 año, tabla de días por año (19,5 … 22 … 20) "por año laborado o fracción superior a seis meses"; máximo 8 años | Código de Trabajo, Art. 29 (reformado por Ley 7983) |
| La tasa es la del inciso que corresponde a la antigüedad total y se multiplica por los años; no se suman tasas distintas | MTSS DAJ-AE-083-09; Directrices MTSS 006-2001 y 1-2003 |
| El tope de 8 años se calcula "conforme la antigüedad del trabajador" | MTSS DAJ-AE-765-06 |
| Base de preaviso y cesantía: promedio de salarios de los últimos seis meses, o fracción menor | Código de Trabajo, Art. 30 |
| Cesantía también "por alguna de las causas previstas en el artículo 83 u otra ajena a la voluntad del trabajador" | Código de Trabajo, Art. 29 |
| Renuncia con justa causa: el trabajador conserva "su derecho a las indemnizaciones y prestaciones legales" | Código de Trabajo, Arts. 83 y 84 |
| Muerte del trabajador y jubilación terminan el contrato sin extinguir los derechos; los parientes reclaman las prestaciones | Código de Trabajo, Art. 85 a) y e) |
| Vacaciones: promedio de las remuneraciones ordinarias y extraordinarias de las últimas 50 semanas | Código de Trabajo, Art. 157 |
| Aguinaldo: doceava parte de los salarios del 1 de diciembre al 30 de noviembre, o proporcional | Ley 2412, Art. 1 |
| El subsidio de incapacidad no es salario | Sala Segunda, Voto 477-2012; MTSS DAJ-AE-201-12 |

**Interpretación propia, sin fuente directa:** en la renuncia justificada se paga también el preaviso, porque
el Art. 84 conserva "las indemnizaciones" y el despido indirecto se equipara a un despido sin causa. En los
límites que la ley deja ambiguos se usa: exactamente 6 meses → tramo de 3 a 6 meses; exactamente 1 año → tramo
de "1 año o más". `fecha_salida` es el último día trabajado (como `empleados.fecha_salida`) y la antigüedad lo
cuenta: se mide contra el día siguiente, así 01/01/2025 → 31/12/2025 es un año exacto.

## 3. Decisiones tomadas

| Pregunta | Decisión |
|---|---|
| Alcance | Preaviso, cesantía, base del Art. 30, motivos nuevos, vacaciones (saldo + Art. 157) y aguinaldo real (Gabriel, 19/10/2026). |
| Contratos a plazo fijo u obra determinada (Art. 31) | Fuera de alcance; la liquidación supone contrato por tiempo indefinido. |
| Fuente del salario cuando no hay nóminas | Salario del puesto, igual que la nómina. Pasar al salario del contrato queda fuera. |
| Días de vacaciones | Los calcula el sistema; el formulario ya no los pide. |

## 4. Diseño

### 4.1 `PromedioSalarial` (helper puro, compartido)

`src/Helpers/PromedioSalarial.php`, sin BD ni reloj. Saca de `NominasService::datosCalculo` la regla
"bruto sin subsidio ÷ días realmente cubiertos" para usarla en la nómina (Art. 157) y en la liquidación (Art. 30).

```php
/**
 * Promedio diario: $bruto ÷ días entre el mayor de ($desde, $ingreso, $primerInicioNomina)
 * y el menor de ($hasta, $ultimoFinNomina), con tope $maxDias. Null si no hay días o el bruto es 0.
 */
public static function diario(
    float $bruto, string $desde, string $hasta,
    ?string $ingreso, ?string $primerInicioNomina, ?string $ultimoFinNomina = null, int $maxDias = 350
): ?float
```

`NominasService` pasa a usarlo sin cambiar el resultado: las pruebas de nómina existentes deben seguir verdes.

### 4.2 `LiquidacionCalculadora` (reescrita, pura)

**Entrada** (`calcular(array $d)`):

| Clave | Significado |
|---|---|
| `fechaIngreso`, `fechaSalida` | YYYY-MM-DD |
| `motivo` | uno de los 7 motivos |
| `salarioPromedio` | base del Art. 30 (mensual) |
| `salarioDiarioVacaciones` | mayor entre salario ÷ 30 y el promedio diario del Art. 157 |
| `diasVacaciones` | saldo disponible a la fecha de salida |
| `brutosAguinaldo` | suma de brutos sin subsidio desde el 1 de diciembre, más los días sin nómina × salario ÷ 30 |

**Antigüedad:** años, meses y días completos entre ingreso y salida (`DateTimeImmutable::diff`).
`masDeSeisMeses` = la fracción del último año supera seis meses (6 meses y al menos 1 día).

**Preaviso (días):**

| Antigüedad | Días |
|---|---|
| menos de 3 meses | 0 |
| de 3 meses hasta 6 meses exactos | 7 |
| más de 6 meses y menos de 1 año | 15 |
| 1 año o más | 30 |

**Cesantía (días):**

| Antigüedad | Días |
|---|---|
| menos de 3 meses | 0 |
| de 3 meses hasta 6 meses exactos | 7 |
| más de 6 meses y menos de 1 año | 14 |
| 1 año o más | `tasa(años completos) × min(años completos + (masDeSeisMeses ? 1 : 0), 8)` |

Tabla `tasa`: 1 → 19,5; 2 → 20; 3 → 20,5; 4 → 21; 5 → 21,24; 6 → 21,5; 7 → 22; 8 → 22; 9 → 22; 10 → 21,5;
11 → 21; 12 → 20,5; 13 o más → 20.

**Rubros por motivo:**

| Motivo | Preaviso | Cesantía |
|---|---|---|
| `despido_sin_causa` | sí | sí |
| `renuncia_justificada` | sí | sí |
| `muerte` | no | sí |
| `jubilacion` | no | sí |
| `renuncia`, `despido_con_causa`, `mutuo_acuerdo` | no | no |

Vacaciones y aguinaldo se pagan siempre.

**Montos** (redondeo a 2 decimales en cada rubro):

- valor día Art. 30 = `salarioPromedio ÷ 30`
- preaviso = días × valor día; cesantía = días × valor día
- vacaciones = `diasVacaciones × salarioDiarioVacaciones`
- aguinaldo = `brutosAguinaldo ÷ 12`

**Salida:** montos (`preaviso`, `cesantia`, `vacaciones_pendientes`, `aguinaldo_proporcional`,
`total_liquidacion`) y trazabilidad (`dias_preaviso`, `dias_cesantia`, `dias_vacaciones`, `salario_promedio`,
`anios_contados`, `tasa_cesantia`).

### 4.3 `LiquidacionService` (reúne los datos)

1. Valida empleado, motivo y fecha de salida (como hoy). Ya no lee `dias_vacaciones` del formulario.
2. **Base Art. 30:** ventana = 6 meses antes de la salida (+1 día) hasta la salida. Se toman las nóminas cuyo
   `fecha_fin` cae en la ventana (`brutoEntre`, `primerInicioNominaEntre`, `ultimoFinNominaEntre`) y
   `PromedioSalarial::mensualPorPeriodos` = bruto ÷ días comerciales entre el primer inicio y el último fin de
   esas nóminas × 30. La nómina paga cada quincena como 15 días comerciales, así que un salario constante da
   exactamente ese salario; no hay tope de 183 días ni se recorta por el ingreso (el bruto incluye quincenas
   completas). Sin bruto o sin días → salario del puesto y `base_estimada = true`.
3. **Vacaciones:** `SaldoVacacionesService::saldoAlSalir(id, ingreso, salida)`: días ganados a la salida menos
   las solicitudes pendientes o aprobadas con `fecha_inicio` ≤ salida, contando sus días solo hasta la salida
   (`fecha_fin` recortada); mínimo 0. Valor diario = máx(salario ÷ 30, promedio diario de los 350 días
   anteriores a la salida, `PromedioSalarial::diario`, regla del Art. 157 sin cambios).
4. **Aguinaldo:** inicio = siempre el 1 de diciembre anterior a la salida (o el mismo año si la salida es en
   diciembre), aunque el ingreso sea posterior, porque una quincena puede empezar antes del ingreso.
   `AguinaldoRepository::sumaBrutos(id, inicio, salida)` (nóminas aprobadas o pagadas) + días comerciales entre
   los días sin nómina y la salida × salario ÷ 30; los días sin nómina empiezan el día siguiente al último fin
   de esas nóminas o, si no hay ninguna, en el mayor entre el 1 de diciembre y el ingreso.
5. Llama a la calculadora, guarda con `upsert` y audita.

Lecturas nuevas solo-SQL: `NominasRepository::ultimoFinNominaEntre(id, desde, hasta)` y
`AguinaldoRepository::ultimoFinNominaPagadaEntre(id, desde, hasta)`.

### 4.4 Datos y esquema

- `liquidacion.motivo` ENUM agrega `renuncia_justificada` y `muerte`.
- Columnas nuevas en `liquidacion` (todas `NOT NULL DEFAULT 0`):
  `salario_promedio DECIMAL(10,2)`, `dias_preaviso DECIMAL(5,2)`, `dias_cesantia DECIMAL(6,2)`,
  `dias_vacaciones DECIMAL(5,2)`, `base_estimada TINYINT(1)` (1 = sin nóminas en la ventana del Art. 30; se usó el
  salario del puesto).
- Migración `database/migraciones/2026-10-19-liquidacion-legal.sql` + `schema.sql` + `demo.php` (la
  liquidación de demostración se recalcula con las reglas nuevas).

### 4.5 Pantallas

- Formulario: quita el campo de días de vacaciones; muestra los 7 motivos con su etiqueta legible
  ("Renuncia justificada (Art. 83)", "Muerte del trabajador").
- Detalle: tabla con rubro, base, días o regla y monto. Si la base fue estimada, aviso
  "Sin nóminas en los últimos seis meses: se usó el salario del puesto".

## 5. Pruebas

`tests/Helpers/LiquidacionCalculadoraTest.php` (se reescribe) y `tests/Helpers/PromedioSalarialTest.php`:

`fecha_salida` es el último día trabajado y la antigüedad lo cuenta (se mide contra el día siguiente):

| Caso (ingreso → salida) | Esperado |
|---|---|
| 2 meses (01/01 → 28/02) | preaviso 0, cesantía 0 |
| 3 meses exactos (01/01 → 31/03); 6 meses exactos (01/01 → 30/06) | preaviso 7, cesantía 7 |
| 6 meses y 1 día (01/01 → 01/07); 11 meses (01/01 → 30/11) | preaviso 15, cesantía 14 |
| 1 año exacto (01/01/2025 → 31/12/2025) | preaviso 30, cesantía 19,5 |
| 1 año y 8 meses (01/01/2024 → 31/08/2025) | cesantía 39 |
| 6 años y 6 meses (→ 30/06/2026) / 6 años y 7 meses (→ 31/07/2026) | 129 / 150,5 |
| 8 años y 7 meses (01/01/2017 → 31/07/2025) | tasa 22 × tope 8 = 176 |
| 9 años (→ 31/12/2025) | 8 × 22 = 176 |
| 12 años (01/01/2014 → 31/12/2025) | 8 × 20,5 = 164 |
| 15 años (01/01/2011 → 31/12/2025) | 8 × 20 = 160 |
| cada motivo | preaviso/cesantía según la tabla de 4.2 |
| `PromedioSalarial::diario` con ingreso dentro de la ventana, sin nóminas y con tope | días cubiertos correctos; null sin datos |
| `PromedioSalarial::mensualPorPeriodos`: 12 quincenas (01/01 → 30/06) y ventana 01/04 → 30/09 (183 días calendario), bruto 1 800 000 | 300 000 en ambos; null sin bruto, sin fechas o sin días |
| `SaldoVacacionesService::saldoAlSalir` | ignora solicitudes posteriores a la salida; recorta la que cruza la salida |

`NominasService` y sus pruebas actuales siguen verdes. Prueba de servicio (stub de repositorios) para la base
estimada, la base igual al salario con un historial constante, y el cálculo de días sin nómina del aguinaldo
(ingreso a mitad de quincena: se consulta desde el 1 de diciembre; sin nóminas, los días empiezan en el ingreso).

## 6. Orden de implementación

1. `PromedioSalarial` + pruebas; `NominasService` lo usa (pruebas de nómina verdes).
2. `LiquidacionCalculadora` reescrita + pruebas.
3. Esquema, migración y `LiquidacionRepository` (columnas y motivos nuevos).
4. `LiquidacionService` reúne los datos; lecturas nuevas en los repositorios; prueba de servicio.
5. Formulario y detalle.
6. `demo.php` y `CLAUDE.md` (motivos y regla de cesantía en la sección legal).

## 7. Fuera de alcance

- Contratos a plazo fijo u obra determinada (Art. 31).
- El preaviso que debe el trabajador cuando renuncia sin avisar.
- El salario pendiente de los días trabajados que todavía no tienen nómina (lo paga la nómina).
- Usar el salario del contrato en vez del salario del puesto.
- Marcar al empleado como inactivo al registrar la liquidación.
