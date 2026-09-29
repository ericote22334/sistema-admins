<?php
require_once __DIR__ . '/lux_config.php';
if (lux_user()) lux_redirect(lux_home(lux_user()['rol']));
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = $db->prepare('SELECT * FROM usuarios WHERE email = ?');
    $st->execute([trim($_POST['email'] ?? '')]);
    $u = $st->fetch();
    if (!$u || !password_verify($_POST['pass'] ?? '', $u['pass'])) $err = 'Usuario o contraseña incorrectos.';
    elseif ($u['estado'] !== 'Activo') $err = 'Tu cuenta está suspendida. Contactá al administrador.';
    else {
        session_regenerate_id(true);
        unset($u['pass']);
        $_SESSION['user'] = $u;
        lux_redirect(lux_home($u['rol']));
    }
}
?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Grupo Lux · Ingresar</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="lux_estilos.css"></head><body>
<div id="login-screen">
<div class="login-visual"><div class="mark"><b>GRUPO LUX</b> · sistema unificado</div>
<div class="pitch"><h1>Un panel para cuatro negocios.</h1>
<p>Papelería, farmacia, tecnología y consultorio médico, administrados desde un mismo entorno, con acceso diferenciado por rol.</p>
<div class="biz-strip">
<div class="biz-chip" style="--chip-color:var(--blue)"><b>Papelería</b>útiles y oficina</div>
<div class="biz-chip" style="--chip-color:var(--green)"><b>Farmacia</b>venta y stock</div>
<div class="biz-chip" style="--chip-color:var(--violet)"><b>Tecnología</b>electro</div>
<div class="biz-chip" style="--chip-color:var(--teal)"><b>Consultorio</b>turnos</div></div></div>
<div class="mark">7mo B · Evaluación de proyecto — UX/UI</div></div>
<div class="login-form-wrap"><form class="login-form" method="post">
<div class="eyebrow">Ingresar</div><h2>Accedé a tu panel</h2>
<?php if ($err): ?><div class="login-err"><?= h($err) ?></div><?php endif; ?>
<?= csrf_field() ?>
<div class="field"><label for="email">Usuario</label><input id="email" name="email" type="email" required value="<?= h($_POST['email'] ?? 'admin@grupolux.com') ?>"></div>
<div class="field"><label for="pass">Contraseña</label><input id="pass" name="pass" type="password" required></div>
<button class="btn-primary" type="submit">Entrar al panel</button>
<div class="login-note">Demo (clave <b>lux2026</b>): admin@grupolux.com · diego.ferro@grupolux.com · ivana.roldan@mail.com</div>
</form></div></div></body></html>
