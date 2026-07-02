<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\VacacionesService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class VacacionesController
{
    public function __construct(
        private readonly Twig              $twig,
        private readonly VacacionesService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params     = $request->getQueryParams();
        $idPeriodo  = isset($params['periodo'])  && is_numeric($params['periodo'])  ? (int)$params['periodo']  : null;
        $idEmpleado = isset($params['empleado']) && is_numeric($params['empleado']) ? (int)$params['empleado'] : null;
        $q          = trim($params['q'] ?? '');
        $filtros    = $this->service->datosFiltros();

        return $this->twig->render($response, 'vacaciones/index.html.twig', [
            'titulo'         => 'Vacaciones',
            'vacaciones'     => $this->service->listar($idPeriodo, $idEmpleado, $q !== '' ? $q : null),
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
        return $this->twig->render($response, 'vacaciones/form.html.twig', [
            'titulo'      => 'Registrar Vacaciones',
            'accion'      => 'crear',
            'vacacion'    => [],
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
            $id = $this->service->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Vacaciones registradas exitosamente.' . $this->avisoSaldo($id);
            return $response->withHeader('Location', $this->urlFor($request, 'vacaciones.index'))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $datosForm = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'vacaciones/form.html.twig', [
                'titulo'      => 'Registrar Vacaciones',
                'accion'      => 'crear',
                'vacacion'    => $datos,
                'solicitudes' => $datosForm['solicitudes'],
                'errores'     => [$e->getMessage()],
            ]);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            $vacacion = $this->service->obtener((int)$args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Registro de vacaciones no encontrado.';
            return $response->withHeader('Location', $this->urlFor($request, 'vacaciones.index'))->withStatus(302);
        }
        $datosForm = $this->service->datosFormulario((int)$vacacion['id_solicitud']);
        return $this->twig->render($response, 'vacaciones/form.html.twig', [
            'titulo'      => 'Editar Vacaciones',
            'accion'      => 'editar',
            'vacacion'    => $vacacion,
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
            $_SESSION['flash_success'] = 'Vacaciones actualizadas correctamente.' . $this->avisoSaldo($id);
            return $response->withHeader('Location', $this->urlFor($request, 'vacaciones.index'))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            try {
                $vacacion = $this->service->obtener($id);
            } catch (RuntimeException) {
                $_SESSION['flash_error'] = 'Registro de vacaciones no encontrado.';
                return $response->withHeader('Location', $this->urlFor($request, 'vacaciones.index'))->withStatus(302);
            }
            return $this->twig->render($response->withStatus(422), 'vacaciones/form.html.twig', [
                'titulo'      => 'Editar Vacaciones',
                'accion'      => 'editar',
                'vacacion'    => array_merge($vacacion, $datos),
                'solicitudes' => [],
                'errores'     => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response->withHeader('Location', $this->urlFor($request, 'vacaciones.index'))->withStatus(302);
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->eliminar((int)$args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Registro de vacaciones eliminado.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $response->withHeader('Location', $this->urlFor($request, 'vacaciones.index'))->withStatus(302);
    }

    private function avisoSaldo(int $idVacacion): string
    {
        $saldo = $this->service->saldoDeVacacion($idVacacion);
        if ($saldo === null) {
            return '';
        }
        $disp = (float)$saldo['dias_disponibles'];
        if ($disp < 0) {
            return ' Atención: el saldo ' . $saldo['anio'] . ' quedó en déficit (' . $disp . ' días).';
        }
        return ' Saldo disponible ' . $saldo['anio'] . ': ' . $disp . ' días.';
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

    private function usuarioId(Request $request): int
    {
        return (int) $request->getAttribute('usuario')['id'];
    }
}
