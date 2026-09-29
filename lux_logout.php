<?php
require_once __DIR__ . '/lux_config.php';
$_SESSION = [];
session_destroy();
lux_redirect('lux_login.php');
