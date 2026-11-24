<?php
declare(strict_types=1);
/** Verificador COMPARTIDO por Compras, Productos de Almacén y editores del ERP.
 * No modifica stock y no crea productos. Para importaciones externas, ejecutar igualmente este verificador.
 */
function gyd_normalizar_nombre(string $nombre): string {
    $nombre=trim(preg_replace('/\s+/u',' ', $nombre) ?? $nombre);
    $nombre=strtr($nombre,['á'=>'A','é'=>'E','í'=>'I','ó'=>'O','ú'=>'U','ü'=>'U','à'=>'A','è'=>'E','ì'=>'I','ò'=>'O','ù'=>'U','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','À'=>'A','È'=>'E','Ì'=>'I','Ò'=>'O','Ù'=>'U']);
    return function_exists('mb_strtoupper') ? mb_strtoupper($nombre,'UTF-8') : strtoupper($nombre);
}
function gyd_normalizar_codigo(string $codigo): string {
    return function_exists('mb_strtoupper') ? mb_strtoupper(trim($codigo),'UTF-8') : strtoupper(trim($codigo));
}
/**
 * @return array|null coincidencia {id, codigo, descripcion, motivo}; incluye inactivos.
 * Identifica repetidos cruzados codigo ↔ codigo_barras y equivalencias registradas.
 */
function gyd_producto_duplicado(PDO $pdo,string $codigo,string $barras,string $descripcion,?int $excluir=null):?array {
    $codigo=gyd_normalizar_codigo($codigo);
    $barras=gyd_normalizar_codigo($barras);
    $desc=gyd_normalizar_nombre($descripcion);
    $st=$pdo->query('SELECT id,codigo,codigo_barras,descripcion,estado FROM productos');
    while($p=$st->fetch(PDO::FETCH_ASSOC)) {
        if($excluir!==null && (int)$p['id']===$excluir)continue;
        $ca=gyd_normalizar_codigo((string)$p['codigo']);
        $ba=gyd_normalizar_codigo((string)($p['codigo_barras']??''));
        $motivo='';
        if($codigo!=='' && ($codigo===$ca || $codigo===$ba))$motivo='código principal';
        elseif($barras!=='' && ($barras===$ca || $barras===$ba))$motivo='código de barras';
        elseif($desc!=='' && $desc===gyd_normalizar_nombre((string)$p['descripcion']))$motivo='descripción';
        if($motivo!=='')return ['id'=>(int)$p['id'],'codigo'=>(string)$p['codigo'],'descripcion'=>(string)$p['descripcion'],'motivo'=>$motivo,'activo'=>(int)$p['estado']];
    }
    // Si la migración de equivalencias aún no se instaló, no interrumpir el módulo de almacén.
    try {
        $st=$pdo->query('SELECT e.producto_id,e.codigo AS equivalente,p.codigo,p.descripcion,p.estado FROM erp_producto_equivalencias e JOIN productos p ON p.id=e.producto_id');
        while($e=$st->fetch(PDO::FETCH_ASSOC)) {
            if($excluir!==null && (int)$e['producto_id']===$excluir)continue;
            if(in_array(gyd_normalizar_codigo((string)$e['equivalente']),array_filter([$codigo,$barras],fn($x)=>$x!==''),true))
                return ['id'=>(int)$e['producto_id'],'codigo'=>(string)$e['codigo'],'descripcion'=>(string)$e['descripcion'],'motivo'=>'código equivalente','activo'=>(int)$e['estado']];
        }
    }catch(PDOException $e){ if(!in_array((string)$e->getCode(),['42S02','42S22'],true))throw $e; }
    return null;
}
function gyd_duplicado_mensaje(array $p):string {
    return 'Ya existe en el catálogo compartido el producto #'.$p['id'].' · '. $p['codigo'].' · '.$p['descripcion'].' (coincide la '.$p['motivo'].'). No se creó ningún duplicado.';
}
