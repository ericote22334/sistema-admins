<?php
require __DIR__ . '/lux_layout.php';
lux_require(['superadmin']);
$q = function ($sql, $p = []) use ($db) { $s = $db->prepare($sql); $s->execute($p); return $s->fetchColumn(); };
$hoy = date('Y-m-d'); $ayer = date('Y-m-d', strtotime('-1 day'));
$vHoy = $q('SELECT COALESCE(SUM(total),0) FROM ventas WHERE date(fecha)=?', [$hoy]);
$vAyer = $q('SELECT COALESCE(SUM(total),0) FROM ventas WHERE date(fecha)=?', [$ayer]);
$delta = $vAyer > 0 ? round(($vHoy - $vAyer) * 100 / $vAyer, 1) : 0;
$turnos = $q('SELECT COUNT(*) FROM turnos WHERE date(fecha)=?', [$hoy]);
$activos = $q("SELECT COUNT(*) FROM usuarios WHERE estado='Activo'");
$alertas = $q('SELECT COUNT(*) FROM productos WHERE stock<minimo');
$negocios = lux_negocios_stats($db);
$dias = [];
for ($i = 6; $i >= 0; $i--) {
    $f = date('Y-m-d', strtotime("-$i days"));
    $dias[] = [strftime_es($f), (float)$q('SELECT COALESCE(SUM(total),0) FROM ventas WHERE date(fecha)=?', [$f])];
}
function strftime_es($f) { return ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'][date('w', strtotime($f))]; }
$max = max(1, max(array_column($dias, 1)));
lux_header('Panel general', 'Métricas de los cuatro negocios de Grupo Lux, hoy'); ?>
<div class="kpi-row">
<div class="kpi"><div class="label">Ventas totales hoy</div><div class="value"><?= money($vHoy) ?></div>
<div class="delta <?= $delta >= 0 ? 'up' : 'down' ?>"><?= $delta >= 0 ? '↑' : '↓' ?> <?= abs($delta) ?>% vs. ayer</div></div>
<div class="kpi"><div class="label">Turnos del consultorio</div><div class="value"><?= $turnos ?></div></div>
<div class="kpi"><div class="label">Usuarios activos</div><div class="value"><?= $activos ?></div></div>
<div class="kpi"><div class="label">Alertas de stock</div><div class="value"><?= $alertas ?></div><div class="delta down">Bajo el mínimo</div></div></div>
<div class="chart-card"><div class="section-title"><h2>Ventas totales — últimos 7 días</h2></div>
<div class="chart-bars"><?php foreach ($dias as $d): ?>
<div class="chart-col" title="<?= money($d[1]) ?>"><div class="bar" style="height:<?= max(3, round($d[1] * 100 / $max)) ?>%"></div><div class="lbl"><?= $d[0] ?></div></div>
<?php endforeach; ?></div></div>
<div class="section-title"><h2>Estado de los negocios</h2><a class="link" href="lux_negocios.php">Ver todos →</a></div>
<div class="biz-grid"><?php foreach ($negocios as $n) lux_biz_panel($n, $vHoy); ?></div>
<?php lux_footer();
