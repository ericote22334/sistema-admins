<?php
// Consultorio Lux Salud — gestión de pacientes: listado/búsqueda, alta, edición y ficha con turnos anteriores
require __DIR__ . '/lux_layout.php';
$u = lux_require(['superadmin', 'admin', 'empleado']);
$nid = lux_negocio_id($u);
if (!$nid) lux_redirect('lux_negocios.php');
if ($nid !== 4) lux_redirect('lux_ventas.php');
if (!lux_puede_pacientes($u)) { flash('Tu puesto no tiene acceso a las fichas de pacientes.', true); lux_redirect('lux_ventas.php'); }
$esMedico = lux_medico_autorizado($db, $u) !== null;

// Pacientes del consultorio: clientes con ficha o con al menos un turno en el consultorio
$basePaciente = "u.rol='cliente' AND (p.id IS NOT NULL OR EXISTS (SELECT 1 FROM turnos t0 WHERE t0.cliente_id=u.id AND t0.negocio_id=4))";

function lux_pac_cargar($db, $id, $base) {
    $s = $db->prepare("SELECT u.id, u.nombre, u.email, p.id ficha_id, p.dni, p.fecha_nacimiento, p.sexo, p.telefono, p.direccion, p.obra_social, p.numero_afiliado,
        p.contacto_emergencia_nombre ce_nombre, p.contacto_emergencia_telefono ce_telefono, p.contacto_emergencia_relacion ce_relacion
        FROM usuarios u LEFT JOIN pacientes p ON p.usuario_id=u.id WHERE u.id=? AND $base");
    $s->execute([(int)$id]);
    return $s->fetch() ?: null;
}
function lux_edad($fecha) { return (new DateTime($fecha))->diff(new DateTime('today'))->y; }
function lux_fdni($dni) { return $dni === null || $dni === '' ? '—' : number_format((int)$dni, 0, ',', '.'); }
function lux_vacio($v) { return $v === null || $v === '' ? '—' : $v; }

// ---------- Guardar (alta o edición) ----------
$form = null; // datos a mostrar en el formulario cuando hay que corregir algo
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $cuenta = null;
    if ($id) {
        $cuenta = lux_pac_cargar($db, $id, $basePaciente);
        if (!$cuenta) { flash('El paciente no existe.', true); lux_redirect('lux_pacientes.php'); }
    }
    [$d, $err] = lux_pac_validar($db, $_POST, $id);
    if ($err) {
        flash(implode(' ', $err), true);
        $form = $d + ['id' => $id];
    } else {
        try {
            $db->beginTransaction();
            if (!$id) {
                // Alta en recepción: la cuenta se crea sin acceso al portal (email de relleno y clave aleatoria)
                $db->prepare("INSERT INTO usuarios(nombre,email,pass,rol,negocio_id) VALUES(?,?,?,'cliente',NULL)")
                   ->execute([$d['nombre'], 'dni' . $d['dni'] . '@sin-email.invalid', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
                $id = (int)$db->lastInsertId();
            } else {
                $db->prepare('UPDATE usuarios SET nombre=? WHERE id=?')->execute([$d['nombre'], $id]);
            }
            $nueva = lux_pac_guardar($db, $id, $d);
            $msg = $nueva ? 'Paciente ' . $d['nombre'] . ' registrado.' : 'Ficha de ' . $d['nombre'] . ' actualizada.';
            $db->commit();
            flash($msg);
            lux_redirect('lux_pacientes.php?id=' . $id);
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($e->getCode() !== '23000') throw $e;
            flash('Ya existe una ficha con ese DNI. Verificá el número o consultá en recepción.', true); // otro usuario lo cargó justo antes
            $form = $d + ['id' => (int)($_POST['id'] ?? 0)];
        }
    }
}

// ---------- Qué pantalla mostrar ----------
$verId = (int)($_GET['id'] ?? ($form['id'] ?? 0));
$editar = $form !== null || isset($_GET['editar']);
$nuevo = $form !== null ? ((int)$form['id'] === 0) : isset($_GET['nuevo']);
$pac = null;
if ($verId && !$nuevo) {
    $pac = lux_pac_cargar($db, $verId, $basePaciente);
    if (!$pac) { flash('El paciente no existe.', true); lux_redirect('lux_pacientes.php'); }
}

// ===== Formulario de alta / edición =====
if ($nuevo || ($editar && $pac)):
    if ($form === null) {
        $form = $pac ? [
            'id' => (int)$pac['id'], 'nombre' => $pac['nombre'], 'dni' => (string)$pac['dni'], 'fecha_nacimiento' => (string)$pac['fecha_nacimiento'], 'sexo' => (string)$pac['sexo'],
            'telefono' => (string)$pac['telefono'], 'direccion' => (string)$pac['direccion'], 'obra_social' => (string)$pac['obra_social'], 'numero_afiliado' => (string)$pac['numero_afiliado'],
            'ce_nombre' => (string)$pac['ce_nombre'], 'ce_telefono' => (string)$pac['ce_telefono'], 'ce_relacion' => (string)$pac['ce_relacion'],
        ] : array_fill_keys(['nombre', 'dni', 'fecha_nacimiento', 'sexo', 'telefono', 'direccion', 'obra_social', 'numero_afiliado', 'ce_nombre', 'ce_telefono', 'ce_relacion'], '') + ['id' => 0];
    }
    $completar = $pac && !$pac['ficha_id'];
    lux_header($nuevo ? 'Nuevo paciente' : ($completar ? 'Completar ficha' : 'Editar paciente'), 'Consultorio Lux Salud — datos del paciente'); ?>
<div class="venta-card"><form method="post" class="venta-form" autocomplete="off"><?= csrf_field() ?>
<input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
<?php lux_pac_campos($form); ?>
<div class="field ancho acciones"><button class="btn-small"><?= $nuevo ? 'Registrar paciente' : 'Guardar cambios' ?></button>
<a class="btn-link" href="lux_pacientes.php<?= $pac ? '?id=' . (int)$pac['id'] : '' ?>">Cancelar</a></div>
</form></div>
<?php lux_footer(); exit; endif;

// ===== Ficha completa del paciente =====
if ($pac):
    $ts = $db->prepare('SELECT id, profesional, fecha, estado FROM turnos WHERE cliente_id=? AND negocio_id=4 ORDER BY fecha DESC, id DESC');
    $ts->execute([$pac['id']]);
    $turnos = $ts->fetchAll();
    $proximos = []; $anteriores = []; $atendidos = 0; $cancelados = 0; $ultimo = null; $ahora = time();
    foreach ($turnos as $t) {
        if (in_array($t['estado'], ['Pendiente', 'Confirmado'], true) && strtotime($t['fecha']) >= $ahora) $proximos[] = $t; else $anteriores[] = $t;
        if ($t['estado'] === 'Atendido') { $atendidos++; if (!$ultimo) $ultimo = $t['fecha']; }
        if ($t['estado'] === 'Cancelado') $cancelados++;
    }
    $proximos = array_reverse($proximos);
    $pill = function ($t) {
        if ($t['estado'] === 'Atendido') return '<span class="status-pill ok">Atendido</span>';
        if ($t['estado'] === 'Cancelado') return '<span class="status-pill low">Cancelado</span>';
        if (in_array($t['estado'], ['Pendiente', 'Confirmado'], true) && strtotime($t['fecha']) < time()) return '<span class="status-pill low">Sin atender</span>';
        if ($t['estado'] === 'Confirmado') return '<span class="status-pill confirmado">Confirmado</span>';
        return '<span class="status-pill">' . h($t['estado']) . '</span>';
    };
    lux_header($pac['nombre'], 'Ficha del paciente — Consultorio Lux Salud'); ?>
<?php if ($esMedico): ?><p><a class="btn-small" href="lux_historias.php?id=<?= (int)$pac['id'] ?>">Abrir historia clínica →</a></p><?php endif; ?>
<?php if (!$pac['ficha_id']): ?>
<div class="venta-aviso">Este paciente todavía no tiene ficha completa. <a class="btn-link" href="lux_pacientes.php?id=<?= (int)$pac['id'] ?>&editar=1">Completar ficha →</a></div>
<?php else: ?>
<div class="pedido-detalle"><div class="cab"><h2>Datos personales</h2>
<div class="acciones"><a class="btn-edit" href="lux_pacientes.php?id=<?= (int)$pac['id'] ?>&editar=1">Editar ficha</a></div></div>
<dl><div><dt>DNI</dt><dd><?= h(lux_fdni($pac['dni'])) ?></dd></div>
<div><dt>Fecha de nacimiento</dt><dd><?= h(date('d/m/Y', strtotime($pac['fecha_nacimiento']))) ?> (<?= lux_edad($pac['fecha_nacimiento']) ?> años)</dd></div>
<div><dt>Sexo</dt><dd><?= h($pac['sexo']) ?></dd></div>
<div><dt>Teléfono</dt><dd><?= h($pac['telefono']) ?></dd></div>
<?php if (!lux_email_relleno($pac['email'])): ?><div><dt>Email</dt><dd><?= h($pac['email']) ?></dd></div><?php endif; ?>
<div><dt>Dirección</dt><dd><?= h(lux_vacio($pac['direccion'])) ?></dd></div>
<div><dt>Obra social</dt><dd><?= h(lux_vacio($pac['obra_social'])) ?></dd></div>
<div><dt>Número de afiliado</dt><dd><?= h(lux_vacio($pac['numero_afiliado'])) ?></dd></div></dl></div>
<div class="pedido-detalle"><div class="cab"><h2>Contacto de emergencia</h2></div>
<dl><div><dt>Nombre</dt><dd><?= h(lux_vacio($pac['ce_nombre'])) ?></dd></div>
<div><dt>Teléfono</dt><dd><?= h(lux_vacio($pac['ce_telefono'])) ?></dd></div>
<div><dt>Relación</dt><dd><?= h(lux_vacio($pac['ce_relacion'])) ?></dd></div></dl></div>
<?php endif; ?>
<div class="kpi-row">
<div class="kpi"><div class="label">Turnos en total</div><div class="value"><?= count($turnos) ?></div></div>
<div class="kpi"><div class="label">Atendidos</div><div class="value"><?= $atendidos ?></div></div>
<div class="kpi"><div class="label">Cancelados</div><div class="value"><?= $cancelados ?></div></div>
<div class="kpi"><div class="label">Última atención</div><div class="value" style="font-size:20px"><?= $ultimo ? h(date('d/m/Y', strtotime($ultimo))) : '—' ?></div></div>
</div>
<?php if ($proximos): ?>
<div class="section-title"><h2>Próximos turnos</h2></div>
<table><thead><tr><th>Fecha</th><th>Profesional</th><th>Estado</th></tr></thead><tbody>
<?php foreach ($proximos as $t): ?><tr><td><?= h(date('d/m/Y H:i', strtotime($t['fecha']))) ?> hs</td><td><?= h($t['profesional']) ?></td><td><?= $pill($t) ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>
<div class="section-title" style="margin-top:28px"><h2>Turnos anteriores</h2></div>
<?php if (!$anteriores): ?><div class="empty-state">Este paciente todavía no tiene turnos anteriores.</div><?php else: ?>
<table><thead><tr><th>Fecha</th><th>Profesional</th><th>Estado</th></tr></thead><tbody>
<?php foreach ($anteriores as $t): ?><tr><td><?= h(date('d/m/Y H:i', strtotime($t['fecha']))) ?> hs</td><td><?= h($t['profesional']) ?></td><td><?= $pill($t) ?></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?>
<p style="margin-top:22px"><a class="btn-link" href="lux_pacientes.php">← Volver al listado de pacientes</a></p>
<?php lux_footer(); exit; endif;

// ===== Listado y búsqueda =====
$q = trim($_GET['q'] ?? '');
$ficha = in_array($_GET['ficha'] ?? '', ['completa', 'incompleta'], true) ? $_GET['ficha'] : '';
$where = $basePaciente; $par = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '\\%_') . '%';
    $cond = 'u.nombre LIKE ? OR u.email LIKE ? OR p.telefono LIKE ? OR p.obra_social LIKE ? OR p.numero_afiliado LIKE ?';
    $par = [$like, $like, $like, $like, $like];
    $digitos = preg_replace('/\D/', '', $q);
    if (strlen($digitos) >= 3) { $cond .= ' OR p.dni LIKE ?'; $par[] = '%' . $digitos . '%'; }
    $where .= " AND ($cond)";
}
if ($ficha === 'completa') $where .= ' AND p.id IS NOT NULL';
if ($ficha === 'incompleta') $where .= ' AND p.id IS NULL';

