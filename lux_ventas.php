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
    $cant = max(1, (int)($_POST['cantidad'] ?? 1));
    $st = $db->prepare('SELECT * FROM productos WHERE id=? AND negocio_id=?');
    $st->execute([$_POST['producto_id'] ?? 0, $nid]);
    if (!($p = $st->fetch())) flash('Producto inválido.', true);
    else {
        $db->beginTransaction();
        $ok = $db->prepare('UPDATE productos SET stock=stock-? WHERE id=? AND stock>=?');
        $ok->execute([$cant, $p['id'], $cant]);
        if ($ok->rowCount() === 0) { $db->rollBack(); flash("Stock insuficiente de {$p['nombre']}.", true); }
        else {
            $db->prepare('INSERT INTO ventas(negocio_id,producto_id,usuario_id,cantidad,total,fecha) VALUES(?,?,?,?,?,?)')
               ->execute([$nid, $p['id'], $u['id'], $cant, $cant * $p['precio'], date('Y-m-d H:i:s')]);
            $db->commit(); flash("Venta registrada: $cant × {$p['nombre']}.");
        }
    }
    lux_redirect('lux_ventas.php');
}
$neg = $db->prepare('SELECT * FROM negocios WHERE id=?'); $neg->execute([$nid]); $neg = $neg->fetch();
$hoy = date('Y-m-d');
$k = $db->prepare('SELECT COALESCE(SUM(total),0) t, COALESCE(SUM(cantidad),0) c FROM ventas WHERE negocio_id=? AND date(fecha)=?');
$k->execute([$nid, $hoy]); $k = $k->fetch();
$al = $db->prepare('SELECT COUNT(*) FROM productos WHERE negocio_id=? AND stock<minimo'); $al->execute([$nid]); $al = $al->fetchColumn();
$rec = $db->prepare('SELECT v.*, p.nombre producto FROM ventas v JOIN productos p ON p.id=v.producto_id WHERE v.negocio_id=? ORDER BY v.fecha DESC LIMIT 8');
$rec->execute([$nid]); $rec = $rec->fetchAll();
$prods = $db->prepare('SELECT * FROM productos WHERE negocio_id=? AND stock>0 ORDER BY nombre'); $prods->execute([$nid]); $prods = $prods->fetchAll();
lux_header('Mi negocio', ($neg['nombre'] ?? '—') . ' — resumen de hoy'); ?>
<div class="kpi-row" style="grid-template-columns:repeat(3,1fr)">
<div class="kpi"><div class="label">Ventas hoy</div><div class="value"><?= money($k['t']) ?></div></div>
<div class="kpi"><div class="label">Unidades vendidas</div><div class="value"><?= $k['c'] ?></div></div>
<div class="kpi"><div class="label">Alertas de stock</div><div class="value"><?= $al ?></div><div class="delta down"><a href="lux_inventario.php">Ver inventario</a></div></div></div>
<div class="section-title"><h2>Ventas recientes</h2></div>
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
<form method="post" class="quick-form"><?= csrf_field() ?>
<div class="field"><label>Producto</label><select name="producto_id"><?php foreach ($prods as $p): ?>
<option value="<?= $p['id'] ?>"><?= h($p['nombre']) ?> (stock <?= $p['stock'] ?> · <?= money($p['precio']) ?>)</option><?php endforeach; ?></select></div>
<div class="field" style="max-width:90px"><label>Cantidad</label><input type="number" name="cantidad" value="1" min="1"></div>
<button class="btn-small">Registrar venta</button></form>
<?php lux_footer();
