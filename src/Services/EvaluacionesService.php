<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\EvaluacionCalculadora;
use App\Repositories\AuditoriaRepository;
use App\Repositories\EvaluacionesRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * EvaluacionesService — reglas de negocio de las evaluaciones de rendimiento.
 * Una evaluación por empleado por año; puntaje total siempre calculado.
 */
class EvaluacionesService
{
    private const ANIO_MINIMO = 2000;
    private const MAX_OBSERVACIONES = 500;
    private const MAX_CRITERIO = 150;

    /** @param array<int, array{criterio:string, peso:float}> $criteriosPlantilla */
    public function __construct(
        private readonly EvaluacionesRepository $repo,
        private readonly AuditoriaRepository    $auditoriaRepo,
        private readonly EvaluacionCalculadora  $calc,
        private readonly array                  $criteriosPlantilla
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function listar(?string $q = null): array
    {
        return $this->repo->findAll($q);
    }

    /** Cabecera + detalles. @return array<string, mixed> */
    public function obtener(int $id): array
    {
        $cab = $this->repo->findById($id);
        if ($cab === null) {
            throw new RuntimeException('Evaluación no encontrada.');
        }
        $cab['detalles'] = $this->repo->findDetalles($id);
        return $cab;
    }

    /** @return array{empleados: array<int, array<string, mixed>>, criterios: array<int, array{criterio:string, peso:float}>} */
    public function datosFormulario(): array
    {
        return [
            'empleados' => $this->repo->empleadosActivos(),
            'criterios' => $this->criteriosPlantilla,
        ];
    }

    /** @return int id_evaluacion */
    public function crear(array $datos, int $loggedInId, string $ip): int
    {
        [$cabecera, $detalles] = $this->validar($datos);
        $id = $this->repo->insertConDetalles($cabecera, $detalles);
        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'evaluaciones', $id, $ip);
        return $id;
    }

    public function actualizar(int $id, array $datos, int $loggedInId, string $ip): void
    {
        $this->obtener($id); // lanza RuntimeException si no existe
        [$cabecera, $detalles] = $this->validar($datos, $id);
        $this->repo->updateConDetalles($id, $cabecera, $detalles);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'evaluaciones', $id, $ip);
    }

    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $this->obtener($id);
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'evaluaciones', $id, $ip);
    }

    /** Evaluaciones (con detalles) del empleado ligado al usuario autenticado. @return array<int, array<string, mixed>> */
    public function misEvaluaciones(int $idUsuario): array
    {
        $evaluaciones = $this->repo->findByEmpleadoUsuario($idUsuario);
        foreach ($evaluaciones as &$ev) {
            $ev['detalles'] = $this->repo->findDetalles((int) $ev['id_evaluacion']);
        }
        unset($ev);
        return $evaluaciones;
    }

    /**
     * Valida el input del formulario y arma cabecera + detalles.
     *
     * @return array{0: array<string, mixed>, 1: array<int, array{criterio:string, puntaje:float, peso:float}>}
     */
    private function validar(array $datos, ?int $excluirId = null): array
    {
        $idEmpleado = isset($datos['id_empleado']) && is_numeric($datos['id_empleado'])
            ? (int) $datos['id_empleado'] : 0;
        $anio = isset($datos['periodo_evaluado']) && is_numeric($datos['periodo_evaluado'])
            ? (int) $datos['periodo_evaluado'] : 0;
        $fecha     = trim((string) ($datos['fecha_evaluacion'] ?? ''));
        $obs       = trim((string) ($datos['observaciones'] ?? ''));
        $criterios = array_values((array) ($datos['criterio'] ?? []));
        $pesos     = array_values((array) ($datos['peso'] ?? []));
        $puntajes  = array_values((array) ($datos['puntaje'] ?? []));

        $errores = [];
        if ($idEmpleado <= 0 || !$this->repo->esEmpleadoActivo($idEmpleado)) {
            $errores[] = 'Debe seleccionar un empleado activo válido.';
        }
        $anioActual = (int) date('Y');
        if ($anio < self::ANIO_MINIMO || $anio > $anioActual) {
            $errores[] = "El año evaluado debe estar entre " . self::ANIO_MINIMO . " y {$anioActual}.";
        }
        if ($fecha === '' || strtotime($fecha) === false) {
            $errores[] = 'La fecha de evaluación no es válida.';
        }
        if (mb_strlen($obs) > self::MAX_OBSERVACIONES) {
            $errores[] = 'Las observaciones no pueden superar los ' . self::MAX_OBSERVACIONES . ' caracteres.';
        }
        $n = count($criterios);
        if ($n === 0 || $n !== count($pesos) || $n !== count($puntajes)) {
            $errores[] = 'Los criterios de la evaluación están incompletos.';
        } else {
            foreach ($criterios as $i => $criterio) {
                $criterio = trim((string) $criterio);
                if ($criterio === '' || mb_strlen($criterio) > self::MAX_CRITERIO) {
                    $errores[] = 'Cada criterio debe tener un nombre de 1 a ' . self::MAX_CRITERIO . ' caracteres.';
                    break;
                }
                if (!is_numeric($pesos[$i]) || !is_numeric($puntajes[$i])) {
                    $errores[] = 'Cada criterio debe tener peso y puntaje numéricos.';
                    break;
                }
                if ((float) $puntajes[$i] < 1 || (float) $puntajes[$i] > 100) {
                    $errores[] = 'Cada puntaje debe estar entre 1 y 100.';
                    break;
                }
            }
        }
        if ($errores === [] && $this->repo->existeParaEmpleadoAnio($idEmpleado, $anio, $excluirId)) {
            $errores[] = "Ya existe una evaluación de ese empleado para el año {$anio}.";
        }
        if ($errores !== []) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $detalles = [];
        foreach ($criterios as $i => $criterio) {
            $detalles[] = [
                'criterio' => trim((string) $criterio),
                'puntaje'  => round((float) $puntajes[$i], 2),
                'peso'     => round((float) $pesos[$i], 2),
            ];
        }
        // Lanza InvalidArgumentException si los pesos no suman 100.
        $total = $this->calc->puntajeTotal($detalles);

        $cabecera = [
            'id_empleado'      => $idEmpleado,
            'fecha_evaluacion' => $fecha,
            'periodo_evaluado' => $anio,
            'puntaje_total'    => $total,
            'observaciones'    => $obs !== '' ? $obs : null,
        ];
        return [$cabecera, $detalles];
    }
}
