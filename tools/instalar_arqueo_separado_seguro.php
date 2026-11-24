<?php
declare(strict_types=1);
/** G&D: Arqueo/Retiro separados + doble validación. Instalable sólo en base prueba. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Sólo terminal PHP.');}
$base=dirname(__DIR__);$payload=__DIR__.'/arqueo_seguro_payload';
$apply=in_array('--apply',$argv,true);$check=in_array('--check',$argv,true);
if($apply===$check){fwrite(STDERR,"Uso: php tools/instalar_arqueo_separado_seguro.php --check | --apply\n");exit(2);}
$errors=[];$changes=[];$new=[];$ok=0;
$newpaths=[
 'app/helpers/gyd_arqueos.php',
 'public/pos_arqueos.php',
 'public/assets/css/gyd_arqueo_modal.css',
 'public/assets/js/gyd_arqueo_modal.js',
 'tests/probar_arqueo_separado.php',
];
foreach($newpaths as $p){
 $src=$payload.'/'.$p;$dest=$base.'/'.$p;
 if(!is_file($src)){$errors[]="Falta archivo del instalador $p";continue;}
 $content=(string)file_get_contents($src);
 if(!is_file($dest)){$new[$p]=$content;continue;}
 if(hash_file('sha256',$dest)!==hash_file('sha256',$src)){$errors[]="INCOMPATIBLE: $p existe y es diferente; no se sobreescribirá.";}else{$ok++;}
}
function exact_once(string $s,string $old,string $new,string $name,array &$errors):string {
 if(str_contains($s,$new))return $s;
 if(substr_count($s,$old)!==1){$errors[]="INCOMPATIBLE: marcador de $name ausente o repetido.";return $s;}
 return str_replace($old,$new,$s);
}
function patch_pos(string $s,array &$errors):string{
 if(!str_contains($s,'<!-- GYD_ARQUEO_RETIRO_SEPARADOS_V1 -->')){
  $regex='~<div style="padding:9px 17px;[^>]*>\s*<a href="pos_retiros\.php"[^>]*>[^<]*Arqueo / Solicitar retiro de efectivo</a>.*?</div>~s';
  if(preg_match_all($regex,$s,$matches)!==1){$errors[]='INCOMPATIBLE: no se encontró la franja Arqueo / Solicitar retiro del POS anterior.';return $s;}
  $banner=<<<'HTML'
<!-- GYD_ARQUEO_RETIRO_SEPARADOS_V1 -->
<div class="gyd-operaciones-efectivo">
  <a class="gyd-retiro-btn" id="gyd-retiro-link" href="pos_retiros.php">💵 Retiro de efectivo</a>
  <button class="gyd-arqueo-btn" id="gyd-arq-open" type="button">🧮 Arqueo protegido</button>
  <span class="gyd-ops-descr">Retiros sin corte · Arqueos solo con autorización de Gerencia o Dirección</span>
</div>
<div id="gyd-arqueo-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="gyd-arq-title">
 <div class="gyd-arq-dialog">
  <h2 id="gyd-arq-title">Autorizar arqueo de caja</h2>
  <p>Ingresa las credenciales de <strong>Gerencia, Dirección/Administración</strong> o <strong>Contabilidad</strong> cuando exista esa cuenta. La cajera no puede autorizar su propio arqueo.</p>
  <form id="gyd-arq-autorizacion" method="post" action="pos_arqueos.php" autocomplete="off">
   <input type="hidden" name="accion" value="autorizar">
   <input type="hidden" name="csrf" value="<?=arq_h(arq_csrf())?>">
   <input type="hidden" name="caja_id" value="">
   <label for="gyd-arq-user">Usuario autorizador</label>
   <input name="autorizador" id="gyd-arq-user" required maxlength="120" autocomplete="username">
   <label for="gyd-arq-password">Contraseña del autorizador</label>
   <input name="password" id="gyd-arq-password" type="password" required autocomplete="off">
   <div class="gyd-arq-actions"><button type="button" id="gyd-arq-close">Cancelar</button><button type="submit">Verificar y entrar</button></div>
  </form>
 </div>
</div>
HTML;
  $s=str_replace($matches[0][0],$banner,$s);
 }
 $req="requireLogin();\nrequire_once __DIR__.'/../app/helpers/gyd_arqueos.php';";
 $s=exact_once($s,'requireLogin();',$req,'POS helper arqueos',$errors);
 $css='<link rel="stylesheet" href="assets/css/gyd_arqueo_modal.css?v=20261027">';
 $s=exact_once($s,'</head>',$css.'</head>','CSS arqueos',$errors);
 $js='<script defer src="assets/js/gyd_arqueo_modal.js?v=20261027"></script>';
 $s=exact_once($s,'</body>',$js.'</body>','JS arqueos',$errors);
 return $s;
}
function patch_control(string $s,array &$errors):string{
 $anchor='$operacion=(string)($_POST[\'accion\']??\'\');';
 $new=$anchor."\n        // Se deshabilita esta ruta antigua: ningún arqueo puede saltar la autorización secundaria.\n        if(\$operacion==='arqueo')throw new DomainException('Para hacer arqueos utiliza Arqueo protegido desde el POS (contraseña del autorizador).');";
 $s=exact_once($s,$anchor,$new,'Bloqueo de arqueo gerencial antiguo',$errors);
 if(!str_contains($s,'<!-- GYD_FORM_ARQUEO_PROTEGIDO -->')){
  $pattern='~<section class="card"><h2>Registrar arqueo</h2>.*?</form></section>~s';
  $n=preg_match_all($pattern,$s,$matches);
  if($n!==1){$errors[]='INCOMPATIBLE: no se encontró formulario de arqueo gerencial antiguo.';return $s;}
  $replacement='<!-- GYD_FORM_ARQUEO_PROTEGIDO --><section class="card"><h2>Arqueo de caja (protegido)</h2><p class="muted">El arqueo es un conteo independiente. Requiere autorización secundaria de Gerencia, Dirección o Contabilidad. No modifica el efectivo ni los cortes del POS.</p><a class="btn secondary" href="pos.php">Abrir arqueo protegido desde Punto de Venta</a></section>';
  $s=str_replace($matches[0][0],$replacement,$s);
 }
 // En gerencia se muestra quién autorizó, sin inventar identidad en arqueos históricos.
 $qold='SELECT a.*,u.nombre responsable FROM erp_caja_arqueos a LEFT JOIN usuarios u ON u.id=a.usuario_id WHERE a.turno_id=? ORDER BY a.id DESC';
 $qnew='SELECT a.*,u.nombre responsable,uv.nombre autorizador FROM erp_caja_arqueos a LEFT JOIN usuarios u ON u.id=a.usuario_id LEFT JOIN gyd_arqueos_autorizaciones au ON au.arqueo_id=a.id LEFT JOIN usuarios uv ON uv.id=au.autorizador_id WHERE a.turno_id=? ORDER BY a.id DESC';
 $s=exact_once($s,$qold,$qnew,'Supervisor en arqueos gerenciales',$errors);
 $old='<th>Responsable</th><th>Observaciones</th></tr></thead><tbody><?php foreach($arqueos as $a):?>';
 $new='<th>Responsable</th><th>Autorizó</th><th>Observaciones</th></tr></thead><tbody><?php foreach($arqueos as $a):?>';
 $s=exact_once($s,$old,$new,'Cabecera historial arqueos',$errors);
 $old='<td><?=cg_h($a[\'responsable\'])?></td><td><?=cg_h($a[\'motivo\'])?></td></tr><?php endforeach;?><?php if(!$arqueos):?><tr><td colspan="6">Sin arqueos registrados.</td></tr>';
 $new='<td><?=cg_h($a[\'responsable\'])?></td><td><?=cg_h($a[\'autorizador\']??\'Histórico: sin dato\')?></td><td><?=cg_h($a[\'motivo\'])?></td></tr><?php endforeach;?><?php if(!$arqueos):?><tr><td colspan="7">Sin arqueos registrados.</td></tr>';
 $s=exact_once($s,$old,$new,'Lista historial arqueos',$errors);
 return $s;
}
$patches=['public/pos.php'=>'patch_pos','public/erp_control_cajas.php'=>'patch_control'];
foreach($patches as $rel=>$fn){
 $file=$base.'/'.$rel;if(!is_file($file)){$errors[]="Falta módulo requerido: $rel";continue;}
 $before=(string)file_get_contents($file);$after=$fn($before,$errors);
 if($before!==$after)$changes[$rel]=$after;else $ok++;
}
$rel='public/assets/js/pos_retiros_aviso.js';$f=$base.'/'.$rel;
if(!is_file($f))$errors[]="Falta módulo previo de retiros: $rel";
else{
 $before=(string)file_get_contents($f);
 $after=exact_once($before,"a.textContent='Abrir arqueo / retiro'","a.textContent='Abrir retiro de efectivo'",$rel,$errors);
 if($before!==$after)$changes[$rel]=$after;else $ok++;
}
$rel='public/pos_retiros.php';$f=$base.'/'.$rel;
if(!is_file($f))$errors[]="Falta el módulo de retiros: $rel";
else{
 $before=(string)file_get_contents($f);
 $after=exact_once($before,'<title>G&D | Arqueo y retiro de caja</title>','<title>G&D | Retiro de efectivo</title>',$rel,$errors);
 if($before!==$after)$changes[$rel]=$after;else $ok++;
}
if($errors){foreach($errors as $error)fwrite(STDERR,$error.PHP_EOL);fwrite(STDERR,"NO SE MODIFICÓ NINGÚN ARCHIVO.\n");exit(1);}
if($check){echo (count($new)+count($changes)?'COMPATIBLE':'YA INSTALADO').'. Nuevos: '.count($new).'; modificados: '.count($changes).'; ya instalados: '.$ok.".\n--check no cambia archivos ni datos. --apply solo permite base prueba.\n";exit(0);}
require_once $base.'/app/config/database.php';
try {
 $db=(new Database())->connect();
 $dbName=(string)$db->query('SELECT DATABASE()')->fetchColumn();
 if($dbName!=='prueba')throw new RuntimeException('Base conectada: '.$dbName.'. SOLO se aplica a prueba.');
 foreach(['erp_caja_arqueos','pos_turnos','pos_movimientos_caja','usuarios','pos_auditoria'] as $table){
  $stmt=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$stmt->execute([$table]);
  if(!$stmt->fetchColumn())throw new RuntimeException('Falta tabla necesaria: '.$table);
 }
 $backup=$base.'/backups_gyd_arqueo_seguro_'.date('Ymd_His');
 if($changes && !is_dir($backup) && !@mkdir($backup,0775,true) && !is_dir($backup))throw new RuntimeException('No se pudo crear el directorio de respaldo.');
 foreach($changes as $rel=>$content){$dest=$backup.'/'.$rel;@mkdir(dirname($dest),0775,true);if(!copy($base.'/'.$rel,$dest))throw new RuntimeException('Falló respaldo de '.$rel);}
 // CREATE TABLE IF NOT EXISTS: se aplica una sola vez, sin tocar las ventas ni cierres existentes.
 // Ejecutar sentencias por separado: PDO MySQL puede deshabilitar multi-statements.
 foreach(explode(';',(string)file_get_contents($payload.'/sql/gyd_arqueos_autorizados.sql')) as $sentencia){
  $sentencia=trim($sentencia);
  if($sentencia!=='')$db->exec($sentencia);
 }
 $wrote=[];
 try{
  foreach(array_merge($new,$changes) as $rel=>$content){$f=$base.'/'.$rel;@mkdir(dirname($f),0775,true);if(file_put_contents($f,$content,LOCK_EX)===false)throw new RuntimeException('No se pudo escribir '.$rel);$wrote[]=$rel;}
 }catch(Throwable $e){foreach($wrote as $rel){if(isset($new[$rel]))@unlink($base.'/'.$rel);elseif(is_file($backup.'/'.$rel))copy($backup.'/'.$rel,$base.'/'.$rel);}throw $e;}
 echo "INSTALACIÓN COMPLETADA. Nuevos: ".count($new)."; modificados: ".count($changes).".\nRespaldo: $backup\n";
 echo "POS: dos botones (Retiro y Arqueo protegido). El arqueo anterior quedó deshabilitado.\n";
}catch(Throwable $e){fwrite(STDERR,'INSTALACIÓN CANCELADA: '.$e->getMessage().PHP_EOL);exit(1);}
