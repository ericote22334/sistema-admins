<?php
require __DIR__ . '/lux_layout.php';
$u = lux_require(['superadmin', 'admin', 'empleado']);
$nid = lux_negocio_id($u);
if (!$nid) lux_redirect('lux_negocios.php');
if ($nid !== 2) lux_redirect('lux_registrar_venta.php');

lux_carritos_importar_sesion($db, $nid, $u['id']);
$fr = $db->prepare('SELECT rol, puesto FROM usuarios WHERE id=?');
$fr->execute([$u['id']]);
$fr = $fr->fetch() ?: [];
$puede = lux_puede_vender(['rol' => $fr['rol'] ?? $u['rol'], 'puesto' => $fr['puesto'] ?? null, 'negocio_id' => $u['negocio_id'] ?? null]);
$ps = $db->prepare('SELECT id, nombre, precio, stock FROM productos WHERE negocio_id=? ORDER BY nombre');
$ps->execute([$nid]);
$productos = [];
foreach ($ps->fetchAll() as $producto) $productos[(int)$producto['id']] = $producto;
$cargarCarritos = function () use ($db, $nid) {
    $st = $db->prepare("SELECT c.id,c.nombre,COUNT(i.producto_id) productos,COALESCE(SUM(i.cantidad*p.precio),0) total
        FROM venta_carritos c
        LEFT JOIN venta_carrito_items i ON i.carrito_id=c.id
        LEFT JOIN productos p ON p.id=i.producto_id
        WHERE c.negocio_id=? AND c.estado='Abierto'
        GROUP BY c.id,c.nombre ORDER BY c.id");
    $st->execute([$nid]);
    return $st->fetchAll();
};
$crearCarrito = function ($nombre) use ($db, $nid, $u) {
    $nombre = trim($nombre);
    if ($nombre === '') $nombre = 'Sin referencia';
    $db->prepare('INSERT INTO venta_carritos(negocio_id,creado_por,nombre,fecha) VALUES(?,?,?,?)')
       ->execute([$nid, $u['id'], $nombre, date('Y-m-d H:i:s')]);
    return (int)$db->lastInsertId();
};
$carritos = $cargarCarritos();
$carritoRaw = $_GET['carrito'] ?? '';
$carritoId = 0;
if ($carritoRaw !== '') {
    $carritoId = is_scalar($carritoRaw) ? filter_var((string)$carritoRaw, FILTER_VALIDATE_INT) : false;
    if ($carritoId === false || $carritoId < 1) {
        flash('Ese carrito no está disponible.', true);
        lux_redirect('lux_carrito.php');
    }
    $abierto = false;
    foreach ($carritos as $item) if ((int)$item['id'] === (int)$carritoId) $abierto = true;
    if (!$abierto) {
        flash('Ese carrito ya se cobró o no está disponible.', true);
        lux_redirect('lux_carrito.php');
    }
    $carritoId = (int)$carritoId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$puede) {
        flash('Tu puesto puede ver los carritos, pero no modificarlos.', true);
        lux_redirect($carritoId ? 'lux_carrito.php?carrito=' . $carritoId : 'lux_carrito.php');
    }
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'nuevo') {
        $nombreRaw = $_POST['nombre'] ?? '';
        $nombre = is_scalar($nombreRaw) ? trim((string)$nombreRaw) : '';
        if (!is_scalar($nombreRaw) || mb_strlen($nombre) > 80) {
            flash('El nombre o referencia no puede superar los 80 caracteres.', true);
            lux_redirect($carritoId ? 'lux_carrito.php?carrito=' . $carritoId : 'lux_carrito.php');
        }
        $nuevoId = $crearCarrito($nombre);
        flash('Nuevo carrito #' . $nuevoId . ' listo para cargar productos.');
        lux_redirect('lux_carrito.php?carrito=' . $nuevoId);
    }

    $idRaw = $_POST['carrito_id'] ?? '';
    $idCarritoPost = is_scalar($idRaw) ? filter_var((string)$idRaw, FILTER_VALIDATE_INT) : false;
    if ($idCarritoPost === false || $idCarritoPost < 1) {
        flash('Seleccioná un carrito antes de modificarlo.', true);
        lux_redirect('lux_carrito.php');
    }
    $idCarritoPost = (int)$idCarritoPost;
    $error = ''; $mensaje = '';
    $db->beginTransaction();
    try {
        $lock = $db->prepare("SELECT id FROM venta_carritos WHERE id=? AND negocio_id=? AND estado='Abierto' FOR UPDATE");
        $lock->execute([$idCarritoPost, $nid]);
        if (!$lock->fetchColumn()) {
            $error = 'Ese carrito ya se cobró o fue eliminado.';
        } elseif ($accion === 'eliminar_carrito') {
            $db->prepare("DELETE FROM venta_carritos WHERE id=? AND negocio_id=? AND estado='Abierto'")->execute([$idCarritoPost, $nid]);
            $mensaje = "Carrito #$idCarritoPost eliminado.";
        } elseif (isset($_POST['quitar_id'])) {
            $productoRaw = $_POST['quitar_id'];
            $productoId = is_scalar($productoRaw) ? filter_var((string)$productoRaw, FILTER_VALIDATE_INT) : false;
            if ($productoId === false || $productoId < 1) $error = 'El producto indicado no es válido.';
            else {
                $db->prepare('DELETE FROM venta_carrito_items WHERE carrito_id=? AND producto_id=?')->execute([$idCarritoPost, $productoId]);
                $mensaje = "Producto quitado del carrito #$idCarritoPost.";
            }
        } elseif ($accion === 'agregar') {
            $productoRaw = $_POST['producto_id'] ?? '';
            $cantidadRaw = $_POST['cantidad'] ?? '';
            $productoId = is_scalar($productoRaw) ? filter_var((string)$productoRaw, FILTER_VALIDATE_INT) : false;
            $cantidad = is_scalar($cantidadRaw) ? filter_var((string)$cantidadRaw, FILTER_VALIDATE_INT) : false;
            if ($productoId === false || $productoId < 1 || $cantidad === false || $cantidad < 1 || $cantidad > 2147483647) {
                $error = 'Elegí un producto y una cantidad válida.';
            } else {
                $sp = $db->prepare('SELECT nombre,stock FROM productos WHERE id=? AND negocio_id=? FOR UPDATE');
                $sp->execute([$productoId, $nid]);
                $producto = $sp->fetch();
                if (!$producto) $error = 'El producto ya no está disponible en esta farmacia.';
                else {
                    $actual = $db->prepare('SELECT cantidad FROM venta_carrito_items WHERE carrito_id=? AND producto_id=?');
                    $actual->execute([$idCarritoPost, $productoId]);
                    $nuevaCantidad = (int)$actual->fetchColumn() + $cantidad;
                    if ($nuevaCantidad > (int)$producto['stock']) $error = "No hay stock suficiente de {$producto['nombre']}.";
                    else {
                        $db->prepare('INSERT INTO venta_carrito_items(carrito_id,producto_id,cantidad) VALUES(?,?,?) ON DUPLICATE KEY UPDATE cantidad=cantidad+VALUES(cantidad)')
                           ->execute([$idCarritoPost, $productoId, $cantidad]);
                        $mensaje = "{$producto['nombre']} agregado al carrito #$idCarritoPost.";
                    }
                }
            }
        } elseif ($accion === 'actualizar') {
            $cantidades = $_POST['items'] ?? [];
            if (!is_array($cantidades)) $error = 'No pude leer las cantidades del carrito.';
            else {
                $leerLinea = $db->prepare('SELECT cantidad FROM venta_carrito_items WHERE carrito_id=? AND producto_id=? FOR UPDATE');
                $leerProducto = $db->prepare('SELECT stock,nombre FROM productos WHERE id=? AND negocio_id=? FOR UPDATE');
                $actualizar = $db->prepare('UPDATE venta_carrito_items SET cantidad=? WHERE carrito_id=? AND producto_id=?');
                foreach ($cantidades as $productoRaw => $cantidadRaw) {
                    $productoId = filter_var((string)$productoRaw, FILTER_VALIDATE_INT);
                    $cantidad = is_scalar($cantidadRaw) ? filter_var((string)$cantidadRaw, FILTER_VALIDATE_INT) : false;
                    if ($productoId === false || $productoId < 1 || $cantidad === false || $cantidad < 1 || $cantidad > 2147483647) {
                        $error = 'El carrito contiene cantidades inválidas.';
                        break;
                    }
                    $leerLinea->execute([$idCarritoPost, $productoId]);
                    if (!$leerLinea->fetchColumn()) {
                        $error = 'Un producto indicado ya no está en este carrito.';
                        break;
                    }
                    $leerProducto->execute([$productoId, $nid]);
                    $producto = $leerProducto->fetch();
                    if (!$producto || $cantidad > (int)$producto['stock']) {
                        $error = $producto ? "Revisá la cantidad disponible de {$producto['nombre']}." : 'Un producto ya no está disponible en esta farmacia.';
                        break;
                    }
                    $actualizar->execute([$cantidad, $idCarritoPost, $productoId]);
                }
                if ($error === '') $mensaje = "Cantidades actualizadas en el carrito #$idCarritoPost.";
            }
        } elseif ($accion === 'vaciar') {
            $db->prepare('DELETE FROM venta_carrito_items WHERE carrito_id=?')->execute([$idCarritoPost]);
            $mensaje = "Carrito #$idCarritoPost vaciado.";
        } else {
            $error = 'Acción de carrito no válida.';
        }
        if ($error !== '') $db->rollBack();
        else $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    if ($error !== '') flash($error, true);
    else flash($mensaje);
    $destino = 'lux_carrito.php';
    if ($error === '' && $accion !== 'eliminar_carrito') $destino .= '?carrito=' . $idCarritoPost;
    lux_redirect($destino);
}

