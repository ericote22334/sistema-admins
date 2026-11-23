<?php
// Receta médica lista para imprimir. La puede abrir un médico autorizado o el propio paciente.
require __DIR__ . '/lux_layout.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$u = lux_require(['superadmin', 'admin', 'empleado', 'cliente']);
$med = lux_medico_autorizado($db, $u);
$id = (int)($_GET['id'] ?? 0);
$q = $db->prepare("SELECT r.*, pu.nombre paciente, p.dni, p.fecha_nacimiento, p.sexo, p.obra_social, p.numero_afiliado,
    mu.nombre medico, m.matricula, e.nombre esp
    FROM recetas r JOIN usuarios pu ON pu.id=r.usuario_id LEFT JOIN pacientes p ON p.usuario_id=pu.id
    JOIN medicos m ON m.id=r.medico_id JOIN usuarios mu ON mu.id=m.usuario_id JOIN especialidades e ON e.id=m.especialidad_id WHERE r.id=?");
$q->execute([$id]); $r = $q->fetch();
$esPaciente = $r && $u['rol'] === 'cliente' && (int)$r['usuario_id'] === (int)$u['id'];
if (!$r || (!$med && !$esPaciente)) {
    flash('No encontramos esa receta.', true);
    lux_redirect($u['rol'] === 'cliente' ? 'lux_mis_recetas.php' : lux_home($u['rol']));
}
if ($med) lux_hc_registrar_acceso($db, $r['usuario_id'], $u['id'], 'ver');
$it = $db->prepare('SELECT medicamento, dosis, frecuencia, duracion, indicaciones FROM receta_items WHERE receta_id=? ORDER BY orden, id');
$it->execute([$id]); $items = $it->fetchAll();
$volver = $esPaciente ? 'lux_mis_recetas.php' : 'lux_recetas.php';
$anulada = $r['estado'] === 'Anulada';
$esAutor = $med && (int)$med['id'] === (int)$r['medico_id'];
$edad = $r['fecha_nacimiento'] ? (new DateTime($r['fecha_nacimiento']))->diff(new DateTime('today'))->y : null;
$codigo = lux_receta_codigo($r['id']);
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receta <?= h($codigo) ?> — Consultorio Lux Salud</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#EFEDE8;color:#161616;font-family:Georgia,'Times New Roman',serif;line-height:1.45}
.barra{max-width:780px;margin:18px auto 0;padding:0 12px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;font-size:14px}
.barra a{color:#1D1D1D}
.barra button{background:#1D1D1D;color:#fff;border:0;border-radius:8px;padding:9px 18px;font-size:14px;cursor:pointer;margin-left:auto}
.anular{max-width:780px;margin:10px auto 0;padding:0 12px;font-family:system-ui,sans-serif;font-size:13px}
.anular form{display:flex;flex-wrap:wrap;gap:8px}
.anular input{flex:1;min-width:220px;padding:8px 10px;border:1px solid #bbb;border-radius:8px;font-size:13px}
.anular button{background:#A6402A;color:#fff;border:0;border-radius:8px;padding:8px 14px;font-size:13px;cursor:pointer}
.aviso{max-width:780px;margin:10px auto 0;padding:10px 14px;border-radius:8px;font-family:system-ui,sans-serif;font-size:13.5px;background:#FBEAE6;color:#8A3A28}
.receta{position:relative;max-width:780px;margin:14px auto 40px;background:#fff;border:1px solid #cfcfcf;padding:42px 50px 36px;overflow:hidden}
.cab{display:flex;justify-content:space-between;gap:20px;border-bottom:2px solid #161616;padding-bottom:14px;margin-bottom:18px}
.cab h1{margin:0;font-size:24px;letter-spacing:.02em}
.cab small{display:block;font-size:12.5px;color:#555;font-family:system-ui,sans-serif}
.num{text-align:right;font-family:system-ui,sans-serif;font-size:13px}
.num b{display:block;font-size:18px;font-family:Georgia,serif}
.datos{display:grid;grid-template-columns:1fr 1fr;gap:6px 28px;font-size:14px;margin-bottom:20px}
.datos h2{grid-column:1/-1;margin:4px 0 0;font-size:11.5px;letter-spacing:.08em;text-transform:uppercase;color:#666;font-family:system-ui,sans-serif}
.datos div span{color:#666;font-size:12.5px;font-family:system-ui,sans-serif}
.rp{font-size:34px;font-style:italic;margin:6px 0 8px}
.item{padding:10px 0;border-bottom:1px dotted #aaa}
.item:last-child{border-bottom:0}
.item b{font-size:16.5px}
.item .det{font-size:14px;color:#222;margin-top:2px}
.item .ind{font-size:13.5px;color:#444;margin-top:2px;font-style:italic}
.gen{margin-top:16px;font-size:14px}
.gen h3{margin:0 0 4px;font-size:11.5px;letter-spacing:.08em;text-transform:uppercase;color:#666;font-family:system-ui,sans-serif}
.firma{margin-top:64px;display:flex;justify-content:flex-end}
.firma div{width:260px;border-top:1px solid #161616;padding-top:6px;text-align:center;font-size:13.5px}
.firma small{display:block;color:#555;font-family:system-ui,sans-serif;font-size:12px}
.pie{margin-top:26px;border-top:1px solid #ddd;padding-top:8px;font-size:11.5px;color:#666;font-family:system-ui,sans-serif}
.marca{position:absolute;top:42%;left:50%;transform:translate(-50%,-50%) rotate(-24deg);font-size:110px;font-weight:700;color:rgba(166,64,42,.16);border:10px solid rgba(166,64,42,.16);padding:0 28px;pointer-events:none;font-family:system-ui,sans-serif}
@media (max-width:640px){.receta{padding:24px 20px}.datos{grid-template-columns:1fr}.marca{font-size:64px}}
@media print{body{background:#fff}.barra,.anular,.aviso,.no-print{display:none!important}.receta{border:0;margin:0;padding:0;max-width:none}}
</style></head><body>
<div class="barra"><a href="<?= h($volver) ?>">← Volver</a><button type="button" onclick="window.print()">Imprimir</button></div>
<?php if ($anulada): ?><div class="aviso">Esta receta está anulada<?= $r['anulada_fecha'] ? ' desde el ' . h(date('d/m/Y H:i', strtotime($r['anulada_fecha']))) : '' ?>. Motivo: <?= h($r['anulada_motivo']) ?>. No es válida.</div>
<?php elseif ($esAutor): ?><div class="anular"><form method="post" action="lux_recetas.php" onsubmit="return confirm('¿Anular la receta? No se puede deshacer.');"><?= csrf_field() ?>
<input type="hidden" name="accion" value="anular"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input name="motivo" maxlength="255" required placeholder="Motivo para anular esta receta (ej. error en la dosis)"><button>Anular receta</button></form></div><?php endif; ?>
<main class="receta">
<?php if ($anulada): ?><div class="marca">ANULADA</div><?php endif; ?>
<div class="cab"><div><h1>Consultorio Lux Salud</h1><small>Grupo Lux</small></div>
<div class="num"><b><?= h($codigo) ?></b>Emitida el <?= h(date('d/m/Y', strtotime($r['fecha_emision']))) ?> a las <?= h(date('H:i', strtotime($r['fecha_emision']))) ?> hs</div></div>
<div class="datos">
<h2>Paciente</h2>
<div><span>Nombre</span><br><?= h($r['paciente']) ?></div>
<div><span>DNI</span><br><?= $r['dni'] ? h(number_format((int)$r['dni'], 0, ',', '.')) : '—' ?><?= $edad !== null ? ' · ' . (int)$edad . ' años' : '' ?></div>
<div><span>Obra social</span><br><?= $r['obra_social'] ? h($r['obra_social']) : 'Sin obra social' ?></div>
<div><span>N.º de afiliado</span><br><?= $r['numero_afiliado'] ? h($r['numero_afiliado']) : '—' ?></div>
<h2>Médico</h2>
<div><span>Profesional</span><br><?= h($r['medico']) ?></div>
<div><span>Matrícula · Especialidad</span><br><?= h($r['matricula']) ?> · <?= h($r['esp']) ?></div>
</div>
<div class="rp">Rp/</div>
<?php foreach ($items as $i => $x): ?><div class="item"><b><?= ($i + 1) ?>. <?= h($x['medicamento']) ?></b>
<div class="det">Dosis: <?= h($x['dosis']) ?><?= $x['frecuencia'] ? ' — ' . h($x['frecuencia']) : '' ?><?= $x['duracion'] ? ' — durante ' . h($x['duracion']) : '' ?></div>
<?php if ($x['indicaciones']): ?><div class="ind"><?= h($x['indicaciones']) ?></div><?php endif; ?></div><?php endforeach; ?>
<?php if ($r['indicaciones']): ?><div class="gen"><h3>Indicaciones generales</h3><?= nl2br(h($r['indicaciones'])) ?></div><?php endif; ?>
<div class="firma"><div><?= h($r['medico']) ?><small>Matrícula <?= h($r['matricula']) ?> · Firma y sello</small></div></div>
<div class="pie">Receta emitida el <?= h(date('d/m/Y H:i', strtotime($r['fecha_emision']))) ?> hs · <?= h($codigo) ?>. Documento confidencial.</div>
</main></body></html>
