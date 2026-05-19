# Sistema de Gestión de Nómina — Lubrimotos del Sur

**Trabajo Final de Graduación**  
Universidad Internacional de las Américas · Ingeniería en Software  
Estudiante: Gabriel Iván Madrigal Flores  
Tutor: Fernando Rios Vargas

---

## Stack tecnológico

| Capa | Tecnología |
|---|---|
| Lenguaje | PHP 8.x |
| Framework | Slim 4 |
| Base de datos | MySQL (PDO, sin ORM) |
| Plantillas | Twig 3 |
| Contenedor DI | PHP-DI 7 |
| Servidor local | XAMPP / Apache |
| Control de versiones | Git / GitHub |

---

## Requisitos previos

- XAMPP ≥ 8.1 (PHP 8.1+, MySQL 8.0+, Apache)
- [Composer](https://getcomposer.org/) instalado globalmente
- Git

---

## Instalación paso a paso

### 1. Clonar el repositorio

```bash
git clone https://github.com/Gabrixf/Proyecto_Graduacion_Gabriel_Madrigal_Flores.git
cd Proyecto_Graduacion_Gabriel_Madrigal_Flores
```

### 2. Instalar dependencias de Composer

```bash
composer install
```

Esto descarga las librerías en `/vendor/`. No modificar nada dentro de esa carpeta.

### 3. Configurar variables de entorno

```bash
cp .env.example .env
```

Abrir `.env` y ajustar los valores:

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=lubrimotos_nomina
DB_USER=root
DB_PASS=           # vacío si XAMPP no tiene contraseña
APP_ENV=development
```

### 4. Crear la base de datos e importar el esquema

1. Iniciar XAMPP (Apache + MySQL).
2. Abrir **phpMyAdmin** en `http://localhost/phpmyadmin`.
3. Crear la base de datos `lubrimotos_nomina` (cotejamiento `utf8mb4_unicode_ci`).
4. Seleccionar la base de datos → pestaña **Importar** → elegir `database/schema.sql` → Continuar.
5. (Opcional) Importar `database/seed.sql` para cargar datos de prueba.

### 5. Configurar Apache (XAMPP)

Copiar la carpeta del proyecto a `C:\xampp\htdocs\` **o** configurar un Virtual Host apuntando al directorio `public/` del proyecto.

**Opción A — Subdirectorio rápido (sin Virtual Host)**

Abrir `.env` y establecer:
```env
APP_BASE_PATH=/Proyecto_Graduacion_Gabriel_Madrigal_Flores/public
```
Acceder en: `http://localhost/Proyecto_Graduacion_Gabriel_Madrigal_Flores/public/`

**Opción B — Virtual Host (recomendado)**

Editar `C:\xampp\apache\conf\extra\httpd-vhosts.conf` y agregar:

```apache
<VirtualHost *:80>
    ServerName lubrimotos.local
    DocumentRoot "C:/xampp/htdocs/Proyecto_Graduacion_Gabriel_Madrigal_Flores/public"
    <Directory "C:/xampp/htdocs/Proyecto_Graduacion_Gabriel_Madrigal_Flores/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Agregar `127.0.0.1 lubrimotos.local` a `C:\Windows\System32\drivers\etc\hosts`.

Reiniciar Apache y acceder en `http://lubrimotos.local/`.

### 6. Verificar que mod_rewrite está activo

En `C:\xampp\apache\conf\httpd.conf` descomentaer (quitar `#`):
```
LoadModule rewrite_module modules/mod_rewrite.so
```

### 7. Crear el directorio de logs

```bash
mkdir logs
```

El directorio `logs/` ya está en `.gitignore`; solo crear el archivo `.gitkeep` para que Git lo rastree:

```bash
touch logs/.gitkeep
```

---

## Estructura del proyecto

```
/
├── .env.example          ← Plantilla de configuración
├── composer.json
├── public/               ← Document root de Apache
│   ├── index.php         ← Bootstrap de Slim
│   ├── .htaccess         ← Reescritura de URLs
│   └── assets/           ← CSS, JS, imágenes estáticas
├── src/
│   ├── Controllers/      ← HTTP handlers (1 por módulo)
│   ├── Services/         ← Lógica de negocio
│   ├── Repositories/     ← Acceso a BD vía PDO
│   ├── Models/           ← POPOs (Plain Old PHP Objects)
│   ├── Middleware/       ← Auth, Role, CSRF, etc.
│   ├── Database/         ← Fábrica de conexión PDO
│   └── Helpers/          ← Funciones utilitarias
├── templates/            ← Plantillas Twig
│   ├── layouts/base.html.twig
│   ├── partials/
│   └── <modulo>/         ← index, create, edit por módulo
├── config/
│   ├── settings.php      ← Configuración global
│   ├── dependencies.php  ← Contenedor PHP-DI
│   ├── middleware.php    ← Registro de middlewares
│   └── routes.php        ← Definición de rutas
├── database/
│   ├── schema.sql        ← DDL completo (21 tablas)
│   └── seed.sql          ← Datos de prueba
├── tests/                ← PHPUnit (estructura lista)
└── logs/                 ← Archivos de log (en .gitignore)
```

---

## Módulos del sistema

| # | Módulo | Estado |
|---|---|---|
| 1 | Auth / Seguridad | 🔲 Pendiente |
| 2 | Mantenimientos (Puestos, Periodos, Feriados, Usuarios) | 🔲 Pendiente |
| 3 | Gestionar Empleados | 🔲 Pendiente |
| 4 | Gestionar Asistencia | 🔲 Pendiente |
| 5 | Gestionar Horas Extra | 🔲 Pendiente |
| 6 | Gestionar Vacaciones | 🔲 Pendiente |
| 7 | Gestionar Incapacidades | 🔲 Pendiente |
| 8 | Gestionar Permisos | 🔲 Pendiente |
| 9 | Gestionar Nóminas | 🔲 Pendiente |
| 10 | Calcular Aguinaldo | 🔲 Pendiente |
| 11 | Gestionar Liquidación | 🔲 Pendiente |
| 12 | Evaluar Rendimiento | 🔲 Pendiente |
| 13 | Consultas / Reportes | 🔲 Pendiente |

---

## Reglas legales costarricenses implementadas

- **CCSS cuota obrera**: 10.67 % del salario bruto
- **CCSS cuota patronal**: 26.33 % del salario bruto
- **Horas extra (jornada ordinaria)**: factor 1.50
- **Horas extra (día feriado)**: factor 2.00
- **Aguinaldo**: 1/12 de los salarios brutos del período 1-dic al 30-nov (Ley 2412)
- **Auditoría**: tabla `auditoria` para trazabilidad conforme Ley 8968 (Protección de Datos)

---

## Flujo de trabajo con Git

```bash
# Crear rama por módulo
git checkout -b feature/auth

# Commit con mensaje claro
git add .
git commit -m "feat(auth): implementa login con sesiones PHP y RBAC"

# Push y Pull Request a main
git push origin feature/auth
```

---

## Contacto

Gabriel Iván Madrigal Flores — wizardxxdd110@gmail.com
