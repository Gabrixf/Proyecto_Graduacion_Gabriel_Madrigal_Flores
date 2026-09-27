# CLAUDE.md — Instrucciones de contexto para el asistente de IA

> Este archivo le da a Claude (u otro asistente de IA) todo el contexto del proyecto.
> Léelo completo antes de sugerir cualquier código o cambio.

---

## Identidad del proyecto

| Campo | Valor |
|---|---|
| Nombre | Sistema web de gestión de nómina — Lubrimotos del Sur |
| Tipo | Trabajo Final de Graduación (TFG) |
| Universidad | Universidad Internacional de las Américas, Costa Rica |
| Carrera | Ingeniería en Software |
| Estudiante | Gabriel Iván Madrigal Flores |
| Tutor | Fernando Rios Vargas |
| Defensa | Abril 2026 |
| Objetivo | Reemplazar el proceso de nómina en libreta física de una PyME costarricense con una aplicación web. |

---

## Stack tecnológico — BLOQUEADO, no proponer cambios

| Capa | Tecnología | Versión |
|---|---|---|
| Lenguaje | PHP | ^8.1 |
| Framework | Slim 4 | ^4.12 |
| Base de datos | MySQL + PDO puro | 8.0 |
| ORM | **Ninguno** — solo PDO raw | — |
| Plantillas | Twig | ^3.8 |
| Contenedor DI | PHP-DI | ^7.0 |
| Twig en Slim | slim/twig-view | ^3.4 |
| Variables de entorno | vlucas/phpdotenv | ^5.6 |
| Logging | Monolog | ^3.5 |
| Exportar Excel | phpoffice/phpspreadsheet | ^5.10 |
| Exportar PDF | dompdf/dompdf | ^3.1 |
| Servidor local | XAMPP (Apache + MySQL) | — |
| Servidor producción | ScalaHosting (Apache) | — |
| Control de versiones | Git / GitHub | — |
| Tests (estructura lista) | PHPUnit | ^11.0 |

**Nunca sugerir:** Laravel, Symfony, Eloquent, Doctrine, React, Vue, Angular, npm, Node, Docker.

---

## Arquitectura — patrón estricto

```
HTTP Request
    │
    ▼
Controller          ← solo HTTP: parsear input, llamar Service, renderizar Twig
    │
    ▼
Service             ← toda la lógica de negocio y reglas legales
    │
    ▼
Repository          ← único punto de acceso a BD (PDO raw)
    │
    ▼
MySQL (PDO)
```

- **Controllers** nunca tocan PDO directamente.
- **Services** nunca extienden ni tocan Slim.
- **Repositories** no contienen lógica de negocio; solo SQL.
- Un **Model** es un POPO (Plain Old PHP Object) si se necesita encapsular datos.
- Todos los archivos usan `declare(strict_types=1)`.
- Nombres de clases en PascalCase, métodos en camelCase, tablas/columnas en snake_case.

---

## Estructura de directorios

```
/
├── CLAUDE.md                   ← este archivo
├── .env.example                ← plantilla de variables de entorno
├── .gitignore
├── composer.json
├── README.md
├── public/                     ← document root de Apache
│   ├── index.php               ← bootstrap de Slim (NO modificar estructura)
│   ├── .htaccess               ← reescritura de URLs
│   └── assets/
│       ├── css/app.css
│       └── js/app.js
├── src/                        ← autoload PSR-4: "App\\" → "src/"
│   ├── Controllers/
│   ├── Services/
│   ├── Repositories/
│   ├── Models/
│   ├── Middleware/
│   │   ├── AuthMiddleware.php  ← verifica sesión activa
│   │   └── RoleMiddleware.php  ← verifica rol (admin / empleado)
│   ├── Database/
│   │   └── Connection.php      ← singleton PDO
│   └── Helpers/
├── templates/                  ← plantillas Twig
│   ├── layouts/
│   │   └── base.html.twig      ← layout base con navbar RBAC-aware
│   ├── partials/
│   ├── auth/
│   │   └── login.html.twig
│   ├── dashboard/
│   │   └── index.html.twig
│   └── <modulo>/               ← index.html.twig + form.html.twig por módulo
├── config/
│   ├── settings.php            ← configuración global (lee .env)
│   ├── dependencies.php        ← contenedor PHP-DI
│   ├── middleware.php          ← registro de middlewares globales
│   └── routes.php              ← todas las rutas
├── database/
│   ├── schema.sql              ← DDL completo 29 tablas (NO modificar sin avisar)
│   └── seed.sql                ← datos de prueba
├── tests/
└── logs/
```

