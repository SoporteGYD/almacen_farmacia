<?php
declare(strict_types=1);
/** Instalación incremental V41, CLI y laboratorio MariaDB prueba:3307 solamente. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Ejecución exclusiva por consola.');}
$mode=$argv[1]??'--check';
if(!in_array($mode,['--check','--apply','--verify','--seed'],true)){
    fwrite(STDERR,"Uso: php tools/instalar_rewards_lab_canjes_v41.php --check|--apply|--verify|--seed\n");exit(2);
}
try {
    $root=realpath(__DIR__.'/..');
    if(!$root)throw new RuntimeException('Raíz del ERP no localizada.');
    $m=json_decode((string)file_get_contents(__DIR__.'/manifest_rewards_lab_canjes_v41.json'),true,512,JSON_THROW_ON_ERROR);
    if(($m['version']??'')!=='V41')throw new RuntimeException('Manifiesto de versión incorrecto.');
    $payload=__DIR__.'/payload_rewards_lab_v41';$pendientes=[];$problemas=[];
    foreach($m['files'] as $ruta=>$item){
        if(str_contains($ruta,'..')||str_starts_with($ruta,'/'))throw new RuntimeException('Ruta insegura: '.$ruta);
        $from=$payload.'/'.$item['payload'];$to=$root.'/'.$ruta;
        if(!is_file($from)||hash_file('sha256',$from)!==$item['new'])throw new RuntimeException('Archivo de actualización alterado: '.$ruta);
        $actual=is_file($to)?hash_file('sha256',$to):null;
        if($actual===$item['new'])$status='INSTALADO';
        elseif($actual===null && empty($item['allowed'])){$status='CREAR';$pendientes[$ruta]=$item;}
        elseif($actual!==null && in_array($actual,$item['allowed'],true)){$status='ACTUALIZAR';$pendientes[$ruta]=$item;}
        else {$status='INCOMPATIBLE';$problemas[]='Archivo diferente a V40: '.$ruta;}
        echo str_pad($status,15).$ruta.PHP_EOL;
    }
    foreach([
        'app/config/database.php','app/helpers/auth.php','app/helpers/gyd_rewards_lab_v40.php',
        'app/helpers/gyd_rewards_catalogo_v38.php','app/helpers/gyd_clientes_rewards.php',
        'app/helpers/gyd_rewards_reversiones_v37.php',
        'public/gyd_lab_v40.php','public/gyd_lab_v40_api.php',
        'public/gyd_canjes_api.php'
    ] as $req)if(!is_file($root.'/'.$req))$problemas[]='Falta dependencia V40: '.$req;
    if($problemas)throw new RuntimeException("BLOQUEADO: \n- ".implode("\n- ",$problemas));
    require_once $root.'/app/config/database.php';
    $db=(new Database())->connect();
    $info=$db->query('SELECT DATABASE() AS nombre,@@port AS puerto')->fetch(PDO::FETCH_ASSOC);
    if(strtolower((string)($info['nombre']??''))!=='prueba'||(int)($info['puerto']??0)!==3307)
        throw new RuntimeException('Base no permitida. Exige prueba:3307.');
    foreach([
      'gyd_rewards_lab_clientes_v40'=>['cliente_id','codigo'],
      'gyd_rewards_lab_productos_v40'=>['sucursal_id','producto_id','precio_centavos','costo_centavos','stock_virtual','puntos_requeridos','escenario'],
      'gyd_rewards_lab_auditoria_v40'=>['sucursal_id','producto_id','accion'],
      'gyd_clientes'=>['id','codigo','nombre','acepta_programa','activo'],
      'gyd_puntos_movimientos'=>['cliente_id','puntos'],
      'productos'=>['id','descripcion','estado'],
      'pos_precios'=>['producto_id','sucursal_id','precio_publico'],
      'lotes'=>['producto_id','costo_unitario'],
      'pos_sucursales'=>['id','activo'],
      'pos_ventas'=>['id','estado'],
      'pos_reversiones'=>['venta_id','tipo','estado'],
      'gyd_clientes_ventas'=>['cliente_id','venta_id']
    ] as $table=>$cols){
      $q=$db->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);
      $found=$q->fetchAll(PDO::FETCH_COLUMN);
      foreach($cols as $c)if(!in_array($c,$found,true))throw new RuntimeException('Esquema V40 incompleto: '.$table.'.'.$c);
    }
    $requeridos=['id','clave','cliente_id','sucursal_id','producto_id','puntos_gastados','costo_centavos','saldo_antes','saldo_despues','stock_antes','stock_despues','usuario_id','creado_en'];
    $checkTable=function(bool $required) use($db,$requeridos):void {
        $st=$db->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $st->execute(['gyd_rewards_lab_canjes_v41']);$cols=$st->fetchAll(PDO::FETCH_COLUMN);
        if(!$cols && !$required)return;
        foreach($requeridos as $c)if(!in_array($c,$cols,true))throw new RuntimeException('Falta columna V41 '.$c);
        $st=$db->prepare('SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $st->execute(['gyd_rewards_lab_canjes_v41']);
        if(strtoupper((string)$st->fetchColumn())!=='INNODB')throw new RuntimeException('La tabla V41 debe usar InnoDB.');
        $st=$db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gyd_rewards_lab_canjes_v41' AND INDEX_NAME='clave' AND NON_UNIQUE=0");
        if((int)$st->fetchColumn()<1)throw new RuntimeException('Falta índice único por clave de canje.');
    };
    $checkTable(in_array($mode,['--verify','--seed'],true));
    if($mode==='--check'){
        echo 'COMPATIBLE V40 + prueba:3307. Pendientes: '.count($pendientes).'. Ningún dato modificado.'.PHP_EOL;exit(0);
    }
    if($mode==='--verify'){
        if($pendientes)throw new RuntimeException('Primero instala todos los archivos V41.');
        $n=(int)$db->query('SELECT COUNT(*) FROM gyd_rewards_lab_canjes_v41')->fetchColumn();
        $p=(int)$db->query("SELECT COUNT(*) FROM gyd_rewards_lab_productos_v40 WHERE escenario='CANJE_ALEATORIO_V41'")->fetchColumn();
        echo "VERIFICADO V41. Premios aleatorios: $p. Canjes virtuales: $n. Inventario real intacto.\n";exit(0);
    }
    if($mode==='--seed'){
        if($pendientes)throw new RuntimeException('Ejecuta --apply antes de --seed.');
        require_once $root.'/app/helpers/gyd_rewards_lab_v41.php';
        $report=[];
        foreach($db->query('SELECT id,nombre FROM pos_sucursales WHERE activo=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $s){
            $report[$s['nombre']]=GydRewardsLabV41::agregarAleatorios($db,(int)$s['id'],1);
        }
        echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";exit(0);
    }
    // --apply: respaldo previo; SQL sólo crea tabla V41; archivos sujetos a SHA-256.
    $backup=dirname(dirname($root)).'/gyd_resguardos_codigo/lab_canjes_v41_'.date('Ymd_His').'_'.bin2hex(random_bytes(4));
    if(!mkdir($backup,0770,true))throw new RuntimeException('No se pudo crear el respaldo.');
    foreach($pendientes as $ruta=>$entry){
        $old=$root.'/'.$ruta;if(!is_file($old))continue;
        $copy=$backup.'/'.$ruta;if(!is_dir(dirname($copy))&&!mkdir(dirname($copy),0770,true))throw new RuntimeException('Fallo directorio respaldo.');
        if(!copy($old,$copy)||hash_file('sha256',$copy)!==hash_file('sha256',$old))throw new RuntimeException('Respaldo incompleto: '.$ruta);
    }
    $sql=(string)file_get_contents(__DIR__.'/esquema_rewards_lab_v41.sql');
    $sql=(string)preg_replace('/^\s*--[^\r\n]*\r?\n/m','',$sql);
    foreach(explode(';',$sql) as $command)if(trim($command)!=='')$db->exec(trim($command));
    $checkTable(true);
    $done=[];
    try {
        foreach($pendientes as $ruta=>$entry){
            $from=$payload.'/'.$entry['payload'];$to=$root.'/'.$ruta;
            if(!is_dir(dirname($to))&&!mkdir(dirname($to),0770,true))throw new RuntimeException('Error creando directorio '.$ruta);
            $tmp=dirname($to).'/.gyd_v41_'.bin2hex(random_bytes(5)).'.tmp';
            if(!copy($from,$tmp)||hash_file('sha256',$tmp)!==$entry['new'])throw new RuntimeException('Copia alterada '.$ruta);
            $done[]=$ruta;
            if(!rename($tmp,$to)){if(!copy($tmp,$to))throw new RuntimeException('No se pudo reemplazar '.$ruta);@unlink($tmp);}
            if(hash_file('sha256',$to)!==$entry['new'])throw new RuntimeException('Hash final inválido '.$ruta);
        }
        require_once $root.'/app/helpers/gyd_rewards_lab_v41.php';
        $resultados=[];
        foreach($db->query('SELECT id,nombre FROM pos_sucursales WHERE activo=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $s){
            $resultados[$s['nombre']]=GydRewardsLabV41::agregarAleatorios($db,(int)$s['id'],1);
        }
        echo "V41 INSTALADA en prueba:3307. Respaldo: $backup\n";
        echo 'Productos iniciales: '.json_encode($resultados,JSON_UNESCAPED_UNICODE)."\n";
        echo "Abre public/gyd_lab_v41.php. SOLO CANJES VIRTUALES.\n";
    } catch(Throwable $e){
        foreach(array_reverse($done) as $ruta){$bk=$backup.'/'.$ruta;if(is_file($bk))@copy($bk,$root.'/'.$ruta);else @unlink($root.'/'.$ruta);}
        throw new RuntimeException('Fallo aplicando V41; se intentó restaurar el código. La tabla y datos de prueba pueden persistir: '.$e->getMessage(),0,$e);
    }
} catch(Throwable $e){fwrite(STDERR,'BLOQUEADO V41: '.$e->getMessage()."\n");exit(2);}
