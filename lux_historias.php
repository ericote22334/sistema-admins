<?php
// Consultorio Lux Salud — historias clínicas. Acceso exclusivo para los médicos activos del consultorio;
// cada apertura y cada cambio queda registrado y las consultas guardadas no se pueden modificar ni borrar.
require __DIR__ . '/lux_layout.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); // los datos clínicos no se guardan en la caché del navegador
header('Pragma: no-cache');
$u = lux_require(['superadmin', 'admin', 'empleado']);
$med = lux_medico_autorizado($db, $u);
if (!$med) {
    flash('Las historias clínicas son de acceso exclusivo para los médicos del consultorio.', true);
    lux_redirect(lux_home($u['rol']));
}
$base = "u.rol='cliente' AND (p.id IS NOT NULL OR EXISTS (SELECT 1 FROM turnos t0 WHERE t0.cliente_id=u.id AND t0.negocio_id=4))";
$VIGENTES = "('Pendiente','Confirmado')";

function lux_hc_fila($t) { return $t === '' || $t === null ? '<span class="hc-vacio">Sin datos registrados</span>' : nl2br(h($t)); }

// ---------- Guardar ----------
$form = null; $modoForm = null; $pidPost = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pidPost = (int)($_POST['id'] ?? 0);
    $pac = lux_hc_paciente($db, $pidPost, $base);
    if (!$pac) { flash('El paciente no existe.', true); lux_redirect('lux_historias.php'); }
    $a = $_POST['accion'] ?? '';
    $err = [];
    if ($a === 'antecedentes') {
        $d = [
            'alergias' => lux_hc_texto($_POST['alergias'] ?? '', 4000, $err, 'Las alergias'),
            'antecedentes' => lux_hc_texto($_POST['antecedentes'] ?? '', 4000, $err, 'Los antecedentes médicos'),
            'enfermedades_previas' => lux_hc_texto($_POST['enfermedades_previas'] ?? '', 4000, $err, 'Las enfermedades previas'),
            'observaciones' => lux_hc_texto($_POST['observaciones'] ?? '', 4000, $err, 'Las observaciones'),
        ];
        $version = is_scalar($_POST['v'] ?? null) ? (string)$_POST['v'] : '';
        if (!$err) {
            try {
            $db->beginTransaction();
            $cur = $db->prepare('SELECT actualizado FROM historias_clinicas WHERE usuario_id=? FOR UPDATE');
            $cur->execute([$pidPost]); $cur = $cur->fetch();
            if ($cur && (string)$cur['actualizado'] !== $version) {
                $db->rollBack();
                $err[] = 'Otro profesional modificó los antecedentes mientras los editabas. Revisá lo que quedó guardado y volvé a editar.';
                $form = null; $modoForm = null;
                flash(implode(' ', $err), true);
                lux_redirect('lux_historias.php?id=' . $pidPost);
            }
            $ahora = date('Y-m-d H:i:s');
            if ($cur) {
                $db->prepare('UPDATE historias_clinicas SET antecedentes=?,alergias=?,enfermedades_previas=?,observaciones=?,actualizado=?,actualizado_por=? WHERE usuario_id=?')
                   ->execute([$d['antecedentes'] ?: null, $d['alergias'] ?: null, $d['enfermedades_previas'] ?: null, $d['observaciones'] ?: null, $ahora, $u['id'], $pidPost]);
            } else {
                $db->prepare('INSERT INTO historias_clinicas(usuario_id,antecedentes,alergias,enfermedades_previas,observaciones,actualizado,actualizado_por) VALUES(?,?,?,?,?,?,?)')
                   ->execute([$pidPost, $d['antecedentes'] ?: null, $d['alergias'] ?: null, $d['enfermedades_previas'] ?: null, $d['observaciones'] ?: null, $ahora, $u['id']]);
            }
            lux_hc_registrar_acceso($db, $pidPost, $u['id'], 'editar_antecedentes');
            $db->commit();
            } catch (PDOException $e) {
                if ($db->inTransaction()) $db->rollBack();
                if ($e->getCode() !== '23000') throw $e;
                flash('Otro profesional creó los antecedentes al mismo tiempo. Revisá lo que quedó guardado y volvé a editar.', true);
                lux_redirect('lux_historias.php?id=' . $pidPost);
            }
            flash('Antecedentes actualizados.');
            lux_redirect('lux_historias.php?id=' . $pidPost);
        }
        flash(implode(' ', $err), true);
        $form = $d + ['v' => $version]; $modoForm = 'antecedentes';
    } else lux_redirect('lux_historias.php');
}

