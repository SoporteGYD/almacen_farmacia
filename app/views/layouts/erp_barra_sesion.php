<?php
$erpUsrBar = currentUser() ?? [];
$erpNombreBar = htmlspecialchars((string)($erpUsrBar['nombre'] ?? 'Usuario'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$erpRolBar = htmlspecialchars((string)($erpUsrBar['rol_original'] ?? $erpUsrBar['rol'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<style>
.gyd-erp-sesion {
    display:flex;
    flex-wrap:wrap;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    padding:12px 20px;
    background:#ffffff;
    color:#163b61;
    border-bottom:1px solid #d4e0ec;
    font:13px system-ui,Segoe UI,Arial,sans-serif;
}
.gyd-erp-sesion-links {
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:center;
}
.gyd-erp-sesion a {
    display:inline-flex;
    align-items:center;
    text-decoration:none;
    padding:10px 14px;
    border-radius:10px;
    background:#edf3fa;
    color:#174572;
    font:600 13px system-ui,Segoe UI,Arial,sans-serif;
}
.gyd-erp-sesion a:hover { background:#dbe9f8; }
.gyd-erp-sesion a.gyd-erp-cerrar { background:#a92844; color:#fff; }
.gyd-erp-sesion a.gyd-erp-cerrar:hover { background:#8b1932; }
.gyd-erp-sesion-nombre { overflow-wrap:anywhere; }
</style>
<nav class="gyd-erp-sesion" aria-label="Sesión de usuario ERP">
    <div class="gyd-erp-sesion-nombre"><strong><?= $erpNombreBar ?></strong> · <?= $erpRolBar ?></div>
    <div class="gyd-erp-sesion-links">
        <a href="<?= strtoupper((string)($erpUsrBar['rol_original']??$erpUsrBar['rol']??''))==='COMPRAS' ? 'erp_compras_inicio.php' : 'dashboard.php' ?>">Inicio</a>
        <a class="gyd-erp-cerrar" href="logout.php">Cerrar sesión</a>
    </div>
</nav>
