<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * EmpleadosRepository
 *
 * Responsabilidad única: acceso a las tablas `empleados`, `persona` y
 * `datos_bancarios` vía PDO. No contiene lógica de negocio.
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
        $sql = 'SELECT e.id_empleado, per.nombre, per.apellidos, per.cedula,
                       e.fecha_ingreso, e.estado, p.nombre AS puesto_nombre
                  FROM empleados e
                  JOIN persona per ON per.id_persona = e.id_persona
                  JOIN puestos p ON p.id_puesto = e.id_puesto';
        $params = [];
        $where  = [];

        if ($estado !== null) {
            $where[]           = 'e.estado = :estado';
            $params[':estado'] = $estado;
        }

        if ($q !== null && $q !== '') {
            $where[] = "(CONCAT(per.nombre, ' ', per.apellidos) LIKE :q
                         OR CONCAT(per.apellidos, ', ', per.nombre) LIKE :q
                         OR per.cedula LIKE :q
                         OR p.nombre LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY per.apellidos ASC, per.nombre ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Empleado por ID + datos de persona + su cuenta bancaria activa (LEFT JOIN).
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.id_empleado, e.id_persona, e.id_puesto, e.id_usuario, e.id_horario,
                    e.numero_asegurado_ccss, e.numero_poliza_ins,
                    e.fecha_ingreso, e.fecha_salida, e.estado,
                    per.cedula, per.nombre, per.apellidos, per.fecha_nacimiento,
                    per.genero, per.estado_civil, per.nacionalidad,
                    per.telefono, per.correo, per.direccion, per.id_distrito,
                    per.telefono_alterno, per.correo_alterno, per.direccion_alterna,
                    per.id_distrito_alterno,
                    per.telefono_emergencia, per.nombre_contacto_emergencia,
                    b.id_datos_bancarios, b.banco, b.tipo_cuenta, b.numero_cuenta,
                    b.numero_cuenta_iban, b.moneda
               FROM empleados e
               JOIN persona per ON per.id_persona = e.id_persona
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
     * Verifica cédula duplicada en `persona`, excluyendo el propio id_persona al editar.
     */
    public function existsByCedula(string $cedula, ?int $excludePersonaId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM persona WHERE cedula = :cedula';
        $params = [':cedula' => $cedula];

        if ($excludePersonaId !== null) {
            $sql .= ' AND id_persona <> :id';
            $params[':id'] = $excludePersonaId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Usuarios rol 'empleado' no vinculados a otro empleado (más el actual al editar).
     * Sin cambios: nunca dependió de columnas movidas a persona.
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
     * Inserta persona + empleado y (opcionalmente) su cuenta bancaria, en una transacción.
     *
     * @param array<string, mixed>      $persona  datos de persona (claves = columnas de `persona`)
     * @param array<string, mixed>      $empleado datos de empleado (claves = columnas propias de `empleados`)
     * @param array<string, mixed>|null $bancario datos bancarios o null
     * @return int id_empleado generado
     */
    public function insert(array $persona, array $empleado, ?array $bancario): int
    {
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $idPersona = $this->insertPersona($persona);

            $stmt = $this->pdo->prepare(
                'INSERT INTO empleados
                    (id_persona, id_puesto, id_usuario, id_horario,
                     numero_asegurado_ccss, numero_poliza_ins,
                     fecha_ingreso, fecha_salida, estado)
                 VALUES
                    (:id_persona, :id_puesto, :id_usuario, :id_horario,
                     :numero_asegurado_ccss, :numero_poliza_ins,
                     :fecha_ingreso, :fecha_salida, :estado)'
            );
            $stmt->execute($this->bindEmpleado($empleado) + [':id_persona' => $idPersona]);
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
     * Actualiza persona + empleado y hace upsert de la cuenta bancaria activa, en transacción.
     *
     * @param array<string, mixed>      $persona
     * @param array<string, mixed>      $empleado
     * @param array<string, mixed>|null $bancario
     */
    public function update(int $id, int $idPersona, array $persona, array $empleado, ?array $bancario): void
    {
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->updatePersona($idPersona, $persona);

            $stmt = $this->pdo->prepare(
                'UPDATE empleados SET
                    id_puesto = :id_puesto, id_usuario = :id_usuario, id_horario = :id_horario,
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
     * Sin cambios.
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
     * @param array<string, mixed> $p
     * @return int id_persona generado
     */
    private function insertPersona(array $p): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO persona
                (cedula, nombre, apellidos, fecha_nacimiento, genero, estado_civil,
                 nacionalidad, telefono, correo, direccion, id_distrito,
                 telefono_alterno, correo_alterno, direccion_alterna, id_distrito_alterno,
                 telefono_emergencia, nombre_contacto_emergencia)
             VALUES
                (:cedula, :nombre, :apellidos, :fecha_nacimiento, :genero, :estado_civil,
                 :nacionalidad, :telefono, :correo, :direccion, :id_distrito,
                 :telefono_alterno, :correo_alterno, :direccion_alterna, :id_distrito_alterno,
                 :telefono_emergencia, :nombre_contacto_emergencia)'
        );
        $stmt->execute($this->bindPersona($p));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $p
     */
    private function updatePersona(int $idPersona, array $p): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE persona SET
                cedula = :cedula, nombre = :nombre, apellidos = :apellidos,
                fecha_nacimiento = :fecha_nacimiento, genero = :genero, estado_civil = :estado_civil,
                nacionalidad = :nacionalidad, telefono = :telefono, correo = :correo,
                direccion = :direccion, id_distrito = :id_distrito,
                telefono_alterno = :telefono_alterno, correo_alterno = :correo_alterno,
                direccion_alterna = :direccion_alterna, id_distrito_alterno = :id_distrito_alterno,
                telefono_emergencia = :telefono_emergencia,
                nombre_contacto_emergencia = :nombre_contacto_emergencia
              WHERE id_persona = :id_persona'
        );
        $params = $this->bindPersona($p);
        $params[':id_persona'] = $idPersona;
        $stmt->execute($params);
    }

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
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function bindPersona(array $p): array
    {
        return [
            ':cedula'                     => $p['cedula'],
            ':nombre'                     => $p['nombre'],
            ':apellidos'                  => $p['apellidos'],
            ':fecha_nacimiento'           => $p['fecha_nacimiento'],
            ':genero'                     => $p['genero'],
            ':estado_civil'               => $p['estado_civil'],
            ':nacionalidad'               => $p['nacionalidad'],
            ':telefono'                   => $p['telefono'],
            ':correo'                     => $p['correo'],
            ':direccion'                  => $p['direccion'],
            ':id_distrito'                => $p['id_distrito'],
            ':telefono_alterno'           => $p['telefono_alterno'],
            ':correo_alterno'             => $p['correo_alterno'],
            ':direccion_alterna'          => $p['direccion_alterna'],
            ':id_distrito_alterno'        => $p['id_distrito_alterno'],
            ':telefono_emergencia'        => $p['telefono_emergencia'],
            ':nombre_contacto_emergencia' => $p['nombre_contacto_emergencia'],
        ];
    }

    /**
     * @param array<string, mixed> $e
     * @return array<string, mixed>
     */
    private function bindEmpleado(array $e): array
    {
        return [
            ':id_puesto'             => $e['id_puesto'],
            ':id_usuario'            => $e['id_usuario'],
            ':id_horario'            => $e['id_horario'],
            ':numero_asegurado_ccss' => $e['numero_asegurado_ccss'],
            ':numero_poliza_ins'     => $e['numero_poliza_ins'],
            ':fecha_ingreso'         => $e['fecha_ingreso'],
            ':fecha_salida'          => $e['fecha_salida'],
            ':estado'                => $e['estado'],
        ];
    }
}
