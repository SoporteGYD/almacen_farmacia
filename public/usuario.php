<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/UsuarioController.php';

requireLogin();

$controller = new UsuarioController();
$controller->verificarAdmin();

$mensaje = '';
$error = '';
if (empty($_SESSION['gyd_usuarios_csrf'])) { $_SESSION['gyd_usuarios_csrf'] = bin2hex(random_bytes(32)); }
$csrfUsuarios = (string) $_SESSION['gyd_usuarios_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrfUsuarios, (string)($_POST['csrf'] ?? ''))) { http_response_code(419); exit('Formulario de usuarios caducado. Actualiza la página y vuelve a intentar.'); }
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        $resultado = $controller->guardar($_POST);

    } elseif ($accion === 'editar') {
        $resultado = $controller->actualizar($_POST);

    } elseif ($accion === 'password') {
        $resultado = $controller->cambiarPassword(
            (int)($_POST['usuario_id'] ?? 0),
            $_POST['nueva_password'] ?? ''
        );

    } elseif ($accion === 'estado') {
        $resultado = $controller->cambiarEstado(
            (int)($_POST['usuario_id'] ?? 0),
            (int)($_POST['estado'] ?? 0)
        );

    } else {
        $resultado = [
            'success' => false,
            'message' => 'Acción inválida.'
        ];
    }

    if ($resultado['success']) {
        $mensaje = $resultado['message'];
    } else {
        $error = $resultado['message'];
    }
}

$usuarios = $controller->usuarios();
$almacenes = $controller->almacenes();
$opcionesPos = $controller->posOpciones();

$moduleCss = 'productos';

include __DIR__ . '/../app/views/layouts/header.php';
?>

<style>
.usuario-card {
    background: #fff;
    border-radius: 14px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 6px 14px rgba(0,0,0,.08);
}

.usuario-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit,minmax(220px,1fr));
    gap: 14px;
}

.usuario-field {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.usuario-field label {
    font-size: 13px;
    font-weight: bold;
    color: #374151;
}

.usuario-field input,
.usuario-field select {
    border: 1px solid #d1d5db;
    border-radius: 8px;
    padding: 10px;
    font-size: 14px;
}

.btn-usuario {
    background: #0f4c81;
    color: white;
    border: none;
    padding: 9px 14px;
    border-radius: 8px;
    font-weight: bold;
    cursor: pointer;
}

.btn-edit {
    background: #2563eb;
}

.btn-danger {
    background: #991b1b;
}

.table-usuarios {
    width: 100%;
    border-collapse: collapse;
}

.table-usuarios th {
    background: #0f172a;
    color: white;
    padding: 10px;
    text-align: left;
}

.table-usuarios td {
    padding: 10px;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: top;
}

.badge-activo {
    background: #dcfce7;
    color: #166534;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: bold;
}

.badge-inactivo {
    background: #fee2e2;
    color: #991b1b;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: bold;
}

.alert-success {
    background: #dcfce7;
    color: #166534;
    padding: 12px;
    border-radius: 10px;
    margin-bottom: 15px;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    padding: 12px;
    border-radius: 10px;
    margin-bottom: 15px;
}

.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, .65);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal-box {
    background: #fff;
    width: 100%;
    max-width: 850px;
    border-radius: 16px;
    padding: 22px;
    box-shadow: 0 20px 40px rgba(0,0,0,.25);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 18px;
}

.modal-header h3 {
    margin: 0;
}

.modal-close {
    background: #e5e7eb;
    border: none;
    border-radius: 8px;
    padding: 8px 12px;
    cursor: pointer;
    font-weight: bold;
}

.actions-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.password-form {
    display: flex;
    gap: 8px;
}

.password-form input {
    padding: 8px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
}
</style>

<div class="module-header">
    <div>
        <h2>Usuarios del Sistema</h2>
        <p>Administración de usuarios y accesos por almacén.</p>
    </div>
</div>

<?php if ($mensaje): ?>
    <div class="alert-success"><?= e($mensaje) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert-error"><?= e($error) ?></div>
<?php endif; ?>

