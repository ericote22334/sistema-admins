<?php
require_once __DIR__ . '/lux_config.php';
lux_redirect(lux_user() ? lux_home(lux_user()['rol']) : 'lux_login.php');
