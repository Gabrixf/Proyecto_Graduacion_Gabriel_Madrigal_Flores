<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PuestosService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Views\Twig;

/**
 * PuestosController
 *
 * Maneja las peticiones HTTP del módulo Puestos.
 * No contiene lógica de negocio; delega todo al Service.
 *
 * Rutas (definidas en config/routes.php):
 *   GET    /mantenimientos/puestos                → index
 *   GET    /mantenimientos/puestos/crear          → create
 *   POST   /mantenimientos/puestos/crear          → store
 *   GET    /mantenimientos/puestos/{id}/editar    → edit
 *   POST   /mantenimientos/puestos/{id}/editar    → update
 *   POST   /mantenimientos/puestos/{id}/eliminar  → destroy
 */
class PuestosController
{
    public function __construct(
        private readonly Twig           $twig,
        private readonly PuestosService $service
    ) {}

    // ── GET /mantenimientos/puestos ───────────────────────
    public function index(Request $request, Response $response): Response
    {
        $puestos      = $this->service->listar();
        $flashSuccess = $this->consumeFlash('flash_success');
        $flashError   = $this->consumeFlash('flash_error');

        return $this->twig->render($response, 'puestos/index.html.twig', [
            'puestos'       => $puestos,
            'flashSuccess'  => $flashSuccess,
            'flashError'    => $flashError,
            'titulo'        => 'Puestos de Trabajo',
        ]);
    }

    // ── GET /mantenimientos/puestos/crear ─────────────────
    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'puestos/form.html.twig', [
            'titulo'   => 'Crear Puesto',
            'accion'   => 'crear',
            'puesto'   => [],
            'errores'  => [],
        ]);
    }

    // ── POST /mantenimientos/puestos/crear ────────────────
    public function store(Request $request, Response $response): Response
    {
        $datos = (array)$request->getParsedBody();

        try {
            $this->service->crear($datos);
            $_SESSION['flash_success'] = 'Puesto creado exitosamente.';
            return $response
                ->withHeader('Location', '/mantenimientos/puestos')
                ->withStatus(302);
        } catch (InvalidArgumentException $e) {
            // Mostrar errores de validación en el formulario
            return $this->twig->render($response->withStatus(422), 'puestos/form.html.twig', [
                'titulo'  => 'Crear Puesto',
                'accion'  => 'crear',
                'puesto'  => $datos,
                'errores' => [$e->getMessage()],
            ]);
        }
    }

    // ── GET /mantenimientos/puestos/{id}/editar ───────────
    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            $puesto = $this->service->obtener((int)$args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Puesto no encontrado.';
            return $response
                ->withHeader('Location', '/mantenimientos/puestos')
                ->withStatus(302);
        }

        return $this->twig->render($response, 'puestos/form.html.twig', [
            'titulo'  => 'Editar Puesto',
            'accion'  => 'editar',
            'puesto'  => $puesto,
            'errores' => [],
        ]);
    }

    // ── POST /mantenimientos/puestos/{id}/editar ──────────
    public function update(Request $request, Response $response, array $args): Response
    {
        $id    = (int)$args['id'];
        $datos = (array)$request->getParsedBody();

        try {
            $this->service->actualizar($id, $datos);
            $_SESSION['flash_success'] = 'Puesto actualizado correctamente.';
            return $response
                ->withHeader('Location', '/mantenimientos/puestos')
                ->withStatus(302);
        } catch (InvalidArgumentException $e) {
            return $this->twig->render($response->withStatus(422), 'puestos/form.html.twig', [
                'titulo'  => 'Editar Puesto',
                'accion'  => 'editar',
                'puesto'  => array_merge(['id_puesto' => $id], $datos),
                'errores' => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response
                ->withHeader('Location', '/mantenimientos/puestos')
                ->withStatus(302);
        }
    }

    // ── POST /mantenimientos/puestos/{id}/eliminar ────────
    public function destroy(Request $request, Response $response, array $args): Response
    {
        try {
            $this->service->eliminar((int)$args['id']);
            $_SESSION['flash_success'] = 'Puesto eliminado correctamente.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/mantenimientos/puestos')
            ->withStatus(302);
    }

    // ── Helpers ───────────────────────────────────────────

    /**
     * Lee y elimina un mensaje flash de la sesión (patrón POST-Redirect-GET).
     */
    private function consumeFlash(string $key): ?string
    {
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }
}
