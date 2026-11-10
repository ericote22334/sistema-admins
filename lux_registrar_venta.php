<?php
require __DIR__ . '/lux_layout.php';
$u = lux_require(['superadmin', 'admin', 'empleado']);
$nid = lux_negocio_id($u);
if (!$nid) lux_redirect('lux_negocios.php');
$clinica = ($nid == 4);
// Se consulta el rol y el puesto actuales en la base (la sesión puede estar desactualizada tras un cambio de puesto)
$fr = $db->prepare('SELECT rol, puesto FROM usuarios WHERE id=?'); $fr->execute([$u['id']]); $fr = $fr->fetch() ?: [];
$puede = lux_puede_vender(['rol' => $fr['rol'] ?? $u['rol'], 'puesto' => $fr['puesto'] ?? null, 'negocio_id' => $u['negocio_id'] ?? null]);
$pagos = ['Efectivo', 'Tarjeta de débito', 'Tarjeta de crédito', 'Transferencia'];
if ($nid == 2 || $nid == 4) $pagos[] = 'Obra social';
$entregas = ['Retira en el local', 'Envío a domicilio'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$puede) { flash('Tu puesto no registra ventas en este negocio.', true); lux_redirect('lux_registrar_venta.php'); }
    $nombre = trim($_POST['producto'] ?? ''); $cant = (int)($_POST['cantidad'] ?? 0);
    $pago = in_array($_POST['pago'] ?? '', $pagos, true) ? $_POST['pago'] : '';
    $cliente = trim($_POST['cliente'] ?? ''); $extra = trim($_POST['extra'] ?? '');
    $detalle = [];
    if ($extra !== '') $detalle[] = ($nid == 2 ? 'Receta / obra social: ' : 'Obra social: ') . $extra;
    if ($nid == 3 && in_array($_POST['entrega'] ?? '', $entregas, true)) $detalle[] = 'Entrega: ' . $_POST['entrega'];
    $sp = $db->prepare('SELECT * FROM productos WHERE negocio_id=? AND nombre=? LIMIT 1'); $sp->execute([$nid, $nombre]); $p = $sp->fetch();
    if ($nombre === '' || $cant < 1 || $pago === '') flash('Completá el producto, la cantidad y el medio de pago.', true);
    elseif ($clinica && $cliente === '') flash('Indicá el nombre del paciente.', true);
    elseif (!$p) flash("No encontré «$nombre» en el inventario. Escribilo y elegilo de la lista.", true);
    else {
        $db->beginTransaction();
        $ok = $db->prepare('UPDATE productos SET stock=stock-? WHERE id=? AND stock>=?');
        $ok->execute([$cant, $p['id'], $cant]);
        if ($ok->rowCount() === 0) { $db->rollBack(); flash("Stock insuficiente de {$p['nombre']}.", true); }
        else {
            $total = $cant * $p['precio'];
            $db->prepare('INSERT INTO ventas(negocio_id,producto_id,usuario_id,cantidad,total,fecha,medio_pago,cliente_nombre,detalle) VALUES(?,?,?,?,?,?,?,?,?)')
               ->execute([$nid, $p['id'], $u['id'], $cant, $total, date('Y-m-d H:i:s'), $pago, $cliente !== '' ? $cliente : null, $detalle ? implode(' · ', $detalle) : null]);
            $db->commit();
            flash(($clinica ? 'Cobro registrado' : 'Venta registrada') . ": $cant × {$p['nombre']} — " . money($total) . " ($pago).");
        }
    }
    lux_redirect('lux_registrar_venta.php');
}
$neg = $db->prepare('SELECT nombre FROM negocios WHERE id=?'); $neg->execute([$nid]); $neg = $neg->fetchColumn();
$ps = $db->prepare('SELECT nombre, precio, stock FROM productos WHERE negocio_id=? ORDER BY nombre'); $ps->execute([$nid]); $prods = $ps->fetchAll();
$mapa = []; foreach ($prods as $p) $mapa[$p['nombre']] = ['precio' => (float)$p['precio'], 'stock' => (int)$p['stock']];
$hs = $db->prepare('SELECT v.*, p.nombre producto, us.nombre vendedor FROM ventas v JOIN productos p ON p.id=v.producto_id JOIN usuarios us ON us.id=v.usuario_id
    WHERE v.negocio_id=? ORDER BY v.fecha DESC, v.id DESC LIMIT 12');
$hs->execute([$nid]); $hist = $hs->fetchAll();
$titulo = $clinica ? 'Registrar cobro' : 'Registrar venta';
lux_header($titulo, ($neg ?: '—') . ($clinica ? ' — escribí la prestación y los datos del paciente' : ' — escribí el producto vendido y completá los datos'));
if (!$puede): ?>
<div class="venta-aviso">Tu puesto (<?= h($fr['puesto'] ?? '') ?>) no registra ventas en este negocio. Si te corresponde hacerlo, pedile al administrador que revise tu puesto.</div>
<?php else: ?>
<div class="venta-card"><form method="post" class="venta-form" autocomplete="off"><?= csrf_field() ?>
<div class="field ancho"><label><?= $clinica ? 'Prestación / servicio' : ($nid == 2 ? 'Medicamento o producto' : 'Producto') ?></label>
<input type="text" name="producto" id="v-producto" list="v-lista" placeholder="Empezá a escribir el nombre..." required>
<datalist id="v-lista"><?php foreach ($prods as $p): ?><option value="<?= h($p['nombre']) ?>" label="<?= h(money($p['precio']) . ' · ' . ($p['stock'] > 0 ? 'stock ' . $p['stock'] : 'sin stock')) ?>"></option><?php endforeach; ?></datalist></div>
<div class="field"><label>Cantidad</label><input type="number" name="cantidad" id="v-cant" value="1" min="1" required></div>
<div class="field"><label>Medio de pago</label><select name="pago" required><?php foreach ($pagos as $pg): ?><option><?= h($pg) ?></option><?php endforeach; ?></select></div>
<div class="field"><label><?= $clinica ? 'Paciente' : 'Cliente (opcional)' ?></label><input type="text" name="cliente" maxlength="120" placeholder="Nombre y apellido" <?= $clinica ? 'required' : '' ?>></div>
<?php if ($nid == 2): ?><div class="field"><label>Receta / obra social (opcional)</label><input type="text" name="extra" maxlength="100" placeholder="N° de receta u obra social"></div><?php endif; ?>
<?php if ($clinica): ?><div class="field"><label>Obra social / plan (opcional)</label><input type="text" name="extra" maxlength="100" placeholder="Ej.: OSDE 210"></div><?php endif; ?>
<?php if ($nid == 3): ?><div class="field"><label>Entrega</label><select name="entrega"><?php foreach ($entregas as $en): ?><option><?= h($en) ?></option><?php endforeach; ?></select></div><?php endif; ?>
<div class="venta-resumen"><span id="v-info">Escribí un producto para ver su precio y stock.</span><span>Total: <b id="v-total">$0</b></span></div>
<div><button class="btn-small"><?= $clinica ? 'Registrar cobro' : 'Registrar venta' ?></button></div></form></div>
<script>
(function () {
  var datos = <?= json_encode($mapa, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var inp = document.getElementById('v-producto'), cant = document.getElementById('v-cant');
  var info = document.getElementById('v-info'), tot = document.getElementById('v-total');
  function pesos(n) { return '$' + n.toLocaleString('es-AR', { maximumFractionDigits: 2 }); }
  function actualizar() {
    var d = datos[inp.value.trim()], q = Math.max(1, parseInt(cant.value, 10) || 1);
    if (!d) { info.textContent = inp.value.trim() ? 'No está en el inventario: elegilo de la lista.' : 'Escribí un producto para ver su precio y stock.'; tot.textContent = '$0'; return; }
    info.textContent = 'Precio ' + pesos(d.precio) + ' · ' + (d.stock > 0 ? 'Stock ' + d.stock : 'Sin stock') + (q > d.stock ? ' — la cantidad supera el stock' : '');
    tot.textContent = pesos(d.precio * q);
  }
  inp.addEventListener('input', actualizar); cant.addEventListener('input', actualizar);
})();
</script>
<?php endif; ?>
<div class="section-title"><h2><?= $clinica ? 'Últimos cobros registrados' : 'Últimas ventas registradas' ?></h2><a class="btn-link" href="lux_historial_ventas.php">Ver historial completo →</a></div>
<?php if (!$hist): ?><div class="empty-state">Todavía no hay ventas registradas.</div><?php else: ?>
<div class="pedidos-tabla"><table><thead><tr><th>Fecha</th><th><?= $clinica ? 'Prestación' : 'Producto' ?></th><th>Cant.</th><th>Total</th><th>Pago</th><th><?= $clinica ? 'Paciente' : 'Cliente' ?></th><th>Registró</th><th>Detalle</th></tr></thead><tbody>
<?php foreach ($hist as $x): ?><tr><td><?= h(date('d/m H:i', strtotime($x['fecha']))) ?></td><td><?= h($x['producto']) ?></td><td><?= (int)$x['cantidad'] ?></td><td><?= money($x['total']) ?></td>
<td><?= h($x['medio_pago'] ?? '—') ?></td><td><?= h($x['cliente_nombre'] ?? '—') ?></td><td><?= h($x['vendedor']) ?></td><td><?= h($x['detalle'] ?? '—') ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php lux_footer();
