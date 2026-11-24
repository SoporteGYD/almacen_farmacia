<?php
/** Control central de restricciones adicionales G&D.
 * IMPORTANTE: una decisión ALLOW nunca sustituye los permisos existentes del ERP.
 * Las decisiones del usuario prevalecen sobre las del rol dentro de ESTA capa.
 */
declare(strict_types=1);

function gyd_acl_catalogo(): array {
    return [
        'almacen' => ['titulo'=>'Operación de Almacén','descripcion'=>'Entradas, salidas, devoluciones, resurtidos e historiales'],
        'inventarios' => ['titulo'=>'Inventarios y Existencias','descripcion'=>'Existencias, agotados, conteos e inventario virtual'],
        'pos' => ['titulo'=>'Punto de Venta','descripcion'=>'POS, cobros, tickets y operaciones del turno'],
        'cajas_gerencia' => ['titulo'=>'Control Gerencial de Cajas','descripcion'=>'Arqueos, control y auditoría de cajas'],
        'compras' => ['titulo'=>'Compras y Órdenes','descripcion'=>'Órdenes de compra, envíos y autorizaciones'],
        'recepciones' => ['titulo'=>'Recepciones y Facturas','descripcion'=>'Recepción, facturas y verificaciones'],
        'proveedores' => ['titulo'=>'Proveedores y Catálogo Maestro','descripcion'=>'Proveedores, productos y precios de catálogo'],
        'finanzas' => ['titulo'=>'Tesorería y Finanzas','descripcion'=>'Cuentas por pagar, tesorería y flujo de efectivo'],
        'reportes' => ['titulo'=>'Reportes Gerenciales','descripcion'=>'Ventas, eficiencia, rendimiento y comparativos'],
        'alertas' => ['titulo'=>'Alertas Gerenciales','descripcion'=>'Centro de seguimiento de incidencias'],
        'seguridad' => ['titulo'=>'Usuarios y Auditoría','descripcion'=>'Cuentas, bitácora de movimientos y seguridad'],
    ];
}

