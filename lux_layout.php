<?php
require_once __DIR__ . '/lux_config.php';

function lux_nav($rol) {
    if ($rol === 'superadmin') {
        $n = ['lux_panel_general.php' => 'Panel general', 'lux_negocios.php' => 'Negocios'];
        if (!empty($_SESSION['negocio_activo'])) $n += ['lux_ventas.php' => '↳ Ventas / turnos', 'lux_inventario.php' => '↳ Inventario'];
        if (!empty($_SESSION['negocio_activo'])) $n += ['lux_registrar_venta.php' => '↳ ' . ((int)$_SESSION['negocio_activo'] === 4 ? 'Registrar cobro' : 'Registrar venta')];
        if (!empty($_SESSION['negocio_activo'])) $n += ['lux_historial_ventas.php' => '↳ ' . ((int)$_SESSION['negocio_activo'] === 4 ? 'Historial de cobros' : 'Historial de ventas')];
        if (!empty($_SESSION['negocio_activo']) && (int)$_SESSION['negocio_activo'] !== 4) $n += ['lux_pedidos.php' => '↳ Pedidos'];
        return $n + ['lux_usuarios.php' => 'Usuarios y roles', 'lux_perfil.php' => 'Mi perfil'];
    }
    if ($rol === 'cliente') return [
        'lux_mis_turnos.php' => 'Mis compras y turnos', 'lux_explorar.php' => 'Explorar negocios',
        'lux_perfil.php' => 'Mi perfil'];
    $n = ['lux_ventas.php' => 'Mi negocio', 'lux_inventario.php' => 'Inventario'];
    $n['lux_registrar_venta.php'] = (int)(lux_user()['negocio_id'] ?? 0) === 4 ? 'Registrar cobro' : 'Registrar venta';
    $n['lux_historial_ventas.php'] = (int)(lux_user()['negocio_id'] ?? 0) === 4 ? 'Historial de cobros' : 'Historial de ventas';
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
<link rel="stylesheet" href="lux_estilos.css?v=<?= (int)@filemtime(__DIR__ . '/lux_estilos.css') ?>"></head><body><div class="shell">
<aside class="sidebar"><div class="brand">GRUPO <span>LUX</span></div>
<?php foreach (lux_nav($u['rol']) as $f => $l): ?>
<a class="nav-item <?= $f === $actual ? 'active' : '' ?>" href="<?= $f ?>"><span class="dot"></span><?= h($l) ?></a>
<?php endforeach; ?>
<div class="sidebar-foot">Sesión: <b style="color:var(--text)"><?= h(($u['puesto'] ?? '') ?: $labels[$u['rol']]) ?></b><br>
<a href="lux_logout.php" style="text-decoration:underline">Cerrar sesión</a></div></aside>
<div class="main"><div class="topbar"><div><h1><?= h($titulo) ?></h1><div class="sub"><?= h($sub) ?></div></div>
<div class="who"><span>Conectado como <b><?= h($u['nombre']) ?></b></span><div class="avatar"><?= h($ini) ?></div></div></div>
<div class="content">
<?php if ($u['rol'] === 'superadmin' && !empty($_SESSION['negocio_activo']) && in_array($actual, ['lux_ventas.php', 'lux_inventario.php', 'lux_pedidos.php', 'lux_registrar_venta.php', 'lux_historial_ventas.php'])):
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
        $r['pendientes'] = $q("SELECT COUNT(*) FROM turnos WHERE negocio_id=? AND estado='Pendiente'", [$nid]);
        $r['vencidos'] = $q("SELECT COUNT(*) FROM turnos WHERE negocio_id=? AND estado='Pendiente' AND fecha<?", [$nid, $ahora]);
        $r['hoy'] = $q("SELECT COUNT(*) FROM turnos WHERE negocio_id=? AND estado<>'Cancelado' AND fecha>=? AND fecha<?",
            [$nid, date('Y-m-d 00:00:00'), date('Y-m-d 00:00:00', strtotime('+1 day'))]);
        if ($r['vencidos']) $r['items'][] = ['rojo', $r['vencidos'] . ($r['vencidos'] == 1 ? ' turno sin atender' : ' turnos sin atender'), 'La fecha ya pasó y siguen pendientes'];
        if ($r['hoy']) $r['items'][] = ['amarillo', $r['hoy'] . ($r['hoy'] == 1 ? ' turno hoy' : ' turnos hoy'), 'Programados para el día de hoy'];
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
