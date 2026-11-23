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
    if ($versionInstalada < 6) lux_migrar_6($db);
    if ($versionInstalada < 7) lux_migrar_7($db);
    if ($versionInstalada < 8) lux_migrar_8($db);
    if ($versionInstalada < 9) lux_migrar_9($db);
    if ($versionInstalada < 10) lux_migrar_10($db);
    if ($versionInstalada < 11) lux_migrar_11($db);
    if ($versionInstalada < 12) lux_migrar_12($db);
    if ($versionInstalada < 13) lux_migrar_13($db);
    if ($versionInstalada < 14) lux_migrar_14($db);
    if ($versionInstalada < 15) lux_migrar_15($db);
    if ($versionInstalada < 16) lux_migrar_16($db);
    if ($versionInstalada < 17) lux_migrar_17($db);
    if ($versionInstalada < 18) lux_migrar_18($db);
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n) { return '$' . number_format((float)$n, 0, ',', '.'); }
function lux_user() { return $_SESSION['user'] ?? null; }
function lux_carritos_cargar($negocioId, $usuarioId) {
    $estado = $_SESSION['lux_carritos'][(int)$negocioId][(int)$usuarioId] ?? [];
    if (!is_array($estado) || !is_array($estado['carts'] ?? null)) {
        $anterior = is_array($estado) ? $estado : [];
        $estado = ['active' => '', 'next' => 1, 'carts' => []];
        if ($anterior) {
            $id = bin2hex(random_bytes(8));
            $estado['carts'][$id] = ['nombre' => 'Carrito anterior', 'items' => $anterior];
            $estado['active'] = $id;
            $estado['next'] = 2;
        }
    }
    $estado['active'] = is_scalar($estado['active'] ?? null) ? (string)$estado['active'] : '';
    $estado['next'] = max(1, (int)($estado['next'] ?? 1));
    if (!isset($estado['carts'][$estado['active']])) $estado['active'] = '';
    return $estado;
}
function lux_carritos_importar_sesion($db, $negocioId, $usuarioId) {
    if (!isset($_SESSION['lux_carritos'][(int)$negocioId][(int)$usuarioId])) return;
    $estado = lux_carritos_cargar($negocioId, $usuarioId);
    if (!$estado['carts']) {
        unset($_SESSION['lux_carritos'][(int)$negocioId][(int)$usuarioId]);
        return;
    }
    $carrito = $db->prepare('INSERT INTO venta_carritos(negocio_id,creado_por,nombre,fecha) VALUES(?,?,?,?)');
    $productoValido = $db->prepare('SELECT id FROM productos WHERE id=? AND negocio_id=?');
    $linea = $db->prepare('INSERT INTO venta_carrito_items(carrito_id,producto_id,cantidad) VALUES(?,?,?)');
    $noImportados = 0;
    $db->beginTransaction();
    try {
        foreach ($estado['carts'] as $datos) {
            $nombre = is_scalar($datos['nombre'] ?? null) ? trim((string)$datos['nombre']) : 'Carrito anterior';
            if ($nombre === '' || mb_strlen($nombre) > 80) $nombre = 'Carrito anterior';
            $carrito->execute([(int)$negocioId, (int)$usuarioId, $nombre, date('Y-m-d H:i:s')]);
            $carritoId = (int)$db->lastInsertId();
            $items = is_array($datos['items'] ?? null) ? $datos['items'] : [];
            foreach ($items as $id => $cantidad) {
                $id = filter_var((string)$id, FILTER_VALIDATE_INT);
                $cantidad = is_scalar($cantidad) ? filter_var((string)$cantidad, FILTER_VALIDATE_INT) : false;
                if ($id === false || $cantidad === false || $cantidad < 1 || $cantidad > 2147483647) {
                    $noImportados++;
                    continue;
                }
                $productoValido->execute([$id, (int)$negocioId]);
                if (!$productoValido->fetchColumn()) {
                    $noImportados++;
                    continue;
                }
                $linea->execute([$carritoId, $id, $cantidad]);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    unset($_SESSION['lux_carritos'][(int)$negocioId][(int)$usuarioId]);
    if ($noImportados) flash("$noImportados producto(s) antiguos no se pudieron importar porque ya no existen o tenían una cantidad inválida.", true);
}
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

// Acceso a las fichas de pacientes (Consultorio): todo el personal salvo limpieza; el Super Admin y el administrador siempre
function lux_puede_pacientes($u) {
    if (in_array($u['rol'] ?? '', ['superadmin', 'admin'], true)) return true;
    return ($u['puesto'] ?? '') !== 'Personal de limpieza';
}
// Las cuentas creadas por recepción sin email usan un email de relleno que no se muestra
function lux_email_relleno($email) {
    $fin = '@sin-email.invalid';
    return substr((string)$email, -strlen($fin)) === $fin;
}

// Migración v5: datos del registro de ventas (medio de pago, cliente escrito a mano, detalle según el negocio)
function lux_migrar_5($db) {
    foreach (['medio_pago' => 'VARCHAR(30) NULL', 'cliente_nombre' => 'VARCHAR(120) NULL', 'detalle' => 'VARCHAR(255) NULL'] as $col => $def) {
        $existe = (int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ventas' AND column_name='$col'")->fetchColumn();
        if (!$existe) $db->exec("ALTER TABLE ventas ADD COLUMN $col $def NULL");
    }
    $db->exec('INSERT INTO lux_schema_version (version) VALUES (5)');
}

// Migración v6: relaciona cada pedido entregado con su venta para evitar duplicados
function lux_migrar_6($db) {
    $columna = (int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='venta_id'")->fetchColumn();
    if (!$columna) $db->exec('ALTER TABLE pedidos ADD COLUMN venta_id INT UNSIGNED NULL');
    $indice = (int)$db->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='pedidos' AND index_name='uq_pedidos_venta_id'")->fetchColumn();
    if (!$indice) $db->exec('ALTER TABLE pedidos ADD UNIQUE INDEX uq_pedidos_venta_id (venta_id)');
    $fk = (int)$db->query("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='pedidos' AND constraint_name='fk_pedidos_venta'")->fetchColumn();
    if (!$fk) $db->exec('ALTER TABLE pedidos ADD CONSTRAINT fk_pedidos_venta FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE SET NULL');
    $db->exec('INSERT INTO lux_schema_version (version) VALUES (6)');
}

// Migración v7: datos de pago y entrega de los pedidos de clientes
function lux_migrar_7($db) {
    foreach ([
        'medio_pago' => 'VARCHAR(30) NULL',
        'entrega' => 'VARCHAR(30) NULL',
        'direccion_envio' => 'VARCHAR(255) NULL',
    ] as $col => $def) {
        $existe = (int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='$col'")->fetchColumn();
        if (!$existe) $db->exec("ALTER TABLE pedidos ADD COLUMN $col $def");
    }
    $db->exec('INSERT INTO lux_schema_version (version) VALUES (7)');
}

// Migración v8: cabecera para agrupar los productos de una misma venta
function lux_migrar_8($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS venta_tickets (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        negocio_id INT UNSIGNED NOT NULL,
        usuario_id INT UNSIGNED NOT NULL,
        fecha DATETIME NOT NULL,
        medio_pago VARCHAR(30) NOT NULL,
        cliente_nombre VARCHAR(120) NULL,
        detalle VARCHAR(255) NULL,
        total DECIMAL(12,2) NOT NULL,
        CONSTRAINT fk_venta_tickets_negocio FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE RESTRICT,
        CONSTRAINT fk_venta_tickets_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
        INDEX idx_venta_tickets_negocio_fecha (negocio_id,fecha)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $columna = (int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ventas' AND column_name='ticket_id'")->fetchColumn();
    if (!$columna) {
        $db->exec('ALTER TABLE ventas ADD COLUMN ticket_id INT UNSIGNED NULL');
        $db->exec('ALTER TABLE ventas ADD INDEX idx_ventas_ticket_id (ticket_id)');
        $db->exec('ALTER TABLE ventas ADD CONSTRAINT fk_ventas_ticket FOREIGN KEY (ticket_id) REFERENCES venta_tickets(id) ON DELETE SET NULL');
    }
    $db->exec('INSERT INTO lux_schema_version (version) VALUES (8)');
}

// Migración v9: carritos compartidos por todo el personal de cada negocio
function lux_migrar_9($db) {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS venta_carritos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    negocio_id INT UNSIGNED NOT NULL,
    creado_por INT UNSIGNED NOT NULL,
    nombre VARCHAR(80) NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'Abierto',
    fecha DATETIME NOT NULL,
    ticket_id INT UNSIGNED NULL,
    CONSTRAINT fk_venta_carritos_negocio FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE RESTRICT,
    CONSTRAINT fk_venta_carritos_usuario FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE RESTRICT,
    CONSTRAINT fk_venta_carritos_ticket FOREIGN KEY (ticket_id) REFERENCES venta_tickets(id) ON DELETE SET NULL,
    UNIQUE INDEX uq_venta_carritos_ticket (ticket_id),
    INDEX idx_venta_carritos_negocio_estado (negocio_id,estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS venta_carrito_items (
    carrito_id INT UNSIGNED NOT NULL,
    producto_id INT UNSIGNED NOT NULL,
    cantidad INT UNSIGNED NOT NULL,
    PRIMARY KEY (carrito_id,producto_id),
    CONSTRAINT fk_venta_carrito_items_carrito FOREIGN KEY (carrito_id) REFERENCES venta_carritos(id) ON DELETE CASCADE,
    CONSTRAINT fk_venta_carrito_items_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    $db->exec('INSERT INTO lux_schema_version (version) VALUES (9)');
}

// Migración v10: número opcional de obra social en pedidos de Farmacia
function lux_migrar_10($db) {
    $columna = (int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='numero_obra_social'")->fetchColumn();
    if (!$columna) $db->exec('ALTER TABLE pedidos ADD COLUMN numero_obra_social VARCHAR(80) NULL');
    $db->exec('INSERT INTO lux_schema_version (version) VALUES (10)');
}

// Migración v11: ficha completa del paciente (Consultorio), 1 a 1 con la cuenta en usuarios
function lux_migrar_11($db) {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS pacientes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    dni VARCHAR(8) NOT NULL,
    fecha_nacimiento DATE NOT NULL,
    sexo VARCHAR(20) NOT NULL,
    telefono VARCHAR(30) NOT NULL,
    direccion VARCHAR(255) NULL,
    obra_social VARCHAR(100) NULL,
    numero_afiliado VARCHAR(60) NULL,
    contacto_emergencia_nombre VARCHAR(150) NULL,
    contacto_emergencia_telefono VARCHAR(30) NULL,
    contacto_emergencia_relacion VARCHAR(50) NULL,
    creado DATETIME NOT NULL,
    CONSTRAINT fk_pacientes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    UNIQUE INDEX uq_pacientes_usuario (usuario_id),
    UNIQUE INDEX uq_pacientes_dni (dni),
    INDEX idx_pacientes_obra_social (obra_social)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    // Fichas de ejemplo para los clientes demo (solo si existen en esta base)
    $demo = [
        [6, '30123456', '1984-03-12', 'Masculino', '2246 45-1234', 'Calle 3 N° 456, Santa Teresita', 'IOMA', '10-30123456-7', 'Laura Castex', '2246 45-9876', 'Hermana'],
        [7, '35987654', '1991-08-27', 'Femenino', '2246 47-5588', 'Av. 32 N° 1120, Santa Teresita', 'OSDE', '3-5987654-01', 'Marcos Roldán', '2246 40-1122', 'Esposo'],
    ];
    $ok = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE id=? AND rol='cliente'");
    $ins = $db->prepare('INSERT IGNORE INTO pacientes(usuario_id,dni,fecha_nacimiento,sexo,telefono,direccion,obra_social,numero_afiliado,contacto_emergencia_nombre,contacto_emergencia_telefono,contacto_emergencia_relacion,creado) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($demo as $d) {
        $ok->execute([$d[0]]);
        if ((int)$ok->fetchColumn()) $ins->execute(array_merge($d, [date('Y-m-d H:i:s')]));
    }
    $db->exec('INSERT INTO lux_schema_version (version) VALUES (11)');
}

// Días de la semana (1 = lunes ... 7 = domingo, como date('N'))
function lux_dias_semana() {
    return [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
}
// "Lun a Vie 09:00–13:00 · Sáb 09:00–12:00" a partir de filas de medico_horarios
function lux_horario_resumen(array $filas) {
    $ab = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];
    $por = [];
    foreach ($filas as $f) $por[(int)$f['dia_semana']] = substr($f['hora_desde'], 0, 5) . '–' . substr($f['hora_hasta'], 0, 5);
    ksort($por);
    $grupos = [];
    foreach ($por as $dia => $rango) {
        $n = count($grupos);
        if ($n && $grupos[$n - 1][2] === $rango && $grupos[$n - 1][1] === $dia - 1) $grupos[$n - 1][1] = $dia;
        else $grupos[] = [$dia, $dia, $rango];
    }
    $out = [];
    foreach ($grupos as $g) {
        $dias = $g[0] === $g[1] ? $ab[$g[0]] : $ab[$g[0]] . ($g[1] === $g[0] + 1 ? ' y ' : ' a ') . $ab[$g[1]];
        $out[] = $dias . ' ' . $g[2];
    }
    return implode(' · ', $out);
}
// Controla que un turno caiga en un día y horario de atención del médico, en su grilla de turnos y sin pisar a otro.
// $m: ['id' => ..., 'duracion_turno' => ...]. $excluir: turno que se está reprogramando (no choca consigo mismo).
// $pacienteId: si viene, tampoco se permite que el paciente tenga dos turnos superpuestos.
// Devuelve el motivo del rechazo o '' si el horario sirve.
function lux_medico_disponible($db, array $m, $ts, $excluir = 0, $pacienteId = 0) {
    $dur = max(1, (int)$m['duracion_turno']);
    $dia = (int)date('N', $ts);
    $b = $db->prepare('SELECT motivo FROM medico_bloqueos WHERE (medico_id IS NULL OR medico_id=?) AND ? BETWEEN desde AND hasta LIMIT 1');
    $b->execute([(int)$m['id'], date('Y-m-d', $ts)]);
    if (($bl = $b->fetch())) return 'No hay atención ese día' . ($bl['motivo'] !== '' ? ' (' . $bl['motivo'] . ')' : '') . '.';
    $s = $db->prepare('SELECT hora_desde, hora_hasta FROM medico_horarios WHERE medico_id=? AND dia_semana=?');
    $s->execute([(int)$m['id'], $dia]);
    $h = $s->fetch();
    if (!$h) return 'El médico no atiende los ' . mb_strtolower(lux_dias_semana()[$dia]) . '.';
    $min = (int)date('G', $ts) * 60 + (int)date('i', $ts);
    $mins = function ($t) { return (int)substr($t, 0, 2) * 60 + (int)substr($t, 3, 2); };
    $desde = $mins($h['hora_desde']); $hasta = $mins($h['hora_hasta']);
    if ($min < $desde || $min + $dur > $hasta)
        return 'Ese día atiende de ' . substr($h['hora_desde'], 0, 5) . ' a ' . substr($h['hora_hasta'], 0, 5) . ' (el último turno debe terminar antes del cierre).';
    if (($min - $desde) % $dur !== 0)
        return 'Los turnos de este médico son de ' . $dur . ' minutos, a partir de las ' . substr($h['hora_desde'], 0, 5) . '.';
    $desdeV = date('Y-m-d H:i:s', $ts - $dur * 60); $hastaV = date('Y-m-d H:i:s', $ts + $dur * 60);
    $c = $db->prepare("SELECT COUNT(*) FROM turnos WHERE medico_id=? AND estado<>'Cancelado' AND id<>? AND fecha>? AND fecha<?");
    $c->execute([(int)$m['id'], (int)$excluir, $desdeV, $hastaV]);
    if ((int)$c->fetchColumn()) return 'Ese horario ya está ocupado. Elegí otro.';
    if ($pacienteId) {
        $p = $db->prepare("SELECT COUNT(*) FROM turnos WHERE cliente_id=? AND negocio_id=4 AND estado<>'Cancelado' AND id<>? AND fecha>? AND fecha<?");
        $p->execute([(int)$pacienteId, (int)$excluir, $desdeV, $hastaV]);
        if ((int)$p->fetchColumn()) return 'Hay otro turno agendado para este paciente en ese horario.';
    }
    return '';
}

// Migración v12: médicos del Consultorio (matrícula, especialidad, horarios) y vínculo de cada turno con su médico
function lux_migrar_12($db) {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS medicos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    matricula VARCHAR(30) NOT NULL,
    especialidad VARCHAR(100) NOT NULL,
    duracion_turno SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    creado DATETIME NOT NULL,
    CONSTRAINT fk_medicos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    UNIQUE INDEX uq_medicos_usuario (usuario_id),
    UNIQUE INDEX uq_medicos_matricula (matricula),
    INDEX idx_medicos_especialidad (especialidad)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS medico_horarios (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    medico_id INT UNSIGNED NOT NULL,
    dia_semana TINYINT UNSIGNED NOT NULL,
    hora_desde TIME NOT NULL,
    hora_hasta TIME NOT NULL,
    CONSTRAINT fk_medico_horarios_medico FOREIGN KEY (medico_id) REFERENCES medicos(id) ON DELETE CASCADE,
    UNIQUE INDEX uq_medico_horarios_dia (medico_id,dia_semana)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    $col = (int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='turnos' AND column_name='medico_id'")->fetchColumn();
    if (!$col) {
        $db->exec('ALTER TABLE turnos ADD COLUMN medico_id INT UNSIGNED NULL AFTER negocio_id');
        $db->exec('ALTER TABLE turnos ADD INDEX idx_turnos_medico_fecha (medico_id,fecha)');
        $db->exec('ALTER TABLE turnos ADD CONSTRAINT fk_turnos_medico FOREIGN KEY (medico_id) REFERENCES medicos(id) ON DELETE SET NULL');
    }
    // Médica de ejemplo: la Dra. Paula Nieva (si existe en esta base) con atención de lunes a sábado
    $paula = $db->query("SELECT id FROM usuarios WHERE email='paula.nieva@grupolux.com' AND negocio_id=4")->fetchColumn();
    if ($paula) {
        $db->prepare("INSERT IGNORE INTO medicos(usuario_id,matricula,especialidad,duracion_turno,creado) VALUES(?,?,?,?,?)")
           ->execute([$paula, 'MP 45210', 'Clínica médica', 30, date('Y-m-d H:i:s')]);
        $mid = $db->prepare('SELECT id FROM medicos WHERE usuario_id=?'); $mid->execute([$paula]); $mid = $mid->fetchColumn();
        $hor = $db->prepare('INSERT IGNORE INTO medico_horarios(medico_id,dia_semana,hora_desde,hora_hasta) VALUES(?,?,?,?)');
        if ($mid) for ($dia = 1; $dia <= 6; $dia++) $hor->execute([$mid, $dia, '09:00:00', '13:00:00']);
    }
    // Los turnos ya cargados con el nombre del profesional quedan vinculados a su médico
    $db->exec("UPDATE turnos t JOIN usuarios u ON u.nombre=t.profesional JOIN medicos m ON m.usuario_id=u.id
        SET t.medico_id=m.id WHERE t.medico_id IS NULL AND t.negocio_id=4");
    $db->exec('INSERT INTO lux_schema_version (version) VALUES (12)');
}

// Migración v13: catálogo de especialidades del Consultorio; cada médico pasa a apuntar a una especialidad (especialidad_id)
function lux_migrar_13($db) {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS especialidades (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    creado DATETIME NOT NULL,
    UNIQUE INDEX uq_especialidades_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    $existe = function ($sql) use ($db) { return (int)$db->query($sql)->fetchColumn(); };
    $colMedicos = function ($c) use ($existe) {
        return $existe("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='medicos' AND column_name='$c'");
    };
    $ins = $db->prepare('INSERT IGNORE INTO especialidades(nombre,creado) VALUES(?,?)');
    $ahora = date('Y-m-d H:i:s');
    foreach (['Cardiología', 'Pediatría', 'Traumatología', 'Dermatología', 'Clínica general', 'Oftalmología'] as $n) $ins->execute([$n, $ahora]);
    if ($colMedicos('especialidad')) {
        // El texto libre anterior 'Clínica médica' pasa a llamarse 'Clínica general'; el resto se conserva tal cual
        $db->exec("UPDATE medicos SET especialidad='Clínica general' WHERE especialidad='Clínica médica'");
        foreach ($db->query('SELECT DISTINCT especialidad FROM medicos')->fetchAll(PDO::FETCH_COLUMN) as $n) $ins->execute([$n, $ahora]);
        if (!$colMedicos('especialidad_id')) $db->exec('ALTER TABLE medicos ADD COLUMN especialidad_id INT UNSIGNED NULL AFTER matricula');
        $db->exec('UPDATE medicos m JOIN especialidades e ON e.nombre=m.especialidad SET m.especialidad_id=e.id WHERE m.especialidad_id IS NULL');
        $db->exec('ALTER TABLE medicos MODIFY especialidad_id INT UNSIGNED NOT NULL');
        if (!$existe("SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema=DATABASE() AND table_name='medicos' AND constraint_name='fk_medicos_especialidad'"))
            $db->exec('ALTER TABLE medicos ADD CONSTRAINT fk_medicos_especialidad FOREIGN KEY (especialidad_id) REFERENCES especialidades(id) ON DELETE RESTRICT');
        $db->exec('ALTER TABLE medicos DROP COLUMN especialidad');
    }
    $db->exec('INSERT INTO lux_schema_version (version) VALUES (13)');
}

// Migración v14: días sin atención (vacaciones, feriados) de un médico o de todo el consultorio; los turnos nuevos los respetan
function lux_migrar_14($db) {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS medico_bloqueos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    medico_id INT UNSIGNED NULL,
    desde DATE NOT NULL,
    hasta DATE NOT NULL,
    motivo VARCHAR(150) NOT NULL DEFAULT '',
    creado DATETIME NOT NULL,
    CONSTRAINT fk_bloqueos_medico FOREIGN KEY (medico_id) REFERENCES medicos(id) ON DELETE CASCADE,
    INDEX idx_bloqueos_fechas (desde,hasta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    $db->exec('INSERT INTO lux_schema_version (version) VALUES (14)');
}

// Migración v15: historias clínicas (antecedentes por paciente, consultas que no se modifican y registro de accesos)
function lux_migrar_15($db) {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS historias_clinicas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    antecedentes TEXT NULL,
    alergias TEXT NULL,
    enfermedades_previas TEXT NULL,
    observaciones TEXT NULL,
    actualizado DATETIME NOT NULL,
    actualizado_por INT UNSIGNED NULL,
    UNIQUE INDEX uq_hc_usuario (usuario_id),
    CONSTRAINT fk_hc_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
    CONSTRAINT fk_hc_actualizado_por FOREIGN KEY (actualizado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS consultas_clinicas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    medico_id INT UNSIGNED NOT NULL,
    turno_id INT UNSIGNED NULL,
    fecha DATETIME NOT NULL,
    motivo VARCHAR(255) NOT NULL,
    diagnostico TEXT NOT NULL,
    tratamiento TEXT NULL,
    observaciones TEXT NULL,
    INDEX idx_consultas_paciente (usuario_id,fecha),
    CONSTRAINT fk_consultas_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
    CONSTRAINT fk_consultas_medico FOREIGN KEY (medico_id) REFERENCES medicos(id) ON DELETE RESTRICT,
    CONSTRAINT fk_consultas_turno FOREIGN KEY (turno_id) REFERENCES turnos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS historia_accesos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    profesional_id INT UNSIGNED NOT NULL,
    accion VARCHAR(30) NOT NULL,
    fecha DATETIME NOT NULL,
    INDEX idx_accesos_fecha (fecha),
    INDEX idx_accesos_paciente (usuario_id),
    CONSTRAINT fk_accesos_paciente FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
    CONSTRAINT fk_accesos_profesional FOREIGN KEY (profesional_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    $db->exec('INSERT INTO lux_schema_version (version) VALUES (15)');
}

// Migración v16: declaración de salud que completa el propio paciente (aparte de la historia clínica oficial)
function lux_migrar_16($db) {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS declaraciones_salud (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    alergias TEXT NULL,
    enfermedades_previas TEXT NULL,
    medicacion TEXT NULL,
    antecedentes TEXT NULL,
    actualizado DATETIME NOT NULL,
    UNIQUE INDEX uq_declaracion_usuario (usuario_id),
    CONSTRAINT fk_declaracion_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    $db->exec('INSERT INTO lux_schema_version (version) VALUES (16)');
}

// Migración v17: las consultas pasan por un borrador ("En curso") que el médico finaliza; las ya cargadas quedan finalizadas
function lux_migrar_17($db) {
    $tiene = (int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='consultas_clinicas' AND column_name='estado'")->fetchColumn();
    if (!$tiene) {
        $db->exec("ALTER TABLE consultas_clinicas MODIFY motivo VARCHAR(255) NOT NULL DEFAULT '', MODIFY diagnostico TEXT NULL,
            ADD COLUMN estado VARCHAR(20) NOT NULL DEFAULT 'Finalizada' AFTER observaciones, ADD COLUMN finalizada DATETIME NULL AFTER estado,
            ADD INDEX idx_consultas_medico (medico_id, estado)");
    }
    $db->exec("UPDATE consultas_clinicas SET finalizada=fecha WHERE estado='Finalizada' AND finalizada IS NULL");
    $db->exec('INSERT INTO lux_schema_version (version) VALUES (17)');
}

function lux_hc_texto($v, $max, array &$err, $etiqueta, $obligatorio = false) {
    $t = is_scalar($v) ? trim(str_replace("\r\n", "\n", (string)$v)) : '';
    if ($obligatorio && $t === '') $err[] = "Completá $etiqueta.";
    if (mb_strlen($t) > $max) $err[] = "$etiqueta supera los $max caracteres.";
    return $t;
}
function lux_hc_paciente($db, $id, $base) {
    $s = $db->prepare("SELECT u.id, u.nombre, p.dni, p.fecha_nacimiento, p.sexo, p.telefono, p.obra_social, p.numero_afiliado
        FROM usuarios u LEFT JOIN pacientes p ON p.usuario_id=u.id WHERE u.id=? AND $base");
    $s->execute([(int)$id]);
    return $s->fetch() ?: null;
}

// Migración v18: recetas médicas (paciente, médico, fecha de emisión y medicamentos con su dosis); una receta emitida no se edita, solo se anula
function lux_migrar_18($db) {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS recetas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    medico_id INT UNSIGNED NOT NULL,
    consulta_id INT UNSIGNED NULL,
    fecha_emision DATETIME NOT NULL,
    indicaciones TEXT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'Emitida',
    anulada_motivo VARCHAR(255) NULL,
    anulada_fecha DATETIME NULL,
    INDEX idx_recetas_paciente (usuario_id, fecha_emision),
    INDEX idx_recetas_medico (medico_id, fecha_emision),
    CONSTRAINT fk_recetas_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
    CONSTRAINT fk_recetas_medico FOREIGN KEY (medico_id) REFERENCES medicos(id) ON DELETE RESTRICT,
    CONSTRAINT fk_recetas_consulta FOREIGN KEY (consulta_id) REFERENCES consultas_clinicas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS receta_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    receta_id INT UNSIGNED NOT NULL,
    orden TINYINT UNSIGNED NOT NULL DEFAULT 1,
    medicamento VARCHAR(150) NOT NULL,
    dosis VARCHAR(100) NOT NULL,
    frecuencia VARCHAR(100) NULL,
    duracion VARCHAR(100) NULL,
    indicaciones VARCHAR(255) NULL,
    INDEX idx_receta_items (receta_id, orden),
    CONSTRAINT fk_receta_items_receta FOREIGN KEY (receta_id) REFERENCES recetas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    $db->exec('INSERT INTO lux_schema_version (version) VALUES (18)');
}
// Número de receta que se imprime (R-000123)
function lux_receta_codigo($id) { return 'R-' . str_pad((string)(int)$id, 6, '0', STR_PAD_LEFT); }

// Historias clínicas: solo las leen y cargan los médicos activos del consultorio (ni el administrador ni el resto del personal).
// Devuelve la fila del médico (id, usuario_id, nombre) o null si el usuario no está autorizado.
function lux_medico_autorizado($db, $u) {
    if (!$u || empty($u['id'])) return null;
    $s = $db->prepare("SELECT m.id, m.usuario_id, us.nombre FROM medicos m JOIN usuarios us ON us.id=m.usuario_id WHERE m.usuario_id=? AND us.estado='Activo' AND us.negocio_id=4");
    $s->execute([(int)$u['id']]);
    return $s->fetch() ?: null;
}
// Registro de quién abrió o modificó una historia clínica; las aperturas repetidas en 10 minutos cuentan como una
function lux_hc_registrar_acceso($db, $pacienteId, $profesionalId, $accion) {
    if ($accion === 'ver') {
        $s = $db->prepare("SELECT COUNT(*) FROM historia_accesos WHERE usuario_id=? AND profesional_id=? AND accion='ver' AND fecha>?");
        $s->execute([(int)$pacienteId, (int)$profesionalId, date('Y-m-d H:i:s', time() - 600)]);
        if ((int)$s->fetchColumn()) return;
    }
    $db->prepare('INSERT INTO historia_accesos(usuario_id,profesional_id,accion,fecha) VALUES(?,?,?,?)')
       ->execute([(int)$pacienteId, (int)$profesionalId, $accion, date('Y-m-d H:i:s')]);
}

// Validación de la ficha del paciente (compartida por recepción y por el propio cliente)
function lux_pac_validar($db, array $in, $usuarioId) {
    $err = [];
    $t = function ($k, $max) use ($in, &$err) {
        $v = isset($in[$k]) && is_scalar($in[$k]) ? trim(preg_replace('/\s+/u', ' ', (string)$in[$k])) : '';
        if (mb_strlen($v) > $max) $err[] = "El campo $k supera los $max caracteres.";
        return $v;
    };
    $tel = function ($v, $etiqueta) use (&$err) {
        if ($v !== '' && (!preg_match('/^[0-9 +().-]+$/', $v) || strlen(preg_replace('/\D/', '', $v)) < 6)) $err[] = "$etiqueta no es válido.";
    };
    $d = [
        'nombre' => $t('nombre', 150),
        'sexo' => $t('sexo', 20),
        'telefono' => $t('telefono', 30),
        'direccion' => $t('direccion', 255),
        'obra_social' => $t('obra_social', 100),
        'numero_afiliado' => $t('numero_afiliado', 60),
        'ce_nombre' => $t('ce_nombre', 150),
        'ce_telefono' => $t('ce_telefono', 30),
        'ce_relacion' => $t('ce_relacion', 50),
        'fecha_nacimiento' => $t('fecha_nacimiento', 10),
        'dni' => preg_replace('/\D/', '', isset($in['dni']) && is_scalar($in['dni']) ? (string)$in['dni'] : ''),
    ];
    if ($d['nombre'] === '') $err[] = 'Ingresá el nombre y apellido.';
    if (!preg_match('/^\d{7,8}$/', $d['dni'])) $err[] = 'El DNI debe tener 7 u 8 números.';
    else {
        $s = $db->prepare('SELECT COUNT(*) FROM pacientes WHERE dni=? AND usuario_id<>?');
        $s->execute([$d['dni'], (int)$usuarioId]);
        if ((int)$s->fetchColumn()) $err[] = 'Ya existe una ficha con ese DNI. Verificá el número o consultá en recepción.';
    }
    $f = DateTime::createFromFormat('Y-m-d', $d['fecha_nacimiento']);
    if (!$f || $f->format('Y-m-d') !== $d['fecha_nacimiento'] || $d['fecha_nacimiento'] < '1900-01-01' || $d['fecha_nacimiento'] > date('Y-m-d'))
        $err[] = 'La fecha de nacimiento no es válida.';
    if (!in_array($d['sexo'], ['Femenino', 'Masculino', 'Otro'], true)) $err[] = 'Elegí el sexo.';
    if ($d['telefono'] === '') $err[] = 'Ingresá un teléfono.';
    $tel($d['telefono'], 'El teléfono');
    $tel($d['ce_telefono'], 'El teléfono del contacto de emergencia');
    if ($d['numero_afiliado'] !== '' && !preg_match('/^[\p{L}\p{N} .\/-]+$/u', $d['numero_afiliado'])) $err[] = 'El número de afiliado tiene caracteres no válidos.';
    if ($d['numero_afiliado'] !== '' && $d['obra_social'] === '') $err[] = 'Indicá la obra social del número de afiliado.';
    if (($d['ce_nombre'] !== '' || $d['ce_telefono'] !== '' || $d['ce_relacion'] !== '') && ($d['ce_nombre'] === '' || $d['ce_telefono'] === ''))
        $err[] = 'Para el contacto de emergencia completá nombre y teléfono.';
    return [$d, array_values(array_unique($err))];
}

// Crea o actualiza la ficha del paciente (el llamador abre la transacción). Devuelve true si la ficha era nueva.
function lux_pac_guardar($db, $usuarioId, array $d) {
    $vals = [$d['dni'], $d['fecha_nacimiento'], $d['sexo'], $d['telefono'], $d['direccion'] ?: null, $d['obra_social'] ?: null, $d['numero_afiliado'] ?: null,
        $d['ce_nombre'] ?: null, $d['ce_telefono'] ?: null, $d['ce_relacion'] ?: null];
    $existe = $db->prepare('SELECT id FROM pacientes WHERE usuario_id=?'); $existe->execute([(int)$usuarioId]);
    if ($existe->fetchColumn()) {
        $db->prepare('UPDATE pacientes SET dni=?,fecha_nacimiento=?,sexo=?,telefono=?,direccion=?,obra_social=?,numero_afiliado=?,
            contacto_emergencia_nombre=?,contacto_emergencia_telefono=?,contacto_emergencia_relacion=? WHERE usuario_id=?')
           ->execute(array_merge($vals, [(int)$usuarioId]));
        return false;
    }
    $db->prepare('INSERT INTO pacientes(dni,fecha_nacimiento,sexo,telefono,direccion,obra_social,numero_afiliado,
        contacto_emergencia_nombre,contacto_emergencia_telefono,contacto_emergencia_relacion,usuario_id,creado) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
       ->execute(array_merge($vals, [(int)$usuarioId, date('Y-m-d H:i:s')]));
    return true;
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
