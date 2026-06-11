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

    \App\Repositories\EmpleadosRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\EmpleadosRepository($c->get(PDO::class));
    },

    \App\Repositories\AsistenciaRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\AsistenciaRepository($c->get(PDO::class));
    },

    \App\Repositories\SolicitudesRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\SolicitudesRepository($c->get(PDO::class));
    },

    \App\Repositories\HorasExtraRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\HorasExtraRepository($c->get(PDO::class));
    },

    \App\Repositories\VacacionesRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\VacacionesRepository($c->get(PDO::class));
    },

    \App\Repositories\IncapacidadesRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\IncapacidadesRepository($c->get(PDO::class));
    },

    \App\Repositories\PermisosRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\PermisosRepository($c->get(PDO::class));
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

    \App\Services\EmpleadosService::class => function (ContainerInterface $c) {
        return new \App\Services\EmpleadosService(
            $c->get(\App\Repositories\EmpleadosRepository::class),
            $c->get(\App\Repositories\PuestosRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },

    \App\Services\AsistenciaService::class => function (ContainerInterface $c) {
        return new \App\Services\AsistenciaService(
            $c->get(\App\Repositories\AsistenciaRepository::class),
            $c->get(\App\Repositories\EmpleadosRepository::class),
            $c->get(\App\Repositories\PeriodosRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },

    \App\Services\SolicitudesService::class => function (ContainerInterface $c) {
        return new \App\Services\SolicitudesService(
            $c->get(\App\Repositories\SolicitudesRepository::class),
            $c->get(\App\Repositories\EmpleadosRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },

    \App\Services\HorasExtraService::class => function (ContainerInterface $c) {
        return new \App\Services\HorasExtraService(
            $c->get(\App\Repositories\HorasExtraRepository::class),
            $c->get(\App\Repositories\SolicitudesRepository::class),
            $c->get(\App\Repositories\EmpleadosRepository::class),
            $c->get(\App\Repositories\PeriodosRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },

    \App\Services\VacacionesService::class => function (ContainerInterface $c) {
        return new \App\Services\VacacionesService(
            $c->get(\App\Repositories\VacacionesRepository::class),
            $c->get(\App\Repositories\SolicitudesRepository::class),
            $c->get(\App\Repositories\EmpleadosRepository::class),
            $c->get(\App\Repositories\PeriodosRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },

    \App\Services\IncapacidadesService::class => function (ContainerInterface $c) {
        return new \App\Services\IncapacidadesService(
            $c->get(\App\Repositories\IncapacidadesRepository::class),
            $c->get(\App\Repositories\EmpleadosRepository::class),
            $c->get(\App\Repositories\PeriodosRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },

    \App\Services\PermisosService::class => function (ContainerInterface $c) {
        return new \App\Services\PermisosService(
            $c->get(\App\Repositories\PermisosRepository::class),
            $c->get(\App\Repositories\SolicitudesRepository::class),
            $c->get(\App\Repositories\EmpleadosRepository::class),
            $c->get(\App\Repositories\PeriodosRepository::class),
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

    \App\Controllers\EmpleadosController::class => function (ContainerInterface $c) {
        return new \App\Controllers\EmpleadosController(
            $c->get(Twig::class),
            $c->get(\App\Services\EmpleadosService::class)
        );
    },

    \App\Controllers\AsistenciaController::class => function (ContainerInterface $c) {
        return new \App\Controllers\AsistenciaController(
            $c->get(Twig::class),
            $c->get(\App\Services\AsistenciaService::class)
        );
    },

    \App\Controllers\SolicitudesController::class => function (ContainerInterface $c) {
        return new \App\Controllers\SolicitudesController(
            $c->get(Twig::class),
            $c->get(\App\Services\SolicitudesService::class)
        );
    },

    \App\Controllers\HorasExtraController::class => function (ContainerInterface $c) {
        return new \App\Controllers\HorasExtraController(
            $c->get(Twig::class),
            $c->get(\App\Services\HorasExtraService::class)
        );
    },

    \App\Controllers\VacacionesController::class => function (ContainerInterface $c) {
        return new \App\Controllers\VacacionesController(
            $c->get(Twig::class),
            $c->get(\App\Services\VacacionesService::class)
        );
    },

    \App\Controllers\IncapacidadesController::class => function (ContainerInterface $c) {
        return new \App\Controllers\IncapacidadesController(
            $c->get(Twig::class),
            $c->get(\App\Services\IncapacidadesService::class)
        );
    },

    \App\Controllers\PermisosController::class => function (ContainerInterface $c) {
        return new \App\Controllers\PermisosController(
            $c->get(Twig::class),
            $c->get(\App\Services\PermisosService::class)
        );
    },

    // ── Nóminas ───────────────────────────────────────────
    \App\Helpers\NominaCalculadora::class => function (ContainerInterface $c) {
        $cfg = $c->get('settings')['nomina'];
        return new \App\Helpers\NominaCalculadora(
            (float) $cfg['ccss_obrera'],
            (float) $cfg['ccss_patronal']
        );
    },

    \App\Repositories\NominasRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\NominasRepository($c->get(PDO::class));
    },

    \App\Services\NominasService::class => function (ContainerInterface $c) {
        return new \App\Services\NominasService(
            $c->get(\App\Repositories\NominasRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class),
            $c->get(\App\Helpers\NominaCalculadora::class)
        );
    },

    \App\Controllers\NominasController::class => function (ContainerInterface $c) {
        return new \App\Controllers\NominasController(
            $c->get(Twig::class),
            $c->get(\App\Services\NominasService::class)
        );
    },

    // ── Aguinaldo ─────────────────────────────────────────
    \App\Repositories\AguinaldoRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\AguinaldoRepository($c->get(PDO::class));
    },

    \App\Services\AguinaldoService::class => function (ContainerInterface $c) {
        return new \App\Services\AguinaldoService(
            $c->get(\App\Repositories\AguinaldoRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },

    \App\Controllers\AguinaldoController::class => function (ContainerInterface $c) {
        return new \App\Controllers\AguinaldoController(
            $c->get(Twig::class),
            $c->get(\App\Services\AguinaldoService::class)
        );
    },

    // ── Liquidación ───────────────────────────────────────
    \App\Helpers\LiquidacionCalculadora::class => function (ContainerInterface $c) {
        return new \App\Helpers\LiquidacionCalculadora();
    },

    \App\Repositories\LiquidacionRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\LiquidacionRepository($c->get(PDO::class));
    },

    \App\Services\LiquidacionService::class => function (ContainerInterface $c) {
        return new \App\Services\LiquidacionService(
            $c->get(\App\Repositories\LiquidacionRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class),
            $c->get(\App\Helpers\LiquidacionCalculadora::class)
        );
    },

    \App\Controllers\LiquidacionController::class => function (ContainerInterface $c) {
        return new \App\Controllers\LiquidacionController(
            $c->get(Twig::class),
            $c->get(\App\Services\LiquidacionService::class)
        );
    },

    // ── Evaluaciones ──────────────────────────────────────
    \App\Helpers\EvaluacionCalculadora::class => function (ContainerInterface $c) {
        return new \App\Helpers\EvaluacionCalculadora();
    },

    \App\Repositories\EvaluacionesRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\EvaluacionesRepository($c->get(PDO::class));
    },

    \App\Services\EvaluacionesService::class => function (ContainerInterface $c) {
        return new \App\Services\EvaluacionesService(
            $c->get(\App\Repositories\EvaluacionesRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class),
            $c->get(\App\Helpers\EvaluacionCalculadora::class),
            $c->get('settings')['criterios_evaluacion']
        );
    },

    \App\Controllers\EvaluacionesController::class => function (ContainerInterface $c) {
        return new \App\Controllers\EvaluacionesController(
            $c->get(Twig::class),
            $c->get(\App\Services\EvaluacionesService::class)
        );
    },

];
