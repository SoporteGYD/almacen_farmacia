<?php
declare(strict_types=1);
/** G&D - captura transaccional de cambios, sólo base prueba:3307. */
$root=dirname(__DIR__);
$pathDb=$root.'/app/config/database.php';
$pathK=$root.'/public/erp_kardex_general.php';
$pathPv=$root.'/app/models/PuntoVenta.php';
$pathRv=$root.'/app/models/PosReversiones.php';
$helper=$root.'/app/helpers/gyd_stock_kardex_verified.php';
$csv=$root.'/public/erp_stock_auditoria_export.php';
$payload=__DIR__.'/payload';
$backup=$root.'/tools/respaldos_auditoria_inventario';
$op=$argv[1]??'--check';
$createdTriggers=[];$updatedFiles=[];
$names=[];foreach(['pg','fc','pe'] as $prefix){foreach(['ai','au','ad'] as $suffix)$names[]='gyd_stock_'.$prefix.'_'.$suffix;}
function gd_read(string $f):string{$s=@file_get_contents($f);if($s===false)throw new RuntimeException('Falta archivo: '.$f);return $s;}
function gd_patchdb(string $s):string{
 if(str_contains($s,'GYD_STOCK_CONTEXT_2026'))return $s;
 $search='return $this->connection;';
 $idx=strrpos($s,$search);
 if($idx===false)throw new RuntimeException('La conexión Database.php es distinta; no se cambió.');
 $insert=<<<'CTX'
/* GYD_STOCK_CONTEXT_2026: sólo información de sesión para auditoría; sin claves ni contraseñas. */
            $actor = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
            $ruta = substr((string)($_SERVER['SCRIPT_NAME'] ?? 'CLI'),0,160);
            $solicitud = bin2hex(random_bytes(12));
            $stAudit = $this->connection->prepare('SET @gyd_stock_user = ?, @gyd_stock_modulo = ?, @gyd_stock_request = ?, @gyd_stock_folio = NULL');
            $stAudit->execute([$actor,$ruta,$solicitud]);

CTX;
 return substr_replace($s,$insert,$idx,0);
}
function gd_patchK(string $s):string{
 if(str_contains($s,'GYD_STOCK_KARDEX_PANEL_2026'))return $s;
 $tag='</main>';if(substr_count(strtolower($s),$tag)!==1)throw new RuntimeException('Kardex no tiene cierre main único, no es seguro cambiarlo.');
 $panel=<<<'PANEL'
<?php /* GYD_STOCK_KARDEX_PANEL_2026 */
if (isset($db,$sid,$pid,$desde,$hasta) && $db instanceof PDO && (int)$pid>0) {
    require_once __DIR__.'/../app/helpers/gyd_stock_kardex_verified.php';
    gyd_vk_panel($db,(int)$sid,(int)$pid,(string)$desde,(string)$hasta);
} ?>
PANEL;
 return str_ireplace($tag,$panel."\n".$tag,$s);
}
function gd_patchPV(string $s):string{
 if(str_contains($s,'GYD_STOCK_FOLIO_VENTA_2026'))return $s;
 $needle='$this->q(\'UPDATE pos_ventas SET folio=? WHERE id=?\',[$folio,$venta]);';
 if(substr_count($s,$needle)!==1)return $s; // No imponer un parche destructivo sobre un POS distinto.
 $new=$needle."\n            // GYD_STOCK_FOLIO_VENTA_2026: sólo contexto para triggers de existencias.\n            \$this->q('SET @gyd_stock_folio=?', [\$folio]);";
 return str_replace($needle,$new,$s);
}
function gd_patchRV(string $s):string{
 if(str_contains($s,'GYD_STOCK_FOLIO_REVERSA_2026'))return $s;
 $needle='// POS general: el cobro ya lo había descontado. Restaurar UNA VEZ.';
 if(substr_count($s,$needle)!==1)return $s;
 $extra="// GYD_STOCK_FOLIO_REVERSA_2026: enlazar la devolución con su folio sin modificar stock.\n            \$this->query('SET @gyd_stock_folio=?', [(string)\$v['folio']]);\n            ";
 return str_replace($needle,$extra.$needle,$s);
}
function gd_sid(string $r,string $type):string{
 if($type!=='pe')return "$r.sucursal_id";
 return "(CASE WHEN UPPER(TRIM($r.sucursal)) COLLATE utf8mb4_general_ci IN ('CIUDAD HIDALGO','CD HIDALGO') THEN 1 WHEN UPPER(TRIM($r.sucursal)) COLLATE utf8mb4_general_ci IN ('TUXTLA','TUXTLA GUTIERREZ','TUXTLA GUTIÉRREZ') THEN 2 ELSE NULL END)";
}
function gd_trigger_sql(string $table,string $type,string $action,string $name):string{
 $old=$action==='INSERT'?'NULL': 'OLD';$new=$action==='DELETE'?'NULL':'NEW';
 $v=static fn($r,$field)=>$r==='NULL'?'NULL':$r.'.'.$field;
 $sOld=$old==='NULL'?'NULL':gd_sid($old,$type);$sNew=$new==='NULL'?'NULL':gd_sid($new,$type);
 $pOld=$v($old,'producto_id');$pNew=$v($new,'producto_id');
 $locOld=$type==='pe'?$v($old,'ubicacion'):'NULL';$locNew=$type==='pe'?$v($new,'ubicacion'):'NULL';
 $exOld=$v($old,'existencia');$exNew=$v($new,'existencia');
 $id=$type==='fc'?"CONCAT(COALESCE(".($new==='NULL'?$sOld:$sNew).",0),':',COALESCE(".($new==='NULL'?$pOld:$pNew).",0))":"CAST(".$v($new==='NULL'?$old:$new,'id')." AS CHAR)";
 $delta=$action==='UPDATE'?"(CASE WHEN ($sOld <=> $sNew) AND ($pOld <=> $pNew) THEN NEW.existencia-OLD.existencia ELSE NULL END)":'NULL';
 $area=match($type){'pg'=>'GENERAL','fc'=>'FARMACIA',default=>'ALMACEN'};
 $where='';
 if($action==='UPDATE') {
   $where="IF NOT (OLD.existencia <=> NEW.existencia) OR NOT ($sOld <=> $sNew) OR NOT ($pOld <=> $pNew) OR NOT ($locOld <=> $locNew) THEN ";
 }
 $vals=implode(', ',["'$area'","'$action'","'$table'",$id,$sOld,$sNew,$pOld,$pNew,$locOld,$locNew,$exOld,$exNew,$delta,'@gyd_stock_user','@gyd_stock_modulo','@gyd_stock_folio','@gyd_stock_request','CONNECTION_ID()','NOW(6)']);
 $insert="INSERT INTO gyd_stock_auditoria_real (area,operacion,tabla_origen,registro_clave,sucursal_antes,sucursal_despues,producto_antes,producto_despues,ubicacion_antes,ubicacion_despues,existencia_antes,existencia_despues,diferencia,usuario_id,modulo,folio,id_solicitud,conexion_sql,creado_en) VALUES ($vals);";
 $body=$action==='UPDATE'?$where.$insert.' END IF;':$insert;
 return "CREATE TRIGGER `$name` AFTER $action ON `$table` FOR EACH ROW BEGIN $body END";
}
function gd_connect(string $pathDb):PDO{
 require_once $pathDb;
 $pdo=(new Database())->connect();
 $details=$pdo->query('SELECT DATABASE() AS db,@@port AS port,VERSION() AS version')->fetch(PDO::FETCH_ASSOC);
 if(($details['db']??'')!=='prueba'||(int)($details['port']??0)!==3307)throw new RuntimeException('Se exige exclusivamente base prueba, puerto 3307. No se hizo ningún cambio.');
 $tables=$pdo->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('pos_existencias_farmacia','erp_pos_farmacia_control','producto_existencias')")->fetchAll(PDO::FETCH_KEY_PAIR);
 foreach(['pos_existencias_farmacia','erp_pos_farmacia_control','producto_existencias'] as $t)if(strtoupper((string)($tables[$t]??''))!=='INNODB')throw new RuntimeException('Tabla de existencias sin InnoDB: '.$t.'. No se puede garantizar auditoría transaccional.');
 // Un pool compartido antiguo de Tapachula/Toscana invalida independencia de sucursales.
 $pool=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gyd_tt_pool_config'")->fetchColumn();
 if($pool){$active=$pdo->query('SELECT COALESCE(MAX(activo),0) FROM gyd_tt_pool_config')->fetchColumn();if((int)$active!==0)throw new RuntimeException('El inventario compartido Tapachula/Toscana sigue ACTIVO. Corrige primero esa configuración; no se instala auditoría sobre stock cruzado.');}
 return $pdo;
}
function gd_existing(PDO $db,array $names):array{
 $f=array_fill(0,count($names),'?');$q='SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME IN ('.implode(',',$f).')';$st=$db->prepare($q);$st->execute($names);return $st->fetchAll(PDO::FETCH_COLUMN);
}
function gd_table_exists(PDO $db):bool{return (bool)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gyd_stock_auditoria_real'")->fetchColumn();}
function gd_check_audit_schema(PDO $db):void{
 if(!gd_table_exists($db))return;
 $st=$db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gyd_stock_auditoria_real'");
 $actual=$st->fetchAll(PDO::FETCH_COLUMN);
 foreach(['area','operacion','tabla_origen','registro_clave','sucursal_antes','sucursal_despues','producto_antes','producto_despues','ubicacion_antes','ubicacion_despues','existencia_antes','existencia_despues','diferencia','usuario_id','modulo','folio','id_solicitud','conexion_sql','creado_en'] as $name)
    if(!in_array($name,$actual,true))throw new RuntimeException('Existe una tabla de auditoría incompatible (falta '.$name.'). No se sobreescribió.');
}
function gd_prepare_files(string $pathDb,string $pathK,string $pathPv,string $pathRv):array{
 $files=[$pathDb=>gd_patchdb(gd_read($pathDb)),$pathK=>gd_patchK(gd_read($pathK))];
 if(is_file($pathPv))$files[$pathPv]=gd_patchPV(gd_read($pathPv));
 if(is_file($pathRv))$files[$pathRv]=gd_patchRV(gd_read($pathRv));
 return $files;
}
try{
 if(!in_array($op,['--check','--apply','--verify','--rollback'],true))throw new RuntimeException('Opciones: --check, --apply, --verify, --rollback');
 $db=gd_connect($pathDb);
 $existing=gd_existing($db,$names);
 gd_check_audit_schema($db);
 if($op==='--check'){
    gd_prepare_files($pathDb,$pathK,$pathPv,$pathRv);
    if(count($existing)>0&&count($existing)!==9)throw new RuntimeException('Existe una instalación incompleta de triggers ('.count($existing).'/9). No se deben añadir más sin revisarla.');
    echo "COMPATIBLE: base prueba:3307, 3 tablas InnoDB, ".count($existing)."/9 triggers instalados. No se ha escrito nada.\n";exit(0);
 }
 if($op==='--verify'){
    if(count($existing)!==9||!gd_table_exists($db)||!str_contains(gd_read($pathDb),'GYD_STOCK_CONTEXT_2026')||!str_contains(gd_read($pathK),'GYD_STOCK_KARDEX_PANEL_2026')||!is_file($helper)||!is_file($csv))throw new RuntimeException('Falta una parte de la instalación. Revisa el LEEME.');
    echo "VERIFICADO: 9 triggers, conexión con contexto y Kardex. No se han modificado existencias.\n";exit(0);
 }
 if($op==='--rollback'){
    foreach($names as $name)$db->exec("DROP TRIGGER IF EXISTS `$name`");
    $manifest=is_file($backup.'/manifest.json')?json_decode((string)file_get_contents($backup.'/manifest.json'),true):[];
    $restored=0;$skipped=[];
    foreach([[$pathDb,'database.php'],[$pathK,'erp_kardex_general.php'],[$pathPv,'PuntoVenta.php'],[$pathRv,'PosReversiones.php']] as [$f,$name])if(is_file($backup.'/'.$name)){
       if(isset($manifest[$name])&&is_file($f)&&hash_file('sha256',$f)!==$manifest[$name]){$skipped[]=$name;continue;}
       if(copy($backup.'/'.$name,$f))$restored++;
    }
    echo "REVERTIDO: disparadores retirados y $restored archivos restaurados. Tabla e historial se CONSERVAN.\n";
    if($skipped)echo 'AVISO: archivos modificados posteriormente y NO sobreescritos: '.implode(', ',$skipped)."\n";
    exit(0);
 }
 // Apply: all dry-run edits planned before DDL.
 $targets=gd_prepare_files($pathDb,$pathK,$pathPv,$pathRv);
 if(count($existing)>0&&count($existing)!==9)throw new RuntimeException('Disparadores parciales; revisión manual requerida.');
 if(!is_dir($backup)&&!mkdir($backup,0770,true))throw new RuntimeException('No se pudo crear carpeta de respaldos.');
 foreach([[$pathDb,'database.php'],[$pathK,'erp_kardex_general.php'],[$pathPv,'PuntoVenta.php'],[$pathRv,'PosReversiones.php']] as [$file,$name]) if(is_file($file)&&!is_file($backup.'/'.$name)){
    if(!copy($file,$backup.'/'.$name))throw new RuntimeException('No se pudo respaldar '.$name);
 }
 if(!gd_table_exists($db)){
  $db->exec("CREATE TABLE gyd_stock_auditoria_real (
     id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
     area VARCHAR(18) NOT NULL, operacion VARCHAR(8) NOT NULL, tabla_origen VARCHAR(64) NOT NULL,
     registro_clave VARCHAR(100) NULL,
     sucursal_antes INT NULL, sucursal_despues INT NULL,
     producto_antes INT NULL, producto_despues INT NULL,
     ubicacion_antes VARCHAR(150) NULL, ubicacion_despues VARCHAR(150) NULL,
     existencia_antes INT NULL, existencia_despues INT NULL, diferencia INT NULL,
     usuario_id INT NULL, modulo VARCHAR(160) NULL, folio VARCHAR(80) NULL,
     id_solicitud CHAR(24) NULL, conexion_sql BIGINT UNSIGNED NULL,
     creado_en DATETIME(6) NOT NULL,
     INDEX idx_gyd_stock_old (sucursal_antes,producto_antes,creado_en),
     INDEX idx_gyd_stock_new (sucursal_despues,producto_despues,creado_en),
     INDEX idx_gyd_stock_req (id_solicitud), INDEX idx_gyd_stock_fecha (creado_en)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 }
 if(count($existing)===0){
    $matrix=['pg'=>'pos_existencias_farmacia','fc'=>'erp_pos_farmacia_control','pe'=>'producto_existencias'];
    foreach($matrix as $type=>$table){foreach(['INSERT'=>'ai','UPDATE'=>'au','DELETE'=>'ad'] as $action=>$code){$name="gyd_stock_{$type}_{$code}";$db->exec(gd_trigger_sql($table,$type,$action,$name));$createdTriggers[]=$name;}}
 }
 if(!is_dir(dirname($helper)))mkdir(dirname($helper),0770,true);
 if(!copy($payload.'/gyd_stock_kardex_verified.php',$helper)||!copy($payload.'/erp_stock_auditoria_export.php',$csv))throw new RuntimeException('No se pudieron copiar los archivos de consulta.');
 $manifest=[];
 foreach($targets as $file=>$content){
    if(gd_read($file)!==$content){
        if(file_put_contents($file,$content,LOCK_EX)===false)throw new RuntimeException('No fue posible actualizar '.$file);
        $updatedFiles[]=$file;
    }
    $manifest[basename($file)]=hash_file('sha256',$file);
 }
 file_put_contents($backup.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),LOCK_EX);
 echo "APLICADO: 9 disparadores con auditoría en la misma transacción; Kardex ampliado. Se conservan los datos originales.\n";
 echo "Nota: --rollback retira los disparadores pero preserva el historial auditado.\n";
 }catch(Throwable $e){
   if($op==='--apply'){
     foreach($updatedFiles as $f){$saved=$backup.'/'.basename($f);if(is_file($saved))@copy($saved,$f);}
     if(isset($db)&&$db instanceof PDO){foreach($createdTriggers as $name){try{$db->exec("DROP TRIGGER IF EXISTS `$name`");}catch(Throwable $ignored){}}}
   }
   fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1);
}
