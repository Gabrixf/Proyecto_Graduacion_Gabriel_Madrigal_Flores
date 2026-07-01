<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\IncapacidadesService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class IncapacidadesController
{
    public function __construct(
        private readonly Twig                 $twig,
        private readonly IncapacidadesService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params     = $request->getQueryParams();
        $idPeriodo  = isset($params['periodo'])  && is_numeric($params['periodo'])  ? (int)$params['periodo']  : null;
        $idEmpleado = isset($params['empleado']) && is_numeric($params['empleado']) ? (int)$params['empleado'] : null;
        $tipo       = in_array($params['tipo'] ?? '', ['CCSS', 'INS', 'particular'], true) ? $params['tipo'] : null;
        $q          = trim($params['q'] ?? '');
        $filtros    = $this->service->datosFiltros();

        return $this->twig->render($response, 'incapacidades/index.html.twig', [
            'titulo'         => 'Incapacidades',
            'incapacidades'  => $this->service->listar($idPeriodo, $idEmpleado, $tipo, $q !== '' ? $q : null),
            'empleados'      => $filtros['empleados'],
            'periodos'       => $filtros['periodos'],
            'filtroPeriodo'  => $idPeriodo,
            'filtroEmpleado' => $idEmpleado,
            'filtroTipo'     => $tipo,
            'q'              => $q,
            'flashSuccess'   => $this->consumeFlash('flash_success'),
            'flashError'     => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $datosForm = $this->service->datosFormulario();
        return $this->twig->render($response, 'incapacidades/form.html.twig', [
            'titulo'       => 'Registrar Incapacidad',
            'accion'       => 'crear',
            'incapacidad'  => [],
            'empleados'    => $datosForm['empleados'],
            'errores'      => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $datos      = (array)$request->getParsedBody();
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Incapacidad registrada exitosamente.';
            return $response->withHeader('Location', $this->urlFor($request, 'incapacidades.index'))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $datosForm = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'incapacidades/form.html.twig', [
                'titulo'      => 'Registrar Incapacidad',
                'accion'      => 'crear',
                'incapacidad' => $datos,
                'empleados'   => $datosForm['empleados'],
                'errores'     => [$e->getMessage()],
            ]);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            $incapacidad = $this->service->obtener((int)$args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Incapacidad no encontrada.';
            return $response->withHeader('Location', $this->urlFor($request, 'incapacidades.index'))->withStatus(302);
        }
        $datosForm = $this->service->datosFormulario();
        return $this->twig->render($response, 'incapacidades/form.html.twig', [
            'titulo'      => 'Editar Incapacidad',
            'accion'      => 'editar',
            'incapacidad' => $incapacidad,
            'empleados'   => $datosForm['empleados'],
            'errores'     => [],
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
            $_SESSION['flash_success'] = 'Incapacidad actualizada correctamente.';
            return $response->withHeader('Location', $this->urlFor($request, 'incapacidades.index'))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $datosForm = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'incapacidades/form.html.twig', [
                'titulo'      => 'Editar Incapacidad',
                'accion'      => 'editar',
                'incapacidad' => array_merge(['id_incapacidad' => $id], $datos),
                'empleados'   => $datosForm['empleados'],
                'errores'     => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response->withHeader('Location', $this->urlFor($request, 'incapacidades.index'))->withStatus(302);
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->eliminar((int)$args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Incapacidad eliminada.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $response->withHeader('Location', $this->urlFor($request, 'incapacidades.index'))->withStatus(302);
    }

    private function urlFor(Request $request, string $routeName): string
    {
        return RouteContext::fromRequest($request)->getRouteParser()->urlFor($routeName);
    }

    private function consumeFlash(string $key): ?string
    {
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }
}
