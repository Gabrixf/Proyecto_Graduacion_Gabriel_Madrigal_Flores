<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\EvaluacionesService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class EvaluacionesController
{
    public function __construct(
        private readonly Twig                $twig,
        private readonly EvaluacionesService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $q = trim($request->getQueryParams()['q'] ?? '');
        return $this->twig->render($response, 'evaluaciones/index.html.twig', [
            'titulo'       => 'Evaluaciones',
            'evaluaciones' => $this->service->listar($q !== '' ? $q : null),
            'q'            => $q,
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $form = $this->service->datosFormulario();
        return $this->twig->render($response, 'evaluaciones/form.html.twig', [
            'titulo'    => 'Nueva Evaluación',
            'accion'    => $this->urlFor($request, 'evaluaciones.store'),
            'empleados' => $form['empleados'],
            'detalles'  => $this->plantillaVacia($form['criterios']),
            'datos'     => [],
            'errores'   => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $datos      = (array) $request->getParsedBody();
        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $id = $this->service->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Evaluación registrada correctamente.';
            return $response->withHeader('Location', $this->urlFor($request, 'evaluaciones.show', ['id' => $id]))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $form = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'evaluaciones/form.html.twig', [
                'titulo'    => 'Nueva Evaluación',
                'accion'    => $this->urlFor($request, 'evaluaciones.store'),
                'empleados' => $form['empleados'],
                'detalles'  => $this->detallesDesdePost($datos, $form['criterios']),
                'datos'     => $datos,
                'errores'   => [$e->getMessage()],
            ]);
        }
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        try {
            $evaluacion = $this->service->obtener((int) $args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Evaluación no encontrada.';
            return $response->withHeader('Location', $this->urlFor($request, 'evaluaciones.index'))->withStatus(302);
        }
        return $this->twig->render($response, 'evaluaciones/detalle.html.twig', [
            'titulo'       => 'Detalle de Evaluación',
            'evaluacion'   => $evaluacion,
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            $evaluacion = $this->service->obtener((int) $args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Evaluación no encontrada.';
            return $response->withHeader('Location', $this->urlFor($request, 'evaluaciones.index'))->withStatus(302);
        }
        $form = $this->service->datosFormulario();
        return $this->twig->render($response, 'evaluaciones/form.html.twig', [
            'titulo'    => 'Editar Evaluación',
            'accion'    => $this->urlFor($request, 'evaluaciones.update', ['id' => $args['id']]),
            'empleados' => $form['empleados'],
            'detalles'  => $evaluacion['detalles'],
            'datos'     => $evaluacion,
            'errores'   => [],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id         = (int) $args['id'];
        $datos      = (array) $request->getParsedBody();
        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->actualizar($id, $datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Evaluación actualizada correctamente.';
            return $response->withHeader('Location', $this->urlFor($request, 'evaluaciones.show', ['id' => $id]))->withStatus(302);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Evaluación no encontrada.';
            return $response->withHeader('Location', $this->urlFor($request, 'evaluaciones.index'))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $form = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'evaluaciones/form.html.twig', [
                'titulo'    => 'Editar Evaluación',
                'accion'    => $this->urlFor($request, 'evaluaciones.update', ['id' => $id]),
                'empleados' => $form['empleados'],
                'detalles'  => $this->detallesDesdePost($datos, $form['criterios']),
                'datos'     => $datos,
                'errores'   => [$e->getMessage()],
            ]);
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->eliminar((int) $args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Evaluación eliminada.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $response->withHeader('Location', $this->urlFor($request, 'evaluaciones.index'))->withStatus(302);
    }

    /** Vista del empleado autenticado: sus evaluaciones con desglose. */
    public function misEvaluaciones(Request $request, Response $response): Response
    {
        $idUsuario = (int) $_SESSION['usuario_id'];
        return $this->twig->render($response, 'evaluaciones/mis_evaluaciones.html.twig', [
            'titulo'       => 'Mis Evaluaciones',
            'evaluaciones' => $this->service->misEvaluaciones($idUsuario),
        ]);
    }

    /** Filas del formulario nuevo: plantilla con puntajes vacíos. */
    private function plantillaVacia(array $criterios): array
    {
        return array_map(
            static fn(array $c): array => ['criterio' => $c['criterio'], 'peso' => $c['peso'], 'puntaje' => ''],
            $criterios
        );
    }

    /** Reconstruye las filas desde el POST fallido para no perder lo digitado. */
    private function detallesDesdePost(array $datos, array $plantilla): array
    {
        $criterios = array_values((array) ($datos['criterio'] ?? []));
        $pesos     = array_values((array) ($datos['peso'] ?? []));
        $puntajes  = array_values((array) ($datos['puntaje'] ?? []));
        if ($criterios === [] || count($criterios) !== count($pesos)) {
            return $this->plantillaVacia($plantilla);
        }
        $filas = [];
        foreach ($criterios as $i => $criterio) {
            $filas[] = [
                'criterio' => (string) $criterio,
                'peso'     => $pesos[$i],
                'puntaje'  => $puntajes[$i] ?? '',
            ];
        }
        return $filas;
    }

    private function urlFor(Request $request, string $routeName, array $data = []): string
    {
        return RouteContext::fromRequest($request)->getRouteParser()->urlFor($routeName, $data);
    }

    private function consumeFlash(string $key): ?string
    {
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }
}
