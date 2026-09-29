<?php
require_once __DIR__ . '/lux_config.php';

function lux_nav($rol) {
    if ($rol === 'superadmin') {
        $n = ['lux_panel_general.php' => 'Panel general', 'lux_negocios.php' => 'Negocios'];
        if (!empty($_SESSION['negocio_activo'])) $n += ['lux_ventas.php' => '↳ Ventas / turnos', 'lux_inventario.php' => '↳ Inventario'];
        return $n + ['lux_usuarios.php' => 'Usuarios y roles', 'lux_perfil.php' => 'Mi perfil'];
    }
    if ($rol === 'cliente') return [
        'lux_mis_turnos.php' => 'Mis compras y turnos', 'lux_explorar.php' => 'Explorar negocios',
        'lux_perfil.php' => 'Mi perfil'];
    return ['lux_ventas.php' => 'Mi negocio', 'lux_inventario.php' => 'Inventario', 'lux_perfil.php' => 'Mi perfil'];
}
function lux_header($titulo, $sub) {
    $u = lux_user();
    $labels = ['superadmin' => 'Super Admin', 'admin' => 'Administrador', 'empleado' => 'Empleado', 'cliente' => 'Cliente'];
    $actual = basename($_SERVER['SCRIPT_NAME']);
    $ini = strtoupper(mb_substr($u['nombre'], 0, 1) . (mb_substr(strrchr(' ' . $u['nombre'], ' '), 1, 1) ?: ''));
    ?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Grupo Lux · <?= h($titulo) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="lux_estilos.css"></head><body><div class="shell">
<aside class="sidebar"><div class="brand">GRUPO <span>LUX</span></div>
<?php foreach (lux_nav($u['rol']) as $f => $l): ?>
<a class="nav-item <?= $f === $actual ? 'active' : '' ?>" href="<?= $f ?>"><span class="dot"></span><?= h($l) ?></a>
<?php endforeach; ?>
<div class="sidebar-foot">Sesión: <b style="color:var(--text)"><?= $labels[$u['rol']] ?></b><br>
<a href="lux_logout.php" style="text-decoration:underline">Cerrar sesión</a></div></aside>
<div class="main"><div class="topbar"><div><h1><?= h($titulo) ?></h1><div class="sub"><?= h($sub) ?></div></div>
<div class="who"><span>Conectado como <b><?= h($u['nombre']) ?></b></span><div class="avatar"><?= h($ini) ?></div></div></div>
<div class="content">
<?php if ($u['rol'] === 'superadmin' && !empty($_SESSION['negocio_activo']) && in_array($actual, ['lux_ventas.php', 'lux_inventario.php'])):
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
