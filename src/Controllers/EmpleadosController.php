<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\EmpleadosService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

/**
 * EmpleadosController
 *
 * Solo HTTP: parsea input, llama al Service, renderiza Twig o redirige.
 * No contiene lógica de negocio ni accede a PDO.
 *
 * Rutas:
 *   GET    /empleados                → index
 *   GET    /empleados/crear          → create
 *   POST   /empleados/crear          → store
 *   GET    /empleados/{id}/editar    → edit
 *   POST   /empleados/{id}/editar    → update
 *   POST   /empleados/{id}/eliminar  → destroy (soft-delete)
 */
class EmpleadosController
{
    public function __construct(
        private readonly Twig             $twig,
        private readonly EmpleadosService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $estado = $params['estado'] ?? 'activo';
        $q      = trim($params['q'] ?? '');

        $filtro = $estado === 'todos' ? null : ($estado === 'inactivo' ? 'inactivo' : 'activo');

        return $this->twig->render($response, 'empleados/index.html.twig', [
            'titulo'       => 'Empleados',
            'empleados'    => $this->service->listar($filtro, $q !== '' ? $q : null),
            'estadoActual' => $filtro === null ? 'todos' : $filtro,
            'q'            => $q,
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $datosForm = $this->service->datosFormulario();

        return $this->twig->render($response, 'empleados/form.html.twig', [
            'titulo'   => 'Nuevo Empleado',
            'accion'   => 'crear',
            'empleado' => [],
            'puestos'  => $datosForm['puestos'],
            'usuarios' => $datosForm['usuarios'],
            'errores'  => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $datos      = (array)$request->getParsedBody();
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Empleado registrado exitosamente.';
            return $response->withHeader('Location', $this->urlFor($request, 'empleados.index'))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $datosForm = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'empleados/form.html.twig', [
                'titulo'   => 'Nuevo Empleado',
                'accion'   => 'crear',
                'empleado' => $datos,
                'puestos'  => $datosForm['puestos'],
                'usuarios' => $datosForm['usuarios'],
                'errores'  => [$e->getMessage()],
            ]);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            $empleado = $this->service->obtener((int)$args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Empleado no encontrado.';
            return $response->withHeader('Location', $this->urlFor($request, 'empleados.index'))->withStatus(302);
        }

        $currentUsuarioId = $empleado['id_usuario'] !== null ? (int)$empleado['id_usuario'] : null;
        $datosForm        = $this->service->datosFormulario($currentUsuarioId);

        return $this->twig->render($response, 'empleados/form.html.twig', [
            'titulo'   => 'Editar Empleado',
            'accion'   => 'editar',
            'empleado' => $empleado,
            'puestos'  => $datosForm['puestos'],
            'usuarios' => $datosForm['usuarios'],
            'errores'  => [],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id         = (int)$args['id'];
        $datos      = (array)$request->getParsedBody();
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->actualizar($id, $datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Empleado actualizado correctamente.';
            return $response->withHeader('Location', $this->urlFor($request, 'empleados.index'))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $currentUsuarioId = $this->idUsuarioDeDatos($datos);
            $datosForm        = $this->service->datosFormulario($currentUsuarioId);
            return $this->twig->render($response->withStatus(422), 'empleados/form.html.twig', [
                'titulo'   => 'Editar Empleado',
                'accion'   => 'editar',
                'empleado' => array_merge(['id_empleado' => $id], $datos),
                'puestos'  => $datosForm['puestos'],
                'usuarios' => $datosForm['usuarios'],
                'errores'  => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response->withHeader('Location', $this->urlFor($request, 'empleados.index'))->withStatus(302);
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->eliminar((int)$args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Empleado desactivado correctamente.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response->withHeader('Location', $this->urlFor($request, 'empleados.index'))->withStatus(302);
    }

    // ── Helpers ───────────────────────────────────────────

    /**
     * Genera una URL absoluta para una ruta nombrada, respetando el base path.
     */
    private function urlFor(Request $request, string $routeName): string
    {
        return RouteContext::fromRequest($request)->getRouteParser()->urlFor($routeName);
    }

    /**
     * @param array<string, mixed> $datos
     */
    private function idUsuarioDeDatos(array $datos): ?int
    {
        $valor = $datos['id_usuario'] ?? '';
        return ($valor !== '' && is_numeric($valor)) ? (int)$valor : null;
    }

    private function consumeFlash(string $key): ?string
    {
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }
}
