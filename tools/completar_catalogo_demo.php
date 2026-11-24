<?php
declare(strict_types=1);
/**
 * G&D laboratorio 2026-10-23 - costos/precios del catálogo DEMO.
 * Usage:
 *   php tools/completar_catalogo_demo.php --check
 *   php tools/completar_catalogo_demo.php --apply --confirmar-prueba
 *   php tools/completar_catalogo_demo.php --report
 * DESARROLLO: nunca ejecutar sobre BD operativa.
 */
if (PHP_SAPI!=='cli') { http_response_code(403);exit('Solo CLI'); }
$action=$argv[1]??'--check';
if (!in_array($action,['--check','--apply','--report'],true)) {
    fwrite(STDERR,"Uso: --check | --apply --confirmar-prueba | --report\n");exit(2);
}
$root=dirname(__DIR__);
$config=$root.'/app/config/database.php';
$sqlPath=__DIR__.'/../sql/02_COMPLETAR_CATALOGO_DEMO.sql';
if (!is_file($config)||!is_file($sqlPath)) {
    fwrite(STDERR,"INCOMPATIBLE: falta app/config/database.php o el SQL. Copia tools y sql en el directorio del ERP.\n");exit(2);
}
require_once $config;
try {
    $pdo=(new Database())->connect();
    $dbName=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $tables=['productos','categorias','proveedores','usuarios','erp_producto_ficha','erp_producto_proveedores','pos_sucursales','pos_precios','pos_precios_mayoreo','pos_presentaciones','pos_precios_historial','erp_ordenes_compra','erp_ordenes_compra_detalle','erp_oc_recepciones','erp_oc_recepciones_detalle','erp_facturas','erp_facturas_detalle'];
    $columns=[
       'productos'=>['id','codigo','codigo_barras','descripcion','categoria_id','proveedor_id','laboratorio','unidad_medida','precio_compra','costo_ultimo','costo_promedio','precio_venta'],
       'erp_producto_ficha'=>['producto_id','iva_compra','iva_venta','ieps','margen_sugerido','precio_sugerido','descuento_1','costo_flete_unitario'],
       'pos_precios'=>['id','sucursal_id','producto_id','precio_publico'],
       'pos_precios_mayoreo'=>['id','sucursal_id','producto_id','precio_mayoreo'],
       'pos_presentaciones'=>['sucursal_id','producto_id','piezas_por_caja','precio_caja'],
       'pos_precios_historial'=>['sucursal_id','producto_id','motivo','usuario_id'],
    ];
    $hasTable=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $hasCol=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $problems=[];
    foreach ($tables as $table) {
        $hasTable->execute([$table]);
        if (!(int)$hasTable->fetchColumn()){$problems[]="Falta tabla: $table";}
    }
    foreach ($columns as $table=>$cols) {
        foreach($cols as $col) {
            $hasCol->execute([$table,$col]);
            if (!(int)$hasCol->fetchColumn())$problems[]="Falta columna: $table.$col";
        }
    }
    if ($dbName!=='prueba' && $dbName!=='prueba_erp_lab_v3') {
        $problems[]="Base '$dbName' NO AUTORIZADA. Solo se permite prueba o prueba_erp_lab_v3.";
    }
    if (!$problems) {
        $admins=(int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol='ADMINISTRADOR' AND estado=1")->fetchColumn();
        $providers=(int)$pdo->query("SELECT COUNT(*) FROM proveedores WHERE estado=1 AND UPPER(nombre) NOT LIKE '%TRASPASO%'")->fetchColumn();
        $categories=(int)$pdo->query('SELECT COUNT(*) FROM categorias')->fetchColumn();
        $branches=(int)$pdo->query('SELECT COUNT(*) FROM pos_sucursales WHERE activo=1')->fetchColumn();
        if (!$admins)$problems[]='Sin ADMINISTRADOR activo para la referencia del historial.';
        if (!$providers)$problems[]='Sin proveedores activos distintos de traspaso.';
        if (!$categories)$problems[]='Sin categorias en catálogo.';
        if (!$branches)$problems[]='Sin sucursales activas.';
    }
    echo "BASE SELECCIONADA: {$dbName}\n";
    if($problems) {foreach($problems as $p)echo "INCOMPATIBLE: $p\n";exit(3);}
    echo "COMPATIBLE: esquema y llaves base detectados.\n";
    $stats=[
        'Productos totales'=>'SELECT COUNT(*) FROM productos',
        'Sin ultimo costo'=>'SELECT COUNT(*) FROM productos WHERE costo_ultimo<=0',
        'Sin promedio'=>'SELECT COUNT(*) FROM productos WHERE costo_promedio<=0',
        'Sin precio base'=>'SELECT COUNT(*) FROM productos WHERE precio_venta<=0',
        'Sin proveedor o categoria'=>'SELECT COUNT(*) FROM productos WHERE proveedor_id IS NULL OR categoria_id IS NULL',
        'Sin ficha'=>'SELECT COUNT(*) FROM productos p LEFT JOIN erp_producto_ficha f ON f.producto_id=p.id WHERE f.producto_id IS NULL',
        'Fichas con IVA/IEPS pendiente'=>'SELECT COUNT(*) FROM erp_producto_ficha WHERE iva_compra IS NULL OR iva_venta IS NULL OR ieps IS NULL',
        'Precios por sucursal'=>'SELECT COUNT(*) FROM pos_precios',
        'Mayoreo por sucursal'=>'SELECT COUNT(*) FROM pos_precios_mayoreo',
        'Presentaciones'=>'SELECT COUNT(*) FROM pos_presentaciones',
        'OC emitidas/borrador (no es saldo)'=>'SELECT COUNT(*) FROM erp_ordenes_compra WHERE estado IN (\'EMITIDA\',\'BORRADOR\')',
        'Facturas sin verificar'=>'SELECT COUNT(*) FROM erp_facturas WHERE estado<>\'VERIFICADA\'',
        'Lineas OC con saldo sin recibir'=>"SELECT COUNT(*) FROM (SELECT d.id,d.cantidad_piezas-COALESCE(r.recibidas,0) saldo FROM erp_ordenes_compra_detalle d JOIN erp_ordenes_compra o ON o.id=d.orden_id LEFT JOIN (SELECT rd.orden_detalle_id,SUM(rd.cantidad_piezas) AS recibidas FROM erp_oc_recepciones_detalle rd JOIN erp_oc_recepciones rr ON rr.id=rd.recepcion_id AND rr.estado='REGISTRADA' GROUP BY rd.orden_detalle_id) r ON r.orden_detalle_id=d.id WHERE o.estado='EMITIDA') x WHERE x.saldo>0",
    ];
    $show=function()use($pdo,$stats):void { foreach($stats as $label=>$query) {
        echo str_pad($label,34).' '.(int)$pdo->query($query)->fetchColumn()."\n";
    }};
    if($action!=='--apply'){$show(); exit(0);}
    if(!in_array('--confirmar-prueba',$argv,true)) {
        fwrite(STDERR,"ABORTADO: para modificar datos es obligatorio --confirmar-prueba.\n");exit(4);
    }
    echo "INICIANDO DEMO. Este proceso sobreescribe precios base y precios POS actuales de '{$dbName}'.\n";
    $sql=file_get_contents($sqlPath);
    $sql=preg_replace('/^[ \t]*--[^\r\n]*(\r?\n|$)/m','',(string)$sql);
    $parts=preg_split('/;[ \t]*(?:\r?\n|$)/',(string)$sql);
    $parts=array_values(array_filter(array_map('trim',$parts),static fn($x)=>$x!==''));
    $pdo->beginTransaction();
    try {
       foreach($parts as $i=>$statement) {
           $pdo->exec($statement);
           echo 'OK paso '.($i+1).'/'.count($parts)."\n";
       }
       $pdo->commit();
    } catch (Throwable $e) {
       if($pdo->inTransaction())$pdo->rollBack();
       throw $e;
    }
    echo "APLICADO - datos DEMO. NO SE HAN CAMBIADO EXISTENCIAS, FACTURAS NI OC.\n";
    $show();
} catch(Throwable $e) {
    fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1);
}
