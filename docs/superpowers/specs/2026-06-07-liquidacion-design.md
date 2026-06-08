# Diseño — Módulo Liquidación

- **Fecha:** 2026-06-07
- **Autor:** Gabriel Iván Madrigal Flores (con asistencia de IA)
- **Estado:** Aprobado (pendiente de revisión final del spec)
- **Objetivo:** Implementar el módulo 11 (Liquidación laboral) siguiendo la arquitectura Controller → Service → Repository, con el cálculo legal aislado en una clase pura testeable.

---

## 1. Contexto

Los módulos 1–10 están completos (hasta Aguinaldo). Sigue **Liquidación**. El modelo de datos ya existe en `database/schema.sql` (tabla `liquidacion`):

- `id_empleado` con `UNIQUE` (una liquidación por empleado).
- `fecha_salida`, `motivo` ENUM('renuncia','despido_con_causa','despido_sin_causa','mutuo_acuerdo','jubilacion').
- Montos: `preaviso`, `cesantia`, `vacaciones_pendientes`, `aguinaldo_proporcional`, `total_liquidacion`, `fecha_calculo`.

El salario vive en `puestos.salario_base` (mensual); `empleados.fecha_ingreso` da la antigüedad.

---

## 2. Alcance

**Incluido:**
- Cálculo legal completo (Código de Trabajo CR, Arts. 28–29): preaviso, cesantía (tabla Art. 29 con tope 8 años), vacaciones pendientes y aguinaldo proporcional.
- Flujo: crear liquidación por empleado, ver desglose, eliminar.
- Pruebas PHPUnit del cálculo.

**Fuera de alcance:**
- Marcar automáticamente al empleado como inactivo / fijar su `fecha_salida` (mejora futura).
- Salario promedio de últimos 6 meses (se usa el salario del puesto).
- Generación de PDF (solo vista HTML).

---

## 3. Decisiones (confirmadas)

1. **Cálculo en clase pura** `App\Helpers\LiquidacionCalculadora` (testeable). El Service orquesta BD/auditoría.
2. **Precisión legal:** tabla completa Art. 29.
3. **Base salarial:** `puestos.salario_base` (mensual). **Valor día** = mensual ÷ 30.
4. **Rubros por motivo** (vacaciones y aguinaldo proporcional siempre aplican):
   - `renuncia`: vacaciones + aguinaldo.
   - `despido_con_causa`: vacaciones + aguinaldo.
   - `despido_sin_causa`: preaviso + cesantía + vacaciones + aguinaldo.
   - `mutuo_acuerdo`: vacaciones + aguinaldo.
   - `jubilacion`: cesantía + vacaciones + aguinaldo.
5. **Días de vacaciones pendientes:** los ingresa el admin en el formulario (entero/decimal ≥ 0).

---

## 4. Fórmulas

Sea `salarioMensual` = salario del puesto; `valorDia = salarioMensual / 30`.
Antigüedad: diferencia entre `fecha_ingreso` y `fecha_salida` → `aniosCompletos` (entero) y `fraccionAnio` (0..1, en meses/12).

### 4.1 Preaviso (Art. 28) — solo `despido_sin_causa`
| Antigüedad | Días de preaviso |
|---|---|
| < 3 meses | 0 |
| ≥ 3 y < 6 meses | 7 |
| ≥ 6 y < 12 meses | 15 |
| ≥ 1 año | 30 |

`preaviso = díasPreaviso × valorDia`.

### 4.2 Cesantía (Art. 29) — `despido_sin_causa` y `jubilacion`
Tabla de días por año (tope: máximo 8 años contados). Aplica solo si antigüedad ≥ 3 meses.

| Año | Días | Año | Días |
|---|---|---|---|
| 1 | 19.5 | 5 | 21.24 |
| 2 | 20 | 6 | 21.5 |
| 3 | 20.5 | 7 | 22 |
| 4 | 21 | 8 | 22 |

`n = min(aniosCompletos, 8)`.
`diasCesantia = (Σ tabla[1..n]) + (aniosCompletos < 8 ? tabla[n+1] × fraccionAnio : 0)`.
`cesantia = diasCesantia × valorDia`.

### 4.3 Vacaciones pendientes
`vacaciones = diasPendientes × valorDia` (diasPendientes ingresado por el admin).

### 4.4 Aguinaldo proporcional
`inicioPeriodo = max(fecha_ingreso, 1-dic del periodo de aguinaldo que contiene fecha_salida)`.
`mesesAguinaldo` = meses (con fracción) entre `inicioPeriodo` y `fecha_salida`.
`aguinaldoProporcional = salarioMensual × mesesAguinaldo / 12`.

