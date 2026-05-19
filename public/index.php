<?php

declare(strict_types=1);

// ──────────────────────────────────────────────────────────
// Bootstrap del sistema de nómina — Lubrimotos del Sur
// ──────────────────────────────────────────────────────────

// Zona horaria de Costa Rica (no usa horario de verano)
date_default_timezone_set('America/Costa_Rica');

// Autoloader de Composer
require __DIR__ . '/../vendor/autoload.php';

// Cargar variables de entorno (.env)
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

// Iniciar sesión PHP de forma segura
if (session_status() === PHP_SESSION_NONE) {
    session_name($_ENV['SESSION_NAME'] ?? 'lubrimotos_session');
    session_set_cookie_params([
        'lifetime' => (int)($_ENV['SESSION_LIFETIME'] ?? 3600),
        'path'     => '/',
        'secure'   => ($_ENV['APP_ENV'] ?? 'development') === 'production',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Cargar configuración de la aplicación
$settings = require __DIR__ . '/../config/settings.php';

// Crear la aplicación Slim con el builder (PHP-DI integration)
$builder = new \DI\ContainerBuilder();

if (($settings['app']['env'] ?? 'development') === 'production') {
    $builder->enableCompilation(__DIR__ . '/../var/cache');
}

// Registrar definiciones del contenedor
$builder->addDefinitions(require __DIR__ . '/../config/dependencies.php');

$container = $builder->build();

// Crear la app Slim y pasarle el contenedor
$app = \Slim\Factory\AppFactory::createFromContainer($container);

// Base path (útil si la app corre en un subdirectorio de XAMPP)
$basePath = $_ENV['APP_BASE_PATH'] ?? '';
if ($basePath !== '') {
    $app->setBasePath($basePath);
}

// Agregar el parseador de cuerpo para POST forms y JSON
$app->addBodyParsingMiddleware();

// Registrar middlewares globales (orden inverso al de ejecución)
(require __DIR__ . '/../config/middleware.php')($app);

// Registrar rutas
(require __DIR__ . '/../config/routes.php')($app);

// Manejo de errores
$displayErrors = ($settings['app']['env'] ?? 'development') !== 'production';
$errorMiddleware = $app->addErrorMiddleware($displayErrors, true, true);

// Ejecutar la aplicación
$app->run();
