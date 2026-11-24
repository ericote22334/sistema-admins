<?php
// Consultorio Lux Salud — reportes y estadísticas (solo administrador). Son datos agregados: no muestra información clínica de personas.
require __DIR__ . '/lux_layout.php';
$u = lux_require(['superadmin', 'admin']);
$nid = lux_negocio_id($u);
if (!$nid) lux_redirect('lux_negocios.php');
if ($nid !== 4) lux_redirect('lux_ventas.php');

$hoy = date('Y-m-d');
function lux_rep_fecha($s, $def) { $s = is_scalar($s) ? (string)$s : ''; $d = DateTime::createFromFormat('Y-m-d', $s); return $d && $d->format('Y-m-d') === $s ? $s : $def; }
function lux_rep_mes($ym) {
    $n = [1 => 'ene', 2 => 'feb', 3 => 'mar', 4 => 'abr', 5 => 'may', 6 => 'jun', 7 => 'jul', 8 => 'ago', 9 => 'sep', 10 => 'oct', 11 => 'nov', 12 => 'dic'];
    return $n[(int)substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4);
}
function lux_rep_barras(array $filas) { // [[etiqueta, valor, texto], ...]
    $max = 0; foreach ($filas as $f) $max = max($max, (float)$f[1]);
    $o = '<div class="rep-barras">';
    foreach ($filas as $f) {
        $w = $max > 0 ? round((float)$f[1] / $max * 100, 1) : 0;
        $o .= '<div class="rep-fila"><span class="rep-et">' . h($f[0]) . '</span><span class="rep-bar"><i style="width:' . $w . '%"></i></span><span class="rep-val">' . h($f[2]) . '</span></div>';
    }
    return $o . '</div>';
}

// ---------- Período (por defecto, los últimos 12 meses) ----------
$primeroMes = date('Y-m-01');
$desde = lux_rep_fecha($_GET['desde'] ?? '', date('Y-m-d', strtotime($primeroMes . ' -11 months')));
$hasta = lux_rep_fecha($_GET['hasta'] ?? '', $hoy);
if ($desde > $hasta) { $t = $desde; $desde = $hasta; $hasta = $t; }
$limite = date('Y-m-d', strtotime(date('Y-m-01', strtotime($hasta)) . ' -35 months')); // se muestran como máximo 36 meses
if ($desde < $limite) $desde = $limite;
$ini = $desde . ' 00:00:00'; $fin = date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00';

$meses = [];
for ($m = date('Y-m-01', strtotime($desde)); $m <= $hasta; $m = date('Y-m-d', strtotime($m . ' +1 month')))
    $meses[substr($m, 0, 7)] = ['turnos' => 0, 'aten' => 0, 'canc' => 0, 'consultas' => 0, 'pac' => 0, 'ing' => 0.0];

$serie = function ($sql, array $par, callable $aplicar) use ($db) { $s = $db->prepare($sql); $s->execute($par); foreach ($s->fetchAll() as $r) $aplicar($r); };
$serie("SELECT DATE_FORMAT(fecha,'%Y-%m') ym, COUNT(*) n, COALESCE(SUM(estado='Atendido'),0) aten, COALESCE(SUM(estado='Cancelado'),0) canc
        FROM turnos WHERE negocio_id=4 AND fecha>=? AND fecha<? GROUP BY ym", [$ini, $fin],
    function ($r) use (&$meses) { if (isset($meses[$r['ym']])) { $meses[$r['ym']]['turnos'] = (int)$r['n']; $meses[$r['ym']]['aten'] = (int)$r['aten']; $meses[$r['ym']]['canc'] = (int)$r['canc']; } });
$serie("SELECT DATE_FORMAT(fecha,'%Y-%m') ym, COUNT(*) n FROM consultas_clinicas WHERE estado='Finalizada' AND fecha>=? AND fecha<? GROUP BY ym", [$ini, $fin],
    function ($r) use (&$meses) { if (isset($meses[$r['ym']])) $meses[$r['ym']]['consultas'] = (int)$r['n']; });
$serie("SELECT DATE_FORMAT(creado,'%Y-%m') ym, COUNT(*) n FROM pacientes WHERE creado>=? AND creado<? GROUP BY ym", [$ini, $fin],
    function ($r) use (&$meses) { if (isset($meses[$r['ym']])) $meses[$r['ym']]['pac'] = (int)$r['n']; });
$serie("SELECT DATE_FORMAT(fecha,'%Y-%m') ym, SUM(total) t FROM ventas WHERE negocio_id=4 AND fecha>=? AND fecha<? GROUP BY ym", [$ini, $fin],
    function ($r) use (&$meses) { if (isset($meses[$r['ym']])) $meses[$r['ym']]['ing'] = (float)$r['t']; });

$tot = ['turnos' => 0, 'aten' => 0, 'canc' => 0, 'consultas' => 0, 'pac' => 0, 'ing' => 0.0];
foreach ($meses as $m) foreach ($tot as $k => $v) $tot[$k] += $m[$k];
$pctCanc = $tot['turnos'] > 0 ? round($tot['canc'] / $tot['turnos'] * 100, 1) : 0;

