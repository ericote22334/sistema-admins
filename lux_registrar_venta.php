<?php
require __DIR__ . '/lux_layout.php';
$u = lux_require(['superadmin', 'admin', 'empleado']);
$nid = lux_negocio_id($u);
if (!$nid) lux_redirect('lux_negocios.php');
$clinica = ($nid == 4);
$farmacia = ($nid == 2);
$carritos = $farmacia ? lux_carritos_cargar($nid, $u['id']) : [];
$carritoRaw = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['carrito_id'] ?? '') : ($_GET['carrito'] ?? '');
$carritoId = is_scalar($carritoRaw) ? (string)$carritoRaw : '';
if ($farmacia && $carritoId !== '') {
    if (!isset($carritos['carts'][$carritoId])) {
        flash('Ese carrito ya no está disponible.', true);
        lux_redirect('lux_carrito.php');
    }
    $carritos['active'] = $carritoId;
    lux_carritos_guardar($nid, $u['id'], $carritos);
}
$cart = $farmacia && $carritoId !== '' ? ($carritos['carts'][$carritoId]['items'] ?? []) : [];
if (!is_array($cart)) $cart = [];
// Se consulta el rol y el puesto actuales en la base (la sesión puede estar desactualizada tras un cambio de puesto)
$fr = $db->prepare('SELECT rol, puesto FROM usuarios WHERE id=?'); $fr->execute([$u['id']]); $fr = $fr->fetch() ?: [];
$puede = lux_puede_vender(['rol' => $fr['rol'] ?? $u['rol'], 'puesto' => $fr['puesto'] ?? null, 'negocio_id' => $u['negocio_id'] ?? null]);
$pagos = ['Efectivo', 'Tarjeta de débito', 'Tarjeta de crédito', 'Transferencia'];
if ($nid == 2 || $nid == 4) $pagos[] = 'Obra social';
$entregas = ['Retira en el local', 'Envío a domicilio'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$puede) { flash('Tu puesto no registra ventas en este negocio.', true); lux_redirect('lux_registrar_venta.php'); }
    $pago = in_array($_POST['pago'] ?? '', $pagos, true) ? $_POST['pago'] : '';
    $clienteRaw = $_POST['cliente'] ?? '';
    $extraRaw = $_POST['extra'] ?? '';
    $datosInvalidos = !is_scalar($clienteRaw) || !is_scalar($extraRaw);
    $cliente = is_scalar($clienteRaw) ? trim((string)$clienteRaw) : '';
    if ($farmacia) {
        if ($carritoId === '') {
            flash('Elegí qué carrito querés cobrar.', true);
            lux_redirect('lux_carrito.php');
        }
        $extra = is_scalar($extraRaw) ? trim((string)$extraRaw) : '';
        $items = []; $itemsInvalidos = !is_array($cart);
        if (!is_array($cart)) $cart = [];
        foreach ($cart as $id => $cantidad) {
            if (!is_scalar($cantidad)) { $itemsInvalidos = true; break; }
            $id = filter_var((string)$id, FILTER_VALIDATE_INT);
            $cantidad = filter_var((string)$cantidad, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1 || $cantidad === false || $cantidad < 1 || $cantidad > 2147483647) {
                $itemsInvalidos = true;
                break;
            }
            $items[$id] = ($items[$id] ?? 0) + $cantidad;
        }
        if ($itemsInvalidos || !$items) {
            flash('No hay productos válidos en el carrito. Cargá los productos antes de cobrar.', true);
            lux_redirect('lux_carrito.php?carrito=' . rawurlencode($carritoId));
        } elseif ($pago === '' || $datosInvalidos || mb_strlen($cliente) > 120 || mb_strlen($extra) > 100) {
            flash('Revisá los productos, el medio de pago y los datos del cliente o receta.', true);
        } else {
            ksort($items, SORT_NUMERIC);
            $db->beginTransaction();
            try {
                $marks = implode(',', array_fill(0, count($items), '?'));
                $sp = $db->prepare("SELECT id, nombre, precio, stock FROM productos WHERE negocio_id=? AND id IN ($marks) ORDER BY id FOR UPDATE");
                $sp->execute(array_merge([$nid], array_keys($items)));
                $productosVenta = [];
                foreach ($sp->fetchAll() as $producto) $productosVenta[(int)$producto['id']] = $producto;
                $errorVenta = count($productosVenta) !== count($items) ? 'Hay productos del carrito que no pertenecen a esta farmacia.' : '';
                $totalVenta = 0.0;
                foreach ($items as $id => $cantidad) {
                    if ($errorVenta !== '') break;
                    $producto = $productosVenta[$id];
                    if ((int)$producto['stock'] < $cantidad) {
                        $errorVenta = "Stock insuficiente de {$producto['nombre']}.";
                        break;
                    }
                    $totalVenta += (float)$producto['precio'] * $cantidad;
                }
                if ($totalVenta > 9999999999.99) $errorVenta = 'El total de la venta supera el máximo permitido.';
                if ($errorVenta !== '') {
                    $db->rollBack();
                    flash($errorVenta, true);
                } else {
                    $fecha = date('Y-m-d H:i:s');
                    $detalle = $extra !== '' ? 'Receta / obra social: ' . $extra : null;
                    $db->prepare('INSERT INTO venta_tickets(negocio_id,usuario_id,fecha,medio_pago,cliente_nombre,detalle,total) VALUES(?,?,?,?,?,?,?)')
                       ->execute([$nid, $u['id'], $fecha, $pago, $cliente !== '' ? $cliente : null, $detalle, $totalVenta]);
                    $ticketId = (int)$db->lastInsertId();
                    $actualizarStock = $db->prepare('UPDATE productos SET stock=stock-? WHERE id=? AND negocio_id=? AND stock>=?');
                    $insertarLinea = $db->prepare('INSERT INTO ventas(negocio_id,producto_id,usuario_id,cantidad,total,fecha,medio_pago,cliente_nombre,detalle,ticket_id) VALUES(?,?,?,?,?,?,?,?,?,?)');
                    foreach ($items as $id => $cantidad) {
                        $producto = $productosVenta[$id];
                        $actualizarStock->execute([$cantidad, $id, $nid, $cantidad]);
                        if ($actualizarStock->rowCount() !== 1) {
                            throw new RuntimeException("No se pudo actualizar el stock de {$producto['nombre']}.");
                        }
                        $insertarLinea->execute([$nid, $id, $u['id'], $cantidad, (float)$producto['precio'] * $cantidad, $fecha, $pago, $cliente !== '' ? $cliente : null, $detalle, $ticketId]);
                    }
                    $db->commit();
                    unset($carritos['carts'][$carritoId]);
                    $carritos['active'] = '';
                    lux_carritos_guardar($nid, $u['id'], $carritos);
                    flash("Venta #$ticketId registrada — " . count($items) . ' productos, total ' . money($totalVenta) . " ($pago).");
                }
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
        }
    } else {
        $nombre = trim($_POST['producto'] ?? ''); $cant = (int)($_POST['cantidad'] ?? 0);
        $extra = is_scalar($extraRaw) ? trim((string)$extraRaw) : '';
        $detalle = [];
        if ($extra !== '') $detalle[] = 'Obra social: ' . $extra;
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
    }
    $destino = 'lux_registrar_venta.php';
    if ($farmacia && isset($carritos['carts'][$carritoId])) $destino .= '?carrito=' . rawurlencode($carritoId);
    lux_redirect($destino);
}
$neg = $db->prepare('SELECT nombre FROM negocios WHERE id=?'); $neg->execute([$nid]); $neg = $neg->fetchColumn();
$ps = $db->prepare('SELECT id, nombre, precio, stock FROM productos WHERE negocio_id=? ORDER BY nombre'); $ps->execute([$nid]); $prods = $ps->fetchAll();
$mapa = []; $mapaNombres = [];
foreach ($prods as $p) {
    $mapa[(int)$p['id']] = ['nombre' => $p['nombre'], 'precio' => (float)$p['precio'], 'stock' => (int)$p['stock']];
    $mapaNombres[$p['nombre']] = ['precio' => (float)$p['precio'], 'stock' => (int)$p['stock']];
}
$checkoutLines = []; $totalCarrito = 0.0; $errorCarrito = '';
if ($farmacia) {
    if (!is_array($cart)) $cart = [];
    if ($carritoId !== '' && isset($carritos['carts'][$carritoId])) {
        $errorCarrito = '';
        foreach ($cart as $id => $cantidad) {
            $id = filter_var((string)$id, FILTER_VALIDATE_INT);
            $cantidad = is_scalar($cantidad) ? filter_var((string)$cantidad, FILTER_VALIDATE_INT) : false;
            if ($id === false || $cantidad === false || $cantidad < 1 || !isset($mapa[$id])) {
                $errorCarrito = 'Hay productos inválidos o que ya no están en el inventario.';
                continue;
            }
            $producto = $mapa[$id];
            if ($cantidad > $producto['stock']) $errorCarrito = "Stock insuficiente de {$producto['nombre']}; actualizá el carrito.";
            $subtotal = $producto['precio'] * $cantidad;
            $totalCarrito += $subtotal;
            $checkoutLines[] = ['producto' => $producto, 'cantidad' => $cantidad, 'subtotal' => $subtotal];
        }
        if ($totalCarrito > 9999999999.99) $errorCarrito = 'El total del carrito supera el máximo permitido.';
    }
}
$hs = $db->prepare('SELECT v.*, p.nombre producto, us.nombre vendedor FROM ventas v JOIN productos p ON p.id=v.producto_id JOIN usuarios us ON us.id=v.usuario_id
    WHERE v.negocio_id=? ORDER BY v.fecha DESC, v.id DESC LIMIT 12');
$hs->execute([$nid]); $hist = $hs->fetchAll();
$titulo = $clinica ? 'Registrar cobro' : 'Registrar venta';
lux_header($titulo, ($neg ?: '—') . ($farmacia ? ' — revisá el carrito cargado y completá el cobro' : ($clinica ? ' — escribí la prestación y los datos del paciente' : ' — escribí el producto vendido y completá los datos')));
if (!$puede): ?>
<div class="venta-aviso">Tu puesto (<?= h($fr['puesto'] ?? '') ?>) no registra ventas en este negocio. Si te corresponde hacerlo, pedile al administrador que revise tu puesto.</div>
<?php else: ?>
<?php if ($farmacia): ?>
<div class="venta-card farmacia-checkout">
<?php if ($carritoId === ''): ?>
<div class="farmacia-encabezado"><h2 class="farmacia-titulo">Elegí qué carrito cobrar</h2><a class="btn-link" href="lux_carrito.php">Administrar carritos</a></div>
<?php if (!$carritos['carts']): ?><div class="empty-state">No hay carritos abiertos. <a href="lux_carrito.php">Crear un carrito</a></div>
<?php else: ?><div class="farmacia-lista-carritos">
<?php foreach ($carritos['carts'] as $id => $datos): $itemsAbiertos = is_array($datos['items'] ?? null) ? $datos['items'] : []; ?>
<div class="farmacia-carrito-resumen"><div><b><?= h($datos['nombre'] ?? 'Carrito') ?></b><span><?= count($itemsAbiertos) ?> <?= count($itemsAbiertos) === 1 ? 'producto' : 'productos' ?></span></div>
<a class="btn-small" href="lux_registrar_venta.php?carrito=<?= h(rawurlencode($id)) ?>">Seleccionar para cobrar</a></div>
<?php endforeach; ?></div><?php endif; ?>
<?php else: ?>
<div class="farmacia-encabezado"><h2 class="farmacia-titulo"><?= h($carritos['carts'][$carritoId]['nombre'] ?? 'Carrito') ?> · resumen de compra</h2><a class="btn-link" href="lux_carrito.php?carrito=<?= h(rawurlencode($carritoId)) ?>">Modificar carrito</a></div>
<?php if (!$checkoutLines && $errorCarrito === ''): ?>
<div class="empty-state">Este carrito no tiene productos. <a href="lux_carrito.php?carrito=<?= h(rawurlencode($carritoId)) ?>">Cargar productos</a></div>
<?php elseif (!$checkoutLines): ?>
<div class="venta-aviso"><?= h($errorCarrito) ?> <a href="lux_carrito.php?carrito=<?= h(rawurlencode($carritoId)) ?>">Volver al carrito</a></div>
<?php else: ?>
<div class="pedidos-tabla"><table><thead><tr><th>Producto</th><th>Precio unitario</th><th>Cantidad</th><th>Subtotal</th></tr></thead><tbody>
<?php foreach ($checkoutLines as $linea): ?><tr><td><?= h($linea['producto']['nombre']) ?></td><td><?= money($linea['producto']['precio']) ?></td><td><?= (int)$linea['cantidad'] ?></td><td><?= money($linea['subtotal']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php if ($errorCarrito !== ''): ?>
<div class="venta-aviso"><?= h($errorCarrito) ?> <a href="lux_carrito.php?carrito=<?= h(rawurlencode($carritoId)) ?>">Volver al carrito</a></div>
<?php else: ?>
<form method="post" class="venta-form farmacia-cobro" autocomplete="off"><?= csrf_field() ?>
<input type="hidden" name="carrito_id" value="<?= h($carritoId) ?>">
<div class="field"><label>Medio de pago</label><select name="pago" required><?php foreach ($pagos as $pg): ?><option><?= h($pg) ?></option><?php endforeach; ?></select></div>
<div class="field"><label>Cliente (opcional)</label><input type="text" name="cliente" maxlength="120" placeholder="Nombre y apellido"></div>
<div class="field"><label>Receta / obra social (opcional)</label><input type="text" name="extra" maxlength="100" placeholder="N° de receta u obra social"></div>
<div class="venta-resumen"><span><?= count($checkoutLines) ?> productos</span><span>Total a cobrar: <b><?= money($totalCarrito) ?></b></span></div>
<div><button class="btn-small" type="submit">Cobrar y registrar venta</button></div></form>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
</div>
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
  var datos = <?= json_encode($mapaNombres, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
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
<?php endif; ?>
<div class="section-title"><h2><?= $clinica ? 'Últimos cobros registrados' : 'Últimas ventas registradas' ?></h2><?php if (!$clinica): ?><a class="btn-link" href="lux_historial_ventas.php">Ver historial completo →</a><?php endif; ?></div>
<?php if (!$hist): ?><div class="empty-state">Todavía no hay ventas registradas.</div><?php else: ?>
<div class="pedidos-tabla"><table><thead><tr><?php if ($farmacia): ?><th>Venta</th><?php endif; ?><th>Fecha</th><th><?= $clinica ? 'Prestación' : 'Producto' ?></th><th>Cant.</th><th>Total</th><th>Pago</th><th><?= $clinica ? 'Paciente' : 'Cliente' ?></th><th>Registró</th><th>Detalle</th></tr></thead><tbody>
<?php foreach ($hist as $x): ?><tr><?php if ($farmacia): ?><td><?= $x['ticket_id'] ? '#' . (int)$x['ticket_id'] : '—' ?></td><?php endif; ?><td><?= h(date('d/m H:i', strtotime($x['fecha']))) ?></td><td><?= h($x['producto']) ?></td><td><?= (int)$x['cantidad'] ?></td><td><?= money($x['total']) ?></td>
<td><?= h($x['medio_pago'] ?? '—') ?></td><td><?= h($x['cliente_nombre'] ?? '—') ?></td><td><?= h($x['vendedor']) ?></td><td><?= h($x['detalle'] ?? '—') ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php lux_footer();
