<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * EvaluacionesRepository — SQL de evaluaciones y detalle_evaluacion.
 * Las escrituras que tocan ambas tablas van en transacción.
 */
class EvaluacionesRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int, array<string, mixed>> */
    public function findAll(?string $q = null): array
    {
        $sql    = "SELECT ev.id_evaluacion, ev.id_empleado, ev.fecha_evaluacion, ev.periodo_evaluado,
                          ev.puntaje_total, ev.observaciones, e.nombre, e.apellidos
                     FROM evaluaciones ev
                     JOIN empleados e ON e.id_empleado = ev.id_empleado";
        $params = [];
        if ($q !== null && $q !== '') {
            $sql .= " WHERE CONCAT(e.nombre, ' ', e.apellidos) LIKE :q OR CONCAT(e.apellidos, ', ', e.nombre) LIKE :q";
            $params[':q'] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY ev.periodo_evaluado DESC, e.apellidos';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT ev.*, e.nombre, e.apellidos
               FROM evaluaciones ev
               JOIN empleados e ON e.id_empleado = ev.id_empleado
              WHERE ev.id_evaluacion = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function findDetalles(int $idEvaluacion): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_detalle, criterio, puntaje, peso
               FROM detalle_evaluacion
              WHERE id_evaluacion = :id
              ORDER BY id_detalle'
        );
        $stmt->execute([':id' => $idEvaluacion]);
        return $stmt->fetchAll();
    }

    /** Evaluaciones del empleado vinculado a un usuario. @return array<int, array<string, mixed>> */
    public function findByEmpleadoUsuario(int $idUsuario): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT ev.id_evaluacion, ev.fecha_evaluacion, ev.periodo_evaluado,
                    ev.puntaje_total, ev.observaciones, e.nombre, e.apellidos
               FROM evaluaciones ev
               JOIN empleados e ON e.id_empleado = ev.id_empleado
              WHERE e.id_usuario = :u
              ORDER BY ev.periodo_evaluado DESC"
        );
        $stmt->execute([':u' => $idUsuario]);
        return $stmt->fetchAll();
    }

    public function existeParaEmpleadoAnio(int $idEmpleado, int $anio, ?int $excluirId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM evaluaciones WHERE id_empleado = :e AND periodo_evaluado = :a';
        $params = [':e' => $idEmpleado, ':a' => $anio];
        if ($excluirId !== null) {
            $sql .= ' AND id_evaluacion <> :id';
            $params[':id'] = $excluirId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function esEmpleadoActivo(int $idEmpleado): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM empleados WHERE id_empleado = :id AND estado = 'activo'"
        );
        $stmt->execute([':id' => $idEmpleado]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** Empleados activos para el formulario. @return array<int, array<string, mixed>> */
    public function empleadosActivos(): array
    {
        return $this->pdo->query(
            "SELECT id_empleado, nombre, apellidos FROM empleados WHERE estado = 'activo' ORDER BY apellidos"
        )->fetchAll();
    }

    /**
     * Inserta cabecera + detalles en una transacción.
     *
     * @param array{id_empleado:int, fecha_evaluacion:string, periodo_evaluado:int, puntaje_total:float, observaciones:?string} $cab
     * @param array<int, array{criterio:string, puntaje:float, peso:float}> $detalles
     */
    public function insertConDetalles(array $cab, array $detalles): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO evaluaciones
                    (id_empleado, fecha_evaluacion, periodo_evaluado, puntaje_total, observaciones)
                 VALUES (:e, :f, :p, :t, :o)'
            );
            $stmt->execute([
                ':e' => $cab['id_empleado'],
                ':f' => $cab['fecha_evaluacion'],
                ':p' => $cab['periodo_evaluado'],
                ':t' => $cab['puntaje_total'],
                ':o' => $cab['observaciones'],
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->insertDetalles($id, $detalles);
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Actualiza cabecera y reemplaza los detalles (DELETE + re-INSERT) en una transacción.
     *
     * @param array{id_empleado:int, fecha_evaluacion:string, periodo_evaluado:int, puntaje_total:float, observaciones:?string} $cab
     * @param array<int, array{criterio:string, puntaje:float, peso:float}> $detalles
     */
    public function updateConDetalles(int $id, array $cab, array $detalles): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE evaluaciones
                    SET id_empleado = :e, fecha_evaluacion = :f, periodo_evaluado = :p,
                        puntaje_total = :t, observaciones = :o
                  WHERE id_evaluacion = :id'
            );
            $stmt->execute([
                ':e'  => $cab['id_empleado'],
                ':f'  => $cab['fecha_evaluacion'],
                ':p'  => $cab['periodo_evaluado'],
                ':t'  => $cab['puntaje_total'],
                ':o'  => $cab['observaciones'],
                ':id' => $id,
            ]);
            $del = $this->pdo->prepare('DELETE FROM detalle_evaluacion WHERE id_evaluacion = :id');
            $del->execute([':id' => $id]);
            $this->insertDetalles($id, $detalles);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        // ON DELETE CASCADE elimina los detalles.
        $stmt = $this->pdo->prepare('DELETE FROM evaluaciones WHERE id_evaluacion = :id');
        $stmt->execute([':id' => $id]);
    }

    /** @param array<int, array{criterio:string, puntaje:float, peso:float}> $detalles */
    private function insertDetalles(int $idEvaluacion, array $detalles): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO detalle_evaluacion (id_evaluacion, criterio, puntaje, peso)
             VALUES (:id, :c, :pu, :pe)'
        );
        foreach ($detalles as $d) {
            $stmt->execute([
                ':id' => $idEvaluacion,
                ':c'  => $d['criterio'],
                ':pu' => $d['puntaje'],
                ':pe' => $d['peso'],
            ]);
        }
    }
}
