<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\MarcaAsistenciaService;
use App\Services\PortalService;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

/**
 * MiAsistenciaController — el empleado marca su asistencia y ve su calendario del mes.
 * El empleado sale del usuario autenticado, nunca del formulario.
 */
class MiAsistenciaController
{
    private readonly Closure $reloj;

    public function __construct(
        private readonly Twig                   $twig,
        private readonly PortalService          $portal,
        private readonly MarcaAsistenciaService $marcas,
        ?Closure $reloj = null
    ) {
        $this->reloj = $reloj ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public function index(Request $request, Response $response): Response
    {
        $base = [
            'titulo'       => 'Mi Asistencia',
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ];

        $idEmpleado = $this->portal->idEmpleado($this->usuarioId($request));
        if ($idEmpleado === null) {
            return $this->twig->render($response, 'portal/mi_asistencia.html.twig', $base + ['vinculado' => false]);
        }

        $mes = $request->getQueryParams()['mes'] ?? null;
        $vista = $this->marcas->vista($idEmpleado, is_string($mes) ? $mes : null, ($this->reloj)());

        return $this->twig->render($response, 'portal/mi_asistencia.html.twig', $base + ['vinculado' => true] + $vista);
    }

    public function entrada(Request $request, Response $response): Response
    {
        $ahora = ($this->reloj)();
        return $this->ejecutar($request, $response,
            fn (int $idEmpleado) => $this->marcas->marcarEntrada($idEmpleado, $ahora, $this->usuarioId($request), $this->ip()),
            'Entrada registrada a las ' . $ahora->format('H:i') . '.'
        );
    }

    public function salida(Request $request, Response $response): Response
    {
        $ahora = ($this->reloj)();
        return $this->ejecutar($request, $response,
            fn (int $idEmpleado) => $this->marcas->marcarSalida($idEmpleado, $ahora, $this->usuarioId($request), $this->ip()),
            'Salida registrada a las ' . $ahora->format('H:i') . '.'
        );
    }

    public function corregir(Request $request, Response $response, array $args): Response
    {
        $hora = (string)(((array)$request->getParsedBody())['hora_salida'] ?? '');
        return $this->ejecutar($request, $response,
            fn (int $idEmpleado) => $this->marcas->corregirSalida(
                $idEmpleado, (int)$args['id'], $hora, ($this->reloj)(), $this->usuarioId($request), $this->ip()
            ),
            'Salida guardada.'
        );
    }

    /**
     * POST → acción → flash → redirección a Mi asistencia.
     */
    private function ejecutar(Request $request, Response $response, callable $accion, string $exito): Response
    {
        $idEmpleado = $this->portal->idEmpleado($this->usuarioId($request));
        if ($idEmpleado === null) {
            $_SESSION['flash_error'] = 'Su usuario no está vinculado a un empleado.';
        } else {
            try {
                $accion($idEmpleado);
                $_SESSION['flash_success'] = $exito;
            } catch (InvalidArgumentException $e) {
                $_SESSION['flash_error'] = $e->getMessage();
            }
        }

        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('portal.asistencia');
        return $response->withHeader('Location', $url)->withStatus(302);
    }

    private function consumeFlash(string $key): ?string
    {
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }

    private function usuarioId(Request $request): int
    {
        return (int)$request->getAttribute('usuario')['id'];
    }

    private function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
