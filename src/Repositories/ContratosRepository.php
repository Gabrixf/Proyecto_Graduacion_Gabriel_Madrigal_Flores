<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * ContratosRepository — SQL para `contratos` (historial de contratos por empleado).
 * Sin lógica de negocio; solo acceso a datos.
 */
class ContratosRepository
{
    public function __construct(private readonly PDO $pdo) {}

    // ── Lectura ───────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function findAll(?int $idEmpleado = null, ?string $estado = null, ?string $tipo = null, ?string $q = null): array
    {
        $sql = "SELECT c.id_contrato, c.id_empleado, c.tipo_contrato, c.salario_pactado,
                       c.jornada, c.fecha_inicio, c.fecha_fin, c.estado,
                       per.nombre, per.apellidos
                  FROM contratos c
                  JOIN empleados e ON e.id_empleado = c.id_empleado
                  JOIN persona per ON per.id_persona = e.id_persona";
        $where  = [];
        $params = [];
        if ($idEmpleado !== null) {
            $where[] = 'c.id_empleado = :empleado';
            $params[':empleado'] = $idEmpleado;
        }
        if ($estado !== null) {
            $where[] = 'c.estado = :estado';
            $params[':estado'] = $estado;
        }
        if ($tipo !== null) {
            $where[] = 'c.tipo_contrato = :tipo';
            $params[':tipo'] = $tipo;
        }
        if ($q !== null && $q !== '') {
            $where[] = "(CONCAT(per.nombre, ' ', per.apellidos) LIKE :q OR CONCAT(per.apellidos, ', ', per.nombre) LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY c.fecha_inicio DESC, per.apellidos ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.*, per.nombre, per.apellidos
               FROM contratos c
               JOIN empleados e ON e.id_empleado = c.id_empleado
               JOIN persona per ON per.id_persona = e.id_persona
              WHERE c.id_contrato = :id
              LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** Empleados activos, para el <select> del formulario de creación. @return array<int, array<string, mixed>> */
    public function empleadosActivos(): array
    {
        return $this->pdo->query(
            "SELECT e.id_empleado, per.nombre, per.apellidos
               FROM empleados e
               JOIN persona per ON per.id_persona = e.id_persona
              WHERE e.estado = 'activo'
              ORDER BY per.apellidos"
        )->fetchAll();
    }

    public function esEmpleadoActivo(int $idEmpleado): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM empleados WHERE id_empleado = :id AND estado = 'activo'");
        $stmt->execute([':id' => $idEmpleado]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /** Contrato vigente (estado='activo') del empleado, o null si no tiene. @return array<string, mixed>|null */
    public function findActivoPorEmpleado(int $idEmpleado): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id_contrato FROM contratos WHERE id_empleado = :e AND estado = 'activo' LIMIT 1"
        );
        $stmt->execute([':e' => $idEmpleado]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    // ── Escritura ─────────────────────────────────────────

    /** @param array<string, mixed> $d */
    public function insert(array $d): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO contratos (id_empleado, tipo_contrato, salario_pactado, jornada, fecha_inicio, fecha_fin, estado)
             VALUES (:id_empleado, :tipo_contrato, :salario_pactado, :jornada, :fecha_inicio, :fecha_fin, :estado)'
        );
        $stmt->execute($this->bind($d));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Edita solo los datos "de hecho" del contrato (tipo/salario/jornada/fecha_inicio).
     * `estado`/`fecha_fin` no se editan aquí — solo cambian vía renovar().
     *
     * @param array<string, mixed> $d
     */
    public function update(int $id, array $d): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE contratos SET
                tipo_contrato = :tipo_contrato, salario_pactado = :salario_pactado,
                jornada = :jornada, fecha_inicio = :fecha_inicio
              WHERE id_contrato = :id'
        );
        $stmt->execute([
            ':tipo_contrato'   => $d['tipo_contrato'],
            ':salario_pactado' => $d['salario_pactado'],
            ':jornada'         => $d['jornada'],
            ':fecha_inicio'    => $d['fecha_inicio'],
            ':id'              => $id,
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM contratos WHERE id_contrato = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Renovación: cierra el contrato vigente (estado=finalizado, fecha_fin=$fechaFinAnterior)
     * y abre uno nuevo (estado=activo, fecha_fin=9999-12-31) para el mismo empleado — en una
     * sola transacción.
     *
     * @param array<string, mixed> $nuevo claves: tipo_contrato, salario_pactado, jornada, fecha_inicio
     * @return int id_contrato del nuevo contrato
     */
    public function renovar(int $idContratoActual, int $idEmpleado, string $fechaFinAnterior, array $nuevo): int
    {
        $this->pdo->beginTransaction();
        try {
            $cerrar = $this->pdo->prepare(
                "UPDATE contratos SET estado = 'finalizado', fecha_fin = :f WHERE id_contrato = :id"
            );
            $cerrar->execute([':f' => $fechaFinAnterior, ':id' => $idContratoActual]);

            $abrir = $this->pdo->prepare(
                "INSERT INTO contratos (id_empleado, tipo_contrato, salario_pactado, jornada, fecha_inicio, fecha_fin, estado)
                 VALUES (:id_empleado, :tipo_contrato, :salario_pactado, :jornada, :fecha_inicio, '9999-12-31', 'activo')"
            );
            $abrir->execute([
                ':id_empleado'     => $idEmpleado,
                ':tipo_contrato'   => $nuevo['tipo_contrato'],
                ':salario_pactado' => $nuevo['salario_pactado'],
                ':jornada'         => $nuevo['jornada'],
                ':fecha_inicio'    => $nuevo['fecha_inicio'],
            ]);
            $id = (int)$this->pdo->lastInsertId();

            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    private function bind(array $d): array
    {
        return [
            ':id_empleado'     => $d['id_empleado'],
            ':tipo_contrato'   => $d['tipo_contrato'],
            ':salario_pactado' => $d['salario_pactado'],
            ':jornada'         => $d['jornada'],
            ':fecha_inicio'    => $d['fecha_inicio'],
            ':fecha_fin'       => $d['fecha_fin'],
            ':estado'          => $d['estado'],
        ];
    }
}
