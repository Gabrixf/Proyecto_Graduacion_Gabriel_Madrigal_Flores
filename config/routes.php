<?php

declare(strict_types=1);

use App\Controllers\FeriadosController;
use App\Controllers\PeriodosController;
use App\Controllers\PuestosController;
use App\Controllers\UsuariosController;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {

    $app->get('/', function ($request, $response) {
        return $response->withHeader('Location', '/login')->withStatus(302);
    });

    // ── Auth (sin protección) ─────────────────────────────
    $app->get('/login',  [\App\Controllers\AuthController::class, 'showLogin'])->setName('auth.login');
    $app->post('/login', [\App\Controllers\AuthController::class, 'login']);
    $app->get('/logout', [\App\Controllers\AuthController::class, 'logout'])->setName('auth.logout');

    // ── Dashboard ─────────────────────────────────────────
    $app->get('/dashboard', [\App\Controllers\DashboardController::class, 'index'])
        ->setName('dashboard')
        ->add(new AuthMiddleware());

    // ── Mantenimientos (solo admin) ───────────────────────
    $app->group('/mantenimientos', function (RouteCollectorProxy $group) {

        // Puestos
        $group->get('/puestos',                [PuestosController::class, 'index'])->setName('puestos.index');
        $group->get('/puestos/crear',          [PuestosController::class, 'create'])->setName('puestos.create');
        $group->post('/puestos/crear',         [PuestosController::class, 'store'])->setName('puestos.store');
        $group->get('/puestos/{id}/editar',    [PuestosController::class, 'edit'])->setName('puestos.edit');
        $group->post('/puestos/{id}/editar',   [PuestosController::class, 'update'])->setName('puestos.update');
        $group->post('/puestos/{id}/eliminar', [PuestosController::class, 'destroy'])->setName('puestos.destroy');

        // Períodos de Pago
        $group->get('/periodos',                [PeriodosController::class, 'index'])->setName('periodos.index');
        $group->get('/periodos/crear',          [PeriodosController::class, 'create'])->setName('periodos.create');
        $group->post('/periodos/crear',         [PeriodosController::class, 'store'])->setName('periodos.store');
        $group->get('/periodos/{id}/editar',    [PeriodosController::class, 'edit'])->setName('periodos.edit');
        $group->post('/periodos/{id}/editar',   [PeriodosController::class, 'update'])->setName('periodos.update');
        $group->post('/periodos/{id}/eliminar', [PeriodosController::class, 'destroy'])->setName('periodos.destroy');

        // Feriados
        $group->get('/feriados',                [FeriadosController::class, 'index'])->setName('feriados.index');
        $group->get('/feriados/crear',          [FeriadosController::class, 'create'])->setName('feriados.create');
        $group->post('/feriados/crear',         [FeriadosController::class, 'store'])->setName('feriados.store');
        $group->get('/feriados/{id}/editar',    [FeriadosController::class, 'edit'])->setName('feriados.edit');
        $group->post('/feriados/{id}/editar',   [FeriadosController::class, 'update'])->setName('feriados.update');
        $group->post('/feriados/{id}/eliminar', [FeriadosController::class, 'destroy'])->setName('feriados.destroy');

        // Usuarios
        $group->get('/usuarios',                  [UsuariosController::class, 'index'])->setName('usuarios.index');
        $group->get('/usuarios/crear',            [UsuariosController::class, 'create'])->setName('usuarios.create');
        $group->post('/usuarios/crear',           [UsuariosController::class, 'store'])->setName('usuarios.store');
        $group->get('/usuarios/{id}/editar',      [UsuariosController::class, 'edit'])->setName('usuarios.edit');
        $group->post('/usuarios/{id}/editar',     [UsuariosController::class, 'update'])->setName('usuarios.update');
        $group->post('/usuarios/{id}/eliminar',   [UsuariosController::class, 'destroy'])->setName('usuarios.destroy');
        $group->get('/usuarios/{id}/password',    [UsuariosController::class, 'showPasswordReset'])->setName('usuarios.password');
        $group->post('/usuarios/{id}/password',   [UsuariosController::class, 'updatePassword'])->setName('usuarios.updatePassword');

    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());

    // ── Empleados (solo admin) — pendiente ────────────────
    $app->group('/empleados', function (RouteCollectorProxy $group) {
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());

    // ── Nóminas (solo admin) — pendiente ─────────────────
    $app->group('/nominas', function (RouteCollectorProxy $group) {
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());

    // ── Portal colaborador — pendiente ────────────────────
    $app->group('/mi-perfil', function (RouteCollectorProxy $group) {
    })->add(new AuthMiddleware());

};
