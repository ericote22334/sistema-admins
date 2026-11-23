<?php
// Cliente: sus recetas médicas, para ver e imprimir
require __DIR__ . '/lux_layout.php';
$u = lux_require(['cliente']);
$st = $db->prepare("SELECT r.id, r.fecha_emision, r.estado, mu.nombre medico, e.nombre esp,
    (SELECT GROUP_CONCAT(i.medicamento ORDER BY i.orden SEPARATOR ', ') FROM receta_items i WHERE i.receta_id=r.id) meds
    FROM recetas r JOIN medicos m ON m.id=r.medico_id JOIN usuarios mu ON mu.id=m.usuario_id JOIN especialidades e ON e.id=m.especialidad_id
    WHERE r.usuario_id=? ORDER BY r.fecha_emision DESC, r.id DESC LIMIT 100");
$st->execute([$u['id']]); $recetas = $st->fetchAll();
lux_header('Mis recetas', 'Recetas que te emitieron en el Consultorio Lux Salud'); ?>
<?php if (!$recetas): ?><div class="empty-state">Todavía no tenés recetas.</div>
<?php else: ?>
<div class="pedidos-tabla"><table><thead><tr><th>Receta</th><th>Fecha</th><th>Médico</th><th>Medicamentos</th><th>Estado</th><th></th></tr></thead><tbody>
<?php foreach ($recetas as $r): ?><tr>
<td><?= h(lux_receta_codigo($r['id'])) ?></td><td><?= h(date('d/m/Y', strtotime($r['fecha_emision']))) ?></td>
<td><?= h($r['medico']) ?><div style="font-size:12px;color:var(--text-muted)"><?= h($r['esp']) ?></div></td><td><?= h($r['meds']) ?></td>
<td><?= $r['estado'] === 'Anulada' ? '<span class="status-pill low">Anulada</span>' : '<span class="status-pill ok">Vigente</span>' ?></td>
<td><a class="btn-detalle" href="lux_receta.php?id=<?= (int)$r['id'] ?>">Ver / imprimir</a></td>
</tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<?php lux_footer();
