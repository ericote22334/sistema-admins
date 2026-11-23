<?php
require_once __DIR__ . '/lux_config.php';
if (lux_user()) lux_redirect(lux_home(lux_user()['rol']));

$err = '';
$nombre = trim($_POST['nombre'] ?? '');
$email = trim($_POST['email'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pass = $_POST['pass'] ?? '';
    $confirmar = $_POST['pass_confirm'] ?? '';
    if ($nombre === '' || mb_strlen($nombre) > 150) $err = 'Ingresá tu nombre (hasta 150 caracteres).';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) $err = 'Ingresá un email válido.';
    elseif (strlen($pass) < 8) $err = 'La contraseña debe tener al menos 8 caracteres.';
    elseif ($pass !== $confirmar) $err = 'Las contraseñas no coinciden.';
    else {
        try {
            $st = $db->prepare("INSERT INTO usuarios(nombre,email,pass,rol,negocio_id) VALUES(?,?,?,'cliente',NULL)");
            $st->execute([$nombre, $email, password_hash($pass, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int)$db->lastInsertId(),
                'nombre' => $nombre,
                'email' => $email,
                'rol' => 'cliente',
                'negocio_id' => null,
                'estado' => 'Activo',
                'puesto' => null,
            ];
            lux_redirect('lux_mis_datos.php');
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) $err = 'Ese email ya está registrado. Probá iniciar sesión.';
            else throw $e;
        }
    }
}
?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Grupo Lux · Crear cuenta</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="lux_tema.js?v=<?= (int)@filemtime(__DIR__ . '/lux_tema.js') ?>"></script>
<link rel="stylesheet" href="lux_estilos.css?v=<?= (int)@filemtime(__DIR__ . '/lux_estilos.css') ?>"></head><body>
<div id="login-screen">
<div class="login-visual"><div class="mark"><b>GRUPO LUX</b> · sistema unificado</div>
<div class="pitch"><h1>Tu cuenta, todos los negocios.</h1>
<p>Registrate para consultar tus compras y turnos en los cuatro sistemas de Grupo Lux.</p></div>
<div class="mark">7mo B · Evaluación de proyecto — UX/UI</div></div>
<div class="login-form-wrap"><form class="login-form" method="post">
<div class="login-theme-control"><button class="theme-toggle" type="button" data-theme-toggle aria-label="Cambiar a modo oscuro" aria-pressed="false"><span data-theme-icon aria-hidden="true">🌙</span><span data-theme-label>Modo oscuro</span></button></div>
<div class="eyebrow">Registro de clientes</div><h2>Creá tu cuenta</h2>
<?php if ($err): ?><div class="login-err" role="alert"><?= h($err) ?></div><?php endif; ?>
<?= csrf_field() ?>
<div class="field"><label for="nombre">Nombre completo</label><input id="nombre" name="nombre" type="text" maxlength="150" required autocomplete="name" value="<?= h($nombre) ?>"></div>
<div class="field"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="190" required autocomplete="email" value="<?= h($email) ?>"></div>
<div class="field"><label for="pass">Contraseña (mínimo 8 caracteres)</label><input id="pass" name="pass" type="password" minlength="8" required autocomplete="new-password"></div>
<div class="field"><label for="pass_confirm">Repetí la contraseña</label><input id="pass_confirm" name="pass_confirm" type="password" minlength="8" required autocomplete="new-password"></div>
<button class="btn-primary" type="submit">Crear cuenta</button>
<div class="login-note">¿Ya tenés cuenta? <a class="login-link" href="lux_login.php">Volver al ingreso</a></div>
</form></div></div></body></html>
