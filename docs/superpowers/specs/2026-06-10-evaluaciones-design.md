# Diseño — Módulo Evaluar Rendimiento

- **Fecha:** 2026-06-10
- **Autor:** Gabriel Iván Madrigal Flores (con asistencia de IA)
- **Estado:** Aprobado
- **Objetivo:** Implementar el módulo 12 (Evaluar Rendimiento) siguiendo la arquitectura Controller → Service → Repository, con el cálculo del puntaje ponderado aislado en una clase pura testeable.

---

## 1. Contexto

Los módulos 1–11 están completos (hasta Liquidación). Sigue **Evaluar Rendimiento**. El modelo de datos ya existe en `database/schema.sql`:

- `evaluaciones` (cabecera): `id_empleado` (FK), `fecha_evaluacion` DATE, `periodo_evaluado` INT (año evaluado), `puntaje_total` DECIMAL(5,2), `observaciones` VARCHAR(500) NULL.
- `detalle_evaluacion` (líneas): `id_evaluacion` (FK, ON DELETE CASCADE), `criterio` VARCHAR(150), `puntaje` DECIMAL(5,2), `peso` DECIMAL(5,2) (porcentual).

No existe vista de empleado en la app todavía; el grupo `/mi-perfil` en `config/routes.php` es un placeholder vacío. Este módulo introduce la primera ruta orientada al empleado (`/mis-evaluaciones`).

---

## 2. Alcance

**Incluido:**
- CRUD completo de evaluaciones para el admin (crear, ver detalle, editar, eliminar).
- Vista de detalle con desglose ponderado por criterio.
- Vista de solo lectura para el empleado (`/mis-evaluaciones`).
- Pruebas PHPUnit del cálculo del puntaje ponderado.

**Fuera de alcance:**
- Catálogo de criterios en BD (la plantilla vive en `config/settings.php`).
- Notificaciones al empleado.
- Comparativas o gráficos entre años (puede cubrirse en módulo 13, Reportes).

---

## 3. Decisiones (confirmadas)

1. **Plantilla fija de 5 criterios** definida en `config/settings.php` (clave `criterios_evaluacion`):

   | Criterio | Peso |
   |---|---|
   | Puntualidad | 20 % |
   | Calidad del trabajo | 25 % |
   | Productividad | 25 % |
   | Trabajo en equipo | 15 % |
   | Actitud | 15 % |

   El formulario pre-carga estas filas; el admin solo digita puntajes. Los pesos siempre suman 100 %.
2. **Escala 1–100** por criterio. `puntaje_total` = promedio ponderado, calculado por `EvaluacionCalculadora` (clase pura). Nunca lo digita el usuario.
3. **Una evaluación por empleado por año** (`periodo_evaluado`). El esquema no tiene UNIQUE, así que la regla se valida en el Service.
4. **Acceso:** grupo `/evaluaciones` con `RoleMiddleware('admin')` + `AuthMiddleware`; ruta `GET /mis-evaluaciones` solo con `AuthMiddleware` (cada usuario ve únicamente sus propias evaluaciones, resueltas vía FK `empleados.id_usuario`).

---

## 4. Cálculo

`puntajeTotal = Σ (puntaje_i × peso_i) / 100`, con puntajes en 1–100 y pesos porcentuales que suman 100.

- Precisión completa en intermedios; redondeo a 2 decimales solo al final.
- Ejemplo: todos los criterios en 80 → total 80.00. Puntajes (90, 80, 70, 100, 60) con pesos (20, 25, 25, 15, 15) → 90×0.20 + 80×0.25 + 70×0.25 + 100×0.15 + 60×0.15 = 79.50.

---

## 5. Componentes (patrón de 7 pasos)

1. `src/Helpers/EvaluacionCalculadora.php` — puro:
   - `puntajeTotal(array $detalles): float` — recibe `[['puntaje' => float, 'peso' => float], …]`, devuelve el ponderado redondeado a 2 decimales. Lanza `InvalidArgumentException` si los pesos no suman 100 o algún puntaje está fuera de 1–100.
   - `validarPesos(array $pesos): bool` — suman 100 (tolerancia 0.01).
