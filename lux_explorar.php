<?php
require __DIR__ . '/lux_layout.php';
lux_require(['cliente']);
$negs = $db->query('SELECT * FROM negocios')->fetchAll();
lux_header('Explorar negocios', 'Los cuatro locales de Grupo Lux'); ?>
<div class="catalog-grid"><?php foreach ($negs as $n): ?>
<div class="catalog-card" style="--biz-color:var(--<?= h($n['color']) ?>)"><div class="name"><?= h($n['nombre']) ?></div>
<div class="type"><?= h($n['tipo']) ?></div><p><?= h($n['descripcion']) ?></p>
<?php if ($n['id'] == 4): ?><a class="btn-small" href="lux_mis_turnos.php" style="display:inline-block;background:transparent;border:1px solid var(--border-strong);color:var(--text)">Reservar turno</a><?php endif; ?></div>
<?php endforeach; ?></div>
<?php lux_footer();
