<?php
// Grupo Lux — configuración, base de datos MySQL y helpers
session_start();
date_default_timezone_set('America/Argentina/Buenos_Aires');
$host = getenv('LUX_DB_HOST') ?: '127.0.0.1';
$port = getenv('LUX_DB_PORT') ?: '3306';
$name = getenv('LUX_DB_NAME') ?: 'grupolux';
$user = getenv('LUX_DB_USER') ?: 'root';
$pass = getenv('LUX_DB_PASS');
if ($pass === false) $pass = '';
if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
    throw new RuntimeException('LUX_DB_NAME solo puede contener letras, números y guion bajo.');
}
$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$servidor = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, $pdoOptions);
$existeBase = $servidor->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name=?');
$existeBase->execute([$name]);
if (!(int)$existeBase->fetchColumn()) {
    $servidor->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}
$db = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, $pdoOptions);
$version = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='lux_schema_version'");
$version->execute();
$tieneVersion = (bool)$version->fetchColumn();
$versionInstalada = $tieneVersion ? (int)$db->query('SELECT COALESCE(MAX(version),0) FROM lux_schema_version')->fetchColumn() : 0;
if ($versionInstalada < 1) lux_instalar($db);
else {
    if ($versionInstalada < 2) lux_migrar_2($db);
    if ($versionInstalada < 3) lux_migrar_3($db);
    if ($versionInstalada < 4) lux_migrar_4($db);
    if ($versionInstalada < 5) lux_migrar_5($db);
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n) { return '$' . number_format((float)$n, 0, ',', '.'); }
function lux_user() { return $_SESSION['user'] ?? null; }
function flash($msg, $err = false) { $_SESSION['flash'] = [$msg, $err]; }
function lux_redirect($to) { header("Location: $to"); exit; }
function lux_home($rol) {
    return ['superadmin' => 'lux_panel_general.php', 'cliente' => 'lux_mis_turnos.php'][$rol] ?? 'lux_ventas.php';
}
function lux_require(array $roles) {
    $u = lux_user();
    if (!$u) lux_redirect('lux_login.php');
    if (!in_array($u['rol'], $roles)) lux_redirect(lux_home($u['rol']));
    return $u;
}
function csrf() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field() { return '<input type="hidden" name="csrf" value="' . csrf() . '">'; }
function csrf_check() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400); exit('Solicitud inválida (CSRF).');
    }
}
function lux_negocios_stats($db) {
    $st = $db->prepare("SELECT n.*,
      (SELECT COALESCE(SUM(total),0) FROM ventas v WHERE v.negocio_id=n.id AND v.fecha>=? AND v.fecha<?) ventas,
      (SELECT COUNT(*) FROM productos p WHERE p.negocio_id=n.id AND p.stock<p.minimo) alertas,
      (SELECT COUNT(*) FROM productos p WHERE p.negocio_id=n.id) items
      FROM negocios n ORDER BY n.id");
    $st->execute([date('Y-m-d 00:00:00'), date('Y-m-d 00:00:00', strtotime('+1 day'))]);
    return $st->fetchAll();
}

// Migración v2: tabla de pedidos (alerta de pedidos pendientes) para bases que ya estaban instaladas
function lux_migrar_2($db) {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS pedidos (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, negocio_id INT UNSIGNED NOT NULL, cliente_id INT UNSIGNED NULL, producto_id INT UNSIGNED NOT NULL, cantidad INT UNSIGNED NOT NULL, estado VARCHAR(20) NOT NULL DEFAULT 'Pendiente', fecha DATETIME NOT NULL, CONSTRAINT fk_pedidos_negocio FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE RESTRICT, CONSTRAINT fk_pedidos_cliente FOREIGN KEY (cliente_id) REFERENCES usuarios(id) ON DELETE SET NULL, CONSTRAINT fk_pedidos_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE RESTRICT, INDEX idx_pedidos_negocio_estado (negocio_id,estado)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    if (!(int)$db->query('SELECT COUNT(*) FROM pedidos')->fetchColumn()) {
        $db->beginTransaction();
        $demo = [
            [1, 1, 7, 1, 2, 'Pendiente', '2026-09-30 09:01:00'],
            [2, 2, 7, 5, 3, 'Pendiente', '2026-09-30 09:02:00'],
            [3, 2, 6, 7, 1, 'Pendiente', '2026-09-30 09:03:00'],
            [4, 3, 7, 9, 1, 'Pendiente', '2026-09-30 09:04:00'],
            [5, 2, 7, 4, 2, 'Entregado', '2026-09-30 09:05:00'],
            [6, 2, 7, 7, 2, 'En camino', '2026-09-29 18:20:00'],
            [7, 2, 6, 5, 1, 'En preparación', '2026-09-29 16:45:00'],
            [8, 1, 7, 2, 5, 'En camino', '2026-09-28 12:10:00'],
        ];
        $ins = $db->prepare('INSERT INTO pedidos(id,negocio_id,cliente_id,producto_id,cantidad,estado,fecha) VALUES(?,?,?,?,?,?,?)');
        foreach ($demo as $d) {
            // solo carga el pedido de ejemplo si el producto y el cliente existen en esta base
            $ok = $db->prepare('SELECT (SELECT COUNT(*) FROM productos WHERE id=? AND negocio_id=?) + (SELECT COUNT(*) FROM usuarios WHERE id=?)');
            $ok->execute([$d[3], $d[1], $d[2]]);
            if ((int)$ok->fetchColumn() === 2) $ins->execute($d);
        }
        $db->commit();
    }
    $db->exec('INSERT INTO lux_schema_version (version) VALUES (2)');
}

// Migración v3: estados nuevos de pedidos (Entregado) y pedidos de ejemplo 6 a 8, para bases ya instaladas
function lux_migrar_3($db) {
    $db->exec("UPDATE pedidos SET estado='Entregado' WHERE estado='Preparado'");
    $demo = [
        [6, 2, 7, 7, 2, 'En camino', '2026-09-29 18:20:00'],
        [7, 2, 6, 5, 1, 'En preparación', '2026-09-29 16:45:00'],
        [8, 1, 7, 2, 5, 'En camino', '2026-09-28 12:10:00'],
    ];
    $ins = $db->prepare('INSERT IGNORE INTO pedidos(id,negocio_id,cliente_id,producto_id,cantidad,estado,fecha) VALUES(?,?,?,?,?,?,?)');
    $ok = $db->prepare('SELECT (SELECT COUNT(*) FROM productos WHERE id=? AND negocio_id=?) + (SELECT COUNT(*) FROM usuarios WHERE id=?)');
    foreach ($demo as $d) {
        $ok->execute([$d[3], $d[1], $d[2]]);
        if ((int)$ok->fetchColumn() === 2) $ins->execute($d); // si el id ya existe, se omite
    }
    $db->exec('INSERT INTO lux_schema_version (version) VALUES (3)');
}

// Puestos de cada negocio. Cada puesto da un rol de acceso: 'admin' (gestiona el negocio) o 'empleado'.
function lux_puestos($negocioId) {
    $a = 'admin'; $e = 'empleado';
    $mapa = [
        1 => ['Dueño / Administrador' => $a, 'Encargado del local' => $e, 'Vendedor / Atención al cliente' => $e, 'Cajero' => $e, 'Repositor de mercadería' => $e, 'Encargado de stock' => $e],
        2 => ['Farmacéutico' => $e, 'Auxiliar de farmacia' => $e, 'Cajero' => $e, 'Vendedor' => $e, 'Encargado de stock' => $e, 'Administrador' => $a],
        3 => ['Dueño / Administrador' => $a, 'Gerente' => $e, 'Vendedor' => $e, 'Cajero' => $e, 'Técnico de reparación' => $e, 'Repartidor' => $e, 'Encargado de depósito' => $e],
        4 => ['Médico' => $e, 'Recepcionista' => $e, 'Secretario/a administrativo/a' => $e, 'Enfermero/a' => $e, 'Personal de limpieza' => $e, 'Administrador' => $a],
    ];
    return $mapa[(int)$negocioId] ?? [];
}

// Migración v4: columna puesto en usuarios (cada negocio tiene sus propios puestos)
function lux_migrar_4($db) {
    $existe = (int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='usuarios' AND column_name='puesto'")->fetchColumn();
    if (!$existe) $db->exec('ALTER TABLE usuarios ADD COLUMN puesto VARCHAR(60) NULL AFTER estado');
    $up = $db->prepare('UPDATE usuarios SET puesto=? WHERE email=? AND puesto IS NULL');
    foreach (['diego.ferro@grupolux.com' => 'Auxiliar de farmacia', 'marina.rios@grupolux.com' => 'Dueño / Administrador',
              'sol.aguirre@grupolux.com' => 'Vendedor', 'paula.nieva@grupolux.com' => 'Administrador'] as $email => $puesto) $up->execute([$puesto, $email]);
    $db->exec('INSERT INTO lux_schema_version (version) VALUES (4)');
}

// Puestos que registran ventas en cada negocio (el administrador y el Super Admin siempre pueden)
function lux_puede_vender($u) {
    if (in_array($u['rol'] ?? '', ['superadmin', 'admin'], true)) return true;
    $p = $u['puesto'] ?? '';
    if ($p === '') return true; // usuarios anteriores a los puestos, todavía sin puesto asignado
    $venden = [
        1 => ['Encargado del local', 'Vendedor / Atención al cliente', 'Cajero'],
        2 => ['Farmacéutico', 'Auxiliar de farmacia', 'Cajero', 'Vendedor'],
        3 => ['Gerente', 'Vendedor', 'Cajero'],
        4 => ['Médico', 'Recepcionista', 'Secretario/a administrativo/a'],
    ];
    return in_array($p, $venden[(int)($u['negocio_id'] ?? 0)] ?? [], true);
}

// Migración v5: datos del registro de ventas (medio de pago, cliente escrito a mano, detalle según el negocio)
function lux_migrar_5($db) {
    foreach (['medio_pago' => 'VARCHAR(30) NULL', 'cliente_nombre' => 'VARCHAR(120) NULL', 'detalle' => 'VARCHAR(255) NULL'] as $col => $def) {
        $existe = (int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ventas' AND column_name='$col'")->fetchColumn();
        if (!$existe) $db->exec("ALTER TABLE ventas ADD COLUMN $col $def NULL");
    }
    $db->exec('INSERT INTO lux_schema_version (version) VALUES (5)');
}

function lux_instalar($db) {
    $tablas = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
    if ($tablas) {
        throw new RuntimeException('La base MySQL ya contiene tablas pero no tiene lux_schema_version. Elegí una base vacía o importá data/grupo_lux.sql manualmente.');
    }
    $dump = file_get_contents(__DIR__ . '/data/grupo_lux.sql');
    if ($dump === false) throw new RuntimeException('No se pudo leer data/grupo_lux.sql.');
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $dump, -1, PREG_SPLIT_NO_EMPTY);
    if (!$statements) throw new RuntimeException('El archivo data/grupo_lux.sql está vacío o no es válido.');
    $insertsStarted = false;
    try {
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '' || strpos($statement, '--') === 0) continue;
            if (!$insertsStarted && preg_match('/^INSERT\s+/i', $statement)) {
                $db->beginTransaction();
                $insertsStarted = true;
            }
            $db->exec($statement);
        }
        if ($db->inTransaction()) {
            $db->commit();
        } else {
            throw new RuntimeException('El archivo SQL no contiene datos para importar.');
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

// Negocio sobre el que se trabaja: el propio (empleado/admin) o el elegido por el Super Admin
function lux_negocio_id($u) {
    return $u['rol'] === 'superadmin' ? (int)($_SESSION['negocio_activo'] ?? 0) : (int)$u['negocio_id'];
}
