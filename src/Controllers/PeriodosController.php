<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PeriodosService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Views\Twig;

class PeriodosController
{
    public function __construct(
        private readonly Twig            $twig,
        private readonly PeriodosService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'periodos/index.html.twig', [
            'titulo'       => 'Períodos de Pago',
            'periodos'     => $this->service->listar(),
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
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Período creado exitosamente.';
            return $response->withHeader('Location', '/mantenimientos/periodos')->withStatus(302);
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
            return $response->withHeader('Location', '/mantenimientos/periodos')->withStatus(302);
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
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->actualizar($id, $datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Período actualizado correctamente.';
            return $response->withHeader('Location', '/mantenimientos/periodos')->withStatus(302);
        } catch (InvalidArgumentException $e) {
            return $this->twig->render($response->withStatus(422), 'periodos/form.html.twig', [
                'titulo'  => 'Editar Período de Pago',
                'accion'  => 'editar',
                'periodo' => array_merge(['id_periodo' => $id], $datos),
                'errores' => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response->withHeader('Location', '/mantenimientos/periodos')->withStatus(302);
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->eliminar((int)$args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Período eliminado correctamente.';
        } catch (InvalidArgumentException|RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response->withHeader('Location', '/mantenimientos/periodos')->withStatus(302);
    }

    private function consumeFlash(string $key): ?string
    {
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }
}
