<?php
// Consultorio Lux Salud — especialidades médicas: agregar, editar y eliminar (solo administrador)
require __DIR__ . '/lux_layout.php';
$u = lux_require(['superadmin', 'admin']);
$nid = lux_negocio_id($u);
if (!$nid) lux_redirect('lux_negocios.php');
if ($nid !== 4) lux_redirect('lux_ventas.php');

function lux_esp_nombre($v, &$err) {
    $n = is_scalar($v) ? trim(preg_replace('/\s+/u', ' ', (string)$v)) : '';
    if (mb_strlen($n) < 3 || mb_strlen($n) > 100) $err = 'El nombre de la especialidad debe tener entre 3 y 100 caracteres.';
    return $n;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = $_POST['accion'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $volver = 'lux_especialidades.php';
    try {
        if ($a === 'crear' || $a === 'editar') {
            $err = '';
            $nombre = lux_esp_nombre($_POST['nombre'] ?? '', $err);
            $dup = $db->prepare('SELECT COUNT(*) FROM especialidades WHERE nombre=? AND id<>?');
            $dup->execute([$nombre, $a === 'editar' ? $id : 0]);
            if (!$err && (int)$dup->fetchColumn()) $err = 'Ya existe una especialidad con ese nombre.';
            if ($err) {
                flash($err, true);
                if ($a === 'editar') $volver .= '?editar=' . $id;
            } elseif ($a === 'crear') {
                $db->prepare('INSERT INTO especialidades(nombre,creado) VALUES(?,?)')->execute([$nombre, date('Y-m-d H:i:s')]);
                flash('Especialidad «' . $nombre . '» agregada.');
            } else {
                $db->prepare('UPDATE especialidades SET nombre=? WHERE id=?')->execute([$nombre, $id]);
                flash('Especialidad actualizada.');
            }
        } elseif ($a === 'eliminar') {
            $q = $db->prepare('SELECT nombre, (SELECT COUNT(*) FROM medicos WHERE especialidad_id=especialidades.id) medicos FROM especialidades WHERE id=?');
            $q->execute([$id]);
            $e = $q->fetch();
            if (!$e) flash('La especialidad no existe.', true);
            elseif ((int)$e['medicos'] > 0) flash('No se puede eliminar «' . $e['nombre'] . '»: tiene ' . (int)$e['medicos'] . ' médico(s) asignado(s). Cambiales la especialidad primero.', true);
            else {
                $db->prepare('DELETE FROM especialidades WHERE id=?')->execute([$id]);
                flash('Especialidad «' . $e['nombre'] . '» eliminada.');
            }
        }
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') throw $e;
        flash('No se pudo guardar: ese nombre ya existe o la especialidad está en uso.', true);
    }
    lux_redirect($volver);
}

$editar = (int)($_GET['editar'] ?? 0);
$lista = $db->query('SELECT e.id, e.nombre, (SELECT COUNT(*) FROM medicos m WHERE m.especialidad_id=e.id) medicos FROM especialidades e ORDER BY e.nombre')->fetchAll();
lux_header('Especialidades', 'Especialidades médicas del Consultorio Lux Salud');
?>
<form method="post" class="quick-form"><?= csrf_field() ?><input type="hidden" name="accion" value="crear">
<div class="field"><label>Nueva especialidad</label><input name="nombre" maxlength="100" required placeholder="Ej.: Cardiología"></div>
<button class="btn-small">Agregar</button></form>
<?php if (!$lista): ?><div class="empty-state">Todavía no hay especialidades cargadas.</div>
<?php else: ?>
<div class="pedidos-tabla" style="margin-top:22px"><table><thead><tr><th>Especialidad</th><th>Médicos</th><th>Acciones</th></tr></thead><tbody>
<?php foreach ($lista as $e): ?><tr>
<?php if ($editar === (int)$e['id']): ?>
<td><input form="f-editar" name="nombre" maxlength="100" required value="<?= h($e['nombre']) ?>" autofocus></td>
<td><?= (int)$e['medicos'] ?></td>
<td><div class="acciones"><button form="f-editar" class="btn-small">Guardar</button><a class="btn-link" href="lux_especialidades.php">Cancelar</a></div></td>
<?php else: ?>
<td><?= h($e['nombre']) ?></td>
<td><?php if ((int)$e['medicos']): ?><a class="btn-link" href="lux_medicos.php?q=<?= h(urlencode($e['nombre'])) ?>"><?= (int)$e['medicos'] ?></a><?php else: ?>0<?php endif; ?></td>
<td><div class="acciones"><a class="btn-edit" href="lux_especialidades.php?editar=<?= (int)$e['id'] ?>">Editar</a>
<form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar la especialidad <?= h(addslashes($e['nombre'])) ?>?');"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
<button class="btn-del" <?= (int)$e['medicos'] ? 'disabled title="Tiene médicos asignados"' : '' ?>>Eliminar</button></form></div></td>
<?php endif; ?>
</tr><?php endforeach; ?>
</tbody></table></div>
<?php if ($editar): ?><form id="f-editar" method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="editar"><input type="hidden" name="id" value="<?= $editar ?>"></form><?php endif; ?>
<?php endif; ?>
<?php lux_footer();