$carritos = $cargarCarritos();
$carrito = null;
if ($carritoId) {
    $st = $db->prepare("SELECT id,nombre FROM venta_carritos WHERE id=? AND negocio_id=? AND estado='Abierto'");
    $st->execute([$carritoId, $nid]);
    $carrito = $st->fetch() ?: null;
}
$lineas = [];
$total = 0.0;
if ($carrito) {
    $st = $db->prepare('SELECT p.id,p.nombre,p.precio,p.stock,i.cantidad FROM venta_carrito_items i JOIN productos p ON p.id=i.producto_id WHERE i.carrito_id=? ORDER BY p.nombre');
    $st->execute([$carritoId]);
    $lineas = $st->fetchAll();
    foreach ($lineas as &$linea) {
        $linea['subtotal'] = (float)$linea['precio'] * (int)$linea['cantidad'];
        $total += $linea['subtotal'];
    }
    unset($linea);
}

lux_header('Carritos de farmacia', 'Los carritos abiertos y sus números son compartidos por el personal de Farmacia.'); ?>
<?php if (!$puede): ?><div class="venta-aviso">Tu puesto puede consultar los carritos, pero no modificarlos ni cobrar ventas.</div><?php endif; ?>
<div class="farmacia-cabecera-carritos">
<div><h2 class="farmacia-titulo">Carritos abiertos</h2><p>El número de carrito es común para todos los usuarios de la farmacia.</p></div>
<?php if ($puede): ?><form method="post" class="farmacia-nuevo-carrito"><?= csrf_field() ?><input type="hidden" name="accion" value="nuevo">
<input type="text" name="nombre" maxlength="80" placeholder="Nombre o referencia (opcional)" aria-label="Nombre o referencia del carrito">
<button type="submit" class="btn-small">+ Nuevo carrito</button></form><?php endif; ?></div>

