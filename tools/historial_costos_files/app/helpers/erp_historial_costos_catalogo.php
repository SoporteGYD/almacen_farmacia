<?php
declare(strict_types=1);
/** Historial de documentos de compra. Solo lectura; nunca modifica costos ni existencias. */
function gd_hc_e(mixed $v):string {return htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function gd_hc_tabla(PDO $db,string $t):bool {
    $q=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$t]);return (int)$q->fetchColumn()!==0;
}
function gd_hc_fecha(mixed $s):string {
    if (!is_string($s)||$s==='') return '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$s)) throw new InvalidArgumentException('Formato de fecha inválido.');
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$s);
    if (!$d||$d->format('Y-m-d')!==$s) throw new InvalidArgumentException('Fecha inválida.');
    return $s;
}
function gd_hc_filtros(array $g):array {
    $fuente=(string)($g['fuente']??'FACTURA');
    if(!in_array($fuente,['FACTURA','OC','AMBAS'],true))$fuente='FACTURA';
    $q=trim(is_scalar($g['q']??null)?(string)$g['q']:'');
    $q=function_exists('mb_substr')?mb_substr($q,0,110,'UTF-8'):substr($q,0,110);
    $desde=gd_hc_fecha($g['desde']??(new DateTimeImmutable('today'))->modify('-1 year')->format('Y-m-d'));
    $hasta=gd_hc_fecha($g['hasta']??date('Y-m-d'));
    if($desde!==''&&$hasta!==''&&$desde>$hasta)throw new InvalidArgumentException('La fecha inicial supera la final.');
    $id=filter_var($g['producto_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])?:0;
    $prov=filter_var($g['proveedor']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])?:0;
    $suc=filter_var($g['sucursal']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])?:0;
    $page=filter_var($g['pagina']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:1;
    return ['fuente'=>$fuente,'q'=>$q,'desde'=>$desde,'hasta'=>$hasta,'producto_id'=>(int)$id,'proveedor'=>(int)$prov,'sucursal'=>(int)$suc,'pagina'=>min((int)$page,100000)];
}
/** Devuelve SQL y argumentos de un origen seguro; las cifras nunca mezclan OC con factura sin etiqueta. */
function gd_hc_consulta(array $f,bool $facturas,bool $ordenes,bool $precios):array {
    $parts=[];
    $precioSql=$f['sucursal']>0&&$precios
       ? '(SELECT pp.precio_publico FROM pos_precios pp WHERE pp.producto_id=p.id AND pp.sucursal_id='.(int)$f['sucursal'].' LIMIT 1)'
       : 'p.precio_venta';
    if($facturas&&in_array($f['fuente'],['FACTURA','AMBAS'],true)) {
        $parts[]="SELECT 'FACTURA' origen,d.id linea_id,f.id doc_id,f.fecha_factura fecha,f.creado_en creado_en, COALESCE(NULLIF(f.folio_interno,''),CONCAT('FAC-',f.id)) folio,f.numero_factura referencia,f.estado,
            f.proveedor_id,pr.nombre proveedor,f.sucursal destino,d.producto_id,
            COALESCE(NULLIF(d.codigo_snapshot,''),p.codigo) codigo,COALESCE(NULLIF(d.descripcion_snapshot,''),p.descripcion) descripcion,
            d.cantidad_facturada cantidad,d.costo_unitario costo,p.costo_ultimo costo_ultimo_actual,p.costo_promedio costo_promedio_actual,$precioSql precio_venta_actual
            FROM erp_facturas_detalle d JOIN erp_facturas f ON f.id=d.factura_id
            JOIN proveedores pr ON pr.id=f.proveedor_id LEFT JOIN productos p ON p.id=d.producto_id";
    }
    if($ordenes&&in_array($f['fuente'],['OC','AMBAS'],true)) {
        $parts[]="SELECT 'OC' origen,d.id linea_id,o.id doc_id,o.fecha fecha,o.creado_en creado_en,COALESCE(NULLIF(o.folio,''),CONCAT('OC-',o.id)) folio,
            COALESCE(NULLIF(o.folio,''),CONCAT('OC-',o.id)) referencia,o.estado,o.proveedor_id,pr.nombre proveedor,o.destino,
            d.producto_id,COALESCE(NULLIF(d.codigo_snapshot,''),p.codigo) codigo,COALESCE(NULLIF(d.descripcion_snapshot,''),p.descripcion) descripcion,
            d.cantidad_piezas cantidad,d.costo_unitario costo,p.costo_ultimo costo_ultimo_actual,p.costo_promedio costo_promedio_actual,$precioSql precio_venta_actual
            FROM erp_ordenes_compra_detalle d JOIN erp_ordenes_compra o ON o.id=d.orden_id
            JOIN proveedores pr ON pr.id=o.proveedor_id LEFT JOIN productos p ON p.id=d.producto_id WHERE o.estado='EMITIDA'";
    }
    if(!$parts)return [null,[]];
    $where=[];$params=[];
    if($f['desde']!==''){$where[]='x.fecha>=:desde';$params['desde']=$f['desde'];}
    if($f['hasta']!==''){$where[]='x.fecha<=:hasta';$params['hasta']=$f['hasta'];}
    if($f['proveedor']>0){$where[]='x.proveedor_id=:prov';$params['prov']=$f['proveedor'];}
    if($f['producto_id']>0){$where[]='x.producto_id=:pid';$params['pid']=$f['producto_id'];}
    if($f['q']!==''){
        $where[]='(x.codigo LIKE :qc OR x.descripcion LIKE :qd OR x.referencia LIKE :qr)';
        foreach(['qc','qd','qr'] as $p)$params[$p]='%'.$f['q'].'%';
    }
    return [' FROM ('.implode(' UNION ALL ',$parts).') x'.($where?' WHERE '.implode(' AND ',$where):''),$params];
}
function gd_hc_exec(PDO $db,string $sql,array $args):PDOStatement {
    $s=$db->prepare($sql);foreach($args as $k=>$v)$s->bindValue(':'.$k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
    $s->execute();return $s;
}
function gd_hc_num(mixed $v):?float {return $v===null||$v===''?null:(is_numeric($v)?(float)$v:null);}
function gd_hc_pct(?float $cost,?float $price):?float{return $cost!==null&&$price!==null&&$price>0?100*($price-$cost)/$price:null;}
function gd_hc_mon(mixed $v,int $n=2):string{return $v===null||$v===''?'—':'$'.number_format((float)$v,$n,'.',',');}
function gd_hc_export_text(mixed $v):string {
    $s=(string)($v??'');if(preg_match('/^[\x00-\x20]*[=+\-@]/u',$s))return "'".$s;
    // Los códigos de barras deben permanecer como texto al abrir el CSV en Excel.
    return $s;
}
function gd_hc_datos(array $r):array {
    $cost=gd_hc_num($r['costo']);$precio=gd_hc_num($r['precio_venta_actual']);
    $r['utilidad_teorica']=($precio!==null&&$precio>0&&$cost!==null)?$precio-$cost:null;
    $r['margen_teorico']=gd_hc_pct($cost,$precio);
    $r['markup_teorico']=($precio!==null&&$cost!==null&&$cost>0)?100*($precio-$cost)/$cost:null;
    $r['variacion_ultimo_actual']=($cost!==null && (float)($r['costo_ultimo_actual']??0)>0)?100*($cost/(float)$r['costo_ultimo_actual']-1):null;
    return $r;
}
