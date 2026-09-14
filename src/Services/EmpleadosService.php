<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\DistritosRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\PuestosRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

/**
 * EmpleadosService
 *
 * Lógica de negocio del módulo Empleados. El Controller nunca accede al
 * Repository ni a PDO. Las fechas y la sesión llegan como parámetros.
 */
class EmpleadosService
{
    private const GENEROS       = ['masculino', 'femenino', 'otro'];
    private const ESTADOS_CIVIL = ['soltero', 'casado', 'union_libre', 'divorciado', 'viudo'];
    private const ESTADOS       = ['activo', 'inactivo'];
    private const TIPOS_CUENTA  = ['corriente', 'ahorros'];
    private const MONEDAS       = ['CRC', 'USD'];

    public function __construct(
        private readonly EmpleadosRepository $repo,
        private readonly PuestosRepository    $puestosRepo,
        private readonly DistritosRepository  $distritosRepo,
        private readonly AuditoriaRepository  $auditoriaRepo
    ) {}

    // ── Consultas ─────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function listar(?string $estado = null, ?string $q = null): array
    {
        return $this->repo->findAll($estado, $q);
    }

    /**
     * @return array<string, mixed>
     * @throws RuntimeException si no existe
     */
    public function obtener(int $id): array
    {
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new RuntimeException('Empleado no encontrado.');
        }
        return $row;
    }

    /**
     * Datos para poblar los <select> del formulario.
     *
     * @return array{puestos: array<int, array<string, mixed>>, usuarios: array<int, array<string, mixed>>, distritos: array<int, array<string, mixed>>}
     */
    public function datosFormulario(?int $currentUsuarioId = null): array
    {
        return [
            'puestos'   => $this->puestosRepo->findAll(),
            'usuarios'  => $this->repo->findUsuariosDisponibles($currentUsuarioId),
            'distritos' => $this->distritosRepo->findAllConJerarquia(),
        ];
    }

    // ── Comandos ──────────────────────────────────────────

    /**
     * @param array<string, mixed> $datos
     * @throws InvalidArgumentException
     * @return int id del nuevo empleado
     */
    public function crear(array $datos, int $loggedInId, string $ip): int
    {
        $validado = $this->validar($datos, null);

        try {
            $id = $this->repo->insert($validado['persona'], $validado['empleado'], $validado['bancario']);
        } catch (PDOException $e) {
            if (str_starts_with((string)$e->getCode(), '23')) {
                throw new InvalidArgumentException('Ya existe un empleado con esa cédula.');
            }
            throw $e;
        }

        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'empleados', $id, $ip);
        return $id;
    }

    /**
     * @param array<string, mixed> $datos
     * @throws InvalidArgumentException
     * @throws RuntimeException si no existe
     */
    public function actualizar(int $id, array $datos, int $loggedInId, string $ip): void
    {
        $current  = $this->obtener($id);
        $validado = $this->validar($datos, (int)$current['id_persona']);

        try {
            $this->repo->update($id, (int)$current['id_persona'], $validado['persona'], $validado['empleado'], $validado['bancario']);
        } catch (PDOException $e) {
            if (str_starts_with((string)$e->getCode(), '23')) {
                throw new InvalidArgumentException('Ya existe un empleado con esa cédula.');
            }
            throw $e;
        }

        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'empleados', $id, $ip);
    }

    /**
     * Soft-delete: desactiva el empleado y registra la fecha de salida.
     *
     * @throws RuntimeException si no existe
     */
    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $this->obtener($id);
        $hoy = (new DateTimeImmutable('today'))->format('Y-m-d');
        $this->repo->deactivate($id, $hoy);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'empleados', $id, $ip);
    }

    // ── Validación (función pura: array → array de columnas) ──

    /**
     * Valida y normaliza los datos de persona/empleado y sus datos bancarios.
     * Acumula TODOS los errores en un solo mensaje.
     *
     * @param array<string, mixed> $d
     * @param int|null $excludePersonaId id_persona a excluir en la verificación de cédula (al editar)
     * @return array{persona: array<string, mixed>, empleado: array<string, mixed>, bancario: array<string, mixed>|null}
     * @throws InvalidArgumentException con todos los errores concatenados
     */
    private function validar(array $d, ?int $excludePersonaId): array
    {
        $errores = [];

        $idPuesto = isset($d['id_puesto']) && is_numeric($d['id_puesto']) ? (int)$d['id_puesto'] : 0;
        if ($idPuesto <= 0 || $this->puestosRepo->findById($idPuesto) === null) {
            $errores[] = 'Debe seleccionar un puesto válido.';
        }

        $nombre = trim((string)($d['nombre'] ?? ''));
        if ($nombre === '' || mb_strlen($nombre) > 100) {
            $errores[] = 'El nombre es obligatorio (máx. 100 caracteres).';
        }

        $apellidos = trim((string)($d['apellidos'] ?? ''));
        if ($apellidos === '' || mb_strlen($apellidos) > 100) {
            $errores[] = 'Los apellidos son obligatorios (máx. 100 caracteres).';
        }

        $cedulaRaw = (string)($d['cedula'] ?? '');
        $cedula    = preg_replace('/[\s-]/', '', $cedulaRaw) ?? '';
        $longitud  = strlen($cedula);
        if ($cedula === '' || !ctype_digit($cedula) || !($longitud === 9 || $longitud === 11 || $longitud === 12)) {
            $errores[] = 'La cédula debe ser nacional (9 dígitos) o DIMEX (11-12 dígitos).';
        } elseif ($this->repo->existsByCedula($cedula, $excludePersonaId)) {
            $errores[] = "Ya existe un empleado con la cédula {$cedula}.";
        }

        $fechaNac = $this->validarFechaNacimiento((string)($d['fecha_nacimiento'] ?? ''), $errores);

        $genero = trim((string)($d['genero'] ?? ''));
        if (!in_array($genero, self::GENEROS, true)) {
            $errores[] = 'El género seleccionado no es válido.';
        }

        $estadoCivil = trim((string)($d['estado_civil'] ?? ''));
        if (!in_array($estadoCivil, self::ESTADOS_CIVIL, true)) {
            $errores[] = 'El estado civil seleccionado no es válido.';
        }

        $nacionalidad = trim((string)($d['nacionalidad'] ?? ''));
        if ($nacionalidad === '' || mb_strlen($nacionalidad) > 50) {
            $errores[] = 'La nacionalidad es obligatoria (máx. 50 caracteres).';
        }

        $fechaIngreso = trim((string)($d['fecha_ingreso'] ?? ''));
        if (DateTimeImmutable::createFromFormat('Y-m-d', $fechaIngreso) === false) {
            $errores[] = 'La fecha de ingreso es obligatoria y debe tener formato válido.';
        }

        $correo = trim((string)($d['correo'] ?? ''));
        if ($correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            $errores[] = 'El correo electrónico no tiene un formato válido.';
        } elseif (mb_strlen($correo) > 150) {
            $errores[] = 'El correo electrónico no puede superar 150 caracteres.';
        }

        $correoAlterno = trim((string)($d['correo_alterno'] ?? ''));
        if ($correoAlterno !== '' && filter_var($correoAlterno, FILTER_VALIDATE_EMAIL) === false) {
            $errores[] = 'El correo electrónico alterno no tiene un formato válido.';
        } elseif (mb_strlen($correoAlterno) > 150) {
            $errores[] = 'El correo electrónico alterno no puede superar 150 caracteres.';
        }

        $idDistrito        = $this->idDistritoValidado($d, 'id_distrito', $errores);
        $idDistritoAlterno = $this->idDistritoValidado($d, 'id_distrito_alterno', $errores);

        $estado = trim((string)($d['estado'] ?? 'activo'));
        if (!in_array($estado, self::ESTADOS, true)) {
            $estado = 'activo';
        }

        $bancario = $this->extraerBancario($d, $errores);

        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $fechaSalida = null;
        if ($estado === 'inactivo') {
            $fechaSalida = $this->fechaOpcional((string)($d['fecha_salida'] ?? ''))
                ?? (new DateTimeImmutable('today'))->format('Y-m-d');
        }

        $persona = [
            'cedula'                     => $cedula,
            'nombre'                     => $nombre,
            'apellidos'                  => $apellidos,
            'fecha_nacimiento'           => $fechaNac,
            'genero'                     => $genero,
            'estado_civil'               => $estadoCivil,
            'nacionalidad'               => $nacionalidad,
            'telefono'                   => $this->textoVacio($d, 'telefono', 20),
            'correo'                     => $correo,
            'direccion'                  => $this->textoVacio($d, 'direccion', 255),
            'id_distrito'                => $idDistrito,
            'telefono_alterno'           => $this->textoVacio($d, 'telefono_alterno', 20),
            'correo_alterno'             => $correoAlterno,
            'direccion_alterna'          => $this->textoVacio($d, 'direccion_alterna', 255),
            'id_distrito_alterno'        => $idDistritoAlterno,
            'telefono_emergencia'        => $this->textoVacio($d, 'telefono_emergencia', 20),
            'nombre_contacto_emergencia' => $this->textoVacio($d, 'nombre_contacto_emergencia', 150),
        ];

        $empleado = [
            'id_puesto'             => $idPuesto,
            'id_usuario'            => $this->idUsuarioOpcional($d),
            'id_horario'            => 1,
            'numero_asegurado_ccss' => $this->textoVacio($d, 'numero_asegurado_ccss', 20),
            'numero_poliza_ins'     => $this->textoVacio($d, 'numero_poliza_ins', 30),
            'fecha_ingreso'         => $fechaIngreso,
            'fecha_salida'          => $fechaSalida,
            'estado'                => $estado,
        ];

        return ['persona' => $persona, 'empleado' => $empleado, 'bancario' => $bancario];
    }

    /**
     * @param string[] $errores referencia acumuladora
     * @return string|null fecha normalizada o null si inválida
     */
    private function validarFechaNacimiento(string $valor, array &$errores): ?string
    {
        $valor = trim($valor);
        $fecha = DateTimeImmutable::createFromFormat('Y-m-d', $valor);
        if ($fecha === false) {
            $errores[] = 'La fecha de nacimiento es obligatoria y debe tener formato válido.';
            return null;
        }
        $hoy = new DateTimeImmutable('today');
        if ($fecha >= $hoy) {
            $errores[] = 'La fecha de nacimiento debe estar en el pasado.';
            return $valor;
        }
        $edad = $hoy->diff($fecha)->y;
        if ($edad < 15) {
            $errores[] = 'El empleado debe tener al menos 15 años (edad laboral mínima en Costa Rica).';
        }
        return $valor;
    }

    /**
     * @param string[] $errores referencia acumuladora
     */
    private function idDistritoValidado(array $d, string $clave, array &$errores): int
    {
        $valor = trim((string)($d[$clave] ?? ''));
        if ($valor === '') {
            return 1;
        }
        if (!ctype_digit($valor) || !$this->distritosRepo->exists((int)$valor)) {
            $errores[] = 'El distrito seleccionado no es válido.';
            return 1;
        }
        return (int)$valor;
    }

    /**
     * Extrae y valida los datos bancarios. Devuelve null si el bloque viene vacío.
     * Los errores se acumulan en la lista compartida $errores (no lanza por sí mismo).
     *
     * @param array<string, mixed> $d
     * @param string[] $errores referencia acumuladora
     * @return array<string, mixed>|null
     */
    private function extraerBancario(array $d, array &$errores): ?array
    {
        $banco      = trim((string)($d['banco'] ?? ''));
        $tipoCuenta = trim((string)($d['tipo_cuenta'] ?? ''));
        $numeroCta  = trim((string)($d['numero_cuenta'] ?? ''));
        $iban       = trim((string)($d['numero_cuenta_iban'] ?? ''));
        $moneda     = trim((string)($d['moneda'] ?? ''));

        $algunoLleno = ($banco !== '' || $tipoCuenta !== '' || $numeroCta !== '' || $iban !== '');
        if (!$algunoLleno) {
            return null;
        }

        if ($banco === '' || mb_strlen($banco) > 100) {
            $errores[] = 'El banco es obligatorio cuando se registran datos bancarios (máx. 100).';
        }
        if (!in_array($tipoCuenta, self::TIPOS_CUENTA, true)) {
            $errores[] = 'Debe seleccionar un tipo de cuenta válido.';
        }
        if ($numeroCta === '' || mb_strlen($numeroCta) > 30) {
            $errores[] = 'El número de cuenta es obligatorio (máx. 30 caracteres).';
        }
        if ($iban !== '' && mb_strlen($iban) > 22) {
            $errores[] = 'El IBAN no puede superar 22 caracteres.';
        }
        if ($moneda === '') {
            $moneda = 'CRC';
        } elseif (!in_array($moneda, self::MONEDAS, true)) {
            $errores[] = 'La moneda seleccionada no es válida.';
        }

        return [
            'banco'              => $banco,
            'tipo_cuenta'        => $tipoCuenta,
            'numero_cuenta'      => $numeroCta,
            'numero_cuenta_iban' => $iban !== '' ? $iban : null,
            'moneda'             => $moneda,
        ];
    }

    // ── Helpers de normalización ──────────────────────────

    /**
     * Como un texto opcional, pero devuelve '' en vez de null
     * (columnas de persona/empleados son NOT NULL DEFAULT '').
     *
     * @param array<string, mixed> $d
     */
    private function textoVacio(array $d, string $clave, int $max): string
    {
        $valor = trim((string)($d[$clave] ?? ''));
        return mb_substr($valor, 0, $max);
    }

    private function fechaOpcional(string $valor): ?string
    {
        $valor = trim($valor);
        return DateTimeImmutable::createFromFormat('Y-m-d', $valor) !== false ? $valor : null;
    }

    /**
     * @param array<string, mixed> $d
     */
    private function idUsuarioOpcional(array $d): ?int
    {
        $valor = $d['id_usuario'] ?? '';
        return ($valor !== '' && is_numeric($valor)) ? (int)$valor : null;
    }
}
