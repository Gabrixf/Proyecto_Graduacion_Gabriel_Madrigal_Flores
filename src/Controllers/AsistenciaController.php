<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AsistenciaService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class AsistenciaController
{
    public function __construct(
        private readonly Twig              $twig,
        private readonly AsistenciaService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params     = $request->getQueryParams();
        $idPeriodo  = isset($params['periodo'])  && is_numeric($params['periodo'])  ? (int)$params['periodo']  : null;
        $idEmpleado = isset($params['empleado']) && is_numeric($params['empleado']) ? (int)$params['empleado'] : null;

        $datosForm = $this->service->datosFormulario();

        return $this->twig->render($response, 'asistencia/index.html.twig', [
            'titulo'         => 'Asistencia',
            'asistencias'    => $this->service->listar($idPeriodo, $idEmpleado),
            'empleados'      => $datosForm['empleados'],
            'periodos'       => $datosForm['periodos'],
            'filtroPeriodo'  => $idPeriodo,
            'filtroEmpleado' => $idEmpleado,
            'flashSuccess'   => $this->consumeFlash('flash_success'),
            'flashError'     => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $datosForm = $this->service->datosFormulario();
        return $this->twig->render($response, 'asistencia/form.html.twig', [
            'titulo'     => 'Registrar Asistencia',
            'accion'     => 'crear',
            'asistencia' => [],
            'empleados'  => $datosForm['empleados'],
            'errores'    => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $datos      = (array)$request->getParsedBody();
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Asistencia registrada exitosamente.';
            return $this->redirect($request, $response, 'asistencia.index');
        } catch (InvalidArgumentException $e) {
            $datosForm = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'asistencia/form.html.twig', [
                'titulo'     => 'Registrar Asistencia',
                'accion'     => 'crear',
                'asistencia' => $datos,
                'empleados'  => $datosForm['empleados'],
                'errores'    => [$e->getMessage()],
            ]);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            $asistencia = $this->service->obtener((int)$args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Registro de asistencia no encontrado.';
            return $this->redirect($request, $response, 'asistencia.index');
        }
        $datosForm = $this->service->datosFormulario();
        return $this->twig->render($response, 'asistencia/form.html.twig', [
            'titulo'     => 'Editar Asistencia',
            'accion'     => 'editar',
            'asistencia' => $asistencia,
            'empleados'  => $datosForm['empleados'],
            'errores'    => [],
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
            $_SESSION['flash_success'] = 'Asistencia actualizada correctamente.';
            return $this->redirect($request, $response, 'asistencia.index');
        } catch (InvalidArgumentException $e) {
            $datosForm = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'asistencia/form.html.twig', [
                'titulo'     => 'Editar Asistencia',
                'accion'     => 'editar',
                'asistencia' => array_merge(['id_asistencia' => $id], $datos),
                'empleados'  => $datosForm['empleados'],
                'errores'    => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $this->redirect($request, $response, 'asistencia.index');
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->eliminar((int)$args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Registro de asistencia eliminado.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $this->redirect($request, $response, 'asistencia.index');
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
