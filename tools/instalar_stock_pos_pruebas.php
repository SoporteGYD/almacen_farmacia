<?php
declare(strict_types=1);
/**
 * G&D ERP/POS: carga masiva de existencias de demostración y conciliación de datos.
 * EN NINGÚN CASO CERTIFICA EXISTENCIA FÍSICA O COSTOS REALES.
 * ÚNICAMENTE CLI + base `prueba` en el puerto 3307, bajo consentimiento explícito.
 * No toca producto_existencias, lotes, movimientos, ventas o tickets.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Solo CLI.\n"); }
require_once __DIR__.'/lib/gyd_stock_pruebas_reglas.php';
$root=dirname(__DIR__);
if (!is_file($root.'/app/config/database.php')) {fwrite(STDERR,"Ejecuta desde tools del ERP: falta app/config/database.php.\n");exit(2);}
require_once $root.'/app/config/database.php';
$argv=$_SERVER['argv']??[];
$action='--check';
foreach (['--check','--apply','--verify'] as $a) if(in_array($a,$argv,true)) $action=$a;
$consent=in_array('--confirmar-simulados',$argv,true);
$demo=48;
foreach($argv as $arg){if(str_starts_with($arg,'--piezas-demo=')){
 $v=substr($arg,14);
 if(!preg_match('/^\d{1,4}$/D',$v) || (int)$v<1 || (int)$v>9999){fwrite(STDERR,"El número de piezas DEMO debe ser 1..9999.\n");exit(2);} $demo=(int)$v;
}}
function dbq(PDO $db,string $sql,array $p=[]): PDOStatement {$st=$db->prepare($sql);$st->execute($p);return $st;}
function bd_segura(PDO $db): void {
 $v=$db->query('SELECT DATABASE() AS base, @@port AS puerto')->fetch(PDO::FETCH_ASSOC);
 if(strtolower((string)($v['base']??''))!=='prueba'||(int)($v['puerto']??0)!==3307)
   throw new DomainException('BLOQUEADO: solo base prueba en MariaDB puerto 3307.');
 $t=$db->query('SELECT activo FROM gyd_tt_pool_config WHERE id=1')->fetchColumn();
 if ((int)$t!==1) throw new DomainException('Inventario compartido Tapachula/Toscana no activo. No se carga stock.');
 $s=dbq($db,'SELECT id,nombre FROM pos_sucursales WHERE activo=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
 $nom=array_column($s,'nombre','id');
 if(count($s)!==4 || !isset($nom[1],$nom[2],$nom[3],$nom[4]) ||
    stripos($nom[1],'HIDALGO')===false || stripos($nom[2],'TUXTLA')===false ||
    stripos($nom[3],'TAPACHULA')===false || stripos($nom[4],'TOSCANA')===false)
   throw new DomainException('Mapa de cuatro sucursales incompatible. Actualiza el sistema antes de aplicar.');
}
function datos(PDO $db,bool $lock=false): array {
 $suffix=$lock?' FOR UPDATE':'';
 $prod=$db->query('SELECT id,codigo,descripcion,precio_compra,costo_ultimo,costo_promedio,precio_venta,estado FROM productos WHERE estado=1 ORDER BY id'.$suffix)->fetchAll(PDO::FETCH_ASSOC);
 $stock=[];
 foreach($db->query('SELECT sucursal_id,producto_id,existencia FROM pos_existencias_farmacia'.$suffix) as $r){
  $key=(int)$r['sucursal_id'].':'.(int)$r['producto_id'];$stock[$key]=(int)$r['existencia'];
  if((int)$r['existencia']<0)throw new DomainException('Existencia POS negativa en '.$key);
 }
 $farm=[];
 foreach($db->query('SELECT sucursal_id,producto_id,existencia,minimo FROM erp_pos_farmacia_control'.$suffix) as $r){
  $key=(int)$r['sucursal_id'].':'.(int)$r['producto_id'];$farm[$key]=['existencia'=>(int)$r['existencia'],'minimo'=>(int)$r['minimo']];
  if((int)$r['existencia']<0)throw new DomainException('Farmacia negativa en '.$key);
 }
 $alm=[];
 $sql="SELECT producto_id, UPPER(TRIM(sucursal)) AS destino, SUM(existencia) AS total
     FROM producto_existencias
     WHERE TRIM(COALESCE(ubicacion,''))<>''
       AND UPPER(TRIM(ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION','SIN UBICACIÓN')
       AND UPPER(TRIM(sucursal)) COLLATE utf8mb4_general_ci
           IN ('CIUDAD HIDALGO','CD HIDALGO','TUXTLA','TUXTLA GUTIERREZ','TUXTLA GUTIÉRREZ')
     GROUP BY producto_id, UPPER(TRIM(sucursal))";
 foreach($db->query($sql) as $r){
  $n=mb_strtoupper(trim((string)$r['destino']),'UTF-8');
  $sid=in_array($n,['CIUDAD HIDALGO','CD HIDALGO'],true)?1:2;
  $key=$sid.':'.(int)$r['producto_id'];$alm[$key]=($alm[$key]??0)+(int)$r['total'];
 }
 foreach($alm as $k=>$v) if($v<0)throw new DomainException('Almacén negativo: '.$k);
 $price=[];
 foreach($db->query('SELECT sucursal_id,producto_id,precio_publico FROM pos_precios'.$suffix) as $r){
  $price[(int)$r['sucursal_id'].':'.(int)$r['producto_id']]=(float)$r['precio_publico'];
 }
 $demoFichas=[];
 foreach($db->query("SELECT producto_id, CASE WHEN COALESCE(observaciones,'') LIKE '%DEMO%' OR COALESCE(descripcion_ampliada,'') LIKE '%DEMO%' THEN 1 ELSE 0 END AS demo FROM erp_producto_ficha") as $r) {
  $demoFichas[(int)$r['producto_id']]=(int)$r['demo'];
 }
 return compact('prod','stock','farm','alm','price','demoFichas');
}
function plan(array $d,int $demo):array {
 $tasks=[]; $warnings=[];$stats=['productos'=>count($d['prod']),'general_nuevo'=>0,'general_modificado'=>0,'farmacia_nueva'=>0,'pool_nuevo'=>0,'costo_nuevo'=>0,'precio_nuevo'=>0,'costo_demo'=>0,'precios_faltantes'=>0,'existencias_conservadas'=>0,'costos_conservados'=>0,'cuadres_actuales'=>0,'descuadres_actuales'=>0];
 foreach($d['prod'] as $p){
  $id=(int)$p['id'];$pp=[];
  for($s=1;$s<=4;$s++) {
   $key="$s:$id"; $pv=$d['price'][$key]??null;
   if($pv===null || $pv<=0) {
    $base=(float)$p['precio_venta'];
    // No se inventan precios si ya hay costo pero no hay precio público ni precio original.
    $value=$base>0?$base:null;
    if($value!==null){$tasks[]=['tipo'=>'PRECIO','sucursal'=>$s,'producto'=>$id,'anterior'=>$pv,'nuevo'=>number_format($value,2,'.',''),'origen'=>'PRECIO_VENTA_CATALOGO'];$stats['precio_nuevo']++;$pp[]=$value;}
    else {$stats['precios_faltantes']++;$warnings[]="Precio ausente para $key";}
   } else {$pp[]=$pv;}
  }
  $cs=gyd_stock_prueba_costo($p,$pp);
  if(($d['demoFichas'][$id]??0)===1) $stats['costo_demo']++;
  if($cs['costo']!==null && $cs['crear']){
   $tasks[]=['tipo'=>'COSTO','sucursal'=>0,'producto'=>$id,'anterior'=>$p['costo_ultimo'],'nuevo'=>$cs['costo'],'origen'=>$cs['fuente']];$stats['costo_nuevo']++;
  } elseif ((float)$p['costo_ultimo']>0) $stats['costos_conservados']++;
  for($s=1;$s<=2;$s++){
   $key="$s:$id";
   $g=$d['stock'][$key]??null;
   $f=isset($d['farm'][$key])?$d['farm'][$key]['existencia']:null;
   $a=$d['alm'][$key]??0;
   $t=gyd_stock_prueba_objetivo($s,$g,$f,$a,$demo);
   if($f===null){$tasks[]=['tipo'=>'FARMACIA','sucursal'=>$s,'producto'=>$id,'anterior'=>null,'nuevo'=>(string)$t['farmacia'],'origen'=>$t['origen']==='FARMACIA_DERIVADA_POS'?'DERIVADO_GENERAL_MENOS_ALMACEN':'SIMULADO_SIN_CONTEO'];$stats['farmacia_nueva']++;}
   if($g===null || $g!==$t['general']){
     $tasks[]=['tipo'=>'GENERAL','sucursal'=>$s,'producto'=>$id,'anterior'=>$g,'nuevo'=>(string)$t['general'],'origen'=>$f===null?'POS_DERIVADO_O_SIMULADO':'FARMACIA_MAS_ALMACEN'];
     if($g===null)$stats['general_nuevo']++; else $stats['general_modificado']++;
   } else {$stats['cuadres_actuales']++;}
   if($g!==null && $f!==null && $g!==$f+$a) $stats['descuadres_actuales']++;
  }
  $key="3:$id";
  $t=gyd_stock_prueba_objetivo(3,$d['stock'][$key]??null,null,0,$demo);
  if(!array_key_exists($key,$d['stock'])){
   $tasks[]=['tipo'=>'POOL','sucursal'=>3,'producto'=>$id,'anterior'=>null,'nuevo'=>(string)$t['general'],'origen'=>'STOCK_COMPARTIDO_SIMULADO'];
   $stats['pool_nuevo']++;
  }else $stats['existencias_conservadas']++;
  // Toscana sólo es snapshot: NUNCA se suma a Tapachula ni se escribe en sucursal 4.
  $snap=$d['stock']["4:$id"]??0;
  if($snap>0){$warnings[]="Snapshot Toscana tiene existencia positiva en producto #$id. Se bloquea aplicación para evitar duplicación.";}
 }
 return compact('tasks','warnings','stats');
}
function resumen(array $pl):void {
 foreach($pl['stats'] as $k=>$v) printf("%-29s %s\n",$k,number_format($v,0,'.',','));
 echo "Tareas calculadas: ".number_format(count($pl['tasks']),0,'.',',')."\n";
 if($pl['warnings']){echo "ADVERTENCIAS (primeras 12):\n";foreach(array_slice($pl['warnings'],0,12) as $v)echo " - $v\n";}
}
function auditoria(PDO $db):void {
 $db->exec("CREATE TABLE IF NOT EXISTS gyd_pos_stock_pruebas_auditoria (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lote VARCHAR(32) NOT NULL,
    tipo VARCHAR(18) NOT NULL,
    sucursal_id INT NOT NULL,
    producto_id INT NOT NULL,
    valor_anterior VARCHAR(40) NULL,
    valor_nuevo VARCHAR(40) NOT NULL,
    fuente VARCHAR(60) NOT NULL,
    registrado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_prueba_lote (lote,tipo,sucursal_id,producto_id),
    KEY idx_prueba_producto(producto_id,sucursal_id)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function aplicar(PDO $db,array $tasks):string {
 $lote='POS-DEMO-'.date('Ymd-His').'-'.bin2hex(random_bytes(3));
 $log=$db->prepare('INSERT INTO gyd_pos_stock_pruebas_auditoria(lote,tipo,sucursal_id,producto_id,valor_anterior,valor_nuevo,fuente) VALUES (?,?,?,?,?,?,?)');
 $f=$db->prepare('INSERT INTO erp_pos_farmacia_control(sucursal_id,producto_id,existencia,minimo,actualizado_por) VALUES(?,?,?,24,NULL) ON DUPLICATE KEY UPDATE existencia=VALUES(existencia)');
 $g=$db->prepare('INSERT INTO pos_existencias_farmacia(sucursal_id,producto_id,existencia) VALUES (?,?,?) ON DUPLICATE KEY UPDATE existencia=VALUES(existencia)');
 $c=$db->prepare('UPDATE productos SET costo_ultimo=? WHERE id=? AND costo_ultimo<=0');
 $pr=$db->prepare('INSERT INTO pos_precios(sucursal_id,producto_id,precio_publico,actualizado_por) VALUES (?,?,?,NULL) ON DUPLICATE KEY UPDATE precio_publico=VALUES(precio_publico)');
 foreach($tasks as $t){
  $log->execute([$lote,$t['tipo'],$t['sucursal'],$t['producto'],$t['anterior']===null?null:(string)$t['anterior'],$t['nuevo'],$t['origen']]);
  switch($t['tipo']) {
   case 'FARMACIA':$f->execute([$t['sucursal'],$t['producto'],$t['nuevo']]);break;
   case 'GENERAL':case 'POOL':$g->execute([$t['sucursal'],$t['producto'],$t['nuevo']]);break;
   case 'COSTO':if($c->execute([$t['nuevo'],$t['producto']]) && $c->rowCount()!==1)throw new RuntimeException('Costo cambiado durante preparación. Reintenta.');break;
   case 'PRECIO':$pr->execute([$t['sucursal'],$t['producto'],$t['nuevo']]);break;
   default:throw new LogicException('Tipo no permitido');
  }
 }
 return $lote;
}
try {
 $db=(new Database())->connect();$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);bd_segura($db);
 echo "G&D / CARGA DE EXISTENCIAS PARA PRUEBAS - Base prueba:3307\n";
 echo "ATENCION: los faltantes son SIMULADOS, no representan conteos ni costos reales.\n";
 if($action==='--apply' && !$consent)throw new DomainException('Para cargar valores DEMO agrega: --confirmar-simulados');
 if($action==='--apply'){
  $lock=dbq($db,"SELECT GET_LOCK('gyd_pos_carga_stock_pruebas',15)")->fetchColumn();
  if((int)$lock!==1)throw new RuntimeException('Otra carga de inventario en curso.');
  try {
   auditoria($db);
   $db->beginTransaction();
   // Lee y bloquea registros actuales para que el plan no se calcule sobre el respaldo antiguo.
   $d=datos($db,true);$pl=plan($d,$demo);
   if($pl['warnings']) throw new DomainException('Hay conflictos o precios faltantes. Ejecuta --check y corrígelos antes de aplicar.');
   resumen($pl);
   $lote=aplicar($db,$pl['tasks']);
   $db->commit();
   echo "\nAPLICADO. Lote auditado: $lote\n";
   $verify=plan(datos($db),$demo);
   if(array_filter($verify['stats'],static fn($v,$k)=>in_array($k,['general_nuevo','general_modificado','farmacia_nueva','pool_nuevo','costo_nuevo','precio_nuevo','descuadres_actuales'],true) && $v>0,ARRAY_FILTER_USE_BOTH)){
     throw new RuntimeException('La verificación detectó saldos pendientes. Revisa --verify antes de vender.');
   }
   echo "VERIFICADO: inventario POS conciliado en base de pruebas.\n";
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  finally {dbq($db,"SELECT RELEASE_LOCK('gyd_pos_carga_stock_pruebas')");}
 }else {
  $pl=plan(datos($db),$demo);resumen($pl);
  if($action==='--verify'){
   $pend=$pl['stats']['general_nuevo']+$pl['stats']['general_modificado']+$pl['stats']['farmacia_nueva']+$pl['stats']['pool_nuevo'];
   if($pend || $pl['warnings'] || $pl['stats']['costo_nuevo'] || $pl['stats']['precio_nuevo']){echo "PENDIENTES: $pend registros requieren carga o conciliación.\n";exit(1);}
   echo "VERIFICADO: todos los productos activos cumplen las reglas de saldos POS.\n";
  }else echo "SOLO DIAGNOSTICO. Para insertar existencias ficticias en PRUEBA usa --apply --confirmar-simulados\n";
 }
}catch(Throwable $e){fwrite(STDERR,"ERROR: ".$e->getMessage()."\n");exit(1);}
