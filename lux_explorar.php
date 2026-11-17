<?php
require __DIR__ . '/lux_layout.php';
$u = lux_require(['cliente']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['accion'] ?? '') === 'pedido') {
        $productoId = (int)($_POST['producto_id'] ?? 0);
        $cantidad = filter_var($_POST['cantidad'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $medioPago = $_POST['medio_pago'] ?? '';
        $entrega = $_POST['entrega'] ?? '';
        $direccion = trim($_POST['direccion_envio'] ?? '');
        $mediosPago = ['Efectivo', 'Tarjeta', 'Transferencia'];
        $opcionesEntrega = ['Retiro en el local', 'Envío a domicilio'];
        $st = $db->prepare('SELECT id,negocio_id,nombre,stock FROM productos WHERE id=? AND negocio_id IN (1,2,3)');
        $st->execute([$productoId]);
        $producto = $st->fetch();
        if (!$producto || $cantidad === false || !in_array($medioPago, $mediosPago, true) || !in_array($entrega, $opcionesEntrega, true)) flash('Completá el producto, la cantidad, el medio de pago y la entrega.', true);
        elseif ($entrega === 'Envío a domicilio' && ($direccion === '' || mb_strlen($direccion) > 255)) flash('Para solicitar un envío, ingresá una dirección de hasta 255 caracteres.', true);
        elseif ((int)$producto['stock'] < $cantidad) flash('No hay stock suficiente de ese producto.', true);
        else {
            $db->prepare("INSERT INTO pedidos(negocio_id,cliente_id,producto_id,cantidad,estado,fecha,medio_pago,entrega,direccion_envio)
                VALUES(?,?,?,?,'Pendiente',?,?,?,?)")->execute([
                (int)$producto['negocio_id'], (int)$u['id'], (int)$producto['id'], $cantidad,
                date('Y-m-d H:i:s'), $medioPago, $entrega,
                $entrega === 'Envío a domicilio' ? $direccion : null,
            ]);
            flash('Pedido de «' . $producto['nombre'] . '» enviado al local y pendiente de confirmación.');
        }
    } else flash('La solicitud de pedido no es válida.', true);
    lux_redirect('lux_explorar.php');
}
$negs = $db->query('SELECT * FROM negocios')->fetchAll();
$buscar = mb_substr(trim($_GET['q'] ?? ''), 0, 190);
$sp = $db->prepare('SELECT * FROM productos WHERE negocio_id IN (1,2,3) AND nombre LIKE ? ORDER BY negocio_id,nombre');
$sp->execute(['%' . addcslashes($buscar, '\\%_') . '%']);
$productos = $sp->fetchAll();
$productosPorNegocio = [];
foreach ($productos as $producto) $productosPorNegocio[(int)$producto['negocio_id']][] = $producto;
lux_header('Explorar negocios', 'Los cuatro locales de Grupo Lux'); ?>
<form class="toolbar product-search" method="get" role="search">
<input type="search" name="q" value="<?= h($buscar) ?>" maxlength="190" placeholder="Buscar productos por nombre..." aria-label="Buscar productos por nombre">
<button class="btn-small" type="submit">Buscar</button>
<?php if ($buscar !== ''): ?><a class="btn-link" href="lux_explorar.php">Limpiar</a><?php endif; ?>
</form>
<div class="catalog-grid"><?php foreach ($negs as $n): ?>
<div class="catalog-card" style="--biz-color:var(--<?= h($n['color']) ?>)"><div class="name"><?= h($n['nombre']) ?></div>
<div class="type"><?= h($n['tipo']) ?></div><p><?= h($n['descripcion']) ?></p>
<?php if ((int)$n['id'] === 4): ?>
<a class="btn-small" href="lux_mis_turnos.php" style="display:inline-block;background:transparent;border:1px solid var(--border-strong);color:var(--text)">Reservar turno</a>
<?php else: ?>
<div class="shop-product-list"><h3>Productos para pedir</h3>
<?php $listaProductos = $productosPorNegocio[(int)$n['id']] ?? [];
if (!$listaProductos): ?><p class="shop-empty"><?= $buscar !== '' ? 'No se encontraron productos con ese nombre.' : 'Todavía no hay productos publicados.' ?></p>
<?php else: foreach ($listaProductos as $producto): ?>
<div class="shop-product"><div class="shop-product-info">
<b><?= h($producto['nombre']) ?></b><span><?= money($producto['precio']) ?> · <?= (int)$producto['stock'] > 0 ? 'Stock: ' . (int)$producto['stock'] : 'Sin stock' ?></span></div>
<?php if ((int)$producto['stock'] > 0): ?>
<details class="shop-order"><summary class="btn-small">Hacer pedido</summary>
<form method="post" class="shop-order-form"><?= csrf_field() ?><input type="hidden" name="accion" value="pedido">
<input type="hidden" name="producto_id" value="<?= (int)$producto['id'] ?>">
<div class="shop-order-options">
<label>Medio de pago<select name="medio_pago" required><option>Efectivo</option><option>Tarjeta</option><option>Transferencia</option></select></label>
<label>Entrega<select name="entrega" required><option>Retiro en el local</option><option>Envío a domicilio</option></select></label>
<label class="shop-address">Dirección (si elegís envío)<input type="text" name="direccion_envio" maxlength="255" placeholder="Calle, número y localidad"></label>
</div>
<div class="shop-order-actions"><input type="number" name="cantidad" value="1" min="1" max="<?= (int)$producto['stock'] ?>" required aria-label="Cantidad de <?= h($producto['nombre']) ?>">
<button class="btn-small" type="submit">Confirmar pedido</button></div></form></details>
<?php else: ?><span class="shop-unavailable">No disponible</span><?php endif; ?>
</div>
<?php endforeach; endif; ?>
</div>
<?php endif; ?></div>
<?php endforeach; ?></div>
<?php lux_footer();
