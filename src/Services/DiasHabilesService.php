<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\DiasHabiles;
use App\Repositories\FeriadosRepository;
use DateTimeImmutable;

/**
 * DiasHabilesService — días que consume una solicitud de vacaciones o permiso.
 * Único punto de la regla: lunes a sábado por defecto (o según el descanso del horario del empleado), sin contar los feriados del catálogo.
 */
class DiasHabilesService
{
    public function __construct(
        private readonly FeriadosRepository $feriadosRepo,
        private readonly ?HorariosService $horarios = null,
        private readonly DiasHabiles $diasHabiles = new DiasHabiles()
    ) {}

    public function contar(string $inicio, string $fin, ?int $idEmpleado = null): float
    {
        $descanso = ($idEmpleado !== null && $this->horarios !== null)
            ? $this->horarios->diasDescanso($idEmpleado)
            : [7];
        $feriados = array_map(
            static fn (array $f): string => (string)$f['fecha'],
            $this->feriadosRepo->findEntre($inicio, $fin)
        );
        return (float)$this->diasHabiles->contar($inicio, $fin, $feriados, $descanso);
    }

    /**
     * Feriados del catálogo de un año atrás a dos años adelante, para que el formulario
     * de solicitudes muestre en pantalla el mismo conteo que valida el servidor.
     * @return string[] fechas Y-m-d
     */
    public function feriadosCercanos(DateTimeImmutable $hoy): array
    {
        return array_map(
            static fn (array $f): string => (string)$f['fecha'],
            $this->feriadosRepo->findEntre(
                $hoy->modify('-1 year')->format('Y-m-d'),
                $hoy->modify('+2 years')->format('Y-m-d')
            )
        );
    }

    /**
     * `horas` > 0 es el override de medio día; si no (0 = días completos), cuenta
     * los días hábiles del rango.
     * @param array<string, mixed> $solicitud
     */
    public function diasDeSolicitud(array $solicitud): float
    {
        if (is_numeric($solicitud['horas'] ?? null) && (float)$solicitud['horas'] > 0) {
            return (float)$solicitud['horas'];
        }
        $inicio = (string)$solicitud['fecha_inicio'];
        $fin    = (string)(($solicitud['fecha_fin'] ?? '') !== '' ? $solicitud['fecha_fin'] : $inicio);
        return $this->contar($inicio, $fin, isset($solicitud['id_empleado']) ? (int)$solicitud['id_empleado'] : null);
    }
}
