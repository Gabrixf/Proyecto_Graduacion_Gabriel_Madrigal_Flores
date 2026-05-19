<?php

declare(strict_types=1);

// ──────────────────────────────────────────────────────────
// Configuración central de la aplicación
// Todos los valores sensibles vienen de .env (nunca hardcodeados aquí)
// ──────────────────────────────────────────────────────────

return [

    'app' => [
        'name'     => $_ENV['APP_NAME']     ?? 'Lubrimotos del Sur - Nómina',
        'env'      => $_ENV['APP_ENV']      ?? 'development',
        'basePath' => $_ENV['APP_BASE_PATH'] ?? '',
        'timezone' => $_ENV['APP_TIMEZONE'] ?? 'America/Costa_Rica',
    ],

    'db' => [
        'host'    => $_ENV['DB_HOST']   ?? '127.0.0.1',
        'port'    => $_ENV['DB_PORT']   ?? '3306',
        'dbname'  => $_ENV['DB_NAME']   ?? 'lubrimotos_nomina',
        'user'    => $_ENV['DB_USER']   ?? 'root',
        'pass'    => $_ENV['DB_PASS']   ?? '',
        'charset' => 'utf8mb4',
    ],

    'session' => [
        'name'     => $_ENV['SESSION_NAME']     ?? 'lubrimotos_session',
        'lifetime' => (int)($_ENV['SESSION_LIFETIME'] ?? 3600),
    ],

    'logger' => [
        'name'  => 'lubrimotos',
        'level' => $_ENV['LOG_LEVEL'] ?? 'debug',
        'path'  => dirname(__DIR__) . '/logs/' . ($_ENV['LOG_PATH'] ?? 'app.log'),
    ],

    'twig' => [
        'template_path' => dirname(__DIR__) . '/templates',
        'cache_path'    => dirname(__DIR__) . '/var/cache/twig',
        'auto_reload'   => true,
        'debug'         => ($_ENV['APP_ENV'] ?? 'development') !== 'production',
    ],

    // ── Reglas legales Costa Rica ─────────────────────────
    'nomina' => [
        'ccss_obrera'    => 0.1067,   // 10.67 % cuota obrera
        'ccss_patronal'  => 0.2633,   // 26.33 % cuota patronal
        'factor_he_ord'  => 1.50,     // horas extra jornada ordinaria
        'factor_he_feri' => 2.00,     // horas extra día feriado
    ],

];
