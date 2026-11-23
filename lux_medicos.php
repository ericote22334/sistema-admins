<?php
// Consultorio Lux Salud — gestión de médicos: registro, matrícula, especialidad, horarios de atención y agenda de turnos
require __DIR__ . '/lux_layout.php';
$u = lux_require(['superadmin', 'admin', 'empleado']);
$nid = lux_negocio_id($u);
if (!$nid) lux_redirect('lux_negocios.php');
if ($nid !== 4) lux_redirect('lux_ventas.php');
if (!lux_puede_pacientes($u)) { flash('Tu puesto no tiene acceso a esta sección.', true); lux_redirect('lux_ventas.php'); }
$gestor = in_array($u['rol'], ['superadmin', 'admin'], true); // solo ellos registran, editan y suspenden médicos
$dias = lux_dias_semana();
$duraciones = [10, 15, 20, 30, 45, 60];
$especialidades = $db->query('SELECT id, nombre FROM especialidades ORDER BY nombre')->fetchAll();
// Médicos: quienes tienen perfil profesional y el personal del consultorio con puesto Médico que todavía no lo completó
$baseMedico = "u.negocio_id=4 AND (m.id IS NOT NULL OR u.puesto='Médico')";

function lux_medico_validar($db, array $in, $usuarioId, $conCuenta) {
    $err = [];
    $t = function ($k, $max, $etq) use ($in, &$err) {
        $v = isset($in[$k]) && is_scalar($in[$k]) ? trim(preg_replace('/\s+/u', ' ', (string)$in[$k])) : '';
        if (mb_strlen($v) > $max) $err[] = "$etq supera los $max caracteres.";
        return $v;
    };
    $d = [
        'nombre' => $t('nombre', 150, 'El nombre'),
        'email' => mb_strtolower($t('email', 190, 'El email')),
        'matricula' => $t('matricula', 30, 'La matrícula'),
        'especialidad_id' => (int)($in['especialidad_id'] ?? 0),
        'duracion' => (int)($in['duracion'] ?? 0),
        'clave' => $conCuenta && isset($in['clave']) && is_string($in['clave']) ? $in['clave'] : '',
        'horarios' => [],
    ];
    if ($d['nombre'] === '') $err[] = 'Ingresá el nombre del médico.';
    if ($conCuenta) {
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $err[] = 'Ingresá un email válido.';
        else {
            $s = $db->prepare('SELECT COUNT(*) FROM usuarios WHERE email=?'); $s->execute([$d['email']]);
            if ((int)$s->fetchColumn()) $err[] = 'Ese email ya está registrado.';
        }
        if (strlen($d['clave']) < 6) $err[] = 'La clave debe tener al menos 6 caracteres.';
    }
    if (!preg_match('/^[\p{L}\p{N} .\/-]{3,30}$/u', $d['matricula'])) $err[] = 'Ingresá la matrícula profesional (3 a 30 caracteres, por ejemplo MP 45210).';
    else {
        $s = $db->prepare('SELECT COUNT(*) FROM medicos WHERE matricula=? AND usuario_id<>?'); $s->execute([$d['matricula'], (int)$usuarioId]);
        if ((int)$s->fetchColumn()) $err[] = 'Esa matrícula ya pertenece a otro médico.';
    }
    $ex = $db->prepare('SELECT COUNT(*) FROM especialidades WHERE id=?'); $ex->execute([$d['especialidad_id']]);
    if (!(int)$ex->fetchColumn()) $err[] = 'Elegí la especialidad.';
    if (!in_array($d['duracion'], [10, 15, 20, 30, 45, 60], true)) { $err[] = 'Elegí la duración del turno.'; $d['duracion'] = 30; }

    $marcados = is_array($in['dias'] ?? null) ? $in['dias'] : [];
    $desde = is_array($in['desde'] ?? null) ? $in['desde'] : [];
    $hasta = is_array($in['hasta'] ?? null) ? $in['hasta'] : [];
    $hora = function ($v) {
        if (is_string($v) && preg_match('/^(\d{2}:\d{2})(:\d{2})?$/', $v, $mm)) $v = $mm[1];
        return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : null;
    };
    $nombres = lux_dias_semana();
    foreach ($nombres as $dia => $nombreDia) {
        if (empty($marcados[$dia])) continue;
        $de = $hora($desde[$dia] ?? ''); $ha = $hora($hasta[$dia] ?? '');
        $d['horarios'][$dia] = [$de ?? '', $ha ?? ''];
        if ($de === null || $ha === null) $err[] = "Completá el horario del $nombreDia (formato HH:MM).";
        elseif ($ha <= $de) $err[] = "El horario del $nombreDia debe terminar después de empezar.";
        elseif ((int)substr($ha, 0, 2) * 60 + (int)substr($ha, 3, 2) - (int)substr($de, 0, 2) * 60 - (int)substr($de, 3, 2) < $d['duracion'])
            $err[] = "El horario del $nombreDia es más corto que un turno de {$d['duracion']} minutos.";
    }
    if (!$d['horarios']) $err[] = 'Elegí al menos un día de atención.';
    return [$d, array_values(array_unique($err))];
}