$verId = (int)($_GET['id'] ?? $pidPost);

// ===== Detalle de la historia de un paciente =====
if ($verId):
    $pac = lux_hc_paciente($db, $verId, $base);
    if (!$pac) { flash('El paciente no existe.', true); lux_redirect('lux_historias.php'); }
    lux_hc_registrar_acceso($db, $verId, $u['id'], 'ver');
    $hc = $db->prepare('SELECT hc.*, us.nombre autor FROM historias_clinicas hc LEFT JOIN usuarios us ON us.id=hc.actualizado_por WHERE hc.usuario_id=?');
    $hc->execute([$verId]); $hc = $hc->fetch() ?: null;
    $decl = $db->prepare('SELECT alergias, enfermedades_previas, medicacion, antecedentes, actualizado FROM declaraciones_salud WHERE usuario_id=?');
    $decl->execute([$verId]); $decl = $decl->fetch() ?: null;
    $declTiene = $decl && trim($decl['alergias'] . $decl['enfermedades_previas'] . $decl['medicacion'] . $decl['antecedentes']) !== '';
    $modo = $modoForm ?? (($_GET['editar'] ?? '') === 'antecedentes' ? 'antecedentes' : 'historia');
    $edad = $pac['fecha_nacimiento'] ? (new DateTime($pac['fecha_nacimiento']))->diff(new DateTime('today'))->y : null;
    $sub = $pac['nombre'] . ($pac['dni'] ? ' — DNI ' . number_format((int)$pac['dni'], 0, ',', '.') : '');

    if ($modo === 'antecedentes'):
        $f = $form ?? ['alergias' => (string)($hc['alergias'] ?? ''), 'antecedentes' => (string)($hc['antecedentes'] ?? ''),
            'enfermedades_previas' => (string)($hc['enfermedades_previas'] ?? ''), 'observaciones' => (string)($hc['observaciones'] ?? ''), 'v' => (string)($hc['actualizado'] ?? '')];
        $prefilled = false;
        if ($form === null && ($_GET['desde'] ?? '') === 'declaracion' && $declTiene) {
            $unir = function ($actual, $nuevo, $rotulo = '') {
                $nuevo = trim((string)$nuevo);
                if ($nuevo === '') return $actual;
                if ($rotulo !== '') $nuevo = $rotulo . ': ' . $nuevo;
                if ($actual !== '' && mb_strpos($actual, $nuevo) !== false) return $actual;
                return $actual === '' ? $nuevo : $actual . "\n" . $nuevo;
            };
            $f['alergias'] = $unir($f['alergias'], $decl['alergias']);
            $f['enfermedades_previas'] = $unir($f['enfermedades_previas'], $decl['enfermedades_previas']);
            $f['antecedentes'] = $unir($unir($f['antecedentes'], $decl['antecedentes']), $decl['medicacion'], 'Medicación actual (declarada por el paciente)');
            $prefilled = true;
        }
        lux_header('Editar antecedentes', $sub); ?>