---

## Base de datos — 29 tablas, normalizado a 3FN

> **Nota sobre nulos (23/08/2026, instrucción explícita del tutor):** ninguna columna del
> esquema acepta `NULL`. Los campos antes opcionales usan `DEFAULT ''`/`DEFAULT 0`, y las FK
> opcionales apuntan a una **fila centinela `id=1`** en su tabla padre (ej. `departamentos`,
> `horarios`, `usuarios`, `feriados`, `parametros_legales`, `distritos` tienen un registro
> `id=1` reservado tipo "Sin asignar"/"No aplica"). Las fechas "aún no aplica" usan el valor
> centinela `9999-12-31` en vez de `NULL`.

### Grupo 1 — Configuración y acceso
| Tabla | PK | Descripción |
|---|---|---|
| `puestos` | `id_puesto` | Catálogo de puestos. `nombre` UNIQUE. FK a `departamentos` (centinela id=1 si no se asigna). |
| `usuarios` | `id_usuario` | Credenciales. `rol` ENUM('admin','empleado'). `contrasena_hash` bcrypt. `id_usuario=1` es la fila centinela "sin_cuenta" (inactiva). |

### Grupo 2 — Empleados
| Tabla | PK | Descripción |
|---|---|---|
| `empleados` | `id_empleado` | Reducida a 10 columnas (23/08/2026): datos de identidad/contacto se movieron a `persona` (Grupo 8). Contiene `id_persona` (FK 1:1), `id_puesto`, `id_usuario`, `id_horario`, seguros (CCSS/INS), fechas de ingreso/salida y `estado` ENUM('activo','inactivo'). |
| `datos_bancarios` | `id_datos_bancarios` | Separada de `empleados` por Ley 8968. `tipo_cuenta` ENUM('corriente','ahorros'). `moneda` ENUM('CRC','USD'). |

### Grupo 3 — Períodos y feriados
| Tabla | PK | Descripción |
|---|---|---|
| `periodos_pago` | `id_periodo` | Períodos quincenales. `estado` ENUM('abierto','cerrado'). |
| `feriados` | `id_feriado` | `fecha` UNIQUE. `tipo` ENUM('obligatorio_pago','no_obligatorio_pago'). |

### Grupo 4 — Solicitudes y eventos laborales
| Tabla | PK | Descripción |
|---|---|---|
| `solicitudes` | `id_solicitud` | `tipo` ENUM('horas_extra','vacaciones','permiso'). `estado` ENUM('pendiente','aprobada','rechazada'). |
| `asistencia` | `id_asistencia` | UNIQUE(`id_empleado`, `fecha`). FK a `periodos_pago` y `feriados`. |
| `horas_extra` | `id_hora_extra` | Requiere `id_solicitud` aprobada. `factor_recargo` DECIMAL(3,2). |
| `vacaciones` | `id_vacacion` | Requiere `id_solicitud` aprobada. `dias_tomados` DECIMAL(4,1). |
| `incapacidades` | `id_incapacidad` | `tipo` ENUM('CCSS','INS','particular'). Sin aprobación previa. |
| `permisos` | `id_permiso` | Requiere `id_solicitud`. `con_goce_salarial` TINYINT(1). |
| `saldo_vacaciones` | `id_saldo` | UNIQUE(`id_empleado`, `anio`). `dias_disponibles` = ganados − disfrutados. |

### Grupo 5 — Nómina y pagos
| Tabla | PK | Descripción |
|---|---|---|
| `nominas` | `id_nomina` | UNIQUE(`id_empleado`, `id_periodo`). `estado` ENUM('borrador','aprobado','pagado'). |
| `ingresos_nomina` | `id_ingreso` | `tipo` ENUM('salario_base','horas_extra','bonificacion','comision','feriado','otro'). |
| `deducciones_nomina` | `id_deduccion` | `tipo` ENUM('CCSS_empleado','CCSS_patronal','impuesto_renta','embargo','otro'). |
| `aguinaldo` | `id_aguinaldo` | UNIQUE(`id_empleado`, `anio`). `estado` ENUM('calculado','pagado'). |
| `liquidacion` | `id_liquidacion` | UNIQUE(`id_empleado`). `motivo` ENUM('renuncia','despido_con_causa','despido_sin_causa','mutuo_acuerdo','jubilacion'). |

