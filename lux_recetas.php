<?php
// Consultorio Lux Salud — recetas médicas: emitir, listar y anular. Solo médicos autorizados.
// Una receta emitida no se edita: si hay un error se anula y se emite otra.
require __DIR__ . '/lux_layout.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); // datos clínicos: sin caché del navegador
header('Pragma: no-cache');
$u = lux_require(['superadmin', 'admin', 'empleado']);
$med = lux_medico_autorizado($db, $u);
if (!$med) {
    flash('Las recetas son de acceso exclusivo para los médicos del consultorio.', true);
    lux_redirect(lux_home($u['rol']));
}
$base = "u.rol='cliente' AND (p.id IS NOT NULL OR EXISTS (SELECT 1 FROM turnos t0 WHERE t0.cliente_id=u.id AND t0.negocio_id=4))";
const LUX_RX_MAX = 12;

// Lee las líneas de medicamentos del formulario. Devuelve [todas las filas (para volver a mostrarlas), filas válidas]
function lux_rx_items(array $post, array &$err) {
    $col = function ($k) use ($post) { return isset($post[$k]) && is_array($post[$k]) ? array_values($post[$k]) : []; };
    $c = ['med' => $col('med'), 'dosis' => $col('dosis'), 'frec' => $col('frec'), 'dur' => $col('dur'), 'ind' => $col('ind')];
    $max = ['med' => 150, 'dosis' => 100, 'frec' => 100, 'dur' => 100, 'ind' => 255];
    $nombre = ['med' => 'el medicamento', 'dosis' => 'la dosis', 'frec' => 'la frecuencia', 'dur' => 'la duración', 'ind' => 'las indicaciones'];
    $todas = []; $validas = [];
    $n = min(max(count($c['med']), count($c['dosis'])), LUX_RX_MAX);
    for ($i = 0; $i < $n; $i++) {
        $fila = [];
        foreach ($c as $k => $vals) $fila[$k] = isset($vals[$i]) && is_scalar($vals[$i]) ? trim(preg_replace('/\s+/u', ' ', (string)$vals[$i])) : '';
        $todas[] = $fila;
        if (implode('', $fila) === '') continue; // fila vacía: se ignora
        if ($fila['med'] === '' || $fila['dosis'] === '') { $err[] = 'En la línea ' . ($i + 1) . ' completá el medicamento y la dosis.'; continue; }
        foreach ($max as $k => $m) if (mb_strlen($fila[$k]) > $m) $err[] = 'En la línea ' . ($i + 1) . ' ' . $nombre[$k] . " supera los $m caracteres.";
        $validas[] = $fila;
    }
    return [$todas, $validas];
}