// Crea o actualiza el perfil profesional y reemplaza sus horarios (el llamador abre la transacción)
function lux_medico_guardar($db, $usuarioId, array $d) {
    $e = $db->prepare('SELECT id FROM medicos WHERE usuario_id=?'); $e->execute([(int)$usuarioId]);
    $mid = $e->fetchColumn();
    if ($mid) {
        $db->prepare('UPDATE medicos SET matricula=?, especialidad_id=?, duracion_turno=? WHERE id=?')
           ->execute([$d['matricula'], $d['especialidad_id'], $d['duracion'], (int)$mid]);
    } else {
        $db->prepare('INSERT INTO medicos(usuario_id,matricula,especialidad_id,duracion_turno,creado) VALUES(?,?,?,?,?)')
           ->execute([(int)$usuarioId, $d['matricula'], $d['especialidad_id'], $d['duracion'], date('Y-m-d H:i:s')]);
        $mid = $db->lastInsertId();
    }
    $mid = (int)$mid;
    $db->prepare('DELETE FROM medico_horarios WHERE medico_id=?')->execute([$mid]);
    $ins = $db->prepare('INSERT INTO medico_horarios(medico_id,dia_semana,hora_desde,hora_hasta) VALUES(?,?,?,?)');
    foreach ($d['horarios'] as $dia => $r) $ins->execute([$mid, (int)$dia, $r[0] . ':00', $r[1] . ':00']);
    return $mid;
}
function lux_medico_cargar($db, $id, $base) {
    $s = $db->prepare("SELECT u.id, u.nombre, u.email, u.estado, m.id medico_id, m.matricula, m.especialidad_id, e.nombre especialidad, m.duracion_turno
        FROM usuarios u LEFT JOIN medicos m ON m.usuario_id=u.id LEFT JOIN especialidades e ON e.id=m.especialidad_id WHERE u.id=? AND $base");
    $s->execute([(int)$id]);
    return $s->fetch() ?: null;
}
function lux_medico_horarios($db, $medicoId) {
    $s = $db->prepare('SELECT dia_semana, hora_desde, hora_hasta FROM medico_horarios WHERE medico_id=? ORDER BY dia_semana');
    $s->execute([(int)$medicoId]);
    $r = [];
    foreach ($s->fetchAll() as $f) $r[(int)$f['dia_semana']] = [substr($f['hora_desde'], 0, 5), substr($f['hora_hasta'], 0, 5)];
    return $r;
}

// ---------- Guardar / cambiar estado ----------
$form = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$gestor) { flash('Solo el administrador puede modificar médicos.', true); lux_redirect('lux_medicos.php'); }
    $accion = $_POST['accion'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($accion === 'estado') {
        if ($id === (int)$u['id']) flash('No podés suspender tu propia cuenta.', true);
        elseif (!lux_medico_cargar($db, $id, $baseMedico)) flash('El médico no existe.', true);
        else {
            $db->prepare("UPDATE usuarios SET estado = CASE estado WHEN 'Activo' THEN 'Inactivo' ELSE 'Activo' END WHERE id=?")->execute([$id]);
            flash('Estado actualizado.');
        }
        lux_redirect('lux_medicos.php?id=' . $id);
    }
    if ($accion === 'guardar') {
        if ($id && !lux_medico_cargar($db, $id, $baseMedico)) { flash('El médico no existe.', true); lux_redirect('lux_medicos.php'); }
        [$d, $err] = lux_medico_validar($db, $_POST, $id, !$id);
        if ($err) {
            flash(implode(' ', $err), true);
            $form = $d + ['id' => $id];
        } else {
            try {
                $db->beginTransaction();
                if (!$id) {
                    $db->prepare("INSERT INTO usuarios(nombre,email,pass,rol,negocio_id,puesto) VALUES(?,?,?,'empleado',4,'Médico')")
                       ->execute([$d['nombre'], $d['email'], password_hash($d['clave'], PASSWORD_DEFAULT)]);
                    $id = (int)$db->lastInsertId();
                    $msg = 'Médico ' . $d['nombre'] . ' registrado.';
                } else {
                    $db->prepare('UPDATE usuarios SET nombre=? WHERE id=?')->execute([$d['nombre'], $id]);
                    $msg = 'Datos de ' . $d['nombre'] . ' actualizados.';
                }
                lux_medico_guardar($db, $id, $d);
                $db->commit();
                flash($msg);
                lux_redirect('lux_medicos.php?id=' . $id);
            } catch (PDOException $e) {
                if ($db->inTransaction()) $db->rollBack();
                if ($e->getCode() !== '23000') throw $e;
                flash('Ese email o esa matrícula ya están registrados.', true);
                $form = $d + ['id' => (int)($_POST['id'] ?? 0)];
            }
        }
    }
}