// Exportar el resumen mensual a CSV (separado por ; para que lo abra bien Excel en español)
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte-consultorio-' . $desde . '_a_' . $hasta . '.csv"');
    echo "\xEF\xBB\xBF";
    $o = fopen('php://output', 'w');
    fputcsv($o, ['Mes', 'Turnos', 'Atendidos', 'Cancelados', 'Consultas realizadas', 'Pacientes nuevos', 'Ingresos'], ';');
    foreach ($meses as $ym => $m) fputcsv($o, [$ym, $m['turnos'], $m['aten'], $m['canc'], $m['consultas'], $m['pac'], number_format($m['ing'], 2, ',', '')], ';');
    fputcsv($o, ['Total', $tot['turnos'], $tot['aten'], $tot['canc'], $tot['consultas'], $tot['pac'], number_format($tot['ing'], 2, ',', '')], ';');
    fclose($o);
    exit;
}

$base = "u.rol='cliente' AND (p.id IS NOT NULL OR EXISTS (SELECT 1 FROM turnos t0 WHERE t0.cliente_id=u.id AND t0.negocio_id=4))";
$pacTotal = (int)$db->query("SELECT COUNT(*) FROM usuarios u LEFT JOIN pacientes p ON p.usuario_id=u.id WHERE $base")->fetchColumn();
$pacFicha = (int)$db->query('SELECT COUNT(*) FROM pacientes')->fetchColumn();

$st = $db->prepare("SELECT mu.nombre, e.nombre esp, COUNT(*) n FROM consultas_clinicas c JOIN medicos m ON m.id=c.medico_id JOIN usuarios mu ON mu.id=m.usuario_id
    JOIN especialidades e ON e.id=m.especialidad_id WHERE c.estado='Finalizada' AND c.fecha>=? AND c.fecha<? GROUP BY m.id, mu.nombre, e.nombre ORDER BY n DESC, mu.nombre LIMIT 10");
$st->execute([$ini, $fin]); $topMedicos = $st->fetchAll();
$st = $db->prepare("SELECT e.nombre, COUNT(*) n FROM consultas_clinicas c JOIN medicos m ON m.id=c.medico_id JOIN especialidades e ON e.id=m.especialidad_id
    WHERE c.estado='Finalizada' AND c.fecha>=? AND c.fecha<? GROUP BY e.id, e.nombre ORDER BY n DESC, e.nombre");
$st->execute([$ini, $fin]); $porEsp = $st->fetchAll();
$st = $db->prepare("SELECT COALESCE(NULLIF(medio_pago,''),'Sin dato') mp, SUM(total) t FROM ventas WHERE negocio_id=4 AND fecha>=? AND fecha<? GROUP BY mp ORDER BY t DESC");
$st->execute([$ini, $fin]); $porPago = $st->fetchAll();
$st = $db->prepare('SELECT COUNT(DISTINCT COALESCE(ticket_id, id)) FROM ventas WHERE negocio_id=4 AND fecha>=? AND fecha<?');
$st->execute([$ini, $fin]); $cobros = (int)$st->fetchColumn();

$sinCobrar = (int)$db->query("SELECT COUNT(*) FROM consultas_clinicas WHERE estado='Finalizada' AND cobro='Pendiente'")->fetchColumn();
$q = http_build_query(['desde' => $desde, 'hasta' => $hasta]);
$atajos = [
    'Últimos 30 días' => [date('Y-m-d', strtotime($hoy . ' -29 days')), $hoy],
    'Últimos 3 meses' => [date('Y-m-d', strtotime($primeroMes . ' -2 months')), $hoy],
    'Últimos 6 meses' => [date('Y-m-d', strtotime($primeroMes . ' -5 months')), $hoy],
    'Últimos 12 meses' => [date('Y-m-d', strtotime($primeroMes . ' -11 months')), $hoy],
    'Este año' => [date('Y-01-01'), $hoy],
];
lux_header('Reportes y estadísticas', 'Consultorio Lux Salud — del ' . date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta))); ?>
<form class="pedidos-filtros" method="get">
<label style="align-self:center;font-size:13px;color:var(--text-muted)">Desde</label><input type="date" name="desde" value="<?= h($desde) ?>" max="<?= h($hoy) ?>">
<label style="align-self:center;font-size:13px;color:var(--text-muted)">Hasta</label><input type="date" name="hasta" value="<?= h($hasta) ?>">
<button class="btn-small">Ver</button>
<a class="btn-link" style="margin-left:auto;align-self:center" href="lux_reportes.php?<?= h($q) ?>&export=1">Descargar CSV</a>
</form>
<div class="rep-atajos"><?php foreach ($atajos as $n => $r): ?><a class="btn-link" href="lux_reportes.php?<?= h(http_build_query(['desde' => $r[0], 'hasta' => $r[1]])) ?>"><?= h($n) ?></a><?php endforeach; ?></div>

