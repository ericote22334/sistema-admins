<?php
require_once __DIR__ . '/lux_config.php';

function lux_nav($rol) {
    global $db;
    if ($rol === 'superadmin') {
        $n = ['lux_panel_general.php' => 'Panel general', 'lux_negocios.php' => 'Negocios'];
        if (!empty($_SESSION['negocio_activo'])) $n += ['lux_ventas.php' => '↳ Ventas / turnos', 'lux_inventario.php' => '↳ Inventario'];
        if ((int)($_SESSION['negocio_activo'] ?? 0) === 4) $n += ['lux_calendario.php' => '↳ Calendario', 'lux_pacientes.php' => '↳ Pacientes', 'lux_medicos.php' => '↳ Médicos', 'lux_especialidades.php' => '↳ Especialidades', 'lux_auditoria_historias.php' => '↳ Auditoría de historias', 'lux_reportes.php' => '↳ Reportes'];
        if ((int)($_SESSION['negocio_activo'] ?? 0) === 2) $n += ['lux_carrito.php' => '↳ Carrito de farmacia'];
        if (!empty($_SESSION['negocio_activo'])) $n += ['lux_registrar_venta.php' => '↳ ' . ((int)$_SESSION['negocio_activo'] === 4 ? 'Registrar cobro' : 'Registrar venta')];
        if (!empty($_SESSION['negocio_activo']) && (int)$_SESSION['negocio_activo'] !== 4) $n += ['lux_historial_ventas.php' => '↳ Historial de ventas'];
        if (!empty($_SESSION['negocio_activo']) && (int)$_SESSION['negocio_activo'] !== 4) $n += ['lux_pedidos.php' => '↳ Pedidos'];
        return $n + ['lux_usuarios.php' => 'Usuarios y roles', 'lux_perfil.php' => 'Mi perfil'];
    }
    if ($rol === 'cliente') return [
        'lux_mis_turnos.php' => 'Mis compras y turnos', 'lux_explorar.php' => 'Explorar negocios',
        'lux_mis_datos.php' => 'Mis datos de paciente', 'lux_mis_recetas.php' => 'Mis recetas',
        'lux_perfil.php' => 'Mi perfil'];
    $n = ['lux_ventas.php' => 'Mi negocio', 'lux_inventario.php' => 'Inventario'];
    if ((int)(lux_user()['negocio_id'] ?? 0) === 4 && lux_puede_pacientes(lux_user())) $n['lux_calendario.php'] = 'Calendario de turnos';
    if ((int)(lux_user()['negocio_id'] ?? 0) === 4 && lux_puede_pacientes(lux_user())) $n['lux_pacientes.php'] = 'Pacientes';
    if ((int)(lux_user()['negocio_id'] ?? 0) === 4 && lux_puede_pacientes(lux_user())) $n['lux_medicos.php'] = 'Médicos';
    if ((int)(lux_user()['negocio_id'] ?? 0) === 4 && lux_medico_autorizado($db, lux_user())) { $n['lux_consultas.php'] = 'Mis consultas del día'; $n['lux_historias.php'] = 'Historias clínicas'; $n['lux_recetas.php'] = 'Recetas'; }
    if ((int)(lux_user()['negocio_id'] ?? 0) === 4 && $rol === 'admin') $n['lux_especialidades.php'] = 'Especialidades';
    if ((int)(lux_user()['negocio_id'] ?? 0) === 4 && $rol === 'admin') $n['lux_auditoria_historias.php'] = 'Auditoría de historias';
    if ((int)(lux_user()['negocio_id'] ?? 0) === 4 && $rol === 'admin') $n['lux_reportes.php'] = 'Reportes y estadísticas';
    if ((int)(lux_user()['negocio_id'] ?? 0) === 2) $n['lux_carrito.php'] = 'Carrito de farmacia';
    $n['lux_registrar_venta.php'] = (int)(lux_user()['negocio_id'] ?? 0) === 4 ? 'Registrar cobro' : 'Registrar venta';
    if ((int)(lux_user()['negocio_id'] ?? 0) !== 4) $n['lux_historial_ventas.php'] = 'Historial de ventas';
    if ((int)(lux_user()['negocio_id'] ?? 0) !== 4) $n['lux_pedidos.php'] = 'Pedidos';
    if ($rol === 'admin') $n['lux_usuarios.php'] = 'Empleados';
    return $n + ['lux_perfil.php' => 'Mi perfil'];
}
function lux_header($titulo, $sub) {
    global $db;
    $u = lux_user();
    $labels = ['superadmin' => 'Super Admin', 'admin' => 'Administrador', 'empleado' => 'Empleado', 'cliente' => 'Cliente'];
    $actual = basename($_SERVER['SCRIPT_NAME']);
    $ini = strtoupper(mb_substr($u['nombre'], 0, 1) . (mb_substr(strrchr(' ' . $u['nombre'], ' '), 1, 1) ?: ''));
    ?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Grupo Lux · <?= h($titulo) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="lux_tema.js?v=<?= (int)@filemtime(__DIR__ . '/lux_tema.js') ?>"></script>
<link rel="stylesheet" href="lux_estilos.css?v=<?= (int)@filemtime(__DIR__ . '/lux_estilos.css') ?>"></head><body><div class="shell">
<aside class="sidebar"><div class="brand">GRUPO <span>LUX</span></div>
<?php foreach (lux_nav($u['rol']) as $f => $l): ?>
<a class="nav-item <?= $f === $actual ? 'active' : '' ?>" href="<?= $f ?>"><span class="dot"></span><?= h($l) ?></a>
<?php endforeach; ?>
<div class="sidebar-foot">Sesión: <b style="color:var(--text)"><?= h(($u['puesto'] ?? '') ?: $labels[$u['rol']]) ?></b><br>
<a href="lux_logout.php" style="text-decoration:underline">Cerrar sesión</a></div></aside>
<div class="main"><div class="topbar"><div><h1><?= h($titulo) ?></h1><div class="sub"><?= h($sub) ?></div></div>
<div class="topbar-actions"><button class="theme-toggle" type="button" data-theme-toggle aria-label="Cambiar a modo oscuro" aria-pressed="false"><span data-theme-icon aria-hidden="true">🌙</span><span data-theme-label>Modo oscuro</span></button>
<div class="who"><span>Conectado como <b><?= h($u['nombre']) ?></b></span><div class="avatar"><?= h($ini) ?></div></div></div></div>
<div class="content">
<?php if ($u['rol'] === 'superadmin' && !empty($_SESSION['negocio_activo']) && in_array($actual, ['lux_ventas.php', 'lux_inventario.php', 'lux_pedidos.php', 'lux_carrito.php', 'lux_registrar_venta.php', 'lux_historial_ventas.php', 'lux_pacientes.php', 'lux_medicos.php', 'lux_especialidades.php', 'lux_calendario.php', 'lux_auditoria_historias.php', 'lux_reportes.php'])):
  $nn = $db->prepare('SELECT nombre FROM negocios WHERE id=?'); $nn->execute([$_SESSION['negocio_activo']]); ?>
<div class="flash">Estás dentro del sistema de <b><?= h($nn->fetchColumn()) ?></b> como Super Admin.
<a href="lux_entrar.php?salir=1" style="text-decoration:underline">Volver al panel general</a> · <a href="lux_negocios.php" style="text-decoration:underline">Cambiar de negocio</a></div>
<?php endif; ?>
<?php if (!empty($_SESSION['flash'])): [$m, $e] = $_SESSION['flash']; unset($_SESSION['flash']); ?>
<div class="flash <?= $e ? 'err' : '' ?>"><?= h($m) ?></div><?php endif;
}
function lux_footer() { echo '</div></div></div></body></html>'; }

