<?php
// Cliente: completar y editar su propia ficha de paciente (necesaria para reservar turnos en el Consultorio)
// y su declaración de salud (opcional; la ven solo los médicos autorizados, el paciente no accede a su historia clínica)
require __DIR__ . '/lux_layout.php';
$u = lux_require(['cliente']);

$s = $db->prepare('SELECT u.nombre, p.id ficha_id, p.dni, p.fecha_nacimiento, p.sexo, p.telefono, p.direccion, p.obra_social, p.numero_afiliado,
    p.contacto_emergencia_nombre ce_nombre, p.contacto_emergencia_telefono ce_telefono, p.contacto_emergencia_relacion ce_relacion
    FROM usuarios u LEFT JOIN pacientes p ON p.usuario_id=u.id WHERE u.id=?');
$s->execute([(int)$u['id']]);
$pac = $s->fetch();
$ds = $db->prepare('SELECT alergias, enfermedades_previas, medicacion, antecedentes, actualizado FROM declaraciones_salud WHERE usuario_id=?');
$ds->execute([(int)$u['id']]);
$decl = $ds->fetch() ?: null;
$form = null; $formSalud = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['accion'] ?? '') === 'salud') {
        $err = [];
        $t = function ($k, $etiqueta) use (&$err) {
            $v = is_scalar($_POST[$k] ?? null) ? trim(str_replace("\r\n", "\n", (string)$_POST[$k])) : '';
            if (mb_strlen($v) > 2000) $err[] = "$etiqueta supera los 2000 caracteres.";
            return $v;
        };
        $d = ['alergias' => $t('alergias', 'Las alergias'), 'enfermedades_previas' => $t('enfermedades_previas', 'Las enfermedades previas'),
              'medicacion' => $t('medicacion', 'La medicación actual'), 'antecedentes' => $t('antecedentes', 'Los otros antecedentes')];
        if ($err) { flash(implode(' ', $err), true); $formSalud = $d; }
        else {
            $db->prepare('INSERT INTO declaraciones_salud(usuario_id,alergias,enfermedades_previas,medicacion,antecedentes,actualizado) VALUES(?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE alergias=VALUES(alergias), enfermedades_previas=VALUES(enfermedades_previas), medicacion=VALUES(medicacion), antecedentes=VALUES(antecedentes), actualizado=VALUES(actualizado)')
               ->execute([$u['id'], $d['alergias'] ?: null, $d['enfermedades_previas'] ?: null, $d['medicacion'] ?: null, $d['antecedentes'] ?: null, date('Y-m-d H:i:s')]);
            lux_hc_registrar_acceso($db, $u['id'], $u['id'], 'declaracion_paciente');
            flash('Tu declaración de salud fue guardada.');
            lux_redirect('lux_mis_datos.php');
        }
    } else {
        [$d, $err] = lux_pac_validar($db, $_POST, $u['id']);
        if ($err) { flash(implode(' ', $err), true); $form = $d; }
        else {
            try {
                $db->beginTransaction();
                $db->prepare('UPDATE usuarios SET nombre=? WHERE id=?')->execute([$d['nombre'], $u['id']]);
                lux_pac_guardar($db, $u['id'], $d);
                $db->commit();
                $_SESSION['user']['nombre'] = $d['nombre'];
                flash('Tus datos fueron guardados.');
                lux_redirect('lux_mis_turnos.php');
            } catch (PDOException $e) {
                if ($db->inTransaction()) $db->rollBack();
                if ($e->getCode() !== '23000') throw $e;
                flash('Ya existe una ficha con ese DNI. Verificá el número o consultá en recepción.', true);
                $form = $d;
            }
        }
    }
}
if ($form === null) {
    $form = [];
    foreach (['nombre', 'dni', 'fecha_nacimiento', 'sexo', 'telefono', 'direccion', 'obra_social', 'numero_afiliado', 'ce_nombre', 'ce_telefono', 'ce_relacion'] as $k) $form[$k] = (string)($pac[$k] ?? '');
}
$sal = $formSalud ?? [];
foreach (['alergias', 'enfermedades_previas', 'medicacion', 'antecedentes'] as $k) if (!isset($sal[$k])) $sal[$k] = (string)($decl[$k] ?? '');
lux_header('Mis datos de paciente', 'Completalos para poder reservar turnos en el Consultorio Lux Salud');
if (!$pac['ficha_id']): ?>
<div class="venta-aviso">Todavía no completaste tus datos de paciente. Son necesarios para reservar turnos.</div>
<?php endif; ?>
<div class="venta-card"><form method="post" class="venta-form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="accion" value="datos">
<?php lux_pac_campos($form); ?>
<div class="field ancho" style="font-size:12.5px;color:var(--text-muted)">Estos datos los ve el personal del consultorio para atenderte.</div>
<div class="field ancho acciones"><button class="btn-small">Guardar mis datos</button>
<a class="btn-link" href="lux_mis_turnos.php">Cancelar</a></div>
</form></div>

<div class="section-title" style="margin-top:28px"><h2>Mi salud (declaración)</h2></div>
<div class="venta-card"><form method="post" class="venta-form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="accion" value="salud">
<div class="field ancho" style="font-size:13px;color:var(--text-muted)">Es opcional, pero le sirve a tu médico. Lo que escribas acá lo ven solo los médicos del consultorio, no el resto del personal. Es una declaración tuya: tu médico la revisa con vos y no reemplaza la consulta. Podés cambiarla cuando quieras.
<?php if ($decl): ?><br>Última actualización: <?= h(date('d/m/Y H:i', strtotime($decl['actualizado']))) ?>.<?php endif; ?></div>
<div class="field ancho"><label>Alergias</label><textarea name="alergias" maxlength="2000" placeholder="Medicamentos, alimentos, látex… (dejalo vacío si no tenés)"><?= h($sal['alergias']) ?></textarea></div>
<div class="field ancho"><label>Enfermedades previas o crónicas</label><textarea name="enfermedades_previas" maxlength="2000"><?= h($sal['enfermedades_previas']) ?></textarea></div>
<div class="field ancho"><label>Medicación actual</label><textarea name="medicacion" maxlength="2000" placeholder="Nombre y dosis, si la sabés"><?= h($sal['medicacion']) ?></textarea></div>
<div class="field ancho"><label>Otros antecedentes</label><textarea name="antecedentes" maxlength="2000" placeholder="Cirugías, internaciones, antecedentes familiares…"><?= h($sal['antecedentes']) ?></textarea></div>
<div class="field ancho acciones"><button class="btn-small">Guardar mi declaración</button></div>
</form></div>
<?php lux_footer();
