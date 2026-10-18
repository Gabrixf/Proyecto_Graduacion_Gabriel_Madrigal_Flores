<?php
// ============================================================
// Datos de demostración para la base local (hoy de referencia = 12/10/2026).
// Llena las 29 tablas con un caso coherente de Lubrimotos del Sur: 7 empleados
// (incluye RRHH, uno recién ingresado y un exempleado con liquidación), asistencia,
// solicitudes, nóminas de mayo a octubre, aguinaldo, evaluaciones y bitácora.
//
// Donde hay cálculos legales (nómina, CCSS, horas extra, aguinaldo, liquidación,
// evaluaciones) usa los services del sistema, así los montos son los mismos que
// produciría la aplicación. Lo demás se inserta por SQL.
//
// Uso (después de importar schema.sql y seed.sql en una base vacía):
//     php database/demo.php                 # usa DB_NAME del .env
//     php database/demo.php otra_base       # en otra base (por ejemplo, para probar)
//
// No corre en producción ni sobre una base que ya tenga los datos de demostración.
// Todos los usuarios usan la contraseña del seed: Test1234!
// ============================================================
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
date_default_timezone_set('America/Costa_Rica');
if (isset($argv[1]) && $argv[1] !== '') {
    $_ENV['DB_NAME'] = $_SERVER['DB_NAME'] = $argv[1];   // Dotenv inmutable no lo sobrescribe
}
Dotenv\Dotenv::createImmutable($root)->safeLoad();
$settings = require $root . '/config/settings.php';
if (($_ENV['APP_ENV'] ?? 'development') === 'production') {
    fwrite(STDERR, "demo.php no se ejecuta en producción.\n");
    exit(1);
}
$builder = new DI\ContainerBuilder();
$builder->addDefinitions(['settings' => $settings]);
$builder->addDefinitions(require $root . '/config/dependencies.php');
$c   = $builder->build();
$pdo = $c->get(PDO::class);
$IP  = '127.0.0.1';
mt_srand(2026);   // datos repetibles

