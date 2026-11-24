<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/UsuarioController.php';

requireLogin();

$controller = new UsuarioController();
$controller->verificarAdmin();

$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    <form method="POST">
        <input type="hidden" name="accion" value="crear">

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
                <th>Almacén</th>
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
                    <td><?= e($usuario['almacen_nombre'] ?? 'SIN ALMACÉN') ?></td>

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
                                class="btn-usuario btn-edit"
                                onclick="abrirModalEditar(
                                    '<?= (int)$usuario['id'] ?>',
                                    '<?= e($usuario['nombre']) ?>',
                                    '<?= e($usuario['usuario']) ?>',
                                    '<?= e($usuario['correo']) ?>',
                                    '<?= e($usuario['rol']) ?>',
                                    '<?= (int)($usuario['almacen_id'] ?? 0) ?>'
                                )"
                            >
                                Editar
                            </button>

                            <form method="POST">
                                <input type="hidden" name="accion" value="estado">
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
            </div>

            <div style="margin-top:18px; display:flex; gap:10px;">
                <button type="submit" class="btn-usuario">Guardar cambios</button>
                <button type="button" class="btn-usuario btn-danger" onclick="cerrarModalEditar()">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModalEditar(id, nombre, usuario, correo, rol, almacenId) {
    document.getElementById('edit_usuario_id').value = id;
    document.getElementById('edit_nombre').value = nombre;
    document.getElementById('edit_usuario').value = usuario;
    document.getElementById('edit_correo').value = correo;
    document.getElementById('edit_rol').value = rol;
    document.getElementById('edit_almacen_id').value = almacenId > 0 ? almacenId : '';

    document.getElementById('modalEditarUsuario').style.display = 'flex';
}

function cerrarModalEditar() {
    document.getElementById('modalEditarUsuario').style.display = 'none';
}
</script>

<?php include __DIR__ . '/../app/views/layouts/footer.php'; ?>