// ---------- Qué pantalla mostrar ----------
$verId = (int)($_GET['id'] ?? ($form['id'] ?? 0));
$editar = $form !== null || isset($_GET['editar']);
$nuevo = $form !== null ? ((int)$form['id'] === 0) : isset($_GET['nuevo']);
if (($nuevo || $editar) && !$gestor) { flash('Solo el administrador puede modificar médicos.', true); lux_redirect('lux_medicos.php' . ($verId ? '?id=' . $verId : '')); }
$med = null;
if ($verId && !$nuevo) {
    $med = lux_medico_cargar($db, $verId, $baseMedico);
    if (!$med) { flash('El médico no existe.', true); lux_redirect('lux_medicos.php'); }
}

// ===== Formulario de alta / edición =====
if ($nuevo || ($editar && $med)):
    if ($form === null) {
        $hs = $med && $med['medico_id'] ? lux_medico_horarios($db, $med['medico_id']) : [];
        $form = ['id' => $med ? (int)$med['id'] : 0, 'nombre' => $med['nombre'] ?? '', 'email' => '', 'matricula' => (string)($med['matricula'] ?? ''),
            'especialidad_id' => (int)($med['especialidad_id'] ?? 0), 'duracion' => (int)($med['duracion_turno'] ?? 30), 'horarios' => $hs];
        if (!$form['horarios']) $form['horarios'] = [1 => ['09:00', '13:00'], 2 => ['09:00', '13:00'], 3 => ['09:00', '13:00'], 4 => ['09:00', '13:00'], 5 => ['09:00', '13:00']];
    }
    $completar = $med && !$med['medico_id'];
    lux_header($nuevo ? 'Registrar médico' : ($completar ? 'Completar perfil del médico' : 'Editar médico'), 'Consultorio Lux Salud — datos profesionales y horarios de atención'); ?>