if ((int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE nombre_usuario = 'dvargas'")->fetchColumn() > 0) {
    fwrite(STDERR, "La base ya tiene los datos de demostración (existe el usuario dvargas).\n");
    exit(1);
}
echo 'Base: ', $pdo->query('SELECT DATABASE()')->fetchColumn(), "\n";

function q(PDO $pdo, string $sql, array $p = []): PDOStatement { $s = $pdo->prepare($sql); $s->execute($p); return $s; }
function one(PDO $pdo, string $sql, array $p = []): mixed { return q($pdo, $sql, $p)->fetchColumn(); }
function paso(string $t): void { echo "· $t\n"; }

// ── 1. Catálogos ─────────────────────────────────────────────
paso('Departamentos, puestos, horarios y parámetros legales');
$dep = [];
foreach (['Administración', 'Taller', 'Ventas'] as $n) {
    q($pdo, 'INSERT INTO departamentos (nombre) VALUES (?)', [$n]);
    $dep[$n] = (int)$pdo->lastInsertId();
}
foreach (['Mecánico General' => 'Taller', 'Mecánico Especialista' => 'Taller', 'Vendedor' => 'Ventas',
          'Administrador' => 'Administración', 'Recepcionista' => 'Ventas'] as $puesto => $d) {
    q($pdo, 'UPDATE puestos SET id_departamento = ? WHERE nombre = ?', [$dep[$d], $puesto]);
}
q($pdo, 'INSERT INTO puestos (id_departamento, nombre, salario_base, descripcion) VALUES (?,?,?,?)',
    [$dep['Administración'], 'Encargada de RRHH', 550000, 'Solicitudes, asistencia y planilla del personal.']);
$puestoRrhh = (int)$pdo->lastInsertId();

q($pdo, "INSERT INTO horarios (nombre, dias_semana) VALUES ('Taller L-V y sábado medio día', 'L-V + S medio día')");
$horario = (int)$pdo->lastInsertId();
foreach ([1, 2, 3, 4, 5] as $dia) {
    q($pdo, "INSERT INTO horario_dias (id_horario, dia_semana, hora_entrada, hora_salida, minutos_almuerzo) VALUES (?, ?, '08:00:00', '17:00:00', 60)", [$horario, $dia]);
}
q($pdo, "INSERT INTO horario_dias (id_horario, dia_semana, hora_entrada, hora_salida, minutos_almuerzo) VALUES (?, 6, '08:00:00', '12:00:00', 0)", [$horario]);

foreach (['ccss_obrera' => 0.1067, 'ccss_patronal' => 0.2633, 'factor_he_ord' => 1.50, 'factor_he_especial' => 3.00] as $k => $v) {
    q($pdo, "INSERT INTO parametros_legales (clave, valor, fecha_inicio) VALUES (?, ?, '2026-01-01')", [$k, $v]);
}

// ── 2. Personas nuevas: RRHH y un exempleado ─────────────────
paso('Daniela (RRHH) y Andrés (exempleado)');
$hash = one($pdo, "SELECT contrasena_hash FROM usuarios WHERE nombre_usuario = 'jperez'");
function nuevoEmpleado(PDO $pdo, string $hash, array $u, array $p, array $e, array $contrato, array $banco): int {
    q($pdo, 'INSERT INTO usuarios (nombre_usuario, contrasena_hash, rol, activo) VALUES (?, ?, ?, 1)', [$u[0], $hash, $u[1]]);
    $idU = (int)$pdo->lastInsertId();
    q($pdo, 'INSERT INTO persona (cedula, nombre, apellidos, fecha_nacimiento, genero, estado_civil, nacionalidad,
                 telefono, correo, direccion, id_distrito, telefono_emergencia, nombre_contacto_emergencia)
             VALUES (?,?,?,?,?,?,?,?,?,?,2,?,?)', $p);
    $idP = (int)$pdo->lastInsertId();
    q($pdo, 'INSERT INTO empleados (id_persona, id_puesto, id_usuario, id_horario, numero_asegurado_ccss, fecha_ingreso, estado)
             VALUES (?,?,?,?,?,?, \'activo\')', [$idP, $e[0], $idU, $e[1], $e[2], $e[3]]);
    $idE = (int)$pdo->lastInsertId();
    q($pdo, "INSERT INTO contratos (id_empleado, tipo_contrato, salario_pactado, jornada, fecha_inicio)
             VALUES (?, 'tiempo_indefinido', ?, 'tiempo_completo', ?)", [$idE, $contrato[0], $e[3]]);
    q($pdo, 'INSERT INTO datos_bancarios (id_empleado, banco, tipo_cuenta, numero_cuenta, numero_cuenta_iban) VALUES (?,?,?,?,?)',
        array_merge([$idE], $banco));
    return $idE;
}
// Carlos (recién ingresado, en período de prueba) y Luis (7 años, con historial de vacaciones).
if (!one($pdo, "SELECT COUNT(*) FROM usuarios WHERE nombre_usuario = 'cjimenez'")) {
    paso('Carlos (nuevo) y Luis (con antigüedad)');
    nuevoEmpleado($pdo, $hash, ['cjimenez', 'empleado'],
        ['1-1567-0234', 'Carlos', 'Jiménez Vargas', '2001-07-22', 'masculino', 'soltero', 'Costarricense', '8712-3344',
         'cjimenez@lubrimotos.cr', 'Barrio Amón, 200 m norte del parque', '8765-1122', 'Ana Vargas (madre)'],
        [3, $horario, '115670234', '2026-09-15'], [380000], ['Banco Nacional de Costa Rica', 'ahorros', '200-01-026-284066', 'CR05015202001026284066']);
    $luis = nuevoEmpleado($pdo, $hash, ['lrojas', 'empleado'],
        ['1-0789-0456', 'Luis', 'Rojas Campos', '1986-03-09', 'masculino', 'casado', 'Costarricense', '8834-5566',
         'lrojas@lubrimotos.cr', 'Del Hospital San Juan de Dios 300 m sur', '8899-7788', 'Karla Mena (esposa)'],
        [2, $horario, '107890456', '2019-02-04'], [600000], ['Banco de Costa Rica', 'ahorros', '001-0458921-3', 'CR21015201001045892131']);
    q($pdo, "UPDATE empleados SET numero_poliza_ins = 'INS-RT-2019-0458' WHERE id_empleado = ?", [$luis]);
    foreach ([['2019-12-16', '2019-12-25'], ['2020-12-14', '2020-12-25'], ['2021-12-13', '2021-12-24'], ['2022-12-12', '2022-12-23'],
              ['2023-12-11', '2023-12-22'], ['2024-12-16', '2024-12-27'], ['2025-12-15', '2025-12-26']] as [$i, $f]) {
        q($pdo, "INSERT INTO solicitudes (id_empleado, tipo, fecha_inicio, fecha_fin, motivo, estado, fecha_solicitud, fecha_resolucion, id_usuario_resuelve)
                 VALUES (?, 'vacaciones', ?, ?, 'Vacaciones de fin de año', 'aprobada', ?, ?, 2)",
            [$luis, $i, $f, date('Y-m-d 09:00:00', strtotime("$i -28 days")), date('Y-m-d 10:00:00', strtotime("$i -27 days"))]);
    }
    q($pdo, "INSERT INTO solicitudes (id_empleado, tipo, fecha_inicio, fecha_fin, motivo, estado)
             VALUES (?, 'vacaciones', '2026-12-21', '2026-12-23', 'Fin de año con la familia', 'pendiente')", [$luis]);
}
$daniela = nuevoEmpleado($pdo, $hash, ['dvargas', 'super_admin'],
    ['2-0612-0345', 'Daniela', 'Vargas Solano', '1990-11-03', 'femenino', 'casado', 'Costarricense', '8601-2233',
     'dvargas@lubrimotos.cr', 'San Francisco de Dos Ríos, 100 m este de la iglesia', '8602-4455', 'Mario Solano (esposo)'],
    [$puestoRrhh, $horario, '206120345', '2022-08-01'], [550000], ['Banco Nacional de Costa Rica', 'ahorros', '200-01-044-118923', 'CR77015100020019811892']);
$andres = nuevoEmpleado($pdo, $hash, ['amora', 'empleado'],
    ['1-1402-0789', 'Andrés', 'Mora Quesada', '1998-02-17', 'masculino', 'soltero', 'Costarricense', '8455-6677',
     'amora@lubrimotos.cr', 'Hatillo 4, casa 23', '8455-9900', 'Rosa Quesada (madre)'],
    [5, $horario, '114020789', '2023-03-06'], [350000], ['BAC Credomatic', 'ahorros', '934512786', 'CR42010200009345127861']);

$emp = [];   // nombre de usuario o apodo => id_empleado
foreach (q($pdo, 'SELECT e.id_empleado, per.nombre FROM empleados e JOIN persona per USING (id_persona)')->fetchAll() as $r) {
    $emp[$r['nombre']] = (int)$r['id_empleado'];
}
q($pdo, 'UPDATE empleados SET id_horario = ?', [$horario]);

paso('Datos bancarios y contacto de los empleados que no tenían');
$bancos = ['Emerson' => ['Banco de Costa Rica', 'corriente', '001-0234567-8', 'CR05015201001023456789'],
           'Juan'    => ['Banco Nacional de Costa Rica', 'ahorros', '200-01-031-552210', 'CR13015100020013155221'],
           'María'   => ['Banco Popular', 'ahorros', '161-01-077-003412', 'CR90016111107700341201']];
foreach ($bancos as $n => $b) {
    if (!one($pdo, 'SELECT COUNT(*) FROM datos_bancarios WHERE id_empleado = ?', [$emp[$n]])) {
        q($pdo, 'INSERT INTO datos_bancarios (id_empleado, banco, tipo_cuenta, numero_cuenta, numero_cuenta_iban) VALUES (?,?,?,?,?)',
            array_merge([$emp[$n]], $b));
    }
}
q($pdo, "UPDATE persona p JOIN empleados e USING (id_persona) SET p.telefono = '8890-1122', p.correo = 'jperez@lubrimotos.cr',
         p.telefono_emergencia = '8890-3344', p.nombre_contacto_emergencia = 'Laura Solís (esposa)' WHERE e.id_empleado = ?", [$emp['Juan']]);
q($pdo, "UPDATE persona p JOIN empleados e USING (id_persona) SET p.telefono = '8765-4321', p.correo = 'mgonzalez@lubrimotos.cr',
         p.telefono_emergencia = '8765-0000', p.nombre_contacto_emergencia = 'Ana Mora (madre)' WHERE e.id_empleado = ?", [$emp['María']]);
q($pdo, "UPDATE empleados SET numero_asegurado_ccss = CASE id_empleado WHEN ? THEN '102340567' WHEN ? THEN '103450678' WHEN ? THEN '104560789' ELSE numero_asegurado_ccss END",
    [$emp['Emerson'], $emp['Juan'], $emp['María']]);

paso('Direcciones, distritos y contactos');
$personales = [ // cédula => [dirección, id_distrito, teléfono alterno, correo alterno, tel. emergencia, contacto emergencia]
    '1-0234-5678' => ['Barrio Escalante, de la iglesia Santa Teresita 150 m este', 2, '2222-4411', 'emerson.fernandez@gmail.com', '8888-2244', 'Patricia Maroto (esposa)'],
    '1-0345-6789' => ['Zapote, 75 m sur del Registro Nacional, casa portón negro', 6, '', 'juanperez.s@gmail.com', '8890-3344', 'Laura Solís (esposa)'],
    '1-0456-7890' => ['Pavas, Rohrmoser, condominio Los Robles, apto 12', 10, '8765-1199', '', '8765-0000', 'Ana Mora (madre)'],
    '1-1567-0234' => ['Barrio Amón, 200 m norte del parque', 2, '', 'carlosjv01@gmail.com', '8765-1122', 'Ana Vargas (madre)'],
    '1-0789-0456' => ['Del Hospital San Juan de Dios 300 m sur', 4, '2233-7788', '', '8899-7788', 'Karla Mena (esposa)'],
    '2-0612-0345' => ['San Francisco de Dos Ríos, 100 m este de la iglesia', 7, '', 'daniela.vargas.s@gmail.com', '8602-4455', 'Mario Solano (esposo)'],
    '1-1402-0789' => ['Hatillo 4, casa 23', 11, '', '', '8455-9900', 'Rosa Quesada (madre)'],
];
foreach ($personales as $cedula => [$dir, $dist, $telAlt, $correoAlt, $telEm, $contacto]) {
    q($pdo, 'UPDATE persona SET direccion = ?, id_distrito = ?, telefono_alterno = ?, correo_alterno = ?,
                                telefono_emergencia = ?, nombre_contacto_emergencia = ?
              WHERE cedula = ?', [$dir, $dist, $telAlt, $correoAlt, $telEm, $contacto, $cedula]);
}

// ── 3. Períodos de pago (junio a la primera quincena de octubre) ──
paso('Períodos de pago');
$quincenas = [['2026-06-01','2026-06-15'],['2026-06-16','2026-06-30'],['2026-07-01','2026-07-15'],['2026-07-16','2026-07-31'],
              ['2026-08-01','2026-08-15'],['2026-08-16','2026-08-31'],['2026-09-01','2026-09-15'],['2026-09-16','2026-09-30'],
              ['2026-10-01','2026-10-15']];
foreach ($quincenas as [$i, $f]) {
    q($pdo, "INSERT INTO periodos_pago (fecha_inicio, fecha_fin, estado) VALUES (?, ?, 'abierto')", [$i, $f]);
}
$periodos = q($pdo, 'SELECT id_periodo, fecha_inicio, fecha_fin FROM periodos_pago ORDER BY fecha_inicio')->fetchAll();
$periodoDe = function (string $fecha) use ($periodos): int {
    foreach ($periodos as $p) { if ($fecha >= $p['fecha_inicio'] && $fecha <= $p['fecha_fin']) return (int)$p['id_periodo']; }
    throw new RuntimeException("Sin período para $fecha");
};

// ── 4. Historial de vacaciones (diciembres) para que el saldo por antigüedad sea realista ──
paso('Historial de vacaciones aprobadas');
$histVac = function (int $idE, int $desde, int $hasta, int $dias) use ($pdo) {
    $ingreso = new DateTimeImmutable((string)one($pdo, 'SELECT fecha_ingreso FROM empleados WHERE id_empleado = ?', [$idE]));
    for ($a = $desde; $a <= $hasta; $a++) {
        $ini = "$a-12-15";
        // El primer diciembre no puede pasar de los días acumulados a esa fecha (1 por mes completo).
        $n   = $a === $desde ? min($dias, (int)(new App\Helpers\AcumulacionVacaciones())->diasGanados($ingreso, new DateTimeImmutable($ini))) : $dias;
        $fin = date('Y-m-d', strtotime("$ini +" . ($n - 1) . ' days'));
        q($pdo, "INSERT INTO solicitudes (id_empleado, tipo, fecha_inicio, fecha_fin, motivo, estado, fecha_solicitud, fecha_resolucion, id_usuario_resuelve)
                 VALUES (?, 'vacaciones', ?, ?, 'Vacaciones de fin de año', 'aprobada', ?, ?, 2)",
            [$idE, $ini, $fin, "$a-11-17 09:00:00", "$a-11-18 10:30:00"]);
    }
};
$histVac($emp['Emerson'], 2015, 2025, 12);
$histVac($emp['Juan'], 2020, 2025, 10);
$histVac($emp['María'], 2021, 2025, 10);
$histVac($daniela, 2022, 2025, 10);
$histVac($andres, 2023, 2025, 10);

// ── 5. Solicitudes de 2026 aprobadas (insertadas con su fecha real) y sus registros ──
$usrDaniela = (int)one($pdo, 'SELECT id_usuario FROM empleados WHERE id_empleado = ?', [$daniela]);
$solAprobada = function (int $idE, string $tipo, string $ini, string $fin, float $horas, string $motivo, string $pedida) use ($pdo, $usrDaniela): int {
    q($pdo, "INSERT INTO solicitudes (id_empleado, tipo, fecha_inicio, fecha_fin, horas, motivo, estado, fecha_solicitud, fecha_resolucion, id_usuario_resuelve)
             VALUES (?,?,?,?,?,?, 'aprobada', ?, ?, ?)",
        [$idE, $tipo, $ini, $fin, $horas, $motivo, "$pedida 08:30:00", "$pedida 15:00:00", $usrDaniela]);
    return (int)$pdo->lastInsertId();
};
$he   = $c->get(App\Services\HorasExtraService::class);
$vac  = $c->get(App\Services\VacacionesService::class);
$perm = $c->get(App\Services\PermisosService::class);
$inc  = $c->get(App\Services\IncapacidadesService::class);

paso('Horas extra, vacaciones, permisos e incapacidades (con los services)');
$extras = [ // empleado, fecha, horas, motivo
    ['Luis', '2026-07-10', 3, 'Entrega urgente de motocicleta de flotilla'],   // anterior a la asistencia de la demo: registro manual
];
foreach ($extras as [$n, $f, $h, $m]) {
    $idS = $solAprobada($emp[$n], 'horas_extra', $f, $f, $h, $m, date('Y-m-d', strtotime("$f -1 day")));
    $he->crear(['id_solicitud' => $idS, 'cantidad_horas' => '', 'factor_recargo' => ''], $usrDaniela, $IP);
}
$vacaciones2026 = [['Juan', '2026-08-03', '2026-08-07'], ['María', '2026-07-20', '2026-07-24']];
foreach ($vacaciones2026 as [$n, $i, $f]) {
    $idS = $solAprobada($emp[$n], 'vacaciones', $i, $f, 0, 'Vacaciones de medio año', date('Y-m-d', strtotime("$i -20 days")));
    $vac->crear(['id_solicitud' => $idS, 'dias_tomados' => ''], $usrDaniela, $IP);
}
$permisos2026 = [[$daniela, '2026-09-25', '1', 'Cita médica en la CCSS'], [$emp['María'], '2026-10-02', '0', 'Trámite personal']];
foreach ($permisos2026 as [$idE, $f, $goce, $m]) {
    $idS = $solAprobada($idE, 'permiso', $f, $f, 0, $m, date('Y-m-d', strtotime("$f -3 days")));
    $perm->crear(['id_solicitud' => $idS, 'con_goce_salarial' => $goce], $usrDaniela, $IP);
}
$inc->crear(['id_empleado' => $emp['María'], 'tipo' => 'CCSS', 'fecha_inicio' => '2026-09-08', 'fecha_fin' => '2026-09-10',
             'dias' => '', 'documento_respaldo' => 'Boleta CCSS 2026-118745'], $usrDaniela, $IP);
$inc->crear(['id_empleado' => $emp['Luis'], 'tipo' => 'INS', 'fecha_inicio' => '2026-06-22', 'fecha_fin' => '2026-06-24',
             'dias' => '', 'documento_respaldo' => 'Caso INS RT-2026-0912 (golpe en la mano)'], $usrDaniela, $IP);

// Bloque que también va en database/demo.php: quincenas de diciembre de años anteriores y el registro de
// cada vacación histórica aprobada, para que aparezca en "Mis vacaciones". Se usa el repositorio (que también
// ajusta saldo_vacaciones) y no VacacionesService::crear, porque la validación de saldo del service cuenta
// todas las vacaciones ya aprobadas, incluso las de años posteriores: sirve para el uso diario, no para cargar historia.
paso('Períodos de diciembre y vacaciones históricas registradas');
$historicas = q($pdo, "SELECT s.id_solicitud, s.id_empleado, s.fecha_inicio, s.fecha_fin FROM solicitudes s
                        WHERE s.tipo = 'vacaciones' AND s.estado = 'aprobada' AND s.fecha_inicio < '2026-01-01'
                          AND NOT EXISTS (SELECT 1 FROM vacaciones v WHERE v.id_solicitud = s.id_solicitud)
                        ORDER BY s.fecha_inicio")->fetchAll();
$diciembres = 0;
foreach (array_unique(array_map(fn ($h) => substr($h['fecha_inicio'], 0, 4), $historicas)) as $a) {
    foreach ([["$a-12-01", "$a-12-15"], ["$a-12-16", "$a-12-31"]] as [$i, $f]) {
        if (!one($pdo, 'SELECT COUNT(*) FROM periodos_pago WHERE fecha_inicio <= ? AND fecha_fin >= ?', [$f, $i])) {
            q($pdo, "INSERT INTO periodos_pago (fecha_inicio, fecha_fin, estado) VALUES (?, ?, 'cerrado')", [$i, $f]);
            $diciembres++;
        }
    }
}
$vacRepo = $c->get(App\Repositories\VacacionesRepository::class);
foreach ($historicas as $h) {
    $idPeriodo = (int)one($pdo, 'SELECT id_periodo FROM periodos_pago WHERE ? BETWEEN fecha_inicio AND fecha_fin LIMIT 1', [$h['fecha_inicio']]);
    $idV = $vacRepo->insert([
        'id_solicitud' => (int)$h['id_solicitud'], 'id_empleado' => (int)$h['id_empleado'], 'id_periodo' => $idPeriodo,
        'fecha_inicio' => $h['fecha_inicio'], 'fecha_fin' => $h['fecha_fin'],
        'dias_tomados' => (float)((strtotime($h['fecha_fin']) - strtotime($h['fecha_inicio'])) / 86400 + 1),
        'anio' => (int)substr($h['fecha_inicio'], 0, 4),
    ]);
    $c->get(App\Repositories\AuditoriaRepository::class)->insert('INSERT', $usrDaniela, 'vacaciones', $idV, $IP, 'Registro histórico');
}
echo '  ', $diciembres, ' quincenas de diciembre y ', count($historicas), " vacaciones registradas\n";

// Una solicitud rechazada (pasada) y pendientes futuras (estas sí con el service).
q($pdo, "INSERT INTO solicitudes (id_empleado, tipo, fecha_inicio, fecha_fin, motivo, estado, fecha_solicitud, fecha_resolucion, id_usuario_resuelve, observacion_admin)
         VALUES (?, 'vacaciones', '2026-09-28', '2026-10-02', 'Viaje familiar', 'rechazada', '2026-09-10 09:00:00', '2026-09-11 11:00:00', ?, 'Semana de mucho trabajo en el taller; reprogramar.')",
    [$emp['Juan'], $usrDaniela]);
$sol = $c->get(App\Services\SolicitudesService::class);
$sol->crear(['id_empleado' => $emp['Carlos'], 'tipo' => 'permiso', 'fecha_inicio' => '2026-10-20', 'fecha_fin' => '2026-10-20',
             'motivo' => 'Trámite en el Registro Civil'], $usrDaniela, $IP);
$sol->crear(['id_empleado' => $emp['Juan'], 'tipo' => 'vacaciones', 'fecha_inicio' => '2026-10-26', 'fecha_fin' => '2026-10-30',
             'motivo' => 'Viaje familiar (reprogramado)'], $usrDaniela, $IP);

// ── 6. Asistencia del 1/8 al 12/10 ───────────────────────────
paso('Asistencia diaria (agosto a hoy)');
$ausencias = []; // id_empleado => [fecha => true]
$marcarRango = function (int $idE, string $i, string $f) use (&$ausencias) {
    for ($d = $i; $d <= $f; $d = date('Y-m-d', strtotime("$d +1 day"))) { $ausencias[$idE][$d] = true; }
};
$marcarRango($emp['Juan'], '2026-08-03', '2026-08-07');
$marcarRango($emp['María'], '2026-09-08', '2026-09-10');
$marcarRango($daniela, '2026-09-25', '2026-09-25');
$marcarRango($emp['María'], '2026-10-02', '2026-10-02');
$marcarRango($emp['Carlos'], '2026-10-10', '2026-10-10');   // olvidó marcar ese sábado
$detectadas = [ // empleado, fecha, opción, motivo, acción del admin
    ['Juan',  '2026-08-22', 'horas_extra', 'Cierre de reparación de motor',      'aprobar'],
    ['María', '2026-09-15', 'horas_extra', 'Atención de clientes en feriado',     'aprobar'],
    ['Luis',  '2026-10-06', 'horas_extra', 'Diagnóstico eléctrico pendiente',    'pendiente'],
    ['Juan',  '2026-10-09', 'despues',     '',                                   'pendiente'],
    ['Luis',  '2026-09-03', 'horas_extra', 'Ordenar la bodega',                  'rechazar'],
];
$extraPorDia = [];
foreach ($extras as [$n, $f, $h]) { $extraPorDia[$emp[$n]][$f] = $h; }
foreach ($detectadas as [$n, $f]) { $extraPorDia[$emp[$n]][$f] = 2; }
$feriados = [];
foreach (q($pdo, "SELECT id_feriado, fecha, tipo FROM feriados WHERE id_feriado <> 1")->fetchAll() as $r) { $feriados[$r['fecha']] = $r; }
$ingresos = [];
foreach (q($pdo, 'SELECT id_empleado, fecha_ingreso FROM empleados')->fetchAll() as $r) { $ingresos[(int)$r['id_empleado']] = $r['fecha_ingreso']; }
$asis = $c->get(App\Repositories\AsistenciaRepository::class);
$hm = fn (int $min) => sprintf('%02d:%02d:00', intdiv($min, 60), $min % 60);
for ($d = '2026-08-01'; $d <= '2026-10-12'; $d = date('Y-m-d', strtotime("$d +1 day"))) {
    $dow = (int)date('N', strtotime($d));
    if ($dow === 7) continue;
    foreach ($ingresos as $idE => $ing) {
        if ($idE === $andres && $d > '2026-08-31') continue;
        if ($d < $ing || isset($ausencias[$idE][$d])) continue;
        $fer = $feriados[$d] ?? null;
        $trabajaFeriado = isset($extraPorDia[$idE][$d]);
        if ($fer && $fer['tipo'] === 'obligatorio_pago' && !$trabajaFeriado) continue;
        $entrada = ($dow === 6 ? 480 : 468) + mt_rand(0, 22);                       // 7:48–8:10
        $salida  = ($dow === 6 ? 720 : 1020) + mt_rand(0, 12) + (int)(($extraPorDia[$idE][$d] ?? 0) * 60);
        if ($trabajaFeriado && $fer) { $entrada = 480; $salida = 480 + (int)($extraPorDia[$idE][$d] * 60); }
        if ($idE === $emp['María'] && $d === '2026-09-15') { $salida = 19 * 60; }                              // feriado trabajado: 2 h extra a x3
        $manual = 0;
        if ($d === '2026-10-12' || ($idE === $emp['Juan'] && $d === '2026-10-08')) { $salida = 0; }          // hoy / salida olvidada
        if ($idE === $emp['Luis'] && $d === '2026-10-07') { $salida = 1020; $manual = 1; }                     // salida escrita por él
        if ($d === '2026-10-12' && in_array($idE, [$emp['María'], $emp['Carlos']], true)) continue;           // aún no llegan
        $horas = $salida > 0 ? round(($salida - $entrada) / 60, 2) : 0.0;
        $asis->insert(['id_empleado' => $idE, 'id_periodo' => $periodoDe($d), 'id_feriado' => $fer ? (int)$fer['id_feriado'] : 1,
            'fecha' => $d, 'hora_entrada' => $hm($entrada), 'hora_salida' => $salida > 0 ? $hm($salida) : '00:00:00',
            'horas_trabajadas' => $horas, 'salida_manual' => $manual]);
    }
}

paso('Horas extra detectadas por la asistencia');
$det = $c->get(App\Services\DeteccionHorasExtraService::class);
$apr = $c->get(App\Services\AprobacionHorasExtraService::class);
$solRepo = $c->get(App\Repositories\SolicitudesRepository::class);
foreach ($detectadas as [$n, $f, $op, $m, $accion]) {
    $a = $asis->findByEmpleadoFecha($emp[$n], $f);
    if ($a === null) { continue; }
    $det->registrar($emp[$n], $f, (string)$a['hora_entrada'], (string)$a['hora_salida'], $op, $m, $usrDaniela, $IP);
    $s = $solRepo->findDetectada($emp[$n], $f);
    if ($s === null) { continue; }
    if ($accion === 'aprobar')  { $apr->aprobar((int)$s['id_solicitud'], (float)$s['horas'], $usrDaniela, $IP); }
    if ($accion === 'rechazar') { $sol->rechazar((int)$s['id_solicitud'], $usrDaniela, $IP, 'Ordenar la bodega no requiere horas extra.'); }
}

// ── 7. Nóminas por período, con el service (CCSS y totales del sistema) ──
paso('Nóminas de mayo a octubre');
$nom = $c->get(App\Services\NominasService::class);
$setEstado = fn (int $idE, string $e) => q($pdo, 'UPDATE empleados SET estado = ? WHERE id_empleado = ?', [$e, $idE]);
$setEstado($emp['Carlos'], 'inactivo');
foreach ($periodos as $p) {
    $id = (int)$p['id_periodo'];
    if ($p['fecha_inicio'] === '2026-09-01') { $setEstado($andres, 'inactivo'); }
    if ($p['fecha_inicio'] === '2026-09-16') { $setEstado($emp['Carlos'], 'activo'); }
    $nom->generarPeriodo($id, $usrDaniela, $IP);
    if ($p['fecha_inicio'] >= '2026-10-01') { continue; }   // la quincena en curso queda en borrador
    foreach (q($pdo, 'SELECT id_nomina, estado FROM nominas WHERE id_periodo = ?', [$id])->fetchAll() as $n) {
        if ($n['estado'] === 'borrador') { $nom->aprobar((int)$n['id_nomina'], $usrDaniela, $IP); }
        if ($n['estado'] !== 'pagado')   { $nom->marcarPagado((int)$n['id_nomina'], $usrDaniela, $IP); }
    }
    $c->get(App\Services\PeriodosService::class)->actualizar($id,
        ['fecha_inicio' => $p['fecha_inicio'], 'fecha_fin' => $p['fecha_fin'], 'estado' => 'cerrado'], $usrDaniela, $IP);
}
// Un bono en la nómina en curso de Luis, para ver una línea manual.
$nomLuis = (int)one($pdo, 'SELECT n.id_nomina FROM nominas n JOIN periodos_pago p USING (id_periodo) WHERE n.id_empleado = ? AND p.fecha_inicio = ?', [$emp['Luis'], '2026-10-01']);
$nom->agregarIngreso($nomLuis, ['tipo' => 'bonificacion', 'descripcion' => 'Bono por puntualidad', 'monto' => '15000'], $usrDaniela, $IP);

// ── 8. Salida de Andrés y su liquidación ─────────────────────
paso('Salida y liquidación de Andrés');
q($pdo, "UPDATE empleados SET estado = 'inactivo', fecha_salida = '2026-08-31' WHERE id_empleado = ?", [$andres]);
q($pdo, "UPDATE usuarios u JOIN empleados e ON e.id_usuario = u.id_usuario SET u.activo = 0 WHERE e.id_empleado = ?", [$andres]);
q($pdo, "UPDATE contratos SET estado = 'finalizado', fecha_fin = '2026-08-31' WHERE id_empleado = ?", [$andres]);
$c->get(App\Services\LiquidacionService::class)->calcular(
    ['id_empleado' => $andres, 'motivo' => 'renuncia', 'fecha_salida' => '2026-08-31', 'dias_vacaciones' => '6'], $usrDaniela, $IP);

// ── 9. Aguinaldo: 2025 pagado (12 meses) y 2026 calculado por el sistema ──
paso('Aguinaldo');
foreach (q($pdo, "SELECT e.id_empleado, p.salario_base FROM empleados e JOIN puestos p USING (id_puesto)
                   WHERE e.fecha_ingreso < '2024-12-01' AND e.id_empleado <> ?", [$emp['Carlos']])->fetchAll() as $r) {
    q($pdo, "INSERT INTO aguinaldo (id_empleado, anio, salarios_acumulados, monto_aguinaldo, fecha_pago, estado)
             VALUES (?, 2025, ?, ?, '2025-12-12', 'pagado')", [$r['id_empleado'], $r['salario_base'] * 12, $r['salario_base']]);
}
$c->get(App\Services\AguinaldoService::class)->calcular(2026, $usrDaniela, $IP);

// ── 10. Evaluaciones anuales 2025 (con el service) ───────────
paso('Evaluaciones 2025');
$evalSvc = $c->get(App\Services\EvaluacionesService::class);
$crit = $evalSvc->datosFormulario()['criterios'];
$puntajes = ['Juan' => [88, 92, 85, 90, 86], 'María' => [95, 90, 93, 97, 92], 'Luis' => [78, 85, 80, 74, 82],
             'Daniela' => [96, 94, 98, 95, 97], 'Emerson' => [90, 90, 90, 90, 90]];
$obs = ['Juan' => 'Buen desempeño; reforzar orden en el área de trabajo.', 'María' => 'Excelente atención al cliente y calidad del trabajo.',
        'Luis' => 'Técnicamente muy sólido; mejorar la puntualidad.', 'Daniela' => 'Planilla siempre a tiempo y sin errores.', 'Emerson' => ''];
foreach ($puntajes as $n => $pts) {
    $idE = $n === 'Daniela' ? $daniela : $emp[$n];
    if ($n === 'Emerson') continue;   // el dueño no se evalúa
    $evalSvc->crear([
        'id_empleado' => $idE, 'periodo_evaluado' => 2025, 'fecha_evaluacion' => '2026-01-20', 'observaciones' => $obs[$n],
        'criterio' => array_column($crit, 'criterio'), 'peso' => array_column($crit, 'peso'),
        'puntaje' => array_slice(array_pad($pts, count($crit), 85), 0, count($crit)),
    ], $usrDaniela, $IP);
}

// ── 11. Registro anual de saldo de vacaciones ─────────────
// Bloque que también va en database/demo.php: registro anual de saldo_vacaciones, recalculado desde cero
// (ganados en el año por antigüedad, tomados según la tabla vacaciones, disponibles = diferencia).
paso('Saldo anual de vacaciones (recalculado)');
$acum = new App\Helpers\AcumulacionVacaciones();
$hoyDemo = new DateTimeImmutable('2026-10-12');
q($pdo, 'DELETE FROM saldo_vacaciones');
foreach (q($pdo, 'SELECT id_empleado, fecha_ingreso, fecha_salida FROM empleados')->fetchAll() as $e) {
    $ingreso = new DateTimeImmutable($e['fecha_ingreso']);
    $tope    = min($hoyDemo, new DateTimeImmutable($e['fecha_salida']));
    for ($a = (int)$ingreso->format('Y'); $a <= (int)$tope->format('Y'); $a++) {
        $finAnio  = min($tope, new DateTimeImmutable("$a-12-31"));
        $ganados  = $acum->diasGanados($ingreso, $finAnio) - $acum->diasGanados($ingreso, new DateTimeImmutable(($a - 1) . '-12-31'));
        $tomados  = (float)one($pdo, 'SELECT COALESCE(SUM(dias_tomados), 0) FROM vacaciones WHERE id_empleado = ? AND YEAR(fecha_inicio) = ?',
                               [$e['id_empleado'], $a]);
        q($pdo, 'INSERT INTO saldo_vacaciones (id_empleado, anio, dias_ganados, dias_disfrutados, dias_disponibles) VALUES (?,?,?,?,?)',
            [$e['id_empleado'], $a, $ganados, $tomados, $ganados - $tomados]);
    }
}

// ── 12. Algunos inicios y cierres de sesión en la bitácora ───
paso('Bitácora de sesiones');
$aud = $c->get(App\Repositories\AuditoriaRepository::class);
foreach (['superadmin', 'dvargas', 'admin', 'jperez', 'lrojas', 'cjimenez'] as $u) {
    $idU = (int)one($pdo, 'SELECT id_usuario FROM usuarios WHERE nombre_usuario = ?', [$u]);
    $aud->insert('LOGIN', $idU, 'usuarios', $idU, '192.168.1.' . (20 + $idU));
    $aud->insert('LOGOUT', $idU, 'usuarios', $idU, '192.168.1.' . (20 + $idU));
}

// ── Resumen ──────────────────────────────────────────────────
echo "\nFilas por tabla:\n";
foreach (q($pdo, "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    printf("  %-20s %5d\n", $t, one($pdo, "SELECT COUNT(*) FROM `$t`"));
}
