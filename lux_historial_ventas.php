<?php
require __DIR__ . '/lux_layout.php';
$u = lux_require(['superadmin', 'admin', 'empleado']);
$nid = lux_negocio_id($u);
if (!$nid) lux_redirect('lux_negocios.php');
$clinica = ($nid == 4);

$neg = $db->prepare('SELECT nombre FROM negocios WHERE id=?'); $neg->execute([$nid]); $neg = $neg->fetchColumn();

// Medios de pago posibles (mismos que en lux_registrar_venta.php)
$pagos = ['Efectivo', 'Tarjeta de débito', 'Tarjeta de crédito', 'Transferencia'];
if ($nid == 2 || $nid == 4) $pagos[] = 'Obra social';

// Filtros (todos por GET para poder compartir o recargar la búsqueda)
$q = trim($_GET['q'] ?? '');
$pago = in_array($_GET['pago'] ?? '', $pagos, true) ? $_GET['pago'] : '';
$vend = (int)($_GET['vendedor'] ?? 0);
$fechaOk = function ($s) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false; };
$desde = $fechaOk($_GET['desde'] ?? '') ? $_GET['desde'] : '';
$hasta = $fechaOk($_GET['hasta'] ?? '') ? $_GET['hasta'] : '';
if ($desde !== '' && $hasta !== '' && $desde > $hasta) { [$desde, $hasta] = [$hasta, $desde]; }

$where = 'v.negocio_id=?'; $par = [$nid];
if ($q !== '') {
    $like = '%' . addcslashes($q, '\\%_') . '%';
    $num = ltrim($q, '#');
    $cond = 'p.nombre LIKE ? OR v.cliente_nombre LIKE ? OR us.nombre LIKE ? OR v.detalle LIKE ?';
    $par = array_merge($par, [$like, $like, $like, $like]);
    if (ctype_digit($num)) { $cond .= ' OR v.id=?'; $par[] = (int)$num; }
    $where .= " AND ($cond)";
}
if ($pago !== '') { $where .= ' AND v.medio_pago=?'; $par[] = $pago; }
if ($vend > 0) { $where .= ' AND v.usuario_id=?'; $par[] = $vend; }
if ($desde !== '') { $where .= ' AND v.fecha>=?'; $par[] = $desde . ' 00:00:00'; }
if ($hasta !== '') { $where .= ' AND v.fecha<?'; $par[] = date('Y-m-d 00:00:00', strtotime($hasta . ' +1 day')); }

$from = 'FROM ventas v JOIN productos p ON p.id=v.producto_id JOIN usuarios us ON us.id=v.usuario_id WHERE ' . $where;

// Totales del filtro actual
$tot = $db->prepare('SELECT COUNT(*) n, COALESCE(SUM(v.cantidad),0) uni, COALESCE(SUM(v.total),0) monto ' . $from);
$tot->execute($par); $tot = $tot->fetch();

// Paginación
$porPag = 25;
$paginas = max(1, (int)ceil($tot['n'] / $porPag));
$pag = min(max(1, (int)($_GET['pag'] ?? 1)), $paginas);
$off = ($pag - 1) * $porPag;
$st = $db->prepare('SELECT v.*, p.nombre producto, us.nombre vendedor ' . $from . " ORDER BY v.fecha DESC, v.id DESC LIMIT $porPag OFFSET $off");
$st->execute($par); $lista = $st->fetchAll();

// Quiénes registraron ventas en este negocio (para el selector)
$vs = $db->prepare('SELECT DISTINCT us.id, us.nombre FROM ventas v JOIN usuarios us ON us.id=v.usuario_id WHERE v.negocio_id=? ORDER BY us.nombre');
$vs->execute([$nid]); $vendedores = $vs->fetchAll();

$keep = array_filter(['q' => $q, 'pago' => $pago, 'vendedor' => $vend ?: '', 'desde' => $desde, 'hasta' => $hasta], 'strlen');
$hayFiltro = (bool)$keep;
$link = function ($p) use ($keep) { return 'lux_historial_ventas.php?' . h(http_build_query($keep + ['pag' => $p])); };

lux_header($clinica ? 'Historial de cobros' : 'Historial de ventas', ($neg ?: '—') . ' — buscá y filtrá todo lo registrado');
?>
<div class="kpi-row" style="grid-template-columns:repeat(3,1fr)">
<div class="kpi"><div class="label"><?= $hayFiltro ? 'Resultados' : ($clinica ? 'Cobros registrados' : 'Ventas registradas') ?></div><div class="value"><?= (int)$tot['n'] ?></div></div>
<div class="kpi"><div class="label">Unidades</div><div class="value"><?= (int)$tot['uni'] ?></div></div>
<div class="kpi"><div class="label">Monto total</div><div class="value"><?= money($tot['monto']) ?></div></div>
</div>

<form class="pedidos-filtros hist-filtros" method="get">
<input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por producto, cliente, vendedor, receta o N°...">
<select name="pago" onchange="this.form.submit()"><option value="">Todos los medios de pago</option>
<?php foreach ($pagos as $pg): ?><option value="<?= h($pg) ?>" <?= $pg === $pago ? 'selected' : '' ?>><?= h($pg) ?></option><?php endforeach; ?></select>
<select name="vendedor" onchange="this.form.submit()"><option value="">Todos los vendedores</option>
<?php foreach ($vendedores as $vd): ?><option value="<?= (int)$vd['id'] ?>" <?= (int)$vd['id'] === $vend ? 'selected' : '' ?>><?= h($vd['nombre']) ?></option><?php endforeach; ?></select>
<label>Desde <input type="date" name="desde" value="<?= h($desde) ?>"></label>
<label>Hasta <input type="date" name="hasta" value="<?= h($hasta) ?>"></label>
<button class="btn-small">Buscar</button>
<?php if ($hayFiltro): ?><a class="btn-link" href="lux_historial_ventas.php" style="align-self:center">Limpiar filtros ✕</a><?php endif; ?>
</form>

<?php if (!$lista): ?><div class="empty-state"><?= $hayFiltro ? 'No hay ventas que coincidan con la búsqueda.' : 'Todavía no hay ventas registradas.' ?></div>
<?php else: ?>
<div class="pedidos-tabla"><table><thead><tr><th>N°</th><th>Fecha</th><th><?= $clinica ? 'Prestación' : 'Producto' ?></th><th>Cant.</th><th>Total</th><th>Pago</th><th><?= $clinica ? 'Paciente' : 'Cliente' ?></th><th>Registró</th><th>Detalle</th></tr></thead><tbody>
<?php foreach ($lista as $x): ?><tr><td><b>#<?= (int)$x['id'] ?></b></td><td><?= h(date('d/m/Y H:i', strtotime($x['fecha']))) ?></td><td><?= h($x['producto']) ?></td><td><?= (int)$x['cantidad'] ?></td><td><?= money($x['total']) ?></td>
<td><?= h($x['medio_pago'] ?? '—') ?></td><td><?= h($x['cliente_nombre'] ?? '—') ?></td><td><?= h($x['vendedor']) ?></td><td><?= h($x['detalle'] ?? '—') ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php if ($paginas > 1): ?>
<div class="hist-pag"><?php if ($pag > 1): ?><a class="btn-detalle" href="<?= $link($pag - 1) ?>">← Anterior</a><?php endif; ?>
<span>Página <?= $pag ?> de <?= $paginas ?></span>
<?php if ($pag < $paginas): ?><a class="btn-detalle" href="<?= $link($pag + 1) ?>">Siguiente →</a><?php endif; ?></div>
<?php endif; endif; ?>
<?php lux_footer();
