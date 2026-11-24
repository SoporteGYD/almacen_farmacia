<?php
declare(strict_types=1);
/** Instalador estricto para G&D V39. No aplica canjes. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Sólo CLI');}
$modo=$argv[1]??'--check';
if(!in_array($modo,['--check','--apply','--verify'],true)){
    fwrite(STDERR,"Uso: php tools/instalar_rewards_precanje_v39.php --check|--apply|--verify\n");exit(2);
}
$root=realpath(__DIR__.'/..');
if(!$root){fwrite(STDERR,"No se encuentra la carpeta raíz del ERP.\n");exit(2);}
$manifest=json_decode((string)file_get_contents(__DIR__.'/manifest_rewards_precanje_v39.json'),true,512,JSON_THROW_ON_ERROR);
$payload=__DIR__.'/payload_rewards_precanje_v39';
$lista=$manifest['files'];
$posActual=is_file($root.'/public/pos.php')?hash_file('sha256',$root.'/public/pos.php'):null;
$posVersion=null;
foreach($manifest['pos_versions'] as $version=>$opcion){
    if($posActual===$opcion['new'] || in_array($posActual,$opcion['allowed'],true)){$posVersion=$version;break;}
}
if($posVersion===null){fwrite(STDERR,"BLOQUEADO: public/pos.php no coincide con ninguna de las variantes POS V36 conocidas. No se reemplazó nada.\n");exit(1);}
$lista['public/pos.php']=$manifest['pos_versions'][$posVersion];
$pendientes=[];$fallos=[];
foreach($lista as $ruta=>$info){
    if(str_contains($ruta,'..') || str_starts_with($ruta,'/')){$fallos[]='Ruta insegura';continue;}
    $dest=$root.'/'.$ruta;$source=$payload.'/'.$info['payload'];
    if(!is_file($source) || hash_file('sha256',$source)!==$info['new']){$fallos[]='Payload incorrecto: '.$ruta;continue;}
    $actual=is_file($dest)?hash_file('sha256',$dest):null;
    if($actual===$info['new'])$estado='INSTALADO';
    elseif($actual===null && empty($info['allowed'])){$estado='CREAR';$pendientes[$ruta]=$info;}
    elseif($actual!==null && in_array($actual,$info['allowed'],true)){$estado='ACTUALIZAR';$pendientes[$ruta]=$info;}
    else{$estado='INCOMPATIBLE';$fallos[]='Archivo modificado o instalación V38 ausente: '.$ruta;}
    echo str_pad($estado,16).$ruta.PHP_EOL;
}
foreach([
 'app/config/database.php', 'app/helpers/auth.php',
 'app/helpers/gyd_clientes_rewards.php','app/helpers/gyd_rewards_reversiones_v37.php',
 'app/helpers/gyd_rewards_catalogo_v38.php','app/helpers/gyd_pos_saldo_dividido.php',
 'app/helpers/pos_inteligente.php','app/models/PuntoVenta.php'
] as $archivo)if(!is_file($root.'/'.$archivo))$fallos[]='Dependencia ausente: '.$archivo;
if($fallos){foreach($fallos as $m)fwrite(STDERR,"BLOQUEADO: $m\n");exit(1);}
require_once $root.'/app/config/database.php';
try{
    $db=(new Database())->connect();
    $ctx=$db->query('SELECT DATABASE() AS base,@@port AS puerto')->fetch(PDO::FETCH_ASSOC);
    if(strtolower((string)($ctx['base']??''))!=='prueba'||(int)($ctx['puerto']??0)!==3307)
        throw new RuntimeException('El instalador exige exclusivamente la BD prueba puerto 3307.');
    $base=[
      'gyd_clientes'=>['id','activo','acepta_programa'],
      'gyd_clientes_ventas'=>['venta_id','cliente_id','puntos_otorgados'],
      'gyd_puntos_movimientos'=>['origen_clave','cliente_id','puntos'],
      'gyd_rewards_revision_v37'=>['reversion_id','venta_id','estado_revision'],
      'gyd_rewards_catalogo_v38'=>['id','sucursal_id','producto_id','puntos_requeridos','estado','costo_referencia_centavos','precio_referencia_centavos'],
      'productos'=>['id','codigo','descripcion','estado'],
      'lotes'=>['producto_id','costo_unitario'],
      'pos_ventas'=>['id','estado'],
      'pos_reversiones'=>['id','venta_id','tipo','estado'],
      'pos_precios'=>['producto_id','sucursal_id','precio_publico'],
      'pos_sucursales'=>['id','nombre','activo'],
      'pos_cajas'=>['id','sucursal_id','activa'],
      'pos_usuario_asignaciones'=>['usuario_id','sucursal_id','caja_id'],
      'pos_existencias_farmacia'=>['sucursal_id','producto_id','existencia'],
      'erp_pos_farmacia_control'=>['sucursal_id','producto_id','existencia'],
      'producto_existencias'=>['id','sucursal','ubicacion','existencia']
    ];
    foreach($base as $tabla=>$requeridas){
        $s=$db->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $s->execute([$tabla]);$cols=$s->fetchAll(PDO::FETCH_COLUMN);
        foreach($requeridas as $campo)if(!in_array($campo,$cols,true))
            throw new RuntimeException('Falta tabla o columna requerida: '.$tabla.'.'.$campo);
    }
    function tablasV39(PDO $db,bool $obligatoria):void {
        $t='gyd_rewards_solicitudes_v39';
        $s=$db->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $s->execute([$t]);$cols=$s->fetchAll(PDO::FETCH_COLUMN);
        if(!$cols){if($obligatoria)throw new RuntimeException('Falta tabla V39');return;}
        foreach(['id','clave','cliente_id','sucursal_id','caja_id','catalogo_id','producto_id','puntos_snapshot','saldo_snapshot','stock_snapshot','estado','usuario_id','creado_en','cancelado_por','cancelado_en','motivo_cancelacion'] as $campo)
           if(!in_array($campo,$cols,true))throw new RuntimeException('Tabla V39 incompatible: '.$campo);
        $s=$db->prepare('SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$t]);
        if(strtoupper((string)$s->fetchColumn())!=='INNODB')throw new RuntimeException('La tabla V39 debe usar InnoDB.');
        $s=$db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
        $s->execute([$t,'uq_gyd_precanje_clave']);if((int)$s->fetchColumn()<1)throw new RuntimeException('Falta índice único de idempotencia V39.');
    }
    tablasV39($db,$modo==='--verify');
}catch(Throwable $e){fwrite(STDERR,'BLOQUEADO: '.$e->getMessage()."\n");exit(2);}
if($modo==='--check'){
    echo 'COMPATIBLE V38, POS '.$posVersion.' y prueba:3307. Pendientes: '.count($pendientes).". Sin cambios.\n";exit(0);
}
if($modo==='--verify'){
    if($pendientes){fwrite(STDERR,"La V39 aún no está aplicada.\n");exit(1);}
    echo "V39 VERIFICADA. Solicitudes de simulación habilitadas; canje real BLOQUEADO.\n";exit(0);
}
// En --apply respaldar ANTES de tocar DDL o archivos.
$backup=dirname(dirname($root)).'/gyd_resguardos_codigo/rewards_precanje_v39_'.date('Ymd_His').'_'.bin2hex(random_bytes(4));
try{
    if(!@mkdir($backup,0770,true))throw new RuntimeException('No se pudo crear carpeta de respaldos.');
    foreach($pendientes as $ruta=>$entrada){
        $dest=$root.'/'.$ruta;$bk=$backup.'/'.$ruta;
        if(!is_dir(dirname($bk)) && !mkdir(dirname($bk),0770,true))throw new RuntimeException('No se pudo crear carpeta del respaldo.');
        if(is_file($dest) && (!copy($dest,$bk)||hash_file('sha256',$bk)!==hash_file('sha256',$dest)))
            throw new RuntimeException('Respaldo fallido: '.$ruta);
    }
    $sql=(string)file_get_contents(__DIR__.'/esquema_rewards_precanje_v39.sql');
    $sql=preg_replace('/^\s*--[^\r\n]*\r?\n/m','',$sql);
    foreach(explode(';',$sql) as $comando){$comando=trim($comando);if($comando!=='')$db->exec($comando);}
    tablasV39($db,true);
}catch(Throwable $e){fwrite(STDERR,'No se cambiaron archivos. Revisa si se creó tabla V39: '.$e->getMessage()."\n");exit(2);}
$aplicados=[];
try{
    foreach($pendientes as $ruta=>$info){
        $dest=$root.'/'.$ruta;$src=$payload.'/'.$info['payload'];
        if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0770,true))throw new RuntimeException('No se pudo crear carpeta destino.');
        $tmp=dirname($dest).'/.gyd_v39_'.bin2hex(random_bytes(6)).'.tmp';
        if(!copy($src,$tmp) || hash_file('sha256',$tmp)!==$info['new'])throw new RuntimeException('Copia fallida: '.$ruta);
        $aplicados[]=$ruta;
        if(!@rename($tmp,$dest)){
           if(!@copy($tmp,$dest))throw new RuntimeException('No se pudo reemplazar '.$ruta);
           @unlink($tmp);
        }
        if(hash_file('sha256',$dest)!==$info['new'])throw new RuntimeException('Hash incorrecto: '.$ruta);
    }
    echo "V39 INSTALADA. Respaldo: $backup\nCanje real permanece BLOQUEADO. Ejecuta --verify.\n";
}catch(Throwable $e){
    foreach(array_reverse($aplicados) as $ruta){$bk=$backup.'/'.$ruta;if(is_file($bk))@copy($bk,$root.'/'.$ruta);else @unlink($root.'/'.$ruta);}
    fwrite(STDERR,'FALLÓ: '.$e->getMessage().". Se intentó restaurar el código; la tabla V39 puede permanecer.\n");exit(2);
}