<div class="venta-card"><form method="post" class="venta-form" autocomplete="off"><?= csrf_field() ?>
<input type="hidden" name="accion" value="antecedentes"><input type="hidden" name="id" value="<?= (int)$verId ?>"><input type="hidden" name="v" value="<?= h($f['v']) ?>">
<?php if ($prefilled): ?><div class="field ancho" style="font-size:13px;color:var(--accent)">Se copió lo que declaró el paciente. Revisalo y editalo antes de guardar: todavía no se guardó nada.</div><?php endif; ?>
<div class="field ancho"><label>Alergias</label><textarea name="alergias" maxlength="4000" placeholder="Medicamentos, alimentos, látex… (dejalo vacío si no tiene)"><?= h($f['alergias']) ?></textarea></div>
<div class="field ancho"><label>Antecedentes médicos</label><textarea name="antecedentes" maxlength="4000" placeholder="Cirugías, internaciones, antecedentes familiares, hábitos…"><?= h($f['antecedentes']) ?></textarea></div>
<div class="field ancho"><label>Enfermedades previas</label><textarea name="enfermedades_previas" maxlength="4000" placeholder="Enfermedades crónicas o pasadas relevantes"><?= h($f['enfermedades_previas']) ?></textarea></div>
<div class="field ancho"><label>Observaciones generales</label><textarea name="observaciones" maxlength="4000"><?= h($f['observaciones']) ?></textarea></div>
<div class="field ancho acciones"><button class="btn-small">Guardar antecedentes</button><a class="btn-link" href="lux_historias.php?id=<?= (int)$verId ?>">Cancelar</a></div>
</form></div>
<?php lux_footer(); exit; endif;


    // ---- Historia completa ----
    $cs = $db->prepare('SELECT c.*, us.nombre medico, e.nombre esp FROM consultas_clinicas c JOIN medicos m ON m.id=c.medico_id JOIN usuarios us ON us.id=m.usuario_id
        JOIN especialidades e ON e.id=m.especialidad_id WHERE c.usuario_id=? ORDER BY c.fecha DESC, c.id DESC LIMIT 100');
    $cs->execute([$verId]); $consultas = $cs->fetchAll();
    $at = $db->prepare("SELECT t.fecha, t.profesional FROM turnos t WHERE t.cliente_id=? AND t.negocio_id=4 AND t.estado='Atendido' ORDER BY t.fecha DESC LIMIT 10");
    $at->execute([$verId]); $atendidos = $at->fetchAll();
    $rx = $db->prepare("SELECT r.id, r.fecha_emision, r.estado, us.nombre medico, (SELECT GROUP_CONCAT(i.medicamento ORDER BY i.orden SEPARATOR ', ') FROM receta_items i WHERE i.receta_id=r.id) meds
        FROM recetas r JOIN medicos m ON m.id=r.medico_id JOIN usuarios us ON us.id=m.usuario_id WHERE r.usuario_id=? ORDER BY r.fecha_emision DESC, r.id DESC LIMIT 10");
    $rx->execute([$verId]); $recetas = $rx->fetchAll();
    $alergias = trim((string)($hc['alergias'] ?? ''));
    lux_header('Historia clínica', $sub); ?>
<div class="hc-resumen"><span><b><?= h($pac['nombre']) ?></b></span>
<?php if ($pac['dni']): ?><span>DNI <?= h(number_format((int)$pac['dni'], 0, ',', '.')) ?></span><?php endif; ?>
<?php if ($edad !== null): ?><span><?= (int)$edad ?> años · <?= h($pac['sexo']) ?></span><?php endif; ?>
<?php if ($pac['obra_social']): ?><span><?= h($pac['obra_social']) ?><?= $pac['numero_afiliado'] ? ' · Afiliado ' . h($pac['numero_afiliado']) : '' ?></span><?php endif; ?>
<span class="hc-links"><a class="btn-link" href="lux_pacientes.php?id=<?= (int)$verId ?>">Ver ficha</a><a class="btn-link" href="lux_historias.php">← Historias clínicas</a></span></div>
<?php $algDecl = trim((string)($decl['alergias'] ?? '')); if ($alergias !== '' || $algDecl !== ''): ?><div class="hc-alerta"><b>⚠ Alergias</b>
<?php if ($alergias !== ''): ?><?= nl2br(h($alergias)) ?><?php endif; ?>
<?php if ($algDecl !== ''): ?><div style="margin-top:<?= $alergias !== '' ? '8' : '0' ?>px"><em>Declaradas por el paciente:</em> <?= nl2br(h($algDecl)) ?></div><?php endif; ?></div>
<?php else: ?><div class="hc-ok">Sin alergias registradas.</div><?php endif; ?>

<div class="hc-card"><div class="section-title" style="margin:0 0 12px"><h3 style="margin:0">Antecedentes</h3><a class="btn-edit" href="lux_historias.php?id=<?= (int)$verId ?>&editar=antecedentes">Editar</a></div>
<div class="hc-grid">
<div class="hc-bloque"><h4>Antecedentes médicos</h4><div class="hc-texto"><?= lux_hc_fila($hc['antecedentes'] ?? '') ?></div></div>
<div class="hc-bloque"><h4>Enfermedades previas</h4><div class="hc-texto"><?= lux_hc_fila($hc['enfermedades_previas'] ?? '') ?></div></div>
<div class="hc-bloque"><h4>Observaciones generales</h4><div class="hc-texto"><?= lux_hc_fila($hc['observaciones'] ?? '') ?></div></div>
</div>
<?php if ($hc): ?><div class="hc-meta">Última actualización: <?= h(date('d/m/Y H:i', strtotime($hc['actualizado']))) ?><?= $hc['autor'] ? ' · ' . h($hc['autor']) : '' ?></div><?php endif; ?>
</div>

<?php if ($declTiene): ?>
<div class="hc-card"><div class="section-title" style="margin:0 0 12px"><h3 style="margin:0">Declarado por el paciente</h3><a class="btn-edit" href="lux_historias.php?id=<?= (int)$verId ?>&editar=antecedentes&desde=declaracion">Pasar a antecedentes</a></div>
<div class="hc-grid" style="grid-template-columns:repeat(2,minmax(0,1fr))">
<div class="hc-bloque"><h4>Alergias</h4><div class="hc-texto"><?= lux_hc_fila($decl['alergias']) ?></div></div>
<div class="hc-bloque"><h4>Enfermedades previas</h4><div class="hc-texto"><?= lux_hc_fila($decl['enfermedades_previas']) ?></div></div>
<div class="hc-bloque"><h4>Medicación actual</h4><div class="hc-texto"><?= lux_hc_fila($decl['medicacion']) ?></div></div>
<div class="hc-bloque"><h4>Otros antecedentes</h4><div class="hc-texto"><?= lux_hc_fila($decl['antecedentes']) ?></div></div>
</div>
<div class="hc-meta">Lo escribió el propio paciente (actualizado el <?= h(date('d/m/Y H:i', strtotime($decl['actualizado']))) ?>). Es información no verificada: revisala con el paciente antes de incorporarla a los antecedentes.</div>
</div>
<?php endif; ?>

<div class="section-title"><h2>Consultas</h2><form method="post" action="lux_consultas.php" class="inline"><?= csrf_field() ?><input type="hidden" name="accion" value="iniciar"><input type="hidden" name="paciente_id" value="<?= (int)$verId ?>"><button class="btn-small">+ Nueva consulta</button></form></div>
<?php if (!$consultas): ?><div class="empty-state">Todavía no hay consultas registradas para este paciente.</div>
<?php else: foreach ($consultas as $c): if ($c['estado'] === 'En curso'): ?>
<div class="hc-card hc-consulta"><div class="hc-cab"><b>Consulta en curso</b><span><?= h($c['medico']) ?> · <?= h($c['esp']) ?> · iniciada <?= h(date('d/m/Y H:i', strtotime($c['fecha']))) ?> hs</span></div>
<?php if ((int)$c['medico_id'] === (int)$med['id']): ?><a class="btn-small" href="lux_consultas.php?consulta=<?= (int)$c['id'] ?>">Continuar consulta →</a>
<?php else: ?><div class="hc-texto hc-vacio">Todavía no fue finalizada por el médico.</div><?php endif; ?></div>
<?php continue; endif; ?>
<div class="hc-card hc-consulta"><div class="hc-cab"><b><?= h(date('d/m/Y H:i', strtotime($c['fecha']))) ?> hs</b><span><?= h($c['medico']) ?> · <?= h($c['esp']) ?></span></div>
<div class="hc-bloque"><h4>Motivo de consulta</h4><div class="hc-texto"><?= nl2br(h($c['motivo'])) ?></div></div>
<div class="hc-bloque"><h4>Diagnóstico</h4><div class="hc-texto"><?= nl2br(h($c['diagnostico'])) ?></div></div>
<div class="hc-bloque"><h4>Tratamiento</h4><div class="hc-texto"><?= lux_hc_fila($c['tratamiento']) ?></div></div>
<?php if ($c['observaciones'] !== null && $c['observaciones'] !== ''): ?><div class="hc-bloque"><h4>Observaciones</h4><div class="hc-texto"><?= nl2br(h($c['observaciones'])) ?></div></div><?php endif; ?>
</div>
<?php endforeach; endif; ?>

<div class="section-title" style="margin-top:26px"><h2>Recetas</h2><a class="btn-small" href="lux_recetas.php?nueva=<?= (int)$verId ?>">+ Nueva receta</a></div>
<?php if (!$recetas): ?><div class="empty-state">Todavía no se emitieron recetas para este paciente.</div>
<?php else: ?><table><thead><tr><th>Receta</th><th>Fecha</th><th>Médico</th><th>Medicamentos</th><th>Estado</th><th></th></tr></thead><tbody>
<?php foreach ($recetas as $r): ?><tr><td><?= h(lux_receta_codigo($r['id'])) ?></td><td><?= h(date('d/m/Y', strtotime($r['fecha_emision']))) ?></td><td><?= h($r['medico']) ?></td><td><?= h($r['meds']) ?></td>
<td><?= $r['estado'] === 'Anulada' ? '<span class="status-pill low">Anulada</span>' : '<span class="status-pill ok">Emitida</span>' ?></td>
<td><a class="btn-detalle" href="lux_receta.php?id=<?= (int)$r['id'] ?>">Ver / imprimir</a></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?>

<?php if ($atendidos): ?>
<div class="section-title" style="margin-top:26px"><h2>Turnos atendidos</h2></div>
<table><thead><tr><th>Fecha</th><th>Profesional</th></tr></thead><tbody>
<?php foreach ($atendidos as $t): ?><tr><td><?= h(date('d/m/Y H:i', strtotime($t['fecha']))) ?> hs</td><td><?= h($t['profesional']) ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>
<p class="hc-aviso-conf">Información confidencial. Cada vez que se abre o se modifica una historia clínica queda registrado quién y cuándo.</p>
<?php lux_footer(); exit; endif;

// ===== Listado de pacientes =====
$q = trim($_GET['q'] ?? '');
$where = $base; $par = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '\\%_') . '%';
    $cond = 'u.nombre LIKE ?'; $par = [$like];
    $dig = preg_replace('/\D/', '', $q);
    if (strlen($dig) >= 3) { $cond .= ' OR p.dni LIKE ?'; $par[] = '%' . $dig . '%'; }
    $where .= " AND ($cond)";
}
$porPag = 20;
$cnt = $db->prepare("SELECT COUNT(*) FROM usuarios u LEFT JOIN pacientes p ON p.usuario_id=u.id WHERE $where"); $cnt->execute($par);
$total = (int)$cnt->fetchColumn();
$paginas = max(1, (int)ceil($total / $porPag));
$pag = min($paginas, max(1, (int)($_GET['pag'] ?? 1)));
$off = ($pag - 1) * $porPag;
$st = $db->prepare("SELECT u.id, u.nombre, p.dni, p.fecha_nacimiento, ((hc.alergias IS NOT NULL AND hc.alergias<>'') OR (ds.alergias IS NOT NULL AND ds.alergias<>'')) con_alergias,
    (SELECT COUNT(*) FROM consultas_clinicas c WHERE c.usuario_id=u.id AND c.estado='Finalizada') consultas, (SELECT MAX(c.fecha) FROM consultas_clinicas c WHERE c.usuario_id=u.id AND c.estado='Finalizada') ultima
    FROM usuarios u LEFT JOIN pacientes p ON p.usuario_id=u.id LEFT JOIN historias_clinicas hc ON hc.usuario_id=u.id LEFT JOIN declaraciones_salud ds ON ds.usuario_id=u.id
    WHERE $where ORDER BY u.nombre, u.id LIMIT $porPag OFFSET $off");
$st->execute($par); $lista = $st->fetchAll();
$link = function ($p) use ($q) {
    $a = array_filter(['q' => $q, 'pag' => $p > 1 ? $p : ''], function ($x) { return $x !== ''; });
    return 'lux_historias.php' . ($a ? '?' . h(http_build_query($a)) : '');
};
lux_header('Historias clínicas', 'Acceso exclusivo para médicos — ' . $med['nombre']); ?>
<form class="pedidos-filtros" method="get">
<input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar paciente por nombre o DNI...">
<button class="btn-small">Buscar</button>
<?php if ($q !== ''): ?><a class="btn-link" href="lux_historias.php" style="align-self:center">Limpiar ✕</a><?php endif; ?>
</form>
<?php if (!$lista): ?><div class="empty-state"><?= $q !== '' ? 'No hay pacientes que coincidan con la búsqueda.' : 'Todavía no hay pacientes registrados.' ?></div>
<?php else: ?>
<div class="pedidos-tabla"><table><thead><tr><th>Paciente</th><th>DNI</th><th>Edad</th><th>Alergias</th><th>Consultas</th><th>Última consulta</th><th></th></tr></thead><tbody>
<?php foreach ($lista as $p): ?><tr>
<td><a class="btn-link" href="lux_historias.php?id=<?= (int)$p['id'] ?>"><?= h($p['nombre']) ?></a></td>
<td><?= $p['dni'] ? h(number_format((int)$p['dni'], 0, ',', '.')) : '—' ?></td>
<td><?= $p['fecha_nacimiento'] ? (new DateTime($p['fecha_nacimiento']))->diff(new DateTime('today'))->y : '—' ?></td>
<td><?= $p['con_alergias'] ? '<span class="status-pill low">⚠ Con alergias</span>' : '—' ?></td>
<td><?= (int)$p['consultas'] ?></td>
<td><?= $p['ultima'] ? h(date('d/m/Y', strtotime($p['ultima']))) : '—' ?></td>
<td><a class="btn-detalle" href="lux_historias.php?id=<?= (int)$p['id'] ?>">Abrir historia</a></td>
</tr><?php endforeach; ?>
</tbody></table></div>
<?php if ($paginas > 1): ?>
<div class="hist-pag"><?php if ($pag > 1): ?><a class="btn-detalle" href="<?= $link($pag - 1) ?>">← Anterior</a><?php endif; ?>
<span>Página <?= $pag ?> de <?= $paginas ?></span>
<?php if ($pag < $paginas): ?><a class="btn-detalle" href="<?= $link($pag + 1) ?>">Siguiente →</a><?php endif; ?></div>
<?php endif; endif; ?>
<p class="hc-aviso-conf">Información confidencial. Cada vez que se abre o se modifica una historia clínica queda registrado quién y cuándo.</p>
<?php lux_footer();
