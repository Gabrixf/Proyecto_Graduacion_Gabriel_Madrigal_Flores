<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ReportesRepository;
use DateTime;

/**
 * ReportesService — valida los filtros GET y arma las estructuras para Twig.
 * Filtros inválidos se ignoran (reporte sin selección); nunca lanzan excepción.
 */
class ReportesService
{
    private const ACCIONES_AUDITORIA = ['INSERT', 'UPDATE', 'DELETE', 'LOGIN', 'LOGOUT'];
    private const LIMITE_AUDITORIA = 200;

    public function __construct(private readonly ReportesRepository $repo) {}

    /** @return array<string, mixed> */
    public function planilla(array $filtros): array
    {
        $idPeriodo = $this->idValido($filtros['periodo'] ?? null);
        $filas = $idPeriodo !== null ? $this->repo->planillaPorPeriodo($idPeriodo) : [];
        $totales = ['bruto' => 0.0, 'deducciones' => 0.0, 'neto' => 0.0];
        foreach ($filas as $f) {
            $totales['bruto']       += (float) $f['salario_bruto'];
            $totales['deducciones'] += (float) $f['total_deducciones'];
            $totales['neto']        += (float) $f['salario_neto'];
        }
        return [
            'periodos'  => $this->repo->periodos(),
            'idPeriodo' => $idPeriodo,
            'filas'     => $filas,
            'totales'   => $totales,
        ];
    }

    /** @return array<string, mixed> */
    public function historial(array $filtros): array
    {
        $idEmpleado = $this->idValido($filtros['empleado'] ?? null);
        [$desde, $hasta] = $this->rangoFechas(
            $filtros['desde'] ?? null,
            $filtros['hasta'] ?? null,
            porDefectoAnio: true
        );
        $secciones = null;
        if ($idEmpleado !== null) {
            $secciones = [
                'nominas'       => $this->repo->nominasEmpleado($idEmpleado, $desde, $hasta),
                'horas_extra'   => $this->repo->horasExtraEmpleado($idEmpleado, $desde, $hasta),
                'vacaciones'    => $this->repo->vacacionesEmpleado($idEmpleado, $desde, $hasta),
                'incapacidades' => $this->repo->incapacidadesEmpleado($idEmpleado, $desde, $hasta),
                'permisos'      => $this->repo->permisosEmpleado($idEmpleado, $desde, $hasta),
            ];
        }
        return [
            'empleados'  => $this->repo->empleados(),
            'idEmpleado' => $idEmpleado,
            'desde'      => $desde,
            'hasta'      => $hasta,
            'secciones'  => $secciones,
        ];
    }

    /** @return array<string, mixed> */
    public function costos(array $filtros): array
    {
        $idPeriodo = $this->idValido($filtros['periodo'] ?? null);
        $filas = [];
        $totales = ['bruto' => 0.0, 'ccss' => 0.0, 'aguinaldo' => 0.0, 'total' => 0.0];
        if ($idPeriodo !== null) {
            foreach ($this->repo->costosPorPeriodo($idPeriodo) as $f) {
                $bruto     = round((float) $f['salario_bruto'], 2);
                $ccss      = round((float) $f['ccss_patronal'], 2);
                $aguinaldo = round((float) $f['provision_aguinaldo'], 2);
                $f['costo_total'] = round($bruto + $ccss + $aguinaldo, 2);
                $totales['bruto']     += $bruto;
                $totales['ccss']      += $ccss;
                $totales['aguinaldo'] += $aguinaldo;
                $totales['total']     += $f['costo_total'];
                $filas[] = $f;
            }
        }
        return [
            'periodos'  => $this->repo->periodos(),
            'idPeriodo' => $idPeriodo,
            'filas'     => $filas,
            'totales'   => $totales,
        ];
    }

    /** @return array<string, mixed> */
    public function auditoria(array $filtros): array
    {
        $accion = in_array($filtros['accion'] ?? '', self::ACCIONES_AUDITORIA, true)
            ? (string) $filtros['accion'] : null;
        $idUsuario = $this->idValido($filtros['usuario'] ?? null);
        [$desde, $hasta] = $this->rangoFechas(
            $filtros['desde'] ?? null,
            $filtros['hasta'] ?? null,
            porDefectoAnio: false
        );
        return [
            'usuarios'  => $this->repo->usuarios(),
            'acciones'  => self::ACCIONES_AUDITORIA,
            'accion'    => $accion,
            'idUsuario' => $idUsuario,
            'desde'     => $desde,
            'hasta'     => $hasta,
            'limite'    => self::LIMITE_AUDITORIA,
            'registros' => $this->repo->auditoria($accion, $idUsuario, $desde, $hasta, self::LIMITE_AUDITORIA),
        ];
    }

    private function idValido(mixed $valor): ?int
    {
        if (!is_string($valor) && !is_int($valor)) {
            return null;
        }
        $s = (string) $valor;
        return ctype_digit($s) && (int) $s > 0 ? (int) $s : null;
    }

    /**
     * Devuelve [desde, hasta] saneados. Con porDefectoAnio, los vacíos se llenan
     * con el año actual; si desde > hasta se intercambian.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function rangoFechas(?string $desde, ?string $hasta, bool $porDefectoAnio): array
    {
        $desde = $this->fechaValida($desde);
        $hasta = $this->fechaValida($hasta);
        if ($porDefectoAnio) {
            $desde ??= date('Y') . '-01-01';
            $hasta ??= date('Y') . '-12-31';
        }
        if ($desde !== null && $hasta !== null && $desde > $hasta) {
            [$desde, $hasta] = [$hasta, $desde];
        }
        return [$desde, $hasta];
    }

    private function fechaValida(?string $fecha): ?string
    {
        $fecha = trim((string) $fecha);
        if ($fecha === '') {
            return null;
        }
        $d = DateTime::createFromFormat('Y-m-d', $fecha);
        return $d !== false && $d->format('Y-m-d') === $fecha ? $fecha : null;
    }
}