<div class="venta-card"><form method="post" class="venta-form" autocomplete="off"><?= csrf_field() ?>
<input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
<div class="field ancho"><label>Nombre y apellido</label><input name="nombre" maxlength="150" required placeholder="Ej.: Dra. Paula Nieva" value="<?= h($form['nombre']) ?>"></div>
<?php if ($nuevo): ?>
<div class="field"><label>Email (para ingresar al sistema)</label><input type="email" name="email" maxlength="190" required value="<?= h($form['email']) ?>"></div>
<div class="field"><label>Clave inicial</label><input type="password" name="clave" minlength="6" required autocomplete="new-password"></div>
<?php endif; ?>
<div class="field"><label>Matrícula profesional</label><input name="matricula" maxlength="30" required placeholder="Ej.: MP 45210" value="<?= h($form['matricula']) ?>"></div>
<div class="field"><label>Especialidad</label><select name="especialidad_id" required><option value="" disabled <?= (int)$form['especialidad_id'] ? '' : 'selected' ?>>Elegí una especialidad</option>
<?php foreach ($especialidades as $e): ?><option value="<?= (int)$e['id'] ?>" <?= (int)$form['especialidad_id'] === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['nombre']) ?></option><?php endforeach; ?></select>
<?php if (!$especialidades): ?><a class="btn-link" href="lux_especialidades.php">Primero creá una especialidad →</a><?php endif; ?></div>
<div class="field"><label>Duración de cada turno</label><select name="duracion" required>
<?php foreach ($duraciones as $dm): ?><option value="<?= $dm ?>" <?= (int)$form['duracion'] === $dm ? 'selected' : '' ?>><?= $dm ?> minutos</option><?php endforeach; ?></select></div>
<div class="field ancho"><h3 style="margin:10px 0 0;font-size:14px;font-family:'Inter',sans-serif">Días y horarios de atención</h3>
<div style="font-size:12.5px;color:var(--text-muted);margin-top:4px">Marcá los días que atiende y el horario de cada uno. Los pacientes solo podrán reservar dentro de estos horarios.</div></div>
<?php foreach ($dias as $n => $nombreDia): $on = isset($form['horarios'][$n]); $r = $form['horarios'][$n] ?? ['09:00', '13:00']; ?>
<div class="field ancho dia-fila"><label><input type="checkbox" name="dias[<?= $n ?>]" value="1" <?= $on ? 'checked' : '' ?>> <?= h($nombreDia) ?></label>
<span>de</span><input type="time" name="desde[<?= $n ?>]" value="<?= h($r[0]) ?>"><span>a</span><input type="time" name="hasta[<?= $n ?>]" value="<?= h($r[1]) ?>"></div>
<?php endforeach; ?>
<div class="field ancho acciones"><button class="btn-small"><?= $nuevo ? 'Registrar médico' : 'Guardar cambios' ?></button>
<a class="btn-link" href="lux_medicos.php<?= $med ? '?id=' . (int)$med['id'] : '' ?>">Cancelar</a></div>
</form></div>
<?php lux_footer(); exit; endif;