<div class="usuario-card">
    <h3 style="margin-bottom:18px;">Crear Usuario</h3>
    <p style="font-size:13px;color:#456480;margin-top:-7px">Para personal de Compras, selecciona <strong>COMPRAS (ERP)</strong>. Cada empleado debe tener usuario, correo y contraseña propios. Podrá entrar a su panel de compras sin acceso al POS ni a la aplicación de inventario.</p>

    <form method="POST">
        <input type="hidden" name="accion" value="crear">
            <input type="hidden" name="csrf" value="<?= e($csrfUsuarios) ?>">

        <div class="usuario-form-grid">
            <div class="usuario-field">
                <label>Nombre</label>
                <input type="text" name="nombre" required>
            </div>

            <div class="usuario-field">
                <label>Usuario</label>
                <input type="text" name="usuario" required>
            </div>

            <div class="usuario-field">
                <label>Correo</label>
                <input type="email" name="correo" required>
            </div>

            <div class="usuario-field">
                <label>Contraseña</label>
                <input type="password" name="password" required>
            </div>

            <div class="usuario-field">
                <label>Rol</label>
                <select name="rol" required>
    <option value="CONSULTA">CONSULTA</option>
    <option value="ENCARGADO">ENCARGADO</option>
    <option value="JEFE_ALMACEN">JEFE DE ALMACÉN (SOLO RESURTIDOS Y TICKETS)</option>
    <option value="CAJERO">CAJERO (SOLO PUNTO DE VENTA)</option>
    <option value="COMPRAS">COMPRAS (ERP)</option>
    <option value="CONTABILIDAD">CONTABILIDAD (CORTES DE CAJA)</option>
    <option value="DIRECCION">DIRECCIÓN (ERP)</option>
    <option value="GERENTE">GERENTE</option>
    <option value="ADMINISTRADOR">ADMINISTRADOR</option>
