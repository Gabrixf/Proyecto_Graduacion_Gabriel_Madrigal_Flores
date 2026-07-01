<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PortalService;
use App\Services\SolicitudesService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class PortalController
{
    public function __construct(
        private readonly Twig               $twig,
        private readonly PortalService       $service,
        private readonly SolicitudesService  $solicitudesService
    ) {}

    public function perfil(Request $request, Response $response): Response
    {
        $datos = $this->service->perfil((int) $_SESSION['usuario_id']);
        return $this->twig->render($response, 'portal/mi_perfil.html.twig', [
            'titulo' => 'Mi Perfil',
        ] + $datos);
    }

    public function colillas(Request $request, Response $response): Response
    {
        $datos = $this->service->colillas((int) $_SESSION['usuario_id']);
        return $this->twig->render($response, 'portal/mis_colillas.html.twig', [
            'titulo'     => 'Mis Colillas',
            'flashError' => $this->consumeFlash('flash_error'),
        ] + $datos);
    }

    public function colilla(Request $request, Response $response, array $args): Response
    {
        try {
            $colilla = $this->service->colilla((int) $args['id'], (int) $_SESSION['usuario_id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Colilla no encontrada.';
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('portal.colillas');
            return $response->withHeader('Location', $url)->withStatus(302);
        }
        return $this->twig->render($response, 'portal/colilla.html.twig', [
            'titulo'  => 'Colilla de Pago',
            'colilla' => $colilla,
        ]);
    }

    public function vacaciones(Request $request, Response $response): Response
    {
        $datos = $this->service->vacaciones((int) $_SESSION['usuario_id']);
        return $this->twig->render($response, 'portal/mis_vacaciones.html.twig', [
            'titulo' => 'Mis Vacaciones',
        ] + $datos);
    }

    public function showChangePassword(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'portal/cambiar_contrasena.html.twig', [
            'titulo'       => 'Cambiar Contraseña',
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function changePassword(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        try {
            $this->service->changePassword(
                (int) $_SESSION['usuario_id'],
                $data['actual']    ?? '',
                $data['nueva']     ?? '',
                $data['confirmar'] ?? ''
            );
            $_SESSION['flash_success'] = 'Contraseña actualizada correctamente.';
        } catch (\InvalidArgumentException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('portal.changePassword');
        return $response->withHeader('Location', $url)->withStatus(302);
    }

    public function asistencia(Request $request, Response $response): Response
    {
        $idPeriodo = ($request->getQueryParams()['periodo'] ?? '') !== ''
            ? (int) $request->getQueryParams()['periodo']
            : null;

        $datos = $this->service->asistencia((int) $_SESSION['usuario_id'], $idPeriodo);
        return $this->twig->render($response, 'portal/mi_asistencia.html.twig', [
            'titulo' => 'Mi Asistencia',
        ] + $datos);
    }

    public function solicitudes(Request $request, Response $response): Response
    {
        $idEmpleado = $this->idEmpleadoOFlash($request);
        if ($idEmpleado === null) {
            return $this->twig->render($response, 'portal/mis_solicitudes.html.twig', [
                'titulo'      => 'Mis Solicitudes',
                'vinculado'   => false,
                'solicitudes' => [],
                'flashError'  => $this->consumeFlash('flash_error'),
            ]);
        }

        $estado = ($request->getQueryParams()['estado'] ?? '') !== ''
            ? $request->getQueryParams()['estado']
            : null;

        return $this->twig->render($response, 'portal/mis_solicitudes.html.twig', [
            'titulo'       => 'Mis Solicitudes',
            'vinculado'    => true,
            'solicitudes'  => $this->solicitudesService->listar(null, $estado, null, $idEmpleado),
            'filtroEstado' => $estado,
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function crearSolicitud(Request $request, Response $response): Response
    {
        $idEmpleado = $this->idEmpleadoOFlash($request);
        if ($idEmpleado === null) {
            return $this->redirectToMisSolicitudes($request, $response);
        }

        return $this->twig->render($response, 'portal/solicitud_form.html.twig', [
            'titulo'    => 'Nueva Solicitud',
            'accion'    => 'crear',
            'solicitud' => [],
            'errores'   => [],
        ]);
    }

    public function guardarSolicitud(Request $request, Response $response): Response
    {
        $idEmpleado = $this->idEmpleadoOFlash($request);
        if ($idEmpleado === null) {
            return $this->redirectToMisSolicitudes($request, $response);
        }

        $datos = (array) $request->getParsedBody();
        $datos['id_empleado'] = $idEmpleado; // nunca confiar en lo que venga del formulario
        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->solicitudesService->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Solicitud registrada exitosamente.';
            return $this->redirectToMisSolicitudes($request, $response);
        } catch (InvalidArgumentException $e) {
            return $this->twig->render($response->withStatus(422), 'portal/solicitud_form.html.twig', [
                'titulo'    => 'Nueva Solicitud',
                'accion'    => 'crear',
                'solicitud' => $datos,
                'errores'   => [$e->getMessage()],
            ]);
        }
    }

    public function editarSolicitud(Request $request, Response $response, array $args): Response
    {
        $idEmpleado = $this->idEmpleadoOFlash($request);
        if ($idEmpleado === null) {
            return $this->redirectToMisSolicitudes($request, $response);
        }

        try {
            $solicitud = $this->solicitudesService->obtener((int) $args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Solicitud no encontrada.';
            return $this->redirectToMisSolicitudes($request, $response);
        }

        if ((int) $solicitud['id_empleado'] !== $idEmpleado || ($solicitud['estado'] ?? '') !== 'pendiente') {
            $_SESSION['flash_error'] = 'No se puede editar esta solicitud.';
            return $this->redirectToMisSolicitudes($request, $response);
        }

        return $this->twig->render($response, 'portal/solicitud_form.html.twig', [
            'titulo'    => 'Editar Solicitud',
            'accion'    => 'editar',
            'solicitud' => $solicitud,
            'errores'   => [],
        ]);
    }

    public function actualizarSolicitud(Request $request, Response $response, array $args): Response
    {
        $idEmpleado = $this->idEmpleadoOFlash($request);
        if ($idEmpleado === null) {
            return $this->redirectToMisSolicitudes($request, $response);
        }

        $id         = (int) $args['id'];
        $datos      = (array) $request->getParsedBody();
        $datos['id_empleado'] = $idEmpleado;
        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->solicitudesService->actualizar($id, $datos, $loggedInId, $ip, $idEmpleado);
            $_SESSION['flash_success'] = 'Solicitud actualizada correctamente.';
            return $this->redirectToMisSolicitudes($request, $response);
        } catch (InvalidArgumentException $e) {
            return $this->twig->render($response->withStatus(422), 'portal/solicitud_form.html.twig', [
                'titulo'    => 'Editar Solicitud',
                'accion'    => 'editar',
                'solicitud' => array_merge(['id_solicitud' => $id], $datos),
                'errores'   => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $this->redirectToMisSolicitudes($request, $response);
        }
    }

    public function eliminarSolicitud(Request $request, Response $response, array $args): Response
    {
        $idEmpleado = $this->idEmpleadoOFlash($request);
        if ($idEmpleado === null) {
            return $this->redirectToMisSolicitudes($request, $response);
        }

        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->solicitudesService->eliminar((int) $args['id'], $loggedInId, $ip, $idEmpleado);
            $_SESSION['flash_success'] = 'Solicitud cancelada.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $this->redirectToMisSolicitudes($request, $response);
    }

    /**
     * Resuelve el id_empleado del usuario autenticado. Si no hay empleado
     * vinculado, deja un flash_error y devuelve null para que el método
     * que llama redirija en vez de continuar.
     */
    private function idEmpleadoOFlash(Request $request): ?int
    {
        $idEmpleado = $this->service->idEmpleado((int) $_SESSION['usuario_id']);
        if ($idEmpleado === null) {
            $_SESSION['flash_error'] = 'Su usuario no está vinculado a un empleado.';
        }
        return $idEmpleado;
    }

    private function redirectToMisSolicitudes(Request $request, Response $response): Response
    {
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('portal.solicitudes');
        return $response->withHeader('Location', $url)->withStatus(302);
    }

    private function consumeFlash(string $key): ?string
    {
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }
}
