<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\NominasService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class NominasController
{
    public function __construct(
        private readonly Twig           $twig,
        private readonly NominasService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params    = $request->getQueryParams();
        $idPeriodo = isset($params['periodo']) && is_numeric($params['periodo']) ? (int) $params['periodo'] : null;

        return $this->twig->render($response, 'nominas/index.html.twig', [
            'titulo'        => 'Nóminas',
            'nominas'       => $this->service->listar($idPeriodo),
            'periodos'      => $this->service->periodos(),
            'filtroPeriodo' => $idPeriodo,
            'flashSuccess'  => $this->consumeFlash('flash_success'),
            'flashError'    => $this->consumeFlash('flash_error'),
        ]);
    }

    public function generate(Request $request, Response $response): Response
    {
        $datos      = (array) $request->getParsedBody();
        $idPeriodo  = isset($datos['id_periodo']) && is_numeric($datos['id_periodo']) ? (int) $datos['id_periodo'] : 0;
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $n = $this->service->generarPeriodo($idPeriodo, $loggedInId, $ip);
            $_SESSION['flash_success'] = $n > 0
                ? "Se generaron {$n} nómina(s) en borrador."
                : 'No había empleados pendientes de nómina en ese periodo.';
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        $url = $this->urlFor($request, 'nominas.index') . ($idPeriodo > 0 ? "?periodo={$idPeriodo}" : '');
        return $response->withHeader('Location', $url)->withStatus(302);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        try {
            $nomina = $this->service->obtener((int) $args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Nómina no encontrada.';
            return $response->withHeader('Location', $this->urlFor($request, 'nominas.index'))->withStatus(302);
        }
        return $this->twig->render($response, 'nominas/detalle.html.twig', [
            'titulo'       => 'Detalle de Nómina',
            'nomina'       => $nomina,
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function addIngreso(Request $request, Response $response, array $args): Response
    {
        return $this->mutarLinea($request, $response, $args, 'ingreso');
    }

    public function addDeduccion(Request $request, Response $response, array $args): Response
    {
        return $this->mutarLinea($request, $response, $args, 'deduccion');
    }

    private function mutarLinea(Request $request, Response $response, array $args, string $tipo): Response
    {
        $id         = (int) $args['id'];
        $datos      = (array) $request->getParsedBody();
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            if ($tipo === 'ingreso') {
                $this->service->agregarIngreso($id, $datos, $loggedInId, $ip);
            } else {
                $this->service->agregarDeduccion($id, $datos, $loggedInId, $ip);
            }
            $_SESSION['flash_success'] = 'Línea agregada.';
        } catch (InvalidArgumentException | RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $response->withHeader('Location', $this->urlFor($request, 'nominas.show', ['id' => $id]))->withStatus(302);
    }

    public function removeLinea(Request $request, Response $response, array $args): Response
    {
        $id         = (int) $args['id'];
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->quitarLinea($id, (string) $args['tipo'], (int) $args['idLinea'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Línea eliminada.';
        } catch (InvalidArgumentException | RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $response->withHeader('Location', $this->urlFor($request, 'nominas.show', ['id' => $id]))->withStatus(302);
    }

    public function aprobar(Request $request, Response $response, array $args): Response
    {
        return $this->cambiarEstado($request, $response, (int) $args['id'], 'aprobar');
    }

    public function pagar(Request $request, Response $response, array $args): Response
    {
        return $this->cambiarEstado($request, $response, (int) $args['id'], 'pagar');
    }

    private function cambiarEstado(Request $request, Response $response, int $id, string $accion): Response
    {
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            if ($accion === 'aprobar') {
                $this->service->aprobar($id, $loggedInId, $ip);
                $_SESSION['flash_success'] = 'Nómina aprobada.';
            } else {
                $this->service->marcarPagado($id, $loggedInId, $ip);
                $_SESSION['flash_success'] = 'Nómina marcada como pagada.';
            }
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $response->withHeader('Location', $this->urlFor($request, 'nominas.show', ['id' => $id]))->withStatus(302);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = $this->usuarioId($request);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->eliminar((int) $args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Nómina eliminada.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $response->withHeader('Location', $this->urlFor($request, 'nominas.index'))->withStatus(302);
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

    private function usuarioId(Request $request): int
    {
        return (int) $request->getAttribute('usuario')['id'];
    }
}
