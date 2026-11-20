<?php
require __DIR__ . '/lux_layout.php';
$u = lux_require(['superadmin', 'admin', 'empleado']);
$nid = lux_negocio_id($u);
if (!$nid) lux_redirect('lux_negocios.php');
if ($nid !== 2) lux_redirect('lux_registrar_venta.php');

$fr = $db->prepare('SELECT rol, puesto FROM usuarios WHERE id=?');
$fr->execute([$u['id']]);
$fr = $fr->fetch() ?: [];
$puede = lux_puede_vender(['rol' => $fr['rol'] ?? $u['rol'], 'puesto' => $fr['puesto'] ?? null, 'negocio_id' => $u['negocio_id'] ?? null]);
$ps = $db->prepare('SELECT id, nombre, precio, stock FROM productos WHERE negocio_id=? ORDER BY nombre');
$ps->execute([$nid]);
$productos = [];
foreach ($ps->fetchAll() as $producto) $productos[(int)$producto['id']] = $producto;

$estado = lux_carritos_cargar($nid, $u['id']);
if (!$estado['carts'] && $_SERVER['REQUEST_METHOD'] !== 'POST') lux_carrito_crear($estado);
$carritoSolicitado = $_GET['carrito'] ?? '';
if ($carritoSolicitado !== '') {
    $carritoSolicitado = is_scalar($carritoSolicitado) ? (string)$carritoSolicitado : '';
    if ($carritoSolicitado === '' || !isset($estado['carts'][$carritoSolicitado])) {
        flash('Ese carrito ya no está disponible.', true);
        lux_redirect('lux_carrito.php');
    }
    $estado['active'] = $carritoSolicitado;
    lux_carritos_guardar($nid, $u['id'], $estado);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$puede) {
        flash('Tu puesto no registra ventas en este negocio.', true);
        lux_redirect('lux_carrito.php');
    }
    $accion = $_POST['accion'] ?? '';
    $idRaw = $_POST['carrito_id'] ?? '';
    $carritoId = is_scalar($idRaw) ? (string)$idRaw : '';
    if ($accion === 'nuevo') {
        $nombreRaw = $_POST['nombre'] ?? '';
        $nombre = is_scalar($nombreRaw) ? trim((string)$nombreRaw) : '';
        if (!is_scalar($nombreRaw) || mb_strlen($nombre) > 80) {
            flash('El nombre o referencia no puede superar los 80 caracteres.', true);
        } else {
            $carritoId = lux_carrito_crear($estado, $nombre);
            flash('Nuevo carrito listo para cargar productos.');
        }
        lux_carritos_guardar($nid, $u['id'], $estado);
        lux_redirect($carritoId !== '' && isset($estado['carts'][$carritoId]) ? 'lux_carrito.php?carrito=' . rawurlencode($carritoId) : 'lux_carrito.php');
    }
    if ($accion === 'seleccionar') {
        if (!isset($estado['carts'][$carritoId])) flash('Ese carrito ya no está disponible.', true);
        else {
            $estado['active'] = $carritoId;
            flash('Carrito seleccionado.');
        }
        lux_carritos_guardar($nid, $u['id'], $estado);
        lux_redirect(isset($estado['carts'][$carritoId]) ? 'lux_carrito.php?carrito=' . rawurlencode($carritoId) : 'lux_carrito.php');
    }
    if (!isset($estado['carts'][$carritoId])) {
        flash('Seleccioná un carrito antes de modificarlo.', true);
        lux_redirect('lux_carrito.php');
    }
    $estado['active'] = $carritoId;
    if ($accion === 'eliminar_carrito') {
        unset($estado['carts'][$carritoId]);
        if ($estado['active'] === $carritoId) $estado['active'] = '';
        flash('Carrito eliminado.');
    } else {
        $cart = $estado['carts'][$carritoId]['items'] ?? [];
        if (!is_array($cart)) $cart = [];
        if (isset($_POST['quitar_id'])) {
            $id = is_scalar($_POST['quitar_id']) ? filter_var((string)$_POST['quitar_id'], FILTER_VALIDATE_INT) : false;
            if ($id === false || !isset($cart[$id])) flash('No encontré ese producto en el carrito.', true);
            else {
                unset($cart[$id]);
                flash('Producto quitado del carrito.');
            }
        } elseif ($accion === 'agregar') {
            $productoRaw = $_POST['producto_id'] ?? '';
            $cantidadRaw = $_POST['cantidad'] ?? '';
            $id = is_scalar($productoRaw) ? filter_var((string)$productoRaw, FILTER_VALIDATE_INT) : false;
            $cantidad = is_scalar($cantidadRaw) ? filter_var((string)$cantidadRaw, FILTER_VALIDATE_INT) : false;
            if ($id === false || $cantidad === false || $cantidad < 1 || $cantidad > 2147483647 || !isset($productos[$id])) {
                flash('Elegí un producto y una cantidad válida.', true);
            } else {
                $enCarrito = (int)($cart[$id] ?? 0);
                if ($enCarrito + $cantidad > (int)$productos[$id]['stock']) {
                    flash("No hay stock suficiente de {$productos[$id]['nombre']}.", true);
                } else {
                    $cart[$id] = $enCarrito + $cantidad;
                    flash("{$productos[$id]['nombre']} agregado al carrito.");
                }
            }
        } elseif ($accion === 'actualizar') {
            $cantidades = $_POST['items'] ?? [];
            $error = !is_array($cantidades) ? 'No pude leer las cantidades del carrito.' : '';
            $actualizado = $cart;
            if (is_array($cantidades)) {
                foreach ($cantidades as $id => $cantidad) {
                    $id = filter_var((string)$id, FILTER_VALIDATE_INT);
                    if ($id === false || !isset($cart[$id]) || !is_scalar($cantidad)) {
                        $error = 'El carrito contiene datos inválidos.';
                        break;
                    }
                    $cantidad = filter_var((string)$cantidad, FILTER_VALIDATE_INT);
                    if ($cantidad === false || $cantidad < 1 || $cantidad > 2147483647 || !isset($productos[$id]) || $cantidad > (int)$productos[$id]['stock']) {
                        $error = isset($productos[$id]) ? "Revisá la cantidad disponible de {$productos[$id]['nombre']}." : 'Un producto del carrito ya no está disponible.';
                        break;
                    }
                    $actualizado[$id] = $cantidad;
                }
            }
            if ($error !== '') flash($error, true);
            else { $cart = $actualizado; flash('Cantidades actualizadas.'); }
        } elseif ($accion === 'vaciar') {
            $cart = [];
            flash('Carrito vaciado.');
        }
        $estado['carts'][$carritoId]['items'] = $cart;
    }
    lux_carritos_guardar($nid, $u['id'], $estado);
    lux_redirect($estado['active'] !== '' ? 'lux_carrito.php?carrito=' . rawurlencode($estado['active']) : 'lux_carrito.php');
}

