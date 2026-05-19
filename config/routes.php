<?php

declare(strict_types=1);

use App\Controllers\PuestosController;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

// ──────────────────────────────────────────────────────────
// Definición de rutas
// Patrón: GET lista / GET crear / POST crear / GET editar / POST editar / POST eliminar
// Todos los grupos protegidos requieren AuthMiddleware
// Los grupos de sólo admin agregan RoleMiddleware('admin')
// ──────────────────────────────────────────────────────────

return function (App $app): void {

    // ── Ruta raíz ─────────────────────────────────────────
    $app->get('/', function ($request, $response) {
        return $response
            ->withHeader('Location', '/login')
            ->withStatus(302);
    });

    // ── Autenticación (sin protección) ────────────────────
    $app->get('/login',  [\App\Controllers\AuthController::class, 'showLogin'])->setName('auth.login');
    $app->post('/login', [\App\Controllers\AuthController::class, 'login']);
    $app->get('/logout', [\App\Controllers\AuthController::class, 'logout'])->setName('auth.logout');

    // ── Dashboard (cualquier rol autenticado) ─────────────
    $app->get('/dashboard', [\App\Controllers\DashboardController::class, 'index'])
        ->setName('dashboard')
        ->add(new AuthMiddleware());

    // ── Grupo: Mantenimientos (solo admin) ────────────────
    $app->group('/mantenimientos', function (RouteCollectorProxy $group) {

        // Puestos
        $group->get('/puestos',                [PuestosController::class, 'index'])->setName('puestos.index');
        $group->get('/puestos/crear',          [PuestosController::class, 'create'])->setName('puestos.create');
        $group->post('/puestos/crear',         [PuestosController::class, 'store'])->setName('puestos.store');
        $group->get('/puestos/{id}/editar',    [PuestosController::class, 'edit'])->setName('puestos.edit');
        $group->post('/puestos/{id}/editar',   [PuestosController::class, 'update'])->setName('puestos.update');
        $group->post('/puestos/{id}/eliminar', [PuestosController::class, 'destroy'])->setName('puestos.destroy');

        // (Aquí se agregan periodos_pago, feriados, usuarios — mismo patrón)

    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());

    // ── Grupo: Empleados (solo admin) ────────────────────
    $app->group('/empleados', function (RouteCollectorProxy $group) {
        // Se implementa en el módulo de Empleados
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());

    // ── Grupo: Nóminas (solo admin) ───────────────────────
    $app->group('/nominas', function (RouteCollectorProxy $group) {
        // Se implementa en el módulo de Nóminas
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());

    // ── Grupo: Portal colaborador (rol empleado) ──────────
    $app->group('/mi-perfil', function (RouteCollectorProxy $group) {
        // Consultas propias del empleado — se implementa por módulo
    })->add(new AuthMiddleware());

};