</select>
            </div>

            <div class="usuario-field">
                <label>Almacén</label>
                <select name="almacen_id">
                    <option value="">Seleccione</option>
                    <?php foreach ($almacenes as $almacen): ?>
                        <option value="<?= (int)$almacen['id'] ?>">
                            <?= e($almacen['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
<div class="usuario-field" data-pos-field><label>Sucursal POS</label><select name="pos_sucursal_id" class="pos-sucursal"><option value="">Seleccionar farmacia</option><?php foreach(array_unique(array_column($opcionesPos,'sucursal_id')) as $sid): $item=current(array_filter($opcionesPos,fn($r)=>(int)$r['sucursal_id']===(int)$sid)); ?><option value="<?= (int)$sid ?>"><?= e($item['sucursal']) ?></option><?php endforeach; ?></select></div>
<div class="usuario-field" data-pos-field><label>Número de caja asignada</label><input type="text" name="pos_numero_caja" class="pos-numero" inputmode="numeric" pattern="[0-9]{1,3}" maxlength="3" placeholder="Ej. 001 o 002" autocomplete="off"><small>Si no existe la caja en esa sucursal, se crea su registro de POS.</small></div>
        </div>

        <div style="margin-top:18px;">
            <button type="submit" class="btn-usuario">Crear Usuario</button>
        </div>
    </form>
</div>

<div class="usuario-card">
    <h3 style="margin-bottom:18px;">Usuarios Registrados</h3>

    <table class="table-usuarios">
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Usuario</th>
                <th>Correo</th>
                <th>Rol</th>
                <th>Almacén / Farmacia POS</th>
                <th>Estado</th>
                <th>Password</th>
                <th>Acciones</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($usuarios as $usuario): ?>
                <tr>
                    <td><?= e($usuario['nombre']) ?></td>
                    <td><?= e($usuario['usuario']) ?></td>
                    <td><?= e($usuario['correo']) ?></td>
                    <td><?= e($usuario['rol']) ?></td>
                    <td><?= ($usuario['rol']==='CAJERO') ? e(($usuario['pos_sucursal_nombre']??'SIN ASIGNAR').' · '.($usuario['pos_caja_nombre']??'SIN CAJA')) : e($usuario['almacen_nombre'] ?? 'SIN ALMACÉN') ?></td>

                    <td>
                        <?php if ((int)$usuario['estado'] === 1): ?>
                            <span class="badge-activo">ACTIVO</span>
                        <?php else: ?>
                            <span class="badge-inactivo">INACTIVO</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <form method="POST" class="password-form">
                            <input type="hidden" name="accion" value="password">
            <input type="hidden" name="csrf" value="<?= e($csrfUsuarios) ?>">
                            <input type="hidden" name="usuario_id" value="<?= (int)$usuario['id'] ?>">

                            <input 
                                type="password"
                                name="nueva_password"
                                placeholder="Nueva contraseña"
                                required
                            >

                            <button type="submit" class="btn-usuario">Cambiar</button>
                        </form>
                    </td>

                    <td>
                        <div class="actions-row">
                            <button
                                type="button"
                                class="btn-usuario btn-edit js-editar-usuario"
                                data-id="<?= (int)$usuario['id'] ?>"
                                data-nombre="<?= htmlspecialchars((string)($usuario['nombre'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                data-usuario="<?= htmlspecialchars((string)($usuario['usuario'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                data-correo="<?= htmlspecialchars((string)($usuario['correo'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                data-rol="<?= htmlspecialchars((string)($usuario['rol'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                data-almacen-id="<?= (int)($usuario['almacen_id'] ?? 0) ?>"
                                data-pos-sucursal-id="<?= (int)($usuario['pos_sucursal_id'] ?? 0) ?>"
                                data-pos-caja-id="<?= (int)($usuario['pos_caja_id'] ?? 0) ?>"
                                data-pos-codigo="<?= htmlspecialchars((string)($usuario['pos_caja_codigo'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                            >
                                Editar
                            </button>

                            <form method="POST">
                                <input type="hidden" name="accion" value="estado">
            <input type="hidden" name="csrf" value="<?= e($csrfUsuarios) ?>">
                                <input type="hidden" name="usuario_id" value="<?= (int)$usuario['id'] ?>">
                                <input 
                                    type="hidden"
                                    name="estado"
                                    value="<?= (int)$usuario['estado'] === 1 ? 0 : 1 ?>"
                                >

                                <button type="submit" class="btn-usuario btn-danger">
                                    <?= (int)$usuario['estado'] === 1 ? 'Desactivar' : 'Activar' ?>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div id="modalEditarUsuario" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Editar Usuario</h3>
            <button type="button" class="modal-close" onclick="cerrarModalEditar()">X</button>
        </div>

        <form method="POST">
            <input type="hidden" name="accion" value="editar">
            <input type="hidden" name="csrf" value="<?= e($csrfUsuarios) ?>">
            <input type="hidden" name="usuario_id" id="edit_usuario_id">

            <div class="usuario-form-grid">
                <div class="usuario-field">
                    <label>Nombre</label>
                    <input type="text" name="nombre" id="edit_nombre" required>
                </div>

                <div class="usuario-field">
                    <label>Usuario</label>
                    <input type="text" name="usuario" id="edit_usuario" required>
                </div>

                <div class="usuario-field">
                    <label>Correo</label>
                    <input type="email" name="correo" id="edit_correo" required>
                </div>

                <div class="usuario-field">
                    <label>Rol</label>
                    <select name="rol" id="edit_rol" required>
                        <option value="CONSULTA">CONSULTA</option>
                        <option value="ENCARGADO">ENCARGADO</option>
                        <option value="JEFE_ALMACEN">JEFE DE ALMACÉN (SOLO RESURTIDOS Y TICKETS)</option>
    <option value="CAJERO">CAJERO (SOLO PUNTO DE VENTA)</option>
    <option value="COMPRAS">COMPRAS (ERP)</option>
    <option value="CONTABILIDAD">CONTABILIDAD (CORTES DE CAJA)</option>
    <option value="DIRECCION">DIRECCIÓN (ERP)</option>
                        <option value="ADMINISTRADOR">ADMINISTRADOR</option>
                        <option value="GERENTE">GERENTE</option>
                    </select>
                </div>

                <div class="usuario-field">
                    <label>Almacén</label>
                    <select name="almacen_id" id="edit_almacen_id">
                        <option value="">Seleccione</option>
                        <?php foreach ($almacenes as $almacen): ?>
                            <option value="<?= (int)$almacen['id'] ?>">
                                <?= e($almacen['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
<div class="usuario-field" data-pos-field><label>Sucursal POS</label><select name="pos_sucursal_id" class="pos-sucursal"><option value="">Seleccionar farmacia</option><?php foreach(array_unique(array_column($opcionesPos,'sucursal_id')) as $sid): $item=current(array_filter($opcionesPos,fn($r)=>(int)$r['sucursal_id']===(int)$sid)); ?><option value="<?= (int)$sid ?>"><?= e($item['sucursal']) ?></option><?php endforeach; ?></select></div>
<div class="usuario-field" data-pos-field><label>Número de caja asignada</label><input type="text" name="pos_numero_caja" class="pos-numero" inputmode="numeric" pattern="[0-9]{1,3}" maxlength="3" placeholder="Ej. 001 o 002" autocomplete="off"><small>Si no existe la caja en esa sucursal, se crea su registro de POS.</small></div>
            </div>

            <div style="margin-top:18px; display:flex; gap:10px;">
                <button type="submit" class="btn-usuario">Guardar cambios</button>
                <button type="button" class="btn-usuario btn-danger" onclick="cerrarModalEditar()">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<script>
function syncPosForm(form){
 const isCash=form.querySelector('[name=rol]').value==='CAJERO';
 const noAlmacen=['CAJERO','COMPRAS','CONTABILIDAD','DIRECCION','ADMINISTRADOR'].includes(form.querySelector('[name=rol]').value);
 form.querySelectorAll('[data-pos-field]').forEach(el=>el.hidden=!isCash);
 const almacen=form.querySelector('[name=almacen_id]');almacen.closest('.usuario-field').hidden=noAlmacen;
 form.querySelector('.pos-sucursal').required=isCash;
 form.querySelector('.pos-numero').required=isCash;
}
document.querySelectorAll('.usuario-card form, #modalEditarUsuario form').forEach(form=>{
 if(!form.querySelector('.pos-sucursal'))return;
 form.querySelector('[name=rol]').addEventListener('change',()=>syncPosForm(form));
 form.querySelector('.pos-sucursal').addEventListener('change',()=>syncPosForm(form));
 syncPosForm(form);
});
// Los datos de usuarios se leen como texto: nunca se interpretan como JavaScript.
document.querySelectorAll('.js-editar-usuario').forEach((boton) => {
    boton.addEventListener('click', () => {
        const d = boton.dataset;
        abrirModalEditar(
            d.id,
            d.nombre,
            d.usuario,
            d.correo,
            d.rol,
            Number(d.almacenId || 0),
            Number(d.posSucursalId || 0),
            d.posCodigo || ''
        );
    });
});
function abrirModalEditar(id, nombre, usuario, correo, rol, almacenId, posSucursalId, posCajaCodigo) {
    document.getElementById('edit_usuario_id').value = id;
    document.getElementById('edit_nombre').value = nombre;
    document.getElementById('edit_usuario').value = usuario;
    document.getElementById('edit_correo').value = correo;
    document.getElementById('edit_rol').value = rol;
    document.getElementById('edit_almacen_id').value = almacenId > 0 ? almacenId : '';

    const form=document.querySelector('#modalEditarUsuario form');
    form.querySelector('.pos-sucursal').value=posSucursalId||'';
    syncPosForm(form);
    const n=String(posCajaCodigo).match(/([0-9]{1,3})$/);
    form.querySelector('.pos-numero').value=n?String(Number(n[1])).padStart(3,'0'):'';
    document.getElementById('modalEditarUsuario').style.display = 'flex';
}

function cerrarModalEditar() {
    document.getElementById('modalEditarUsuario').style.display = 'none';
}
</script>

<?php include __DIR__ . '/../app/views/layouts/footer.php'; ?>
