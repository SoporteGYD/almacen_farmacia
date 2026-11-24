<?php
declare(strict_types=1);
require_once __DIR__.'/erp_oc_win.php';
function ocw_pdf_escape(string $s):string {
 $s=preg_replace('/\s+/u',' ',trim($s))??'';$w=@iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$s);
 return str_replace(['\\','(',')'],['\\\\','\(','\)'],$w===false?'':$w);
}
function ocw_pdf_t(float $x,float $y,string $txt,int $size=9,bool $bold=false):string {
 return sprintf("BT /%s %d Tf %.2F %.2F Td (%s) Tj ET\n",$bold?'F2':'F1',$size,$x,$y,ocw_pdf_escape($txt));
}
function ocw_pdf_l(float $x1,float $y1,float $x2,float $y2):string{return sprintf("%.2F %.2F m %.2F %.2F l S\n",$x1,$y1,$x2,$y2);}
function ocw_pdf_wrap(string $txt,int $width):array {
 $words=preg_split('/\s+/u',trim($txt))?:[];$lines=[];$buffer='';
 foreach($words as $word){$v=($buffer===''?$word:$buffer.' '.$word);if(mb_strlen($v)>$width&&$buffer!==''){$lines[]=$buffer;$buffer=$word;}else $buffer=$v;}
 if($buffer!=='')$lines[]=$buffer;return $lines?:[''];
}
function ocw_pdf_right(float $right,float $y,string $txt,int $size=9,bool $bold=false):string{
 // Ajuste aproximado de longitud para fuente Helvetica fija de referencia.
 $estWidth=0;foreach(str_split(iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$txt)?:$txt) as $c){$estWidth+=($c===' '?0.28:(str_contains('.,:iIl1',$c)?0.29:(str_contains('MW@',$c)?0.79:0.54)));}
 return ocw_pdf_t($right-$estWidth*$size,$y,$txt,$size,$bold);
}
function ocw_pdf_build(PDO $db,int $id,array $usuario,bool $mostrarCostos=true):string {
 $data=ocw_order($db,$id,$usuario);$o=$data['orden'];
 if(ocw_role($usuario)==='GERENTE'&&!gd_oc_esta_lista((string)$o['estado'],$o['autorizacion']??null)){http_response_code(403);exit('Orden no disponible.');}
 return ocw_pdf_compose($o,$data['lineas'],$mostrarCostos);
}
/** Constructor PDF independiente del controlador, permite previsualizar y probar sin base de datos. */
function ocw_pdf_compose(array $o,array $items,bool $mostrarCostos=true):string {
 $nombre=(string)$o['proveedor'];$dest=ocw_destinos()[$o['destino']]??$o['destino'];$fecha=date('d/m/Y',strtotime($o['fecha']));$entrega=date('d-m-Y',strtotime($o['fecha_entrega']??$o['fecha']));
 $emitio=trim((string)($o['emitio']??''))?:'SIN REGISTRAR';
 $autorizo=gd_oc_requiere_autorizacion()?($o['autorizacion']==='AUTORIZADA'?((string)($o['autorizo']??'DIRECCIÓN')):'PENDIENTE'):'';
 $tituloSegundaFirma=gd_oc_requiere_autorizacion()?'Autorizo':'Recepcion (Gerencia)';
 $subtotal=(float)($o['subtotal_bruto']??$o['subtotal']);$descuento=(float)($o['descuento_total']??0);$iva=(float)($o['iva_total']??0);$ieps=(float)($o['ieps_total']??0);$fpgc=(float)($o['fpgc_total']??0);$total=(float)($o['total_neto']??$o['subtotal']);
 $sumCajas=0.;$sumPiezas=0;foreach($items as $it){$sumPiezas+=(int)$it['cantidad_piezas'];$sumCajas+=(float)($it['cantidad_cajas']??0);}
 $pages=[];$body='';$y=0;$pageNo=0;
 $open=function() use (&$body,&$y,&$pageNo,$o,$nombre,$dest,$fecha,$entrega,$mostrarCostos):void {
  $pageNo++;$body="0 0 0 rg\n0.5 w\n";
  $body.=ocw_pdf_t(22,797,'DISTRIBUCION G&D',9,true).ocw_pdf_t(35,785,'S.A. DE C.V.',9,true);
  $body.=ocw_pdf_t(175,796,'ORDEN DE COMPRA',10,true);
  if(!$mostrarCostos)$body.=ocw_pdf_t(185,781,'SIN COSTOS',9,true);
  $body.=ocw_pdf_t(338,796,'Número : '.ocw_numero((int)$o['id']),9,true);
  $body.=ocw_pdf_t(477,796,'Fecha:'.$fecha,8,true);
  $body.=ocw_pdf_t(34,731,'Proveedor. '.$nombre.'  ('.(int)$o['proveedor_id'].')',9);
  $body.=ocw_pdf_t(34,715,'Dirección. '.mb_substr((string)($o['direccion_proveedor']??$o['p_direccion']??''),0,60),9);
  $body.=ocw_pdf_t(245,715,'Colonia. '.mb_substr((string)($o['colonia_proveedor']??''),0,36),9);
  $body.=ocw_pdf_t(34,699,'Ciudad. '.mb_substr((string)($o['ciudad_proveedor']??''),0,35),9);
  $body.=ocw_pdf_t(245,699,mb_substr((string)($o['cp_proveedor']??''),0,5),9);
  $body.=ocw_pdf_t(296,699,'Teléfonos. '.mb_substr((string)($o['telefono_proveedor']??$o['p_telefono']??''),0,24),9);
  $body.=ocw_pdf_t(34,683,'Almacén . '.$dest,9);
  $body.=ocw_pdf_t(34,667,'Fecha de entrega : '.$entrega,9);
  if($mostrarCostos){
   $body.=ocw_pdf_t(38,625,'ARTICULO',9,true).ocw_pdf_t(120,625,'NOMBRE',9,true).ocw_pdf_t(337,625,'CANTIDAD',9,true).ocw_pdf_t(399,625,'UNID',9,true).ocw_pdf_t(431,625,'COSTO UNITARIO',8,true).ocw_pdf_t(532,625,'IMPORTE',8,true);
  }else{
   $body.=ocw_pdf_t(38,625,'ARTICULO',9,true).ocw_pdf_t(155,625,'NOMBRE',9,true).ocw_pdf_t(464,625,'CANTIDAD',9,true).ocw_pdf_t(538,625,'UNID',9,true);
  }
  $body.=ocw_pdf_l(34,619,572,619);$y=599;
 };
 $finish=function(bool $last=false) use (&$body,&$pages,&$pageNo):void{
  $body.=ocw_pdf_t(35,27,'Documento interno · ERP G&D / Página '.$pageNo,7);$pages[]=$body;
 };
 $open();foreach($items as $it){
  $wrapped=ocw_pdf_wrap((string)$it['descripcion_snapshot'],$mostrarCostos?38:48);$rowH=max(21,count($wrapped)*12+4);
  if($y-$rowH<210){$finish();$open();}
  $body.=ocw_pdf_t(38,$y,mb_substr((string)$it['codigo_snapshot'],0,16),8);
  foreach($wrapped as $ix=>$line)$body.=ocw_pdf_t($mostrarCostos?120:155,$y-12*$ix,$line,8);
  $body.=ocw_pdf_right($mostrarCostos?391:517,$y,number_format((int)$it['cantidad_piezas'],2),8);
  $body.=ocw_pdf_t($mostrarCostos?400:540,$y,(string)($it['unidad']??'PZA'),8);
  if($mostrarCostos){
   $body.=ocw_pdf_right(492,$y,number_format((float)$it['costo_unitario'],2),8);
   $body.=ocw_pdf_right(572,$y,number_format((float)$it['importe'],2),8);
  }
  $y-=$rowH;
 }
 // Totales + firmas requieren espacio. En órdenes extensas pasar a última página.
 if($y<320){$finish();$open();$y=525;}
 $body.=ocw_pdf_l(34,$y-4,572,$y-4);
 if($mostrarCostos){
  $labels=[['SUBTOTAL',$subtotal],['DESCUENTO',$descuento],['IEPS',$ieps],['IVA',$iva],['FPGC',$fpgc],['TOTAL',$total]];
  foreach($labels as $k=>$row){$py=$y-22-$k*16;$body.=ocw_pdf_right(483,$py,$row[0],9,$row[0]==='TOTAL');$body.=ocw_pdf_right(572,$py,number_format($row[1],2),9,$row[0]==='TOTAL');}
 }
 $body.=ocw_pdf_t(118,$y-($mostrarCostos?125:28),number_format(count($items),2).' Artículos  '.number_format($sumCajas,2).' Cajas  '.number_format($sumPiezas,2).' Piezas.',8,true);
 // Las observaciones son texto libre y pueden contener precios: no se muestran en la versión sin costos.
 $ob=trim((string)($o['observaciones']??''));if($mostrarCostos&&$ob!==''){$body.=ocw_pdf_t(35,$y-150,'Observaciones: '.mb_substr($ob,0,95),7);}
 $sigY=140;$body.=ocw_pdf_l(70,$sigY+30,280,$sigY+30).ocw_pdf_l(299,$sigY+30,508,$sigY+30);
 $body.=ocw_pdf_t(155,$sigY+18,'Emitio',9,true).ocw_pdf_t(360,$sigY+18,$tituloSegundaFirma,9,true);
 $body.=ocw_pdf_t(90,$sigY,mb_substr($emitio,0,40),9,true).ocw_pdf_t(314,$sigY,mb_substr($autorizo,0,40),9,true);
 $body.=ocw_pdf_l(182,70,400,70).ocw_pdf_t(268,55,'Vo.Bo.',9,true);
 if(!gd_oc_esta_lista((string)$o['estado'],$o['autorizacion']??null)){$body.=ocw_pdf_t(180,42,'BORRADOR - NO ENVIAR',9,true);}
 $finish(true);
 $objects=[];$add=function(string $v)use(&$objects):int{$objects[]=$v;return count($objects);};$catalog=$add('');$pagesId=$add('');
 $normal=$add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');$bold=$add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');$kids=[];
 foreach($pages as $page){$stream=$add('<< /Length '.strlen($page)." >>\nstream\n".$page."\nendstream");$pageId=$add('<< /Type /Page /Parent '.$pagesId.' 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 '.$normal.' 0 R /F2 '.$bold.' 0 R >> >> /Contents '.$stream.' 0 R >>');$kids[]=$pageId.' 0 R';}
 $objects[$pagesId-1]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($kids).' >>';$objects[$catalog-1]='<< /Type /Catalog /Pages '.$pagesId.' 0 R >>';
 $pdf="%PDF-1.4\n";$positions=[0];foreach($objects as $i=>$obj){$positions[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n".$obj."\nendobj\n";}
 $xref=strlen($pdf);$pdf.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";for($i=1;$i<=count($objects);$i++)$pdf.=sprintf('%010d 00000 n ', $positions[$i])."\n";
 $pdf.="trailer\n<< /Size ".(count($objects)+1).' /Root '.$catalog." 0 R >>\nstartxref\n".$xref."\n%%EOF";return $pdf;
}
