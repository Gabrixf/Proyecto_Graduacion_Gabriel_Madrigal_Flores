<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\ExcelExportHelper;
use App\Helpers\PdfExportHelper;
use App\Services\ReportesService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class ReportesController
{
    private const TIPOS_VALIDOS = ['planilla', 'historial', 'costos', 'auditoria'];
    private const FORMATOS_VALIDOS = ['excel', 'pdf'];

    public function __construct(
        private readonly Twig               $twig,
        private readonly ReportesService    $service,
        private readonly ExcelExportHelper  $excel,
        private readonly PdfExportHelper    $pdf
    ) {}

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'reportes/index.html.twig', [
            'titulo' => 'Reportes',
        ]);
    }

    public function planilla(Request $request, Response $response): Response
    {
        $datos = $this->service->planilla($request->getQueryParams());
        return $this->twig->render($response, 'reportes/planilla.html.twig', [
            'titulo' => 'Planilla por Período',
        ] + $datos);
    }

    public function historial(Request $request, Response $response): Response
    {
        $datos = $this->service->historial($request->getQueryParams());
        return $this->twig->render($response, 'reportes/historial.html.twig', [
            'titulo' => 'Historial por Empleado',
        ] + $datos);
    }

    public function costos(Request $request, Response $response): Response
    {
        $datos = $this->service->costos($request->getQueryParams());
        return $this->twig->render($response, 'reportes/costos.html.twig', [
            'titulo' => 'Costos Patronales',
        ] + $datos);
    }

    public function auditoria(Request $request, Response $response): Response
    {
        $datos = $this->service->auditoria($request->getQueryParams());
        return $this->twig->render($response, 'reportes/auditoria.html.twig', [
            'titulo' => 'Bitácora de Auditoría',
        ] + $datos);
    }

    public function exportar(Request $request, Response $response, array $args): Response
    {
        $tipo = $args['tipo'] ?? '';
        $formato = $args['formato'] ?? '';

        if (!in_array($tipo, self::TIPOS_VALIDOS, true) || !in_array($formato, self::FORMATOS_VALIDOS, true)) {
            return $response->withStatus(404);
        }

        $filtros = $request->getQueryParams();
        $datos = match ($tipo) {
            'planilla'  => $this->service->planilla($filtros),
            'historial' => $this->service->historial($filtros),
            'costos'    => $this->service->costos($filtros),
            'auditoria' => $this->service->auditoria($filtros),
        };

        return $formato === 'excel'
            ? $this->exportarExcel($response, $tipo, $datos)
            : $this->exportarPdf($response, $tipo, $datos);
    }

    /** @param array<string, mixed> $datos */
    private function exportarExcel(Response $response, string $tipo, array $datos): Response
    {
        [$titulo, $spreadsheet] = match ($tipo) {
            'planilla'  => [$this->tituloExportable($tipo), $this->hojaPlanilla($datos)],
            'costos'    => [$this->tituloExportable($tipo), $this->hojaCostos($datos)],
            'auditoria' => [$this->tituloExportable($tipo), $this->hojaAuditoria($datos)],
            'historial' => [$this->tituloExportable($tipo), $this->hojasHistorial($datos)],
        };

        $bytes = $this->excel->toBinaryString($spreadsheet);
        $nombreArchivo = $this->nombreArchivo($tipo, 'xlsx');

        $response->getBody()->write($bytes);
        return $response
            ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $nombreArchivo . '"')
            ->withHeader('Content-Length', (string) strlen($bytes));
    }

    /** @param array<string, mixed> $datos */
    private function exportarPdf(Response $response, string $tipo, array $datos): Response
    {
        $html = $this->twig->fetch('reportes/pdf/' . $tipo . '.html.twig', [
            'titulo' => $this->tituloExportable($tipo),
        ] + $datos);

        $bytes = $this->pdf->fromHtml($html);
        $nombreArchivo = $this->nombreArchivo($tipo, 'pdf');

        $response->getBody()->write($bytes);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $nombreArchivo . '"')
            ->withHeader('Content-Length', (string) strlen($bytes));
    }

    private function tituloExportable(string $tipo): string
    {
        return match ($tipo) {
            'planilla'  => 'Planilla por Periodo',
            'historial' => 'Historial por Empleado',
            'costos'    => 'Costos Patronales',
            'auditoria' => 'Bitacora de Auditoria',
        };
    }

    private function nombreArchivo(string $tipo, string $extension): string
    {
        return $tipo . '_' . date('Y-m-d') . '.' . $extension;
    }

    /** @param array<string, mixed> $datos */
    private function hojaPlanilla(array $datos): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $encabezados = ['Cédula', 'Nombre', 'Apellidos', 'Salario Bruto', 'Deducciones', 'Salario Neto'];
        $filas = array_map(static fn (array $f): array => [
            $f['cedula'], $f['nombre'], $f['apellidos'],
            (float) $f['salario_bruto'], (float) $f['total_deducciones'], (float) $f['salario_neto'],
        ], $datos['filas']);

        return $this->excel->build('Planilla por Periodo', $encabezados, $filas);
    }

    /** @param array<string, mixed> $datos */
    private function hojaCostos(array $datos): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $encabezados = ['Nombre', 'Apellidos', 'Salario Bruto', 'CCSS Patronal', 'Provisión Aguinaldo', 'Costo Total'];
        $filas = array_map(static fn (array $f): array => [
            $f['nombre'], $f['apellidos'],
            (float) $f['salario_bruto'], (float) $f['ccss_patronal'],
            (float) $f['provision_aguinaldo'], (float) $f['costo_total'],
        ], $datos['filas']);

        return $this->excel->build('Costos Patronales', $encabezados, $filas);
    }

    /** @param array<string, mixed> $datos */
    private function hojaAuditoria(array $datos): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $encabezados = ['Fecha', 'Usuario', 'Acción', 'Tabla Afectada', 'ID Registro', 'IP', 'Detalle'];
        $filas = array_map(static fn (array $r): array => [
            $r['fecha_accion'], $r['nombre_usuario'], $r['accion'],
            $r['tabla_afectada'], $r['id_registro'], $r['ip_origen'], $r['detalle'] ?? '',
        ], $datos['registros']);

        return $this->excel->build('Bitácora de Auditoría', $encabezados, $filas);
    }

    /** @param array<string, mixed> $datos */
    private function hojasHistorial(array $datos): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $secciones = $datos['secciones'] ?? null;

        $spreadsheet = $this->excel->build('Nóminas', ['Período inicio', 'Período fin', 'Bruto', 'Deducciones', 'Neto', 'Estado'],
            $secciones === null ? [] : array_map(static fn (array $n): array => [
                $n['fecha_inicio'], $n['fecha_fin'], (float) $n['salario_bruto'],
                (float) $n['total_deducciones'], (float) $n['salario_neto'], $n['estado'],
            ], $secciones['nominas']));

        if ($secciones !== null) {
            $this->excel->addSheet($spreadsheet, 'Horas Extra', ['Fecha', 'Horas', 'Factor'],
                array_map(static fn (array $h): array => [$h['fecha'], (float) $h['cantidad_horas'], (float) $h['factor_recargo']], $secciones['horas_extra']));

            $this->excel->addSheet($spreadsheet, 'Vacaciones', ['Inicio', 'Fin', 'Días'],
                array_map(static fn (array $v): array => [$v['fecha_inicio'], $v['fecha_fin'], (float) $v['dias_tomados']], $secciones['vacaciones']));

            $this->excel->addSheet($spreadsheet, 'Incapacidades', ['Tipo', 'Inicio', 'Fin', 'Días'],
                array_map(static fn (array $i): array => [$i['tipo'], $i['fecha_inicio'], $i['fecha_fin'], (float) $i['dias']], $secciones['incapacidades']));

            $this->excel->addSheet($spreadsheet, 'Permisos', ['Inicio', 'Fin'],
                array_map(static fn (array $p): array => [$p['fecha_inicio'], $p['fecha_fin']], $secciones['permisos']));
        }

        return $spreadsheet;
    }
}
