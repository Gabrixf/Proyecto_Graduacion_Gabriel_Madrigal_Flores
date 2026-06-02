<?php

declare(strict_types=1);

use App\Controllers\AsistenciaController;
use App\Controllers\EmpleadosController;
use App\Controllers\HorasExtraController;
use App\Controllers\SolicitudesController;
use App\Controllers\VacacionesController;
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
        $routeParser = \Slim\Routing\RouteContext::fromRequest($request)->getRouteParser();
        return $response->withHeader('Location', $routeParser->urlFor('auth.login'))->withStatus(302);
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

    // ── Empleados (solo admin) ────────────────────────────
    $app->group('/empleados', function (RouteCollectorProxy $group) {
        $group->get('',                  [EmpleadosController::class, 'index'])->setName('empleados.index');
        $group->get('/crear',            [EmpleadosController::class, 'create'])->setName('empleados.create');
        $group->post('/crear',           [EmpleadosController::class, 'store'])->setName('empleados.store');
        $group->get('/{id}/editar',      [EmpleadosController::class, 'edit'])->setName('empleados.edit');
        $group->post('/{id}/editar',     [EmpleadosController::class, 'update'])->setName('empleados.update');
        $group->post('/{id}/eliminar',   [EmpleadosController::class, 'destroy'])->setName('empleados.destroy');
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());

    // ── Asistencia (solo admin) ───────────────────────────
    $app->group('/asistencia', function (RouteCollectorProxy $group) {
        $group->get('',                  [AsistenciaController::class, 'index'])->setName('asistencia.index');
        $group->get('/crear',            [AsistenciaController::class, 'create'])->setName('asistencia.create');
        $group->post('/crear',           [AsistenciaController::class, 'store'])->setName('asistencia.store');
        $group->get('/{id}/editar',      [AsistenciaController::class, 'edit'])->setName('asistencia.edit');
        $group->post('/{id}/editar',     [AsistenciaController::class, 'update'])->setName('asistencia.update');
        $group->post('/{id}/eliminar',   [AsistenciaController::class, 'destroy'])->setName('asistencia.destroy');
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());

    // ── Solicitudes (solo admin) ──────────────────────────
    $app->group('/solicitudes', function (RouteCollectorProxy $group) {
        $group->get('',                  [SolicitudesController::class, 'index'])->setName('solicitudes.index');
        $group->get('/crear',            [SolicitudesController::class, 'create'])->setName('solicitudes.create');
        $group->post('/crear',           [SolicitudesController::class, 'store'])->setName('solicitudes.store');
        $group->get('/{id}/editar',      [SolicitudesController::class, 'edit'])->setName('solicitudes.edit');
        $group->post('/{id}/editar',     [SolicitudesController::class, 'update'])->setName('solicitudes.update');
        $group->post('/{id}/aprobar',    [SolicitudesController::class, 'aprobar'])->setName('solicitudes.aprobar');
        $group->post('/{id}/rechazar',   [SolicitudesController::class, 'rechazar'])->setName('solicitudes.rechazar');
        $group->post('/{id}/eliminar',   [SolicitudesController::class, 'destroy'])->setName('solicitudes.destroy');
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());

    // ── Horas Extra (solo admin) ──────────────────────────
    $app->group('/horas-extra', function (RouteCollectorProxy $group) {
        $group->get('',                [HorasExtraController::class, 'index'])->setName('horas_extra.index');
        $group->get('/crear',          [HorasExtraController::class, 'create'])->setName('horas_extra.create');
        $group->post('/crear',         [HorasExtraController::class, 'store'])->setName('horas_extra.store');
        $group->get('/{id}/editar',    [HorasExtraController::class, 'edit'])->setName('horas_extra.edit');
        $group->post('/{id}/editar',   [HorasExtraController::class, 'update'])->setName('horas_extra.update');
        $group->post('/{id}/eliminar', [HorasExtraController::class, 'destroy'])->setName('horas_extra.destroy');
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());

    // ── Vacaciones (solo admin) ───────────────────────────
    $app->group('/vacaciones', function (RouteCollectorProxy $group) {
        $group->get('',                [VacacionesController::class, 'index'])->setName('vacaciones.index');
        $group->get('/crear',          [VacacionesController::class, 'create'])->setName('vacaciones.create');
        $group->post('/crear',         [VacacionesController::class, 'store'])->setName('vacaciones.store');
        $group->get('/{id}/editar',    [VacacionesController::class, 'edit'])->setName('vacaciones.edit');
        $group->post('/{id}/editar',   [VacacionesController::class, 'update'])->setName('vacaciones.update');
        $group->post('/{id}/eliminar', [VacacionesController::class, 'destroy'])->setName('vacaciones.destroy');
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());

    // ── Nóminas (solo admin) — pendiente ─────────────────
    $app->group('/nominas', function (RouteCollectorProxy $group) {
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());

    // ── Portal colaborador — pendiente ────────────────────
    $app->group('/mi-perfil', function (RouteCollectorProxy $group) {
    })->add(new AuthMiddleware());

};