// ---------- Acciones ----------
$form = null; $nuevaPost = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = $_POST['accion'] ?? '';

    if ($a === 'emitir') {
        $pid = (int)($_POST['paciente_id'] ?? 0); $cid = (int)($_POST['consulta_id'] ?? 0);
        $err = [];
        if (!lux_hc_paciente($db, $pid, $base)) $err[] = 'Elegí un paciente.';
        [$filas, $items] = lux_rx_items($_POST, $err);
        if (!$items && !$err) $err[] = 'Agregá al menos un medicamento con su dosis.';
        $ind = lux_hc_texto($_POST['indicaciones'] ?? '', 1000, $err, 'Las indicaciones generales');
        $consulta = null;
        if ($cid && $pid) {
            $q = $db->prepare('SELECT id FROM consultas_clinicas WHERE id=? AND usuario_id=? AND medico_id=?');
            $q->execute([$cid, $pid, $med['id']]); $consulta = $q->fetchColumn() ?: null;
        }
        if (!$err) {
            $db->beginTransaction();
            $db->prepare('INSERT INTO recetas(usuario_id,medico_id,consulta_id,fecha_emision,indicaciones,estado) VALUES(?,?,?,?,?,?)')
               ->execute([$pid, $med['id'], $consulta, date('Y-m-d H:i:s'), $ind ?: null, 'Emitida']);
            $rid = (int)$db->lastInsertId();
            $ins = $db->prepare('INSERT INTO receta_items(receta_id,orden,medicamento,dosis,frecuencia,duracion,indicaciones) VALUES(?,?,?,?,?,?,?)');
            foreach ($items as $n => $it) $ins->execute([$rid, $n + 1, $it['med'], $it['dosis'], $it['frec'] ?: null, $it['dur'] ?: null, $it['ind'] ?: null]);
            lux_hc_registrar_acceso($db, $pid, $u['id'], 'receta_emitida');
            $db->commit();
            flash('Receta ' . lux_receta_codigo($rid) . ' emitida. Ya podés imprimirla.');
            lux_redirect('lux_receta.php?id=' . $rid);
        }
        flash(implode(' ', $err), true);
        $form = ['paciente_id' => $pid, 'consulta_id' => $consulta ?: 0, 'filas' => $filas, 'indicaciones' => $ind];
        $nuevaPost = $pid ?: 1;
    } elseif ($a === 'anular') {
        $rid = (int)($_POST['id'] ?? 0);
        $err = [];
        $motivo = lux_hc_texto($_POST['motivo'] ?? '', 255, $err, 'El motivo de la anulación', true);
        $q = $db->prepare("SELECT usuario_id FROM recetas WHERE id=? AND medico_id=? AND estado='Emitida'");
        $q->execute([$rid, $med['id']]); $pidR = (int)$q->fetchColumn();
        if (!$pidR) $err[] = 'La receta no existe, ya está anulada o es de otro médico.';
        if ($err) flash(implode(' ', $err), true);
        else {
            $db->prepare("UPDATE recetas SET estado='Anulada', anulada_motivo=?, anulada_fecha=? WHERE id=? AND medico_id=? AND estado='Emitida'")
               ->execute([$motivo, date('Y-m-d H:i:s'), $rid, $med['id']]);
            lux_hc_registrar_acceso($db, $pidR, $u['id'], 'receta_anulada');
            flash('Receta ' . lux_receta_codigo($rid) . ' anulada.');
        }
        lux_redirect('lux_receta.php?id=' . $rid);
    } else lux_redirect('lux_recetas.php');
}

// ===== Formulario de receta nueva =====
$nueva = (int)($_GET['nueva'] ?? $nuevaPost);
if ($nueva):
    $pid = $form ? (int)$form['paciente_id'] : ((int)($_GET['nueva'] ?? 0) > 1 ? (int)$_GET['nueva'] : 0);
    $pac = $pid ? lux_hc_paciente($db, $pid, $base) : null;
    if ($pid && !$pac) { flash('El paciente no existe.', true); lux_redirect('lux_recetas.php'); }
    $cid = $form ? (int)$form['consulta_id'] : (int)($_GET['consulta'] ?? 0);
    $pacientes = $pac ? [] : $db->query("SELECT u.id, u.nombre, p.dni FROM usuarios u LEFT JOIN pacientes p ON p.usuario_id=u.id WHERE $base ORDER BY u.nombre")->fetchAll();
    $alg = '';
    if ($pac) {
        lux_hc_registrar_acceso($db, $pid, $u['id'], 'ver');
        $x = $db->prepare('SELECT alergias FROM historias_clinicas WHERE usuario_id=?'); $x->execute([$pid]); $a1 = trim((string)$x->fetchColumn());
        $x = $db->prepare('SELECT alergias FROM declaraciones_salud WHERE usuario_id=?'); $x->execute([$pid]); $a2 = trim((string)$x->fetchColumn());
        $alg = [$a1, $a2];
    }
    $filas = $form ? $form['filas'] : [];
    while (count($filas) < 4) $filas[] = ['med' => '', 'dosis' => '', 'frec' => '', 'dur' => '', 'ind' => ''];
    $ind = $form ? $form['indicaciones'] : '';
    $fila = function ($f) {
        return '<div class="rx-item"><input name="med[]" maxlength="150" placeholder="Medicamento" value="' . h($f['med']) . '"><input name="dosis[]" maxlength="100" placeholder="Dosis (ej. 500 mg)" value="' . h($f['dosis']) . '">'
            . '<input name="frec[]" maxlength="100" placeholder="Frecuencia (ej. cada 8 hs)" value="' . h($f['frec']) . '"><input name="dur[]" maxlength="100" placeholder="Duración (ej. 7 días)" value="' . h($f['dur']) . '">'
            . '<input name="ind[]" maxlength="255" placeholder="Indicaciones" value="' . h($f['ind']) . '"></div>';
    };
    lux_header('Nueva receta', $pac ? $pac['nombre'] . ($pac['dni'] ? ' — DNI ' . number_format((int)$pac['dni'], 0, ',', '.') : '') : 'Elegí el paciente y cargá los medicamentos'); ?>
