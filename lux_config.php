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