<?php if (!$carritos): ?><div class="empty-state">No hay carritos abiertos. <?php if ($puede): ?>Creá uno para empezar.<?php endif; ?></div>
<?php else: ?>
<div class="farmacia-lista-carritos">
<?php foreach ($carritos as $abierto): $seleccionado = (int)$abierto['id'] === $carritoId; ?>
<div class="farmacia-carrito-resumen <?= $seleccionado ? 'seleccionado' : '' ?>">
<div><b>Carrito #<?= (int)$abierto['id'] ?> · <?= h($abierto['nombre']) ?></b><span><?= (int)$abierto['productos'] ?> <?= (int)$abierto['productos'] === 1 ? 'producto' : 'productos' ?> · <?= money($abierto['total']) ?><?= $seleccionado ? ' · seleccionado' : '' ?></span></div>
<div class="farmacia-carrito-botones">
<?php if (!$seleccionado): ?><a class="btn-detalle" href="lux_carrito.php?carrito=<?= (int)$abierto['id'] ?>">Abrir carrito</a><?php endif; ?>
<?php if ($puede): ?><form method="post" class="inline" onsubmit="return confirm('¿Eliminar el carrito #<?= (int)$abierto['id'] ?> y sus productos cargados?')"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar_carrito"><input type="hidden" name="carrito_id" value="<?= (int)$abierto['id'] ?>"><button class="btn-del" type="submit">Eliminar</button></form><?php endif; ?></div></div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($carrito): ?>
<div class="section-title"><h2>Carrito #<?= (int)$carrito['id'] ?> · <?= h($carrito['nombre']) ?></h2></div>
<?php if ($puede): ?>
<div class="venta-card">
<form method="post" class="venta-form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="accion" value="agregar"><input type="hidden" name="carrito_id" value="<?= (int)$carritoId ?>">
<div class="field ancho"><label for="carrito-producto">Producto</label>
<div class="farmacia-agregar"><select name="producto_id" id="carrito-producto" required><option value="">Elegí un producto...</option>
<?php foreach ($productos as $producto): ?><option value="<?= (int)$producto['id'] ?>" <?= (int)$producto['stock'] < 1 ? 'disabled' : '' ?>><?= h($producto['nombre']) ?> — <?= h(money($producto['precio'])) ?> · stock <?= (int)$producto['stock'] ?></option><?php endforeach; ?>
</select><div class="field farmacia-cantidad"><label for="carrito-cantidad">Cantidad</label><input id="carrito-cantidad" type="number" name="cantidad" min="1" value="1" required></div>
<button type="submit" class="btn-small">Agregar al carrito #<?= (int)$carritoId ?></button></div></div></form></div>
<?php endif; ?>

