<?php

declare(strict_types=1);

use App\Database\Connection;
use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;

// ──────────────────────────────────────────────────────────
// Definiciones del contenedor PHP-DI
// ──────────────────────────────────────────────────────────

$settings = require __DIR__ . '/settings.php';

return [

    // ── Configuración ─────────────────────────────────────
    'settings' => $settings,

    // ── Logger (Monolog) ──────────────────────────────────
    LoggerInterface::class => function (ContainerInterface $c): LoggerInterface {
        $cfg    = $c->get('settings')['logger'];
        $level  = Level::fromName(ucfirst($cfg['level']));
        $logger = new Logger($cfg['name']);
        $logger->pushHandler(new StreamHandler($cfg['path'], $level));
        return $logger;
    },

    // ── Conexión PDO ──────────────────────────────────────
    PDO::class => function (ContainerInterface $c): PDO {
        return Connection::create($c->get('settings')['db']);
    },

    // ── Vista Twig ────────────────────────────────────────
    Twig::class => function (ContainerInterface $c): Twig {
        $cfg  = $c->get('settings')['twig'];
        $opts = ['auto_reload' => $cfg['auto_reload']];

        if ($cfg['debug']) {
            $opts['debug'] = true;
        } else {
            $opts['cache'] = $cfg['cache_path'];
        }

        $twig = Twig::create($cfg['template_path'], $opts);

        // Variables globales accesibles en todas las plantillas
        $env = $twig->getEnvironment();

        if ($cfg['debug']) {
            $env->addExtension(new \Twig\Extension\DebugExtension());
        }

        // Inyectar datos de sesión para que Twig los use en el layout
        $env->addGlobal('session', $_SESSION ?? []);
        $env->addGlobal('app_name', $c->get('settings')['app']['name']);

        return $twig;
    },

    // ── Repositorios ──────────────────────────────────────
    \App\Repositories\PuestosRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\PuestosRepository($c->get(PDO::class));
    },

    // ── Servicios ─────────────────────────────────────────
    \App\Services\PuestosService::class => function (ContainerInterface $c) {
        return new \App\Services\PuestosService(
            $c->get(\App\Repositories\PuestosRepository::class)
        );
    },

    // ── Controladores ─────────────────────────────────────
    \App\Controllers\PuestosController::class => function (ContainerInterface $c) {
        return new \App\Controllers\PuestosController(
            $c->get(Twig::class),
            $c->get(\App\Services\PuestosService::class)
        );
    },

];