2. `src/Repositories/EvaluacionesRepository.php` — SQL (ambas tablas, transacción):
   - `findAll(): array` (JOIN empleados para nombre y cédula)
   - `findById(int $id): ?array`
   - `findDetalles(int $idEvaluacion): array`
   - `findByEmpleadoUsuario(int $idUsuario): array` (JOIN empleados ON id_usuario)
   - `existeParaEmpleadoAnio(int $idEmpleado, int $anio, ?int $excluirId = null): bool`
   - `insertConDetalles(array $cabecera, array $detalles): int` (transacción)
   - `updateConDetalles(int $id, array $cabecera, array $detalles): void` (transacción: UPDATE cabecera + DELETE detalles + re-INSERT)
   - `delete(int $id): void` (CASCADE elimina detalles)
   - `empleadosActivos(): array`
3. `src/Services/EvaluacionesService.php`:
   - `listar()`, `obtener(int $id)` (cabecera + detalles), `datosFormulario()` (empleados activos + plantilla de criterios)
   - `crear(array $datos, int $loggedInId, string $ip): int`
   - `actualizar(int $id, array $datos, int $loggedInId, string $ip): void`
   - `eliminar(int $id, int $loggedInId, string $ip): void`
   - `misEvaluaciones(int $idUsuario): array`
   - Validaciones: empleado activo válido; `periodo_evaluado` entre 2000 y el año actual; `fecha_evaluacion` válida; puntajes numéricos 1–100; sin duplicado empleado+año (excluyendo el propio id al editar); observaciones ≤ 500 caracteres.
   - Auditoría (INSERT/UPDATE/DELETE) en crear/actualizar/eliminar.
4. `src/Controllers/EvaluacionesController.php` — `index`, `create`, `store`, `show`, `edit`, `update`, `destroy`, `misEvaluaciones` (patrón idéntico a los módulos previos, flash + POST-Redirect-GET).
5. Plantillas (extienden `layouts/base.html.twig`, tema visual existente):
   - `templates/evaluaciones/index.html.twig` — lista con empleado, año, fecha, puntaje y badge de color por rango (≥ 90 verde "Excelente", 80–89.99 azul "Muy bueno", 70–79.99 amarillo "Bueno", 60–69.99 naranja "Regular", < 60 rojo "Deficiente").
   - `templates/evaluaciones/form.html.twig` — compartido crear/editar: select de empleado, año, fecha, 5 filas fijas de criterios (nombre y peso de solo lectura, input numérico 1–100), observaciones.
   - `templates/evaluaciones/detalle.html.twig` — desglose ponderado por criterio + total + badge.
   - `templates/evaluaciones/mis_evaluaciones.html.twig` — lista de solo lectura del empleado con el desglose de criterios incluido en la misma página (sin enlace a la vista admin, para no abrir `/evaluaciones/{id}` a empleados).
6. `config/dependencies.php` — registrar Calculadora, Repository, Service, Controller.
7. `config/routes.php`:
   - Grupo `/evaluaciones` (admin): `GET ''` index, `GET /crear`, `POST /crear`, `GET /{id}`, `GET /{id}/editar`, `POST /{id}/editar`, `POST /{id}/eliminar`.
   - `GET /mis-evaluaciones` → `misEvaluaciones`, solo `AuthMiddleware`.
   - Sidebar: conectar el ítem "Evaluaciones" (admin) y agregar "Mis evaluaciones" visible para rol empleado.

---

## 6. Pruebas (PHPUnit)

`tests/Helpers/EvaluacionCalculadoraTest.php`:
- Ponderado correcto: todos 80 → 80.00; caso mixto (90, 80, 70, 100, 60) → 79.50.
- Pesos que no suman 100 → excepción.
- Puntaje fuera de 1–100 → excepción.
- Redondeo a 2 decimales (caso con fracción periódica).

---

## 7. Riesgos / notas

- **Editar reemplaza detalles:** DELETE + re-INSERT dentro de la transacción; simple y seguro con el CASCADE del esquema.
- **Usuario sin ficha de empleado** (p. ej. admin puro): `mis-evaluaciones` muestra lista vacía con mensaje informativo, no error.
- **Plantilla en config, criterio en BD como texto:** evaluaciones históricas conservan los criterios/pesos con los que se crearon aunque la plantilla cambie después.
- **Precisión monetaria/numérica:** redondeo solo al final, como en las calculadoras previas.
