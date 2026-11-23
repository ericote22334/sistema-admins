<?php
// Consultorio Lux Salud — consultas médicas: pacientes del día, consulta en curso (borrador) y finalización.
// Solo médicos autorizados. Una consulta finalizada ya no se puede modificar ni eliminar.
require __DIR__ . '/lux_layout.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); // datos clínicos: sin caché del navegador
header('Pragma: no-cache');
$u = lux_require(['superadmin', 'admin', 'empleado']);
$med = lux_medico_autorizado($db, $u);
if (!$med) {
    flash('Las consultas médicas son de acceso exclusivo para los médicos del consultorio.', true);
    lux_redirect(lux_home($u['rol']));
}
$base = "u.rol='cliente' AND (p.id IS NOT NULL OR EXISTS (SELECT 1 FROM turnos t0 WHERE t0.cliente_id=u.id AND t0.negocio_id=4))";
$VIG = "('Pendiente','Confirmado')";
$hoy = date('Y-m-d');

function lux_cons_fecha($s, $def) { $s = is_scalar($s) ? (string)$s : ''; $d = DateTime::createFromFormat('Y-m-d', $s); return $d && $d->format('Y-m-d') === $s ? $s : $def; }
function lux_cons_sumar($ymd, $n) { return date('Y-m-d', strtotime($ymd . ' ' . ($n >= 0 ? '+' : '') . $n . ' days')); }
function lux_cons_edad($f) { return $f ? (new DateTime($f))->diff(new DateTime('today'))->y : null; }

