<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/erp_oc_importar.php';
$assert=static function(bool $ok, string $name):void{if(!$ok)throw new RuntimeException('FALLA: '.$name);echo "OK: $name\n";};
$real=oci_split_text((string)file_get_contents(__DIR__.'/../ejemplos/OC_HGO_13_ARTICULOS.txt'),'txt');
$assert(count($real)===13,'Se reconocen los 13 artículos del TXT real');
$assert(oci_es_txt_hgo($real),'Se reconoce el formato sin encabezados');
$assert($real[0][0]==='650240050398' && $real[0][1]==='96' && $real[0][2]==='109.300293','Se conserva primera línea y costo original');
$single=oci_split_text("7501298217611,1,450.21\r\n",'txt');
$assert(count($single)===1 && oci_es_txt_hgo($single),'Archivo de un solo artículo');
$header=oci_split_text("CODIGO,CANTIDAD,COSTO\n7501298217611,1,450.21",'csv');
$assert(count($header)===2 && !oci_es_txt_hgo($header),'El CSV con encabezados sigue sin confundirse');
$assert(oci_numeric('109.300293',4)===109.3003,'El costo se guarda en 4 decimales conforme al ERP');
$assert(!oci_es_txt_hgo([['A','B','C']]),'No se reconocen textos arbitrarios como formato HGO');
echo "TODAS LAS PRUEBAS HGO PASARON. No se utilizó MySQL.\n";
