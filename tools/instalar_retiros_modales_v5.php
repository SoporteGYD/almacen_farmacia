<?php
declare(strict_types=1);
/** G&D | Quita verificación electrónica y agrega mensajes seguros. Proyecto de pruebas. */
if (PHP_SAPI !== 'cli') { http_response_code(403);exit('Solo consola.'); }
$check=in_array('--check',$argv,true);$apply=in_array('--apply',$argv,true);
if ($check === $apply) {fwrite(STDERR,"Uso: php tools/instalar_retiros_modales_v5.php --check | --apply\n");exit(2);}
$project=dirname(__DIR__);$payload=__DIR__.'/retiro_modal_v5_payload';
$orig=json_decode((string)file_get_contents($payload.'/hash_origen.json'),true,512,JSON_THROW_ON_ERROR);
$errors=[];$changes=[];$newFiles=[];$already=0;$modals=0;
foreach ($orig as $relative=>$approvedHash) {
 $live=$project.'/'.$relative;$source=$payload.'/'.$relative;
 if (!is_file($live)||!is_file($source)){$errors[]='FALTA MÓDULO: '.$relative;continue;}
 $current=hash_file('sha256',$live);$destHash=hash_file('sha256',$source);
 if ($current===$destHash){$already++;continue;}
 if ($current!==$approvedHash){$errors[]='INCOMPATIBLE: '.$relative.' tiene modificaciones posteriores. No se sobrescribe.';continue;}
 $changes[$relative]=(string)file_get_contents($source);
}
// Vistas HTML de registro más utilizadas. Sólo añadimos el recurso compartido en un cierre inequívoco.
$pages=['app/views/layouts/footer.php','public/pos.php','public/erp_control_cajas.php','public/erp_recepciones_pendientes.php','public/erp_recepcion_registrar.php',
 'public/erp_ordenes_compra.php','public/erp_catalogo_maestro.php','public/erp_facturas.php','public/erp_facturas_verificacion.php',
 'public/erp_facturas_aplicar.php','public/erp_oc_win.php','public/erp_cambio_precios.php','public/erp_oc_importar.php',
 'public/erp_recepciones_historial.php','public/entradas.php','public/salidas.php','public/tickets.php',
 'public/resurtidos.php','public/productos.php','public/reportes.php','public/usuario.php'];
$tag='<script src="assets/js/gyd_feedback_modal.js?v=5" defer></script>';
foreach ($pages as $relative){
 $f=$project.'/'.$relative;if(!is_file($f))continue;
 $body=(string)file_get_contents($f);
 if(strpos($body,'gyd_feedback_modal.js')!==false)continue;
 if(preg_match_all('~</body\s*>~i',$body,$m,PREG_OFFSET_CAPTURE)!==1)continue;
 $position=$m[0][0][1];
 $changes[$relative]=substr($body,0,$position)."\n".$tag."\n".substr($body,$position);
 $modals++;
}
// Sustituye enlaces obsoletos en Control de Cajas sin tocar la lógica de arqueo.
$control='public/erp_control_cajas.php';
if(is_file($project.'/'.$control)){
 $code=$changes[$control]??(string)file_get_contents($project.'/'.$control);
 if(strpos($code,'erp_retiros_supervision.php')!==false || strpos($code,'erp_retiros_diarios.php')!==false){
  $code=str_replace(['erp_retiros_supervision.php','erp_retiros_diarios.php','Confirmar retiros','Verificar retiros'],
                   ['erp_cortes_boveda.php','erp_cortes_boveda.php','Consultar retiros y cortes','Consultar retiros y cortes'],$code);
  $changes[$control]=$code;
 }
}
foreach(['public/assets/js/gyd_feedback_modal.js','tests/probar_retiros_modales_v5.php'] as $relative){
 $target=$project.'/'.$relative;$source=$payload.'/'.$relative;
 if(!is_file($source)){$errors[]='Falta archivo nuevo: '.$relative;continue;}
 if(!is_file($target)){$newFiles[$relative]=(string)file_get_contents($source);continue;}
 if(hash_file('sha256',$target)!==hash_file('sha256',$source)){$errors[]='INCOMPATIBLE: '.$relative.' ya existe con otro contenido.';continue;}
 $already++;
}
foreach (['app/helpers/gyd_retiros.php','app/helpers/auth.php','public/pos_arqueos.php','public/pos_corte_retiros.php'] as $r){
 if(!is_file($project.'/'.$r))$errors[]='Módulo requerido ausente: '.$r;
}
if($errors){foreach($errors as $e)fwrite(STDERR,$e."\n");fwrite(STDERR,"NO SE MODIFICÓ NINGÚN ARCHIVO.\n");exit(1);}
if($check){echo (count($changes)||count($newFiles)?'COMPATIBLE':'YA INSTALADO').". Cambios: ".count($changes).". Nuevos: ".count($newFiles).". Pantallas integradas al modal: $modals.\n";
 echo "No altera movimientos, estados históricos ni existencias.\n";exit(0);}
if(!$changes&&!$newFiles){echo "YA INSTALADO.\n";exit(0);}
try{
 require_once $project.'/app/config/database.php';
 $db=(new Database())->connect();$base=(string)$db->query('SELECT DATABASE()')->fetchColumn();
 if($base!=='prueba')throw new RuntimeException('Sólo instalable en la base prueba. Conectada: '.$base);
 $backup=$project.'/backups_gyd_retiros_modal_v5_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
 if(!mkdir($backup,0775,true)&&!is_dir($backup))throw new RuntimeException('No se pudo crear el respaldo.');
 foreach ($changes as $r=>$content){$out=$backup.'/'.$r;@mkdir(dirname($out),0775,true);if(!copy($project.'/'.$r,$out))throw new RuntimeException('No se pudo respaldar '.$r);}
 $written=[];
 try{
  foreach (array_merge($newFiles,$changes) as $r=>$content){$out=$project.'/'.$r;@mkdir(dirname($out),0775,true);
   if(file_put_contents($out,$content,LOCK_EX)===false)throw new RuntimeException('Error guardando '.$r);
   $written[]=$r;
  }
 } catch(Throwable $e){foreach(array_reverse($written) as $r){if(isset($newFiles[$r]))@unlink($project.'/'.$r);else @copy($backup.'/'.$r,$project.'/'.$r);}throw $e;}
 echo "INSTALADO CORRECTAMENTE. Cambios: ".count($changes)."; nuevos: ".count($newFiles).".\n";
 echo "Respaldo: $backup\nRetiro sin verificación, modales y corte sin estados de bóveda. No se alteró la BD.\n";
}catch(Throwable $e){fwrite(STDERR,"INSTALACIÓN DETENIDA: ".$e->getMessage()."\n");exit(1);}
