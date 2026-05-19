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
│   ├── schema.sql              ← DDL completo 21 tablas (NO modificar sin avisar)
│   └── seed.sql                ← datos de prueba
├── tests/
└── logs/
```

---

## Base de datos — 21 tablas, normalizado a 3FN

### Grupo 1 — Configuración y acceso
| Tabla | PK | Descripción |
|---|---|---|
| `puestos` | `id_puesto` | Catálogo de puestos. `nombre` UNIQUE. |
| `usuarios` | `id_usuario` | Credenciales. `rol` ENUM('admin','empleado'). `contrasena_hash` bcrypt. |

### Grupo 2 — Empleados
| Tabla | PK | Descripción |
|---|---|---|
| `empleados` | `id_empleado` | 23 columnas. FK a `puestos` y `usuarios`. `cedula` UNIQUE. `estado` ENUM('activo','inactivo'). |
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
- Variables de sesión usadas en toda la app:
  - `$_SESSION['usuario_id']` — ID del usuario autenticado
  - `$_SESSION['usuario_nombre']` — nombre de usuario
  - `$_SESSION['usuario_rol']` — `'admin'` o `'empleado'`
- `AuthMiddleware` — redirige a `/login` si no hay sesión.
- `RoleMiddleware('admin')` — redirige a `/dashboard` si el rol no coincide.
- Contraseñas: `password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12])` / `password_verify()`.
- Cada LOGIN y LOGOUT debe registrarse en la tabla `auditoria`.

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
| 1 | Auth / Seguridad | `feature/auth` | 🔲 Pendiente |
| 2 | Mantenimientos (Puestos ✅, Periodos, Feriados, Usuarios) | `feature/mantenimientos` | 🟡 Puestos listo |
| 3 | Gestionar Empleados + datos_bancarios | `feature/empleados` | 🔲 Pendiente |
| 4 | Gestionar Asistencia | `feature/asistencia` | 🔲 Pendiente |
| 5 | Gestionar Horas Extra | `feature/horas-extra` | 🔲 Pendiente |
| 6 | Gestionar Vacaciones | `feature/vacaciones` | 🔲 Pendiente |
| 7 | Gestionar Incapacidades | `feature/incapacidades` | 🔲 Pendiente |
| 8 | Gestionar Permisos | `feature/permisos` | 🔲 Pendiente |
| 9 | Gestionar Nóminas | `feature/nominas` | 🔲 Pendiente |
| 10 | Calcular Aguinaldo | `feature/aguinaldo` | 🔲 Pendiente |
| 11 | Gestionar Liquidación | `feature/liquidacion` | 🔲 Pendiente |
| 12 | Evaluar Rendimiento | `feature/evaluaciones` | 🔲 Pendiente |
| 13 | Consultas / Reportes | `feature/reportes` | 🔲 Pendiente |

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

*Última actualización: Mayo 2026 — Gabriel Iván Madrigal Flores*
