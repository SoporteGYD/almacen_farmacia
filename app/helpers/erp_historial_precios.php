<?php
declare(strict_types=1);
/** Consulta SOLO LECTURA del registro del modal de cambios de precios G&D. */
function hp_esc($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function hp_fecha($v): ?string {
    if ($v === null || $v === '') return null;
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v)) throw new InvalidArgumentException('Fecha incorrecta.');
    $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
    if (!$dt || $dt->format('Y-m-d')!==$v) throw new InvalidArgumentException('Fecha incorrecta.');
    return $v;
}
function hp_filtros(array $get): array {
    $desde=hp_fecha($get['desde'] ?? null); $hasta=hp_fecha($get['hasta'] ?? null);
    if ($desde && $hasta && $desde>$hasta) throw new InvalidArgumentException('La fecha inicial es posterior a la final.');
    $tipo=(string)($get['tipo']??'TODOS');
    if(!in_array($tipo,['TODOS','APLICADO','OMITIDO'],true)) $tipo='TODOS';
    $sid=filter_var($get['sucursal']??'0',FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]) ?: 0;
    $uid=filter_var($get['usuario']??'0',FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]) ?: 0;
    $pag=filter_var($get['pag']??'1',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 1;
    $busqueda=$get['q']??''; $busqueda=is_scalar($busqueda)?trim((string)$busqueda):'';
    $q=function_exists('mb_substr')?mb_substr($busqueda,0,90,'UTF-8'):substr($busqueda,0,90);
    return ['desde'=>$desde,'hasta'=>$hasta,'tipo'=>$tipo,'sucursal'=>(int)$sid,'usuario'=>(int)$uid,'pag'=>min($pag,100000),'q'=>$q];
}
function hp_tabla_existe(PDO $db,string $tabla):bool {
    // Nombre sólo de código propio, nunca entrada del navegador.
    $st=$db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
    $st->execute([$tabla]); return (bool)$st->fetchColumn();
}
function hp_consulta(array $f,bool $tieneOmisiones):array {
    $base="SELECT 'APLICADO' tipo, o.id registro_id, o.creado_en, p.codigo, p.descripcion,
      s.nombre sucursal, d.sucursal_id, u.nombre responsable, o.usuario_id, o.factura_id,
      o.motivo, o.costo_antes, o.costo_factura, d.precio_anterior, d.precio_nuevo,
      o.modo, o.calculo, o.producto_id
      FROM erp_precio_operaciones o
      JOIN erp_precio_operacion_detalle d ON d.operacion_id=o.id
      LEFT JOIN productos p ON p.id=o.producto_id
      LEFT JOIN pos_sucursales s ON s.id=d.sucursal_id
      LEFT JOIN usuarios u ON u.id=o.usuario_id";
    if($tieneOmisiones){
       $base.=" UNION ALL SELECT 'OMITIDO' tipo, om.id registro_id, om.creado_en, p.codigo, p.descripcion,
       NULL sucursal, NULL sucursal_id, u.nombre responsable, om.usuario_id, om.factura_id,
       om.motivo, om.costo_referencia costo_antes, om.costo_factura, NULL precio_anterior, NULL precio_nuevo,
       NULL modo, NULL calculo, om.producto_id
       FROM erp_precio_omisiones om
       LEFT JOIN productos p ON p.id=om.producto_id
       LEFT JOIN usuarios u ON u.id=om.usuario_id";
    }
    $w=[];$args=[];
    if($f['desde']){$w[]='h.creado_en >= :desde';$args['desde']=$f['desde'].' 00:00:00';}
    if($f['hasta']){$w[]='h.creado_en < DATE_ADD(:hasta,INTERVAL 1 DAY)';$args['hasta']=$f['hasta'].' 00:00:00';}
    if($f['tipo']!=='TODOS'){$w[]='h.tipo = :tipo';$args['tipo']=$f['tipo'];}
    if($f['sucursal']){$w[]='h.sucursal_id = :sucursal';$args['sucursal']=$f['sucursal'];}
    if($f['usuario']){$w[]='h.usuario_id = :usuario';$args['usuario']=$f['usuario'];}
    if($f['q']!==''){
       $w[]='(h.codigo LIKE :q_codigo OR h.descripcion LIKE :q_desc OR h.motivo LIKE :q_motivo)';
       $needle='%'.$f['q'].'%'; $args['q_codigo']=$needle;$args['q_desc']=$needle;$args['q_motivo']=$needle;
    }
    $from=' FROM ('.$base.') h '.($w?'WHERE '.implode(' AND ',$w):'');
    return [$from,$args];
}
function hp_ejecutar(PDO $db,string $sql,array $args):PDOStatement {
    $st=$db->prepare($sql);
    foreach($args as $key=>$value)$st->bindValue(':'.$key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
    $st->execute();return $st;
}
function hp_csv_seguro($v):string{
    $s=(string)($v??'');
    // Excel podría interpretar valores externos como fórmulas; escape incluso con espacios iniciales.
    if(preg_match('/^[\x00-\x20]*[=+\-@]/u',$s) || preg_match('/^[\t\r\n]/',$s))return "'".$s;
    return $s;
}
function hp_formato_importe($x,int $dec=2):string{
    return $x===null || $x==='' ? '—' : '$'.number_format((float)$x,$dec,'.',',');
}