$k = $db->query("SELECT COUNT(*) total, COALESCE(SUM(p.id IS NOT NULL),0) con FROM usuarios u LEFT JOIN pacientes p ON p.usuario_id=u.id WHERE $basePaciente")->fetch();
$porPag = 20;
$cnt = $db->prepare("SELECT COUNT(*) FROM usuarios u LEFT JOIN pacientes p ON p.usuario_id=u.id WHERE $where");
$cnt->execute($par);
$total = (int)$cnt->fetchColumn();
$paginas = max(1, (int)ceil($total / $porPag));
$pag = min($paginas, max(1, (int)($_GET['pag'] ?? 1)));
$off = ($pag - 1) * $porPag;
$st = $db->prepare("SELECT u.id, u.nombre, u.email, p.id ficha_id, p.dni, p.fecha_nacimiento, p.telefono, p.obra_social, p.numero_afiliado,
    (SELECT COUNT(*) FROM turnos t WHERE t.cliente_id=u.id AND t.negocio_id=4) turnos
    FROM usuarios u LEFT JOIN pacientes p ON p.usuario_id=u.id WHERE $where ORDER BY u.nombre, u.id LIMIT $porPag OFFSET $off");
$st->execute($par);
$lista = $st->fetchAll();
$hayFiltro = $q !== '' || $ficha !== '';
$link = function ($p) use ($q, $ficha) {
    $a = array_filter(['q' => $q, 'ficha' => $ficha, 'pag' => $p > 1 ? $p : ''], function ($x) { return $x !== ''; });
    return 'lux_pacientes.php' . ($a ? '?' . h(http_build_query($a)) : '');
};
lux_header('Pacientes', 'Fichas de los pacientes del Consultorio Lux Salud'); ?>
<div class="kpi-row" style="grid-template-columns:repeat(3,1fr)">
<div class="kpi"><div class="label">Pacientes</div><div class="value"><?= (int)$k['total'] ?></div></div>
<div class="kpi"><div class="label">Con ficha completa</div><div class="value"><?= (int)$k['con'] ?></div></div>
<div class="kpi"><div class="label">Sin ficha</div><div class="value"><?= (int)$k['total'] - (int)$k['con'] ?></div></div>
</div>
<form class="pedidos-filtros" method="get">
<input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por nombre, DNI, teléfono u obra social...">
<select name="ficha" onchange="this.form.submit()"><option value="">Todas las fichas</option>
<option value="completa" <?= $ficha === 'completa' ? 'selected' : '' ?>>Ficha completa</option>
<option value="incompleta" <?= $ficha === 'incompleta' ? 'selected' : '' ?>>Sin ficha</option></select>
<button class="btn-small">Buscar</button>
<?php if ($hayFiltro): ?><a class="btn-link" href="lux_pacientes.php" style="align-self:center">Limpiar filtros ✕</a><?php endif; ?>
<a class="btn-small" href="lux_pacientes.php?nuevo=1" style="margin-left:auto">+ Nuevo paciente</a>
</form>
<?php if (!$lista): ?><div class="empty-state"><?= $hayFiltro ? 'No hay pacientes que coincidan con la búsqueda.' : 'Todavía no hay pacientes registrados.' ?></div>
<?php else: ?>
<div class="pedidos-tabla"><table><thead><tr><th>Paciente</th><th>DNI</th><th>Edad</th><th>Teléfono</th><th>Obra social</th><th>Turnos</th><th>Ficha</th><th>Acciones</th></tr></thead><tbody>
<?php foreach ($lista as $p): ?><tr>
<td><a class="btn-link" href="lux_pacientes.php?id=<?= (int)$p['id'] ?>"><?= h($p['nombre']) ?></a><?php if (!lux_email_relleno($p['email'])): ?><div style="font-size:12px;color:var(--text-muted)"><?= h($p['email']) ?></div><?php endif; ?></td>
<td><?= h(lux_fdni($p['dni'])) ?></td>
<td><?= $p['fecha_nacimiento'] ? lux_edad($p['fecha_nacimiento']) : '—' ?></td>
<td><?= h(lux_vacio($p['telefono'])) ?></td>
<td><?= h(lux_vacio($p['obra_social'])) ?><?php if (!empty($p['numero_afiliado'])): ?><div style="font-size:12px;color:var(--text-muted)">Afiliado: <?= h($p['numero_afiliado']) ?></div><?php endif; ?></td>
<td><?= (int)$p['turnos'] ?></td>
<td><?= $p['ficha_id'] ? '<span class="status-pill ok">Completa</span>' : '<span class="status-pill low">Sin ficha</span>' ?></td>
<td><div class="acciones"><a class="btn-detalle" href="lux_pacientes.php?id=<?= (int)$p['id'] ?>">Ver ficha</a>
<a class="btn-edit" href="lux_pacientes.php?id=<?= (int)$p['id'] ?>&editar=1"><?= $p['ficha_id'] ? 'Editar' : 'Completar' ?></a></div></td>
</tr><?php endforeach; ?>
</tbody></table></div>
<?php if ($paginas > 1): ?>
<div class="hist-pag"><?php if ($pag > 1): ?><a class="btn-detalle" href="<?= $link($pag - 1) ?>">← Anterior</a><?php endif; ?>
<span>Página <?= $pag ?> de <?= $paginas ?></span>
<?php if ($pag < $paginas): ?><a class="btn-detalle" href="<?= $link($pag + 1) ?>">Siguiente →</a><?php endif; ?></div>
<?php endif; endif; ?>
<?php lux_footer();
