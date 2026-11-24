<?php
declare(strict_types=1);
/** Instalador estricto V43 basado en el ZIP recibido 12-nov-2026. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo consola.');}
$accion=$argv[1]??'--check';
if(!in_array($accion,['--check','--apply','--verify','--seed'],true)){
    fwrite(STDERR,"Uso: php tools/instalar_rewards_productos_v43.php --check|--apply|--verify|--seed\n");exit(2);
}
try {
    $raiz=realpath(__DIR__.'/..');
    if(!$raiz)throw new RuntimeException('No se encontró raíz del ERP.');
    $manifest=json_decode((string)file_get_contents(__DIR__.'/manifest_rewards_productos_v43.json'),true,512,JSON_THROW_ON_ERROR);
    if(($manifest['version']??'')!=='V43')throw new RuntimeException('Manifiesto incorrecto.');
    $payload=__DIR__.'/payload_rewards_productos_v43';
    $pendientes=[];$problemas=[];
    foreach($manifest['files'] as $ruta=>$datos){
        if(str_starts_with($ruta,'/') || str_contains($ruta,'..'))throw new RuntimeException('Ruta no permitida.');
        $src=$payload.'/'.$ruta;$dst=$raiz.'/'.$ruta;
        if(!is_file($src)||hash_file('sha256',$src)!==$datos['nuevo'])throw new RuntimeException('Archivo del paquete alterado: '.$ruta);
        $actual=is_file($dst)?hash_file('sha256',$dst):null;
        if($actual===$datos['nuevo'])$estado='YA INSTALADO';
        elseif($actual===null && $datos['origen']===null){$estado='NUEVO';$pendientes[$ruta]=true;}
        elseif($actual!==null && hash_equals((string)$datos['origen'],$actual)){$estado='REEMPLAZAR';$pendientes[$ruta]=true;}
        else {$estado='DIFERENTE';$problemas[]='Archivo con cambios propios, no sobrescrito: '.$ruta;}
        echo str_pad($estado,17).' '.$ruta.PHP_EOL;
    }
    foreach(['app/config/database.php','app/helpers/auth.php','app/helpers/gyd_rewards_lab_v41.php','app/helpers/gyd_rewards_lab_v40.php',
        'app/helpers/gyd_rewards_catalogo_v38.php','app/helpers/gyd_clientes_rewards.php'] as $dependencia){
        if(!is_file($raiz.'/'.$dependencia))$problemas[]='Falta archivo base: '.$dependencia;
    }
    if($problemas)throw new RuntimeException("BLOQUEADO: \n - ".implode("\n - ",$problemas));
    require_once $raiz.'/app/config/database.php';
    $db=(new Database())->connect();
    $env=$db->query('SELECT DATABASE() base,@@port puerto')->fetch(PDO::FETCH_ASSOC);
    if(strtolower((string)($env['base']??''))!=='prueba' || (int)($env['puerto']??0)!==3307)
        throw new RuntimeException('NO SE MODIFICÓ NADA: el servidor debe ser prueba:3307.');
    $requisitos=[
        'gyd_rewards_lab_productos_v40'=>['sucursal_id','producto_id','stock_virtual','puntos_requeridos','escenario','costo_centavos','precio_centavos'],
        'gyd_rewards_lab_auditoria_v40'=>['sucursal_id','producto_id','accion'],
        'gyd_rewards_lab_canjes_v41'=>['cliente_id','producto_id','puntos_gastados'],
        'gyd_clientes'=>['codigo','nombre'],
        'gyd_rewards_lab_clientes_v40'=>['cliente_id'],
        'gyd_puntos_movimientos'=>['cliente_id','puntos'],
        'productos'=>['id','estado','costo_ultimo','costo_promedio','precio_compra'],
        'pos_precios'=>['producto_id','sucursal_id','precio_publico'],
        'pos_sucursales'=>['id','activo'],
        'lotes'=>['producto_id','costo_unitario'],
        'usuarios'=>['id'],
    ];
    $st=$db->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach($requisitos as $tabla=>$cols){
        $st->execute([$tabla]);$existentes=$st->fetchAll(PDO::FETCH_COLUMN);
        foreach($cols as $col)if(!in_array($col,$existentes,true))throw new RuntimeException('Base de datos incompleta: '.$tabla.'.'.$col);
    }
    $actor=(int)$db->query('SELECT MIN(id) FROM usuarios')->fetchColumn();
    if($actor<1)throw new RuntimeException('La base necesita al menos un usuario existente para auditar los cambios.');
    $sucursales=$db->query('SELECT id,nombre FROM pos_sucursales WHERE activo=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    if(!$sucursales)throw new RuntimeException('No hay sucursales activas en prueba.');
    if($accion==='--check'){
        echo "COMPATIBLE V41 + prueba:3307. Archivos pendientes: ".count($pendientes).". Cero cambios realizados.\n";exit(0);
    }
    if($accion==='--verify'){
        if($pendientes)throw new RuntimeException('Falta aplicar V43.');
        require_once $raiz.'/app/helpers/gyd_rewards_lab_v43.php';
        foreach($sucursales as $s){
            $q=$db->prepare("SELECT COUNT(*) FROM gyd_rewards_lab_productos_v40 WHERE sucursal_id=? AND escenario IN ('V43_COSTO_LOTE','V43_COSTO_PRODUCTO','V43_COSTO_SIMULADO')");
            $q->execute([(int)$s['id']]);
            echo $s['nombre'].': '.(int)$q->fetchColumn()." productos de laboratorio V43\n";
        }
        echo "V43 verificada. Ventas, puntos operativos e inventarios reales sin modificar por este instalador.\n";exit(0);
    }
    if($accion==='--seed' && $pendientes)throw new RuntimeException('Primero ejecuta --apply.');
    $respaldo=null;$copiados=[];
    if($accion==='--apply'){
        // Respaldo de archivos FUERA del directorio público, sin incluir credenciales ni la base.
        $respaldo=dirname(dirname($raiz)).'/gyd_resguardo_rewards_v43_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
        if($pendientes && !mkdir($respaldo,0700,true))throw new RuntimeException('No fue posible crear un respaldo de archivos.');
        foreach($pendientes as $ruta=>$_){
            if(!is_file($raiz.'/'.$ruta))continue;
            $destino=$respaldo.'/'.$ruta;
            if(!is_dir(dirname($destino))&&!mkdir(dirname($destino),0700,true))throw new RuntimeException('No pudo crearse el directorio de respaldo.');
            if(!copy($raiz.'/'.$ruta,$destino) || hash_file('sha256',$destino)!==hash_file('sha256',$raiz.'/'.$ruta))
                throw new RuntimeException('Respaldo fallido: '.$ruta);
        }
        try {
            foreach($pendientes as $ruta=>$_){
                $destino=$raiz.'/'.$ruta;$fuente=$payload.'/'.$ruta;
                if(!is_dir(dirname($destino))&&!mkdir(dirname($destino),0750,true))throw new RuntimeException('No se pudo crear directorio destino.');
                $temporal=dirname($destino).'/.gyd_v43_'.bin2hex(random_bytes(5)).'.tmp';
                if(!copy($fuente,$temporal) || hash_file('sha256',$temporal)!==$manifest['files'][$ruta]['nuevo'])throw new RuntimeException('Copia temporal inválida: '.$ruta);
                if(!rename($temporal,$destino)){@unlink($temporal);throw new RuntimeException('No se pudo instalar '.$ruta);}
                $copiados[]=$ruta;
            }
        } catch(Throwable $e){
            foreach(array_reverse($copiados) as $ruta){
                $anterior=$respaldo.'/'.$ruta;$destino=$raiz.'/'.$ruta;
                if(is_file($anterior))@copy($anterior,$destino);else @unlink($destino);
            }
            throw $e;
        }
    }
    require_once $raiz.'/app/helpers/gyd_rewards_lab_v43.php';
    if($accion==='--apply'){
        // Sólo si no hay clientes de prueba; el respaldo recibido ya trae ocho.
        $n=(int)$db->query('SELECT COUNT(*) FROM gyd_rewards_lab_clientes_v40')->fetchColumn();
        if($n===0){
            $res=GydRewardsLabV40::sembrar($db,$actor);
            echo 'Clientes de laboratorio inicializados: '.json_encode($res,JSON_UNESCAPED_UNICODE)."\n";
        }
    }
    $resumen=[];
    foreach($sucursales as $s){
        $resumen[$s['nombre']]=$accion==='--seed'
          ?GydRewardsLabV43::agregar($db,(int)$s['id'],$actor,20)
          :GydRewardsLabV43::completar($db,(int)$s['id'],$actor);
    }
    echo "V43 instalada en prueba:3307. Nuevos premios por sucursal:\n";
    foreach($resumen as $nombre=>$r){
        echo " - $nombre: ".$r['agregados']." nuevos";
        if(isset($r['origenes']))echo ' (costos registrados: '.(($r['origenes']['LOTE']??0)+($r['origenes']['CATALOGO']??0)).', simulados: '.($r['origenes']['SIMULADO']??0).')';
        echo "\n";
    }
    if($respaldo)echo "Respaldo de código: $respaldo\n";
    echo "Abrir: http://localhost/almacen-farmacia2/public/gyd_lab_v41.php\n";
} catch(Throwable $e){fwrite(STDERR,'V43 NO COMPLETADA: '.$e->getMessage()."\n");exit(2);}
