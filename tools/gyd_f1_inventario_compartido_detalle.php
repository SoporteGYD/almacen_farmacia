<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI');}
$root=dirname(__DIR__);
if(in_array('--check',$argv,true)){
    foreach(['app/config/database.php','app/helpers/pos_inteligente.php','app/models/PuntoVenta.php'] as $file){
        if(!is_file($root.'/'.$file)){fwrite(STDERR,"INCOMPATIBLE: falta $file\n");exit(3);}
    }
    echo "COMPATIBLE: comando SOLO LECTURA; usar --run --expect=prueba --json\n";exit(0);
}
if(!in_array('--run',$argv,true)){fwrite(STDERR,"Uso: --check | --run --expect=prueba [--json]\n");exit(2);}
$expect=null;
foreach($argv as $arg){if(str_starts_with($arg,'--expect='))$expect=substr($arg,9);}
if(!$expect){fwrite(STDERR,"ERROR: especifica --expect=prueba (o base de laboratorio).\n");exit(2);}
require_once $root.'/app/config/database.php';
try{
    $db=(new Database())->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $base=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    if($base!==$expect)throw new RuntimeException('Base no coincide: '.$base.' vs '.$expect);
    $sql="SELECT p.id producto_id,p.codigo,p.descripcion,
        COALESCE(SUM(CASE WHEN e.sucursal_id=3 THEN e.existencia ELSE 0 END),0) tapachula,
        COALESCE(SUM(CASE WHEN e.sucursal_id=4 THEN e.existencia ELSE 0 END),0) toscana,
        COUNT(DISTINCT CASE WHEN e.sucursal_id IN(3,4) THEN e.sucursal_id ELSE NULL END) sucursales_con_fila
        FROM pos_existencias_farmacia e JOIN productos p ON p.id=e.producto_id
        WHERE e.sucursal_id IN (3,4)
        GROUP BY p.id,p.codigo,p.descripcion ORDER BY p.id";
    $rows=$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $ambas=[];$soloTap=0;$soloTos=0;
    foreach($rows as $r){
        $t=(int)$r['tapachula'];$s=(int)$r['toscana'];
        if((int)$r['sucursales_con_fila']===2)$ambas[]=['producto_id'=>(int)$r['producto_id'],'codigo'=>$r['codigo'],'descripcion'=>$r['descripcion'],'tapachula'=>$t,'toscana'=>$s,'suma_no_validada'=>$t+$s];
        elseif($t!==0)$soloTap++;
        elseif($s!==0)$soloTos++;
    }
    // The last receipts/return processes can still be branch-specific.
    $report=['base'=>$base,'fecha'=>date(DATE_ATOM),'solo_lectura'=>true,'sucursales_pos'=>['Tapachula'=>3,'Toscana'=>4],
      'total_productos_con_fila'=>count($rows),'productos_con_filas_en_ambas'=>count($ambas),'muestras_ambas'=>array_slice($ambas,0,100),
      'no_es_conciliacion_fisica'=>true,'observacion'=>'Sumar filas no prueba inventario compartido; ventas, devoluciones, ajustes, importaciones y traspasos siguen sujetos a conciliacion.',
      'siguiente_paso'=>'Confirmar existencia fisica y adaptar todos los procesos de escritura en una base aislada antes de activar stock unificado para cobrar'];
    if(in_array('--json',$argv,true))echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
    else{
      echo 'G&D - Inventario Tapachula / Toscana - SOLO LECTURA'.PHP_EOL;
      echo 'Base: '.$base.' | Productos con filas en ambas: '.count($ambas).PHP_EOL;
      foreach(array_slice($ambas,0,30) as $r)echo '#'.$r['producto_id'].' | '.$r['codigo'].' | T '.$r['tapachula'].' | P '. $r['toscana'].PHP_EOL;
      echo 'NO fusionar ni transferir existencias automaticamente.'.PHP_EOL;
    }
} catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage().PHP_EOL);exit(4);}
