<?php
declare(strict_types=1);
// Instalador sin sobrescribir cambios del usuario; comprueba un patrón exacto.
$mode=$argv[1]??'--check';
if(!in_array($mode,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/instalar_guard_stock.php --check | --apply\n");exit(2);}
$root=dirname(__DIR__);$target=$root.'/public/erp_facturas_aplicar.php';
if(!is_file($target)){fwrite(STDERR,"Falta $target. Instala antes el módulo de aplicación al stock.\n");exit(2);}
$src=file_get_contents($target);
$marker='// GYD_GUARD_STOCK_OC_V1';
if(str_contains($src,$marker)){echo "El guard ya está instalado. No se duplica.\n";exit(0);}
$needle="require_once __DIR__.'/../app/helpers/erp_facturas.php';";
$start="\$qty=(int)\$r['cantidad'];\$pid=(int)\$r['producto_id'];";
$lock="SELECT id,sucursal,proveedor_id,folio_interno FROM erp_facturas WHERE id=? FOR UPDATE";
if(substr_count($src,$needle)!==1||substr_count($src,$start)!==1||substr_count($src,$lock)!==1){fwrite(STDERR,"Versión de archivo no reconocida; no se toca. Envía el archivo actual para adaptación.\n");exit(3);}
// Precarga el helper y aumenta contexto del bloqueo de factura con orden_id.
$out=str_replace($needle,$needle."\nrequire_once __DIR__.'/../app/helpers/erp_limite_recepcion_stock.php'; // GYD_GUARD_STOCK_OC_V1",$src);
$out=str_replace($lock,"SELECT id,sucursal,proveedor_id,folio_interno,orden_id FROM erp_facturas WHERE id=? FOR UPDATE",$out);
$comment='        // Bloquear la factura primero. Serializa aplicaciones concurrentes de este documento.';
if(substr_count($src,$comment)!==1){fwrite(STDERR,"La versión de bloqueo cambió, sin modificaciones.\n");exit(3);}
$lockOrder=<<<'PHPLOCK'
        // Bloquea primero la OC: serializa validación física y recepción.
        $beforeOC=$db->prepare('SELECT orden_id FROM erp_facturas WHERE id=?');
        $beforeOC->execute([$fid]);$orderForLock=(int)$beforeOC->fetchColumn();
        if($orderForLock>0){
            $ocRowLock=$db->prepare('SELECT id FROM erp_ordenes_compra WHERE id=? FOR UPDATE');
            $ocRowLock->execute([$orderForLock]);
        }
PHPLOCK;
$out=str_replace($comment,$lockOrder."\n".$comment,$out);
$out=str_replace($start,$start."\n            erp_guard_limite_recepcion_stock(\$db,(int)(\$locked['orden_id']??0),\$pid,\$qty);",$out);
if($mode==='--check'){echo "COMPATIBLE: erp_facturas_aplicar.php; --apply instalará el límite físico.\n";exit(0);}
$bk=$target.'.backup_guard_'.date('Ymd_His');
if(!copy($target,$bk)){fwrite(STDERR,"No se pudo crear respaldo.\n");exit(4);}
$temp=$target.'.guard_tmp';
if(file_put_contents($temp,$out,LOCK_EX)===false || !rename($temp,$target)){fwrite(STDERR,"No se pudo actualizar. Respaldo: $bk\n");exit(5);}
echo "Guard aplicado. Respaldo: $bk\n";
