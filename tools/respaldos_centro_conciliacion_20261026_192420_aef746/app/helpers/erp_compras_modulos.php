<?php
declare(strict_types=1);
/** Listado cerrado de rutas permitidas al personal de Compras. No incluye aplicaciones de stock ni administración de usuarios. */
function gyd_compras_modulos(): array {
    return [
        // GYD_COMPRAS_FINANZAS_MENU_V1: herramientas financieras de consulta.
        'Reportes, costos y alertas' => [
            ['titulo'=>'Reportes del Catálogo Maestro', 'ruta'=>'erp_catalogo_reportes.php', 'detalle'=>'Filtrar columnas y exportar productos con costos'],
            ['titulo'=>'Auditoría del Catálogo', 'ruta'=>'erp_catalogo_auditoria.php', 'detalle'=>'Revisar datos incompletos, IVA e incidencias de costos'],
            ['titulo'=>'Costos y rentabilidad', 'ruta'=>'erp_catalogo_finanzas.php', 'detalle'=>'Último costo, promedio, precio y márgenes por sucursal'],
            ['titulo'=>'Historial de costos y facturas', 'ruta'=>'erp_catalogo_historial_costos.php', 'detalle'=>'Evolución de costos por código, fecha y proveedor'],
            ['titulo'=>'Comparar proveedores', 'ruta'=>'erp_catalogo_comparar_proveedores.php', 'detalle'=>'Costos documentados, variaciones y márgenes'],
            ['titulo'=>'Alertas inteligentes', 'ruta'=>'erp_alertas_compras.php', 'detalle'=>'Aumentos, margen bajo, pérdidas y oportunidades de ahorro'],
            // GYD_SEGUIMIENTO_COMPRAS_MENU_V1
            ['titulo'=>'Centro de seguimiento de alertas', 'ruta'=>'erp_compras_alertas_seguimiento.php', 'detalle'=>'Asignar, revisar y cerrar incidencias con historial'],
        ],
        'Pedidos y productos' => [
            ['titulo'=>'Órdenes de compra', 'ruta'=>'erp_ordenes_compra.php', 'detalle'=>'Crear, importar, enviar y consultar pedidos'],
            ['titulo'=>'Análisis de compras', 'ruta'=>'erp_analisis_compras.php', 'detalle'=>'Comparar costos por producto y proveedor'],
            ['titulo'=>'Proveedores', 'ruta'=>'erp_proveedores.php', 'detalle'=>'Catálogo y datos de proveedores'],
            ['titulo'=>'Catálogo maestro', 'ruta'=>'erp_catalogo_maestro.php', 'detalle'=>'Consultar productos e impuestos'],
            ['titulo'=>'Alta de artículos', 'ruta'=>'erp_producto_alta.php', 'detalle'=>'Registrar fichas y artículos nuevos'],
            ['titulo'=>'Mi correo de trabajo', 'ruta'=>'erp_mi_correo.php', 'detalle'=>'Correo asociado al usuario de Compras'],
        ],
        'Facturas y seguimiento' => [
            ['titulo'=>'Avisos de recepciones', 'ruta'=>'erp_compras_avisos.php', 'detalle'=>'Recepciones notificadas por Bodega y facturas pendientes'], // OCAV_COMPRAS_MENU_V1
            ['titulo'=>'Entradas por factura', 'ruta'=>'erp_facturas.php', 'detalle'=>'Registrar facturas vinculadas a órdenes'],
            ['titulo'=>'Verificación de factura', 'ruta'=>'erp_facturas_verificacion.php', 'detalle'=>'Consultar verificaciones y diferencias'],
            ['titulo'=>'Conciliación OC / Factura', 'ruta'=>'erp_facturas_conciliacion.php', 'detalle'=>'Consultar conciliación de aplicación'],
            ['titulo'=>'Órdenes pendientes de recepción', 'ruta'=>'erp_recepciones_pendientes.php', 'detalle'=>'Ver faltantes y entregas parciales'],
            ['titulo'=>'Historial de recepciones', 'ruta'=>'erp_recepciones_historial.php', 'detalle'=>'Seguimiento de recepciones registradas por Bodega'],
        ],
        'Pagos y seguimiento' => [
            ['titulo'=>'Cuentas por pagar', 'ruta'=>'erp_cuentas_por_pagar.php', 'detalle'=>'Documentos y solicitudes de pago'],
            ['titulo'=>'Tesorería y pagos', 'ruta'=>'erp_tesoreria.php', 'detalle'=>'Consultar y solicitar programación de pagos'],
        ],
    ];
}
function gyd_compras_ruta_permitida(string $ruta): bool {
    static $rutas = [
        'erp_compras_inicio.php'=>true,
        // GYD_COMPRAS_FINANZAS_RUTAS_V1: únicamente páginas de consulta (GET).
        'erp_catalogo_reportes.php'=>true,
        'erp_catalogo_auditoria.php'=>true,
        'erp_catalogo_finanzas.php'=>true,
        'erp_catalogo_historial_costos.php'=>true,
        'erp_catalogo_comparar_proveedores.php'=>true,
        'erp_alertas_compras.php'=>true,
        // GYD_SEGUIMIENTO_COMPRAS_RUTA_V1
        'erp_compras_alertas_seguimiento.php'=>true,
        'erp_ordenes_compra.php'=>true,
        'erp_oc_win.php'=>true,
        'erp_oc_win_enviar.php'=>true,
        'erp_oc_win_pdf.php'=>true,
        'erp_oc_importar.php'=>true,
        'erp_oc_enviar.php'=>true,
        'erp_orden_compra_pdf.php'=>true,
        'erp_oc_autorizaciones.php'=>true,
        'erp_analisis_compras.php'=>true,
        'erp_proveedores.php'=>true,
        'erp_catalogo_auditoria.php'=>true,
        'erp_catalogo_reportes.php'=>true,
        'erp_catalogo_maestro.php'=>true,
        'erp_producto_alta.php'=>true,
        'erp_mi_correo.php'=>true,
        'erp_facturas.php'=>true,
        'erp_facturas_verificacion.php'=>true,
        'erp_facturas_conciliacion.php'=>true,
        'erp_recepciones_pendientes.php'=>true,
        'erp_recepciones_historial.php'=>true,
        'erp_recepcion_registrar.php'=>true, // Lectura permitida; erpr_puede_registrar_destino() deniega POST a Compras.
        'erp_cuentas_por_pagar.php'=>true,
        'erp_cxp_archivo.php'=>true,
        'erp_tesoreria.php'=>true,
        'erp_notificaciones.php'=>true,
        'erp_notificaciones_api.php'=>true,
        'session_keepalive.php'=>true,
        'logout.php'=>true,
    ];
    return isset($rutas[$ruta]);
}
