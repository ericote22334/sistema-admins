<?php
// Consultorio Lux Salud — registro de accesos a historias clínicas (solo administrador; no muestra datos clínicos)
require __DIR__ . '/lux_layout.php';
$u = lux_require(['superadmin', 'admin']);
$nid = lux_negocio_id($u);
if (!$nid) lux_redirect('lux_negocios.php');
if ($nid !== 4) lux_redirect('lux_ventas.php');

$acciones = ['ver' => 'Abrió la historia', 'editar_antecedentes' => 'Modificó los antecedentes', 'nueva_consulta' => 'Registró una consulta', 'declaracion_paciente' => 'El paciente actualizó su declaración',
    'consulta_iniciada' => 'Inició una consulta', 'consulta_finalizada' => 'Finalizó una consulta', 'consulta_descartada' => 'Descartó una consulta en curso',
    'receta_emitida' => 'Emitió una receta', 'receta_anulada' => 'Anuló una receta'];
$accion = isset($acciones[$_GET['accion'] ?? '']) ? $_GET['accion'] : '';
$q = trim($_GET['q'] ?? '');
$where = '1=1'; $par = [];
if ($accion !== '') { $where .= ' AND a.accion=?'; $par[] = $accion; }
if ($q !== '') { $like = '%' . addcslashes($q, '\\%_') . '%'; $where .= ' AND (pac.nombre LIKE ? OR prof.nombre LIKE ?)'; $par[] = $like; $par[] = $like; }
$st = $db->prepare("SELECT a.fecha, a.accion, a.usuario_id, pac.nombre paciente, prof.nombre profesional FROM historia_accesos a
    JOIN usuarios pac ON pac.id=a.usuario_id JOIN usuarios prof ON prof.id=a.profesional_id WHERE $where ORDER BY a.fecha DESC, a.id DESC LIMIT 200");
$st->execute($par); $filas = $st->fetchAll();
lux_header('Auditoría de historias', 'Quién abrió o modificó cada historia clínica — Consultorio Lux Salud'); ?>
<form class="pedidos-filtros" method="get">
<input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por paciente o profesional...">
<select name="accion" onchange="this.form.submit()"><option value="">Todas las acciones</option>
<?php foreach ($acciones as $k => $n): ?><option value="<?= h($k) ?>" <?= $accion === $k ? 'selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select>
<button class="btn-small">Buscar</button>
<?php if ($q !== '' || $accion !== ''): ?><a class="btn-link" href="lux_auditoria_historias.php" style="align-self:center">Limpiar filtros ✕</a><?php endif; ?>
</form>
<?php if (!$filas): ?><div class="empty-state">Todavía no hay accesos registrados.</div>
<?php else: ?>
<div class="pedidos-tabla"><table><thead><tr><th>Fecha y hora</th><th>Quién</th><th>Acción</th><th>Paciente</th></tr></thead><tbody>
<?php foreach ($filas as $f): ?><tr><td><?= h(date('d/m/Y H:i', strtotime($f['fecha']))) ?></td><td><?= h($f['profesional']) ?></td><td><?= h($acciones[$f['accion']] ?? $f['accion']) ?></td><td><?= h($f['paciente']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<p class="hc-aviso-conf">Se muestran los últimos 200 movimientos. Esta pantalla no incluye datos clínicos: solo quién accedió, cuándo y a la historia de quién.</p>
<?php endif; ?>
<?php lux_footer();
