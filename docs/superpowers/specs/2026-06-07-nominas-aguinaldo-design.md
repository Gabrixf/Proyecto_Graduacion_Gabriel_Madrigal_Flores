# Diseño — Módulos Nóminas y Aguinaldo

- **Fecha:** 2026-06-07
- **Autor:** Gabriel Iván Madrigal Flores (con asistencia de IA)
- **Estado:** Aprobado (pendiente de revisión final del spec)
- **Objetivo:** Llevar el sistema web de ~62% a ~77% implementando el módulo 9 (Nóminas) y el módulo 10 (Aguinaldo), siguiendo la arquitectura Controller → Service → Repository ya establecida.

---

## 1. Contexto

Los módulos 1–8 (Auth, Mantenimientos, Empleados, Asistencia, Horas Extra, Vacaciones, Incapacidades, Permisos) están completos. El siguiente en el orden acordado es **Nóminas**, el núcleo del sistema, seguido de **Aguinaldo**. El modelo de datos ya existe en `database/schema.sql` (tablas `nominas`, `ingresos_nomina`, `deducciones_nomina`, `aguinaldo`).

Hechos del modelo relevantes:
- El salario vive en `puestos.salario_base` (mensual). `empleados` **no** tiene salario propio; se obtiene vía su puesto.
- `periodos_pago` es quincenal (`fecha_inicio`, `fecha_fin`, `estado` abierto/cerrado).
- `nominas` tiene `UNIQUE(id_empleado, id_periodo)` y `estado` ENUM('borrador','aprobado','pagado').
- `ingresos_nomina.tipo`: salario_base | horas_extra | bonificacion | comision | feriado | otro.
- `deducciones_nomina.tipo`: CCSS_empleado | CCSS_patronal | impuesto_renta | embargo | otro.

---

## 2. Alcance

**Incluido:**
- Módulo 9 — Nóminas: generación por lote del periodo + edición individual con líneas manuales.
- Módulo 10 — Aguinaldo: cálculo anual (Ley 2412) a partir del historial de nóminas.
- Pruebas unitarias PHPUnit del cálculo legal.

**Fuera de alcance (avances posteriores):**
- Impuesto sobre la renta (tramos).
- Efecto de incapacidades/vacaciones sobre el salario del periodo.
- Liquidación (módulo 11), Evaluaciones (12), Reportes (13).
- Generación de PDF de la colilla (solo vista HTML por ahora).

---

## 3. Decisiones de diseño (confirmadas)

1. **Cálculo en clase pura.** `App\Helpers\NominaCalculadora` (sin BD ni Slim) recibe entradas y devuelve totales. `NominaService` la usa y orquesta la persistencia. Permite pruebas unitarias del cálculo legal.
2. **Flujo híbrido.** Generación por lote del periodo + creación/edición individual + líneas manuales de ingreso/deducción antes de aprobar.
3. **Deducciones:** CCSS obrera 10.67% (reduce el neto) + CCSS patronal 26.33% (línea informativa, **no** reduce el neto). Sin impuesto de renta.
4. **Salario quincenal** = `salario_base_mensual / 2`.
5. **Valor hora** = `salario_base_mensual / 240` (30 días × 8 h).
6. **Bloqueo:** una nómina en estado `aprobado` o `pagado` no es editable (ni líneas ni recálculo).
7. Las tasas (0.1067, 0.2633) se leen de `config/settings.php` (`nomina.ccss_obrera`, `nomina.ccss_patronal`), nunca hardcodeadas.

---

## 4. Módulo 9 — Nóminas

### 4.1 Flujo

1. El admin entra a `/nominas`, elige un **periodo** (filtro).
2. **Generar nóminas del periodo:** por cada empleado *activo* sin nómina en ese periodo se crea un borrador:
   - `salario_base` (snapshot) = `puesto.salario_base / 2`.
   - Línea de ingreso `salario_base` con ese monto.
   - Líneas de ingreso `horas_extra`: por cada registro de `horas_extra` del empleado en ese periodo, `monto = (salario_base_mensual / 240) × cantidad_horas × factor_recargo`.
   - `salario_bruto` = Σ ingresos.
   - Deducción `CCSS_empleado` = `bruto × 0.1067` (afecta el neto).
   - Deducción `CCSS_patronal` = `bruto × 0.2633` (informativa, no afecta el neto).
   - `total_ingresos`, `total_deducciones` (solo las que afectan el neto), `salario_neto = bruto − total_deducciones`.
   - `estado = borrador`.
3. **Detalle de nómina:** el admin abre una nómina y puede agregar/quitar líneas manuales (ingreso: bonificación/comisión/otro; deducción: embargo/otro). Al cambiar líneas se **recalcula** (solo en `borrador`).
4. **Aprobar** (`borrador`→`aprobado`) y **Marcar pagado** (`aprobado`→`pagado`).
5. Cada operación se registra en `auditoria` (INSERT/UPDATE/DELETE).

### 4.2 Componentes

1. `src/Helpers/NominaCalculadora.php` — funciones puras:
   - `valorHora(float $salarioMensual): float`
   - `montoHorasExtra(float $salarioMensual, float $horas, float $factor): float`
   - `calcularTotales(array $ingresos, array $deducciones): array{total_ingresos, total_deducciones, salario_bruto, salario_neto}` — `total_deducciones` excluye las informativas (CCSS_patronal).
   - `deduccionesCCSS(float $bruto, float $obrera, float $patronal): array` — devuelve las dos líneas.
