<?php
declare(strict_types=1);
/** Instala Peticiones de Traspaso; V26.2 adaptado al auth.php real compartido. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo consola.');}
$action=$argv[1]??'--check';if(!in_array($action,['--check','--apply'],true)){fwrite(STDERR,"Uso: --check | --apply\n");exit(2);}
$root=dirname(__DIR__);$payload=__DIR__.'/_peticiones_traspasos_payload';
$files=[
 'app/models/PeticionesTraspasosGyD.php',
 'public/peticiones_traspasos.php',
 'public/peticiones_traspasos_api.php',
 'public/peticiones_traspasos_imprimir.php',
 'public/peticiones_traspasos.js',
];
$edited=['app/helpers/auth.php','app/views/layouts/header.php','app/models/TraspasosGyD.php','public/traspasos.php'];
/** Localiza un arreglo PHP delimitado por [] o array(), ignorando cadenas y comentarios. */
function pt_finArreglo(string $texto,int $pos):int {
 $inicio=$texto[$pos]??'';$cierre=$inicio==='['?']':($inicio==='('?')':'');
 if($cierre==='')throw new RuntimeException('No se reconoce la sintaxis del arreglo de permisos.');
 $nivel=1;$n=strlen($texto);$modo='';
 for($i=$pos+1;$i<$n;$i++){
  $c=$texto[$i];$sig=$texto[$i+1]??'';
  if($modo==='linea'){if($c==="\n")$modo='';continue;}
  if($modo==='bloque'){if($c==='*'&&$sig==='/'){$modo='';$i++;}continue;}
  if($modo==='comilla' || $modo==='doble'){
   if($c==='\\'){$i++;continue;}
   if($c===($modo==='comilla'?"'":'"'))$modo='';continue;
  }
  if($c==="'"){$modo='comilla';continue;}
  if($c==='"'){$modo='doble';continue;}
  if($c==='/'&&$sig==='/'){$modo='linea';$i++;continue;}
  if($c==='/'&&$sig==='*'){$modo='bloque';$i++;continue;}
  if($c==='#'){$modo='linea';continue;}
  if($c===$inicio){$nivel++;continue;}
  if($c===$cierre){$nivel--;if($nivel===0)return $i;}
 }
 throw new RuntimeException('Arreglo de permisos sin cierre; no se modificará auth.php.');
}
function pt_tieneRuta(string $lista,string $ruta):bool {
 return preg_match('~[\\\'\"]'.preg_quote($ruta,'~').'[\\\'\"]~',$lista)===1;
}
function pt_extenderListaDeRutas(string $texto,string $rol,array $rutas):string {
 $encontradas=[];
 preg_match_all('/\\$([A-Za-z_][A-Za-z_0-9]*)\\s*=\\s*(\\[|array\\s*\\()/i',$texto,$coincidencias,PREG_OFFSET_CAPTURE);
 foreach($coincidencias[0] as $i=>$m){
  $var=$coincidencias[1][$i][0];$abierto=$m[1]+strlen($m[0])-1;
  $cerrado=pt_finArreglo($texto,$abierto);
  $cuerpo=substr($texto,$abierto+1,$cerrado-$abierto-1);
  if($rol==='COMPRAS'){
   if(!pt_tieneRuta($cuerpo,'erp_ordenes_compra.php'))continue;
   // Debe ser el arreglo que usa el bloqueo de ruta y pertenecer a la rama de Compras.
   $previo=substr($texto,max(0,$m[1]-1400),min($m[1],1400));
   if(!preg_match('/\\bCOMPRAS\\b/', $previo))continue;
  }else{
   if(!pt_tieneRuta($cuerpo,'resurtidos.php')||!pt_tieneRuta($cuerpo,'tickets.php'))continue;
   $inicioFunc=strrpos(substr($texto,0,$m[1]),'function verificarAccesoJefeAlmacen');
   if($inicioFunc===false||$m[1]-$inicioFunc>3500)continue;
  }
  $despues=substr($texto,$cerrado+1,1200);
  if(!preg_match('/in_array\\s*\\([^;]*\\$'.preg_quote($var,'/').'\\s*[,)]/s',$despues))continue;
  $encontradas[]=['open'=>$abierto,'body'=>$cuerpo,'variable'=>$var];
 }
 if(count($encontradas)!==1){
  $detalle=$rol==='COMPRAS'?'lista de páginas autorizadas a Compras':'lista de páginas autorizadas al Jefe de Almacén';
  throw new RuntimeException('No se identificó de forma inequívoca la '.$detalle.' en auth.php. No se modificó. Adjunta app/helpers/auth.php para adaptar el instalador.');
 }
 $e=$encontradas[0];$faltantes=[];
 foreach($rutas as $r)if(!pt_tieneRuta($e['body'],$r))$faltantes[]="'".$r."'";
 if(!$faltantes)return $texto;
 $agregar=implode(',',$faltantes).',';
 return substr_replace($texto,$agregar,$e['open']+1,0);
}

