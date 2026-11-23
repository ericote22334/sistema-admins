<?php
// Consultorio Lux Salud — calendario de turnos: vistas diaria, semanal y mensual, filtro por médico,
// agendar, confirmar, reprogramar, cancelar y días sin atención
require __DIR__ . '/lux_layout.php';
$u = lux_require(['superadmin', 'admin', 'empleado']);
$nid = lux_negocio_id($u);
if (!$nid) lux_redirect('lux_negocios.php');
if ($nid !== 4) lux_redirect('lux_ventas.php');
if (!lux_puede_pacientes($u)) { flash('Tu puesto no tiene acceso al calendario de turnos.', true); lux_redirect('lux_ventas.php'); }

// Se consulta el rol y el puesto actuales en la base (la sesión puede estar desactualizada tras un cambio de puesto)
$fr = $db->prepare('SELECT rol, puesto FROM usuarios WHERE id=?'); $fr->execute([$u['id']]); $fr = $fr->fetch() ?: [];
$gestor = in_array($fr['rol'] ?? $u['rol'], ['superadmin', 'admin'], true);
$medAut = lux_medico_autorizado($db, $u);
$puedeEditar = lux_puede_vender(['rol' => $fr['rol'] ?? $u['rol'], 'puesto' => $fr['puesto'] ?? null, 'negocio_id' => $nid]);

$MESES = [1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'];
$DIAS = lux_dias_semana();
$AB = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];
$VIGENTES = "('Pendiente','Confirmado')";
$hoy = date('Y-m-d');

function lux_cal_fecha($s, $def) { $s = is_scalar($s) ? (string)$s : ''; $d = DateTime::createFromFormat('Y-m-d', $s); return $d && $d->format('Y-m-d') === $s ? $s : $def; }
function lux_cal_sumar($ymd, $dias) { return date('Y-m-d', strtotime($ymd . ' ' . ($dias >= 0 ? '+' : '') . $dias . ' days')); }
function lux_cal_lunes($ymd) { return lux_cal_sumar($ymd, -((int)date('N', strtotime($ymd)) - 1)); }
function lux_cal_url($vista, $fecha, $medico, array $extra = []) { return 'lux_calendario.php?' . http_build_query(array_merge(['vista' => $vista, 'fecha' => $fecha, 'medico' => $medico], $extra)); }
function lux_cal_min($hhmm) { return (int)substr($hhmm, 0, 2) * 60 + (int)substr($hhmm, 3, 2); }