### Grupo 6 — Evaluación y auditoría
| Tabla | PK | Descripción |
|---|---|---|
| `evaluaciones` | `id_evaluacion` | Evaluación anual por empleado. |
| `detalle_evaluacion` | `id_detalle` | Criterios con `puntaje` y `peso` porcentual. |
| `auditoria` | `id_auditoria` | `accion` ENUM('INSERT','UPDATE','DELETE','LOGIN','LOGOUT'). Ley 8968. |

### Grupo 7 — Parametrización y estructura organizacional

> Agregado 23/08/2026 a partir de retroalimentación del tutor (Braulio Sandí Morales): faltaba
> estructura organizacional (departamentos), historial de contratos y una forma de parametrizar
> los factores legales sin tocar código. **Contratos ya tiene código completo (Repository/Service/Controller/vistas, 14/09/2026)** — ver
> `docs/superpowers/specs/2026-09-14-contratos-module-design.md`. Departamentos, Horarios y
> ParametrosLegales siguen solo en el esquema; el código PHP de esos 3 módulos todavía no está
> construido.

| Tabla | PK | Descripción |
|---|---|---|
| `departamentos` | `id_departamento` | Catálogo de departamentos. `nombre` UNIQUE. |
| `horarios` | `id_horario` | Catálogo de horarios/turnos (hora_entrada, hora_salida, dias_semana). `nombre` UNIQUE. |
| `contratos` | `id_contrato` | Historial de contratos laborales por empleado (1:N, permite renovaciones). `tipo_contrato` ENUM('tiempo_indefinido','plazo_fijo','obra_determinada'). |
| `parametros_legales` | `id_parametro` | Factores legales (factor HE, % CCSS) versionados por `fecha_inicio`/`fecha_fin` de vigencia. UNIQUE(`clave`, `fecha_inicio`). Reemplazará a `config/settings.php['nomina']` cuando se implemente el código. `id_parametro=1` es la fila centinela "no_aplica". |

### Grupo 8 — Personas y geografía

> Agregado 23/08/2026 a partir de retroalimentación del tutor: debe existir una tabla `persona`
> antes de `empleados` (supertipo de identidad/contacto, reutilizable a futuro), y la dirección
> debe normalizarse en 3 tablas (`provincias`/`cantones`/`distritos`) en vez de texto libre.
> **`EmpleadosRepository`/`EmpleadosService`/el formulario ya fueron migrados (14/09/2026) para
> leer/escribir a través de `persona`** — ver `docs/superpowers/specs/2026-09-14-empleados-persona-migration-design.md`.
> **Fase 2 (14/09/2026, completa):** los otras 12 repositorios que hacían `JOIN empleados` y
> leían `nombre`/`apellidos`/`cedula` directo de esa tabla (Asistencia, Horas Extra, Vacaciones,
> Incapacidades, Permisos, Nóminas, Aguinaldo, Liquidación, Evaluaciones, Reportes, Solicitudes,
> Portal) ahora agregan `JOIN persona per ON per.id_persona = e.id_persona` y leen esas columnas
> de `per.*` — ya no queda ningún acceso a las columnas viejas de `empleados`. Datos geográficos: subconjunto representativo
> (las 7 provincias reales + el cantón cabecera de cada una + distritos reales conocidos),
> no el catálogo completo del INEC (~84 cantones / 500+ distritos) — ver comentarios en
> `database/schema.sql` y `seed.sql`.

| Tabla | PK | Descripción |
|---|---|---|
| `provincias` | `id_provincia` | Catálogo de provincias de Costa Rica. `nombre` UNIQUE. `id_provincia=1` es la fila centinela "No aplica". |
| `cantones` | `id_canton` | FK a `provincias`. UNIQUE(`id_provincia`, `nombre`). `id_canton=1` es la fila centinela. |
| `distritos` | `id_distrito` | FK a `cantones`. UNIQUE(`id_canton`, `nombre`). `id_distrito=1` es la fila centinela "No aplica", usada por defecto en `persona`. |
| `persona` | `id_persona` | Supertipo de identidad/contacto (nombre, cédula, contacto, dirección alterna). `cedula` UNIQUE. FK a `distritos` (principal y alterna). Especializado hoy solo por `empleados` (FK 1:1 vía `empleados.id_persona`). |

