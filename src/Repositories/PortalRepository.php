<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * PortalRepository — consultas del empleado autenticado.
 * Toda consulta filtra por empleados.id_usuario en el propio SQL:
 * un empleado nunca puede leer datos de otro aunque manipule IDs.
 */
class PortalRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function idEmpleadoPorUsuario(int $idUsuario): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_empleado FROM empleados WHERE id_usuario = :u LIMIT 1'
        );
        $stmt->execute([':u' => $idUsuario]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    /** Perfil del empleado vinculado al usuario. @return array<string, mixed>|null */
    public function perfil(int $idUsuario): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.*, p.nombre AS puesto, p.salario_base AS salario_puesto
               FROM empleados e
               JOIN puestos p ON p.id_puesto = e.id_puesto
              WHERE e.id_usuario = :u LIMIT 1'
        );
        $stmt->execute([':u' => $idUsuario]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** Cuentas bancarias activas del empleado. @return array<int, array<string, mixed>> */
    public function datosBancarios(int $idEmpleado): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT banco, tipo_cuenta, numero_cuenta, numero_cuenta_iban, moneda
               FROM datos_bancarios
              WHERE id_empleado = :e AND activa = 1
              ORDER BY id_datos_bancarios'
        );
        $stmt->execute([':e' => $idEmpleado]);
        return $stmt->fetchAll();
    }

    /** Nóminas visibles como colilla (aprobado/pagado). @return array<int, array<string, mixed>> */
    public function colillas(int $idUsuario): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT n.id_nomina, n.salario_bruto, n.total_deducciones, n.salario_neto, n.estado,
                    p.fecha_inicio, p.fecha_fin
               FROM nominas n
               JOIN empleados e ON e.id_empleado = n.id_empleado
               JOIN periodos_pago p ON p.id_periodo = n.id_periodo
              WHERE e.id_usuario = :u AND n.estado IN ('aprobado', 'pagado')
              ORDER BY p.fecha_inicio DESC"
        );
        $stmt->execute([':u' => $idUsuario]);
        return $stmt->fetchAll();
    }

    /** Cabecera de una colilla propia; null si el id no pertenece al usuario. @return array<string, mixed>|null */
    public function colilla(int $idNomina, int $idUsuario): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT n.*, e.nombre, e.apellidos, e.cedula, pu.nombre AS puesto,
                    p.fecha_inicio, p.fecha_fin
               FROM nominas n
               JOIN empleados e ON e.id_empleado = n.id_empleado
               JOIN puestos pu ON pu.id_puesto = e.id_puesto
               JOIN periodos_pago p ON p.id_periodo = n.id_periodo
              WHERE n.id_nomina = :id AND e.id_usuario = :u
                AND n.estado IN ('aprobado', 'pagado')
              LIMIT 1"
        );
        $stmt->execute([':id' => $idNomina, ':u' => $idUsuario]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function ingresosColilla(int $idNomina): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT tipo, descripcion, monto FROM ingresos_nomina
              WHERE id_nomina = :id ORDER BY id_ingreso'
        );
        $stmt->execute([':id' => $idNomina]);
        return $stmt->fetchAll();
    }

    /** Deducciones del empleado (excluye la CCSS patronal, que es informativa). @return array<int, array<string, mixed>> */
    public function deduccionesColilla(int $idNomina): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT tipo, descripcion, porcentaje, monto FROM deducciones_nomina
              WHERE id_nomina = :id AND tipo <> 'CCSS_patronal' ORDER BY id_deduccion"
        );
        $stmt->execute([':id' => $idNomina]);
        return $stmt->fetchAll();
    }

    /** Saldo de vacaciones por año. @return array<int, array<string, mixed>> */
    public function saldosVacaciones(int $idUsuario): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.anio, s.dias_ganados, s.dias_disfrutados, s.dias_disponibles
               FROM saldo_vacaciones s
               JOIN empleados e ON e.id_empleado = s.id_empleado
              WHERE e.id_usuario = :u
              ORDER BY s.anio DESC'
        );
        $stmt->execute([':u' => $idUsuario]);
        return $stmt->fetchAll();
    }

    /** Vacaciones disfrutadas. @return array<int, array<string, mixed>> */
    public function vacacionesDisfrutadas(int $idUsuario): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT v.fecha_inicio, v.fecha_fin, v.dias_tomados
               FROM vacaciones v
               JOIN empleados e ON e.id_empleado = v.id_empleado
              WHERE e.id_usuario = :u
              ORDER BY v.fecha_inicio DESC'
        );
        $stmt->execute([':u' => $idUsuario]);
        return $stmt->fetchAll();
    }
}
