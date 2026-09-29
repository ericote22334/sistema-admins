<?php
require __DIR__ . '/lux_layout.php';
$u = lux_require(['superadmin', 'admin', 'empleado', 'cliente']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $nombre = trim($_POST['nombre'] ?? '');
    if ($nombre === '') flash('El nombre no puede estar vacío.', true);
    else {
        $db->prepare('UPDATE usuarios SET nombre=? WHERE id=?')->execute([$nombre, $u['id']]);
        $_SESSION['user']['nombre'] = $nombre;
        if (($_POST['nueva'] ?? '') !== '') {
            $hash = $db->prepare('SELECT pass FROM usuarios WHERE id=?'); $hash->execute([$u['id']]);
            if (!password_verify($_POST['actual'] ?? '', $hash->fetchColumn()) || strlen($_POST['nueva']) < 6) {
                flash('Clave actual incorrecta o nueva muy corta (mín. 6).', true); lux_redirect('lux_perfil.php');
            }
            $db->prepare('UPDATE usuarios SET pass=? WHERE id=?')->execute([password_hash($_POST['nueva'], PASSWORD_DEFAULT), $u['id']]);
        }
        flash('Perfil actualizado.');
    }
    lux_redirect('lux_perfil.php');
}
$neg = $u['negocio_id'] ? $db->query('SELECT nombre FROM negocios WHERE id=' . (int)$u['negocio_id'])->fetchColumn() : 'Todos los negocios';
lux_header('Mi perfil', 'Tus datos y tu acceso'); ?>
<form method="post"><?= csrf_field() ?><div class="settings-grid">
<div class="settings-block"><h3>Datos personales</h3>
<div class="field"><label>Nombre</label><input name="nombre" value="<?= h($u['nombre']) ?>"></div>
<div class="field"><label>Email</label><input value="<?= h($u['email']) ?>" disabled></div>
<div class="field"><label>Negocio asignado</label><input value="<?= h($neg) ?>" disabled></div></div>
<div class="settings-block"><h3>Cambiar contraseña</h3>
<div class="field"><label>Clave actual</label><input type="password" name="actual"></div>
<div class="field"><label>Nueva clave</label><input type="password" name="nueva" minlength="6"></div>
<button class="btn-small">Guardar cambios</button></div></div></form>
<?php lux_footer();
