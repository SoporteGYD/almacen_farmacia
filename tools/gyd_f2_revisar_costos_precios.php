<?php
declare(strict_types=1);
/** F2 / Farmacia G&D. Diagnóstico SQL SOLO LECTURA; base de laboratorio obligatoria. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo CLI.'); }
function f2v31_arg(array $args, string $key): ?string {
    foreach ($args as $a) if (str_starts_with($a, $key.'=')) return substr($a, strlen($key)+1);
    return null;
}
$args=$argv??[];
if (!in_array('--run',$args,true)) {
    echo "Uso: php tools/gyd_f2_revisar_costos_precios.php --run --expect=prueba --port=3307 [--json]\n";
    exit(1);
}
if (f2v31_arg($args,'--expect')!=='prueba' || f2v31_arg($args,'--port')!=='3307') {
    fwrite(STDERR,"BLOQUEADO: requiere --expect=prueba --port=3307.\n");exit(2);
}
require_once __DIR__.'/../app/config/database.php';
try {
    $db=(new Database())->connect();
    $conn=$db->query('SELECT DATABASE() AS bd, @@port AS puerto')->fetch(PDO::FETCH_ASSOC)?:[];
    if (($conn['bd']??'')!=='prueba'||(int)($conn['puerto']??0)!==3307) {
        throw new RuntimeException('La conexión no corresponde a prueba:3307.');
    }
    // Exclusivamente metadatos y SELECT. No se crean tablas ni se modifica ningún registro.
    $tablas=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('productos','pos_sucursales','pos_precios','erp_producto_ficha','gyd_tt_pool_config')")->fetchAll(PDO::FETCH_COLUMN);
    if(!in_array('productos',$tablas,true))throw new RuntimeException('No existe productos.');
    $datos=$db->query("SELECT
        COUNT(*) AS total_productos,
        COALESCE(SUM(estado=1),0) AS activos,
        COALESCE(SUM(estado=1 AND (costo_ultimo IS NULL OR costo_ultimo<=0)),0) AS activos_sin_costo_ultimo_positivo,
        COALESCE(SUM(estado=1 AND (costo_promedio IS NULL OR costo_promedio<=0)),0) AS activos_sin_costo_promedio_positivo,
        COALESCE(SUM(estado=1 AND (precio_compra IS NULL OR precio_compra<=0)),0) AS activos_sin_costo_referencia_positivo,
        COALESCE(SUM(estado=1 AND (precio_venta IS NULL OR precio_venta<=0)),0) AS activos_sin_precio_base_positivo,
        COALESCE(SUM(estado=1 AND precio_venta>0 AND costo_ultimo>0 AND precio_venta<costo_ultimo),0) AS precio_base_debajo_ultimo_costo,
        COALESCE(SUM(costo_ultimo<0 OR costo_promedio<0 OR precio_compra<0 OR precio_venta<0),0) AS productos_con_valores_negativos,
        COALESCE(SUM(estado=1 AND costo_ultimo>0 AND costo_promedio>0 AND costo_ultimo>costo_promedio*1.5),0) AS ultimo_costo_mas_50pct_sobre_promedio
      FROM productos")->fetch(PDO::FETCH_ASSOC)?:[];
    $sucursales=[];$casos=[];$fichas=[];
    if(in_array('pos_sucursales',$tablas,true)&&in_array('pos_precios',$tablas,true)){
        $sucursales=$db->query("SELECT s.id AS sucursal_id,s.nombre AS sucursal,
            COUNT(p.id) AS productos_activos_en_catalogo,
            COALESCE(SUM(pp.producto_id IS NOT NULL AND pp.precio_publico>0),0) AS con_precio_positivo,
            COALESCE(SUM(pp.producto_id IS NULL OR pp.precio_publico IS NULL OR pp.precio_publico<=0),0) AS sin_precio_pos_positivo,
            COALESCE(SUM(pp.precio_publico>0 AND p.costo_ultimo>0 AND pp.precio_publico<p.costo_ultimo),0) AS precio_pos_debajo_ultimo_costo
           FROM pos_sucursales s CROSS JOIN productos p
           LEFT JOIN pos_precios pp ON pp.sucursal_id=s.id AND pp.producto_id=p.id
           WHERE p.estado=1 GROUP BY s.id,s.nombre ORDER BY s.id")->fetchAll(PDO::FETCH_ASSOC);
        $casos=$db->query("SELECT s.nombre AS sucursal,p.id,p.codigo,p.descripcion,
           p.costo_ultimo,pp.precio_publico,
           ROUND(p.costo_ultimo-pp.precio_publico,4) AS diferencia
           FROM pos_precios pp JOIN productos p ON p.id=pp.producto_id
           JOIN pos_sucursales s ON s.id=pp.sucursal_id
           WHERE p.estado=1 AND pp.precio_publico>0 AND p.costo_ultimo>0
             AND pp.precio_publico<p.costo_ultimo
           ORDER BY diferencia DESC,p.id,s.id LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
    }
    if(in_array('erp_producto_ficha',$tablas,true)){
        $fichas=$db->query("SELECT COUNT(*) AS fichas,
          COALESCE(SUM(factor_compra IS NULL OR factor_compra<=0),0) AS factor_compra_no_positivo,
          COALESCE(SUM(iva_compra IS NULL),0) AS fichas_sin_iva_compra,
          COALESCE(SUM(iva_venta IS NULL),0) AS fichas_sin_iva_venta
          FROM erp_producto_ficha")->fetch(PDO::FETCH_ASSOC)?:[];
    }
    $pool=null;
    if(in_array('gyd_tt_pool_config',$tablas,true)){
        $pool=(int)$db->query('SELECT COALESCE(MAX(activo),0) FROM gyd_tt_pool_config WHERE id=1')->fetchColumn()===1;
    }
    $res=[
       'modo'=>'SOLO_LECTURA','base'=>'prueba','puerto'=>3307,
       'totales_costos'=>$datos,
       'precios_por_sucursal'=>$sucursales,
       'muestras_precio_pos_inferior_al_ultimo_costo'=>$casos,
       'fichas_compra_e_impuestos'=>$fichas,
       'inventario_compartido_tapachula_toscana_activo'=>$pool,
       'advertencias'=>[
        'Sin precio POS significa que no hay precio positivo para cada producto activo en esa sucursal; puede ser deliberado si no se vende allí.',
        'Precio inferior al último costo es una alerta para revisar, no demuestra una pérdida neta: deben validarse descuentos, IVA, IEPS y unidad de costo.',
        'El inventario POS de Tapachula y Toscana es un saldo único cuando la configuración compartida está activa. No sumarlo dos veces.',
        'No se han ejecutado UPDATE, INSERT ni DELETE.'
       ]
    ];
    if(in_array('--json',$args,true)){
       echo json_encode($res,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
    }else{
       echo "REVISION F2 - COSTOS Y PRECIOS (SOLO LECTURA)\nBase: prueba | Puerto: 3307\n\n";
       foreach($datos as $k=>$v)echo str_pad($k,44).": $v\n";
       echo "\nPRECIOS POS POR SUCURSAL\n";
       foreach($sucursales as $r){
          echo sprintf('%s [ID %s]: %s con precio / %s sin precio positivo / %s bajo último costo',
            $r['sucursal'],$r['sucursal_id'],$r['con_precio_positivo'],$r['sin_precio_pos_positivo'],$r['precio_pos_debajo_ultimo_costo']).PHP_EOL;
       }
       if(!$sucursales)echo "No existen las dos tablas necesarias para evaluar precios POS.\n";
       echo "\nFicha compra/IVA: ".($fichas?json_encode($fichas,JSON_UNESCAPED_UNICODE):'No disponible').PHP_EOL;
       echo 'Inventario compartido Tapachula/Toscana: '.($pool===null?'No comprobado':($pool?'ACTIVO':'INACTIVO')).PHP_EOL;
       echo 'Muestras de precio POS bajo último costo: '.count($casos).' (ver --json para el detalle)'.PHP_EOL;
       echo "AVISO: sin precio POS no implica un fallo si el producto no se comercializa allí. Sin modificaciones SQL.\n";
    }
}catch(Throwable $e){fwrite(STDERR,'NO SE COMPLETO: '.$e->getMessage().PHP_EOL);exit(3);}
