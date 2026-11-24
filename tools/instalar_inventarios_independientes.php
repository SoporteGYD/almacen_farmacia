<?php
declare(strict_types=1);
/**
 * G&D ERP POS - Independencia fisica de inventarios por sucursal.
 * Uso: php tools/instalar_inventarios_independientes.php --check|--apply|--verify
 * Requiere acceso a la base de laboratorio prueba:3307.
 * No asigna cantidades de forma automatica ni modifica movimientos existentes.
 */
ini_set('display_errors','stderr');
error_reporting(E_ALL);
$mode = $argv[1] ?? '--help';
$root = dirname(__DIR__);
$patchPath = __DIR__ . '/reemplazos_inventarios_separados.json';
function out(string $line): void {fwrite(STDOUT,$line.PHP_EOL);}
function fail(string $message): never {fwrite(STDERR,'ERROR: '.$message.PHP_EOL);exit(1);}
function validatedDatabase(string $root): PDO {
    $path = $root.'/app/config/database.php';
    if (!is_file($path)) throw new RuntimeException('No existe la configuración de base de datos.');
    // Revisión adicional de conexión antes de cualquier cambio.
    require_once $path;
    $pdo=(new Database())->connect();
    $dbname=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $port=(int)$pdo->query('SELECT @@port')->fetchColumn();
    if($dbname!=='prueba' || $port!==3307)throw new RuntimeException('Sólo se permite base prueba:3307. Conexión actual: '.$dbname.':'.$port);
    $map=$pdo->query("SELECT id,nombre FROM pos_sucursales WHERE id IN (1,2,3,4)")->fetchAll(PDO::FETCH_KEY_PAIR);
    $expected=[1=>'HIDALGO',2=>'TUXTLA',3=>'TAPACHULA',4=>'TOSCANA'];
    foreach($expected as $id=>$fragment){if(!isset($map[$id])||stripos($map[$id],$fragment)===false)throw new RuntimeException('Mapa de sucursales inesperado, id='.$id);}
    return $pdo;
}
function buildPlan(string $root,string $patchPath):array {
    if(!is_file($patchPath)) throw new RuntimeException('Falta JSON de reemplazos.');
    $patch=json_decode((string)file_get_contents($patchPath),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($patch)) throw new RuntimeException('JSON de reemplazos inválido.');
    $plan=[];
    foreach($patch as $rel=>$changes){
        if(!preg_match('~^(app|public)/[A-Za-z0-9_./-]+\.(php|js)$~D',$rel)||str_contains($rel,'..'))throw new RuntimeException('Ruta de archivo no autorizada: '.$rel);
        $file=$root.'/'.$rel;
        if(!is_file($file))throw new RuntimeException('No existe '.$rel);
        $original=file_get_contents($file);
        if($original===false)throw new RuntimeException('No se pudo leer '.$rel);
        $updated=$original;
        foreach($changes as $change){
            $old=$change['old']??null; $new=$change['new']??null;
            if(!is_string($old)||$old===''||!is_string($new))throw new RuntimeException('Reemplazo incorrecto para '.$rel);
            $nOld=substr_count($updated,$old);
            if($nOld===1){$updated=str_replace($old,$new,$updated);continue;}
            if($nOld===0 && ($new==='' || substr_count($updated,$new)===1))continue;
            throw new RuntimeException('Archivo distinto al analizado (o reemplazo ambiguo): '.$rel.'. Se aborta para proteger tus cambios.');
        }
        $plan[$rel]=['file'=>$file,'old'=>$original,'new'=>$updated,'changed'=>$updated!==$original];
    }
    return $plan;
}
function validatePhpSyntax(string $path,string $contents):void {
    if(!str_ends_with($path,'.php'))return;
    $tmp=tempnam(sys_get_temp_dir(),'gydphplint_');
    if($tmp===false)throw new RuntimeException('No se pudo preparar prueba PHP.');
    try{
        file_put_contents($tmp,$contents,LOCK_EX);
        $cmd=escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1';
        exec($cmd,$output,$rc);
        if($rc!==0)throw new RuntimeException('Sintaxis PHP inválida al aplicar a '.$path.': '.implode(' ',$output));
    }finally{ @unlink($tmp); }
}
try{
 if(!in_array($mode,['--check','--check-files','--apply','--verify'],true)){
  out('Uso: php tools/instalar_inventarios_independientes.php --check | --apply | --verify');exit(0);
 }
 $plan=buildPlan($root,$patchPath);
 out('Archivos compatibles: '.count($plan));
 foreach($plan as $rel=>$p){out(($p['changed']?'PENDIENTE ':'ACTUALIZADO ').$rel);}
 if($mode==='--check-files') {out('COMPATIBLE (archivos). No se comprobó la base de datos.');exit(0);}
 $db=validatedDatabase($root);
 $flag=$db->query('SELECT activo FROM gyd_tt_pool_config WHERE id=1')->fetchColumn();
 if($flag===false)throw new RuntimeException('Falta configuración de pool. No se puede desactivar con seguridad.');
 out('Pool antiguo activo: '.((int)$flag===1?'SI':'NO'));
 $count=$db->query("SELECT sucursal_id,COUNT(*) registros,COALESCE(SUM(CASE WHEN existencia>0 THEN 1 ELSE 0 END),0) positivos FROM pos_existencias_farmacia WHERE sucursal_id IN (3,4) GROUP BY sucursal_id")->fetchAll(PDO::FETCH_ASSOC);
 foreach($count as $r)out('Sucursal '.$r['sucursal_id'].': '.$r['registros'].' registros; '.$r['positivos'].' con existencia > 0.');
 if($mode==='--check'){out('COMPATIBLE. Se mantendrán todas las existencias originales; no se copiarán de una sucursal a otra.');exit(0);}
 if($mode==='--verify'){
  if((int)$flag!==0)throw new RuntimeException('El pool antiguo sigue activo.');
  foreach($plan as $rel=>$p){if($p['changed'])throw new RuntimeException('El archivo aún requiere cambios: '.$rel);}
  require_once $root.'/app/helpers/pos_inteligente.php';
  if(pi_tt_stock_sid($db,3)!==3||pi_tt_stock_sid($db,4)!==4||pi_tt_clave(3,100)===pi_tt_clave(4,100)||pi_tt_activo($db))throw new RuntimeException('Los inventarios siguen compartiendo el resolvedor.');
  out('VERIFICADO: Tapachula y Toscana tienen saldos y candados independientes. Hidalgo y Tuxtla conservan sus reglas.');exit(0);
 }
 // --apply: no escribe inventarios ni crea existencias. Respalda archivos de código.
 $pending=array_filter($plan,static fn(array $p)=>$p['changed']);
 $backups=$root.'/tools/respaldos_inventarios_independientes_'.date('Ymd_His');
 if($pending && !mkdir($backups,0700,true) && !is_dir($backups))throw new RuntimeException('No se pudo crear carpeta de respaldo.');
 foreach($pending as $rel=>$p){validatePhpSyntax($rel,$p['new']);}
 foreach($pending as $rel=>$p){
   $target=$backups.'/'.$rel;
   if(!is_dir(dirname($target)))mkdir(dirname($target),0700,true);
   if(!copy($p['file'],$target))throw new RuntimeException('No se pudo respaldar '.$rel);
 }
 $written=[];
 try{
  foreach($pending as $rel=>$p){
   if(file_put_contents($p['file'],$p['new'],LOCK_EX)!==strlen($p['new']))throw new RuntimeException('Falló escritura de '.$rel);
   $written[]=$rel;
  }
  $db->beginTransaction();
  $db->exec('UPDATE gyd_tt_pool_config SET activo=0 WHERE id=1');
  $db->commit();
 }catch(Throwable $e){
  if($db->inTransaction())$db->rollBack();
  foreach($written as $rel){copy($backups.'/'.$rel,$plan[$rel]['file']);}
  throw $e;
 }
 out('APLICADO: inventarios independientes.');
 if($pending)out('Respaldo en: '.$backups);
 out('No se modificó ningún conteo ni precio. Ejecuta --verify para comprobar.');
}catch(Throwable $e){fail($e->getMessage());}
