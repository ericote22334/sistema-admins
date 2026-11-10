<?php
require __DIR__ . '/lux_layout.php';
$u = lux_require(['superadmin', 'admin', 'empleado']);
$nid = lux_negocio_id($u);
if (!$nid) lux_redirect('lux_negocios.php');
$puedeBorrar = in_array($u['rol'], ['superadmin', 'admin']); // el empleado puede editar, solo el admin elimina
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['accion'] ?? '') === 'nuevo') {
        $n = trim($_POST['nombre'] ?? '');
        if ($n === '' || (float)$_POST['precio'] < 0) flash('Datos inválidos.', true);
        else { $db->prepare('INSERT INTO productos(negocio_id,nombre,precio,stock,minimo) VALUES(?,?,?,?,?)')
                  ->execute([$nid, $n, (float)$_POST['precio'], max(0, (int)$_POST['stock']), max(0, (int)$_POST['minimo'])]);
               flash("Producto «$n» agregado."); }
    } elseif (($_POST['accion'] ?? '') === 'reponer') {
        $db->prepare('UPDATE productos SET stock=stock+? WHERE id=? AND negocio_id=?')->execute([max(1, (int)$_POST['cant']), $_POST['id'], $nid]);
        flash('Stock repuesto.');
    } elseif (($_POST['accion'] ?? '') === 'editar') {
        $n = trim($_POST['nombre'] ?? '');
        if ($n === '' || (float)$_POST['precio'] < 0) { flash('Datos inválidos.', true); lux_redirect('lux_inventario.php?editar=' . (int)$_POST['id']); }
        $db->prepare('UPDATE productos SET nombre=?, precio=?, stock=?, minimo=? WHERE id=? AND negocio_id=?')
           ->execute([$n, (float)$_POST['precio'], max(0, (int)$_POST['stock']), max(0, (int)$_POST['minimo']), (int)$_POST['id'], $nid]);
        flash("Producto «$n» actualizado.");
    } elseif (($_POST['accion'] ?? '') === 'eliminar') {
        $sn = $db->prepare('SELECT nombre FROM productos WHERE id=? AND negocio_id=?');
        $sn->execute([(int)$_POST['id'], $nid]); $nom = $sn->fetchColumn();
        if (!$puedeBorrar) flash('Solo un administrador puede eliminar productos.', true);
        elseif ($nom === false) flash('El producto no existe.', true);
        else {
            try { $db->prepare('DELETE FROM productos WHERE id=? AND negocio_id=?')->execute([(int)$_POST['id'], $nid]); flash("Producto «$nom» eliminado."); }
            catch (PDOException $e) { flash("No se puede eliminar «$nom»: tiene ventas o pedidos registrados. Si ya no lo vendés, dejá el stock en 0.", true); }
        }
    }
    lux_redirect('lux_inventario.php');
}
$b = trim($_GET['q'] ?? '');
$st = $db->prepare('SELECT * FROM productos WHERE negocio_id=? AND nombre LIKE ? ORDER BY nombre');
$st->execute([$nid, "%$b%"]); $lista = $st->fetchAll();
$ed = null;
if (!empty($_GET['editar'])) { $se = $db->prepare('SELECT * FROM productos WHERE id=? AND negocio_id=?'); $se->execute([(int)$_GET['editar'], $nid]); $ed = $se->fetch() ?: null; }
lux_header('Inventario', 'Stock del negocio asignado'); ?>
<?php if ($ed): ?>
<div class="pedido-detalle edit-card"><div class="cab"><h2>Editar producto</h2><a class="btn-link" href="lux_inventario.php">Cancelar ✕</a></div>
<form method="post" class="quick-form wrap-form"><?= csrf_field() ?><input type="hidden" name="accion" value="editar"><input type="hidden" name="id" value="<?= (int)$ed['id'] ?>">
<div class="field"><label>Producto</label><input name="nombre" value="<?= h($ed['nombre']) ?>" required></div>
<div class="field"><label>Precio</label><input type="number" name="precio" min="0" step="0.01" value="<?= h($ed['precio']) ?>" required></div>
<div class="field"><label>Stock</label><input type="number" name="stock" min="0" value="<?= (int)$ed['stock'] ?>"></div>
<div class="field"><label>Mínimo</label><input type="number" name="minimo" min="0" value="<?= (int)$ed['minimo'] ?>"></div>
<button class="btn-small">Guardar cambios</button></form></div>
<?php endif; ?>
<form class="toolbar" method="get"><input type="text" name="q" value="<?= h($b) ?>" placeholder="Buscar producto..."><button class="btn-small">Buscar</button></form>
<table><thead><tr><th>Producto</th><th>Precio</th><th>Stock</th><th>Mínimo</th><th>Estado</th><th>Reponer</th><th>Acciones</th></tr></thead><tbody>
<?php foreach ($lista as $p): $bajo = $p['stock'] < $p['minimo']; ?><tr>
<td><?= h($p['nombre']) ?></td><td><?= money($p['precio']) ?></td><td><?= $p['stock'] ?></td><td><?= $p['minimo'] ?></td>
<td><span class="status-pill <?= $bajo ? 'low' : 'ok' ?>"><?= $bajo ? 'Bajo' : 'Normal' ?></span></td>
<td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="accion" value="reponer"><input type="hidden" name="id" value="<?= $p['id'] ?>">
<input type="number" name="cant" value="10" min="1"> <button class="btn-link">+ Agregar</button></form></td>
<td><div class="acciones"><a class="btn-edit" href="lux_inventario.php?editar=<?= (int)$p['id'] ?>">✏️ Editar</a>
<?php if ($puedeBorrar): ?><form method="post" class="inline" onsubmit="return confirm(<?= h(json_encode('¿Eliminar «' . $p['nombre'] . '» del inventario?')) ?>)"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn-del" title="Eliminar">🗑</button></form><?php endif; ?></div></td></tr><?php endforeach; ?>
</tbody></table>
<form method="post" class="quick-form wrap-form"><?= csrf_field() ?><input type="hidden" name="accion" value="nuevo">
<div class="field"><label>Producto</label><input name="nombre" required></div>
<div class="field"><label>Precio</label><input type="number" name="precio" min="0" step="0.01" required></div>
<div class="field"><label>Stock</label><input type="number" name="stock" min="0" value="0"></div>
<div class="field"><label>Mínimo</label><input type="number" name="minimo" min="0" value="5"></div>
<button class="btn-small">+ Nuevo producto</button></form>
<?php lux_footer();