---

## Reglas legales de Costa Rica — implementar siempre con exactitud

```php
// En config/settings.php — NO cambiar estos valores
'ccss_obrera'    => 0.1067,   // 10.67 % cuota obrera (empleado)
'ccss_patronal'  => 0.2633,   // 26.33 % cuota patronal (empresa)
'factor_he_ord'  => 1.50,     // horas extra jornada ordinaria
'factor_he_feri' => 2.00,     // horas extra día feriado
// Aguinaldo: suma salarios brutos (1-dic → 30-nov) ÷ 12  (Ley 2412)
// Preaviso y cesantía: Código de Trabajo CR, Arts. 28-29
```

---

## Autenticación y RBAC

- Sesiones PHP nativas (`session_start()` en `public/index.php`).
- Variables de sesión — solo `AuthController` (login/logout) y `AuthMiddleware` las leen/escriben directamente:
  - `$_SESSION['usuario_id']` — ID del usuario autenticado
  - `$_SESSION['usuario_nombre']` — nombre de usuario
  - `$_SESSION['usuario_rol']` — `'super_admin'`, `'admin'` o `'empleado'` (jerárquico: `super_admin` > `admin` > `empleado`)
- `AuthMiddleware` — redirige a `/login` si no hay sesión; si la hay, adjunta al `Request` el atributo `usuario` (`['id', 'nombre', 'rol']`).
- **Controllers y `RoleMiddleware` nunca leen `$_SESSION['usuario_*']` directamente** — siempre vía `$request->getAttribute('usuario')`. Esto es lo que permite testear Controllers sin bootstrapear una sesión real (construir un `Request` con el atributo ya seteado alcanza). Cada Controller que lo necesite expone un helper privado `usuarioId(Request $request): int`.
  - Excepción deliberada: `AuthController::showLogin/login/logout` — esas rutas no pasan por `AuthMiddleware` (login es pre-sesión; logout debe funcionar incluso si la sesión ya expiró), así que ahí `$_SESSION` sigue siendo la fuente directa.
- `RoleMiddleware($rol)` — jerárquico por rango (`empleado=0, admin=1, super_admin=2`); deja pasar si el rango del usuario es igual o mayor al requerido, y redirige a `/dashboard` si no (leyendo el atributo `usuario`, no la sesión). Solo el grupo `/mantenimientos` (Puestos, Períodos, Feriados, Contratos, Usuarios) exige `super_admin`; el resto de grupos admin-only siguen exigiendo `admin` (un `super_admin` entra igual, por jerarquía).
- Contraseñas: `password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12])` / `password_verify()`.
- Cada LOGIN y LOGOUT debe registrarse en la tabla `auditoria`.

> **Nota sobre roles (13/09/2026, retroalimentación del tutor Braulio Sandí Morales):** se agregó el rol
> `super_admin`, jerárquico por encima de `admin`. Controla exclusivamente Usuarios y los catálogos del
> grupo `/mantenimientos` (Puestos, Períodos, Feriados, Contratos) — el resto de los módulos siguen aceptando `admin`
> como antes, ya que un `super_admin` los hereda por jerarquía. El sistema no permite que quede activo cero
> `super_admin` a la vez (`UsuariosService::bloquearSiEsUltimoSuperAdminActivo`).

---

## Convenciones de rutas

```
GET    /modulo                    → lista (index)
GET    /modulo/crear              → formulario nuevo
POST   /modulo/crear              → guardar nuevo (store)
GET    /modulo/{id}/editar        → formulario editar
POST   /modulo/{id}/editar        → guardar cambios (update)
POST   /modulo/{id}/eliminar      → eliminar (destroy)
```

- No usar DELETE/PUT/PATCH (los formularios HTML solo soportan GET y POST).
- Todos los grupos de admin van envueltos en `->add(new RoleMiddleware('admin'))->add(new AuthMiddleware())`.

---

## Patrón de un módulo nuevo — copiar siempre este orden

