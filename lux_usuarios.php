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
        $rol = $esAdminNegocio ? 'empleado' : ($_POST['rol'] ?? 'cliente');
        $neg = $esAdminNegocio ? $negocioId : ($_POST['negocio_id'] ?: null);
        if (!$nombre || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($_POST['pass'] ?? '') < 6
            || ($esAdminNegocio && !$negocioId)
            || !in_array($rol, ['admin','empleado','cliente'])) flash('Datos inválidos (clave de 6+ caracteres).', true);
        else try {
            $db->prepare('INSERT INTO usuarios(nombre,email,pass,rol,negocio_id) VALUES(?,?,?,?,?)')
               ->execute([$nombre, $email, password_hash($_POST['pass'], PASSWORD_DEFAULT), $rol, $neg]);
            flash("Usuario $nombre creado.");
        } catch (PDOException $e) { flash('Ese email ya está registrado.', true); }
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
<td><span class="role-badge <?= $u['rol'] === 'superadmin' ? 'admin' : h($u['rol']) ?>"><?= h(ucfirst($u['rol'])) ?></span></td>
<td style="color:<?= $u['estado'] === 'Activo' ? 'var(--green)' : 'var(--text-muted)' ?>"><?= h($u['estado']) ?></td>
<td class="row-actions"><?php if (!$esAdminNegocio): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="accion" value="estado">
<input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn-link"><?= $u['estado'] === 'Activo' ? 'Suspender' : 'Reactivar' ?></button></form><?php endif; ?></td>
</tr><?php endforeach; ?></tbody></table>
<form method="post" class="quick-form wrap-form"><?= csrf_field() ?><input type="hidden" name="accion" value="crear">
<div class="field"><label>Nombre</label><input name="nombre" required></div>
<div class="field"><label>Email</label><input name="email" type="email" required></div>
<div class="field"><label>Clave</label><input name="pass" type="password" minlength="6" required></div>
<?php if (!$esAdminNegocio): ?>
<div class="field"><label>Rol</label><select name="rol"><option value="empleado">Empleado</option><option value="admin">Administrador</option><option value="cliente">Cliente</option></select></div>
<div class="field"><label>Negocio</label><select name="negocio_id"><option value="">—</option>
<?php foreach ($negs as $n): ?><option value="<?= $n['id'] ?>"><?= h($n['nombre']) ?></option><?php endforeach; ?></select></div>
<?php endif; ?>
<button class="btn-small"><?= $esAdminNegocio ? '+ Agregar empleado' : '+ Nuevo usuario' ?></button></form>
<?php lux_footer();
