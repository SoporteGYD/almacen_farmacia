<?php
declare(strict_types=1);
/** Auditoría SOLO LECTURA: un registro por producto con todas sus observaciones. */
function gyd_ac_h($v):string{return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');}
function gyd_ac_ficha(PDO $db):bool{
    $st=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute(['erp_producto_ficha']); return (int)$st->fetchColumn()>0;
}
function gyd_ac_reglas(bool $ficha,bool $costos):array{
    // Todos los fragmentos SQL están codificados aquí, jamás proceden del navegador.
    $r=[
      'sin_codigo'=>['Código principal faltante',"(p.codigo IS NULL OR TRIM(p.codigo)='')",'alta'],
      'codigo_repetido'=>['Código principal duplicado','(COALESCE(dc.total,0)>1)','alta'],
      'barras_repetido'=>['Código de barras duplicado','(COALESCE(db.total,0)>1)','alta'],
      'nombre_repetido'=>['Descripción repetida: verificar presentaciones','(COALESCE(dn.total,0)>1)','revision'],
      'sin_barras'=>['Sin código de barras',"(p.codigo_barras IS NULL OR TRIM(p.codigo_barras)='')",'revision'],
      'sin_laboratorio'=>['Sin laboratorio',"(p.laboratorio IS NULL OR TRIM(p.laboratorio)='')",'revision'],
      'sin_categoria'=>['Sin categoría válida','(c.id IS NULL)','revision'],
      'sin_proveedor'=>['Sin proveedor válido','(pr.id IS NULL)','revision'],
      'sin_unidad'=>['Sin unidad de medida',"(p.unidad_medida IS NULL OR TRIM(p.unidad_medida)='')",'revision'],
    ];
    if($ficha){
        $r['sin_ficha']=['Sin ficha fiscal','(f.producto_id IS NULL)','revision'];
        $r['sin_iva_compra']=['IVA de compra por configurar','(f.iva_compra IS NULL)','revision'];
        $r['sin_iva_venta']=['IVA de venta por configurar','(f.iva_venta IS NULL)','revision'];
        $r['ieps_revisar']=['IEPS pendiente de definir o confirmar no aplicación','(f.ieps IS NULL)','informativa'];
    }
    if($costos){
        $r['sin_ultimo']=['Último costo sin referencia positiva','(p.costo_ultimo IS NULL OR p.costo_ultimo<=0)','revision'];
        $r['sin_promedio']=['Costo promedio sin referencia positiva','(p.costo_promedio IS NULL OR p.costo_promedio<=0)','revision'];
        $r['variacion_costos']=['Diferencia entre último y promedio ≥ 20%',
        '(p.costo_ultimo>0 AND p.costo_promedio>0 AND ABS(p.costo_ultimo-p.costo_promedio)/p.costo_promedio>=0.20)','revision'];
    }
    return $r;
}
function gyd_ac_from(bool $ficha):string{
    return ' FROM productos p
      LEFT JOIN categorias c ON c.id=p.categoria_id
      LEFT JOIN proveedores pr ON pr.id=p.proveedor_id
      '.($ficha?'LEFT JOIN erp_producto_ficha f ON f.producto_id=p.id':'').'
      LEFT JOIN (SELECT UPPER(TRIM(codigo)) AS clave, COUNT(*) AS total FROM productos
          WHERE codigo IS NOT NULL AND TRIM(codigo)<>\'\'
          GROUP BY UPPER(TRIM(codigo)) HAVING COUNT(*)>1) dc ON dc.clave=UPPER(TRIM(p.codigo))
      LEFT JOIN (SELECT UPPER(TRIM(codigo_barras)) AS clave, COUNT(*) AS total FROM productos
          WHERE codigo_barras IS NOT NULL AND TRIM(codigo_barras)<>\'\'
          GROUP BY UPPER(TRIM(codigo_barras)) HAVING COUNT(*)>1) db ON db.clave=UPPER(TRIM(p.codigo_barras))
      LEFT JOIN (SELECT UPPER(TRIM(descripcion)) AS clave, COUNT(*) AS total FROM productos
          WHERE descripcion IS NOT NULL AND TRIM(descripcion)<>\'\'
          GROUP BY UPPER(TRIM(descripcion)) HAVING COUNT(*)>1) dn ON dn.clave=UPPER(TRIM(p.descripcion))';
}
function gyd_ac_filtros(array $request,array $reglas):array{
    $tipo=(string)($request['tipo']??'todos');if($tipo!=='todos'&&!isset($reglas[$tipo]))$tipo='todos';
    $estado=(string)($request['estado']??'activos');if(!in_array($estado,['todos','activos','inactivos'],true))$estado='activos';
    $q=trim((string)($request['q']??''));$q=function_exists('mb_substr')?mb_substr($q,0,120):substr($q,0,120);
    $pagina=max(1,min(1000000,(int)($request['pagina']??1)));
    return ['tipo'=>$tipo,'estado'=>$estado,'q'=>$q,'pagina'=>$pagina];
}
function gyd_ac_where(array $f,array $r):array{
    $conds=[];$args=[];
    if($f['tipo']==='todos')$conds[]='('.implode(' OR ',array_map(static fn($row)=>$row[1],array_values($r))).')';
    else $conds[]=$r[$f['tipo']][1];
    if($f['estado']==='activos')$conds[]='p.estado=1';
    if($f['estado']==='inactivos')$conds[]='p.estado<>1';
    if($f['q']!==''){
        $conds[]='(p.codigo LIKE :q1 OR p.codigo_barras LIKE :q2 OR p.descripcion LIKE :q3 OR p.laboratorio LIKE :q4)';
        foreach(['q1','q2','q3','q4'] as $k)$args[$k]='%'.$f['q'].'%';
    }
    return [' WHERE '.implode(' AND ',$conds),$args];
}
function gyd_ac_sql_rows(array $r,bool $ficha,bool $costos):string{
    $cols=['p.id','p.codigo','p.codigo_barras','p.descripcion','p.laboratorio','p.estado', 'c.nombre AS categoria','pr.nombre AS proveedor'];
    if($costos){$cols[]='p.costo_ultimo';$cols[]='p.costo_promedio';}
    if($ficha){$cols[]='f.iva_compra';$cols[]='f.iva_venta';$cols[]='f.ieps';}
    foreach($r as $id=>$info)$cols[]='CASE WHEN '.$info[1].' THEN 1 ELSE 0 END AS `'.$id.'`';
    return 'SELECT '.implode(', ',$cols).gyd_ac_from($ficha);
}
function gyd_ac_totales(PDO $db,array $reglas,bool $ficha,array $filtro):array{
    // Resumen sobre el mismo universo filtrado por estado y texto; independientes del filtro de incidencia.
    $rango=$filtro;$rango['tipo']='todos';
    [$where,$params]=gyd_ac_where($rango,$reglas);
    $lista=['COUNT(*) AS productos_con_observaciones'];
    foreach($reglas as $id=>$regla)$lista[]='COALESCE(SUM(CASE WHEN '.$regla[1].' THEN 1 ELSE 0 END),0) AS `'.$id.'`';
    $st=$db->prepare('SELECT '.implode(',',$lista).gyd_ac_from($ficha).$where);
    $st->execute($params);
    return $st->fetch(PDO::FETCH_ASSOC)?:[];
}
function gyd_ac_lotes(PDO $db,array $reglas,bool $ficha,bool $costos,array $filtro,?int $limite,int $offset=0):PDOStatement{
    [$where,$params]=gyd_ac_where($filtro,$reglas);
    $sql=gyd_ac_sql_rows($reglas,$ficha,$costos).$where.' ORDER BY p.descripcion ASC,p.id ASC';
    if($limite!==null)$sql.=' LIMIT '.max(1,$limite).' OFFSET '.max(0,$offset);
    $st=$db->prepare($sql);$st->execute($params);return $st;
}
function gyd_ac_contar(PDO $db,array $reglas,bool $ficha,array $filtro):int{
    [$where,$params]=gyd_ac_where($filtro,$reglas);
    $st=$db->prepare('SELECT COUNT(*)'.gyd_ac_from($ficha).$where);$st->execute($params);return (int)$st->fetchColumn();
}
function gyd_ac_incidencias(array $fila,array $reglas):array{
    $res=[];foreach($reglas as $id=>$r)if((int)($fila[$id]??0)===1)$res[]=['nombre'=>$r[0],'nivel'=>$r[2]];return $res;
}