2. `src/Repositories/NominasRepository.php` — solo SQL, transaccional:
   - `findAll(?int $idPeriodo): array`
   - `findByIdConLineas(int $id): ?array` (encabezado + ingresos + deducciones)
   - `empleadosActivosSinNomina(int $idPeriodo): array`
   - `horasExtraDelPeriodo(int $idEmpleado, int $idPeriodo): array`
   - `insertNomina(array $cab, array $ingresos, array $deducciones): int` (en transacción)
   - `insertIngreso(int $idNomina, array $linea): int`, `insertDeduccion(...)`, `deleteIngreso(int $id)`, `deleteDeduccion(int $id)`
   - `updateTotales(int $id, array $totales): void`
   - `updateEstado(int $id, string $estado): void`
   - `delete(int $id): void`
3. `src/Services/NominasService.php`:
   - `listar(?int $idPeriodo)`, `obtener(int $id)`
   - `generarPeriodo(int $idPeriodo, int $loggedInId, string $ip): int` (cuántas generó)
   - `crearIndividual(int $idEmpleado, int $idPeriodo, ...)`
   - `agregarIngreso(int $idNomina, array $datos, ...)`, `agregarDeduccion(...)`, `quitarLinea(...)`
   - `recalcular(int $idNomina)` (privado, tras cambios)
   - `aprobar(int $id, ...)`, `marcarPagado(int $id, ...)`, `eliminar(int $id, ...)`
   - Validaciones: periodo válido, no duplicar (UNIQUE), bloqueo si no es `borrador`, montos > 0.
   - Auditoría en cada mutación.
4. `src/Controllers/NominasController.php` — `index`, `generate`, `show`, `addIngreso`, `addDeduccion`, `removeLinea`, `aprobar`, `pagar`, `destroy`. Patrón idéntico a `HorasExtraController` (flash, `urlFor`, RBAC).
5. Plantillas:
   - `templates/nominas/index.html.twig` — filtro por periodo, botón "Generar", tabla de nóminas (empleado, bruto, deducciones, neto, estado, acciones).
   - `templates/nominas/detalle.html.twig` — encabezado + tabla de ingresos + tabla de deducciones + totales + acciones (agregar línea, aprobar, pagar).
6. `config/dependencies.php` — registrar Repository, Service, Controller, Calculadora.
7. `config/routes.php` — grupo `/nominas` con `RoleMiddleware('admin')` + `AuthMiddleware`:
   - `GET /nominas` → index
   - `POST /nominas/generar` → generate
   - `GET /nominas/{id}` → show
   - `POST /nominas/{id}/ingreso` → addIngreso
   - `POST /nominas/{id}/deduccion` → addDeduccion
   - `POST /nominas/{id}/linea/{tipo}/{idLinea}/eliminar` → removeLinea
   - `POST /nominas/{id}/aprobar` → aprobar
   - `POST /nominas/{id}/pagar` → pagar
   - `POST /nominas/{id}/eliminar` → destroy

### 4.3 Estados

`borrador` → (aprobar) → `aprobado` → (pagar) → `pagado`. Edición y borrado solo en `borrador`.

---

## 5. Módulo 10 — Aguinaldo (Ley 2412)

### 5.1 Flujo

1. Admin entra a `/aguinaldo`, elige **año**.
2. **Calcular aguinaldo:** por cada empleado activo:
   - `salarios_acumulados` = Σ `salario_bruto` de sus nóminas en estado `aprobado` o `pagado` cuyo periodo cae entre **1-dic (año−1)** y **30-nov (año)**.
   - `monto_aguinaldo` = `salarios_acumulados / 12`.
   - `estado = calculado`. `UNIQUE(id_empleado, anio)` (upsert / evitar duplicado).
3. **Marcar pagado** (`calculado`→`pagado`, registra `fecha_pago`).
4. Auditoría en cada operación.

### 5.2 Componentes

- `src/Repositories/AguinaldoRepository.php` — `findAll(int $anio)`, `salariosBrutosDelRango(int $idEmpleado, string $desde, string $hasta)`, `upsert(array)`, `updateEstadoPagado(int $id, string $fecha)`.
- `src/Services/AguinaldoService.php` — `listar(int $anio)`, `calcular(int $anio, ...)`, `marcarPagado(int $id, ...)`.
- `src/Controllers/AguinaldoController.php` — `index`, `calcular`, `pagar`.
- Plantilla `templates/aguinaldo/index.html.twig` — filtro por año, botón "Calcular", tabla (empleado, salarios acumulados, monto, estado, acción).
- DI + rutas (grupo admin `/aguinaldo`).

---

## 6. Pruebas (PHPUnit)

`tests/NominaCalculadoraTest.php`:
- `valorHora`: 240000 mensual → 1000.
- `montoHorasExtra`: factor 1.5 y 2.0.
- `calcularTotales`: bruto = Σ ingresos; CCSS_patronal excluida del neto; neto = bruto − CCSS obrera.
- Redondeo a 2 decimales solo en el resultado final; precisión completa en intermedios.

`tests/AguinaldoCalculoTest.php` (o dentro del Service con un cálculo puro):
- Σ brutos / 12.

---

## 7. Riesgos / notas

- **Precisión monetaria:** usar tipos `DECIMAL`/`float` con redondeo final a 2 decimales; nunca redondear intermedios.
- **Transacción:** la generación de una nómina (encabezado + líneas) debe ser atómica.
- **Idempotencia:** "Generar periodo" omite empleados que ya tienen nómina en ese periodo (no duplica).
- **Bloqueo de estado:** cualquier intento de editar una nómina no-borrador lanza error de negocio.
- **UI:** las nuevas vistas usan el tema visual ya aplicado (sidebar/tarjetas) y `extends layouts/base.html.twig`.
