<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * EmpleadosRepository
 *
 * Responsabilidad única: acceso a las tablas `empleados` y `datos_bancarios`
 * vía PDO. No contiene lógica de negocio.
 */
class EmpleadosRepository
{
    public function __construct(private readonly PDO $pdo) {}

    // ── Lectura ───────────────────────────────────────────

    /**
     * Lista empleados con el nombre de su puesto.
     * Filtra por estado ('activo'/'inactivo') o todos si es null.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(?string $estado = null, ?string $q = null): array
    {
        $sql = 'SELECT e.id_empleado, e.nombre, e.apellidos, e.cedula,
                       e.fecha_ingreso, e.estado, p.nombre AS puesto_nombre
                  FROM empleados e
                  JOIN puestos p ON p.id_puesto = e.id_puesto';
        $params = [];
        $where  = [];

        if ($estado !== null) {
            $where[]           = 'e.estado = :estado';
            $params[':estado'] = $estado;
        }

        if ($q !== null && $q !== '') {
            $where[] = "(CONCAT(e.nombre, ' ', e.apellidos) LIKE :q
                         OR CONCAT(e.apellidos, ', ', e.nombre) LIKE :q
                         OR e.cedula LIKE :q
                         OR p.nombre LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY e.apellidos ASC, e.nombre ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Empleado por ID + su cuenta bancaria activa (LEFT JOIN).
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.*,
                    b.id_datos_bancarios, b.banco, b.tipo_cuenta, b.numero_cuenta,
                    b.numero_cuenta_iban, b.moneda
               FROM empleados e
          LEFT JOIN datos_bancarios b
                 ON b.id_empleado = e.id_empleado AND b.activa = 1
              WHERE e.id_empleado = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Verifica si ya existe la cédula (normalizada), excluyendo un ID al editar.
     */
    public function existsByCedula(string $cedula, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM empleados WHERE cedula = :cedula';
        $params = [':cedula' => $cedula];

        if ($excludeId !== null) {
            $sql .= ' AND id_empleado <> :id';
            $params[':id'] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Usuarios rol 'empleado' no vinculados a otro empleado (más el actual al editar).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findUsuariosDisponibles(?int $currentUsuarioId = null): array
    {
        $sql = "SELECT u.id_usuario, u.nombre_usuario
                  FROM usuarios u
                 WHERE u.rol = 'empleado'
                   AND u.id_usuario NOT IN (
                           SELECT id_usuario FROM empleados WHERE id_usuario IS NOT NULL
                   )";
        $params = [];

        if ($currentUsuarioId !== null) {
            // Al editar, incluir también el usuario ya vinculado a este empleado.
            $sql = "SELECT u.id_usuario, u.nombre_usuario
                      FROM usuarios u
                     WHERE u.rol = 'empleado'
                       AND (u.id_usuario NOT IN (
                               SELECT id_usuario FROM empleados WHERE id_usuario IS NOT NULL
                            )
                            OR u.id_usuario = :current)";
            $params[':current'] = $currentUsuarioId;
        }

        $sql .= ' ORDER BY u.nombre_usuario ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ── Escritura ─────────────────────────────────────────

    /**
     * Inserta empleado y (opcionalmente) su cuenta bancaria en una transacción.
     *
     * @param array<string, mixed>      $empleado datos del empleado (claves = columnas)
     * @param array<string, mixed>|null $bancario datos bancarios o null
     * @return int id_empleado generado
     */
    public function insert(array $empleado, ?array $bancario): int
    {
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO empleados
                    (id_puesto, id_usuario, nombre, apellidos, cedula, fecha_nacimiento,
                     genero, estado_civil, nacionalidad, telefono, correo, direccion,
                     provincia, canton, distrito, telefono_emergencia,
                     nombre_contacto_emergencia, numero_asegurado_ccss, numero_poliza_ins,
                     fecha_ingreso, fecha_salida, estado)
                 VALUES
                    (:id_puesto, :id_usuario, :nombre, :apellidos, :cedula, :fecha_nacimiento,
                     :genero, :estado_civil, :nacionalidad, :telefono, :correo, :direccion,
                     :provincia, :canton, :distrito, :telefono_emergencia,
                     :nombre_contacto_emergencia, :numero_asegurado_ccss, :numero_poliza_ins,
                     :fecha_ingreso, :fecha_salida, :estado)'
            );
            $stmt->execute($this->bindEmpleado($empleado));
            $id = (int)$this->pdo->lastInsertId();

            if ($bancario !== null) {
                $this->insertBancario($id, $bancario);
            }

            if ($ownTransaction) {
                $this->pdo->commit();
            }
            return $id;
        } catch (\Throwable $ex) {
            if ($ownTransaction) {
                $this->pdo->rollBack();
            }
            throw $ex;
        }
    }

    /**
     * Actualiza empleado y hace upsert de su cuenta bancaria activa, en transacción.
     *
     * @param array<string, mixed>      $empleado
     * @param array<string, mixed>|null $bancario
     */
    public function update(int $id, array $empleado, ?array $bancario): void
    {
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE empleados SET
                    id_puesto = :id_puesto, id_usuario = :id_usuario, nombre = :nombre,
                    apellidos = :apellidos, cedula = :cedula, fecha_nacimiento = :fecha_nacimiento,
                    genero = :genero, estado_civil = :estado_civil, nacionalidad = :nacionalidad,
                    telefono = :telefono, correo = :correo, direccion = :direccion,
                    provincia = :provincia, canton = :canton, distrito = :distrito,
                    telefono_emergencia = :telefono_emergencia,
                    nombre_contacto_emergencia = :nombre_contacto_emergencia,
                    numero_asegurado_ccss = :numero_asegurado_ccss,
                    numero_poliza_ins = :numero_poliza_ins,
                    fecha_ingreso = :fecha_ingreso, fecha_salida = :fecha_salida, estado = :estado
                  WHERE id_empleado = :id_empleado'
            );
            $params = $this->bindEmpleado($empleado);
            $params[':id_empleado'] = $id;
            $stmt->execute($params);

            if ($bancario !== null) {
                $chk = $this->pdo->prepare(
                    'SELECT id_datos_bancarios FROM datos_bancarios
                      WHERE id_empleado = :id AND activa = 1 LIMIT 1'
                );
                $chk->execute([':id' => $id]);
                $existing = $chk->fetchColumn();

                if ($existing !== false) {
                    $upd = $this->pdo->prepare(
                        'UPDATE datos_bancarios SET
                            banco = :banco, tipo_cuenta = :tipo_cuenta,
                            numero_cuenta = :numero_cuenta,
                            numero_cuenta_iban = :numero_cuenta_iban, moneda = :moneda
                          WHERE id_datos_bancarios = :idb'
                    );
                    $upd->execute([
                        ':banco'              => $bancario['banco'],
                        ':tipo_cuenta'        => $bancario['tipo_cuenta'],
                        ':numero_cuenta'      => $bancario['numero_cuenta'],
                        ':numero_cuenta_iban' => $bancario['numero_cuenta_iban'],
                        ':moneda'             => $bancario['moneda'],
                        ':idb'                => (int)$existing,
                    ]);
                } else {
                    $this->insertBancario($id, $bancario);
                }
            } else {
                // El bloque bancario vino vacío: desactivar la cuenta activa si existía.
                $deact = $this->pdo->prepare(
                    'UPDATE datos_bancarios SET activa = 0
                      WHERE id_empleado = :id AND activa = 1'
                );
                $deact->execute([':id' => $id]);
            }

            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $ex) {
            if ($ownTransaction) {
                $this->pdo->rollBack();
            }
            throw $ex;
        }
    }

    /**
     * Soft-delete: marca el empleado como inactivo y registra la fecha de salida.
     */
    public function deactivate(int $id, string $fechaSalida): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE empleados SET estado = 'inactivo', fecha_salida = :f
              WHERE id_empleado = :id"
        );
        $stmt->execute([':f' => $fechaSalida, ':id' => $id]);
    }

    // ── Helpers privados ──────────────────────────────────

    /**
     * @param array<string, mixed> $b
     */
    private function insertBancario(int $idEmpleado, array $b): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO datos_bancarios
                (id_empleado, banco, tipo_cuenta, numero_cuenta, numero_cuenta_iban, moneda, activa)
             VALUES
                (:id_empleado, :banco, :tipo_cuenta, :numero_cuenta, :numero_cuenta_iban, :moneda, 1)'
        );
        $stmt->execute([
            ':id_empleado'        => $idEmpleado,
            ':banco'              => $b['banco'],
            ':tipo_cuenta'        => $b['tipo_cuenta'],
            ':numero_cuenta'      => $b['numero_cuenta'],
            ':numero_cuenta_iban' => $b['numero_cuenta_iban'],
            ':moneda'             => $b['moneda'],
        ]);
    }

    /**
     * Mapea el array de empleado a parámetros nombrados de PDO.
     *
     * @param array<string, mixed> $e
     * @return array<string, mixed>
     */
    private function bindEmpleado(array $e): array
    {
        return [
            ':id_puesto'                  => $e['id_puesto'],
            ':id_usuario'                 => $e['id_usuario'],
            ':nombre'                     => $e['nombre'],
            ':apellidos'                  => $e['apellidos'],
            ':cedula'                     => $e['cedula'],
            ':fecha_nacimiento'           => $e['fecha_nacimiento'],
            ':genero'                     => $e['genero'],
            ':estado_civil'               => $e['estado_civil'],
            ':nacionalidad'               => $e['nacionalidad'],
            ':telefono'                   => $e['telefono'],
            ':correo'                     => $e['correo'],
            ':direccion'                  => $e['direccion'],
            ':provincia'                  => $e['provincia'],
            ':canton'                     => $e['canton'],
            ':distrito'                   => $e['distrito'],
            ':telefono_emergencia'        => $e['telefono_emergencia'],
            ':nombre_contacto_emergencia' => $e['nombre_contacto_emergencia'],
            ':numero_asegurado_ccss'      => $e['numero_asegurado_ccss'],
            ':numero_poliza_ins'          => $e['numero_poliza_ins'],
            ':fecha_ingreso'              => $e['fecha_ingreso'],
            ':fecha_salida'               => $e['fecha_salida'],
            ':estado'                     => $e['estado'],
        ];
    }
}