/** Mapeo explícito: rutas no enumeradas continúan bajo sus validaciones originales. */
function gyd_acl_modulo_ruta(string $filename): ?string {
    static $m = null;
    if ($m === null) {
        $byModule = [
            'almacen'=>['entradas.php','salidas.php','devoluciones.php','resurtidos.php','tickets.php','historial_entradas.php','historial_salidas.php','historial_movimientos.php','imprimir_entrada.php','imprimir_salida.php','kardex.php','reportes.php','api_buscar_producto.php','importar_productos.php','exportar_reporte_excel.php','exportar_reporte_pdf.php','api_notificaciones_pendientes.php'],
            'inventarios'=>['existencias.php','agotados.php','inventario_fisico.php','inventario_virtual.php','inventario_virtual_historial.php','erp_conciliacion_inventarios.php','erp_conciliacion_gestion.php','erp_reservas_almacen.php','erp_kardex_general.php','api_guardar_inventario_virtual.php','exportar_inventario_virtual_excel.php','exportar_inventario_virtual_pdf.php','exportar_agotados_excel.php'],
            'pos'=>['pos.php','pos_api.php','pos_ticket.php','pos_historial.php','pos_retiros.php','pos_retiros_api.php','pos_turnos_v13.php','pos_turnos_v13_api.php','pos_retiros_estado.php','pos_configurar.php','pos_ubicaciones_config.php','pos_eficiencia_api.php','pos_inteligente_api.php','pos_inteligente.php','pos_prueba.php','pos_ticket_prueba.php'],
            'cajas_gerencia'=>['erp_control_cajas.php','erp_auditoria_cajas_v12.php','erp_reportes_ventas_cajas.php','erp_conciliacion_diaria_cajas.php','erp_conciliacion_cajas.php'],
            'compras'=>['erp_ordenes_compra.php','erp_oc_win.php','erp_oc_win_enviar.php','erp_oc_win_gerentes.php','erp_oc_win_pdf.php','erp_oc_enviar.php','erp_oc_importar.php','erp_oc_autorizaciones.php','erp_orden_compra_pdf.php'],
            'recepciones'=>['erp_recepciones_pendientes.php','erp_recepciones_historial.php','erp_recepcion_registrar.php','erp_facturas.php','erp_facturas_verificacion.php','erp_facturas_aplicar.php'],
            'proveedores'=>['erp_catalogo_maestro.php','erp_proveedores.php','erp_producto_alta.php','erp_cambio_precios.php','erp_historial_precios.php'],
            'finanzas'=>['erp_cuentas_por_pagar.php','erp_tesoreria.php','erp_flujo_efectivo.php','erp_cxp_archivo.php'],
            'reportes'=>['erp_dashboard_gerencial.php','erp_eficiencia_cajeros.php','erp_eficiencia_diagnostico.php','erp_rendimiento_cajeros.php','erp_comparativo_sucursales.php'],
            'alertas'=>['erp_alertas_gerenciales.php','erp_alertas_gerenciales_api.php'],
            'seguridad'=>['usuario.php','erp_permisos_usuarios.php','erp_auditoria.php','respaldos.php'],
        ];
        $m=[];
        foreach ($byModule as $name=>$routes) foreach($routes as $route) $m[$route]=$name;
    }
    return $m[basename($filename)]??null;
}
function gyd_acl_rol_real(array $usuario): string {
    return strtoupper(trim((string)($usuario['rol_original']??$usuario['rol']??'')));
}
function gyd_acl_decisiones(array $usuario): array {
    static $cache = [];
    $uid=(int)($usuario['id']??0);$role=gyd_acl_rol_real($usuario);
    $key=$uid.':'.$role;
    if(isset($cache[$key]))return $cache[$key];
    if($uid<=0) throw new RuntimeException('Sesión de usuario inválida.');
    require_once __DIR__.'/../config/database.php';
    $db=(new Database())->connect();
    $r=$db->prepare('SELECT modulo,decision FROM gyd_acl_roles WHERE rol=?');$r->execute([$role]);
    $rolePolicies=[];foreach($r->fetchAll(PDO::FETCH_ASSOC) as $p)$rolePolicies[$p['modulo']]=$p['decision'];
    $r=$db->prepare('SELECT modulo,decision FROM gyd_acl_usuarios WHERE usuario_id=?');$r->execute([$uid]);
    $userPolicies=[];foreach($r->fetchAll(PDO::FETCH_ASSOC) as $p)$userPolicies[$p['modulo']]=$p['decision'];
    return $cache[$key]=['rol'=>$rolePolicies,'usuario'=>$userPolicies];
}
function gyd_acl_puede(array $usuario,string $modulo): bool {
    if(!array_key_exists($modulo,gyd_acl_catalogo()))return true;
    $real=gyd_acl_rol_real($usuario);
    // No bloquear administradores por error: siempre conservan el rescate de configuración.
    if($real==='ADMINISTRADOR')return true;
    $p=gyd_acl_decisiones($usuario);
    $decision=$p['usuario'][$modulo]??$p['rol'][$modulo]??'ALLOW';
    return $decision!=='DENY';
}
function gyd_acl_exigir_ruta(array $usuario,string $filename): void {
    $mod=gyd_acl_modulo_ruta($filename);
    if($mod===null)return;
    // No conferir permisos: solo comprobar denegaciones adicionales.
    try { $allowed=gyd_acl_puede($usuario,$mod); }
    catch(Throwable $e) { error_log('G&D ACL: '.$e->getMessage());$allowed=false; }
    if($allowed)return;
    http_response_code(403);
    header('Cache-Control: no-store');
    if(str_contains($filename,'api') || str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')){
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>'No tienes acceso a este módulo. Contacta al administrador.'],JSON_UNESCAPED_UNICODE);
    }else{
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Acceso restringido</title><main style="max-width:560px;margin:14vh auto;font:16px system-ui;padding:25px"><h1>Acceso restringido</h1><p>Tu cuenta no tiene permiso para ingresar a este módulo.</p><a href="dashboard.php">Volver al inicio</a> · <a href="logout.php">Cerrar sesión</a></main>';
    }
    exit;
}
