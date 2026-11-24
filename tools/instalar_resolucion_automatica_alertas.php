<?php
declare(strict_types=1);
/** G&D · Parche incremental de direccionamiento y cierre verificable de alertas. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Sólo terminal PHP.');}
$mode=$argv[1]??'--check';
if(!in_array($mode,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/instalar_resolucion_automatica_alertas.php --check | --apply\n");exit(2);}
$root=dirname(__DIR__);$src=__DIR__.'/alertas_auto_fuentes';$pending=[];$errors=[];$notes=[];
$change=function(string $rel,string $before,string $after,string $desc)use($root,&$pending,&$errors,&$notes):void {
    $file=$root.'/'.$rel;
    if(!is_file($file)){$errors[]="Falta archivo requerido: $rel";return;}
    $c=$pending[$rel]??file_get_contents($file);
    if($c===false){$errors[]="No se puede leer $rel";return;}
    if(str_contains($c,$after)){$notes[]="YA INSTALADO: $desc";return;}
    if(substr_count($c,$before)!==1){$errors[]="INCOMPATIBLE: $rel, cambió el punto esperado ($desc). No se sobrescribirá.";return;}
    $pending[$rel]=str_replace($before,$after,$c);
    $notes[]="ACTUALIZAR: $desc";
};
// La nueva lógica se incluye sin reemplazar el módulo de seguimiento existente.
$change('app/helpers/erp_compras_alertas_seguimiento.php',
 "require_once __DIR__.'/erp_alertas_compras.php';",
 "require_once __DIR__.'/erp_alertas_compras.php';\n// GYD_AUTO_ALERTAS_20261026\nrequire_once __DIR__.'/erp_compras_alertas_auto.php';",
 'reglas de verificación de incidencias');
$change('app/helpers/erp_compras_alertas_seguimiento.php',
 '         $db->exec("INSERT INTO erp_compras_alerta_config(llave,valor) VALUES (\'ultima_sync\',NOW()) ON DUPLICATE KEY UPDATE valor=VALUES(valor)");',
 '         // GYD_AUTO_ALERTAS_SINCRONIZAR: cerrar verificadas o reabrir problemas recurrentes.' . "\n" .
 '         $autoStats=gdca_auto_sincronizar($db,$events);' . "\n" .
 '         $db->exec("INSERT INTO erp_compras_alerta_config(llave,valor) VALUES (\'ultima_sync\',NOW()) ON DUPLICATE KEY UPDATE valor=VALUES(valor)");',
 'cierre por comprobación después de actualizar la detección');
// El botón Guardar en Cambio de Precios ya confirma en su transacción y nunca modifica stock.
$change('public/erp_cambio_precios.php',
 '            $res=cp_guardar($db,$usuario,$_POST);',
 '            $res=cp_guardar($db,$usuario,$_POST);' . "\n" . <<<'SRC'
            // GYD_AUTO_ALERTAS_PRECIO_GUARDADO
            // Revalidar inmediatamente después del COMMIT; no bloquear el cambio si falla la alerta.
            if(empty($res['duplicada']) && (int)($res['cambios']??0)>0){
                try {
                    require_once __DIR__.'/../app/helpers/erp_compras_alertas_seguimiento.php';
                    gdca_sync($db,true);
                } catch(Throwable $e){error_log('G&D alerta post cambio de precio: '.$e->getMessage());}
            }
SRC,
 'verificación inmediata al guardar precios');
// Revisar conserva responsable y observación; el sistema dirige al módulo correcto.
$change('public/erp_compras_alerta_resolver.php',
 '    // Reutilizar el sistema de estados y bitácora, preservando el responsable actual.',
 <<<'SRC'
    // GYD_AUTO_ALERTAS_ENVIAR_A_MODULO
    // En alertas medibles no se permite un cierre manual sin que la detección haya desaparecido.
    if($nuevo==='ATENDIDA' && gdca_auto_tipo_verificable((string)$caso['tipo'])){
        gdca_sync($db,true);
        $q=$db->prepare('SELECT estado FROM erp_compras_alerta_casos WHERE id=? LIMIT 1');$q->execute([$id]);
        if($q->fetchColumn()!=='ATENDIDA')throw new InvalidArgumentException('La condición todavía no está corregida. Usa En revisión para abrir el módulo correspondiente, o descártala con justificación.');
        header('Location: erp_compras_alerta_resolver.php?caso='.$id.'&guardado=1',true,303);exit;
    }
    // Reutilizar el sistema de estados y bitácora, preservando el responsable actual.
SRC,
 'validación real antes de marcar Atendida');
$change('public/erp_compras_alerta_resolver.php',
 "    gdca_edit_case(\$db,\$user,\$id,\$nuevo,(int)(\$caso['asignado_a']??0),\$nota);",
 "    gdca_edit_case(\$db,\$user,\$id,\$nuevo,(int)(\$caso['asignado_a']??0),\$nota);\n".<<<'SRC'
    // GYD_AUTO_ALERTAS_REVISION_RUTA
    if($nuevo==='EN_REVISION'){
        $destino=gdca_auto_ruta_caso($db,$caso);
        header('Location: '.$destino,true,303);exit;
    }
SRC,
 'En revisión abre el módulo que puede resolver la alerta');
$change('public/erp_compras_alerta_resolver.php',
 'Registrar revisión</button>',
 'Guardar y abrir módulo correspondiente</button>',
 'etiqueta de acción de revisión');
$change('public/erp_compras_alerta_resolver.php',
 'Marcar como atendida significa que ya realizaste o verificaste una acción, no cambia por sí solo los precios.',
 'Las alertas medibles se marcan Atendidas automáticamente cuando el cambio quede comprobado; las demás requieren una decisión documentada.',
 'explicación sobre cierre comprobado');
$change('public/erp_notificaciones.php',
 '</main></body></html>',
 <<<'SRC'
</main>
<!-- GYD_AUTO_ALERTAS_VISTA_ACTUALIZA -->
<script>
(()=>{
 const totalInicial=<?= (int)($counts['total']??0) ?>;
 const sinLeerInicial=<?= (int)($counts['sin_leer']??0) ?>;
 let checking=false;
 async function revisar(){
   if(checking||document.visibilityState!=='visible')return;
   checking=true;
   try{
     const r=await fetch('erp_notificaciones_api.php',{credentials:'same-origin',cache:'no-store'});
     if(r.ok){const d=await r.json();if(d.ok&&d.contadores&&(Number(d.contadores.total)!==totalInicial||Number(d.contadores.sin_leer)!==sinLeerInicial))location.reload();}
   }catch(_e){}finally{checking=false;}
 }
 window.addEventListener('focus',revisar);
 document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')revisar();});
 setInterval(revisar,30000);
})();
</script></body></html>
SRC,
 'actualizar bandeja al regresar de un cambio de precios');
// Archivos nuevos. No se toca ninguna tabla: se usan las tablas ya instaladas.
foreach([
 'app/helpers/erp_compras_alertas_auto.php'=>'app/helpers/erp_compras_alertas_auto.php',
 'tests/probar_alertas_resolucion_automatica.php'=>'tests/probar_alertas_resolucion_automatica.php'
] as $rel=>$from){
 $f=$src.'/'.$from;$c=is_file($f)?file_get_contents($f):false;
 if($c===false){$errors[]='Fuente ausente: '.$from;continue;}
 $dest=$root.'/'.$rel;
 if(is_file($dest)){
   if(file_get_contents($dest)!==$c)$errors[]='INCOMPATIBLE: '.$rel.' ya existe con contenido diferente.';
   else $notes[]='YA INSTALADO: '.$rel;
 }else{$pending[$rel]=$c;$notes[]='CREAR: '.$rel;}
}
foreach($pending as $rel=>$c){
 if(!str_ends_with($rel,'.php'))continue;
 $tmp=tempnam(sys_get_temp_dir(),'gydauto_');
 if($tmp===false){$errors[]='Sin espacio temporal para validar PHP';continue;}
 file_put_contents($tmp,$c);$out=[];$exit=1;
 exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$out,$exit);unlink($tmp);
 if($exit!==0)$errors[]='Error PHP en '.$rel.': '.implode(' ',$out);
}
foreach($notes as $note)echo $note."\n";
if($errors){foreach($errors as $err)fwrite(STDERR,$err."\n");fwrite(STDERR,"INCOMPATIBLE. No se modificaron archivos ni base de datos.\n");exit(3);}
echo 'COMPATIBLE: '.count($pending)." archivos por crear/actualizar. Sin cambios en el esquema de MariaDB.\n";
if($mode==='--check')exit(0);
if(!$pending){echo "YA INSTALADO.\n";exit(0);}
$backup=$root.'/tools/backups_gyd_alertas_auto_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if(!mkdir($backup,0700,true)){fwrite(STDERR,"No se pudo crear la carpeta de respaldo.\n");exit(4);}
foreach($pending as $rel=>$c){
 $dst=$root.'/'.$rel;if(!is_file($dst))continue;
 $save=$backup.'/'.$rel;if(!is_dir(dirname($save)))mkdir(dirname($save),0700,true);
 if(!copy($dst,$save)){fwrite(STDERR,"No se pudo respaldar $rel.\n");exit(4);}
}
$written=[];
try {
 foreach($pending as $rel=>$c){
   $dst=$root.'/'.$rel;if(!is_dir(dirname($dst)))mkdir(dirname($dst),0755,true);
   $tmp=$dst.'.auto_'.getmypid();if(file_put_contents($tmp,$c,LOCK_EX)===false)throw new RuntimeException('No se pudo escribir '.$rel);
   if(!rename($tmp,$dst)){@unlink($tmp);throw new RuntimeException('No se pudo instalar '.$rel);}
   $written[]=$rel;
 }
}catch(Throwable $e){
 foreach(array_reverse($written) as $rel){$old=$backup.'/'.$rel;$dst=$root.'/'.$rel;if(is_file($old))copy($old,$dst);else @unlink($dst);}
 fwrite(STDERR,'Se restauraron los archivos por un error: '.$e->getMessage()."\n");exit(5);
}
echo "INSTALADO: ".count($written)." archivos. Respaldos: $backup\n";
echo "Pruebas: php tests/probar_alertas_resolucion_automatica.php\n";