// ===== Ficha y agenda del médico =====
if ($med):
    $hs = $med['medico_id'] ? lux_medico_horarios($db, $med['medico_id']) : [];
    $ahora = date('Y-m-d H:i:s');
    $k = ['total' => 0, 'atendidos' => 0, 'cancelados' => 0, 'proximos' => 0, 'hoy' => 0];
    $proximos = []; $anteriores = [];
    if ($med['medico_id']) {
        $q = $db->prepare("SELECT COUNT(*) total, COALESCE(SUM(estado='Atendido'),0) atendidos, COALESCE(SUM(estado='Cancelado'),0) cancelados,
            COALESCE(SUM(estado IN ('Pendiente','Confirmado') AND fecha>=?),0) proximos, COALESCE(SUM(estado<>'Cancelado' AND fecha>=? AND fecha<?),0) hoy
            FROM turnos WHERE medico_id=? AND negocio_id=4");
        $q->execute([$ahora, date('Y-m-d 00:00:00'), date('Y-m-d 00:00:00', strtotime('+1 day')), $med['medico_id']]);
        $k = $q->fetch();
        $ts = $db->prepare('SELECT t.id, t.fecha, t.estado, t.cliente_id, c.nombre paciente FROM turnos t JOIN usuarios c ON c.id=t.cliente_id
            WHERE t.medico_id=? AND t.negocio_id=4 ORDER BY t.fecha DESC, t.id DESC LIMIT 300');
        $ts->execute([$med['medico_id']]);
        foreach ($ts->fetchAll() as $t) {
            if (in_array($t['estado'], ['Pendiente', 'Confirmado'], true) && $t['fecha'] >= $ahora) $proximos[] = $t; else $anteriores[] = $t;
        }
        $proximos = array_reverse($proximos);
        $anteriores = array_slice($anteriores, 0, 100);
    }
    $pill = function ($t) {
        if ($t['estado'] === 'Atendido') return '<span class="status-pill ok">Atendido</span>';
        if ($t['estado'] === 'Cancelado') return '<span class="status-pill low">Cancelado</span>';
        if ($t['estado'] === 'Pendiente' && $t['fecha'] < date('Y-m-d H:i:s')) return '<span class="status-pill low">Sin atender</span>';
        return '<span class="status-pill">' . h($t['estado']) . '</span>';
    };
    lux_header($med['nombre'], 'Médico — Consultorio Lux Salud'); ?>
<?php if (!$med['medico_id']): ?>
<div class="venta-aviso">Esta persona tiene el puesto de médico pero todavía no tiene perfil profesional (matrícula, especialidad y horarios).
<?php if ($gestor): ?><a class="btn-link" href="lux_medicos.php?id=<?= (int)$med['id'] ?>&editar=1">Completar perfil →</a><?php endif; ?></div>
<?php else: ?>
<div class="pedido-detalle"><div class="cab"><h2>Datos profesionales</h2>
<?php if ($gestor): ?><div class="acciones"><a class="btn-edit" href="lux_medicos.php?id=<?= (int)$med['id'] ?>&editar=1">Editar</a>
<?php if ((int)$med['id'] !== (int)$u['id']): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= (int)$med['id'] ?>">
<button class="btn-del" onclick="return confirm('<?= $med['estado'] === 'Activo' ? '¿Suspender a este médico? No podrá ingresar ni recibir reservas.' : '¿Reactivar a este médico?' ?>')"><?= $med['estado'] === 'Activo' ? 'Suspender' : 'Reactivar' ?></button></form><?php endif; ?></div><?php endif; ?></div>
<dl><div><dt>Matrícula profesional</dt><dd><?= h($med['matricula']) ?></dd></div>
<div><dt>Especialidad</dt><dd><?= h($med['especialidad']) ?></dd></div>
<div><dt>Duración del turno</dt><dd><?= (int)$med['duracion_turno'] ?> minutos</dd></div>
<div><dt>Email</dt><dd><?= h($med['email']) ?></dd></div>
<div><dt>Estado</dt><dd><?= $med['estado'] === 'Activo' ? '<span class="status-pill ok">Activo</span>' : '<span class="status-pill low">Suspendido</span>' ?></dd></div></dl></div>
<div class="section-title"><h2>Horarios de atención</h2></div>
<table><thead><tr><th>Día</th><th>Horario</th></tr></thead><tbody>
<?php foreach ($dias as $n => $nombreDia): ?><tr><td><?= h($nombreDia) ?></td>
<td><?= isset($hs[$n]) ? h($hs[$n][0] . ' a ' . $hs[$n][1] . ' hs') : '<span style="color:var(--text-muted)">No atiende</span>' ?></td></tr><?php endforeach; ?>
</tbody></table>
<div class="kpi-row" style="margin-top:28px">
<div class="kpi"><div class="label">Turnos hoy</div><div class="value"><?= (int)$k['hoy'] ?></div></div>
<div class="kpi"><div class="label">Próximos turnos</div><div class="value"><?= (int)$k['proximos'] ?></div></div>
<div class="kpi"><div class="label">Atendidos</div><div class="value"><?= (int)$k['atendidos'] ?></div></div>
<div class="kpi"><div class="label">Cancelados</div><div class="value"><?= (int)$k['cancelados'] ?></div></div>
</div>
<div class="section-title"><h2>Próximos turnos</h2></div>
<?php if (!$proximos): ?><div class="empty-state">No tiene turnos próximos.</div><?php else: ?>
<table><thead><tr><th>Fecha</th><th>Paciente</th><th>Estado</th></tr></thead><tbody>
<?php foreach ($proximos as $t): ?><tr><td><?= h(date('d/m/Y H:i', strtotime($t['fecha']))) ?> hs</td>
<td><a class="btn-link" href="lux_pacientes.php?id=<?= (int)$t['cliente_id'] ?>"><?= h($t['paciente']) ?></a></td><td><?= $pill($t) ?></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?>
<div class="section-title" style="margin-top:28px"><h2>Turnos anteriores</h2></div>
<?php if (!$anteriores): ?><div class="empty-state">Todavía no tiene turnos anteriores.</div><?php else: ?>
<table><thead><tr><th>Fecha</th><th>Paciente</th><th>Estado</th></tr></thead><tbody>
<?php foreach ($anteriores as $t): ?><tr><td><?= h(date('d/m/Y H:i', strtotime($t['fecha']))) ?> hs</td>
<td><a class="btn-link" href="lux_pacientes.php?id=<?= (int)$t['cliente_id'] ?>"><?= h($t['paciente']) ?></a></td><td><?= $pill($t) ?></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?>
<?php endif; ?>
<p style="margin-top:22px"><a class="btn-link" href="lux_medicos.php">← Volver al listado de médicos</a></p>
<?php lux_footer(); exit; endif;

// ===== Listado y búsqueda =====
$q = trim($_GET['q'] ?? '');
$dia = (int)($_GET['dia'] ?? 0);
if (!isset($dias[$dia])) $dia = 0;
$ahora = date('Y-m-d H:i:s');
$where = $baseMedico; $par = [$ahora];
if ($q !== '') {
    $like = '%' . addcslashes($q, '\\%_') . '%';
    $where .= ' AND (u.nombre LIKE ? OR u.email LIKE ? OR m.matricula LIKE ? OR e.nombre LIKE ?)';
    array_push($par, $like, $like, $like, $like);
}
if ($dia) { $where .= ' AND EXISTS (SELECT 1 FROM medico_horarios h WHERE h.medico_id=m.id AND h.dia_semana=?)'; $par[] = $dia; }
$st = $db->prepare("SELECT u.id, u.nombre, u.email, u.estado, m.id medico_id, m.matricula, e.nombre especialidad,
    (SELECT COUNT(*) FROM turnos t WHERE t.medico_id=m.id AND t.estado IN ('Pendiente','Confirmado') AND t.fecha>=?) proximos
    FROM usuarios u LEFT JOIN medicos m ON m.usuario_id=u.id LEFT JOIN especialidades e ON e.id=m.especialidad_id WHERE $where ORDER BY u.nombre, u.id");
$st->execute($par);
$lista = $st->fetchAll();
$horPor = [];
$ids = array_values(array_filter(array_map(function ($r) { return (int)$r['medico_id']; }, $lista)));
if ($ids) {
    $hq = $db->prepare('SELECT medico_id, dia_semana, hora_desde, hora_hasta FROM medico_horarios WHERE medico_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $hq->execute($ids);
    foreach ($hq->fetchAll() as $f) $horPor[(int)$f['medico_id']][] = $f;
}
$kp = $db->query("SELECT COALESCE(SUM(u.estado='Activo' AND m.id IS NOT NULL),0) activos, COUNT(DISTINCT m.especialidad_id) esp
    FROM usuarios u LEFT JOIN medicos m ON m.usuario_id=u.id WHERE $baseMedico")->fetch();
$th = $db->prepare("SELECT COUNT(*) FROM turnos WHERE negocio_id=4 AND medico_id IS NOT NULL AND estado<>'Cancelado' AND fecha>=? AND fecha<?");
$th->execute([date('Y-m-d 00:00:00'), date('Y-m-d 00:00:00', strtotime('+1 day'))]);
$turnosHoy = (int)$th->fetchColumn();
$hayFiltro = $q !== '' || $dia;
lux_header('Médicos', 'Médicos, especialidades y horarios de atención del Consultorio Lux Salud'); ?>
<div class="kpi-row" style="grid-template-columns:repeat(3,1fr)">
<div class="kpi"><div class="label">Médicos activos</div><div class="value"><?= (int)$kp['activos'] ?></div></div>
<div class="kpi"><div class="label">Especialidades</div><div class="value"><?= (int)$kp['esp'] ?></div></div>
<div class="kpi"><div class="label">Turnos de hoy</div><div class="value"><?= $turnosHoy ?></div></div>
</div>
<form class="pedidos-filtros" method="get">
<input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por nombre, matrícula o especialidad...">
<select name="dia" onchange="this.form.submit()"><option value="">Todos los días</option>
<?php foreach ($dias as $n => $nombreDia): ?><option value="<?= $n ?>" <?= $n === $dia ? 'selected' : '' ?>>Atiende los <?= h(mb_strtolower($nombreDia)) ?></option><?php endforeach; ?></select>
<button class="btn-small">Buscar</button>
<?php if ($hayFiltro): ?><a class="btn-link" href="lux_medicos.php" style="align-self:center">Limpiar filtros ✕</a><?php endif; ?>
<?php if ($gestor): ?><a class="btn-small" href="lux_medicos.php?nuevo=1" style="margin-left:auto">+ Registrar médico</a><?php endif; ?>
</form>
<?php if (!$lista): ?><div class="empty-state"><?= $hayFiltro ? 'No hay médicos que coincidan con la búsqueda.' : 'Todavía no hay médicos registrados.' ?></div>
<?php else: ?>
<div class="pedidos-tabla"><table><thead><tr><th>Médico</th><th>Matrícula</th><th>Especialidad</th><th>Días y horarios</th><th>Próximos turnos</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
<?php foreach ($lista as $m): $hf = $horPor[(int)$m['medico_id']] ?? []; $dd = []; foreach ($hf as $f) $dd[(int)$f['dia_semana']] = true; ?><tr>
<td><a class="btn-link" href="lux_medicos.php?id=<?= (int)$m['id'] ?>"><?= h($m['nombre']) ?></a><?= (int)$m['id'] === (int)$u['id'] ? ' <span style="font-size:12px;color:var(--text-muted)">(vos)</span>' : '' ?>
<div style="font-size:12px;color:var(--text-muted)"><?= h($m['email']) ?></div></td>
<?php if ($m['medico_id']): ?>
<td><?= h($m['matricula']) ?></td><td><?= h($m['especialidad']) ?></td>
<td><?php foreach ($dias as $n => $nombreDia): ?><span class="dia-chip <?= isset($dd[$n]) ? '' : 'off' ?>"><?= h(mb_substr($nombreDia, 0, 3)) ?></span><?php endforeach; ?>
<div style="font-size:12px;color:var(--text-muted)"><?= h(lux_horario_resumen($hf)) ?></div></td>
<td><?= (int)$m['proximos'] ?></td>
<?php else: ?><td colspan="4"><span class="status-pill low">Perfil incompleto</span></td><?php endif; ?>
<td><?= $m['estado'] === 'Activo' ? '<span class="status-pill ok">Activo</span>' : '<span class="status-pill low">Suspendido</span>' ?></td>
<td><div class="acciones"><a class="btn-detalle" href="lux_medicos.php?id=<?= (int)$m['id'] ?>"><?= $m['medico_id'] ? 'Ver agenda' : 'Ver' ?></a>
<?php if ($gestor): ?><a class="btn-edit" href="lux_medicos.php?id=<?= (int)$m['id'] ?>&editar=1"><?= $m['medico_id'] ? 'Editar' : 'Completar' ?></a><?php endif; ?></div></td>
</tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<?php lux_footer();
