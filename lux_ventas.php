<?php
require __DIR__ . '/lux_layout.php';
$u = lux_require(['superadmin', 'admin', 'empleado']);
$nid = lux_negocio_id($u);
if (!$nid) lux_redirect('lux_negocios.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['accion'] ?? '') === 'atender' && $nid == 4) {
        $db->prepare("UPDATE turnos SET estado='Atendido' WHERE id=? AND negocio_id=4")->execute([$_POST['id']]);
        flash('Turno marcado como atendido.'); lux_redirect('lux_ventas.php');
    }
    lux_redirect('lux_ventas.php');
}
$neg = $db->prepare('SELECT * FROM negocios WHERE id=?'); $neg->execute([$nid]); $neg = $neg->fetch();
$hoy = date('Y-m-d');
$k = $db->prepare('SELECT COALESCE(SUM(total),0) t, COALESCE(SUM(cantidad),0) c FROM ventas WHERE negocio_id=? AND fecha>=? AND fecha<?');
$k->execute([$nid, $hoy . ' 00:00:00', date('Y-m-d 00:00:00', strtotime('+1 day'))]); $k = $k->fetch();
$A = lux_alertas($db, $nid);
$rec = $db->prepare('SELECT v.*, p.nombre producto FROM ventas v JOIN productos p ON p.id=v.producto_id WHERE v.negocio_id=? ORDER BY v.fecha DESC LIMIT 8');
$rec->execute([$nid]); $rec = $rec->fetchAll();
lux_header('Mi negocio', ($neg['nombre'] ?? '—') . ' — resumen de hoy'); ?>
<div class="kpi-row" style="grid-template-columns:repeat(3,1fr)">
<div class="kpi"><div class="label">Ventas hoy</div><div class="value"><?= money($k['t']) ?></div></div>
<div class="kpi"><div class="label">Unidades vendidas</div><div class="value"><?= $k['c'] ?></div></div>
<?php if ($A['clinica']): ?>
<div class="kpi kpi-alert"><div class="kpi-ico">🗓️</div><div><div class="label">Turnos pendientes</div><div class="value"><?= $A['pendientes'] ?></div>
<div class="delta <?= $A['vencidos'] ? 'down' : 'up' ?>"><?= $A['vencidos'] ? $A['vencidos'] . ' sin atender' : 'Todo al día' ?></div></div></div></div>
<?php else: ?>
<div class="kpi kpi-alert"><div class="kpi-ico">⚠️</div><div><div class="label">Productos a reponer</div><div class="value"><?= $A['reponer'] ?></div>
<div class="delta <?= $A['sin_stock'] ? 'down' : 'up' ?>"><?= $A['sin_stock'] ? $A['sin_stock'] . ' sin stock' : 'Ninguno sin stock' ?> · <a href="lux_inventario.php">Ver inventario</a></div></div></div></div>
<?php endif; ?>
<?php lux_render_alertas($A); ?>
<div class="section-title"><h2>Ventas recientes</h2><a class="btn-link" href="lux_historial_ventas.php">Ver historial completo →</a></div>
<table><thead><tr><th>Fecha</th><th>Producto</th><th>Cantidad</th><th>Total</th></tr></thead><tbody>
<?php foreach ($rec as $v): ?><tr><td><?= h(date('d/m H:i', strtotime($v['fecha']))) ?></td><td><?= h($v['producto']) ?></td><td><?= $v['cantidad'] ?></td><td><?= money($v['total']) ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php if ($nid == 4):
  $tt = $db->query("SELECT t.*, u.nombre cliente FROM turnos t JOIN usuarios u ON u.id=t.cliente_id WHERE t.negocio_id=4 AND t.estado<>'Cancelado' ORDER BY t.fecha DESC LIMIT 15")->fetchAll(); ?>
<div class="section-title" style="margin-top:28px"><h2>Turnos</h2></div>
<table><thead><tr><th>Fecha</th><th>Paciente</th><th>Profesional</th><th>Estado</th><th></th></tr></thead><tbody>
<?php foreach ($tt as $t): ?><tr><td><?= h(date('d/m H:i', strtotime($t['fecha']))) ?></td><td><?= h($t['cliente']) ?></td><td><?= h($t['profesional']) ?></td><td><?= h($t['estado']) ?></td>
<td><?php if ($t['estado'] === 'Pendiente'): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="accion" value="atender"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn-link">Marcar atendido</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?>
<p style="margin-top:22px"><a class="btn-small" href="lux_registrar_venta.php">+ <?= $nid == 4 ? 'Registrar cobro' : 'Registrar venta' ?></a></p>
<?php lux_footer();
