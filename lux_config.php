<?php
// Grupo Lux — configuración, base de datos (SQLite) y helpers
session_start();
date_default_timezone_set('America/Argentina/Buenos_Aires');
$dir = __DIR__ . '/data';
if (!is_dir($dir)) mkdir($dir, 0755, true);
$nuevo = !file_exists("$dir/lux.sqlite");
$db = new PDO("sqlite:$dir/lux.sqlite");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($nuevo) lux_instalar($db);

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
      (SELECT COALESCE(SUM(total),0) FROM ventas v WHERE v.negocio_id=n.id AND date(v.fecha)=?) ventas,
      (SELECT COUNT(*) FROM productos p WHERE p.negocio_id=n.id AND p.stock<p.minimo) alertas,
      (SELECT COUNT(*) FROM productos p WHERE p.negocio_id=n.id) items
      FROM negocios n ORDER BY n.id");
    $st->execute([date('Y-m-d')]);
    return $st->fetchAll();
}

function lux_instalar($db) {
    $db->exec("
    CREATE TABLE negocios(id INTEGER PRIMARY KEY, nombre TEXT, tipo TEXT, color TEXT, descripcion TEXT);
    CREATE TABLE usuarios(id INTEGER PRIMARY KEY, nombre TEXT, email TEXT UNIQUE, pass TEXT,
      rol TEXT CHECK(rol IN ('superadmin','admin','empleado','cliente')), negocio_id INTEGER, estado TEXT DEFAULT 'Activo');
    CREATE TABLE productos(id INTEGER PRIMARY KEY, negocio_id INTEGER, nombre TEXT, precio REAL, stock INTEGER, minimo INTEGER);
    CREATE TABLE ventas(id INTEGER PRIMARY KEY, negocio_id INTEGER, producto_id INTEGER, usuario_id INTEGER,
      cliente_id INTEGER, cantidad INTEGER, total REAL, fecha TEXT);
    CREATE TABLE turnos(id INTEGER PRIMARY KEY, cliente_id INTEGER, negocio_id INTEGER, profesional TEXT, fecha TEXT, estado TEXT DEFAULT 'Pendiente');");
    $neg = [
      ['Papelería Lux','Librería y papelería','blue','Útiles escolares, materiales de oficina y artículos de librería para toda la familia.'],
      ['Farmacia Lux','Farmacia','green','Medicamentos, perfumería y productos de cuidado personal, con retiro en el local.'],
      ['ElectroLux','Electrodomésticos y tecnología','violet','Electrodomésticos y tecnología para el hogar, con asesoramiento y garantía.'],
      ['Consultorio Lux Salud','Consultorio médico','teal','Turnos con clínica general y especialistas, con recordatorio automático.'],
    ];
    foreach ($neg as $n) $db->prepare('INSERT INTO negocios(nombre,tipo,color,descripcion) VALUES(?,?,?,?)')->execute($n);
    $p = password_hash('lux2026', PASSWORD_DEFAULT);
    $us = [
      ['Admin General','admin@grupolux.com','superadmin',null,'Activo'],
      ['Diego Ferro','diego.ferro@grupolux.com','empleado',2,'Activo'],
      ['Marina Ríos','marina.rios@grupolux.com','admin',1,'Activo'],
      ['Sol Aguirre','sol.aguirre@grupolux.com','empleado',3,'Activo'],
      ['Dra. Paula Nieva','paula.nieva@grupolux.com','admin',4,'Activo'],
      ['Bruno Castex','bruno.castex@mail.com','cliente',2,'Inactivo'],
      ['Ivana Roldán','ivana.roldan@mail.com','cliente',3,'Activo'],
    ];
    foreach ($us as $u) $db->prepare('INSERT INTO usuarios(nombre,email,pass,rol,negocio_id,estado) VALUES(?,?,?,?,?,?)')
        ->execute([$u[0], $u[1], $p, $u[2], $u[3], $u[4]]);
    $prod = [
      [1,'Set de útiles escolares',8200,40,10],[1,'Cuaderno A4 rayado',2500,120,30],[1,'Resma A4 500 hojas',9800,8,10],
      [2,'Ibuprofeno 400mg',1700,14,20],[2,'Alcohol en gel 500ml',2100,56,15],[2,'Termómetro digital',8900,6,10],
      [2,'Vitamina C x30',3200,40,15],[2,'Protector solar FP50',7400,3,12],
      [3,'Auriculares inalámbricos',34500,12,5],[3,'Licuadora 600W',48000,7,3],
      [4,'Consulta clínica',12000,999,0],
    ];
    foreach ($prod as $r) $db->prepare('INSERT INTO productos(negocio_id,nombre,precio,stock,minimo) VALUES(?,?,?,?,?)')->execute($r);
    // ventas de los últimos 7 días (datos demo)
    $todos = $db->query('SELECT * FROM productos')->fetchAll();
    for ($d = 0; $d < 7; $d++) foreach ($todos as $i => $pr) {
        $c = (($d * 3 + $i * 2) % 4) + 1;
        $f = date('Y-m-d H:i:s', strtotime("-$d days -" . ($i * 17) . ' minutes'));
        $db->prepare('INSERT INTO ventas(negocio_id,producto_id,usuario_id,cliente_id,cantidad,total,fecha) VALUES(?,?,?,?,?,?,?)')
           ->execute([$pr['negocio_id'], $pr['id'], 2, ($i % 4 == 0 ? 7 : null), $c, $c * $pr['precio'], $f]);
    }
    $t = [[7,4,'Dra. Paula Nieva',date('Y-m-d 10:30', strtotime('+3 days'))],
          [6,4,'Dra. Paula Nieva',date('Y-m-d 09:00')],[6,4,'Dra. Paula Nieva',date('Y-m-d 11:00')]];
    foreach ($t as $r) $db->prepare('INSERT INTO turnos(cliente_id,negocio_id,profesional,fecha) VALUES(?,?,?,?)')->execute($r);
}

// Negocio sobre el que se trabaja: el propio (empleado/admin) o el elegido por el Super Admin
function lux_negocio_id($u) {
    return $u['rol'] === 'superadmin' ? (int)($_SESSION['negocio_activo'] ?? 0) : (int)$u['negocio_id'];
}