<?php if ($pac && is_array($alg) && ($alg[0] !== '' || $alg[1] !== '')): ?><div class="hc-alerta"><b>⚠ Alergias del paciente</b>
<?php if ($alg[0] !== ''): ?><?= nl2br(h($alg[0])) ?><?php endif; ?>
<?php if ($alg[1] !== ''): ?><div style="margin-top:<?= $alg[0] !== '' ? '8' : '0' ?>px"><em>Declaradas por el paciente:</em> <?= nl2br(h($alg[1])) ?></div><?php endif; ?></div><?php endif; ?>
<div class="venta-card"><form method="post" class="venta-form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="accion" value="emitir"><input type="hidden" name="consulta_id" value="<?= $cid ?>">
<?php if ($pac): ?><input type="hidden" name="paciente_id" value="<?= (int)$pid ?>">
<?php else: ?><div class="field ancho"><label>Paciente</label><select name="paciente_id" required><option value="" disabled selected>Elegí un paciente</option>
<?php foreach ($pacientes as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['nombre'] . ($p['dni'] ? ' — DNI ' . number_format((int)$p['dni'], 0, ',', '.') : '')) ?></option><?php endforeach; ?></select></div><?php endif; ?>
<div class="field ancho"><label>Medicamentos</label>
<div class="rx-item rx-cab"><span>Medicamento</span><span>Dosis</span><span>Frecuencia</span><span>Duración</span><span>Indicaciones</span></div>
<div id="rx-filas"><?php foreach ($filas as $f) echo $fila($f); ?></div>
<button type="button" class="btn-link" id="rx-mas">+ Agregar otro medicamento</button></div>
<div class="field ancho"><label>Indicaciones generales (opcional)</label><textarea name="indicaciones" maxlength="1000" placeholder="Reposo, dieta, controles, cuándo volver a consultar…"><?= h($ind) ?></textarea></div>
<div class="field ancho" style="font-size:12.5px;color:var(--text-muted)">Se emite a tu nombre con la fecha y hora de ahora. Una receta emitida no se puede editar: si hay un error, se anula y se emite otra.</div>
<div class="field ancho acciones"><button class="btn-small" onclick="return confirm('¿Emitir la receta?');">Emitir receta</button><a class="btn-link" href="lux_recetas.php">Cancelar</a></div>
</form></div>
<template id="rx-tpl"><?= $fila(['med' => '', 'dosis' => '', 'frec' => '', 'dur' => '', 'ind' => '']) ?></template>
<script>
(function () {
  var cont = document.getElementById('rx-filas'), btn = document.getElementById('rx-mas'), tpl = document.getElementById('rx-tpl');
  btn.addEventListener('click', function () {
    if (cont.children.length >= <?= LUX_RX_MAX ?>) { btn.style.display = 'none'; return; }
    cont.appendChild(tpl.content.cloneNode(true));
    if (cont.children.length >= <?= LUX_RX_MAX ?>) btn.style.display = 'none';
  });
})();
</script>
<?php lux_footer(); exit; endif;

