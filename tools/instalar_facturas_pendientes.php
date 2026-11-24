<?php
declare(strict_types=1);
/** Parche sin sobrescritura completa de facturas y verificación. */
$modo=$argv[1]??'--help';
if(!in_array($modo,['--check','--apply'],true)){
    fwrite(STDERR,"Uso: php tools/instalar_facturas_pendientes.php --check | --apply\n");exit(1);
}
$raiz=dirname(__DIR__);$archivos=[];
$helper=$raiz.'/app/helpers/erp_facturas_oc_documental.php';
if(!is_file($helper)){
    fwrite(STDERR,"FALTA: $helper. Primero copia app, tools y tests del ZIP.\n");exit(2);
}
function sustituirUnico(string $data,string $antes,string $despues,string $archivo):string{
    $n=substr_count($data,$antes);
    if($n!==1)throw new RuntimeException("Ancla no única ($n) en $archivo. No se modificarán archivos.");
    return str_replace($antes,$despues,$data);
}
try{
    $path=$raiz.'/public/erp_facturas.php';
    if(!is_file($path))throw new RuntimeException("No existe: $path");
    $src=file_get_contents($path);
    if($src===false)throw new RuntimeException("No se puede leer: $path");
    if(str_contains($src,'GYD_FACTURAS_DOCUMENTAL_V1')){
        echo "YA INSTALADO: erp_facturas.php\n";
    }else{
        $s=$src;
        $s=sustituirUnico($s,"require_once __DIR__.'/../app/helpers/erp_facturas_oc_parciales.php';",
            "require_once __DIR__.'/../app/helpers/erp_facturas_oc_parciales.php';\nrequire_once __DIR__.'/../app/helpers/erp_facturas_oc_documental.php'; // GYD_FACTURAS_DOCUMENTAL_V1",'erp_facturas.php');
        $n=substr_count($s,'ef_oc_saldo_facturable($db,');
        if($n!==2)throw new RuntimeException("Se esperaban 2 consultas anteriores en erp_facturas.php; aparecen $n. Versión diferente: sin cambios.");
        $s=str_replace('ef_oc_saldo_facturable($db,','ef_oc_saldo_documental($db,',$s);
        $s=sustituirUnico($s,"? 'No hay piezas recibidas sin facturar en esta orden. Revisa el historial; si faltan piezas, registra su nueva recepción.'",
            "? 'Esta orden ya tiene todas sus piezas facturadas. No registres otra factura del mismo producto: revisa las facturas anteriores y registra la recepción de faltantes.'",'erp_facturas.php');
        $s=sustituirUnico($s,
            ".(\$saldo['tiene_recepciones']?'Sólo se permiten piezas físicamente recibidas y aún no facturadas.':'Revisa las cantidades ya facturadas.')",
            ".'El máximo corresponde a piezas aún no facturadas de la orden, con independencia de las recepciones. Confirma el documento original.'",'erp_facturas.php');
        // Puede variar el texto introductorio; no afecta la lógica documental.
        $antes='<b>Si la orden tiene recepciones parciales, sólo se cargan las cantidades recibidas que aún no estén en otra factura.</b>';
        $despues='<b>Captura lo que ampara la factura real, aunque existan faltantes físicos. Las piezas no recibidas quedarán pendientes para Verificación y Recepción; registrar no suma existencias.</b>';
        if(str_contains($s,$antes))$s=str_replace($antes,$despues,$s);
        $antes='Ajusta si la factura ampara menos piezas. Registrar la factura NO modifica inventario.';
        $despues='La cantidad propuesta es editable: captura exactamente lo facturado por el proveedor. Las piezas pendientes de recibir no entran al stock hasta su verificación. Registrar la factura NO modifica inventario.';
        if(str_contains($s,$antes))$s=str_replace($antes,$despues,$s);
        $antes="'La orden no tiene artículos recibidos pendientes de factura.'";
        $despues="'La orden ya está facturada en su totalidad; revisa el historial para los faltantes físicos.'";
        if(str_contains($s,$antes))$s=str_replace($antes,$despues,$s);
        $archivos[$path]=$s;
        echo "OK: erp_facturas.php (documental independiente de recepción)\n";
    }
    $path=$raiz.'/public/erp_facturas_verificacion.php';
    if(!is_file($path))throw new RuntimeException("No existe: $path");
    $src=file_get_contents($path);
    if($src===false)throw new RuntimeException("No se puede leer: $path");
    if(str_contains($src,'GYD_OC_PROTEGER_ACEPTACION_V1')){
        echo "YA INSTALADO: erp_facturas_verificacion.php\n";
    }else{
        $s=$src;
        $ancla="require_once __DIR__.'/../app/helpers/erp_facturas.php';";
        $s=sustituirUnico($s,$ancla,$ancla."\nrequire_once __DIR__.'/../app/helpers/erp_facturas_oc_documental.php'; // GYD_OC_PROTEGER_ACEPTACION_V1",'erp_facturas_verificacion.php');
        $ancla='$db->beginTransaction();';
        $nuevo='$db->beginTransaction();'."\n    // Serializa verificación y recepciones de esta OC sin cambiar existencias.\n    if((int)(\$factura['orden_id']??0)>0){\n        \$lkOC=\$db->prepare('SELECT id FROM erp_ordenes_compra WHERE id=? FOR UPDATE');\n        \$lkOC->execute([(int)\$factura['orden_id']]);\n    }";
        $s=sustituirUnico($s,$ancla,$nuevo,'erp_facturas_verificacion.php');
        $ancla="if(!\$out)throw new RuntimeException('Indica al menos una cantidad para verificar o reportar.');";
        $nuevo=$ancla."\n        ef_oc_proteger_aceptacion(\$db,(int)(\$factura['orden_id']??0),\$details,\$out);";
        $s=sustituirUnico($s,$ancla,$nuevo,'erp_facturas_verificacion.php');
        // Las reposiciones resueltas generan una ACEPTADA adicional y usan el mismo tope físico.
        $ancla="if(\$res==='REPOSICION_RECIBIDA'){";
        $reposicion = <<<'PHPINC'
if($res==='REPOSICION_RECIBIDA'){
            $pCheck=$db->prepare('SELECT id,producto_id,descripcion_snapshot FROM erp_facturas_detalle WHERE id=? AND factura_id=?');
            $pCheck->execute([(int)$inc['factura_detalle_id'],$fid]);
            $dCheck=$pCheck->fetch(PDO::FETCH_ASSOC);
            if(!$dCheck)throw new RuntimeException('No se encontró la partida de la reposición.');
            ef_oc_proteger_aceptacion($db,(int)($factura['orden_id']??0),[$dCheck],[
                ['id'=>(int)$inc['factura_detalle_id'],'tipo'=>'ACEPTADA','cantidad'=>(int)$inc['cantidad']]
            ]);
PHPINC;
        $s=sustituirUnico($s,$ancla,$reposicion,'erp_facturas_verificacion.php');
        $archivos[$path]=$s;
        echo "OK: erp_facturas_verificacion.php (aceptadas <= recepciones de la OC)\n";
    }
    foreach($archivos as $path=>$data){
        $tmp=tempnam(sys_get_temp_dir(),'erp_php_');
        if($tmp===false)throw new RuntimeException('No se pudo crear archivo temporal.');
        file_put_contents($tmp,$data);
        $out=[];$rc=0;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$out,$rc);
        @unlink($tmp);
        if($rc!==0)throw new RuntimeException('Sintaxis inválida en '.basename($path).': '.implode(' ', $out));
    }
    if($modo==='--check'){echo 'Comprobación terminada: '.count($archivos)." archivo(s) por modificar. No se escribió nada.\n";exit(0);}
    // Respaldar TODOS antes de aplicar alguno; rollback si hay errores.
    $backups=[];$stamp=date('Ymd_His');
    foreach($archivos as $path=>$data){
        $bak=$path.'.backup_factura_pendientes_'.$stamp;
        if(!copy($path,$bak))throw new RuntimeException('Error al respaldar '.basename($path));
        $backups[$path]=$bak;
    }
    foreach($archivos as $path=>$data){
        $n=file_put_contents($path,$data,LOCK_EX);
        if($n!==strlen($data)){
            foreach($backups as $dst=>$bak)copy($bak,$dst);
            throw new RuntimeException('Fallo de escritura; restaurados los respaldos.');
        }
        echo 'ACTUALIZADO: '.basename($path).' | Respaldo: '.$backups[$path]."\n";
    }
    echo "Listo. NO se tocaron SQL, facturas existentes, precios ni inventarios.\n";
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(3);}