1. `src/Repositories/NombreRepository.php` — métodos: `findAll`, `findById`, `insert`, `update`, `delete`
2. `src/Services/NombreService.php` — métodos: `listar`, `obtener`, `crear`, `actualizar`, `eliminar` + validaciones privadas
3. `src/Controllers/NombreController.php` — métodos: `index`, `create`, `store`, `edit`, `update`, `destroy`
4. `templates/nombre/index.html.twig` — extiende `layouts/base.html.twig`
5. `templates/nombre/form.html.twig` — compartido para crear y editar
6. Registrar en `config/dependencies.php` (Repository → Service → Controller)
7. Registrar rutas en `config/routes.php`

El módulo `Puestos` es el ejemplo de referencia. Copiar exactamente esa estructura.

---

## Mensajes flash (patrón POST-Redirect-GET)

```php
// En el Controller, antes de redirect:
$_SESSION['flash_success'] = 'Operación exitosa.';
$_SESSION['flash_error']   = 'Ocurrió un error.';

// Leer y borrar en el mismo Controller (método consumeFlash):
private function consumeFlash(string $key): ?string
{
    $msg = $_SESSION[$key] ?? null;
    unset($_SESSION[$key]);
    return $msg;
}
```

Pasarlos a Twig como `flashSuccess` y `flashError`. El layout base los muestra automáticamente.

---

## Módulos — estado de desarrollo

| # | Módulo | Rama Git | Estado |
|---|---|---|---|
| 1 | Auth / Seguridad | `feature/auth` | ✅ Completo |
| 2 | Mantenimientos (Puestos, Periodos, Feriados, Usuarios) | `feature/mantenimientos` | ✅ Completo |
| 3 | Gestionar Empleados + datos_bancarios | `feature/empleados` | ✅ Completo (pendiente prueba manual) |
| 4 | Gestionar Asistencia | `feature/asistencia` | ✅ Completo (pendiente prueba manual) |
| — | Solicitudes (unificado: HE/Vacaciones/Permisos) | `feature/solicitudes` | ✅ Completo (pendiente prueba manual) |
| 5 | Gestionar Horas Extra | `feature/horas-extra` | ✅ Completo (pendiente prueba manual) |
| 6 | Gestionar Vacaciones | `feature/vacaciones` | ✅ Completo (pendiente prueba manual) |
| 7 | Gestionar Incapacidades | `feature/incapacidades` | ✅ Completo (pendiente prueba manual) |
| 8 | Gestionar Permisos | `feature/permisos` | ✅ Completo (pendiente prueba manual) |
| 9 | Gestionar Nóminas | `feature/nominas` | ✅ Completo (pendiente prueba manual) |
| 10 | Calcular Aguinaldo | `feature/nominas` | ✅ Completo (pendiente prueba manual) |
| 11 | Gestionar Liquidación | `feature/liquidacion` | ✅ Completo (pendiente prueba manual) |
| 12 | Evaluar Rendimiento | `feature/evaluaciones` | ✅ Completo (pendiente prueba manual) |
| 13 | Consultas / Reportes | `feature/reportes` | ✅ Completo (pendiente prueba manual) |
| 14 | Gestionar Contratos | `feature/contratos-module` | ✅ Completo (pendiente prueba manual) |

**Orden de desarrollo acordado:** Auth → Mantenimientos → Empleados → Asistencia → HE → Vacaciones → Incapacidades → Permisos → Nóminas → Aguinaldo → Liquidación → Evaluaciones → Reportes.

---

## Lo que NO hacer

- ❌ No usar ORM (Eloquent, Doctrine, etc.)
- ❌ No instalar npm / Node / frameworks JS
- ❌ No crear SPAs ni componentes React/Vue
- ❌ No hardcodear credenciales — siempre leer de `.env`
- ❌ No acceder a PDO desde un Controller
- ❌ No poner lógica de negocio en un Repository
- ❌ No cambiar el esquema de BD sin actualizar `database/schema.sql`
- ❌ No commitear `.env` (está en `.gitignore`)
- ❌ No commitear directamente a `main` — siempre usar ramas `feature/*`

---

## Instalación local (recordatorio rápido)

```bash
composer install
cp .env.example .env          # ajustar DB_USER, DB_PASS
# Importar database/schema.sql en phpMyAdmin → lubrimotos_nomina
# Importar database/seed.sql (opcional, datos de prueba)
# Apuntar Apache DocumentRoot a /public
```

URL local: `http://localhost/` (Virtual Host) o `http://localhost/<carpeta>/public/`

---

*Última actualización: Junio 2026 — Gabriel Iván Madrigal Flores*