// ===== Listado de mis recetas =====
$q = trim($_GET['q'] ?? '');
$estado = in_array($_GET['estado'] ?? '', ['Emitida', 'Anulada'], true) ? $_GET['estado'] : '';
$where = 'r.medico_id=?'; $par = [$med['id']];
if ($estado !== '') { $where .= ' AND r.estado=?'; $par[] = $estado; }
if ($q !== '') {
    $like = '%' . addcslashes($q, '\\%_') . '%';
    $where .= ' AND (pu.nombre LIKE ? OR p.dni LIKE ? OR EXISTS (SELECT 1 FROM receta_items i2 WHERE i2.receta_id=r.id AND i2.medicamento LIKE ?))';
    array_push($par, $like, '%' . preg_replace('/\D/', '', $q) . '%', $like);
    if (preg_replace('/\D/', '', $q) === '') $par[count($par) - 2] = '';
}
$porPag = 20;
$cnt = $db->prepare("SELECT COUNT(*) FROM recetas r JOIN usuarios pu ON pu.id=r.usuario_id LEFT JOIN pacientes p ON p.usuario_id=pu.id WHERE $where"); $cnt->execute($par);
$total = (int)$cnt->fetchColumn();
$paginas = max(1, (int)ceil($total / $porPag));
$pag = min($paginas, max(1, (int)($_GET['pag'] ?? 1)));
$off = ($pag - 1) * $porPag;
$st = $db->prepare("SELECT r.id, r.fecha_emision, r.estado, pu.id paciente_id, pu.nombre paciente, p.dni,
    (SELECT GROUP_CONCAT(i.medicamento ORDER BY i.orden SEPARATOR ', ') FROM receta_items i WHERE i.receta_id=r.id) meds
    FROM recetas r JOIN usuarios pu ON pu.id=r.usuario_id LEFT JOIN pacientes p ON p.usuario_id=pu.id WHERE $where ORDER BY r.fecha_emision DESC, r.id DESC LIMIT $porPag OFFSET $off");
$st->execute($par); $lista = $st->fetchAll();
$hayFiltro = $q !== '' || $estado !== '';
$link = function ($p) use ($q, $estado) {
    $a = array_filter(['q' => $q, 'estado' => $estado, 'pag' => $p > 1 ? $p : ''], function ($x) { return $x !== ''; });
    return 'lux_recetas.php' . ($a ? '?' . h(http_build_query($a)) : '');
};
lux_header('Recetas médicas', 'Recetas emitidas por ' . $med['nombre']); ?>
<form class="pedidos-filtros" method="get">
<input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por paciente, DNI o medicamento...">
<select name="estado" onchange="this.form.submit()"><option value="">Todas</option>
<option value="Emitida" <?= $estado === 'Emitida' ? 'selected' : '' ?>>Emitidas</option><option value="Anulada" <?= $estado === 'Anulada' ? 'selected' : '' ?>>Anuladas</option></select>
<button class="btn-small">Buscar</button>
<?php if ($hayFiltro): ?><a class="btn-link" href="lux_recetas.php" style="align-self:center">Limpiar filtros ✕</a><?php endif; ?>
<a class="btn-small" href="lux_recetas.php?nueva=1" style="margin-left:auto">+ Nueva receta</a>
</form>
<?php if (!$lista): ?><div class="empty-state"><?= $hayFiltro ? 'No hay recetas que coincidan con la búsqueda.' : 'Todavía no emitiste recetas.' ?></div>
<?php else: ?>
<div class="pedidos-tabla"><table><thead><tr><th>Receta</th><th>Fecha</th><th>Paciente</th><th>Medicamentos</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
<?php foreach ($lista as $r): ?><tr>
<td><?= h(lux_receta_codigo($r['id'])) ?></td><td><?= h(date('d/m/Y H:i', strtotime($r['fecha_emision']))) ?></td>
<td><?= h($r['paciente']) ?><?php if ($r['dni']): ?><div style="font-size:12px;color:var(--text-muted)">DNI <?= h(number_format((int)$r['dni'], 0, ',', '.')) ?></div><?php endif; ?></td>
<td><?= h($r['meds']) ?></td>
<td><?= $r['estado'] === 'Anulada' ? '<span class="status-pill low">Anulada</span>' : '<span class="status-pill ok">Emitida</span>' ?></td>
<td><div class="acciones"><a class="btn-detalle" href="lux_receta.php?id=<?= (int)$r['id'] ?>">Ver / imprimir</a></div></td>
</tr><?php endforeach; ?>
</tbody></table></div>
<?php if ($paginas > 1): ?>
<div class="hist-pag"><?php if ($pag > 1): ?><a class="btn-detalle" href="<?= $link($pag - 1) ?>">← Anterior</a><?php endif; ?>
<span>Página <?= $pag ?> de <?= $paginas ?></span>
<?php if ($pag < $paginas): ?><a class="btn-detalle" href="<?= $link($pag + 1) ?>">Siguiente →</a><?php endif; ?></div>
<?php endif; endif; ?>
<p class="hc-aviso-conf">Información confidencial. Cada receta que se emite o se anula queda registrada con quién y cuándo.</p>
<?php lux_footer();
