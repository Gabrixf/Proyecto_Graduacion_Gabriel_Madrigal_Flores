<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\SolicitudesService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class SolicitudesController
{
    public function __construct(
        private readonly Twig               $twig,
        private readonly SolicitudesService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $tipo   = in_array($params['tipo']   ?? '', ['horas_extra', 'vacaciones', 'permiso'], true) ? $params['tipo']   : null;
        $estado = in_array($params['estado'] ?? '', ['pendiente', 'aprobada', 'rechazada'], true)    ? $params['estado'] : null;
        $q      = trim($params['q'] ?? '');

        return $this->twig->render($response, 'solicitudes/index.html.twig', [
            'titulo'       => 'Solicitudes',
            'solicitudes'  => $this->service->listar($tipo, $estado, $q !== '' ? $q : null),
            'filtroTipo'   => $tipo,
            'filtroEstado' => $estado,
            'q'            => $q,
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $datosForm = $this->service->datosFormulario();
        return $this->twig->render($response, 'solicitudes/form.html.twig', [
            'titulo'    => 'Nueva Solicitud',
            'accion'    => 'crear',
            'solicitud' => [],
            'empleados' => $datosForm['empleados'],
            'errores'   => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $datos      = (array)$request->getParsedBody();
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Solicitud registrada exitosamente.';
            return $response->withHeader('Location', $this->urlFor($request, 'solicitudes.index'))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $datosForm = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'solicitudes/form.html.twig', [
                'titulo'    => 'Nueva Solicitud',
                'accion'    => 'crear',
                'solicitud' => $datos,
                'empleados' => $datosForm['empleados'],
                'errores'   => [$e->getMessage()],
            ]);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            $solicitud = $this->service->obtenerEditable((int)$args['id']);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response->withHeader('Location', $this->urlFor($request, 'solicitudes.index'))->withStatus(302);
        }
        $datosForm = $this->service->datosFormulario();
        return $this->twig->render($response, 'solicitudes/form.html.twig', [
            'titulo'    => 'Editar Solicitud',
            'accion'    => 'editar',
            'solicitud' => $solicitud,
            'empleados' => $datosForm['empleados'],
            'errores'   => [],
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
            $_SESSION['flash_success'] = 'Solicitud actualizada correctamente.';
            return $response->withHeader('Location', $this->urlFor($request, 'solicitudes.index'))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $datosForm = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'solicitudes/form.html.twig', [
                'titulo'    => 'Editar Solicitud',
                'accion'    => 'editar',
                'solicitud' => array_merge(['id_solicitud' => $id], $datos),
                'empleados' => $datosForm['empleados'],
                'errores'   => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response->withHeader('Location', $this->urlFor($request, 'solicitudes.index'))->withStatus(302);
        }
    }

    public function aprobar(Request $request, Response $response, array $args): Response
    {
        $this->resolverAccion($request, $args, true);
        return $response->withHeader('Location', $this->urlFor($request, 'solicitudes.index'))->withStatus(302);
    }

    public function rechazar(Request $request, Response $response, array $args): Response
    {
        $this->resolverAccion($request, $args, false);
        return $response->withHeader('Location', $this->urlFor($request, 'solicitudes.index'))->withStatus(302);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->eliminar((int)$args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Solicitud eliminada.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $response->withHeader('Location', $this->urlFor($request, 'solicitudes.index'))->withStatus(302);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function resolverAccion(Request $request, array $args, bool $aprobar): void
    {
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $obs        = (string)(((array)$request->getParsedBody())['observacion'] ?? '');
        try {
            if ($aprobar) {
                $this->service->aprobar((int)$args['id'], $loggedInId, $ip, $obs);
                $_SESSION['flash_success'] = 'Solicitud aprobada.';
            } else {
                $this->service->rechazar((int)$args['id'], $loggedInId, $ip, $obs);
                $_SESSION['flash_success'] = 'Solicitud rechazada.';
            }
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
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
