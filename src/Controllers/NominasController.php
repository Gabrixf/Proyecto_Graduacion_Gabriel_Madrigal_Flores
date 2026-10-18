<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\ExcelExportHelper;
use App\Helpers\PdfExportHelper;
use App\Services\NominasService;
use DateTimeImmutable;
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
        private readonly NominasService $service,
        private readonly ExcelExportHelper $excel,
        private readonly PdfExportHelper $pdf
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params  = $request->getQueryParams();
        $periodo = isset($params['periodo']) && $params['periodo'] !== '' ? (string) $params['periodo'] : null;
        $estado  = isset($params['estado']) && $params['estado'] !== '' ? (string) $params['estado'] : null;

        return $this->twig->render($response, 'nominas/index.html.twig', [
            'titulo'       => 'Nóminas',
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ] + $this->service->vistaListado($periodo, $estado, new DateTimeImmutable('today')));
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

    public function exportar(Request $request, Response $response, array $args): Response
    {
        $formato   = (string)($args['formato'] ?? '');
        $idPeriodo = (int)($request->getQueryParams()['periodo'] ?? 0);
        if (!in_array($formato, ['xlsx', 'pdf'], true) || $idPeriodo <= 0) {
            return $response->withStatus(404);
        }
        try {
            $r = $this->service->reportePeriodo($idPeriodo);
        } catch (RuntimeException) {
            return $response->withStatus(404);
        }
        $titulo = sprintf('Planilla del %s al %s', (new DateTimeImmutable((string)$r['periodo']['fecha_inicio']))->format('d/m/Y'),
            (new DateTimeImmutable((string)$r['periodo']['fecha_fin']))->format('d/m/Y'));
        $archivo = 'planilla_' . $r['periodo']['fecha_inicio'] . '.' . $formato;

        if ($formato === 'xlsx') {
            $enc = ['Empleado', 'Cédula', 'Salario base', 'Horas extra', 'Otros ingresos', 'Subsidio incap.', 'Bruto',
                    'CCSS obrera', 'Renta', 'Otras deducc.', 'Neto', 'CCSS patronal', 'Estado'];
            $filas = array_map(static fn ($f) => [
                $f['apellidos'] . ', ' . $f['nombre'], $f['cedula'], (float)$f['salario_base'], (float)$f['horas_extra'],
                (float)$f['otros_ingresos'], (float)$f['subsidio'], (float)$f['salario_bruto'], (float)$f['ccss_obrera'],
                (float)$f['renta'], (float)$f['otras_deducciones'], (float)$f['salario_neto'], (float)$f['ccss_patronal'], $f['estado'],
            ], $r['filas']);
            $t = $r['totales'];
            $filas[] = ['TOTAL', '', $t['salario_base'], $t['horas_extra'], $t['otros_ingresos'], $t['subsidio'], $t['salario_bruto'],
                        $t['ccss_obrera'], $t['renta'], $t['otras_deducciones'], $t['salario_neto'], $t['ccss_patronal'], ''];
            $filas[] = [];
            $filas[] = ['Total pagado (neto)', '', $t['salario_neto']];
            $filas[] = ['Cargas sociales a pagar a la CCSS', '', $t['cargas_ccss']];
            $filas[] = ['Renta retenida a pagar a Hacienda', '', $t['renta']];
            if ($r['incluye_borrador']) {
                $filas[] = ['Incluye nóminas en borrador'];
            }
            $bytes = $this->excel->toBinaryString($this->excel->build('Planilla ' . $r['periodo']['fecha_inicio'], $enc, $filas));
            $tipo  = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        } else {
            $bytes = $this->pdf->fromHtml($this->twig->fetch('nominas/pdf/periodo.html.twig', ['titulo' => $titulo] + $r));
            $tipo  = 'application/pdf';
        }
        $response->getBody()->write($bytes);
        return $response->withHeader('Content-Type', $tipo)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $archivo . '"')
            ->withHeader('Content-Length', (string)strlen($bytes));
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        try {
            $nomina = $this->service->obtener((int) $args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Nómina no encontrada.';
            return $this->redirect($request, $response, 'nominas.index');
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
        return $this->redirect($request, $response, 'nominas.show', ['id' => $id]);
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
        return $this->redirect($request, $response, 'nominas.show', ['id' => $id]);
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
        return $this->redirect($request, $response, 'nominas.show', ['id' => $id]);
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
        return $this->redirect($request, $response, 'nominas.index');
    }

    public function regenerar(Request $request, Response $response, array $args): Response
    {
        try {
            $nuevo = $this->service->regenerar((int)$args['id'], $this->usuarioId($request), $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            $_SESSION['flash_success'] = 'Nómina recalculada desde cero.';
            return $this->redirect($request, $response, 'nominas.show', ['id' => $nuevo]);
        } catch (RuntimeException | InvalidArgumentException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $this->redirect($request, $response, 'nominas.show', ['id' => (int)$args['id']]);
        }
    }

    private function urlFor(Request $request, string $routeName, array $data = []): string
    {
        return RouteContext::fromRequest($request)->getRouteParser()->urlFor($routeName, $data);
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

    public function aprobarPeriodo(Request $request, Response $response, array $args): Response
    {
        return $this->cambiarPeriodo($request, $response, (int)$args['id'], 'aprobar');
    }

    public function pagarPeriodo(Request $request, Response $response, array $args): Response
    {
        return $this->cambiarPeriodo($request, $response, (int)$args['id'], 'pagar');
    }

    private function cambiarPeriodo(Request $request, Response $response, int $idPeriodo, string $accion): Response
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $n = $accion === 'aprobar'
                ? $this->service->aprobarPeriodo($idPeriodo, $this->usuarioId($request), $ip)
                : $this->service->pagarPeriodo($idPeriodo, $this->usuarioId($request), $ip);
            $_SESSION['flash_success'] = sprintf($accion === 'aprobar' ? 'Se aprobaron %d nóminas.' : 'Se marcaron %d nóminas como pagadas.', $n);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        $url = $this->urlFor($request, 'nominas.index') . '?periodo=' . $idPeriodo;
        return $response->withHeader('Location', $url)->withStatus(302);
    }
}
