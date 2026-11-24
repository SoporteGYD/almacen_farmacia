<?php
declare(strict_types=1);
/** G&D: costos documentados por proveedor y producto. Solo lectura. */
function gd_cp_h(mixed $v):string { return htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function gd_cp_date(string $v):string {
    if ($v==='') return '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v)) throw new InvalidArgumentException('Fecha inválida.');
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
    if (!$d || $d->format('Y-m-d')!==$v) throw new InvalidArgumentException('Fecha inexistente.');
    return $v;
}
function gd_cp_parameters(array $g):array {
    $q=is_scalar($g['q']??null)?trim((string)$g['q']):'';
    $q=function_exists('mb_substr')?mb_substr($q,0,100,'UTF-8'):substr($q,0,100);
    $desde=gd_cp_date(is_string($g['desde']??null)?$g['desde']:date('Y-m-d',strtotime('-12 months')));
    $hasta=gd_cp_date(is_string($g['hasta']??null)?$g['hasta']:date('Y-m-d'));
    if($desde!=='' && $hasta!=='' && $desde>$hasta)throw new InvalidArgumentException('La fecha inicial es posterior a la fecha final.');
    $prov=filter_var($g['proveedor']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
    $branch=filter_var($g['sucursal']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
    $pid=filter_var($g['producto_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
    $page=filter_var($g['pagina']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:1;
    $vigencia=filter_var($g['vigencia']??90,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>3650]])?:90;
    return ['q'=>$q,'desde'=>$desde,'hasta'=>$hasta,'proveedor'=>(int)$prov,'sucursal'=>(int)$branch,
      'producto_id'=>(int)$pid,'pagina'=>(int)$page,'vigencia'=>(int)$vigencia];
}
function gd_cp_table(PDO $db,string $t):bool {
    $s=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $s->execute([$t]);return (int)$s->fetchColumn()>0;
}
function gd_cp_price(mixed $v):?float {return (is_numeric($v) && (float)$v>0)?(float)$v:null;}
function gd_cp_money(mixed $v):string {return $v===null?'—':'$'.number_format((float)$v,4,'.',',');}
function gd_cp_percent(?float $new,?float $previous):?float {
    return $new!==null&&$previous!==null&&$previous>0?100*($new-$previous)/$previous:null;
}
function gd_cp_margin(?float $cost,?float $price):?float {
    return $cost!==null&&$price!==null&&$price>0?100*($price-$cost)/$price:null;
}
function gd_cp_pct_text(?float $v):string {return $v===null?'—':number_format($v,2,'.',',').'%';}
function gd_cp_csv_text(mixed $v):string {
    $s=(string)($v??'');
    if(preg_match('/^[\x00-\x20]*[=+\-@]/u',$s)) return "'".$s;
    return $s;
}
/** Factura documentada. Los proveedores quedan comparados únicamente dentro del periodo seleccionado.
  * Orden por producto y proveedor; las primeras dos filas de cada pareja sirven para última y anterior factura. */
function gd_cp_query(array $f, bool $hasPos):array {
    $price=$f['sucursal']>0&&$hasPos
       ? '(SELECT pp.precio_publico FROM pos_precios pp WHERE pp.producto_id=p.id AND pp.sucursal_id='.(int)$f['sucursal'].' LIMIT 1)'
       : 'p.precio_venta';
    $terms=['d.costo_unitario>=0','d.cantidad_facturada>0'];$args=[];
    if($f['desde']!==''){$terms[]='f.fecha_factura >= :desde';$args['desde']=$f['desde'];}
    if($f['hasta']!==''){$terms[]='f.fecha_factura <= :hasta';$args['hasta']=$f['hasta'];}
    if($f['proveedor']>0){$terms[]='f.proveedor_id=:prov';$args['prov']=$f['proveedor'];}
    if($f['producto_id']>0){$terms[]='d.producto_id=:pid';$args['pid']=$f['producto_id'];}
    if($f['q']!==''){
      $terms[]='(p.codigo LIKE :code OR p.codigo_barras LIKE :barcode OR p.descripcion LIKE :name)';
      $args['code']='%'.$f['q'].'%';$args['barcode']='%'.$f['q'].'%';$args['name']='%'.$f['q'].'%';
    }
    $sql='SELECT d.id detalle_id, d.producto_id, p.codigo,p.codigo_barras,p.descripcion,
        p.costo_ultimo,p.costo_promedio, '.$price.' precio_comparacion,
        f.proveedor_id, pr.nombre proveedor, f.fecha_factura fecha,
        f.numero_factura numero_factura, f.id factura_id, d.costo_unitario costo, d.cantidad_facturada piezas
      FROM erp_facturas_detalle d
      JOIN erp_facturas f ON f.id=d.factura_id
      JOIN productos p ON p.id=d.producto_id
      JOIN proveedores pr ON pr.id=f.proveedor_id
      WHERE '.implode(' AND ',$terms).'
      ORDER BY p.codigo ASC, d.producto_id ASC, f.proveedor_id ASC, f.fecha_factura DESC, f.id DESC, d.id DESC';
    return [$sql,$args];
}
function gd_cp_fetch(PDO $db,string $sql,array $args):PDOStatement {
    $st=$db->prepare($sql);
    foreach($args as $name=>$value)$st->bindValue(':'.$name,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
    $st->execute();return $st;
}
/** Conserva la última y penúltima factura por pareja código/proveedor.
  * No suma precios ni trata OC como compra realizada. */
function gd_cp_group(iterable $rows,int $vigencia,?DateTimeImmutable $now=null):array {
    $now=$now??new DateTimeImmutable('today');$out=[];
    foreach($rows as $r){
      $pid=(int)$r['producto_id'];$prov=(int)$r['proveedor_id'];$key=$pid.'_'.$prov;
      if(!isset($out[$pid]))$out[$pid]=['producto_id'=>$pid,'codigo'=>(string)$r['codigo'],
        'codigo_barras'=>(string)$r['codigo_barras'],'descripcion'=>(string)$r['descripcion'],
        'ultimo_actual'=>gd_cp_price($r['costo_ultimo']),
        'promedio_actual'=>gd_cp_price($r['costo_promedio']),
        'precio'=>gd_cp_price($r['precio_comparacion']),'proveedores'=>[]];
      if(!isset($out[$pid]['proveedores'][$prov])){
        $ultimo=gd_cp_price($r['costo']);
        $fecha=(string)$r['fecha'];$dia=DateTimeImmutable::createFromFormat('!Y-m-d',$fecha);
        $dias=$dia?(int)$dia->diff($now)->format('%r%a'):null;
        $antiguo=$dias===null||$dias>$vigencia;
        $out[$pid]['proveedores'][$prov]=['id'=>$prov,'factura_id'=>(int)$r['factura_id'],'proveedor'=>(string)$r['proveedor'],
          'costo'=>$ultimo,'fecha'=>$fecha,'factura'=>(string)$r['numero_factura'],
          'piezas'=>(int)$r['piezas'],'anterior'=>null,'fecha_anterior'=>null,'anterior_registrado'=>false,
          'dias'=>$dias,'antiguo'=>$antiguo,'riesgo'=>($dias!==null&&$dias<0)];
      }else{
        $item=&$out[$pid]['proveedores'][$prov];
        if(!$item['anterior_registrado'] && (int)$r['factura_id']!==$item['factura_id']){
           $item['anterior']=gd_cp_price($r['costo']);$item['fecha_anterior']=(string)$r['fecha'];$item['anterior_registrado']=true;
        }
        unset($item);
      }
    }
    foreach($out as &$p){
      $best=null;$worst=null;$bestId=null;
      foreach($p['proveedores'] as $prov=>$d){
        if($d['costo']===null || $d['antiguo'] || $d['riesgo'])continue;
        if($best===null || $d['costo']<$best){$best=$d['costo'];$bestId=$prov;}
        if($worst===null || $d['costo']>$worst)$worst=$d['costo'];
      }
      $p['mejor_costo_vigente']=$best;
      $p['mejor_proveedor_id']=$bestId;
      $p['mayor_costo_vigente']=$worst;
      $p['diferencia_observada']=($best!==null&&$worst!==null&&count($p['proveedores'])>1)?$worst-$best:null;
      $p['hay_comparacion']=count(array_filter($p['proveedores'],static fn($d)=>$d['costo']!==null&&!$d['antiguo']&&!$d['riesgo']))>1;
    }
    unset($p);
    uasort($out,static fn($a,$b)=>strcmp($a['codigo'],$b['codigo']));
    return $out;
}