### 4.5 Total
`total = (preaviso si aplica) + (cesantía si aplica) + vacaciones + aguinaldoProporcional`, según el motivo (sección 3.4).
Todos los montos se redondean a 2 decimales solo al final; precisión completa en intermedios.

---

## 5. Componentes (patrón de 7 pasos)

1. `src/Helpers/LiquidacionCalculadora.php` — puro:
   - `antiguedad(string $fechaIngreso, string $fechaSalida): array{anios:int, meses:int, fraccion:float}`
   - `diasPreaviso(int $anios, int $meses): int`
   - `montoPreaviso(float $salarioMensual, int $anios, int $meses): float`
   - `montoCesantia(float $salarioMensual, int $anios, float $fraccion, int $mesesTotales): float`
   - `montoVacaciones(float $salarioMensual, float $diasPendientes): float`
   - `aguinaldoProporcional(float $salarioMensual, string $fechaIngreso, string $fechaSalida): float`
   - `calcular(array $datos): array{preaviso, cesantia, vacaciones_pendientes, aguinaldo_proporcional, total_liquidacion}` — aplica el mapeo por motivo.
2. `src/Repositories/LiquidacionRepository.php` — SQL:
   - `findAll(): array` (con nombre del empleado)
   - `findByEmpleado(int $idEmpleado): ?array`
   - `findById(int $id): ?array`
   - `empleadosActivos(): array`
   - `datosEmpleado(int $idEmpleado): ?array` (salario del puesto + fecha_ingreso)
   - `upsert(array $datos): int` (UNIQUE id_empleado → INSERT … ON DUPLICATE KEY UPDATE)
   - `delete(int $id): void`
3. `src/Services/LiquidacionService.php`:
   - `listar()`, `obtener(int $id)`, `datosFormulario()` (empleados activos)
   - `calcular(array $datos, int $loggedInId, string $ip): int` — valida, calcula con la calculadora, persiste, auditoría
   - `eliminar(int $id, int $loggedInId, string $ip)`
   - Validaciones: empleado activo válido, motivo válido, `fecha_salida` ≥ `fecha_ingreso`, días pendientes ≥ 0.
4. `src/Controllers/LiquidacionController.php` — `index`, `create`, `store`, `show`, `destroy` (patrón idéntico a `NominasController`/`HorasExtraController`).
5. Plantillas:
   - `templates/liquidacion/index.html.twig` — lista + botón "Nueva liquidación".
   - `templates/liquidacion/form.html.twig` — empleado, motivo, fecha_salida, días vacaciones.
   - `templates/liquidacion/detalle.html.twig` — desglose de rubros + total.
6. `config/dependencies.php` — registrar Calculadora, Repository, Service, Controller.
7. `config/routes.php` — grupo `/liquidacion` con `RoleMiddleware('admin')` + `AuthMiddleware`:
   - `GET /liquidacion` → index
   - `GET /liquidacion/crear` → create
   - `POST /liquidacion/crear` → store
   - `GET /liquidacion/{id}` → show
   - `POST /liquidacion/{id}/eliminar` → destroy

---

## 6. Pruebas (PHPUnit)

`tests/Helpers/LiquidacionCalculadoraTest.php`:
- `diasPreaviso`: <3m → 0; 4m → 7; 8m → 15; 2 años → 30.
- `montoCesantia`: 1 año exacto (19.5 días); 8 años (Σ tabla 1..8 = 167.74 días); 10 años (tope = 167.74 días); fracción (5 años 6 meses = Σ1..5 + tabla[6]×0.5).
- `calcular` por motivo: `despido_con_causa` no incluye preaviso ni cesantía; `despido_sin_causa` los incluye; total = suma de rubros aplicables.
- Redondeo final a 2 decimales.

---

## 7. Riesgos / notas

- **Precisión monetaria:** redondeo solo al final.
- **Tope de cesantía:** máximo 8 años contados (no 8 meses de pago); para 8 años Σ tabla = 167.74 días.
- **UNIQUE(id_empleado):** recalcular una liquidación existente la sobrescribe (upsert).
- **Antigüedad mínima:** cesantía solo aplica con ≥ 3 meses; preaviso según tabla (0 si < 3 meses).
- **UI:** vistas con el tema visual existente y `extends layouts/base.html.twig`.
- Enlace en el sidebar: el ítem "Liquidaciones" (hoy `href="#"`) se conecta a `liquidacion.index`.
