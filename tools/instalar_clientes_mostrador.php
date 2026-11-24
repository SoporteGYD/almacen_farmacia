<?php
declare(strict_types=1);
/** Actualización incremental de clientes POS; sólo MariaDB prueba:3307. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Ejecutar desde PHP CLI.');}
$action=$argv[1]??'--check';
if(!in_array($action,['--check','--apply','--verify','--restore'],true)){fwrite(STDERR,"Uso: php instalar_clientes_mostrador.php --check|--apply|--verify|--restore\n");exit(2);}
$tools=__DIR__;$root=dirname(__DIR__);$backDir=$tools.'/backups_clientes_mostrador';
$paths=[
 'app/helpers/gyd_clientes_rewards.php',
 'public/gyd_clientes_api.php',
 'public/assets/js/gyd_clientes_pos.js',
 'public/assets/css/gyd_clientes_pos.css',
 'public/pos.php',
 'app/helpers/gyd_clientes_mostrador.php'
];
function msg(string $s):void{echo $s.PHP_EOL;}
function pth(string $r):string{global $root;return $root.'/'.$r;}
function patch(string $src,string $needle,string $replace,string $name):string{
 if(str_contains($src,$replace))return $src;
 if(substr_count($src,$needle)!==1)throw new RuntimeException("Incompatible: no se encontró el bloque esperado en $name (sin cambios).");
 return str_replace($needle,$replace,$src);
}
function replacements(array $files):array{
 $helper='app/helpers/gyd_clientes_rewards.php';$api='public/gyd_clientes_api.php';$ui='public/pos.php';
 $h=$files[$helper];
 $h=patch($h,"require_once __DIR__.'/gyd_rewards_reversiones_v37.php';","require_once __DIR__.'/gyd_rewards_reversiones_v37.php';\nrequire_once __DIR__.'/gyd_clientes_mostrador.php';",$helper);
 $start='    public static function validar(PDO $db,int $id,bool $bloquear=false):array {';
 $end='    private static function saneaNombre(string $v):string {';
 if(!str_contains($h,'// INICIO MOSTRADOR VALIDAR')){
    $a=strpos($h,$start);$b=strpos($h,$end);
    if($a===false||$b===false||$b<=$a)throw new RuntimeException('Incompatible: método validar no coincide.');
    $block=<<<'CODE'
    // INICIO MOSTRADOR VALIDAR
    public static function validar(PDO $db,int $id,bool $bloquear=false,?int $sucursal=null):array {
        $r=self::q($db,'SELECT id,nombre,activo,acepta_programa FROM gyd_clientes WHERE id=?'.($bloquear?' FOR UPDATE':''),[$id])->fetch(PDO::FETCH_ASSOC);
        if(!$r || (int)$r['activo']!==1)throw new DomainException('Cliente inexistente o inactivo.');
        $sucursalMostrador=GydClientesMostrador::sucursalDeCliente($db,$id);
        if($sucursalMostrador!==null){
            if($sucursal!==null && $sucursalMostrador!==$sucursal)throw new DomainException('Esta Venta de mostrador pertenece a otra sucursal.');
            $r['es_mostrador']=true;
            $r['sucursal_mostrador']=$sucursalMostrador;
            return $r;
        }
        if((int)$r['acepta_programa']!==1)throw new DomainException('Cliente sin consentimiento para el programa de puntos.');
        $r['es_mostrador']=false;
        return $r;
    }
    // FIN MOSTRADOR VALIDAR
CODE;
    $h=substr($h,0,$a).$block."\n".substr($h,$b);
 }
 $edit='    public static function editar(PDO $db,int $id,array $data):array {';
 if(!str_contains($h,'No se puede modificar una cuenta de Venta de mostrador')){
   $h=patch($h,$edit,$edit."\n        if(GydClientesMostrador::sucursalDeCliente(\$db,\$id)!==null)throw new DomainException('No se puede modificar una cuenta de Venta de mostrador.');",$helper);
 }
 $ac='    public static function acumular(PDO $db,int $cliente,int $venta,int $sucursal,array $detalle,int $usuario):array {';
 if(!str_contains($h,'// INICIO MOSTRADOR SIN PUNTOS')){
   $addition=<<<'CODE'
        // INICIO MOSTRADOR SIN PUNTOS
        $sucursalMostrador=GydClientesMostrador::sucursalDeCliente($db,$cliente);
        if($sucursalMostrador!==null){
            if($sucursalMostrador!==$sucursal)throw new DomainException('No se puede registrar una venta de mostrador en otra sucursal.');
            self::validar($db,$cliente,true,$sucursal);
            self::q($db,'INSERT INTO gyd_clientes_ventas(venta_id,cliente_id,sucursal_id,puntos_otorgados,ingreso_elegible_centavos,utilidad_referencia_centavos,registrado_por) VALUES (?,?,?,0,0,0,?)',[$venta,$cliente,$sucursal,$usuario]);
            return ['cliente_id'=>$cliente,'puntos'=>0,'monto_elegible'=>'0.00','renglones_excluidos'=>count($detalle),'saldo_estimado'=>0,'canje_habilitado'=>false,'es_mostrador'=>true];
        }
        // FIN MOSTRADOR SIN PUNTOS
CODE;
   $h=patch($h,$ac,$ac."\n".$addition,$helper);
 }
 $files[$helper]=$h;
 $a=$files[$api];
 $old="        if(\$action==='buscar')respuesta(['clientes'=>GydClientesRewards::buscar(\$db,(string)(\$_GET['q']??''))]);";
 $new=<<<'CODE'
        if($action==='listar'){
            require_once __DIR__.'/../app/models/PuntoVenta.php';
            $pos=new PuntoVenta();$caja=(int)($_GET['caja_id']??0);
            $info=$pos->cajaAutorizada($u,$caja);
            respuesta(GydClientesMostrador::listar($db,(int)$info['id'],(string)($_GET['q']??''),(int)($_GET['pagina']??1)));
        }
        if($action==='buscar')respuesta(['clientes'=>GydClientesRewards::buscar($db,(string)($_GET['q']??''))]);
CODE;
 if(!str_contains($a,"if(\$action==='listar')"))$a=patch($a,$old,$new,$api);
 $old2='$caja=(int)($body[\'caja_id\']??0);$pos=new PuntoVenta();$pos->cajaAutorizada($u,$caja);';
 $new2='$caja=(int)($body[\'caja_id\']??0);$pos=new PuntoVenta();$cajaInfo=$pos->cajaAutorizada($u,$caja);';
 $a=patch($a,$old2,$new2,$api);
 $a=patch($a,'if($id!==null)GydClientesRewards::validar($db,$id);','if($id!==null)GydClientesRewards::validar($db,$id,false,(int)$cajaInfo[\'id\']);',$api);
 $files[$api]=$a;
 $p=$files[$ui];
 $p=str_replace('Escribe al menos 2 caracteres para buscar.','Selecciona un cliente de la tabla o utiliza el buscador.',$p);
 $p=str_replace('Clientes G&D · Puntos','Clientes G&D · Seleccionar cliente',$p);
 $p=str_replace('assets/js/gyd_clientes_pos.js?v=36','assets/js/gyd_clientes_pos.js?v=listado-clientes',$p);
 $p=str_replace('assets/css/gyd_clientes_pos.css?v=36','assets/css/gyd_clientes_pos.css?v=listado-clientes',$p);
 $files[$ui]=$p;
 $files['public/assets/js/gyd_clientes_pos.js']=file_get_contents(__DIR__.'/payload/public/assets/js/gyd_clientes_pos.js');
 $css=$files['public/assets/css/gyd_clientes_pos.css'];
 if(!str_contains($css,'/* Inicio: clientes en tabla y venta de mostrador */')){
   $css.="\n".file_get_contents(__DIR__.'/payload/listado_clientes.css');
 }
 $files['public/assets/css/gyd_clientes_pos.css']=$css;
 $files['app/helpers/gyd_clientes_mostrador.php']=file_get_contents(__DIR__.'/payload/app/helpers/gyd_clientes_mostrador.php');
 return $files;
}
function db():PDO{
 require_once pth('app/config/database.php');
 $pdo=(new Database())->connect();
 $ctx=$pdo->query('SELECT DATABASE() base,@@port puerto')->fetch(PDO::FETCH_ASSOC);
 if(strtolower((string)($ctx['base']??''))!=='prueba'||(int)($ctx['puerto']??0)!==3307)throw new RuntimeException('Sólo se permite base prueba en puerto 3307.');
 return $pdo;
}
function sucursales(PDO $pdo):array {
 $r=$pdo->query('SELECT id,nombre FROM pos_sucursales WHERE activo=1')->fetchAll(PDO::FETCH_ASSOC);
 $map=[];foreach($r as $fila)$map[mb_strtoupper(trim((string)$fila['nombre']),'UTF-8')]=(int)$fila['id'];
 $spec=[
  'TAPACHULA'=>['MOST-TAP','Venta de mostrador de Tapachula'],
  'PLAZA TOSCANA'=>['MOST-TOS','Venta de mostrador de Plaza Toscana'],
  'CIUDAD HIDALGO'=>['MOST-HID','Venta de mostrador de Ciudad Hidalgo'],
  'TUXTLA GUTIERREZ'=>['MOST-TUX','Venta de mostrador de Tuxtla Gutiérrez'],
 ];
 foreach($spec as $n=>$value)if(!isset($map[$n]))throw new RuntimeException('No existe la sucursal '.$n.' en la BD.');
 return [$map,$spec];
}
function create(PDO $pdo):void{
 [$map,$spec]=sucursales($pdo);
 $pdo->exec('CREATE TABLE IF NOT EXISTS gyd_clientes_mostrador (sucursal_id INT NOT NULL PRIMARY KEY, cliente_id BIGINT UNSIGNED NOT NULL UNIQUE, creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
 $pdo->beginTransaction();
 try{
  $sel=$pdo->prepare('SELECT id,nombre FROM gyd_clientes WHERE codigo=? FOR UPDATE');
  $ins=$pdo->prepare('INSERT INTO gyd_clientes(codigo,nombre,telefono,correo,acepta_programa,consentimiento_en,activo,creado_por) VALUES(?,?,NULL,NULL,0,NULL,1,NULL)');
  $selMap=$pdo->prepare('SELECT cliente_id FROM gyd_clientes_mostrador WHERE sucursal_id=? FOR UPDATE');
  $selInv=$pdo->prepare('SELECT sucursal_id FROM gyd_clientes_mostrador WHERE cliente_id=? FOR UPDATE');
  $insMap=$pdo->prepare('INSERT INTO gyd_clientes_mostrador(sucursal_id,cliente_id) VALUES (?,?)');
  foreach($spec as $n=>[$codigo,$nombre]){
   $sid=$map[$n];$sel->execute([$codigo]);$existing=$sel->fetch(PDO::FETCH_ASSOC);
   if(!$existing){$ins->execute([$codigo,$nombre]);$cid=(int)$pdo->lastInsertId();}
   else{$cid=(int)$existing['id'];if((string)$existing['nombre']!==$nombre)throw new RuntimeException('Código reservado para mostrador ya usado: '.$codigo);}
   $selMap->execute([$sid]);$curr=$selMap->fetchColumn();
   $selInv->execute([$cid]);$other=$selInv->fetchColumn();
   if(($curr!==false&&(int)$curr!==$cid)||($other!==false&&(int)$other!==$sid))throw new RuntimeException('Asignación de mostrador inconsistente en '.$n.'.');
   if($curr===false)$insMap->execute([$sid,$cid]);
  }
  $pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function lintPHP(string $path):void{
 $cmd=escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path).' 2>&1';
 exec($cmd,$out,$status);if($status!==0)throw new RuntimeException('Error de sintaxis PHP en '.$path.': '.implode(' ',$out));
}
try{
 if($action==='--restore'){
    $f=$backDir.'/latest.json';if(!is_file($f))throw new RuntimeException('No hay respaldo de archivos disponible.');
    $manifest=json_decode((string)file_get_contents($f),true,512,JSON_THROW_ON_ERROR);$dir=$manifest['dir'];
    foreach($manifest['archivos'] as $r=>$exists){$dest=pth($r);if($exists){copy($dir.'/'.$r,$dest);}elseif(is_file($dest))unlink($dest);}
    msg('Archivos restaurados. No se eliminan clientes ni datos para preservar la auditoría.');exit(0);
 }
 $files=[];foreach($paths as $rel){$f=pth($rel);if(!is_file($f)&&$rel!=='app/helpers/gyd_clientes_mostrador.php')throw new RuntimeException('Falta '.$rel);$files[$rel]=is_file($f)?(string)file_get_contents($f):'';}
 $out=replacements($files);
 // Prevalidar sintaxis en temporales sin afectar el ERP.
 foreach($out as $rel=>$data){if(str_ends_with($rel,'.php')){$tmp=tempnam(sys_get_temp_dir(),'gydlint');file_put_contents($tmp,$data);try{lintPHP($tmp);}finally{unlink($tmp);}}}
 if($action==='--check'){
   $pdo=db();sucursales($pdo);
   msg('COMPATIBLE · base prueba:3307 · 4 sucursales encontradas · cambios preparados sin modificar datos.');exit(0);
 }
 if($action==='--verify'){
   $pdo=db();[$map,$spec]=sucursales($pdo);
   $rows=$pdo->query('SELECT s.nombre,c.codigo,c.nombre cliente,c.acepta_programa FROM gyd_clientes_mostrador m JOIN pos_sucursales s ON s.id=m.sucursal_id JOIN gyd_clientes c ON c.id=m.cliente_id ORDER BY s.id')->fetchAll(PDO::FETCH_ASSOC);
   if(count($rows)!==4)throw new RuntimeException('No están registradas las cuatro Ventas de mostrador.');
   foreach($rows as $row){$name=mb_strtoupper((string)$row['nombre'],'UTF-8');if(!isset($spec[$name])||$row['codigo']!==$spec[$name][0]||(int)$row['acepta_programa']!==0)throw new RuntimeException('Registro de mostrador inválido: '.$name);}
   foreach($out as $rel=>$contents){if($contents!==$files[$rel])throw new RuntimeException('No está aplicado el cambio en '.$rel);}
   msg('VERIFICADO · tabla y cuatro clientes de mostrador · código actualizado.');exit(0);
 }
 $pdo=db();sucursales($pdo);
 if(!is_dir($backDir))mkdir($backDir,0770,true);
 $dir=$backDir.'/'.date('Ymd_His').'_'.bin2hex(random_bytes(3));mkdir($dir,0770,true);
 $manifest=['dir'=>$dir,'archivos'=>[]];
 foreach($paths as $rel){$dest=pth($rel);$exists=is_file($dest);$manifest['archivos'][$rel]=$exists;if($exists){$backup=$dir.'/'.$rel;if(!is_dir(dirname($backup)))mkdir(dirname($backup),0770,true);copy($dest,$backup);}}
 $changed=[];
 try {
   foreach($out as $rel=>$data){$dest=pth($rel);if($data===$files[$rel])continue;if(!is_dir(dirname($dest)))mkdir(dirname($dest),0770,true);$tmp=$dest.'.tmp_'.bin2hex(random_bytes(4));file_put_contents($tmp,$data);if(!rename($tmp,$dest))throw new RuntimeException('No se pudo escribir '.$rel);$changed[]=$rel;}
   create($pdo);
   file_put_contents($backDir.'/latest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
 }catch(Throwable $e){foreach($manifest['archivos'] as $rel=>$ex){if($ex)copy($dir.'/'.$rel,pth($rel));elseif(is_file(pth($rel)))unlink(pth($rel));}throw $e;}
 msg('APLICADO · cuatro clientes Venta de mostrador independientes; tabla paginada en POS.');
 msg('Respaldo de archivos: '.$dir);msg('Ejecuta ahora --verify.');
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage().PHP_EOL);exit(1);}
