<?php
require_once __DIR__ . '/lux_config.php';
if (lux_user()) lux_redirect(lux_home(lux_user()['rol']));
$err = '';
$negocios = $db->query('SELECT id,nombre,tipo,color,descripcion FROM negocios ORDER BY id')->fetchAll();
$ofertas = [
    1 => ['Catálogo de útiles escolares, materiales de oficina y artículos de librería.', 'Consultá la disponibilidad de productos.', 'Registrá tus compras en un solo lugar.'],
    2 => ['Medicamentos, perfumería y productos de cuidado personal.', 'Consultá productos y opciones de retiro en el local.', 'Revisá tus compras desde tu cuenta.'],
    3 => ['Electrodomésticos y tecnología para el hogar.', 'Recibí asesoramiento para elegir tus productos.', 'Comprá con garantía y seguimiento de tus compras.'],
    4 => ['Atención de clínica general y especialidades.', 'Reservá y administrá tus turnos en línea.', 'Recibí recordatorios y consultá tus próximas citas.'],
];
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
<script src="lux_tema.js?v=<?= (int)@filemtime(__DIR__ . '/lux_tema.js') ?>"></script>
<link rel="stylesheet" href="lux_estilos.css?v=<?= (int)@filemtime(__DIR__ . '/lux_estilos.css') ?>"></head><body>
<div id="login-screen">
<div class="login-visual"><div class="mark"><b>GRUPO LUX</b> · sistema unificado</div>
<div class="pitch"><h1>Un panel para cuatro negocios.</h1>
<p>Papelería, farmacia, tecnología y consultorio médico, administrados desde un mismo entorno, con acceso diferenciado por rol.</p>
<div class="biz-strip">
<?php foreach ($negocios as $n): ?>
<button class="biz-chip" type="button" data-negocio="<?= (int)$n['id'] ?>"
  data-nombre="<?= h($n['nombre']) ?>" data-tipo="<?= h($n['tipo']) ?>"
  data-descripcion="<?= h($n['descripcion']) ?>"
  data-ofertas="<?= h(implode('|', $ofertas[(int)$n['id']] ?? [])) ?>"
  style="--chip-color:var(--<?= h($n['color']) ?>)" aria-haspopup="dialog">
<b><?= h($n['nombre']) ?></b><?= h($n['tipo']) ?><span class="biz-cta">Ver más →</span></button>
<?php endforeach; ?>
</div>
</div>
<dialog class="system-dialog" id="system-dialog" aria-labelledby="system-dialog-title">
<button class="system-dialog-close" type="button" aria-label="Cerrar">×</button>
<div class="eyebrow" id="system-dialog-type"></div>
<h2 id="system-dialog-title"></h2>
<p id="system-dialog-description"></p>
<h3>¿Qué vas a encontrar?</h3>
<ul id="system-dialog-offers"></ul>
<a class="btn-primary system-dialog-link" href="lux_registro.php">Crear cuenta de cliente</a>
</dialog>
<div class="mark">7mo B · Evaluación de proyecto — UX/UI</div></div>
<div class="login-form-wrap"><form class="login-form" method="post">
<div class="login-theme-control"><button class="theme-toggle" type="button" data-theme-toggle aria-label="Cambiar a modo oscuro" aria-pressed="false"><span data-theme-icon aria-hidden="true">🌙</span><span data-theme-label>Modo oscuro</span></button></div>
<div class="eyebrow">Ingresar</div><h2>Accedé a tu panel</h2>
<?php if ($err): ?><div class="login-err"><?= h($err) ?></div><?php endif; ?>
<?= csrf_field() ?>
<div class="field"><label for="email">Usuario</label><input id="email" name="email" type="email" required value="<?= h($_POST['email'] ?? 'admin@grupolux.com') ?>"></div>
<div class="field"><label for="pass">Contraseña</label><input id="pass" name="pass" type="password" required></div>
<button class="btn-primary" type="submit">Entrar al panel</button>
<div class="login-note">¿Todavía no tenés cuenta? <a class="login-link" href="lux_registro.php">Registrate como cliente</a></div>
<div class="login-note">Demo (clave <b>lux2026</b>): admin@grupolux.com · diego.ferro@grupolux.com · ivana.roldan@mail.com</div>
</form></div></div>
<script>
(function () {
  var dialog = document.getElementById('system-dialog');
  var close = dialog.querySelector('.system-dialog-close');
  var title = document.getElementById('system-dialog-title');
  var type = document.getElementById('system-dialog-type');
  var description = document.getElementById('system-dialog-description');
  var offers = document.getElementById('system-dialog-offers');
  document.querySelectorAll('.biz-chip').forEach(function (button) {
    button.addEventListener('click', function () {
      title.textContent = button.dataset.nombre;
      type.textContent = button.dataset.tipo;
      description.textContent = button.dataset.descripcion;
      while (offers.firstChild) offers.removeChild(offers.firstChild);
      button.dataset.ofertas.split('|').forEach(function (offer) {
        var item = document.createElement('li');
        item.textContent = offer;
        offers.appendChild(item);
      });
      dialog.showModal();
      close.focus();
    });
  });
  close.addEventListener('click', function () { dialog.close(); });
  dialog.addEventListener('click', function (event) {
    if (event.target === dialog) dialog.close();
  });
})();
</script>
</body></html>
