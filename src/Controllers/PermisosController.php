<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PermisosService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class PermisosController
{
    public function __construct(
        private readonly Twig            $twig,
        private readonly PermisosService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params     = $request->getQueryParams();
        $idPeriodo  = isset($params['periodo'])  && is_numeric($params['periodo'])  ? (int)$params['periodo']  : null;
        $idEmpleado = isset($params['empleado']) && is_numeric($params['empleado']) ? (int)$params['empleado'] : null;
        $q          = trim($params['q'] ?? '');
        $filtros    = $this->service->datosFiltros();

        return $this->twig->render($response, 'permisos/index.html.twig', [
            'titulo'         => 'Permisos',
            'permisos'       => $this->service->listar($idPeriodo, $idEmpleado, $q !== '' ? $q : null),
            'empleados'      => $filtros['empleados'],
            'periodos'       => $filtros['periodos'],
            'filtroPeriodo'  => $idPeriodo,
            'filtroEmpleado' => $idEmpleado,
            'q'              => $q,
            'flashSuccess'   => $this->consumeFlash('flash_success'),
            'flashError'     => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $datosForm = $this->service->datosFormulario();
        return $this->twig->render($response, 'permisos/form.html.twig', [
            'titulo'      => 'Registrar Permiso',
            'accion'      => 'crear',
            'permiso'     => [],
            'solicitudes' => $datosForm['solicitudes'],
            'errores'     => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $datos      = (array)$request->getParsedBody();
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Permiso registrado exitosamente.';
            return $this->redirect($request, $response, 'permisos.index');
        } catch (InvalidArgumentException $e) {
            $datosForm = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'permisos/form.html.twig', [
                'titulo'      => 'Registrar Permiso',
                'accion'      => 'crear',
                'permiso'     => $datos,
                'solicitudes' => $datosForm['solicitudes'],
                'errores'     => [$e->getMessage()],
            ]);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            $permiso = $this->service->obtener((int)$args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Permiso no encontrado.';
            return $this->redirect($request, $response, 'permisos.index');
        }
        $datosForm = $this->service->datosFormulario((int)$permiso['id_solicitud']);
        return $this->twig->render($response, 'permisos/form.html.twig', [
            'titulo'      => 'Editar Permiso',
            'accion'      => 'editar',
            'permiso'     => $permiso,
            'solicitudes' => $datosForm['solicitudes'],
            'errores'     => [],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id         = (int)$args['id'];
        $datos      = (array)$request->getParsedBody();
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->actualizar($id, $datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Permiso actualizado correctamente.';
            return $this->redirect($request, $response, 'permisos.index');
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $this->redirect($request, $response, 'permisos.index');
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->eliminar((int)$args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Permiso eliminado.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $this->redirect($request, $response, 'permisos.index');
    }

    private function urlFor(Request $request, string $routeName): string
    {
        return RouteContext::fromRequest($request)->getRouteParser()->urlFor($routeName);
    }

    private function redirect(Request $request, Response $response, string $routeName, array $routeArgs = []): Response
    {
        return $response->withHeader('Location', $this->urlFor($request, $routeName, $routeArgs))->withStatus(302);
    }

    private function consumeFlash(string $key): ?string
    {
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }

    private function usuarioId(Request $request): int
    {
        return (int) $request->getAttribute('usuario')['id'];
    }
}
