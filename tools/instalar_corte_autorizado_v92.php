<?php
declare(strict_types=1);
/** V9.2: adaptación exacta al PuntoVenta.php recibido 28-oct-2026. Solo aplica sobre base 'prueba'; no sustituye archivos completos del ERP. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo consola.');}
$check=in_array('--check',$argv,true);$apply=in_array('--apply',$argv,true);
if($check===$apply||count($argv)!==2){fwrite(STDERR,"Uso: php tools/instalar_corte_autorizado_v92.php --check | --apply\n");exit(2);}
$root=dirname(__DIR__);$payload=__DIR__.'/gyd_corte_v92_payload';$changes=[];$new=[];$errors=[];$comments=[];
/** Comparación exacta primero; si varió solo el espaciado de PHP, comparar tokens. */
function v92_tokens(string $code):array {
 $prefix=str_contains(substr($code,0,20),'<?php')?'':'<?php ';
 $items=token_get_all($prefix.$code);$offset=0;$out=[];
 foreach($items as $t){
  $value=is_array($t)?$t[1]:$t;
  $kind=is_array($t)?$t[0]:null;
  $start=$offset-strlen($prefix);$end=$start+strlen($value);$offset+=strlen($value);
  if($kind===T_OPEN_TAG||$kind===T_WHITESPACE)continue;
  $out[]=['v'=>$value,'a'=>$start,'b'=>$end];
 }
 return $out;
}
function v92_find_span(string $haystack,string $needle):array {
 $big=v92_tokens($haystack);$small=v92_tokens($needle);
 if(!$small||!$big||count($small)>count($big))return [];
 $matches=[];$needCount=count($small);$first=$small[0]['v'];
 for($i=0,$limit=count($big)-$needCount;$i<=$limit;$i++){
  if($big[$i]['v']!==$first)continue;
  $match=true;
  for($j=1;$j<$needCount;$j++){
   if($big[$i+$j]['v']!==$small[$j]['v']){$match=false;break;}
  }
  if($match)$matches[]=[$big[$i]['a'],$big[$i+$needCount-1]['b']];
 }
 return $matches;
}
function v9_one(string $input,string $from,string $to,string $file,array &$errors):string {
 if(str_contains($input,$to))return $input;
 $n=substr_count($input,$from);
 if($n===1)return str_replace($from,$to,$input);
 // Usar tokens solo para PHP puro; no interpretar HTML o JavaScript como si fuera PHP.
 if($n===0&&in_array($file,['app/models/PuntoVenta.php','public/pos_api.php'],true)){
  $spans=v92_find_span($input,$from);
  if(count($spans)===1){[$a,$b]=$spans[0];return substr($input,0,$a).$to.substr($input,$b);}
  $n=count($spans);
 }
 $marker=preg_replace('/\\s+/u',' ',trim($from));$marker=substr($marker??$from,0,140);
 $errors[]="INCOMPATIBLE {$file}: marcador no único o cambiado ({$n} coincidencias). Se buscó: {$marker}";
 return $input;
}
function v9_read(string $root,string $rel,array &$errors):?string{
 $file=$root.'/'.$rel;
 if(!is_file($file)){$errors[]="Falta {$rel}; instala primero el módulo del que depende.";return null;}
 $s=file_get_contents($file);
 if($s===false){$errors[]="No se pudo leer {$rel}.";return null;}return $s;
}
foreach(['app/helpers/gyd_cortes_autorizados.php','public/assets/js/gyd_corte_autorizado.js','public/assets/css/gyd_corte_autorizado.css'] as $rel){
 $src=$payload.'/'.$rel; $dest=$root.'/'.$rel;
 if(!is_file($src)){$errors[]="Paquete incompleto: {$rel}.";continue;}
 $data=file_get_contents($src);
 if($data===false){$errors[]="Paquete no legible: {$rel}.";continue;}
 if(is_file($dest)){
  if(hash_file('sha256',$src)!==hash_file('sha256',$dest))$errors[]="INCOMPATIBLE {$rel}: existe una versión distinta, no se sobreescribirá.";
 }else{$new[$rel]=$data;}
}
// Actualizamos el método en lugar de reemplazar el modelo entero.
$rel='app/models/PuntoVenta.php';$s=v9_read($root,$rel,$errors);
if($s!==null){
 $begin=strpos($s,'public function cerrarTurno(');$end=$begin===false?false:strpos($s,'public function cobrar(',$begin);
 if($begin===false||$end===false||$end<=$begin){$errors[]='INCOMPATIBLE: no se identificó cerrarTurno().';}
 else {
  $pre=substr($s,0,$begin);$method=substr($s,$begin,$end-$begin);$post=substr($s,$end);
  $method=v9_one($method,
    'public function cerrarTurno(array $u,int $caja,string $contado,array $denominaciones):array {',
    'public function cerrarTurno(array $u,int $caja,string $contado,array $denominaciones,string $supervisorLogin="",string $supervisorPassword=""):array {', $rel,$errors);
  $anchor='$this->cajaAutorizada($u,$caja);$n=self::cents($contado);';
  $method=v9_one($method,$anchor,$anchor."\n        // No se puede cerrar sin permiso gerencial, incluso llamando directamente al API.\n        \$autorizacion=gyd_corte_autorizar(\$this->db,\$u,\$caja,\$supervisorLogin,\$supervisorPassword);",$rel,$errors);
  $anchor='$dif=$n-$esperado;';
  $method=v9_one($method,$anchor,$anchor.'$resultadoCorte=gyd_corte_resultado($dif);',$rel,$errors);
  $method=v9_one($method,"[\$u['id'],self::pesos(\$n),self::pesos(\$dif),\$turno['id']]", "[\$autorizacion['id'],self::pesos(\$n),self::pesos(\$dif),\$turno['id']]",$rel,$errors);
  $anchor='$this->audit((int)$u[\'id\'],null,\'CIERRE_CAJA\'';
  $newAnchor="\$this->q('INSERT INTO gyd_cortes_autorizaciones(turno_id,cajero_id,autorizador_id,autorizador_rol,modalidad,resultado,importe_diferencia) VALUES (?,?,?,?,?,?,?)',[(int)\$turno['id'],(int)\$u['id'],(int)\$autorizacion['id'],\$autorizacion['rol'],\$autorizacion['modo'],\$resultadoCorte['tipo'],\$resultadoCorte['importe']]);\n            \$this->audit((int)\$u['id'],null,'CORTE_CAJA_AUTORIZADO',['turno'=>(int)\$turno['id'],'autoriza'=>(int)\$autorizacion['id'],'rol'=>(string)\$autorizacion['rol'],'resultado'=>\$resultadoCorte['tipo'],'importe'=>\$resultadoCorte['importe']]);\n            ".$anchor;
  $method=v9_one($method,$anchor,$newAnchor,$rel,$errors);
  // En el PuntoVenta.php real, devolver turno_id es necesario para el ticket y el historial.
  $method=v9_one($method,"return ['esperado'=>self::pesos(\$esperado),'diferencia'=>self::pesos(\$dif),'turno_id'=>(int)\$turno['id']];", "return ['esperado'=>self::pesos(\$esperado),'diferencia'=>self::pesos(\$dif),'turno_id'=>(int)\$turno['id'],'resultado'=>\$resultadoCorte['tipo'],'importe_diferencia'=>\$resultadoCorte['importe'],'caja_cerrada'=>true];",$rel,$errors);
  if(!str_contains($method,"pos_turnos SET estado='CERRADO'"))$errors[]='INCOMPATIBLE: el cierre ya no actualiza pos_turnos.';
  $s=$pre.$method.$post;
 }
 $req="require_once __DIR__.'/../helpers/pos_inteligente.php';";
 $s=v9_one($s,$req,$req."\nrequire_once __DIR__.'/../helpers/gyd_cortes_autorizados.php';",$rel,$errors);
 $changes[$rel]=$s;
}
// Verificación final: el código propuesto no debe permitir cierre sin validación del autorizador.
if(isset($changes['app/models/PuntoVenta.php'])){
 $model=$changes['app/models/PuntoVenta.php'];
 foreach(['gyd_corte_autorizar(',"pos_turnos SET estado='CERRADO'",'gyd_cortes_autorizaciones','gyd_corte_resultado('] as $must){
  if(!str_contains($model,$must))$errors[]='INCOMPATIBLE app/models/PuntoVenta.php: falta verificación estructural obligatoria: '.$must;
 }
}
$rel='public/pos_api.php';$s=v9_read($root,$rel,$errors);
if($s!==null){
 $from="\$model->cerrarTurno(\$u,\$caja,(string)(\$post['contado']??''),(array)(\$post['denominaciones']??[]))";
 $to="\$model->cerrarTurno(\$u,\$caja,(string)(\$post['contado']??''),(array)(\$post['denominaciones']??[]),(string)(\$post['supervisor_login']??''),(string)(\$post['supervisor_password']??''))";
 $s=v9_one($s,$from,$to,$rel,$errors);
 $changes[$rel]=$s;
}
$rel='public/assets/js/pos_gyd.js';$s=v9_read($root,$rel,$errors);
if($s!==null){
 $from='window.GYD_Corte?.preparar(action);';
 $s=v9_one($s,$from,$from.'window.GYD_CorteAutorizado?.preparar(action);',$rel,$errors);
 $from="if(action==='cerrar')solicitud.denominaciones=window.GYD_Corte.datos();";
 $s=v9_one($s,$from,$from."if(action==='cerrar')Object.assign(solicitud,window.GYD_CorteAutorizado?.credenciales()||{});",$rel,$errors);
 $from="notify('Corte registrado · Efectivo esperado: $'+res.esperado+' · Diferencia: $'+res.diferencia)";
 $s=v9_one($s,$from,"notify(window.GYD_CorteAutorizado.mensaje(res))",$rel,$errors);
 $from='finally{busy=false}';
 $s=v9_one($s,$from,"finally{if(action==='cerrar')window.GYD_CorteAutorizado?.limpiar();busy=false}",$rel,$errors);
 $from="\$('cancelDialog').onclick=()=>\$('modal').hidden=true;";
 $s=v9_one($s,$from,"\$('cancelDialog').onclick=()=>{window.GYD_CorteAutorizado?.limpiar();\$('modal').hidden=true;};",$rel,$errors);
 $changes[$rel]=$s;
}
$rel='public/pos.php';$s=v9_read($root,$rel,$errors);
if($s!==null){
 $s=v9_one($s,'</head><body>', '<link rel="stylesheet" href="assets/css/gyd_corte_autorizado.css?v=20261028"></head><body>',$rel,$errors);
 $s=v9_one($s,'<script src="assets/js/pos_gyd.js?v=', '<script src="assets/js/gyd_corte_autorizado.js?v=20261028"></script><script src="assets/js/pos_gyd.js?v=',$rel,$errors);
 $changes[$rel]=$s;
}
$rel='public/pos_corte_retiros.php';$s=v9_read($root,$rel,$errors);
if($s!==null){
 $s=v9_one($s,'Diferencia (sobrante/faltante)', "<?=\$res['diferencia']===null?'Resultado del corte':(\$res['diferencia']>0?'SOBRANTE':(\$res['diferencia']<0?'FALTANTE':'CUADRE EXACTO'))?>",$rel,$errors);
 $s=v9_one($s,"\$res['diferencia']===null?'PENDIENTE':gd_signed(\$res['diferencia'])", "\$res['diferencia']===null?'PENDIENTE':gr_money(abs(\$res['diferencia']))",$rel,$errors);
 // Quitar la mención a Contabilidad en la constancia: no autoriza cortes.
 $s=str_replace('Revisó Gerencia/Contabilidad:', 'Autorizó Gerencia / Dueños / Administración:', $s);
 $changes[$rel]=$s;
}
$rel='public/erp_cortes_boveda.php';$s=v9_read($root,$rel,$errors);
if($s!==null){
 $from=<<<'OLD'
<b>Diferencia:</b> <?=($res['diferencia']===null?'Pendiente':gd_signed($res['diferencia']))?>
OLD;
 $to=<<<'NEW'
<b><?=($res['diferencia']===null?'Resultado del corte':($res['diferencia']>0?'SOBRANTE':($res['diferencia']<0?'FALTANTE':'CUADRE EXACTO')))?>:</b> <?=($res['diferencia']===null?'Pendiente':gr_money(abs($res['diferencia'])))?>
NEW;
 $s=v9_one($s,$from,$to,$rel,$errors);
 $changes[$rel]=$s;
}
$rel='public/erp_control_cajas.php';$s=v9_read($root,$rel,$errors);
if($s!==null){
 $from="function cg_money(mixed \$v): string { return '$'.number_format((float)\$v, 2, '.', ','); }";
 $to=$from."\nfunction cg_resultado_corte(mixed \$v):string { \$n=(float)\$v; return (\$n>0?'SOBRANTE':(\$n<0?'FALTANTE':'CUADRE EXACTO')).' · '.cg_money(abs(\$n)); }";
 $s=v9_one($s,$from,$to,$rel,$errors);
 $s=v9_one($s,"\$t['diferencia_efectivo']!==null?cg_money(\$t['diferencia_efectivo']):'—'", "\$t['diferencia_efectivo']!==null?cg_resultado_corte(\$t['diferencia_efectivo']):'—'",$rel,$errors);
 $s=v9_one($s,'<strong>Diferencia guardada por POS al cerrar:</strong> <?=cg_money($det[\'diferencia_efectivo\']??0)?>','<strong>Resultado del corte POS:</strong> <?=cg_resultado_corte($det[\'diferencia_efectivo\']??0)?>',$rel,$errors);
 $changes[$rel]=$s;
}
// El archivo autorizado debe conservar sus scripts del conteo y el HTML POS.
if(isset($changes['public/pos.php'])&&!str_contains($changes['public/pos.php'],'pos_corte_denominaciones.js'))$errors[]='INCOMPATIBLE: falta el JS de conteo por billetes y monedas.';
foreach($changes as $rel=>$out){$before=file_get_contents($root.'/'.$rel);if($before===$out)unset($changes[$rel]);}
if($errors){foreach($errors as $e)fwrite(STDERR,$e.PHP_EOL);fwrite(STDERR,"No se cambió ningún archivo.\n");exit(1);}
if($check){
 foreach(array_merge($new,$changes) as $rel=>$content){
  if(!str_ends_with($rel,'.php'))continue;
  $tmp=tempnam(sys_get_temp_dir(),'gyd_check_');
  if(!$tmp){fwrite(STDERR,"No se pudo crear archivo temporal para validar.\n");exit(2);}
  try{
   file_put_contents($tmp,$content);$salida=[];$code=0;
   exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$salida,$code);
   if($code!==0){fwrite(STDERR,'Sintaxis inválida en '.$rel.': '.implode(' ',$salida)."\n");exit(2);}
  }finally{@unlink($tmp);}
 }
 echo "COMPATIBLE: ".count($changes)." archivos para modificar y ".count($new)." nuevos; dos tablas de control nuevas.\n";
 echo "Comprobación de sintaxis PHP de candidatos: OK.\n";
 echo "Sin modificaciones: --check es solo lectura.\n";exit(0);
}
// Instalar en laboratorio de pruebas; la aplicación hace las operaciones reales atómicamente.
try{
 require_once $root.'/app/config/database.php';$db=(new Database())->connect();
 if((string)$db->query('SELECT DATABASE()')->fetchColumn()!=='prueba')throw new RuntimeException('La conexión NO usa la base prueba. Instalación cancelada.');
 foreach(['usuarios','pos_turnos','pos_auditoria','pos_cortes_denominaciones'] as $table){
  $q=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);
  if(!$q->fetchColumn())throw new RuntimeException('Falta tabla requerida: '.$table);
 }
 $beforeHashes=[];foreach($changes as $rel=>$content)$beforeHashes[$rel]=hash_file('sha256',$root.'/'.$rel);
 $backup=$root.'/backups_gyd_corte_v92_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
 if(!is_dir($backup)&&!mkdir($backup,0775,true))throw new RuntimeException('No se pudo crear carpeta de respaldo.');
 foreach($changes as $rel=>$content){$dest=$backup.'/'.$rel;@mkdir(dirname($dest),0775,true);if(!copy($root.'/'.$rel,$dest))throw new RuntimeException('No se pudo respaldar '.$rel);}
 $all=array_merge($new,$changes);
 // Validación de sintaxis en los archivos candidatos ANTES de aplicar cambios.
 foreach($all as $rel=>$content){
  if(!str_ends_with($rel,'.php'))continue;
  $temp=tempnam(sys_get_temp_dir(),'gydv92_');if(!$temp)throw new RuntimeException('No se pudo crear temporal.');
  try{file_put_contents($temp,$content);$stdout=[];$exitCode=0;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($temp).' 2>&1',$stdout,$exitCode);if($exitCode!==0)throw new RuntimeException('Sintaxis inválida en '.$rel.': '.implode(' ',$stdout));}finally{@unlink($temp);}
 }
 foreach($beforeHashes as $rel=>$hash)if(hash_file('sha256',$root.'/'.$rel)!==$hash)throw new RuntimeException('El archivo '.$rel.' cambió durante la instalación.');
 $statements=explode(';',(string)file_get_contents($payload.'/instalar_tablas.sql'));
 foreach($statements as $sql)if(trim($sql)!=='')$db->exec(trim($sql));
 $written=[];
 try{
  foreach($all as $rel=>$content){$dest=$root.'/'.$rel;@mkdir(dirname($dest),0775,true);if(file_put_contents($dest,$content,LOCK_EX)!==strlen($content))throw new RuntimeException('Escritura fallida: '.$rel);$written[]=$rel;}
 }catch(Throwable $e){foreach($written as $rel){if(isset($new[$rel]))@unlink($root.'/'.$rel);elseif(isset($changes[$rel]))@copy($backup.'/'.$rel,$root.'/'.$rel);}throw $e;}
 echo "V9.2 INSTALADO. Corte solo con sesión privilegiada o contraseña gerencial validada.\n";
 echo "Al confirmar corte, pos_turnos cambia a CERRADO en esa misma transacción.\n";
 echo "Respaldo de archivos: {$backup}\n";
 echo "No se modificaron ventas, retiros ni cortes previos.\n";
}catch(Throwable $e){fwrite(STDERR,'INSTALACIÓN DETENIDA: '.$e->getMessage().PHP_EOL);exit(1);}