function lux_biz_panel($n, $total) {
    $pct = $total > 0 ? round($n['ventas'] * 100 / $total) : 0; ?>
<div class="biz-panel" style="--biz-color:var(--<?= h($n['color']) ?>)">
<div class="head"><div><div class="name"><?= h($n['nombre']) ?></div><div class="type"><?= h($n['tipo']) ?></div></div>
<span class="status-pill online">En línea</span></div>
<div class="metrics">
<div><div class="n"><?= money($n['ventas']) ?></div><div class="l">Ventas hoy</div></div>
<div><div class="n"><?= $pct ?>%</div><div class="l">del total</div></div>
<div><div class="n"><?= (int)$n['alertas'] ?></div><div class="l">Alertas de stock</div></div></div>
<div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
<?php if (lux_user()['rol'] === 'superadmin'): ?><a class="btn-small" href="lux_entrar.php?id=<?= $n['id'] ?>" style="display:inline-block;margin-top:14px">Entrar al sistema →</a><?php endif; ?></div>
<?php }

// Alertas según el sistema: comercios (stock + pedidos) o consultorio (turnos)
// Cada alerta: [color, título, detalle, acción opcional]
function lux_alertas($db, $nid) {
    $q = function ($sql, $p = []) use ($db) { $s = $db->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); };
    $r = ['clinica' => ((int)$nid === 4), 'items' => [], 'reponer' => 0, 'sin_stock' => 0, 'pendientes' => 0, 'vencidos' => 0, 'hoy' => 0];
    if ($r['clinica']) {
        $ahora = date('Y-m-d H:i:s');
        $r['pendientes'] = $q("SELECT COUNT(*) FROM turnos WHERE negocio_id=? AND estado IN ('Pendiente','Confirmado')", [$nid]);
        $r['vencidos'] = $q("SELECT COUNT(*) FROM turnos WHERE negocio_id=? AND estado IN ('Pendiente','Confirmado') AND fecha<?", [$nid, $ahora]);
        $r['hoy'] = $q("SELECT COUNT(*) FROM turnos WHERE negocio_id=? AND estado<>'Cancelado' AND fecha>=? AND fecha<?",
            [$nid, date('Y-m-d 00:00:00'), date('Y-m-d 00:00:00', strtotime('+1 day'))]);
        if ($r['vencidos']) $r['items'][] = ['rojo', $r['vencidos'] . ($r['vencidos'] == 1 ? ' turno sin atender' : ' turnos sin atender'), 'La fecha ya pasó y siguen pendientes'];
        if ($r['hoy']) $r['items'][] = ['amarillo', $r['hoy'] . ($r['hoy'] == 1 ? ' turno hoy' : ' turnos hoy'), 'Programados para el día de hoy'];
        $porCobrar = $q("SELECT COUNT(*) FROM consultas_clinicas WHERE estado='Finalizada' AND cobro='Pendiente'");
        if ($porCobrar) $r['items'][] = ['rojo', $porCobrar . ($porCobrar == 1 ? ' consulta por cobrar' : ' consultas por cobrar'), 'Finalizadas y todavía sin cobro registrado'];
        if ($r['pendientes']) $r['items'][] = ['caja', $r['pendientes'] . ($r['pendientes'] == 1 ? ' turno pendiente' : ' turnos pendientes'), 'Esperando atención'];
        return $r;
    }
    $st = $db->prepare('SELECT nombre, stock, minimo FROM productos WHERE negocio_id=? AND (stock<minimo OR stock=0) ORDER BY (stock=0) DESC, stock ASC, nombre');
    $st->execute([$nid]);
    foreach ($st->fetchAll() as $p) {
        $r['reponer']++;
        if ((int)$p['stock'] === 0) { $r['sin_stock']++; $r['items'][] = ['rojo', $p['nombre'], 'Sin stock — reponer con urgencia']; }
        else $r['items'][] = ['amarillo', $p['nombre'], 'Quedan ' . (int)$p['stock'] . ((int)$p['stock'] === 1 ? ' unidad' : ' unidades')];
    }
    $r['pendientes'] = $q("SELECT COUNT(*) FROM pedidos WHERE negocio_id=? AND estado='Pendiente'", [$nid]);
    if ($r['pendientes']) $r['items'][] = ['caja', $r['pendientes'] . ($r['pendientes'] == 1 ? ' pedido pendiente' : ' pedidos pendientes'), 'Esperando preparación', 'ver_pedidos'];
    return $r;
}
function lux_render_alertas($r) { ?>
<div class="alert-panel"><div class="section-title"><h2>Alertas</h2><span class="alert-sub"><?= $r['items'] ? 'requieren acción' : 'todo en orden' ?></span></div>
<?php if (!$r['items']): ?><div class="alert-item verde"><span class="alert-dot verde"></span><div class="alert-txt"><b>Sin alertas</b><span>Todo está en orden en este negocio.</span></div></div><?php endif; ?>
<?php foreach ($r['items'] as $it): ?>
<div class="alert-item <?= h($it[0]) ?>"><span class="alert-dot <?= h($it[0]) ?>"><?= $it[0] === 'caja' ? '📦' : '' ?></span>
<div class="alert-txt"><b><?= h($it[1]) ?></b><span><?= h($it[2]) ?></span></div>
<?php if (($it[3] ?? '') === 'ver_pedidos'): ?><a class="alert-link" href="lux_pedidos.php?estado=Pendiente">Ver pedidos →</a><?php endif; ?></div>
<?php endforeach; ?></div>
<?php }

