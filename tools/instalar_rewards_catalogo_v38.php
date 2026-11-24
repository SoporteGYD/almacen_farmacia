<?php
declare(strict_types=1);
/** Actualización incremental V38: preflight de archivos, esquema y BD laboratorio. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Sólo CLI');}
$modo=$argv[1]??'--check';
if(!in_array($modo,['--check','--apply','--verify'],true)){
    fwrite(STDERR,"Uso: php tools/instalar_rewards_catalogo_v38.php --check|--apply|--verify\n");exit(2);
}
$root=realpath(__DIR__.'/..');
if(!$root){fwrite(STDERR,"No se localiza la carpeta raíz del ERP.\n");exit(2);}
$manifiesto=json_decode((string)file_get_contents(__DIR__.'/manifest_rewards_catalogo_v38.json'),true,512,JSON_THROW_ON_ERROR);
$payload=__DIR__.'/payload_catalogo_rewards_v38';
$pendientes=[];$fallos=[];
foreach($manifiesto['files'] as $ruta=>$entrada){
    if(str_contains($ruta,'..') || str_starts_with($ruta,'/')){$fallos[]='Ruta insegura';continue;}
    $source=$payload.'/'.$entrada['payload'];$dest=$root.'/'.$ruta;
    if(!is_file($source)||hash_file('sha256',$source)!==$entrada['new']){$fallos[]="Payload alterado: $ruta";continue;}
    $actual=is_file($dest)?hash_file('sha256',$dest):null;
    if($actual===$entrada['new'])$estado='INSTALADO';
    elseif($actual===null && empty($entrada['allowed'])){$estado='CREAR';$pendientes[$ruta]=$entrada;}
    elseif($actual!==null && in_array($actual,$entrada['allowed'],true)){$estado='ACTUALIZAR';$pendientes[$ruta]=$entrada;}
    else {$estado='INCOMPATIBLE';$fallos[]="Archivo diferente a V37: $ruta";}
    echo str_pad($estado,15).$ruta.PHP_EOL;
}
foreach(['app/helpers/gyd_rewards_reversiones_v37.php','app/helpers/gyd_clientes_rewards.php',
          'public/gyd_clientes_api.php'] as $requerido){
    if(!is_file($root.'/'.$requerido))$fallos[]='Falta archivo base V37: '.$requerido;
}
if($fallos){foreach($fallos as $mensaje)fwrite(STDERR,"BLOQUEADO: $mensaje\n");exit(1);}
require_once $root.'/app/config/database.php';
try{
    $db=(new Database())->connect();
    $ctx=$db->query('SELECT DATABASE() AS base,@@port AS puerto')->fetch(PDO::FETCH_ASSOC);
    if(strtolower((string)($ctx['base']??''))!=='prueba'||(int)($ctx['puerto']??0)!==3307)
        throw new RuntimeException('Este instalador exige base prueba y puerto 3307.');
    $base=[
       'gyd_clientes'=>['id','nombre'],
       'gyd_clientes_ventas'=>['venta_id','cliente_id'],
       'gyd_puntos_movimientos'=>['cliente_id','origen_clave'],
       'gyd_rewards_revision_v37'=>['reversion_id','venta_id'],
       'productos'=>['id','codigo','codigo_barras','descripcion','estado'],
       'lotes'=>['producto_id','costo_unitario'],
       'pos_precios'=>['producto_id','sucursal_id','precio_publico'],
       'pos_sucursales'=>['id','nombre','activo'],
    ];
    $nuevo=[
       'gyd_rewards_catalogo_v38'=>['id','sucursal_id','producto_id','puntos_requeridos','costo_referencia_centavos','precio_referencia_centavos','estado','autorizado_por'],
       'gyd_rewards_catalogo_auditoria_v38'=>['id','catalogo_id','usuario_id','accion','motivo','antes_json','despues_json']
    ];
    foreach($base as $tabla=>$cols){
        $q=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$tabla]);$have=$q->fetchAll(PDO::FETCH_COLUMN);
        foreach($cols as $col)if(!in_array($col,$have,true))throw new RuntimeException('Falta tabla o columna base '.$tabla.'.'.$col);
    }
    function verificarTablasV38(PDO $db,array $esquemas,bool $obligatorio):void {
        foreach($esquemas as $tabla=>$cols){
            $s=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$tabla]);$have=$s->fetchAll(PDO::FETCH_COLUMN);
            if(!$have){if($obligatorio)throw new RuntimeException('Falta tabla V38: '.$tabla);continue;}
            foreach($cols as $col)if(!in_array($col,$have,true))throw new RuntimeException('Esquema V38 incompatible: '.$tabla.'.'.$col);
            $s=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$tabla]);
            if(strtoupper((string)$s->fetchColumn())!=='INNODB')throw new RuntimeException('Se requiere InnoDB: '.$tabla);
        }
    }
    verificarTablasV38($db,$nuevo,$modo==='--verify');
} catch(Throwable $e){fwrite(STDERR,'BLOQUEADO: '.$e->getMessage()."\n");exit(2);}
if($modo==='--check'){
    echo 'COMPATIBLE V37 + prueba:3307; archivos pendientes: '.count($pendientes).". No se modificó código ni base.\n";exit(0);
}
if($modo==='--verify'){
    if($pendientes){fwrite(STDERR,"Faltan archivos. Ejecuta --apply.\n");exit(1);}
    echo "V38 VERIFICADA. Catálogo administrativo instalado; canjes deshabilitados.\n";exit(0);
}
try {
    $sql=(string)file_get_contents(__DIR__.'/esquema_rewards_catalogo_v38.sql');
    $sql=preg_replace('/^\s*--[^\r\n]*\r?\n/m','',$sql);
    foreach(explode(';',$sql) as $comando){$comando=trim($comando);if($comando!=='')$db->exec($comando);}
    verificarTablasV38($db,$nuevo,true);
} catch(Throwable $e){fwrite(STDERR,'No se cambió código. DDL incompleto: '.$e->getMessage()."\n");exit(2);}
if(!$pendientes){echo "V38 ya instalada. Esquema verificado.\n";exit(0);}
$backup=dirname(dirname($root)).'/gyd_resguardos_codigo/catalogo_rewards_v38_'.date('Ymd_His').'_'.bin2hex(random_bytes(4));
if(!@mkdir($backup,0770,true)){fwrite(STDERR,"No se pudo crear carpeta de respaldos.\n");exit(2);}
$aplicados=[];
try {
    foreach($pendientes as $ruta=>$entrada){
        $actual=$root.'/'.$ruta;$bk=$backup.'/'.$ruta;
        if(!is_dir(dirname($bk))&&!mkdir(dirname($bk),0770,true))throw new RuntimeException('No se crea carpeta de respaldo.');
        if(is_file($actual)&&(!copy($actual,$bk)||hash_file('sha256',$actual)!==hash_file('sha256',$bk)))throw new RuntimeException('Error de respaldo: '.$ruta);
    }
    foreach($pendientes as $ruta=>$entrada){
        $from=$payload.'/'.$entrada['payload'];$dest=$root.'/'.$ruta;
        if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0770,true))throw new RuntimeException('No se crea carpeta destino.');
        $tmp=dirname($dest).'/.rewards_v38_'.bin2hex(random_bytes(6)).'.tmp';
        if(!copy($from,$tmp)||hash_file('sha256',$tmp)!==$entrada['new'])throw new RuntimeException('Copia fallida: '.$ruta);
        $aplicados[]=$ruta;
        if(!@rename($tmp,$dest)){if(!@copy($tmp,$dest))throw new RuntimeException('No se reemplazó '.$ruta);@unlink($tmp);}
        if(hash_file('sha256',$dest)!==$entrada['new'])throw new RuntimeException('Hash incorrecto: '.$ruta);
    }
    echo "V38 INSTALADA. Copias de código en: $backup\nEjecuta --verify y prueba sólo en entorno de laboratorio.\n";
}catch(Throwable $e){
    foreach(array_reverse($aplicados) as $ruta){$bk=$backup.'/'.$ruta;if(is_file($bk))@copy($bk,$root.'/'.$ruta);else @unlink($root.'/'.$ruta);}
    fwrite(STDERR,'FALLÓ: '.$e->getMessage()."; se intentó restaurar el código. Las tablas nuevas pueden permanecer.\n");exit(2);
}