// ---------- Acciones ----------
$form = null; $editPost = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = $_POST['accion'] ?? '';

    if ($a === 'iniciar') {
        $pid = (int)($_POST['paciente_id'] ?? 0); $tid = (int)($_POST['turno_id'] ?? 0);
        if (!lux_hc_paciente($db, $pid, $base)) { flash('El paciente no existe.', true); lux_redirect('lux_consultas.php'); }
        $db->beginTransaction();
        $turno = null;
        if ($tid) {
            // solo se puede atender un turno vigente de este paciente con este mismo médico
            $t = $db->prepare("SELECT id FROM turnos WHERE id=? AND cliente_id=? AND medico_id=? AND negocio_id=4 AND estado IN $VIG FOR UPDATE");
            $t->execute([$tid, $pid, $med['id']]);
            $turno = $t->fetchColumn() ?: null;
            if (!$turno) { $db->rollBack(); flash('Ese turno ya no está disponible para atender.', true); lux_redirect('lux_consultas.php'); }
        }
        // si ya hay una consulta en curso para el mismo caso, se retoma en vez de abrir otra
        $ex = $db->prepare("SELECT id FROM consultas_clinicas WHERE usuario_id=? AND medico_id=? AND estado='En curso' AND turno_id <=> ? LIMIT 1 FOR UPDATE");
        $ex->execute([$pid, $med['id'], $turno]);
        $cid = (int)$ex->fetchColumn();
        if (!$cid) {
            $db->prepare("INSERT INTO consultas_clinicas(usuario_id,medico_id,turno_id,fecha,motivo,estado) VALUES(?,?,?,?,'','En curso')")
               ->execute([$pid, $med['id'], $turno, date('Y-m-d H:i:s')]);
            $cid = (int)$db->lastInsertId();
            lux_hc_registrar_acceso($db, $pid, $u['id'], 'consulta_iniciada');
        }
        $db->commit();
        lux_redirect('lux_consultas.php?consulta=' . $cid);
    }

    $cid = (int)($_POST['id'] ?? 0);
    $q = $db->prepare("SELECT id, usuario_id, turno_id FROM consultas_clinicas WHERE id=? AND medico_id=? AND estado='En curso'");
    $q->execute([$cid, $med['id']]); $cons = $q->fetch();
    if (!$cons) { flash('Esa consulta ya fue finalizada o no es tuya.', true); lux_redirect('lux_consultas.php'); }

    if ($a === 'descartar') {
        $db->prepare("DELETE FROM consultas_clinicas WHERE id=? AND medico_id=? AND estado='En curso'")->execute([$cid, $med['id']]);
        lux_hc_registrar_acceso($db, $cons['usuario_id'], $u['id'], 'consulta_descartada');
        flash('Consulta en curso descartada.');
        lux_redirect('lux_consultas.php');
    }

    if ($a === 'guardar' || $a === 'finalizar') {
        $fin = $a === 'finalizar';
        $err = [];
        $d = [
            'motivo' => lux_hc_texto($_POST['motivo'] ?? '', 255, $err, 'El motivo de consulta', $fin),
            'diagnostico' => lux_hc_texto($_POST['diagnostico'] ?? '', 4000, $err, 'El diagnóstico', $fin),
            'tratamiento' => lux_hc_texto($_POST['tratamiento'] ?? '', 4000, $err, 'El tratamiento'),
            'observaciones' => lux_hc_texto($_POST['observaciones'] ?? '', 4000, $err, 'Las observaciones'),
            'turno_id' => (int)($_POST['turno_id'] ?? 0),
        ];
        if (!$err) {
            $db->beginTransaction();
            $turno = null;
            if ($d['turno_id']) {
                // puede quedar el turno con el que se inició, o elegirse otro vigente de este paciente con este médico
                $t = $db->prepare("SELECT id FROM turnos WHERE id=? AND cliente_id=? AND medico_id=? AND negocio_id=4 AND (estado IN $VIG OR id=?) FOR UPDATE");
                $t->execute([$d['turno_id'], $cons['usuario_id'], $med['id'], (int)$cons['turno_id']]);
                $turno = $t->fetchColumn() ?: null;
                if (!$turno) $err[] = 'El turno elegido ya no está disponible.';
            }
            if ($err) $db->rollBack();
            else {
                $set = 'motivo=?, diagnostico=?, tratamiento=?, observaciones=?, turno_id=?';
                $par = [$d['motivo'], $d['diagnostico'] ?: null, $d['tratamiento'] ?: null, $d['observaciones'] ?: null, $turno];
                if ($fin) { $set .= ", estado='Finalizada', finalizada=?"; $par[] = date('Y-m-d H:i:s'); }
                $par[] = $cid; $par[] = $med['id'];
                $up = $db->prepare("UPDATE consultas_clinicas SET $set WHERE id=? AND medico_id=? AND estado='En curso'");
                $up->execute($par);
                if ($fin) {
                    if ($turno) $db->prepare("UPDATE turnos SET estado='Atendido' WHERE id=? AND negocio_id=4 AND estado IN $VIG")->execute([$turno]);
                    lux_hc_registrar_acceso($db, $cons['usuario_id'], $u['id'], 'consulta_finalizada');
                }
                $db->commit();
                flash($fin ? 'Consulta finalizada y guardada en la historia clínica.' . ($turno ? ' El turno quedó marcado como atendido.' : '') : 'Borrador guardado.');
                lux_redirect($fin ? 'lux_consultas.php' : 'lux_consultas.php?consulta=' . $cid);
            }
        }
        flash(implode(' ', $err), true);
        $form = $d; $editPost = $cid;
    } else lux_redirect('lux_consultas.php');
}

$editId = (int)($_GET['consulta'] ?? $editPost);

