<?php
declare(strict_types=1);
/** ERP G&D · F2 Centralización Catálogo Maestro · Instalador para proyecto de laboratorio.
 * Usar php tools/instalar_f2_catalogo_unico.php --check|--apply|--verify
 * No ejecuta SQL ni cambia existencias. --apply valida prueba:3307.
 */
const GYD_F2_OLD=[
  'public/erp_catalogo_maestro.php'=>'1b309be0990a228b5eec297c8b1b19f1f0aa1de2d20a251851ba56add6cbc59c',
  'public/pos_ubicaciones_config.php'=>'d21d7512ba198306153ce54500db4675d97751ab5a553edcb3fd07f8c6a823c0',
  'public/pos.php'=>'b7c712b83397fa0fe0573ed41c2b9c4fb4d1b226902816313d7f159b5343a199',
  'public/erp_facturas_aplicar.php'=>'c02d342d7bdca2f591c349233a70222c935c68970413b9ac07a6363dc7f102bc',
  'app/helpers/erp_facturas.php'=>'d3fe8ba23bba8363470e93af027b0480d12c1bc12b5f0dee0d7fb225a418c6b9',
  'app/views/layouts/header.php'=>'afabd6381d90dfa91fe9c722a452a965f009a9507f97c36bb97bc599b4366c21',
  'app/helpers/gyd_catalogo_operativo.php'=>null,
];
const GYD_F2_NEW=[
  'public/erp_catalogo_maestro.php'=>'3a0bc13afc99ab2a0cbb83799d0e52d46783a3713ea1eb6e017114773928a741',
  'public/pos_ubicaciones_config.php'=>'21dd1f7fa7329e5382cea32e30453825a567aa3cb28be0ac9e319fe906d34eee',
  'public/pos.php'=>'1de4989c96e9a308dd76d7d0cfc427ce6a98f04fc9def77ef7bbf8cdd31739af',
  'public/erp_facturas_aplicar.php'=>'e72f7befa20b5711500ecc0c3e0c1d412e6a45db742aa37d1da525848a415372',
  'app/helpers/erp_facturas.php'=>'b1b6390f7c4c4aec4dc3b77a85c5d69bb8ca509b4b6261595e46feaa3adb476d',
  'app/views/layouts/header.php'=>'ed0b8a8d145f32289a2de92fbed7091ee31d754c03af59a14c5bbc95db18cd57',
  'app/helpers/gyd_catalogo_operativo.php'=>'0caf315ff98c8b65bf964519102cbce899f1fac7fd324622007088933fcdf909',
];
$cmd=$argv[1]??'--check';
if(!in_array($cmd,['--check','--apply','--verify'],true)){
    fwrite(STDERR,"Uso: php tools/instalar_f2_catalogo_unico.php --check | --apply | --verify\n");exit(2);
}
$root=realpath(__DIR__.'/..');
if(!$root){fwrite(STDERR,"No se encuentra la raíz del ERP.\n");exit(2);}
$payload=__DIR__.'/payload_f2_catalogo_unico';
$statuses=[];$errors=[];
foreach(GYD_F2_NEW as $rel=>$newHash){
    $dest=$root.'/'.$rel;$src=$payload.'/'.$rel;$oldHash=GYD_F2_OLD[$rel];
    if(!is_file($src)||hash_file('sha256',$src)!==$newHash){$errors[]='El archivo de actualización falta o fue alterado: '.$rel;continue;}
    $cur=is_file($dest)?hash_file('sha256',$dest):null;
    if($cur===$newHash){$statuses[$rel]='INSTALADO';}
    elseif($cur===$oldHash){$statuses[$rel]='MODIFICAR';}
    else{$statuses[$rel]='INCOMPATIBLE';$errors[]='La versión local de '.$rel.' no coincide. No sobrescribirla.';}
}
foreach($statuses as $rel=>$state)echo str_pad($state,14).' '.$rel.PHP_EOL;
if($errors){foreach($errors as $error)fwrite(STDERR,'ERROR: '.$error.PHP_EOL);echo "INCOMPATIBLE. No se modificó ningún archivo.\n";exit(1);}
if($cmd==='--check'){echo "COMPATIBLE: ".count(array_filter($statuses,fn($x)=>$x==='MODIFICAR'))." archivos pendientes. No se modificó nada.\n";exit;}
if($cmd==='--verify'){
    if(count(array_filter($statuses,fn($x)=>$x!=='INSTALADO'))){fwrite(STDERR,"PENDIENTE: hay archivos todavía sin instalar.\n");exit(1);}
    echo "VERIFICADO: todos los archivos coinciden con la actualización. MySQL no se modificó.\n";exit;
}
$pending=array_keys(array_filter($statuses,fn($x)=>$x==='MODIFICAR'));
// Crear primero el helper nuevo para evitar una referencia temporal sin archivo.
usort($pending,static fn($a,$b)=>($a==='app/helpers/gyd_catalogo_operativo.php'?-1:0)<=>($b==='app/helpers/gyd_catalogo_operativo.php'?-1:0));
if(!$pending){echo "YA INSTALADO: no se repitió la actualización.\n";exit;}
// Sólo instalación en laboratorio. La comprobación usa la misma conexión que usa el ERP.
require_once $root.'/app/config/database.php';
try {
    $db=(new Database())->connect();
    $env=$db->query('SELECT DATABASE() AS base,@@port AS puerto')->fetch(PDO::FETCH_ASSOC);
    if(strtolower((string)($env['base']??''))!=='prueba'||(int)($env['puerto']??0)!==3307)throw new RuntimeException('BD/puerto no autorizados para esta actualización.');
}catch(Throwable $e){fwrite(STDERR,'BLOQUEADO: comprueba MySQL prueba:3307. '.$e->getMessage().PHP_EOL);exit(2);}
// Guardar fuera de htdocs (C:\xampp\gyd_resguardos_codigo en la instalación típica).
$backupRoot=dirname(dirname($root)).'/gyd_resguardos_codigo/f2_catalogo_unico_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if(!is_dir($backupRoot)&&!mkdir($backupRoot,0770,true)){fwrite(STDERR,"No se pudo crear respaldo externo.\n");exit(2);}
$done=[];
try{
    // Copiar todos los originales antes de instalar el primero.
    foreach($pending as $rel){$dest=$root.'/'.$rel;$save=$backupRoot.'/'.$rel;
        if(is_file($dest)){
            if(!is_dir(dirname($save))&&!mkdir(dirname($save),0770,true))throw new RuntimeException('No se pudo crear carpeta de respaldo.');
            if(!copy($dest,$save)||hash_file('sha256',$dest)!==hash_file('sha256',$save))throw new RuntimeException('No se pudo respaldar: '.$rel);
        }
    }
    foreach($pending as $rel){
        $src=$payload.'/'.$rel;$dest=$root.'/'.$rel;$folder=dirname($dest);
        if(!is_dir($folder)&&!mkdir($folder,0770,true))throw new RuntimeException('No se pudo crear directorio: '.$rel);
        $temp=$folder.'/.gyd_f2_'.bin2hex(random_bytes(5)).'.tmp';
        if(!copy($src,$temp)||hash_file('sha256',$temp)!==GYD_F2_NEW[$rel])throw new RuntimeException('No se pudo preparar: '.$rel);
        // Registrar ANTES de sustituir para restaurar incluso si falla la copia a mitad.
        $done[]=$rel;
        if(!@rename($temp,$dest)){
            if(!copy($temp,$dest)){@unlink($temp);throw new RuntimeException('No se pudo escribir: '.$rel);}
            @unlink($temp);
        }
        if(hash_file('sha256',$dest)!==GYD_F2_NEW[$rel])throw new RuntimeException('Fallo de verificación: '.$rel);
    }
    echo 'INSTALADO. Respaldo del código anterior: '.$backupRoot.PHP_EOL;
    echo "MySQL sin modificaciones. Prueba el Catálogo Maestro y luego ejecuta --verify.\n";
}catch(Throwable $e){
    // Retroceso de todos los archivos que este intento modificó.
    foreach(array_reverse($done) as $rel){$dest=$root.'/'.$rel;$save=$backupRoot.'/'.$rel;if(is_file($save))@copy($save,$dest);elseif(GYD_F2_OLD[$rel]===null)@unlink($dest);}
    fwrite(STDERR,'INSTALACIÓN INCOMPLETA, se intentó restaurar: '.$e->getMessage().PHP_EOL);
    fwrite(STDERR,'Revisa los respaldos en '.$backupRoot.PHP_EOL);exit(1);
}
