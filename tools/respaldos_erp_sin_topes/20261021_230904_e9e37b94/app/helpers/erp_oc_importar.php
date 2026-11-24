<?php
declare(strict_types=1);
require_once __DIR__.'/erp_catalogo_iva.php';
/** Lectura temporal de archivos de pedido. No escribe en MySQL ni en inventarios. */
function oci_utf8(string $s):string {
 if(str_starts_with($s,"\xEF\xBB\xBF"))$s=substr($s,3);
 if(str_starts_with($s,"\xFF\xFE"))$s=iconv('UTF-16LE','UTF-8//IGNORE',substr($s,2))?:'';
 elseif(str_starts_with($s,"\xFE\xFF"))$s=iconv('UTF-16BE','UTF-8//IGNORE',substr($s,2))?:'';
 elseif(!preg_match('//u',$s))$s=iconv('Windows-1252','UTF-8//IGNORE',$s)?:'';
 return str_replace(["\0","\r"],['',"\n"],$s);
}
function oci_norm(string $s):string {
 $s=trim(mb_strtolower($s,'UTF-8'));
 $s=strtr($s,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
 return preg_replace('/[^a-z0-9]+/','',$s)?:'';
}
function oci_limit_rows(array $raw):array{
 $rows=[];
 foreach($raw as $r){
  if(count($rows)>=210)break;
  $out=[];
  foreach(array_slice((array)$r,0,22) as $x)$out[]=mb_substr(trim((string)$x),0,220,'UTF-8');
  if(count(array_filter($out,fn($x)=>$x!==''))<2)continue;
  // Separar '10.00 PZA' del PDF como dos columnas sin modificar el número.
  $normalized=[];foreach($out as $cell){
   if(preg_match('/^([0-9.,]+)\s+(PZA|PZAS|PIEZA|PIEZAS|PZ|CAJA|CAJAS|CAJ)$/iu',$cell,$m)){$normalized[]=$m[1];$normalized[]=$m[2];}
   else $normalized[]=$cell;
  }
  $rows[]=array_slice($normalized,0,22);
 }
 return $rows;
}
function oci_split_text(string $text,string $ext):array {
 $lines=preg_split('/\n+/u',oci_utf8($text))?:[];
 $lines=array_values(array_filter(array_slice($lines,0,700),fn($l)=>trim($l)!==''));
 if(!$lines)throw new RuntimeException('El archivo no contiene filas de texto legibles.');
 // CSV/TXT delimitados: autodetectar el separador de columnas.
 $delims=["\t",';','|',','];$best=null;$bestScore=0;
 foreach($delims as $d){$score=0;
  foreach(array_slice($lines,0,8) as $line){$cols=str_getcsv($line,$d,'"','\\');if(count($cols)>=3)$score+=min(count($cols),10);}
  if($score>$bestScore){$best=$d;$bestScore=$score;}
 }
 $rows=[];
 if($best!==null && $bestScore>=3){foreach($lines as $line){$row=str_getcsv($line,$best,'"','\\');if(count($row)>=2)$rows[]=$row;}}
 else {
  // PDF de diseño tabular o TXT alineado con dos o más espacios.
  foreach($lines as $line){$l=trim($line);$cols=preg_split('/\s{2,}|\t+/u',$l,-1,PREG_SPLIT_NO_EMPTY)?:[];
   if(count($cols)>=3){$rows[]=$cols;continue;}
   // En PDF algunas extracciones de tablas se condensan en una sola línea.
   if(preg_match('/^([A-Za-z0-9-]{5,30})\s+(.+?)\s+([\d,.]+)\s+(PZA|PZAS|PIEZA|PIEZAS|CAJ|CAJA|CAJAS|PZ)\s+([\d,.]+)(?:\s+[\d,.]+)?$/iu',$l,$m)){
    $rows[]=[$m[1],$m[2],$m[3],$m[4],$m[5]];
   }
  }
 }
 return oci_limit_rows($rows);
}
function oci_xlsx(string $file):array{
 if(!class_exists('ZipArchive')||!class_exists('DOMDocument'))throw new RuntimeException('Activa las extensiones PHP zip y dom en XAMPP para leer Excel .xlsx.');
 $zip=new ZipArchive();if($zip->open($file)!==true)throw new RuntimeException('El Excel está dañado o no es XLSX.');
 try{
  if($zip->numFiles>500)throw new RuntimeException('Excel con demasiados componentes.');
  foreach(['xl/worksheets/sheet1.xml','xl/sharedStrings.xml'] as $path){$i=$zip->locateName($path);if($i!==false){$st=$zip->statIndex($i);if(($st['size']??0)>6500000)throw new RuntimeException('Excel demasiado grande.');}}
  $strings=[];$ss=$zip->getFromName('xl/sharedStrings.xml');
  if($ss!==false){$doc=new DOMDocument();if(!@$doc->loadXML($ss,LIBXML_NONET))throw new RuntimeException('Tabla de textos Excel no válida.');$xp=new DOMXPath($doc);$xp->registerNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');foreach($xp->query('//s:sst/s:si') as $si){$value='';foreach($xp->query('.//s:t',$si) as $t)$value.=$t->textContent;$strings[]=$value;}}
  $sheet=$zip->getFromName('xl/worksheets/sheet1.xml');
  if($sheet===false)throw new RuntimeException('No se encontró la primera hoja en el Excel. Guarda el archivo como XLSX estándar.');
  $doc=new DOMDocument();if(!@$doc->loadXML($sheet,LIBXML_NONET))throw new RuntimeException('La hoja de cálculo tiene XML inválido.');
  $xp=new DOMXPath($doc);$xp->registerNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
  $rows=[];
  foreach($xp->query('//s:worksheet/s:sheetData/s:row') as $row){$cols=[];
   foreach($xp->query('./s:c',$row) as $c){$ref=$c->getAttribute('r');if(!preg_match('/^([A-Z]{1,3})\d+$/',$ref,$m))continue;$ix=0;foreach(str_split($m[1]) as $ch)$ix=$ix*26+ord($ch)-64;$ix--;if($ix<0||$ix>=22)continue;
    $t=$c->getAttribute('t');$v=$xp->query('./s:v',$c)->item(0)?->textContent??'';
    if($t==='s')$v=$strings[(int)$v]??'';
    elseif($t==='inlineStr'){$v='';foreach($xp->query('.//s:is//s:t',$c) as $el)$v.=$el->textContent;}
    // Fórmulas sin valor cacheado no se evalúan por seguridad.
    $cols[$ix]=$v;
   }
   if($cols){$max=min(max(array_keys($cols)),21);$arr=[];for($i=0;$i<=$max;$i++)$arr[]=$cols[$i]??'';$rows[]=$arr;}
   if(count($rows)>=210)break;
  }
  return oci_limit_rows($rows);
 }finally{$zip->close();}
}
function oci_pdf(string $file):array{
 // Configura Poppler/ pdftotext en tools/OC_IMPORTACION_LEEME.md.
 $binary=getenv('GYD_PDFTOTEXT')?:'pdftotext';
 if(!function_exists('exec'))throw new RuntimeException('El servidor no permite extracción PDF. Utiliza CSV o XLSX, o habilita pdftotext.');
 $out=tempnam(sys_get_temp_dir(),'gy_oc_txt_');if($out===false)throw new RuntimeException('No se pudo crear temporal.');
 try{
  $command=escapeshellarg($binary).' -f 1 -l 8 -layout -enc UTF-8 '.escapeshellarg($file).' '.escapeshellarg($out).' 2>&1';$log=[];$rc=0;exec($command,$log,$rc);
  if($rc!==0||!is_file($out))throw new RuntimeException('No se pudo leer el PDF. Instala Poppler/pdftotext en el servidor. Los PDF escaneados requieren OCR externo.');
  $text=file_get_contents($out);if($text===false||trim($text)==='')throw new RuntimeException('PDF sin texto seleccionable; exporta CSV/Excel o usa un PDF con texto.');
  return oci_split_text($text,'pdf');
 }finally{@unlink($out);}
}
function oci_parse_upload(array $file):array{
 if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($file['tmp_name']??'')))throw new RuntimeException('Selecciona un archivo válido y vuelve a intentar.');
 $size=(int)($file['size']??0);if($size<4||$size>6*1024*1024)throw new RuntimeException('Máximo permitido: 6 MB por archivo.');
 $name=(string)($file['name']??'');$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));if(!in_array($ext,['csv','txt','xlsx','pdf','xls'],true))throw new RuntimeException('Formato no permitido. Usa TXT, CSV, XLSX o PDF.');
 $path=(string)$file['tmp_name'];
 if($ext==='xls')throw new RuntimeException('Excel antiguo .xls: abre el archivo en Excel y guárdalo como .xlsx o CSV.');
 if($ext==='xlsx')$rows=oci_xlsx($path);elseif($ext==='pdf'){
  $head=file_get_contents($path,false,null,0,5);if($head!=='%PDF-')throw new RuntimeException('El archivo no es un PDF válido.');$rows=oci_pdf($path);
 }else{$content=file_get_contents($path);if($content===false)throw new RuntimeException('No se pudo abrir archivo.');$rows=oci_split_text($content,$ext);}
 if(count($rows)<1)throw new RuntimeException('No se detectaron filas estructuradas. Revisa el formato o exporta a CSV.');
 return ['rows'=>$rows,'filename'=>mb_substr(basename($name),0,150),'format'=>$ext,'total_rows'=>count($rows)];
}

