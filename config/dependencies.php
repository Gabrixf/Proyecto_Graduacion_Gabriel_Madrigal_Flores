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

$settings = require __DIR__ . '/settings.php';

return [

    'settings' => $settings,

    LoggerInterface::class => function (ContainerInterface $c): LoggerInterface {
        $cfg    = $c->get('settings')['logger'];
        $level  = Level::fromName(ucfirst($cfg['level']));
        $logger = new Logger($cfg['name']);
        $logger->pushHandler(new StreamHandler($cfg['path'], $level));
        return $logger;
    },

    PDO::class => function (ContainerInterface $c): PDO {
        return Connection::create($c->get('settings')['db']);
    },

    Twig::class => function (ContainerInterface $c): Twig {
        $cfg  = $c->get('settings')['twig'];
        $opts = ['auto_reload' => $cfg['auto_reload']];

        if ($cfg['debug']) {
            $opts['debug'] = true;
        } else {
            $opts['cache'] = $cfg['cache_path'];
        }

        $twig = Twig::create($cfg['template_path'], $opts);
        $env  = $twig->getEnvironment();

        if ($cfg['debug']) {
            $env->addExtension(new \Twig\Extension\DebugExtension());
        }

        $env->addGlobal('session',   $_SESSION ?? []);
        $env->addGlobal('app_name',  $c->get('settings')['app']['name']);

        return $twig;
    },

    // ── Repositories ──────────────────────────────────────
    \App\Repositories\AuditoriaRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\AuditoriaRepository(
            $c->get(PDO::class),
            $c->get(LoggerInterface::class)
        );
    },

    \App\Repositories\UsuariosRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\UsuariosRepository($c->get(PDO::class));
    },

    \App\Repositories\PuestosRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\PuestosRepository($c->get(PDO::class));
    },

    \App\Repositories\PeriodosRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\PeriodosRepository($c->get(PDO::class));
    },

    \App\Repositories\FeriadosRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\FeriadosRepository($c->get(PDO::class));
    },

    // ── Services ──────────────────────────────────────────
    \App\Services\AuthService::class => function (ContainerInterface $c) {
        return new \App\Services\AuthService(
            $c->get(\App\Repositories\UsuariosRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },

    \App\Services\UsuariosService::class => function (ContainerInterface $c) {
        return new \App\Services\UsuariosService(
            $c->get(\App\Repositories\UsuariosRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },

    \App\Services\PuestosService::class => function (ContainerInterface $c) {
        return new \App\Services\PuestosService(
            $c->get(\App\Repositories\PuestosRepository::class)
        );
    },

    \App\Services\PeriodosService::class => function (ContainerInterface $c) {
        return new \App\Services\PeriodosService(
            $c->get(\App\Repositories\PeriodosRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },

    \App\Services\FeriadosService::class => function (ContainerInterface $c) {
        return new \App\Services\FeriadosService(
            $c->get(\App\Repositories\FeriadosRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },

    // ── Controllers ───────────────────────────────────────
    \App\Controllers\AuthController::class => function (ContainerInterface $c) {
        return new \App\Controllers\AuthController(
            $c->get(Twig::class),
            $c->get(\App\Services\AuthService::class)
        );
    },

    \App\Controllers\DashboardController::class => function (ContainerInterface $c) {
        return new \App\Controllers\DashboardController($c->get(Twig::class));
    },

    \App\Controllers\PuestosController::class => function (ContainerInterface $c) {
        return new \App\Controllers\PuestosController(
            $c->get(Twig::class),
            $c->get(\App\Services\PuestosService::class)
        );
    },

    \App\Controllers\UsuariosController::class => function (ContainerInterface $c) {
        return new \App\Controllers\UsuariosController(
            $c->get(Twig::class),
            $c->get(\App\Services\UsuariosService::class)
        );
    },

    \App\Controllers\PeriodosController::class => function (ContainerInterface $c) {
        return new \App\Controllers\PeriodosController(
            $c->get(Twig::class),
            $c->get(\App\Services\PeriodosService::class)
        );
    },

    \App\Controllers\FeriadosController::class => function (ContainerInterface $c) {
        return new \App\Controllers\FeriadosController(
            $c->get(Twig::class),
            $c->get(\App\Services\FeriadosService::class)
        );
    },

];
