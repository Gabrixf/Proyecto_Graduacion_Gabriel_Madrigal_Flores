<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AguinaldoService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class AguinaldoController
{
    public function __construct(
        private readonly Twig             $twig,
        private readonly AguinaldoService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $anio   = isset($params['anio']) && is_numeric($params['anio'])
            ? (int) $params['anio']
            : (int) date('Y');

        return $this->twig->render($response, 'aguinaldo/index.html.twig', [
            'titulo'       => 'Aguinaldo',
            'anio'         => $anio,
            'aguinaldos'   => $this->service->listar($anio),
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function calcular(Request $request, Response $response): Response
    {
        $datos      = (array) $request->getParsedBody();
        $anio       = isset($datos['anio']) && is_numeric($datos['anio']) ? (int) $datos['anio'] : (int) date('Y');
        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $n = $this->service->calcular($anio, $loggedInId, $ip);
        $_SESSION['flash_success'] = "Aguinaldo calculado para {$n} empleado(s).";
        return $response->withHeader('Location', $this->urlFor($request, 'aguinaldo.index') . "?anio={$anio}")->withStatus(302);
    }

    public function pagar(Request $request, Response $response, array $args): Response
    {
        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->marcarPagado((int) $args['id'], date('Y-m-d'), $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Aguinaldo marcado como pagado.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        $anio = $request->getQueryParams()['anio'] ?? date('Y');
        return $response->withHeader('Location', $this->urlFor($request, 'aguinaldo.index') . "?anio={$anio}")->withStatus(302);
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
