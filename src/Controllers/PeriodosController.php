<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PeriodosService;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class PeriodosController
{
    public function __construct(
        private readonly Twig            $twig,
        private readonly PeriodosService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $hoy        = new DateTimeImmutable('today');
        $generables = [];
        foreach ($this->service->aniosGenerables($hoy) as $anio) {
            $generables[$anio] = $this->service->faltantes($anio, $hoy);
        }

        return $this->twig->render($response, 'periodos/index.html.twig', [
            'titulo'       => 'Períodos de Pago',
            'periodos'     => $this->service->listar(),
            'generables'   => $generables,
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'periodos/form.html.twig', [
            'titulo'   => 'Nuevo Período de Pago',
            'accion'   => 'crear',
            'periodo'  => $this->service->sugerirSiguiente(),
            'errores'  => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $datos      = (array)$request->getParsedBody();
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Período creado exitosamente.';
            return $this->redirect($request, $response, 'periodos.index');
        } catch (InvalidArgumentException $e) {
            return $this->twig->render($response->withStatus(422), 'periodos/form.html.twig', [
                'titulo'  => 'Nuevo Período de Pago',
                'accion'  => 'crear',
                'periodo' => $datos,
                'errores' => [$e->getMessage()],
            ]);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            $periodo = $this->service->obtener((int)$args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Período no encontrado.';
            return $this->redirect($request, $response, 'periodos.index');
        }

        return $this->twig->render($response, 'periodos/form.html.twig', [
            'titulo'  => 'Editar Período de Pago',
            'accion'  => 'editar',
            'periodo' => $periodo,
            'errores' => [],
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
            $_SESSION['flash_success'] = 'Período actualizado correctamente.';
            return $this->redirect($request, $response, 'periodos.index');
        } catch (InvalidArgumentException $e) {
            return $this->twig->render($response->withStatus(422), 'periodos/form.html.twig', [
                'titulo'  => 'Editar Período de Pago',
                'accion'  => 'editar',
                'periodo' => array_merge(['id_periodo' => $id], $datos),
                'errores' => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $this->redirect($request, $response, 'periodos.index');
        }
    }

    /** POST /periodos/generar — crea de una vez las quincenas que faltan del año. */
    public function generar(Request $request, Response $response): Response
    {
        $datos      = (array)$request->getParsedBody();
        $anio       = isset($datos['anio']) && is_numeric($datos['anio']) ? (int)$datos['anio'] : (int)date('Y');
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $n = $this->service->generarAnio($anio, new DateTimeImmutable('today'), $loggedInId, $ip);
            $_SESSION['flash_success'] = "Se crearon {$n} período(s) de {$anio}.";
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $this->redirect($request, $response, 'periodos.index');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->eliminar((int)$args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Período eliminado correctamente.';
        } catch (InvalidArgumentException|RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $this->redirect($request, $response, 'periodos.index');
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
