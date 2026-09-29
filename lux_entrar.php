<?php
require_once __DIR__ . '/lux_config.php';
lux_require(['superadmin']);
if (isset($_GET['salir'])) { unset($_SESSION['negocio_activo']); lux_redirect('lux_panel_general.php'); }
$s = $db->prepare('SELECT COUNT(*) FROM negocios WHERE id=?');
$s->execute([(int)($_GET['id'] ?? 0)]);
if ($s->fetchColumn()) { $_SESSION['negocio_activo'] = (int)$_GET['id']; lux_redirect('lux_ventas.php'); }
lux_redirect('lux_negocios.php');
