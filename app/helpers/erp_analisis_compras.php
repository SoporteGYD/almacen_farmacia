<?php
declare(strict_types=1);
/** ERP G&D: análisis de compras, SOLO LECTURA. No escribe a BD. PHP 8+. */
function ac_e(mixed $v): string {return htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function ac_rol(array $u):string{return strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));}
function ac_autorizado(array $u):bool{return in_array(ac_rol($u),['ADMIN','ADMINISTRADOR','DIRECCION','COMPRAS'],true);}
function ac_fecha(mixed $v):string {
 if(!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v)) throw new DomainException('La fecha debe tener formato AAAA-MM-DD.');
 $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
 if(!$d||$d->format('Y-m-d')!==$v)throw new DomainException('Fecha no válida.');
 return $v;
}
function ac_int(mixed $v,int $default=0):int{
 if($v===null||$v==='')return $default;
 $n=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>1000000000]]);
 if($n===false)throw new DomainException('Un filtro de números no es válido.');return (int)$n;
}
function ac_filtros(array $get,?DateTimeImmutable $hoy=null):array{
 $hoy=$hoy??new DateTimeImmutable('today');
 $fin=ac_fecha(is_string($get['hasta']??null)?$get['hasta']:$hoy->format('Y-m-d'));
 $ini=ac_fecha(is_string($get['desde']??null)?$get['desde']:$hoy->sub(new DateInterval('P180D'))->format('Y-m-d'));
 if($ini>$fin)throw new DomainException('La fecha inicial no puede superar la final.');
 $tipo=$get['fuente']??'FACTURAS';if(!in_array($tipo,['FACTURAS','ORDENES'],true))throw new DomainException('Fuente no permitida.');
 $estado=(string)($get['estado']??'TODAS');if(!in_array($estado,['TODAS','VERIFICADA'],true))$estado='TODAS';
 $sucursal=(string)($get['sucursal']??'TODAS');
 if(!in_array($sucursal,['TODAS','TAPACHULA','TOSCANA','HIDALGO','TUXTLA'],true))$sucursal='TODAS';
 $q=$get['q']??'';$q=is_scalar($q)?trim((string)$q):'';
 $q=function_exists('mb_substr')?mb_substr($q,0,90,'UTF-8'):substr($q,0,90);
 $umbral=$get['umbral']??'5';
 if(!is_scalar($umbral)||!preg_match('/^\d{1,2}(?:\.\d)?$/D',(string)$umbral))throw new DomainException('El umbral de alertas no es válido.');
 $umbral=(float)$umbral;if($umbral>99)throw new DomainException('El umbral debe ser entre 0 y 99%.');
 $pagina=max(1,min(10000,ac_int($get['pagina']??1,1)));
 $producto=ac_int($get['producto']??0);
 $vista=$get['vista']??'PRODUCTOS';if(!in_array($vista,['PRODUCTOS','PROVEEDORES','ALERTAS'],true))$vista='PRODUCTOS';
 return ['desde'=>$ini,'hasta'=>$fin,'fuente'=>$tipo,'estado'=>$estado,'sucursal'=>$sucursal,'proveedor'=>ac_int($get['proveedor']??0),'q'=>$q,'umbral'=>$umbral,'pagina'=>$pagina,'producto'=>$producto,'vista'=>$vista];
}
function ac_tabla(PDO $db,string $nombre):bool{
 $st=$db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
 $st->execute([$nombre]);return (bool)$st->fetchColumn();
}
function ac_sql(array $f):array{
 // Nombres de tablas y campos constantes, no se interpolan datos del usuario.
 if($f['fuente']==='FACTURAS'){
  $sql="SELECT f.id documento_id, f.folio_interno folio, f.numero_factura referencia,
   f.fecha_factura fecha, f.sucursal sucursal, f.estado estado, f.proveedor_id,
   pr.nombre proveedor, d.producto_id,
   COALESCE(NULLIF(d.codigo_snapshot,''),p.codigo,'') codigo,
   COALESCE(NULLIF(d.descripcion_snapshot,''),p.descripcion,'Artículo sin descripción') descripcion,
   d.cantidad_facturada cantidad, d.costo_unitario costo
   FROM erp_facturas_detalle d JOIN erp_facturas f ON f.id=d.factura_id
   JOIN proveedores pr ON pr.id=f.proveedor_id LEFT JOIN productos p ON p.id=d.producto_id";
  $date='f.fecha_factura';$supplier='f.proveedor_id';$branch='f.sucursal';
 }else{
  $sql="SELECT o.id documento_id, o.folio folio, o.folio referencia,
   o.fecha fecha, o.destino sucursal, o.estado estado, o.proveedor_id,
   pr.nombre proveedor, d.producto_id,
   COALESCE(NULLIF(d.codigo_snapshot,''),p.codigo,'') codigo,
   COALESCE(NULLIF(d.descripcion_snapshot,''),p.descripcion,'Artículo sin descripción') descripcion,
   d.cantidad_piezas cantidad, d.costo_unitario costo
   FROM erp_ordenes_compra_detalle d JOIN erp_ordenes_compra o ON o.id=d.orden_id
   JOIN proveedores pr ON pr.id=o.proveedor_id LEFT JOIN productos p ON p.id=d.producto_id";
  $date='o.fecha';$supplier='o.proveedor_id';$branch='o.destino';
 }
 $where=["$date >= :desde","$date <= :hasta"];
 $params=['desde'=>$f['desde'],'hasta'=>$f['hasta']];
 if($f['fuente']==='ORDENES'){$where[]="o.estado='EMITIDA'";}
 elseif($f['estado']==='VERIFICADA'){$where[]="f.estado='VERIFICADA'";}
 if($f['proveedor']>0){$where[]="$supplier=:proveedor";$params['proveedor']=$f['proveedor'];}
 if($f['sucursal']!=='TODAS'){
  if($f['fuente']==='ORDENES' && in_array($f['sucursal'],['HIDALGO','TUXTLA'],true)){$where[]="$branch LIKE :sucursal";$params['sucursal']=$f['sucursal'].'%';}
  else{$where[]="$branch=:sucursal";$params['sucursal']=$f['sucursal'];}
 }
 if($f['q']!==''){
  $where[]="(p.codigo LIKE :c1 OR p.codigo_barras LIKE :cb OR p.descripcion LIKE :d1 OR d.codigo_snapshot LIKE :c2 OR d.descripcion_snapshot LIKE :d2)";
  foreach(['c1','cb','d1','c2','d2'] as $k)$params[$k]='%'.$f['q'].'%';
 }
 $sql.=' WHERE '.implode(' AND ',$where).' ORDER BY d.producto_id ASC, '.$supplier.' ASC, '.$date.' DESC, '.($f['fuente']==='ORDENES'?'o.id':'f.id').' DESC LIMIT 12001';
 return [$sql,$params];
}
/** Devuelve un arreglo de artículos-proveedor y resúmenes SIN mezclar OC con facturas. */
function ac_resumir(iterable $records,float $umbral=5.0):array{
 $pares=[];$providers=[];$productos=[];$docIds=[];$lineas=[];$total=0.;$qtyTotal=0;$filas=0;
 foreach($records as $r){
   ++$filas;if($filas>12000)throw new DomainException('Hay más de 12,000 partidas en el periodo. Reduce las fechas o usa filtros de proveedor/producto para evitar resultados parciales.');
   $pid=(int)$r['producto_id'];$sid=(int)$r['proveedor_id'];$doc=(int)$r['documento_id'];
   $costo=(float)$r['costo'];$q=(int)$r['cantidad'];$importe=round($costo*$q,4);$key="$pid:$sid";
   if(!isset($pares[$key]))$pares[$key]=['producto_id'=>$pid,'proveedor_id'=>$sid,'codigo'=>$r['codigo'],'descripcion'=>$r['descripcion'], 'proveedor'=>$r['proveedor'], 'ultimo_costo'=>$costo,'costo_previo'=>null,'fecha_ultima'=>$r['fecha'], 'folio_ultimo'=>$r['folio'], 'min'=>$costo,'max'=>$costo,'cantidad'=>0,'valor'=>0.,'documentos'=>[]];
   $p=&$pares[$key];if($p['costo_previo']===null && $doc !== (int)($p['documento_ultimo']??$doc))$p['costo_previo']=$costo;
   if(!isset($p['documento_ultimo']))$p['documento_ultimo']=$doc;
   $p['min']=min($p['min'],$costo);$p['max']=max($p['max'],$costo);$p['cantidad']+=$q;$p['valor']+=$importe;$p['documentos'][$doc]=true;unset($p);
   if(!isset($providers[$sid]))$providers[$sid]=['id'=>$sid,'nombre'=>$r['proveedor'],'cantidad'=>0,'valor'=>0.,'documentos'=>[],'productos'=>[],'ultima_fecha'=>$r['fecha']];
   $pr=&$providers[$sid];$pr['cantidad']+=$q;$pr['valor']+=$importe;$pr['documentos'][$doc]=true;$pr['productos'][$pid]=true;if($r['fecha']>$pr['ultima_fecha'])$pr['ultima_fecha']=$r['fecha'];unset($pr);
   if(!isset($productos[$pid]))$productos[$pid]=['id'=>$pid,'codigo'=>$r['codigo'],'descripcion'=>$r['descripcion'],'proveedores'=>[],'fecha_ultima'=>$r['fecha']];
   $productos[$pid]['proveedores'][$sid]=true;if($r['fecha']>$productos[$pid]['fecha_ultima'])$productos[$pid]['fecha_ultima']=$r['fecha'];
   $docIds[(string)$sid.':'.$doc]=true;
   $total+=$importe;$qtyTotal+=$q;$lineas[]=$r;
 }
 foreach($pares as &$p){$p['promedio']=$p['cantidad']>0?$p['valor']/$p['cantidad']:0.;$p['documentos_count']=count($p['documentos']);$p['variacion']=($p['costo_previo']!==null && $p['costo_previo']>0)?($p['ultimo_costo']/$p['costo_previo']-1)*100:null;unset($p['documentos']);}unset($p);
 foreach($providers as &$pr){$pr['documentos_count']=count($pr['documentos']);$pr['productos_count']=count($pr['productos']);unset($pr['documentos'],$pr['productos']);}unset($pr);
 $porProducto=[];foreach($pares as $par)$porProducto[$par['producto_id']][]=$par;
 $comparaciones=[];
 foreach($productos as $pid=>$p){
  $es=$porProducto[$pid]??[];
  usort($es,static fn($a,$b)=>$a['ultimo_costo']<=>$b['ultimo_costo']);
  $min=$es[0]['ultimo_costo']??0.;$max=$es[count($es)-1]['ultimo_costo']??0.;
  $comparaciones[]=['id'=>$pid,'codigo'=>$p['codigo'],'descripcion'=>$p['descripcion'],'proveedores'=>count($es),'mejor_proveedor'=>$es[0]['proveedor']??'','menor'=>$min,'mayor'=>$max,'diferencia'=>$max-$min,'brecha_pct'=>$min>0?($max/$min-1)*100:0.,'fecha_ultima'=>$p['fecha_ultima']];
 }
 usort($comparaciones,static fn($a,$b)=>($b['proveedores']<=>$a['proveedores'])?:($b['brecha_pct']<=>$a['brecha_pct'])?:strcmp($a['descripcion'],$b['descripcion']));
 $alerts=array_values(array_filter($pares,static fn($p)=>$p['variacion']!==null&&$p['variacion']>=$umbral));
 usort($alerts,static fn($a,$b)=>$b['variacion']<=>$a['variacion']);
 uasort($providers,static fn($a,$b)=>$b['valor']<=>$a['valor']);
 return ['pares'=>$pares,'proveedores'=>$providers,'productos'=>$comparaciones,'alertas'=>$alerts,'movimientos'=>$lineas,'kpi'=>['partidas'=>$filas,'documentos'=>count($docIds),'proveedores'=>count($providers),'productos'=>count($productos),'importe'=>$total,'piezas'=>$qtyTotal,'comparables'=>count(array_filter($comparaciones,static fn($r)=>$r['proveedores']>1))]];
}
function ac_money(mixed $v,int $dec=2):string{return '$'.number_format((float)$v,$dec,'.',',');}
function ac_csv(mixed $v):string{
 $s=(string)($v??'');
 // Evita que Excel ejecute fórmulas al abrir un CSV obtenido del proveedor/producto.
 if(preg_match('/^[\x00-\x20]*[=+\-@]/u',$s))$s="'".$s;
 return $s;
}
function ac_query(array $f,array $extra=[]):string{
 $values=array_merge($f,$extra);
 unset($values['umbral']);$values['umbral']=$extra['umbral']??$f['umbral'];
 return '?'.http_build_query(array_filter($values,static fn($v)=>$v!==null&&$v!==''&&$v!==0));
}
