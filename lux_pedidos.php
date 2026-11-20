<?php
require __DIR__ . '/lux_layout.php';
$u = lux_require(['superadmin', 'admin', 'empleado']);
$nid = lux_negocio_id($u);
if (!$nid) lux_redirect('lux_negocios.php');
if ($nid == 4) { flash('El consultorio trabaja con turnos, no con pedidos.', true); lux_redirect('lux_ventas.php'); }
$estados = ['Pendiente', 'En preparación', 'En camino', 'Entregado'];
$clase = ['Pendiente' => 'pendiente', 'En preparación' => 'preparacion', 'En camino' => 'camino', 'Entregado' => 'entregado'];
$registrador = $db->prepare('SELECT rol,puesto FROM usuarios WHERE id=?'); $registrador->execute([$u['id']]); $registrador = $registrador->fetch() ?: [];
$puedeRegistrarVenta = lux_puede_vender([
    'rol' => $registrador['rol'] ?? $u['rol'],
    'puesto' => $registrador['puesto'] ?? null,
    'negocio_id' => $u['negocio_id'] ?? null,
]);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $accion = $_POST['accion'] ?? '';
    $pedidoId = (int)($_POST['id'] ?? 0);
    $estado = $_POST['estado'] ?? '';
    if ($accion === 'estado' && in_array($estado, $estados, true)) {
        $db->beginTransaction();
        try {
            $sp = $db->prepare('SELECT p.*,pr.nombre producto,pr.precio,u.nombre cliente
                FROM pedidos p JOIN productos pr ON pr.id=p.producto_id
                LEFT JOIN usuarios u ON u.id=p.cliente_id
                WHERE p.id=? AND p.negocio_id=? FOR UPDATE');
            $sp->execute([$pedidoId, $nid]); $pedido = $sp->fetch();
            $err = '';
            $msg = '';
            if (!$pedido) $err = 'El pedido no existe en este negocio.';
            elseif ($pedido['estado'] === 'Entregado' || $pedido['venta_id'] !== null) $err = 'Este pedido ya fue entregado o ya tiene una venta registrada.';
            elseif ($estado === $pedido['estado']) $msg = 'El pedido ya estaba en ese estado.';
            elseif ($estado === 'Entregado' && !$puedeRegistrarVenta) $err = 'Tu puesto no tiene permiso para registrar ventas y entregar pedidos.';
            elseif ($estado === 'Entregado') {
                $stock = $db->prepare('UPDATE productos SET stock=stock-? WHERE id=? AND negocio_id=? AND stock>=?');
                $stock->execute([(int)$pedido['cantidad'], (int)$pedido['producto_id'], $nid, (int)$pedido['cantidad']]);
                if ($stock->rowCount() !== 1) $err = 'No se puede entregar el pedido: no hay stock suficiente. Reponé el inventario antes de confirmar.';
                else {
                    $detalle = 'Pedido web #' . (int)$pedido['id'] . ' · ' . ($pedido['entrega'] ?: 'Entrega sin especificar');
                    if ($nid == 2 && isset($pedido['numero_obra_social']) && $pedido['numero_obra_social'] !== '') $detalle .= ' · Obra social: ' . $pedido['numero_obra_social'];
                    $db->prepare('INSERT INTO ventas(negocio_id,producto_id,usuario_id,cliente_id,cantidad,total,fecha,medio_pago,cliente_nombre,detalle)
                        VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([
                        $nid, (int)$pedido['producto_id'], (int)$u['id'], $pedido['cliente_id'],
                        (int)$pedido['cantidad'], (float)$pedido['precio'] * (int)$pedido['cantidad'],
                        date('Y-m-d H:i:s'), $pedido['medio_pago'], $pedido['cliente'], $detalle,
                    ]);
                    $ventaId = (int)$db->lastInsertId();
                    $up = $db->prepare("UPDATE pedidos SET estado='Entregado',venta_id=? WHERE id=? AND negocio_id=? AND estado<>'Entregado' AND venta_id IS NULL");
                    $up->execute([$ventaId, $pedidoId, $nid]);
                    if ($up->rowCount() !== 1) $err = 'No se pudo vincular la venta al pedido.';
                    else $msg = 'Pedido #' . $pedidoId . ' entregado y venta #' . $ventaId . ' registrada en el historial.';
                }
            } else {
                $db->prepare('UPDATE pedidos SET estado=? WHERE id=? AND negocio_id=?')->execute([$estado, $pedidoId, $nid]);
                $msg = 'Pedido #' . $pedidoId . ' actualizado a «' . $estado . '».';
            }
            if ($err !== '') {
                $db->rollBack();
                flash($err, true);
            } else {
                $db->commit();
                flash($msg);
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    } else {
        flash('La solicitud de cambio de estado no es válida.', true);
    }
    $vuelta = array_filter(['q' => trim($_POST['q'] ?? ''), 'estado' => $_POST['filtro'] ?? ''], 'strlen');
    lux_redirect('lux_pedidos.php' . ($vuelta ? '?' . http_build_query($vuelta) : ''));
}
$q = trim($_GET['q'] ?? '');
$f = in_array($_GET['estado'] ?? '', $estados, true) ? $_GET['estado'] : '';
$sql = "SELECT p.*, pr.nombre producto, pr.precio, COALESCE(u.nombre,'Mostrador') cliente
        FROM pedidos p JOIN productos pr ON pr.id=p.producto_id LEFT JOIN usuarios u ON u.id=p.cliente_id
        WHERE p.negocio_id=?";
$par = [$nid];
if ($f !== '') { $sql .= ' AND p.estado=?'; $par[] = $f; }
if ($q !== '') {
    $num = ltrim($q, '#');
    if (ctype_digit($num)) { $sql .= ' AND (p.id=? OR u.nombre LIKE ?)'; $par[] = (int)$num; }
    else $sql .= ' AND u.nombre LIKE ?';
    $par[] = "%$q%";
}
$st = $db->prepare($sql . ' ORDER BY p.fecha DESC, p.id DESC'); $st->execute($par); $lista = $st->fetchAll();
$det = null;
if (!empty($_GET['ver'])) {
    $sd = $db->prepare("SELECT p.*, pr.nombre producto, pr.precio, COALESCE(u.nombre,'Mostrador') cliente
        FROM pedidos p JOIN productos pr ON pr.id=p.producto_id LEFT JOIN usuarios u ON u.id=p.cliente_id
        WHERE p.id=? AND p.negocio_id=?");
    $sd->execute([(int)$_GET['ver'], $nid]); $det = $sd->fetch() ?: null;
}
$keep = array_filter(['q' => $q, 'estado' => $f], 'strlen');
lux_header('Pedidos', 'Seguimiento y cambio de estado de los pedidos recibidos.'); ?>
<?php if ($det): ?>
<div class="pedido-detalle"><div class="cab"><h2>Pedido #<?= (int)$det['id'] ?></h2>
<a class="btn-link" href="lux_pedidos.php<?= $keep ? '?' . h(http_build_query($keep)) : '' ?>">Cerrar detalle ✕</a></div>
<dl><div><dt>Cliente</dt><dd><?= h($det['cliente']) ?></dd></div>
<div><dt>Fecha</dt><dd><?= h(date('d/m/Y H:i', strtotime($det['fecha']))) ?></dd></div>
<div><dt>Producto</dt><dd><?= h($det['producto']) ?></dd></div>
<div><dt>Cantidad</dt><dd><?= (int)$det['cantidad'] ?></dd></div>
<div><dt>Medio de pago</dt><dd><?= h($det['medio_pago'] ?? '—') ?></dd></div>
<div><dt>Entrega</dt><dd><?= h($det['entrega'] ?? '—') ?></dd></div>
<?php if (!empty($det['direccion_envio'])): ?><div><dt>Dirección de envío</dt><dd><?= h($det['direccion_envio']) ?></dd></div><?php endif; ?>
<?php if ($nid == 2 && isset($det['numero_obra_social']) && $det['numero_obra_social'] !== ''): ?><div><dt>Número de obra social</dt><dd><?= h($det['numero_obra_social']) ?></dd></div><?php endif; ?>
<div><dt>Precio unitario</dt><dd><?= money($det['precio']) ?></dd></div>
<div><dt>Total</dt><dd><?= money($det['cantidad'] * $det['precio']) ?></dd></div>
<div><dt>Estado</dt><dd><?= h($det['estado']) ?></dd></div></dl></div>
<?php endif; ?>
<form class="pedidos-filtros" method="get">
<input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por cliente o número...">
<select name="estado" onchange="this.form.submit()"><option value="">Todos los estados</option>
<?php foreach ($estados as $e): ?><option value="<?= h($e) ?>" <?= $e === $f ? 'selected' : '' ?>><?= h($e) ?></option><?php endforeach; ?></select>
<button class="btn-small">Buscar</button></form>
<?php if (!$lista): ?><div class="empty-state">No hay pedidos que coincidan con la búsqueda.</div><?php else: ?>
<div class="pedidos-tabla"><table><thead><tr><th>N°</th><th>Cliente</th><th>Fecha</th><th>Ítems</th><th>Total</th><th>Pago</th><th>Entrega</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
<?php foreach ($lista as $p): $ops = in_array($p['estado'], $estados, true) ? $estados : array_merge([$p['estado']], $estados); ?>
<tr><td><b>#<?= (int)$p['id'] ?></b></td><td><?= h($p['cliente']) ?></td><td><?= h(date('d/m/Y', strtotime($p['fecha']))) ?></td>
<td><?= (int)$p['cantidad'] ?></td><td><?= money($p['cantidad'] * $p['precio']) ?></td>
<td><?= h($p['medio_pago'] ?? '—') ?></td><td><?= h($p['entrega'] ?? '—') ?></td>
<?php if ($p['estado'] === 'Entregado'): ?><td><span class="status-pill online">Entregado</span></td>
<?php else: ?>
<td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
<input type="hidden" name="q" value="<?= h($q) ?>"><input type="hidden" name="filtro" value="<?= h($f) ?>">
<select name="estado" class="est <?= h($clase[$p['estado']] ?? 'pendiente') ?>" data-original="<?= h($p['estado']) ?>" onchange="if (this.value === 'Entregado' && !confirm(<?= h(json_encode('¿Estás seguro de marcar el pedido #' . (int)$p['id'] . ' como entregado? Se registrará la venta y se descontará el stock.')) ?>)) { this.value = this.dataset.original; return; } this.form.submit()">
<?php foreach ($ops as $e): ?><option value="<?= h($e) ?>" <?= $e === $p['estado'] ? 'selected' : '' ?>><?= h($e) ?></option><?php endforeach; ?></select></form></td>
<?php endif; ?>
<td><a class="btn-detalle" href="lux_pedidos.php?<?= h(http_build_query($keep + ['ver' => $p['id']])) ?>">Ver detalle</a></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php lux_footer();