// ===== Consulta en curso (editor) =====
if ($editId):
    $q = $db->prepare('SELECT c.*, u.nombre paciente FROM consultas_clinicas c JOIN usuarios u ON u.id=c.usuario_id WHERE c.id=?');
    $q->execute([$editId]); $c = $q->fetch();
    if (!$c) { flash('La consulta no existe.', true); lux_redirect('lux_consultas.php'); }
    if ($c['estado'] !== 'En curso' || (int)$c['medico_id'] !== (int)$med['id']) {
        flash('Esa consulta ya está finalizada o es de otro médico. Podés verla en la historia clínica.', true);
        lux_redirect('lux_historias.php?id=' . (int)$c['usuario_id']);
    }
    $pid = (int)$c['usuario_id'];
    $pac = lux_hc_paciente($db, $pid, $base);
    if (!$pac) { flash('El paciente no existe.', true); lux_redirect('lux_consultas.php'); }
    lux_hc_registrar_acceso($db, $pid, $u['id'], 'ver');
    $hc = $db->prepare('SELECT alergias FROM historias_clinicas WHERE usuario_id=?'); $hc->execute([$pid]); $algHc = trim((string)$hc->fetchColumn());
    $ds = $db->prepare('SELECT alergias FROM declaraciones_salud WHERE usuario_id=?'); $ds->execute([$pid]); $algDecl = trim((string)$ds->fetchColumn());
    $ult = $db->prepare("SELECT c.fecha, c.motivo, c.diagnostico, us.nombre medico FROM consultas_clinicas c JOIN medicos m ON m.id=c.medico_id JOIN usuarios us ON us.id=m.usuario_id
        WHERE c.usuario_id=? AND c.estado='Finalizada' ORDER BY c.fecha DESC, c.id DESC LIMIT 3");
    $ult->execute([$pid]); $ultimas = $ult->fetchAll();
    $ts = $db->prepare("SELECT id, fecha FROM turnos WHERE cliente_id=? AND medico_id=? AND negocio_id=4 AND (estado IN $VIG OR id=?) ORDER BY fecha");
    $ts->execute([$pid, $med['id'], (int)$c['turno_id']]); $turnos = $ts->fetchAll();
    $f = $form ?? ['motivo' => (string)$c['motivo'], 'diagnostico' => (string)$c['diagnostico'], 'tratamiento' => (string)$c['tratamiento'], 'observaciones' => (string)$c['observaciones'], 'turno_id' => (int)$c['turno_id']];
    $edad = lux_cons_edad($pac['fecha_nacimiento']);
    lux_header('Consulta en curso', $pac['nombre'] . ($pac['dni'] ? ' — DNI ' . number_format((int)$pac['dni'], 0, ',', '.') : '')); ?>
<div class="hc-resumen"><span><b><?= h($pac['nombre']) ?></b></span>
<?php if ($edad !== null): ?><span><?= (int)$edad ?> años · <?= h($pac['sexo']) ?></span><?php endif; ?>
<?php if ($pac['obra_social']): ?><span><?= h($pac['obra_social']) ?><?= $pac['numero_afiliado'] ? ' · Afiliado ' . h($pac['numero_afiliado']) : '' ?></span><?php endif; ?>
<span>Iniciada <?= h(date('d/m/Y H:i', strtotime($c['fecha']))) ?> hs</span>
<span class="hc-links"><a class="btn-link" href="lux_pacientes.php?id=<?= $pid ?>" target="_blank" rel="noopener">Ficha</a><a class="btn-link" href="lux_historias.php?id=<?= $pid ?>" target="_blank" rel="noopener">Historia completa</a><a class="btn-link" href="lux_recetas.php?nueva=<?= $pid ?>&consulta=<?= (int)$c['id'] ?>" target="_blank" rel="noopener">Recetar</a></span></div>
<?php if ($algHc !== '' || $algDecl !== ''): ?><div class="hc-alerta"><b>⚠ Alergias</b>
<?php if ($algHc !== ''): ?><?= nl2br(h($algHc)) ?><?php endif; ?>
<?php if ($algDecl !== ''): ?><div style="margin-top:<?= $algHc !== '' ? '8' : '0' ?>px"><em>Declaradas por el paciente:</em> <?= nl2br(h($algDecl)) ?></div><?php endif; ?></div>
<?php else: ?><div class="hc-ok">Sin alergias registradas.</div><?php endif; ?>

<?php if ($ultimas): ?><div class="hc-card"><h3>Últimas consultas</h3>
<?php foreach ($ultimas as $x): ?><div class="hc-texto" style="margin-bottom:8px"><b><?= h(date('d/m/Y', strtotime($x['fecha']))) ?></b> · <?= h($x['medico']) ?> — <?= h($x['motivo']) ?>
<div class="hc-meta" style="margin:2px 0 0">Diagnóstico: <?= h(mb_strimwidth((string)$x['diagnostico'], 0, 160, '…')) ?></div></div><?php endforeach; ?></div><?php endif; ?>

<div class="venta-card"><form method="post" class="venta-form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
<div class="field ancho"><label>Motivo de consulta</label><input name="motivo" maxlength="255" value="<?= h($f['motivo']) ?>"></div>
<div class="field ancho"><label>Diagnóstico</label><textarea name="diagnostico" maxlength="4000"><?= h($f['diagnostico']) ?></textarea></div>
<div class="field ancho"><label>Tratamiento indicado</label><textarea name="tratamiento" maxlength="4000" placeholder="Medicación, estudios, indicaciones, seguimiento…"><?= h($f['tratamiento']) ?></textarea></div>
<div class="field ancho"><label>Observaciones</label><textarea name="observaciones" maxlength="4000"><?= h($f['observaciones']) ?></textarea></div>
<div class="field ancho"><label>Turno de esta consulta (se marca como atendido al finalizar)</label><select name="turno_id"><option value="0">Sin turno asociado</option>
<?php foreach ($turnos as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)$f['turno_id'] === (int)$t['id'] ? 'selected' : '' ?>><?= h(date('d/m/Y H:i', strtotime($t['fecha']))) ?> hs</option><?php endforeach; ?></select></div>
<div class="field ancho" style="font-size:12.5px;color:var(--text-muted)">Podés guardar un borrador y seguir después. Al finalizar, la consulta queda en la historia clínica a tu nombre y ya no se puede modificar ni eliminar. El motivo y el diagnóstico son obligatorios para finalizar.</div>
<div class="field ancho acciones"><button class="btn-edit" name="accion" value="guardar">Guardar borrador</button>
<button class="btn-small" name="accion" value="finalizar" onclick="return confirm('¿Finalizar la consulta? Una vez finalizada no se puede modificar.');">Finalizar consulta</button>
<a class="btn-link" href="lux_consultas.php">Volver a mis pacientes</a></div>
</form>
<form method="post" style="margin-top:10px" onsubmit="return confirm('¿Descartar esta consulta en curso? Se pierde lo escrito.');"><?= csrf_field() ?><input type="hidden" name="accion" value="descartar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn-del">Descartar consulta</button></form>
</div>
<?php lux_footer(); exit; endif;

// ===== Pacientes del día =====
$fecha = lux_cons_fecha($_GET['fecha'] ?? '', $hoy);
$ini = $fecha . ' 00:00:00'; $fin = lux_cons_sumar($fecha, 1) . ' 00:00:00';
$st = $db->prepare("SELECT t.id, t.fecha, t.estado, t.cliente_id, u.nombre paciente, p.dni, p.fecha_nacimiento,
    (SELECT c.id FROM consultas_clinicas c WHERE c.turno_id=t.id ORDER BY c.id DESC LIMIT 1) consulta_id,
    (SELECT c.estado FROM consultas_clinicas c WHERE c.turno_id=t.id ORDER BY c.id DESC LIMIT 1) consulta_estado,
    ((hc.alergias IS NOT NULL AND hc.alergias<>'') OR (ds.alergias IS NOT NULL AND ds.alergias<>'')) con_alergias
    FROM turnos t JOIN usuarios u ON u.id=t.cliente_id LEFT JOIN pacientes p ON p.usuario_id=u.id
    LEFT JOIN historias_clinicas hc ON hc.usuario_id=u.id LEFT JOIN declaraciones_salud ds ON ds.usuario_id=u.id
    WHERE t.negocio_id=4 AND t.medico_id=? AND t.estado<>'Cancelado' AND t.fecha>=? AND t.fecha<? ORDER BY t.fecha, t.id");
$st->execute([$med['id'], $ini, $fin]); $turnos = $st->fetchAll();
$atendidos = 0; $porAtender = 0;
foreach ($turnos as $t) { if ($t['estado'] === 'Atendido') $atendidos++; elseif (in_array($t['estado'], ['Pendiente', 'Confirmado'], true)) $porAtender++; }
$ec = $db->prepare("SELECT c.id, c.fecha, u.nombre paciente FROM consultas_clinicas c JOIN usuarios u ON u.id=c.usuario_id WHERE c.medico_id=? AND c.estado='En curso' ORDER BY c.fecha");
$ec->execute([$med['id']]); $enCurso = $ec->fetchAll();
$MESES = [1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'];
$DIAS = lux_dias_semana();
$tsf = strtotime($fecha);
$titulo = $DIAS[(int)date('N', $tsf)] . ' ' . (int)date('j', $tsf) . ' de ' . $MESES[(int)date('n', $tsf)] . ' de ' . date('Y', $tsf);
lux_header('Mis consultas del día', $med['nombre'] . ' — pacientes y consultas'); ?>
<div class="kpi-row" style="grid-template-columns:repeat(3,1fr)">
<div class="kpi"><div class="label">Pacientes del día</div><div class="value"><?= count($turnos) ?></div></div>
<div class="kpi"><div class="label">Por atender</div><div class="value"><?= $porAtender ?></div></div>
<div class="kpi"><div class="label">Atendidos</div><div class="value"><?= $atendidos ?></div></div>
</div>

<?php if ($enCurso): ?>
<div class="section-title" style="margin-top:22px"><h2>Consultas en curso</h2></div>
<table><thead><tr><th>Paciente</th><th>Iniciada</th><th></th></tr></thead><tbody>
<?php foreach ($enCurso as $e): ?><tr><td><?= h($e['paciente']) ?></td><td><?= h(date('d/m/Y H:i', strtotime($e['fecha']))) ?> hs</td>
<td><a class="btn-small" href="lux_consultas.php?consulta=<?= (int)$e['id'] ?>">Continuar</a></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>

<div class="cal-bar"><div class="cal-nav"><a href="lux_consultas.php?fecha=<?= h(lux_cons_sumar($fecha, -1)) ?>" aria-label="Día anterior">←</a><a href="lux_consultas.php">Hoy</a><a href="lux_consultas.php?fecha=<?= h(lux_cons_sumar($fecha, 1)) ?>" aria-label="Día siguiente">→</a></div>
<a class="btn-link" href="lux_historias.php" style="margin-left:auto">Atender a un paciente sin turno →</a></div>
<h2 class="cal-titulo"><?= h($titulo) ?></h2>
<?php if (!$turnos): ?><div class="empty-state">No tenés turnos para este día.</div>
<?php else: ?>
<div class="pedidos-tabla"><table><thead><tr><th>Hora</th><th>Paciente</th><th>Alergias</th><th>Turno</th><th>Consulta</th><th>Acciones</th></tr></thead><tbody>
<?php foreach ($turnos as $t): $edad = lux_cons_edad($t['fecha_nacimiento']); ?><tr>
<td><b><?= h(substr($t['fecha'], 11, 5)) ?></b></td>
<td><?= h($t['paciente']) ?><div style="font-size:12px;color:var(--text-muted)"><?= $edad !== null ? (int)$edad . ' años' : '' ?><?= $t['dni'] ? ($edad !== null ? ' · ' : '') . 'DNI ' . h(number_format((int)$t['dni'], 0, ',', '.')) : '' ?></div></td>
<td><?= $t['con_alergias'] ? '<span class="status-pill low">⚠ Con alergias</span>' : '—' ?></td>
<td><?= $t['estado'] === 'Atendido' ? '<span class="status-pill ok">Atendido</span>' : ($t['estado'] === 'Confirmado' ? '<span class="status-pill confirmado">Confirmado</span>' : '<span class="status-pill pendiente">Pendiente</span>') ?></td>
<td><?= $t['consulta_estado'] === 'En curso' ? '<span class="status-pill pendiente">En curso</span>' : ($t['consulta_estado'] === 'Finalizada' ? '<span class="status-pill ok">Finalizada</span>' : '—') ?></td>
<td><div class="acciones">
<a class="btn-link" href="lux_pacientes.php?id=<?= (int)$t['cliente_id'] ?>">Ficha</a>
<a class="btn-link" href="lux_historias.php?id=<?= (int)$t['cliente_id'] ?>">Historia</a>
<?php if ($t['consulta_estado'] === 'En curso'): ?><a class="btn-small" href="lux_consultas.php?consulta=<?= (int)$t['consulta_id'] ?>">Continuar</a>
<?php elseif (!$t['consulta_estado'] && in_array($t['estado'], ['Pendiente', 'Confirmado'], true)): ?>
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="accion" value="iniciar"><input type="hidden" name="paciente_id" value="<?= (int)$t['cliente_id'] ?>"><input type="hidden" name="turno_id" value="<?= (int)$t['id'] ?>"><button class="btn-small">Iniciar consulta</button></form>
<?php endif; ?></div></td>
</tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<p class="hc-aviso-conf">Información confidencial. Cada vez que se abre una historia clínica o se inicia o finaliza una consulta queda registrado quién y cuándo.</p>
<?php lux_footer();