// ---------- Médicos ----------
$medicos = $db->query("SELECT m.id, m.usuario_id, m.duracion_turno, u.nombre, u.estado, e.nombre esp FROM medicos m
    JOIN usuarios u ON u.id=m.usuario_id JOIN especialidades e ON e.id=m.especialidad_id WHERE u.negocio_id=4 ORDER BY u.nombre")->fetchAll();
$medPorId = [];
foreach ($medicos as $m) $medPorId[(int)$m['id']] = $m;
$propio = $db->prepare('SELECT id FROM medicos WHERE usuario_id=?'); $propio->execute([$u['id']]); $propio = (int)$propio->fetchColumn();

// ---------- Vista, fecha y filtro (los datos de la pantalla viajan en la URL) ----------
$vista = in_array($_GET['vista'] ?? '', ['dia', 'semana', 'mes'], true) ? $_GET['vista'] : 'semana';
$fecha = lux_cal_fecha($_GET['fecha'] ?? '', $hoy);
$medicoSel = isset($_GET['medico']) ? (int)$_GET['medico'] : $propio; // un médico ve su propia agenda por defecto
if ($medicoSel && !isset($medPorId[$medicoSel])) $medicoSel = 0;

// ---------- Acciones ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $vp = in_array($_POST['vista'] ?? '', ['dia', 'semana', 'mes'], true) ? $_POST['vista'] : 'semana';
    $fp = lux_cal_fecha($_POST['fecha_vista'] ?? '', $hoy);
    $mp = (int)($_POST['medico_vista'] ?? 0);
    $volver = lux_cal_url($vp, $fp, $mp);
    $a = $_POST['accion'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if (in_array($a, ['confirmar', 'cancelar', 'atender', 'reprogramar', 'agendar'], true) && !$puedeEditar) {
        flash('Tu puesto no puede modificar turnos.', true); lux_redirect($volver);
    }
    if (in_array($a, ['bloquear', 'desbloquear'], true) && !$gestor) {
        flash('Solo el administrador configura los días sin atención.', true); lux_redirect($volver);
    }

    if ($a === 'confirmar' || $a === 'cancelar' || $a === 'atender') {
        $nuevo = ['confirmar' => 'Confirmado', 'cancelar' => 'Cancelado', 'atender' => 'Atendido'][$a];
        $desde = $a === 'confirmar' ? "estado='Pendiente'" : "estado IN $VIGENTES";
        $s = $db->prepare("UPDATE turnos SET estado=? WHERE id=? AND negocio_id=4 AND $desde");
        $s->execute([$nuevo, $id]);
        $msgs = ['confirmar' => 'Turno confirmado.', 'cancelar' => 'Turno cancelado.', 'atender' => 'Turno marcado como atendido.'];
        if ($s->rowCount()) flash($msgs[$a]); else flash('El turno ya no admite ese cambio.', true);
    } elseif ($a === 'reprogramar' || $a === 'agendar') {
        $f = strtotime((string)($_POST['fecha'] ?? ''));
        $mid = (int)($_POST['medico_id'] ?? 0);
        $pid = (int)($_POST['paciente_id'] ?? 0);
        $volverErr = lux_cal_url($vp, $fp, $mp, $a === 'agendar' ? ['nuevo' => 1] : ['editar' => $id]);
        $volver = $volverErr; $ok = false;
        if (!$f || $f < time()) flash('Elegí una fecha y hora futuras.', true);
        else {
            $db->beginTransaction();
            // se bloquea al médico para que dos cambios simultáneos no tomen el mismo horario
            $m = $db->prepare("SELECT m.id, m.duracion_turno, mu.nombre FROM medicos m JOIN usuarios mu ON mu.id=m.usuario_id WHERE m.id=? AND mu.estado='Activo' AND mu.negocio_id=4 FOR UPDATE");
            $m->execute([$mid]); $m = $m->fetch();
            $msg = '';
            if (!$m) $msg = 'Elegí un médico.';
            elseif ($a === 'reprogramar') {
                $t = $db->prepare("SELECT id, cliente_id FROM turnos WHERE id=? AND negocio_id=4 AND estado IN $VIGENTES FOR UPDATE");
                $t->execute([$id]); $t = $t->fetch();
                if (!$t) $msg = 'El turno ya no se puede reprogramar (fue atendido o cancelado).';
                else {
                    $msg = lux_medico_disponible($db, $m, $f, $id, $t['cliente_id']);
                    if ($msg === '') {
                        $db->prepare('UPDATE turnos SET medico_id=?, profesional=?, fecha=? WHERE id=?')->execute([$m['id'], $m['nombre'], date('Y-m-d H:i', $f), $id]);
                        $ok = true; $msg = 'Turno reprogramado: ' . date('d/m/Y H:i', $f) . ' con ' . $m['nombre'] . '.';
                    }
                }
            } else {
                $p = $db->prepare("SELECT u.id FROM usuarios u JOIN pacientes p ON p.usuario_id=u.id WHERE u.id=? AND u.rol='cliente'");
                $p->execute([$pid]);
                if (!$p->fetchColumn()) $msg = 'Elegí un paciente con ficha completa.';
                else {
                    $msg = lux_medico_disponible($db, $m, $f, 0, $pid);
                    if ($msg === '') {
                        $db->prepare("INSERT INTO turnos(cliente_id,negocio_id,medico_id,profesional,fecha,estado) VALUES(?,4,?,?,?,'Confirmado')")
                           ->execute([$pid, $m['id'], $m['nombre'], date('Y-m-d H:i', $f)]);
                        $ok = true; $msg = 'Turno agendado y confirmado: ' . date('d/m/Y H:i', $f) . ' con ' . $m['nombre'] . '.';
                    }
                }
            }
            if ($ok) $db->commit(); else $db->rollBack();
            flash($msg, !$ok);
            if ($ok) $volver = lux_cal_url('dia', date('Y-m-d', $f), $mp ? (int)$m['id'] : 0);
        }
    } elseif ($a === 'bloquear') {
        $mid = (int)($_POST['medico_id'] ?? 0);
        $desde = lux_cal_fecha($_POST['desde'] ?? '', ''); $hasta = lux_cal_fecha($_POST['hasta'] ?? '', '');
        $motivo = is_scalar($_POST['motivo'] ?? null) ? trim(preg_replace('/\s+/u', ' ', (string)$_POST['motivo'])) : '';
        if ($mid && !isset($medPorId[$mid])) flash('El médico no existe.', true);
        elseif ($desde === '' || $hasta === '' || $hasta < $desde) flash('Elegí un rango de fechas válido.', true);
        elseif ($hasta < $hoy) flash('Ese período ya pasó.', true);
        elseif (strtotime($hasta) - strtotime($desde) > 366 * 86400) flash('El período no puede superar un año.', true);
        elseif (mb_strlen($motivo) > 150) flash('El motivo supera los 150 caracteres.', true);
        else {
            $db->prepare('INSERT INTO medico_bloqueos(medico_id,desde,hasta,motivo,creado) VALUES(?,?,?,?,?)')
               ->execute([$mid ?: null, $desde, $hasta, $motivo, date('Y-m-d H:i:s')]);
            $q = "SELECT COUNT(*) FROM turnos WHERE negocio_id=4 AND estado IN $VIGENTES AND fecha>=? AND fecha<?" . ($mid ? ' AND medico_id=?' : '');
            $c = $db->prepare($q);
            $c->execute(array_merge([$desde . ' 00:00:00', lux_cal_sumar($hasta, 1) . ' 00:00:00'], $mid ? [$mid] : []));
            $n = (int)$c->fetchColumn();
            flash('Días sin atención guardados.' . ($n ? " Ya hay $n turno(s) agendado(s) en ese período: reprogramalos o cancelalos desde el calendario." : ''));
        }
    } elseif ($a === 'desbloquear') {
        $db->prepare('DELETE FROM medico_bloqueos WHERE id=?')->execute([$id]);
        flash('Período eliminado.');
    }
    lux_redirect($volver);
}

// ---------- Rango a mostrar ----------
if ($vista === 'dia') { $ini = $fecha; $fin = lux_cal_sumar($fecha, 1); $ant = lux_cal_sumar($fecha, -1); $sig = lux_cal_sumar($fecha, 1); }
elseif ($vista === 'semana') { $ini = lux_cal_lunes($fecha); $fin = lux_cal_sumar($ini, 7); $ant = lux_cal_sumar($fecha, -7); $sig = lux_cal_sumar($fecha, 7); }
else {
    $p1 = date('Y-m-01', strtotime($fecha));
    $ini = lux_cal_lunes($p1);
    $semanas = (int)ceil(((int)date('N', strtotime($p1)) - 1 + (int)date('t', strtotime($p1))) / 7);
    $fin = lux_cal_sumar($ini, $semanas * 7);
    $ant = date('Y-m-d', strtotime($p1 . ' -1 month')); $sig = date('Y-m-d', strtotime($p1 . ' +1 month'));
}
$ts = strtotime($fecha);
$dmy = function ($ymd) use ($MESES) { $t = strtotime($ymd); return (int)date('j', $t) . ' de ' . $MESES[(int)date('n', $t)]; };
if ($vista === 'dia') $titulo = $DIAS[(int)date('N', $ts)] . ' ' . $dmy($fecha) . ' de ' . date('Y', $ts);
elseif ($vista === 'semana') $titulo = 'Semana del ' . $dmy($ini) . ' al ' . $dmy(lux_cal_sumar($ini, 6)) . ' de ' . date('Y', strtotime(lux_cal_sumar($ini, 6)));
else $titulo = ucfirst($MESES[(int)date('n', strtotime($p1))]) . ' ' . date('Y', strtotime($p1));

// ---------- Turnos del rango ----------
$q = "SELECT t.id, t.fecha, t.estado, t.medico_id, t.profesional, t.cliente_id, u.nombre paciente FROM turnos t JOIN usuarios u ON u.id=t.cliente_id
    WHERE t.negocio_id=4 AND t.estado<>'Cancelado' AND t.fecha>=? AND t.fecha<?" . ($medicoSel ? ' AND t.medico_id=?' : '') . ' ORDER BY t.fecha, t.id';
$st = $db->prepare($q);
$st->execute(array_merge([$ini . ' 00:00:00', $fin . ' 00:00:00'], $medicoSel ? [$medicoSel] : []));
$porDia = [];
foreach ($st->fetchAll() as $t) $porDia[substr($t['fecha'], 0, 10)][] = $t;

// ---------- Días sin atención (los del consultorio y, con filtro, los del médico) ----------
$bq = $db->prepare('SELECT desde, hasta, motivo FROM medico_bloqueos WHERE hasta>=? AND desde<?' . ($medicoSel ? ' AND (medico_id IS NULL OR medico_id=?)' : ' AND medico_id IS NULL'));
$bq->execute(array_merge([$ini, $fin], $medicoSel ? [$medicoSel] : []));
$bloqueos = $bq->fetchAll();
$bloqDia = function ($ymd) use ($bloqueos) {
    foreach ($bloqueos as $b) if ($ymd >= $b['desde'] && $ymd <= $b['hasta']) return $b['motivo'] !== '' ? $b['motivo'] : 'Sin atención';
    return null;
};

// ---------- Indicadores ----------
$k = $db->prepare("SELECT COALESCE(SUM(fecha>=? AND fecha<?),0) hoy, COALESCE(SUM(estado='Pendiente' AND fecha>=?),0) porconf FROM turnos
    WHERE negocio_id=4 AND estado IN $VIGENTES" . ($medicoSel ? ' AND medico_id=?' : ''));
$k->execute(array_merge([$hoy . ' 00:00:00', lux_cal_sumar($hoy, 1) . ' 00:00:00', date('Y-m-d H:i:s')], $medicoSel ? [$medicoSel] : []));
$k = $k->fetch();

// ---------- Formularios (reprogramar / agendar) ----------
$editar = (int)($_GET['editar'] ?? 0);
$turnoEd = null;
if ($editar && $puedeEditar) {
    $te = $db->prepare("SELECT t.id, t.fecha, t.medico_id, t.estado, u.nombre paciente FROM turnos t JOIN usuarios u ON u.id=t.cliente_id WHERE t.id=? AND t.negocio_id=4 AND t.estado IN $VIGENTES");
    $te->execute([$editar]); $turnoEd = $te->fetch() ?: null;
}
$nuevoParam = $_GET['nuevo'] ?? '';
$verNuevo = $puedeEditar && $nuevoParam !== '' && !$turnoEd;
$prefill = is_string($nuevoParam) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $nuevoParam) ? $nuevoParam : '';
$activos = array_values(array_filter($medicos, function ($m) { return $m['estado'] === 'Activo'; }));
$pacientes = $verNuevo ? $db->query("SELECT u.id, u.nombre, p.dni FROM usuarios u JOIN pacientes p ON p.usuario_id=u.id WHERE u.rol='cliente' ORDER BY u.nombre")->fetchAll() : [];

// Campos ocultos y botones de acción que vuelven a esta misma vista
$ocultos = '<input type="hidden" name="vista" value="' . h($vista) . '"><input type="hidden" name="fecha_vista" value="' . h($fecha) . '"><input type="hidden" name="medico_vista" value="' . (int)$medicoSel . '">';
$accion = function ($accion, $id, $etiqueta, $clase = 'btn-link', $confirmar = '') use ($ocultos) {
    return '<form method="post" class="inline"' . ($confirmar !== '' ? ' onsubmit="return confirm(\'' . h(addslashes($confirmar)) . '\');"' : '') . '>' . csrf_field() . $ocultos
        . '<input type="hidden" name="accion" value="' . h($accion) . '"><input type="hidden" name="id" value="' . (int)$id . '"><button class="' . h($clase) . '">' . h($etiqueta) . '</button></form>';
};
$pill = function ($t) {
    $e = $t['estado'];
    if ($e === 'Atendido') return '<span class="status-pill ok">Atendido</span>';
    if ($e === 'Confirmado') return '<span class="status-pill confirmado">Confirmado</span>';
    return '<span class="status-pill pendiente">Pendiente</span>';
};
$claseEst = function ($e) { return ['Pendiente' => 'est-pendiente', 'Confirmado' => 'est-confirmado', 'Atendido' => 'est-atendido'][$e] ?? ''; };
$item = function ($t) use ($claseEst, $vista, $medicoSel) {
    $hora = substr($t['fecha'], 11, 5);
    $url = lux_cal_url('dia', substr($t['fecha'], 0, 10), $medicoSel);
    return '<a class="cal-item ' . $claseEst($t['estado']) . '" href="' . h($url) . '" title="' . h($hora . ' · ' . $t['paciente'] . ' · ' . $t['profesional'] . ' · ' . $t['estado']) . '">' . h($hora) . ' <b>' . h($t['paciente']) . '</b></a>';
};

lux_header('Calendario de turnos', 'Agenda del Consultorio Lux Salud');
?>
<div class="kpi-row" style="grid-template-columns:repeat(2,1fr)">
<div class="kpi"><div class="label">Turnos de hoy</div><div class="value"><?= (int)$k['hoy'] ?></div></div>
<div class="kpi"><div class="label">Por confirmar</div><div class="value"><?= (int)$k['porconf'] ?></div></div>
</div>

<?php if ($turnoEd): ?>
<div class="venta-card" style="margin-top:22px"><form method="post" class="venta-form" autocomplete="off"><?= csrf_field() ?><?= $ocultos ?>
<input type="hidden" name="accion" value="reprogramar"><input type="hidden" name="id" value="<?= (int)$turnoEd['id'] ?>">
<div class="field ancho"><h3 style="margin:0;font-size:15px;font-family:'Inter',sans-serif">Reprogramar turno de <?= h($turnoEd['paciente']) ?></h3>
<div style="font-size:13px;color:var(--text-muted);margin-top:4px">Actual: <?= h(date('d/m/Y H:i', strtotime($turnoEd['fecha']))) ?> hs · <?= h($turnoEd['estado']) ?></div></div>
<div class="field"><label>Médico</label><select name="medico_id" required>
<?php foreach ($activos as $m): ?><option value="<?= (int)$m['id'] ?>" <?= (int)$turnoEd['medico_id'] === (int)$m['id'] ? 'selected' : '' ?>><?= h($m['nombre'] . ' — ' . $m['esp']) ?></option><?php endforeach; ?></select></div>
<div class="field"><label>Nueva fecha y hora</label><input type="datetime-local" name="fecha" required min="<?= date('Y-m-d\TH:i') ?>" value="<?= h(date('Y-m-d\TH:i', strtotime($turnoEd['fecha']))) ?>"></div>
<div class="field ancho acciones"><button class="btn-small">Guardar cambios</button><a class="btn-link" href="<?= h(lux_cal_url($vista, $fecha, $medicoSel)) ?>">Cancelar</a></div>
</form></div>
<?php elseif ($verNuevo): ?>
<div class="venta-card" style="margin-top:22px"><form method="post" class="venta-form" autocomplete="off"><?= csrf_field() ?><?= $ocultos ?>
<input type="hidden" name="accion" value="agendar">
<div class="field ancho"><h3 style="margin:0;font-size:15px;font-family:'Inter',sans-serif">Agendar turno</h3>
<div style="font-size:13px;color:var(--text-muted);margin-top:4px">El turno queda confirmado. El paciente debe tener su ficha completa.</div></div>
<div class="field ancho"><label>Paciente</label><select name="paciente_id" required><option value="" disabled selected>Elegí un paciente</option>
<?php foreach ($pacientes as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['nombre'] . ' — DNI ' . number_format((int)$p['dni'], 0, ',', '.')) ?></option><?php endforeach; ?></select></div>
<div class="field"><label>Médico</label><select name="medico_id" required><option value="" disabled <?= $medicoSel ? '' : 'selected' ?>>Elegí un médico</option>
<?php foreach ($activos as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $medicoSel === (int)$m['id'] ? 'selected' : '' ?>><?= h($m['nombre'] . ' — ' . $m['esp']) ?></option><?php endforeach; ?></select></div>
<div class="field"><label>Fecha y hora</label><input type="datetime-local" name="fecha" required min="<?= date('Y-m-d\TH:i') ?>" value="<?= h($prefill) ?>"></div>
<div class="field ancho acciones"><button class="btn-small">Agendar turno</button><a class="btn-link" href="<?= h(lux_cal_url($vista, $fecha, $medicoSel)) ?>">Cancelar</a></div>
</form></div>
<?php if (!$pacientes): ?><div class="empty-state">Todavía no hay pacientes con ficha completa. Registralos desde <a class="btn-link" href="lux_pacientes.php">Pacientes</a>.</div><?php endif; ?>
<?php endif; ?>

<div class="cal-bar">
<form method="get"><input type="hidden" name="vista" value="<?= h($vista) ?>"><input type="hidden" name="fecha" value="<?= h($fecha) ?>">
<select name="medico" onchange="this.form.submit()" aria-label="Filtrar por médico"><option value="0">Todos los médicos</option>
<?php foreach ($medicos as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $medicoSel === (int)$m['id'] ? 'selected' : '' ?>><?= h($m['nombre'] . ' — ' . $m['esp'] . ($m['estado'] === 'Activo' ? '' : ' (inactivo)')) ?></option><?php endforeach; ?></select></form>
<div class="cal-tabs"><?php foreach (['dia' => 'Día', 'semana' => 'Semana', 'mes' => 'Mes'] as $vk => $vn): ?><a class="<?= $vista === $vk ? 'on' : '' ?>" href="<?= h(lux_cal_url($vk, $fecha, $medicoSel)) ?>"><?= $vn ?></a><?php endforeach; ?></div>
<div class="cal-nav"><a href="<?= h(lux_cal_url($vista, $ant, $medicoSel)) ?>" aria-label="Anterior">←</a><a href="<?= h(lux_cal_url($vista, $hoy, $medicoSel)) ?>">Hoy</a><a href="<?= h(lux_cal_url($vista, $sig, $medicoSel)) ?>" aria-label="Siguiente">→</a></div>
<?php if ($puedeEditar): ?><a class="btn-small" href="<?= h(lux_cal_url($vista, $fecha, $medicoSel, ['nuevo' => 1])) ?>" style="margin-left:auto">+ Agendar turno</a><?php endif; ?>
</div>
<h2 class="cal-titulo"><?= h($titulo) ?></h2>

<?php if ($vista === 'dia'):
    $turnosDia = $porDia[$fecha] ?? [];
    $motivoBloq = $bloqDia($fecha);
    $filas = [];
    foreach ($turnosDia as $t) $filas[] = ['hora' => substr($t['fecha'], 11, 5), 't' => $t];
    // con un médico elegido también se muestran los horarios libres de su grilla
    if ($medicoSel && $motivoBloq === null) {
        $hs = $db->prepare('SELECT hora_desde, hora_hasta FROM medico_horarios WHERE medico_id=? AND dia_semana=?');
        $hs->execute([$medicoSel, (int)date('N', $ts)]); $hs = $hs->fetch();
        if ($hs) {
            $dur = max(1, (int)$medPorId[$medicoSel]['duracion_turno']);
            $d0 = lux_cal_min($hs['hora_desde']); $d1 = lux_cal_min($hs['hora_hasta']);
            for ($mn = $d0; $mn + $dur <= $d1; $mn += $dur) {
                $ocupado = false;
                foreach ($turnosDia as $t) { $x = lux_cal_min(substr($t['fecha'], 11, 5)); if ($x >= $mn && $x < $mn + $dur) { $ocupado = true; break; } }
                if (!$ocupado) $filas[] = ['hora' => sprintf('%02d:%02d', intdiv($mn, 60), $mn % 60), 't' => null];
            }
        }
    }
    usort($filas, function ($a, $b) { return strcmp($a['hora'], $b['hora']); });
    if ($motivoBloq !== null): ?><div class="venta-aviso">Sin atención este día: <?= h($motivoBloq) ?>.</div><?php endif;
    if (!$filas): ?><div class="empty-state">No hay turnos para este día.</div>
<?php else: ?>
<div class="pedidos-tabla"><table><thead><tr><th>Hora</th><th>Paciente</th><th>Médico</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
<?php foreach ($filas as $f): $t = $f['t']; ?><tr>
<?php if ($t): ?>
<td><b><?= h($f['hora']) ?></b></td>
<td><a class="btn-link" href="lux_pacientes.php?id=<?= (int)$t['cliente_id'] ?>"><?= h($t['paciente']) ?></a></td>
<td><?= h($t['profesional']) ?></td><td><?= $pill($t) ?></td>
<td><div class="acciones"><?php if ($medAut): ?>
<?php if ((int)$t['medico_id'] === (int)$medAut['id'] && in_array($t['estado'], ['Pendiente', 'Confirmado'], true)): ?><form method="post" action="lux_consultas.php" class="inline"><?= csrf_field() ?><input type="hidden" name="accion" value="iniciar"><input type="hidden" name="paciente_id" value="<?= (int)$t['cliente_id'] ?>"><input type="hidden" name="turno_id" value="<?= (int)$t['id'] ?>"><button class="btn-small">Atender</button></form><?php endif; ?>
<a class="btn-link" href="lux_historias.php?id=<?= (int)$t['cliente_id'] ?>">Historia clínica</a>
<?php endif; ?>
<?php if ($puedeEditar && in_array($t['estado'], ['Pendiente', 'Confirmado'], true)): ?>
<?php if ($t['estado'] === 'Pendiente') echo $accion('confirmar', $t['id'], 'Confirmar', 'btn-small'); ?>
<a class="btn-edit" href="<?= h(lux_cal_url($vista, $fecha, $medicoSel, ['editar' => $t['id']])) ?>">Reprogramar</a>
<?= $accion('atender', $t['id'], 'Atendido', 'btn-link') ?>
<?= $accion('cancelar', $t['id'], 'Cancelar', 'btn-del', '¿Cancelar el turno de ' . $t['paciente'] . '?') ?>
<?php endif; ?></div></td>
<?php else: ?>
<td><b><?= h($f['hora']) ?></b></td><td class="cal-libre" colspan="3">Libre</td>
<td><?php if ($puedeEditar && strtotime($fecha . ' ' . $f['hora']) > time()): ?><a class="btn-edit" href="<?= h(lux_cal_url('dia', $fecha, $medicoSel, ['nuevo' => $fecha . 'T' . $f['hora']])) ?>">Agendar</a><?php endif; ?></td>
<?php endif; ?>
</tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>

<?php elseif ($vista === 'semana'): ?>
<div class="cal-semana">
<?php for ($i = 0; $i < 7; $i++): $d = lux_cal_sumar($ini, $i); $lista = $porDia[$d] ?? []; $bl = $bloqDia($d); ?>
<div class="cal-col <?= $d === $hoy ? 'hoy' : '' ?>">
<h4><a href="<?= h(lux_cal_url('dia', $d, $medicoSel)) ?>"><?= $AB[$i + 1] ?> <?= (int)date('j', strtotime($d)) ?></a></h4>
<?php if ($bl !== null): ?><div class="cal-bloq">⛔ <?= h($bl) ?></div><?php endif; ?>
<?php foreach ($lista as $t) echo $item($t); ?>
<?php if (!$lista && $bl === null): ?><span class="cal-libre" style="font-size:12px">Sin turnos</span><?php endif; ?>
</div>
<?php endfor; ?>
</div>

<?php else: ?>
<div class="cal-mes">
<?php for ($i = 1; $i <= 7; $i++): ?><div class="cal-cab"><?= $AB[$i] ?></div><?php endfor; ?>
<?php for ($d = $ini; $d < $fin; $d = lux_cal_sumar($d, 1)): $lista = $porDia[$d] ?? []; $bl = $bloqDia($d); $otro = substr($d, 0, 7) !== substr($p1, 0, 7); ?>
<div class="cal-celda <?= $d === $hoy ? 'hoy' : '' ?> <?= $otro ? 'otro' : '' ?>">
<a class="cal-num" href="<?= h(lux_cal_url('dia', $d, $medicoSel)) ?>"><?= (int)date('j', strtotime($d)) ?></a>
<?php if ($bl !== null): ?><div class="cal-bloq">⛔ <?= h($bl) ?></div><?php endif; ?>
<?php foreach (array_slice($lista, 0, 3) as $t) echo $item($t); ?>
<?php if (count($lista) > 3): ?><a class="cal-mas" href="<?= h(lux_cal_url('dia', $d, $medicoSel)) ?>">+<?= count($lista) - 3 ?> más</a><?php endif; ?>
</div>
<?php endfor; ?>
</div>
<?php endif; ?>

<?php // ---------- Horarios de atención y días sin atención ----------
$hsel = $medicoSel ? $db->prepare('SELECT dia_semana, hora_desde, hora_hasta FROM medico_horarios WHERE medico_id=? ORDER BY dia_semana') : null;
if ($hsel) $hsel->execute([$medicoSel]);
$lista_b = $db->prepare('SELECT b.id, b.medico_id, b.desde, b.hasta, b.motivo, u.nombre medico FROM medico_bloqueos b LEFT JOIN medicos m ON m.id=b.medico_id LEFT JOIN usuarios u ON u.id=m.usuario_id
    WHERE b.hasta>=? ORDER BY b.desde, b.id');
$lista_b->execute([$hoy]); $lista_b = $lista_b->fetchAll(); ?>
<div class="cal-box"><h3>Horarios de atención</h3>
<?php if ($medicoSel): $med = $medPorId[$medicoSel]; $res = lux_horario_resumen($hsel->fetchAll()); ?>
<p style="margin:0 0 8px;font-size:14px"><b><?= h($med['nombre']) ?></b> — <?= h($med['esp']) ?> · turnos de <?= (int)$med['duracion_turno'] ?> min</p>
<p style="margin:0 0 10px;font-size:14px"><?= $res !== '' ? h($res) : 'Todavía no tiene horarios cargados.' ?></p>
<?php if ($gestor): ?><a class="btn-link" href="lux_medicos.php?id=<?= (int)$med['usuario_id'] ?>&editar=1">Configurar días, horarios y duración →</a><?php endif; ?>
<?php else: ?>
<p style="margin:0 0 10px;font-size:14px;color:var(--text-muted)">Elegí un médico en el filtro para ver sus horarios y los turnos libres de cada día.</p>
<?php if ($gestor): ?><a class="btn-link" href="lux_medicos.php">Configurar horarios de cada médico →</a><?php endif; ?>
<?php endif; ?>
</div>

<div class="cal-box"><h3>Días sin atención</h3>
<?php if (!$lista_b): ?><p style="margin:0 0 10px;font-size:14px;color:var(--text-muted)">No hay vacaciones ni feriados cargados.</p>
<?php else: ?><table><thead><tr><th>Quién</th><th>Desde</th><th>Hasta</th><th>Motivo</th><th></th></tr></thead><tbody>
<?php foreach ($lista_b as $b): ?><tr><td><?= $b['medico_id'] ? h($b['medico']) : 'Todo el consultorio' ?></td>
<td><?= h(date('d/m/Y', strtotime($b['desde']))) ?></td><td><?= h(date('d/m/Y', strtotime($b['hasta']))) ?></td><td><?= h($b['motivo'] !== '' ? $b['motivo'] : '—') ?></td>
<td><?php if ($gestor) echo $accion('desbloquear', $b['id'], 'Eliminar', 'btn-del', '¿Eliminar este período sin atención?'); ?></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?>
<?php if ($gestor): ?>
<form method="post" class="venta-form" style="margin-top:14px" autocomplete="off"><?= csrf_field() ?><?= $ocultos ?><input type="hidden" name="accion" value="bloquear">
<div class="field"><label>Quién</label><select name="medico_id"><option value="0">Todo el consultorio</option>
<?php foreach ($medicos as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $medicoSel === (int)$m['id'] ? 'selected' : '' ?>><?= h($m['nombre']) ?></option><?php endforeach; ?></select></div>
<div class="field"><label>Desde</label><input type="date" name="desde" required min="<?= h($hoy) ?>"></div>
<div class="field"><label>Hasta</label><input type="date" name="hasta" required min="<?= h($hoy) ?>"></div>
<div class="field"><label>Motivo</label><input name="motivo" maxlength="150" placeholder="Vacaciones, feriado, congreso…"></div>
<div class="field acciones"><button class="btn-small">Agregar período</button></div>
</form>
<?php endif; ?>
</div>
<?php lux_footer();
