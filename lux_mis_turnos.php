<?php
require __DIR__ . '/lux_layout.php';
$u = lux_require(['cliente']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = $_POST['accion'] ?? '';
    if ($a === 'reservar') {
        $f = strtotime($_POST['fecha'] ?? '');
        if (!$f || $f < time()) flash('Elegí una fecha futura.', true);
        else { $db->prepare("INSERT INTO turnos(cliente_id,negocio_id,profesional,fecha) VALUES(?,4,'Dra. Paula Nieva',?)")
                  ->execute([$u['id'], date('Y-m-d H:i', $f)]); flash('Turno reservado.'); }
    } elseif ($a === 'cancelar') {
        $db->prepare("UPDATE turnos SET estado='Cancelado' WHERE id=? AND cliente_id=?")->execute([$_POST['id'], $u['id']]);
        flash('Turno cancelado.');
    }
    lux_redirect('lux_mis_turnos.php');
}
$t = $db->prepare("SELECT * FROM turnos WHERE cliente_id=? AND estado<>'Cancelado' ORDER BY fecha DESC"); $t->execute([$u['id']]); $turnos = $t->fetchAll();
$proximo = null; foreach (array_reverse($turnos) as $x) if (strtotime($x['fecha']) >= time()) { $proximo = $x; break; }
$c = $db->prepare('SELECT v.*, n.nombre negocio, p.nombre producto FROM ventas v JOIN negocios n ON n.id=v.negocio_id JOIN productos p ON p.id=v.producto_id WHERE v.cliente_id=? ORDER BY v.fecha DESC LIMIT 10');
$c->execute([$u['id']]); $compras = $c->fetchAll();
$p = $db->prepare('SELECT pe.id,pe.fecha,pe.cantidad,pe.estado,pe.medio_pago,pe.entrega,pe.direccion_envio,pe.numero_obra_social,n.id negocio_id,n.nombre negocio,pr.nombre producto
    FROM pedidos pe JOIN negocios n ON n.id=pe.negocio_id JOIN productos pr ON pr.id=pe.producto_id
    WHERE pe.cliente_id=? ORDER BY pe.fecha DESC,pe.id DESC LIMIT 20');
$p->execute([$u['id']]); $pedidos = $p->fetchAll();
lux_header('Mis compras y turnos', 'Historial y próximas citas en los negocios de Grupo Lux');
if ($proximo): ?>
<div class="appt-card"><div><div class="eyebrow">Próximo turno</div><span class="who">Consultorio Lux Salud — <?= h($proximo['profesional']) ?></span>
<div class="when"><?= h(date('d/m/Y \a \l\a\s H:i', strtotime($proximo['fecha']))) ?> hs</div></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="cancelar"><input type="hidden" name="id" value="<?= $proximo['id'] ?>"><button class="btn-small">Cancelar turno</button></form></div>
<?php endif; ?>
<div class="section-title"><h2>Compras recientes</h2></div>
<table><thead><tr><th>Fecha</th><th>Negocio</th><th>Producto / servicio</th><th>Total</th></tr></thead><tbody>
<?php foreach ($compras as $v): ?><tr><td><?= h(date('d/m', strtotime($v['fecha']))) ?></td><td><?= h($v['negocio']) ?></td><td><?= h($v['producto']) ?></td><td><?= money($v['total']) ?></td></tr><?php endforeach;
if (!$compras): ?><tr><td colspan="4">Todavía no hay compras.</td></tr><?php endif; ?></tbody></table>
<div class="section-title" style="margin-top:28px"><h2>Mis pedidos</h2><a class="btn-link" href="lux_explorar.php">Hacer un pedido →</a></div>
<?php if (!$pedidos): ?><div class="empty-state">Todavía no hiciste pedidos.</div><?php else: ?>
<div class="pedidos-tabla"><table><thead><tr><th>N°</th><th>Fecha</th><th>Negocio</th><th>Producto</th><th>Cantidad</th><th>Pago</th><th>Entrega</th><th>Estado</th></tr></thead><tbody>
<?php foreach ($pedidos as $p): ?><tr><td><b>#<?= (int)$p['id'] ?></b></td><td><?= h(date('d/m/Y H:i', strtotime($p['fecha']))) ?></td>
<td><?= h($p['negocio']) ?></td><td><?= h($p['producto']) ?></td><td><?= (int)$p['cantidad'] ?></td><td><?= h($p['medio_pago'] ?? '—') ?></td>
<td><?= h($p['entrega'] ?? '—') ?><?= !empty($p['direccion_envio']) ? '<div style="font-size:12px;color:var(--text-muted)">' . h($p['direccion_envio']) . '</div>' : '' ?><?= (int)$p['negocio_id'] === 2 && isset($p['numero_obra_social']) && $p['numero_obra_social'] !== '' ? '<div style="font-size:12px;color:var(--text-muted)">Obra social: ' . h($p['numero_obra_social']) . '</div>' : '' ?></td>
<td><?= h($p['estado']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<form method="post" class="quick-form"><?= csrf_field() ?><input type="hidden" name="accion" value="reservar">
<div class="field"><label>Reservar turno — Consultorio Lux Salud (Dra. Paula Nieva)</label><input type="datetime-local" name="fecha" required></div>
<button class="btn-small">Reservar</button></form>
<?php lux_footer();