// Campos del formulario de la ficha del paciente (recepción y cliente); $form trae los valores actuales
function lux_pac_campos($form) {
    $sexos = ['Femenino', 'Masculino', 'Otro'];
    $obras = ['Particular (sin obra social)', 'PAMI', 'IOMA', 'OSDE', 'Swiss Medical', 'Galeno', 'Medifé', 'OMINT', 'Sancor Salud', 'Unión Personal', 'OSECAC'];
    $v = function ($k) use ($form) { return h($form[$k] ?? ''); }; ?>
<div class="field ancho"><label>Nombre y apellido</label><input name="nombre" maxlength="150" required value="<?= $v('nombre') ?>"></div>
<div class="field"><label>DNI</label><input name="dni" inputmode="numeric" maxlength="11" placeholder="Solo números" required value="<?= $v('dni') ?>"></div>
<div class="field"><label>Fecha de nacimiento</label><input type="date" name="fecha_nacimiento" min="1900-01-01" max="<?= date('Y-m-d') ?>" required value="<?= $v('fecha_nacimiento') ?>"></div>
<div class="field"><label>Sexo</label><select name="sexo" required><option value="" disabled <?= ($form['sexo'] ?? '') === '' ? 'selected' : '' ?>>Elegí una opción</option>
<?php foreach ($sexos as $s): ?><option value="<?= h($s) ?>" <?= ($form['sexo'] ?? '') === $s ? 'selected' : '' ?>><?= h($s) ?></option><?php endforeach; ?></select></div>
<div class="field"><label>Teléfono</label><input type="tel" name="telefono" maxlength="30" required value="<?= $v('telefono') ?>"></div>
<div class="field ancho"><label>Dirección</label><input name="direccion" maxlength="255" placeholder="Calle, número, localidad" value="<?= $v('direccion') ?>"></div>
<div class="field"><label>Obra social</label><input name="obra_social" list="lux-obras" maxlength="100" value="<?= $v('obra_social') ?>">
<datalist id="lux-obras"><?php foreach ($obras as $o): ?><option value="<?= h($o) ?>"><?php endforeach; ?></datalist></div>
<div class="field"><label>Número de afiliado</label><input name="numero_afiliado" maxlength="60" value="<?= $v('numero_afiliado') ?>"></div>
<div class="field ancho"><h3 style="margin:10px 0 0;font-size:14px;font-family:'Inter',sans-serif">Contacto de emergencia</h3></div>
<div class="field"><label>Nombre</label><input name="ce_nombre" maxlength="150" value="<?= $v('ce_nombre') ?>"></div>
<div class="field"><label>Teléfono</label><input type="tel" name="ce_telefono" maxlength="30" value="<?= $v('ce_telefono') ?>"></div>
<div class="field"><label>Relación</label><input name="ce_relacion" maxlength="50" placeholder="Madre, esposo, amiga…" value="<?= $v('ce_relacion') ?>"></div>
<?php }
