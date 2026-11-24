<?php
declare(strict_types=1);
/** G&D – Diagnóstico read-only por defecto de costos/precios/márgenes y sincronización opcional SOLO de alertas en BD prueba. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Disponible solo por terminal.');}
$root=dirname(__DIR__);
require_once $root.'/app/config/database.php';
require_once $root.'/app/helpers/erp_compras_alertas_seguimiento.php';
if(!function_exists('gdca_auto_contexto')){fwrite(STDERR,"Falta instalar Alertas V2.\n");exit(2);}
$options=[];
foreach(array_slice($argv,1) as $arg){
 if(!preg_match('/^--([a-z-]+)(?:=(.*))?$/D',$arg,$m)){fwrite(STDERR,"Argumento incorrecto: $arg\n");exit(2);}
 $options[$m[1]]=$m[2]??true;
}
if(isset($options['ayuda'])){echo "Opciones: --codigo=... | --caso=ID | --actualizar --confirmar-prueba\n";exit(0);}
$ref=(string)($options['codigo']??'');$case=(string)($options['caso']??'');
if($ref!==''&&(!preg_match('/^[0-9a-zA-Z.\-_]{1,80}$/D',$ref))){fwrite(STDERR,"Código inválido.\n");exit(2);}
if($case!==''&&(!ctype_digit($case)||(int)$case<1)){fwrite(STDERR,"ID de caso inválido.\n");exit(2);}
$money=static fn($v)=>$v===null?'SIN PRECIO':'$'.number_format((float)$v,2,'.',',');
try {
 $db=(new Database())->connect();
 $bd=(string)$db->query('SELECT DATABASE()')->fetchColumn();
 echo "G&D | Diagnóstico de alertas por precios\nBase conectada: $bd\n";
 if(isset($options['actualizar'])){
    if(!isset($options['confirmar-prueba'])||$bd!=='prueba')throw new RuntimeException('La sincronización por terminal solo se permite en BD llamada prueba con --confirmar-prueba.');
    $r=gdca_sync($db,true);
    echo 'Sincronización de alertas: '.json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
 }
 if(!gdca_tables_ok($db))throw new RuntimeException('Faltan tablas de seguimiento de alertas.');
 $sql="SELECT id,codigo,descripcion,tipo,estado,vigente,producto_id,sucursal_id,sucursal_nombre,precio,costo,factura_id,detectado_en
       FROM erp_compras_alerta_casos WHERE 1=1";$params=[];
 if($case!==''){$sql.=' AND id=?';$params[]=(int)$case;}
 elseif($ref!==''){$sql.=' AND codigo=?';$params[]=$ref;}
 else{$sql.=" AND tipo IN ('MARGEN_BAJO','PERDIDA','SIN_PRECIO')";}
 $sql.=' ORDER BY id DESC LIMIT 60';
 $q=$db->prepare($sql);$q->execute($params);$casos=$q->fetchAll(PDO::FETCH_ASSOC);
 if(!$casos){echo "No se encontraron casos para este filtro.\n";exit(0);}
 echo "Casos encontrados: ".count($casos)."\n";
 foreach($casos as $c){
    $ctx=gdca_auto_contexto($db,$c);$tipo=(string)$c['tipo'];$pct=$ctx['margen'];
    $s=(string)($c['sucursal_nombre']?:'Precio base del catálogo');
    echo "\nCASO #{$c['id']} {$c['codigo']} | {$tipo} | {$s} | {$c['estado']} | Vigente={$c['vigente']}\n";
    echo '  Última factura elegible #'.($ctx['factura_id']?:'NINGUNA').' | costo='.$money($ctx['costo']).' | precio actual='.$money($ctx['precio'])."\n";
    echo '  Margen real: '.($pct===null?'NO CALCULABLE':number_format($pct,4,'.','').'%')."\n";
    if($tipo==='MARGEN_BAJO' && $ctx['costo']!==null && $ctx['costo']>=0){
        $v=ceil(($ctx['costo']/.85)*100.0-1e-8)/100.0;
        for($i=0;$i<4 && $v>0 && (100*($v-$ctx['costo'])/$v)<(15-1e-7);$i++)$v+=.01;
        echo '  Precio mínimo matemático para margen real >=15%: '.$money($v)."\n";
    }
    if($ctx['motivo'])echo '  Aviso: '.$ctx['motivo']."\n";
    if($ctx['factura_id']&&$ctx['precio']!==null&&$tipo==='MARGEN_BAJO'){
       echo $pct>=15?'  DIAGNOSTICO: margen corregido. Si continúa pendiente, ejecutar Comprobar de nuevo / --actualizar.\n': '  DIAGNOSTICO: margen todavía menor a 15%; la alerta corresponde a esta sucursal/costo.\n';
    }
 }
}catch(Throwable $e){fwrite(STDERR,"NO SE COMPLETO DIAGNOSTICO: ".$e->getMessage()."\n");exit(1);}
