<?php
require __DIR__ . '/lux_layout.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$u = lux_require(['superadmin', 'admin', 'empleado']);
$nid = lux_negocio_id($u);
if ($nid !== 2) lux_redirect('lux_ventas.php');

$idRaw = $_GET['id'] ?? '';
$id = is_scalar($idRaw) ? filter_var((string)$idRaw, FILTER_VALIDATE_INT) : false;
if ($id === false || $id < 1) {
    flash('No encontramos ese ticket.', true);
    lux_redirect('lux_historial_ventas.php');
}

$st = $db->prepare('SELECT t.*, n.nombre negocio, u.nombre vendedor
    FROM venta_tickets t
    JOIN negocios n ON n.id=t.negocio_id
    JOIN usuarios u ON u.id=t.usuario_id
    WHERE t.id=? AND t.negocio_id=?');
$st->execute([(int)$id, $nid]);
$ticket = $st->fetch();
if (!$ticket) {
    flash('No encontramos ese ticket.', true);
    lux_redirect('lux_historial_ventas.php');
}

$st = $db->prepare('SELECT p.nombre, v.cantidad, v.total
    FROM ventas v
    JOIN productos p ON p.id=v.producto_id
    WHERE v.ticket_id=? AND v.negocio_id=?
    ORDER BY v.id');
$st->execute([(int)$id, $nid]);
$items = $st->fetchAll();
$unidades = 0;
foreach ($items as $item) $unidades += (int)$item['cantidad'];
$volver = 'lux_historial_ventas.php';
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ticket #<?= (int)$ticket['id'] ?> — <?= h($ticket['negocio']) ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#efede8;color:#161616;font-family:Arial,Helvetica,sans-serif;line-height:1.4}
.barra{max-width:440px;margin:18px auto 10px;padding:0 12px;display:flex;align-items:center;gap:14px;font-size:14px}
.barra a{color:#1d1d1d}
.barra button{margin-left:auto;background:#1d1d1d;color:#fff;border:0;border-radius:6px;padding:9px 16px;font-size:14px;cursor:pointer}
.ticket{width:calc(100% - 24px);max-width:416px;margin:0 auto 32px;padding:24px;background:#fff;border:1px solid #d5d5d5}
.cab{text-align:center;border-bottom:1px dashed #777;padding-bottom:14px;margin-bottom:12px}
.cab h1{font-size:21px;margin:0 0 3px}
.cab p{font-size:12px;margin:0;color:#555}
.titulo{text-align:center;font-size:13px;font-weight:bold;letter-spacing:.08em;margin:12px 0}
.datos{font-size:12px;border-bottom:1px dashed #777;padding-bottom:10px;margin-bottom:10px}
.dato{display:flex;justify-content:space-between;gap:12px;margin:3px 0}
.dato span:first-child{color:#555}
table{width:100%;border-collapse:collapse;font-size:12px}
th{font-size:10px;text-transform:uppercase;text-align:left;border-bottom:1px solid #bbb;padding:5px 2px}
td{vertical-align:top;padding:7px 2px;border-bottom:1px dotted #ccc}
.num{text-align:right;white-space:nowrap}
.producto{min-width:100px}
.producto small{display:block;color:#555;margin-top:2px}
.total{display:flex;justify-content:space-between;border-top:1px dashed #777;padding-top:10px;margin-top:10px;font-size:17px;font-weight:bold}
.pie{text-align:center;font-size:11px;color:#555;margin-top:16px}
.detalle{font-size:11px;margin-top:8px}
@media(max-width:440px){.ticket{padding:18px 14px}}
@media print{
    @page{size:80mm auto;margin:4mm}
    body{background:#fff;font-size:11px}
    .barra{display:none}
    .ticket{width:100%;max-width:none;margin:0;padding:0;border:0}
    .cab h1{font-size:17px}
    table{font-size:10px}
    th{font-size:9px}
}
</style></head><body>
<div class="barra"><a href="<?= h($volver) ?>">← Volver al historial</a><button type="button" onclick="window.print()">Imprimir ticket</button></div>
<main class="ticket">
<header class="cab"><h1><?= h($ticket['negocio']) ?></h1><p>Grupo Lux</p></header>
<div class="titulo">TICKET DE VENTA #<?= (int)$ticket['id'] ?></div>
<section class="datos">
<div class="dato"><span>Fecha</span><b><?= h(date('d/m/Y H:i', strtotime($ticket['fecha']))) ?></b></div>
<div class="dato"><span>Atendió</span><b><?= h($ticket['vendedor']) ?></b></div>
<div class="dato"><span>Cliente</span><b><?= h($ticket['cliente_nombre'] ?: 'Consumidor final') ?></b></div>
<div class="dato"><span>Forma de pago</span><b><?= h($ticket['medio_pago']) ?></b></div>
</section>
<table><thead><tr><th>Producto</th><th class="num">Cant.</th><th class="num">P. unit.</th><th class="num">Subtotal</th></tr></thead><tbody>
<?php foreach ($items as $item): $cantidad = (int)$item['cantidad']; $subtotal = (float)$item['total']; ?>
<tr><td class="producto"><?= h($item['nombre']) ?></td><td class="num"><?= $cantidad ?></td><td class="num"><?= money($subtotal / $cantidad) ?></td><td class="num"><?= money($subtotal) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<div class="total"><span>TOTAL</span><span><?= money($ticket['total']) ?></span></div>
<div class="dato" style="font-size:11px;margin-top:5px"><span>Unidades</span><b><?= $unidades ?></b></div>
<?php if (!empty($ticket['detalle'])): ?><div class="detalle"><?= h($ticket['detalle']) ?></div><?php endif; ?>
<footer class="pie">Gracias por su compra.<br>Conserve este ticket como comprobante de su venta.</footer>
</main></body></html>
