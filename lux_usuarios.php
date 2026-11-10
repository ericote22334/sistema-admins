<?php
require __DIR__ . '/lux_layout.php';
$me = lux_require(['superadmin', 'admin']);
$esAdminNegocio = $me['rol'] === 'admin';
$negocioId = (int)($me['negocio_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = $_POST['accion'] ?? '';
    if ($a === 'crear') {
        $nombre = trim($_POST['nombre'] ?? ''); $email = trim($_POST['email'] ?? '');
        $neg = $esAdminNegocio ? $negocioId : (int)($_POST['negocio_id'] ?? 0);
        $puesto = trim($_POST['puesto'] ?? '');
        $mapa = lux_puestos($neg); $rol = null; $puestoDb = null;
        if (!$esAdminNegocio && $puesto === 'Cliente') $rol = 'cliente';
        elseif (isset($mapa[$puesto]) && ($mapa[$puesto] === 'empleado' || !$esAdminNegocio)) { $rol = $mapa[$puesto]; $puestoDb = $puesto; }
        if (!$nombre || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($_POST['pass'] ?? '') < 6
            || ($esAdminNegocio && !$negocioId) || !$rol) flash('Datos inválidos (clave de 6+ caracteres y un puesto que exista en el negocio elegido).', true);
        else try {
            $db->prepare('INSERT INTO usuarios(nombre,email,pass,rol,negocio_id,puesto) VALUES(?,?,?,?,?,?)')
               ->execute([$nombre, $email, password_hash($_POST['pass'], PASSWORD_DEFAULT), $rol, $neg ?: null, $puestoDb]);
            flash("Usuario $nombre creado.");
        } catch (PDOException $e) { flash('Ese email ya está registrado.', true); }
    } elseif ($a === 'puesto' && !$esAdminNegocio) {
        $nombresRol = ['admin' => 'Administrador', 'empleado' => 'Empleado', 'cliente' => 'Cliente'];
        $id = (int)($_POST['id'] ?? 0); $nuevo = trim($_POST['puesto'] ?? '');
        $t = $db->prepare('SELECT * FROM usuarios WHERE id=?'); $t->execute([$id]); $t = $t->fetch();
        $mapa = $t ? lux_puestos($t['negocio_id']) : [];
        $antes = $t ? (($t['puesto'] ?? '') ?: $nombresRol[$t['rol']] ?? $t['rol']) : '';
        if (!$t) flash('Datos inválidos.', true);
        elseif ($t['rol'] === 'superadmin') flash('No se puede cambiar el puesto de un Super Admin.', true);
        elseif ($id === (int)$me['id']) flash('No podés cambiar tu propio puesto.', true);
        elseif ($nuevo === 'Cliente') {
            if ($t['rol'] === 'cliente') flash('El usuario ya es cliente.', true);
            else { $db->prepare("UPDATE usuarios SET rol='cliente', puesto=NULL WHERE id=?")->execute([$id]); flash($t['nombre'] . ': ' . $antes . ' → Cliente.'); }
        }
        elseif (!isset($mapa[$nuevo])) flash('Ese puesto no existe en el negocio del usuario (¿tiene un negocio asignado?).', true);
        elseif (($t['puesto'] ?? '') === $nuevo && $t['rol'] === $mapa[$nuevo]) flash('El usuario ya tiene ese puesto.', true);
        else {
            $db->prepare('UPDATE usuarios SET rol=?, puesto=? WHERE id=?')->execute([$mapa[$nuevo], $nuevo, $id]);
            flash($t['nombre'] . ': ' . $antes . ' → ' . $nuevo . ($mapa[$nuevo] === 'admin' ? ' (acceso de administrador del negocio).' : '.'));
        }
    } elseif ($a === 'estado' && !$esAdminNegocio && (int)$_POST['id'] !== (int)$me['id']) {
        $db->prepare("UPDATE usuarios SET estado = CASE estado WHEN 'Activo' THEN 'Inactivo' ELSE 'Activo' END WHERE id=?")->execute([$_POST['id']]);
        flash('Estado actualizado.');
    } elseif ($a === 'estado' && !$esAdminNegocio) flash('No podés suspender tu propia cuenta.', true);
    lux_redirect('lux_usuarios.php');
}
$buscar = trim($_GET['q'] ?? '');
$termino = "%$buscar%";
if ($esAdminNegocio) {
    $st = $db->prepare("SELECT u.*, n.nombre negocio FROM usuarios u LEFT JOIN negocios n ON n.id=u.negocio_id
      WHERE u.negocio_id=? AND u.rol='empleado' AND (u.nombre LIKE ? OR u.email LIKE ?) ORDER BY u.id");
    $st->execute([$negocioId, $termino, $termino]);
} else {
    $st = $db->prepare("SELECT u.*, n.nombre negocio FROM usuarios u LEFT JOIN negocios n ON n.id=u.negocio_id
      WHERE u.nombre LIKE ? OR n.nombre LIKE ? OR u.email LIKE ? ORDER BY u.id");
    $st->execute([$termino, $termino, $termino]);
}
$lista = $st->fetchAll();
if (!$esAdminNegocio) $negs = $db->query('SELECT id,nombre FROM negocios')->fetchAll();
lux_header($esAdminNegocio ? 'Empleados' : 'Usuarios y roles', $esAdminNegocio ? 'Empleados de tu negocio' : 'Administradores, empleados y clientes de todos los negocios'); ?>
<form class="toolbar" method="get"><input type="text" name="q" value="<?= h($buscar) ?>" placeholder="Buscar usuario o negocio..."><button class="btn-small">Buscar</button></form>
<table><thead><tr><th>Usuario</th><th>Negocio</th><th>Rol</th><th>Estado</th><th></th></tr></thead><tbody>
<?php foreach ($lista as $u): ?><tr>
<td><?= h($u['nombre']) ?><div style="font-size:12px;color:var(--text-muted)"><?= h($u['email']) ?></div></td>
<td><?= h($u['negocio'] ?? 'Todos') ?></td>
<td><span class="role-badge <?= $u['rol'] === 'superadmin' ? 'admin' : h($u['rol']) ?>"><?= h(ucfirst($u['rol'])) ?></span>
<?php if (!empty($u['puesto'])): ?><div style="font-size:12px;color:var(--text-muted);margin-top:4px"><?= h($u['puesto']) ?></div><?php endif; ?></td>
<td style="color:<?= $u['estado'] === 'Activo' ? 'var(--green)' : 'var(--text-muted)' ?>"><?= h($u['estado']) ?></td>
<td class="row-actions"><?php if (!$esAdminNegocio && $u['rol'] !== 'superadmin' && (int)$u['id'] !== (int)$me['id'] && $u['negocio_id']):
  $actual = $u['rol'] === 'cliente' ? 'Cliente' : (string)($u['puesto'] ?? ''); ?>
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="accion" value="puesto"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
<select name="puesto" class="rol-select" data-actual="<?= h($actual) ?>" title="Cambiar puesto" onchange="if (confirm(<?= h(json_encode('¿Cambiar el puesto de ' . $u['nombre'] . '?')) ?>)) this.form.submit(); else this.value = this.dataset.actual;">
<?php if ($actual === ''): ?><option value="" disabled selected>Sin puesto</option><?php endif; ?>
<?php foreach (array_keys(lux_puestos($u['negocio_id'])) as $pu): ?><option value="<?= h($pu) ?>" <?= $actual === $pu ? 'selected' : '' ?>><?= h($pu) ?></option><?php endforeach; ?>
<option value="Cliente" <?= $actual === 'Cliente' ? 'selected' : '' ?>>Cliente</option></select></form><?php endif; ?>
<?php if (!$esAdminNegocio): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="accion" value="estado">
<input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn-link"><?= $u['estado'] === 'Activo' ? 'Suspender' : 'Reactivar' ?></button></form><?php endif; ?></td>
</tr><?php endforeach; ?></tbody></table>
<form method="post" class="quick-form wrap-form"><?= csrf_field() ?><input type="hidden" name="accion" value="crear">
<div class="field"><label>Nombre</label><input name="nombre" required></div>
<div class="field"><label>Email</label><input name="email" type="email" required></div>
<div class="field"><label>Clave</label><input name="pass" type="password" minlength="6" required></div>
<?php if (!$esAdminNegocio): ?>
<div class="field"><label>Negocio</label><select name="negocio_id" id="f-neg"><option value="">—</option>
<?php foreach ($negs as $n): ?><option value="<?= $n['id'] ?>"><?= h($n['nombre']) ?></option><?php endforeach; ?></select></div>
<?php endif; ?>
<div class="field"><label>Puesto</label><select name="puesto" id="f-puesto">
<?php if (!$esAdminNegocio) {
    foreach ($negs as $n) foreach (lux_puestos($n['id']) as $pu => $rolPuesto) echo '<option value="' . h($pu) . '" data-n="' . (int)$n['id'] . '">' . h($pu) . '</option>';
    echo '<option value="Cliente" data-n="*">Cliente</option>';
} else {
    foreach (lux_puestos($negocioId) as $pu => $rolPuesto) if ($rolPuesto === 'empleado') echo '<option value="' . h($pu) . '">' . h($pu) . '</option>';
} ?></select></div>
<button class="btn-small"><?= $esAdminNegocio ? '+ Agregar empleado' : '+ Nuevo usuario' ?></button></form>
<?php if (!$esAdminNegocio): ?><script>
(function () {
  var n = document.getElementById('f-neg'), p = document.getElementById('f-puesto');
  if (!n || !p) return;
  function filtrar() {
    var id = n.value, primero = null;
    for (var i = 0; i < p.options.length; i++) {
      var o = p.options[i], ok = (o.dataset.n === '*') || (id !== '' && o.dataset.n === id);
      o.disabled = !ok; o.hidden = !ok;
      if (ok && !primero) primero = o;
    }
    if (primero) p.value = primero.value;
  }
  n.addEventListener('change', filtrar); filtrar();
})();
</script><?php endif; ?>
<?php lux_footer();