/** G&D: pedidos Codigo,Cantidad,Costo sin fila de encabezados (OC HGO.txt).
 * Es un detector estricto: nunca confunde una cabecera común con el primer artículo.
 * No almacena documentos ni modifica el inventario.
 */
function oci_es_txt_hgo(array $rows):bool {
 if(!$rows)return false;
 foreach(array_slice($rows,0, min(8,count($rows))) as $row){
  if(count($row)!==3)return false;
  $code=trim((string)$row[0]);$qty=trim((string)$row[1]);$cost=trim((string)$row[2]);
  if(!preg_match('/^[0-9]{8,16}$/D',$code))return false;
  if(!preg_match('/^[1-9][0-9]{0,7}$/D',$qty))return false;
  if(!preg_match('/^[0-9]+(?:\.[0-9]+)?$/D',$cost))return false;
 }
 return true;
}

function oci_numeric(mixed $value,int $decimals=4):?float {
 $s=trim((string)$value);if($s==='')return null;$s=str_replace(["\xc2\xa0",' ','$', 'MXN','mxn','%'],'',$s);
 if(!preg_match('/^[0-9.,]+$/D',$s))return null;
 $lastComma=strrpos($s,',');$lastDot=strrpos($s,'.');
 if($lastComma!==false&&$lastDot!==false){$decimal=$lastComma>$lastDot?',':'.';$group=$decimal===','?'.':',';$s=str_replace($group,'',$s);$s=str_replace($decimal,'.',$s);}
 elseif($lastComma!==false){if(substr_count($s,',')>1)$s=str_replace(',','',$s);else{$suffix=substr($s,$lastComma+1);$s=(strlen($suffix)===3)?str_replace(',','',$s):str_replace(',','.',$s);}}
 elseif($lastDot!==false){if(substr_count($s,'.')>1)$s=str_replace('.','',$s);}
 if(!is_numeric($s))return null;return round((float)$s,$decimals);
}
function oci_suggest(array $headers):array{
 $map=['codigo'=>['codigo','codigobarras','ean','sku','articulo','clave','upc'], 'descripcion'=>['descripcion','nombre','producto','articulo','concepto'], 'cantidad'=>['cantidad','cant','piezas','cantidadpzas','qty','unidades','pedido'], 'costo'=>['costounitario','precio unitario','preciounitario','costo','precio','costocompra'], 'cajas'=>['cajas','caja'], 'iva'=>['iva','iva%', 'tasaiva'], 'ieps'=>['ieps','tasaieps'],'unidad'=>['unidad','um','unidadmedida','u de medida']];
 $out=[];$used=[];foreach($map as $k=>$terms){foreach($headers as $i=>$header){if(isset($used[$i]))continue;$norm=oci_norm((string)$header);if($norm==='')continue;foreach($terms as $t){if($norm===oci_norm($t)||($k!=='codigo'&&strlen($norm)>=4&&str_contains($norm,oci_norm($t)))){$out[$k]=$i;$used[$i]=true;break 2;}}}}
 return $out;
}
function oci_match_rows(PDO $db,array $rows,array $m):array {
 foreach(['cantidad','codigo','descripcion'] as $key)if(!array_key_exists($key,$m)||(!is_int($m[$key])&&$m[$key]!==null))throw new RuntimeException('Selecciona las columnas de cantidad y código o descripción.');
 if($m['cantidad']===null||($m['codigo']===null&&$m['descripcion']===null))throw new RuntimeException('La cantidad y el código o descripción son obligatorios.');
 if(count($rows)>205)throw new RuntimeException('El archivo excede el límite de 200 filas de importación.');
 $byCode=$db->prepare('SELECT p.id,p.codigo,p.codigo_barras,p.descripcion,p.proveedor_id,COALESCE(NULLIF(p.costo_ultimo,0),NULLIF(p.costo_promedio,0),p.precio_compra,0) AS costo,f.iva_compra AS iva_catalogo,f.ieps AS ieps_catalogo FROM productos p LEFT JOIN erp_producto_ficha f ON f.producto_id=p.id WHERE p.estado=1 AND (p.codigo=? OR p.codigo_barras=?) LIMIT 3');
 $byDesc=$db->prepare('SELECT p.id,p.codigo,p.codigo_barras,p.descripcion,p.proveedor_id,COALESCE(NULLIF(p.costo_ultimo,0),NULLIF(p.costo_promedio,0),p.precio_compra,0) AS costo,f.iva_compra AS iva_catalogo,f.ieps AS ieps_catalogo FROM productos p LEFT JOIN erp_producto_ficha f ON f.producto_id=p.id WHERE p.estado=1 AND p.descripcion=? LIMIT 3');
 $out=[];$already=[];$summary=['ready'=>0,'review'=>0];
 foreach($rows as $i=>$r){$r=array_slice((array)$r,0,22);$pick=static fn($key)=>isset($m[$key])&&$m[$key]!==null?trim((string)($r[$m[$key]]??'')):'';
  $code=$pick('codigo');$name=$pick('descripcion');$qty=oci_numeric($pick('cantidad'),0);$rawQty=$pick('cantidad');
  if($code===''&&$name==='')continue;
  if($qty===null||$qty<1||$qty>10000000||abs($qty-(float)oci_numeric($rawQty,4))>0.00001){$out[]=['line'=>$i+1,'code'=>$code,'name'=>$name,'status'=>'Cantidad inválida','ok'=>false];$summary['review']++;continue;}
  $unit=oci_norm($pick('unidad'));
  if(in_array($unit,['caja','cajas','caj','cj'],true)){$out[]=['line'=>$i+1,'code'=>$code,'name'=>$name,'status'=>'Cantidad en cajas: convierte a piezas antes de agregar','ok'=>false];$summary['review']++;continue;}
  $found=[];if($code!==''){$byCode->execute([$code,$code]);$found=$byCode->fetchAll(PDO::FETCH_ASSOC);}else{$byDesc->execute([$name]);$found=$byDesc->fetchAll(PDO::FETCH_ASSOC);}
  if(count($found)!==1){$out[]=['line'=>$i+1,'code'=>$code,'name'=>$name,'status'=>!$found?'No encontrado en Catálogo Maestro':'Código o descripción ambiguos; revisar','ok'=>false];$summary['review']++;continue;}
  $p=$found[0];$id=(int)$p['id'];/* IVA pendiente en la ficha: se permite continuar con 0% provisional. */if(isset($already[$id])){$out[]=['line'=>$i+1,'code'=>$code,'name'=>$name,'status'=>'Producto repetido en archivo (línea '.(int)$already[$id].')','ok'=>false];$summary['review']++;continue;}$already[$id]=$i+1;
  $rawCost=$pick('costo');$cost=$rawCost===''?(float)$p['costo']:oci_numeric($rawCost,4);
  if($cost===null||$cost<0||$cost>99999999){$out[]=['line'=>$i+1,'code'=>$code,'name'=>$name,'status'=>'Costo inválido','ok'=>false];$summary['review']++;continue;}
  $iva=gyd_catalogo_iva_compra($p['iva_catalogo'],$p['codigo']);$ieps=$p['ieps_catalogo']!==null?gyd_catalogo_ieps_si_existe($p['ieps_catalogo']):($pick('ieps')===''?0:oci_numeric($pick('ieps'),2));
  $cajas=$pick('cajas')===''?0:oci_numeric($pick('cajas'),2);
  if($iva===null||$ieps===null||$cajas===null||$iva>100||$ieps>100||$iva<0||$ieps<0||$cajas<0||$cajas>9999999){$out[]=['line'=>$i+1,'code'=>$code,'name'=>$name,'status'=>'Impuestos o cajas inválidos','ok'=>false];$summary['review']++;continue;}
  $out[]=['line'=>$i+1,'code'=>$p['codigo'],'name'=>$p['descripcion'],'quantity'=>(int)$qty,'cost'=>$cost,'cost_origin'=>$rawCost===''?'Catálogo':(preg_match('/\.[0-9]{5,}$/D',$rawCost)?'Archivo · redondeado a 4 decimales':'Archivo'),'cajas'=>$cajas,'iva'=>$iva,'ieps'=>$ieps,'product'=>['id'=>$id,'codigo'=>$p['codigo'],'descripcion'=>$p['descripcion'],'proveedor_id'=>$p['proveedor_id'],'costo'=>$cost,'costo_catalogo'=>(float)$p['costo'],'iva_pendiente'=>($p['iva_catalogo']===null||trim((string)$p['iva_catalogo'])===''),'iva_catalogo'=>$iva,'ieps_catalogo'=>$p['ieps_catalogo']],'status'=>($p['iva_catalogo']===null||trim((string)$p['iva_catalogo'])==='')?'Coincidencia exacta · IVA 0% provisional (sin configurar en catálogo)':'Coincidencia exacta · IVA tomado del catálogo','ok'=>true];$summary['ready']++;
 }
 return ['items'=>$out,'summary'=>$summary];
}
