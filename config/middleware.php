<?php

declare(strict_types=1);

use App\Middleware\AuthMiddleware;
use Slim\App;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

// ──────────────────────────────────────────────────────────
// Registro de middlewares globales
// Se ejecutan en orden LIFO (último registrado = primero en ejecutarse)
// ──────────────────────────────────────────────────────────

return function (App $app): void {

    // Routing middleware (requerido por Slim 4)
    $app->addRoutingMiddleware();

    // Middleware de Twig para helpers de ruta en plantillas
    $app->add(TwigMiddleware::createFromContainer($app, Twig::class));
};