lux_carritos_guardar($nid, $u['id'], $estado);
$carritoId = $estado['active'];
$carrito = $carritoId !== '' ? $estado['carts'][$carritoId] : null;
$cart = $carrito['items'] ?? [];
if (!is_array($cart)) $cart = [];
$lineas = [];
$total = 0.0;
foreach ($cart as $id => $cantidad) {
    $id = (int)$id;
    $disponible = isset($productos[$id]);
    $producto = $disponible ? $productos[$id] : ['nombre' => "Producto #$id no disponible", 'precio' => 0, 'stock' => 0];
    $cantidad = max(0, (int)$cantidad);
    $subtotal = (float)$producto['precio'] * $cantidad;
    $total += $subtotal;
    $lineas[] = ['id' => $id, 'producto' => $producto, 'cantidad' => $cantidad, 'subtotal' => $subtotal, 'disponible' => $disponible];
}

lux_header('Carritos de farmacia', 'Cada carrito corresponde a una compra distinta.'); ?>
<?php if (!$puede): ?>
<div class="venta-aviso">Tu puesto (<?= h($fr['puesto'] ?? '') ?>) no puede cargar productos para la venta.</div>
<?php else: ?>
<div class="farmacia-cabecera-carritos">
<div><h2 class="farmacia-titulo">Compras abiertas</h2><p>Creá un carrito por comprador para mantener sus productos separados.</p></div>
<form method="post" class="farmacia-nuevo-carrito"><?= csrf_field() ?><input type="hidden" name="accion" value="nuevo">
<input type="text" name="nombre" maxlength="80" placeholder="Nombre o referencia (opcional)" aria-label="Nombre o referencia del carrito">
<button type="submit" class="btn-small">+ Nuevo carrito</button></form></div>

