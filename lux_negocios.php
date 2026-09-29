<?php
require __DIR__ . '/lux_layout.php';
lux_require(['superadmin']);
$negocios = lux_negocios_stats($db);
$total = array_sum(array_column($negocios, 'ventas'));
lux_header('Negocios', 'Los cuatro locales que administra Grupo Lux'); ?>
<div class="biz-grid"><?php foreach ($negocios as $n) lux_biz_panel($n, $total); ?></div>
<?php lux_footer();