<div class="section-title"><h2>Productos cargados</h2></div>
<?php if (!$lineas): ?><div class="empty-state">Este carrito todavía no tiene productos.</div>
<?php else: ?>
<div class="venta-card farmacia-carrito">
<?php if ($puede): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="actualizar"><input type="hidden" name="carrito_id" value="<?= (int)$carritoId ?>"><?php endif; ?>
<div class="pedidos-tabla"><table><thead><tr><th>Producto</th><th>Precio unitario</th><th>Cantidad</th><th>Subtotal</th><?php if ($puede): ?><th></th><?php endif; ?></tr></thead><tbody>
<?php foreach ($lineas as $linea): ?><tr><td><?= h($linea['nombre']) ?><?= (int)$linea['cantidad'] > (int)$linea['stock'] ? '<br><small class="carrito-alerta">Stock actual: ' . (int)$linea['stock'] . '</small>' : '' ?></td>
<td><?= money($linea['precio']) ?></td><td><?php if ($puede): ?><input class="carrito-cantidad" type="number" name="items[<?= (int)$linea['id'] ?>]" value="<?= (int)$linea['cantidad'] ?>" min="1" max="<?= (int)$linea['stock'] ?>" required><?php else: ?><?= (int)$linea['cantidad'] ?><?php endif; ?></td>
<td><?= money($linea['subtotal']) ?></td><?php if ($puede): ?><td><button class="btn-link" type="submit" name="quitar_id" value="<?= (int)$linea['id'] ?>" formnovalidate>Quitar</button></td><?php endif; ?></tr><?php endforeach; ?>
</tbody></table></div>
<?php if ($puede): ?><div class="farmacia-acciones"><button type="submit" class="btn-small">Actualizar cantidades</button><span>Total: <b><?= money($total) ?></b></span></div></form>
<div class="farmacia-pie"><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="accion" value="vaciar"><input type="hidden" name="carrito_id" value="<?= (int)$carritoId ?>"><button class="btn-link" type="submit">Vaciar carrito #<?= (int)$carritoId ?></button></form>
<a class="btn-small" href="lux_registrar_venta.php?carrito=<?= (int)$carritoId ?>">Continuar al cobro →</a></div><?php else: ?><div class="farmacia-acciones"><span>Total: <b><?= money($total) ?></b></span></div><?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php lux_footer();