/** Extiende un unico arreglo literal identificado por su variable; no modifica otras reglas. */
function pt_agregarRutasLiteral(string $texto,string $variable,array $rutas):string {
 $pattern='~'.preg_quote($variable,'~').'\s*=\s*(\[[^\[\]]*\])\s*;~s';
 if(preg_match_all($pattern,$texto,$m,PREG_OFFSET_CAPTURE)!==1)
  throw new RuntimeException('Lista de rutas no reconocida para '.$variable.'.');
 $literal=$m[1][0][0];$pos=$m[1][0][1];$contenido=rtrim(substr($literal,1,-1));
 $extras='';
 foreach($rutas as $ruta)if(!pt_tieneRuta($contenido,$ruta))$extras.="\n        '".$ruta."',";
 if($extras==='')return $texto;
 $contenido.=$extras;
 return substr_replace($texto,'['.$contenido."\n    ]",$pos,strlen($literal));
}

function pt_patch(string $key,string $text):string {
 if($key==='app/helpers/auth.php'){
  // V26.2: adaptar solo las DOS listas efectivas del auth.php compartido.
  // COMPRAS tiene una lista de excepciones antes de gyd_compras_ruta_permitida(),
  // no una lista de paginas interna a esta funcion. Mantener ACL central despues.
  $nuevas=['peticiones_traspasos.php','peticiones_traspasos_api.php','peticiones_traspasos_imprimir.php','traspasos.php'];
  $funcInicio=strpos($text,'function verificarAccesoJefeAlmacen(): void');
  $funcFin=strpos($text,'function paginaInicialUsuario(', (int)$funcInicio);
  if($funcInicio===false||$funcFin===false||$funcFin<=$funcInicio)
   throw new RuntimeException('No se reconoce la funcion restringida del Jefe de Almacen en auth.php.');
  $jefe=substr($text,$funcInicio,$funcFin-$funcInicio);
  if(substr_count($jefe,'$archivosPermitidos')<2||!str_contains($jefe,"'resurtidos.php'")||!str_contains($jefe,"'tickets.php'")||!str_contains($jefe,'in_array($archivo, $archivosPermitidos, true)'))
   throw new RuntimeException('La lista de rutas del Jefe de Almacen ya no tiene la estructura compartida.');
  $jefe=pt_agregarRutasLiteral($jefe,'$archivosPermitidos',$nuevas);
  $text=substr_replace($text,$jefe,$funcInicio,$funcFin-$funcInicio);

  $comprasInicio=strpos($text,"if(\$rolErp==='COMPRAS'");
  $comprasFin=strpos($text,'// GYD_ACL_CONTROL_CENTRAL_RUTAS', (int)$comprasInicio);
  if($comprasInicio===false||$comprasFin===false||$comprasFin<=$comprasInicio)
   throw new RuntimeException('No se reconoce el bloqueo de Compras en auth.php.');
  $bloque=substr($text,$comprasInicio,$comprasFin-$comprasInicio);
  if(!str_contains($bloque,'gyd_compras_ruta_permitida($rutaActual)')||!str_contains($bloque,"'erp_compras_avisos.php'")||!str_contains($bloque,"'erp_oc_inventarios.php'"))
   throw new RuntimeException('El filtro de rutas de Compras no coincide con el archivo compartido.');
  // Excepciones puntuales: los modulos comprueban rol, accion, sesion y CSRF.
  // NO eliminar ni neutralizar gyd_compras_ruta_permitida() o gyd_acl_exigir_ruta().
  if(!preg_match('/in_array\s*\(\s*basename\s*\(.*?\)\s*,\s*(\[[^\[\]]+\])\s*,\s*true\s*\)/s',$bloque,$m))
   throw new RuntimeException('No se encuentra la lista de excepciones de Compras.');
  $lista=$m[1];$contenido=trim(substr($lista,1,-1));
  foreach($nuevas as $ruta)if(!pt_tieneRuta($contenido,$ruta)){
   if($contenido!==''&&!str_ends_with(rtrim($contenido),','))$contenido.=',';
   $contenido.="'".$ruta."'";
  }
  $bloque=str_replace($lista,'['.$contenido.']',$bloque);
  $text=substr_replace($text,$bloque,$comprasInicio,$comprasFin-$comprasInicio);
  if(!str_contains($text,'gyd_acl_exigir_ruta(currentUser()'))
   throw new RuntimeException('No aparece el control ACL central en auth.php.');
  return $text;
 }
 if($key==='app/views/layouts/header.php'){
  $mark='// GYD_PETICIONES_TRASPASOS';if(str_contains($text,$mark))return $text;
  $anchor='// Existencias y conteos.';if(substr_count($text,$anchor)!==1)throw new RuntimeException('No se encontró el punto único del menú.');
  $insert=<<<'INS'
// GYD_PETICIONES_TRASPASOS
$ptPendientesCompras = 0;
if ($menuEsCompras || $menuEsAdmin) {
  try {
    require_once __DIR__.'/../../config/database.php';
    $ptDb=(new Database())->connect();
    $ptPendientesCompras=(int)$ptDb->query("SELECT COUNT(*) FROM erp_pet_cab WHERE estado='PENDIENTE_COMPRAS'")->fetchColumn();
  } catch (Throwable $ptError) { error_log('Aviso Peticiones: '.$ptError->getMessage()); }
}
$menuAgregar('📦 Operación de almacén', 'Peticiones de Traspaso'.($ptPendientesCompras>0?' 🔔 '.$ptPendientesCompras:''), 'peticiones_traspasos.php', $menuEsAdmin || $menuEsCompras || $esGerente || $esEncargado || $esJefeAlmacen);

INS;
  return str_replace($anchor,$insert.$anchor,$text);
 }
 if($key==='app/models/TraspasosGyD.php'){
  $mark='// GYD_ASIGNACION_SUCURSAL_PETICIONES';if(str_contains($text,$mark))return $text;
  $anchor="public static function sucursalUsuario(array \$u):int {return match((int)(\$u['almacen_id']??0)){1=>1,3=>2,default=>0};}";
  if(substr_count($text,$anchor)!==1)throw new RuntimeException('Modelo TraspasosGyD personalizado: no se reconoce la asignación original.');
  $new=<<<'METHOD'
// GYD_ASIGNACION_SUCURSAL_PETICIONES
public static function sucursalUsuario(array $u):int {
    $id=(int)($u['id']??0);
    if($id>0){try{
        $db=(new Database())->connect();
        $s=$db->prepare('SELECT sucursal_id FROM erp_pet_usuario_sucursal WHERE usuario_id=?');
        $s->execute([$id]);$n=(int)($s->fetchColumn()?:0);
        if($n>0)return $n;
    }catch(Throwable $e){error_log('Traspasos asignación: '.$e->getMessage());}}
    return match((int)($u['almacen_id']??0)){1=>1,3=>2,default=>0};
}
METHOD;
  $text=str_replace($anchor,$new,$text);
  $added="public static function globalLectura(array \$u):bool{return self::global(\$u)||self::rol(\$u)==='COMPRAS';}";
  $where='public function listado(array $u):array {';
  if(substr_count($text,$where)!==1)throw new RuntimeException('No se reconoció el listado de Traspasos.');
  $text=str_replace($where,$added."\n    ".$where,$text);
  $line='if(!self::global($u)';
  if(substr_count($text,$line)!==2)throw new RuntimeException('No se encontraron los dos controles de consulta en el modelo Traspasos.');
  $text=str_replace($line,'if(!self::globalLectura($u)',$text);
  $role="['GERENTE','ENCARGADO'],true)";
  if(substr_count($text,$role)!==1)throw new RuntimeException('Roles del modelo de Traspasos cambiaron; se requiere revisión manual.');
  return str_replace($role,"['GERENTE','ENCARGADO','JEFE_ALMACEN'],true)",$text);
 }
 if($key==='public/traspasos.php'){
  $mark='<!-- GYD_PETICIONES_TRASPASOS -->';if(str_contains($text,$mark))return $text;
  $role="['ADMINISTRADOR','ADMIN','DIRECCION','GERENTE','ENCARGADO'],true)";
  if(substr_count($text,$role)!==1)throw new RuntimeException('Permisos de la pantalla Traspasos difieren del esperado.');
  $text=str_replace($role,"['ADMINISTRADOR','ADMIN','DIRECCION','GERENTE','ENCARGADO','JEFE_ALMACEN','COMPRAS'],true)",$text);
  $anchor='<a class="btn alt" href="resurtidos.php">Resurtidos internos</a>';
  if(substr_count($text,$anchor)!==1)throw new RuntimeException('La pantalla de Traspasos no tiene el enlace de Resurtidos original.');
  return str_replace($anchor,'<!-- GYD_PETICIONES_TRASPASOS --><a class="btn alt" href="peticiones_traspasos.php">📨 Peticiones y Compras</a>'.$anchor,$text);
 }
 throw new RuntimeException('Ruta no autorizada para parchear.');
}
try {
 if(PHP_VERSION_ID<80100)throw new RuntimeException('PHP 8.1 o superior requerido.');
 foreach(array_merge($files,$edited) as $f){if(!is_file($root.'/'.$f)&&in_array($f,$edited,true))throw new RuntimeException('Falta archivo base: '.$f);}
 foreach($files as $f){$src=$payload.'/'.$f;$dst=$root.'/'.$f;if(!is_file($src))throw new RuntimeException('Falta archivo en ZIP: '.$f);
  if(is_file($dst)&&hash_file('sha256',$src)!==hash_file('sha256',$dst))throw new RuntimeException('Ya existe una versión distinta de '.$f.'. No se sobrescribirá.');}
 $patched=[];foreach($edited as $f){$orig=file_get_contents($root.'/'.$f);if($orig===false)throw new RuntimeException('No se pudo leer '.$f);$patched[$f]=pt_patch($f,$orig);}
 $sql=file_get_contents($payload.'/sql/peticiones_traspasos.sql');if($sql===false)throw new RuntimeException('Falta SQL de peticiones.');
 $clean=trim((string)preg_replace('/^\s*--[^\r\n]*(?:\r?\n|$)/m','',$sql));
 $statements=array_values(array_filter(array_map('trim',explode(';',$clean))));
 $allowed=['erp_pet_usuario_sucursal','erp_pet_cab','erp_pet_det','erp_pet_tr_vinculo','erp_pet_eventos'];
 if(count($statements)!==count($allowed))throw new RuntimeException('El SQL no tiene la cantidad esperada de tablas.');
 foreach($statements as $i=>$sqlStatement)if(!preg_match('/^CREATE TABLE IF NOT EXISTS '.preg_quote($allowed[$i],'/').'\s*\(/i',$sqlStatement))throw new RuntimeException('SQL no reconocido para '.$allowed[$i]);
 require_once $root.'/app/config/database.php';$db=(new Database())->connect();
 $database=(string)$db->query('SELECT DATABASE()')->fetchColumn();if($database!=='prueba')throw new RuntimeException('Protección activa: solo database=prueba. Actual: '.$database);
 $required=[
  'pos_sucursales'=>['id','nombre','activo'],
  'usuarios'=>['id','nombre','rol','almacen_id'],
  'productos'=>['id','codigo','codigo_barras','descripcion','estado'],
  'pos_ventas'=>['id','estado','pagada_en','sucursal_id'],
  'pos_venta_detalle'=>['venta_id','producto_id','cantidad','unidades_descontadas'],
  'erp_traspasos'=>['id','folio','origen_id','destino_id','origen_tipo','destino_tipo','modalidad','estado','solicitado_por','observaciones'],
  'erp_traspasos_detalle'=>['traspaso_id','producto_id','solicitado','recibido'],
  'erp_traspasos_eventos'=>['traspaso_id','usuario_id','accion','detalle'],
  'pos_existencias_farmacia'=>['sucursal_id','producto_id','existencia'],
  'erp_pos_farmacia_control'=>['sucursal_id','producto_id','existencia'],
  'producto_existencias'=>['producto_id','sucursal','ubicacion','existencia'],
 ];
 foreach($required as $table=>$cols){$s=$db->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN);$missing=array_diff($cols,$s);if($missing)throw new RuntimeException('Faltan columnas en '.$table.': '.implode(',',$missing));}
 $sucs=$db->query('SELECT id,nombre FROM pos_sucursales WHERE activo=1')->fetchAll(PDO::FETCH_KEY_PAIR);
 foreach([1=>'HIDALGO',2=>'TUXTLA',3=>'TAPACHULA',4=>'TOSCANA'] as $id=>$nom)if(!isset($sucs[$id])||stripos($sucs[$id],$nom)===false)throw new RuntimeException('Asignación de sucursales no coincide: '.$id.'.');
 $expected=[
 'erp_pet_usuario_sucursal'=>['usuario_id','sucursal_id','actualizado_por'],
 'erp_pet_cab'=>['id','folio','origen_id','destino_id','destino_tipo','estado','creado_por','validado_por','respuesta_compras'],
 'erp_pet_det'=>['peticion_id','producto_id','origen_tipo','solicitado','aprobado'],
 'erp_pet_tr_vinculo'=>['peticion_id','origen_tipo','traspaso_id'],
 'erp_pet_eventos'=>['id','peticion_id','usuario_id','accion','mensaje'],
 ];
 foreach($expected as $tbl=>$cols){$t=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$t->execute([$tbl]);$eng=$t->fetchColumn();if(!$eng)continue;if(strtoupper((string)$eng)!=='INNODB')throw new RuntimeException('La tabla '.$tbl.' no es InnoDB.');
  $actual=$db->query('SHOW COLUMNS FROM `'.$tbl.'`')->fetchAll(PDO::FETCH_COLUMN);$missing=array_diff($cols,$actual);if($missing)throw new RuntimeException('Tabla preexistente incompatible '.$tbl.': '.implode(',',$missing));}
 foreach(['erp_traspasos','erp_traspasos_detalle','pos_existencias_farmacia','pos_ventas'] as $tbl){$st=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$st->execute([$tbl]);if(strtoupper((string)$st->fetchColumn())!=='INNODB')throw new RuntimeException('Se requiere InnoDB en '.$tbl);}
 echo "COMPATIBLE · Base prueba · Traspasos detectados · permisos verificados para Compras y Almacén.\n";
 // Validar la sintaxis de todos los PHP que se modificarán, ANTES de cambios de SQL o archivos.
 foreach($patched as $ruta=>$contenido){
  $temporal=tempnam(sys_get_temp_dir(),'gyd_pet_');
  if($temporal===false)throw new RuntimeException('No se pudo crear archivo temporal para validación.');
  try{
   if(file_put_contents($temporal,$contenido)===false)throw new RuntimeException('No se pudo escribir archivo temporal.');
   $salida=[];$codigo=1;
   exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($temporal).' 2>&1',$salida,$codigo);
   if($codigo!==0)throw new RuntimeException('Sintaxis PHP incorrecta en '.$ruta.': '.implode(' ',$salida));
  }finally{@unlink($temporal);}
 }
 if($action==='--check'){echo "Solo lectura: se validó la estructura y sintaxis sin modificar archivos ni tablas.\n";exit(0);}
 $backup=$root.'/tools/backups_peticiones_traspasos_'.date('Ymd_His');if(!is_dir($backup)&&!mkdir($backup,0775,true))throw new RuntimeException('No se pudo crear carpeta de respaldos.');
 foreach($edited as $f){$dest=$backup.'/'.basename($f);if(is_file($dest))$dest=$backup.'/'.str_replace('/','__',$f);if(!copy($root.'/'.$f,$dest))throw new RuntimeException('Fallo respaldando '.$f);}
 // DDL aditivo: MySQL/MariaDB confirma cada CREATE TABLE y no puede revertir DDL automáticamente.
 foreach($statements as $stmt)$db->exec($stmt);
 foreach($expected as $tbl=>$cols){$actual=$db->query('SHOW COLUMNS FROM `'.$tbl.'`')->fetchAll(PDO::FETCH_COLUMN);if($miss=array_diff($cols,$actual))throw new RuntimeException('Migración parcial: faltan columnas en '.$tbl.': '.implode(',',$miss));}
 $created=[];
 try{
  foreach($files as $f){$dst=$root.'/'.$f;if(is_file($dst))continue;$tmp=$dst.'.temp_'.bin2hex(random_bytes(4));if(!copy($payload.'/'.$f,$tmp)||!rename($tmp,$dst))throw new RuntimeException('Error creando '.$f);$created[]=$dst;echo 'Nuevo: '.$f."\n";}
  foreach($edited as $f){$dst=$root.'/'.$f;$tmp=$dst.'.temp_'.bin2hex(random_bytes(4));if(file_put_contents($tmp,$patched[$f],LOCK_EX)===false||!rename($tmp,$dst))throw new RuntimeException('Error actualizando '.$f);echo 'Actualizado: '.$f."\n";}
 }catch(Throwable $e){foreach($created as $f)@unlink($f);foreach($edited as $f){$bak=$backup.'/'.basename($f);if(is_file($bak))@copy($bak,$root.'/'.$f);}throw $e;}
 echo "LISTO. Abre public/peticiones_traspasos.php.\nRespaldos: ".$backup."\n";
 echo "No se alteraron ventas, productos, existencias ni traspasos históricos. DDL solo creó tablas de solicitudes.\n";
}catch(Throwable $e){fwrite(STDERR,'INCOMPATIBLE: '.$e->getMessage()."\n");exit(1);}