<div class="kpi-row" style="grid-template-columns:repeat(5,1fr)">
<div class="kpi"><div class="label">Pacientes registrados</div><div class="value"><?= $pacTotal ?></div><div class="delta"><?= $pacFicha ?> con ficha · <?= $tot['pac'] ?> nuevos en el período</div></div>
<div class="kpi"><div class="label">Turnos del período</div><div class="value"><?= $tot['turnos'] ?></div><div class="delta"><?= $tot['aten'] ?> atendidos</div></div>
<div class="kpi"><div class="label">Consultas realizadas</div><div class="value"><?= $tot['consultas'] ?></div><div class="delta">finalizadas en el período</div></div>
<div class="kpi"><div class="label">Turnos cancelados</div><div class="value"><?= $tot['canc'] ?></div><div class="delta <?= $pctCanc >= 20 ? 'down' : '' ?>"><?= h(str_replace('.', ',', (string)$pctCanc)) ?>% de los turnos</div></div>
<div class="kpi"><div class="label">Ingresos</div><div class="value"><?= h(money($tot['ing'])) ?></div><div class="delta"><?= $cobros ?> cobro(s)<?= $cobros ? ' · prom. ' . h(money($tot['ing'] / $cobros)) : '' ?></div></div>
</div>

<div class="section-title"><h2>Turnos por mes</h2></div>
<div class="pedidos-tabla"><table><thead><tr><th>Mes</th><th>Turnos</th><th>Atendidos</th><th>Cancelados</th><th>% cancelados</th><th style="width:34%"></th></tr></thead><tbody>
<?php $maxT = 0; foreach ($meses as $m) $maxT = max($maxT, $m['turnos']);
foreach ($meses as $ym => $m): ?><tr><td><?= h(lux_rep_mes($ym)) ?></td><td><b><?= $m['turnos'] ?></b></td><td><?= $m['aten'] ?></td><td><?= $m['canc'] ?></td>
<td><?= $m['turnos'] ? h(str_replace('.', ',', (string)round($m['canc'] / $m['turnos'] * 100, 1))) . '%' : '—' ?></td>
<td><span class="rep-bar"><i style="width:<?= $maxT ? round($m['turnos'] / $maxT * 100, 1) : 0 ?>%"></i></span></td></tr><?php endforeach; ?>
</tbody></table></div>

<div class="rep-dos">
<div><div class="section-title"><h2>Consultas realizadas por mes</h2></div>
<?php $f = []; foreach ($meses as $ym => $m) $f[] = [lux_rep_mes($ym), $m['consultas'], (string)$m['consultas']]; echo lux_rep_barras($f); ?></div>
<div><div class="section-title"><h2>Ingresos por mes</h2></div>
<?php $f = []; foreach ($meses as $ym => $m) $f[] = [lux_rep_mes($ym), $m['ing'], money($m['ing'])]; echo lux_rep_barras($f); ?></div>
</div>

<div class="rep-dos">
<div><div class="section-title"><h2>Médicos con más consultas</h2></div>
<?php if (!$topMedicos): ?><div class="empty-state">Todavía no hay consultas finalizadas en este período.</div>
<?php else: $f = []; foreach ($topMedicos as $i => $m) $f[] = [($i + 1) . '. ' . $m['nombre'] . ' · ' . $m['esp'], $m['n'], (string)$m['n']]; echo lux_rep_barras($f); endif; ?></div>
<div><div class="section-title"><h2>Consultas por especialidad</h2></div>
<?php if (!$porEsp): ?><div class="empty-state">Todavía no hay consultas finalizadas en este período.</div>
<?php else: $f = []; foreach ($porEsp as $e) $f[] = [$e['nombre'], $e['n'], $e['n'] . ' (' . h(str_replace('.', ',', (string)round($e['n'] / max(1, $tot['consultas']) * 100))) . '%)']; echo lux_rep_barras($f); endif; ?></div>
</div>

<div class="rep-dos">
<div><div class="section-title"><h2>Pacientes nuevos por mes</h2></div>
<?php $f = []; foreach ($meses as $ym => $m) $f[] = [lux_rep_mes($ym), $m['pac'], (string)$m['pac']]; echo lux_rep_barras($f); ?></div>
<div><div class="section-title"><h2>Ingresos por medio de pago</h2></div>
<?php if (!$porPago): ?><div class="empty-state">No hay cobros registrados en este período.</div>
<?php else: $f = []; foreach ($porPago as $p) $f[] = [$p['mp'], (float)$p['t'], money($p['t'])]; echo lux_rep_barras($f); endif; ?></div>
</div>
<?php if ($sinCobrar): ?><div class="venta-aviso">Hay <?= $sinCobrar ?> consulta(s) finalizada(s) sin cobrar: todavía no figuran en los ingresos. <a class="btn-link" href="lux_registrar_venta.php">Ir a cobrar →</a></div><?php endif; ?>
<p class="hc-aviso-conf">Los ingresos son los cobros registrados en el consultorio (incluidas las consultas cobradas). Los turnos se cuentan por la fecha del turno y las consultas por la fecha en que se iniciaron; solo cuentan las finalizadas. Pacientes nuevos = fichas creadas en el período.</p>
<?php lux_footer();