<?php if (!$estado['carts']): ?><div class="empty-state">No hay carritos abiertos. Creá uno para empezar.</div>
<?php else: ?>
<div class="farmacia-lista-carritos">
<?php foreach ($estado['carts'] as $id => $datos): $itemsAbiertos = is_array($datos['items'] ?? null) ? $datos['items'] : []; $cantidadProductos = count($itemsAbiertos); $nombreCarrito = $datos['nombre'] ?? 'Carrito'; ?>
<div class="farmacia-carrito-resumen <?= $id === $carritoId ? 'seleccionado' : '' ?>">
<div><b><?= h($nombreCarrito) ?></b><span><?= $cantidadProductos ?> <?= $cantidadProductos === 1 ? 'producto' : 'productos' ?><?= $id === $carritoId ? ' · seleccionado' : '' ?></span></div>
<div class="farmacia-carrito-botones">
<?php if ($id !== $carritoId): ?><a class="btn-detalle" href="lux_carrito.php?carrito=<?= h(rawurlencode($id)) ?>">Abrir carrito</a><?php endif; ?>
<form method="post" class="inline" onsubmit="return confirm('¿Eliminar este carrito y sus productos cargados?')"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar_carrito"><input type="hidden" name="carrito_id" value="<?= h($id) ?>"><button class="btn-del" type="submit">Eliminar</button></form></div></div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($carrito): ?>
<div class="section-title"><h2><?= h($carrito['nombre'] ?? 'Carrito') ?> · productos</h2></div>
<div class="venta-card">
<form method="post" class="venta-form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="accion" value="agregar"><input type="hidden" name="carrito_id" value="<?= h($carritoId) ?>">
<div class="field ancho"><label for="carrito-producto">Producto</label>
<div class="farmacia-agregar"><select name="producto_id" id="carrito-producto" required><option value="">Elegí un producto...</option>
<?php foreach ($productos as $producto): ?><option value="<?= (int)$producto['id'] ?>" <?= (int)$producto['stock'] < 1 ? 'disabled' : '' ?>><?= h($producto['nombre']) ?> — <?= h(money($producto['precio'])) ?> · stock <?= (int)$producto['stock'] ?></option><?php endforeach; ?>
</select><div class="field farmacia-cantidad"><label for="carrito-cantidad">Cantidad</label><input id="carrito-cantidad" type="number" name="cantidad" min="1" value="1" required></div>
<button type="submit" class="btn-small">Agregar al carrito</button></div></div></form></div>

<div class="section-title"><h2>Productos cargados</h2></div>
<?php if (!$lineas): ?>
<div class="empty-state">Este carrito todavía no tiene productos.</div>
<?php else: ?>
<div class="venta-card farmacia-carrito">
<form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="actualizar"><input type="hidden" name="carrito_id" value="<?= h($carritoId) ?>">
<div class="pedidos-tabla"><table><thead><tr><th>Producto</th><th>Precio unitario</th><th>Cantidad</th><th>Subtotal</th><th></th></tr></thead><tbody>
<?php foreach ($lineas as $linea): ?><tr>
<td><?= h($linea['producto']['nombre']) ?><?= $linea['disponible'] && $linea['cantidad'] > (int)$linea['producto']['stock'] ? '<br><small class="carrito-alerta">Stock actual: ' . (int)$linea['producto']['stock'] . '</small>' : '' ?></td>
<td><?= $linea['disponible'] ? money($linea['producto']['precio']) : '—' ?></td>
<td><?php if ($linea['disponible']): ?><input class="carrito-cantidad" type="number" name="items[<?= $linea['id'] ?>]" value="<?= $linea['cantidad'] ?>" min="1" max="<?= (int)$linea['producto']['stock'] ?>" required><?php else: ?>—<?php endif; ?></td>
<td><?= $linea['disponible'] ? money($linea['subtotal']) : '—' ?></td>
<td><button class="btn-link" type="submit" name="quitar_id" value="<?= $linea['id'] ?>" formnovalidate>Quitar</button></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="farmacia-acciones"><button type="submit" class="btn-small">Actualizar cantidades</button><span>Total del carrito: <b><?= money($total) ?></b></span></div></form>
<div class="farmacia-pie">
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="accion" value="vaciar"><input type="hidden" name="carrito_id" value="<?= h($carritoId) ?>"><button class="btn-link" type="submit">Vaciar este carrito</button></form>
<a class="btn-small" href="lux_registrar_venta.php?carrito=<?= h(rawurlencode($carritoId)) ?>">Continuar a Registrar venta →</a></div>
</div>
<?php endif; ?>
<?php else: ?>
<div class="empty-state">Elegí un carrito abierto para cargar productos o creá uno nuevo.</div>
<?php endif; ?>
<?php endif; ?>
<?php lux_footer();
