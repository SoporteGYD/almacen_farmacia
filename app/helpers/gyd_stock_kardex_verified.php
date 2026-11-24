<?php
/** G&D: historial verificado por disparadores InnoDB; sólo consulta. */
declare(strict_types=1);
function gyd_vk_e(mixed $s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function gyd_vk_warehouse_label(?string $s):string{return (string)$s==='ALMACEN'?'Almacén (ubicación)':((string)$s==='FARMACIA'?'Farmacia física':'General POS');}
function gyd_vk_filter(PDO $db,int $sid,int $pid,string $desde,string $hasta,int $limite=80):array{
    $st=$db->prepare('SELECT a.*,u.nombre AS responsable FROM gyd_stock_auditoria_real a LEFT JOIN usuarios u ON u.id=a.usuario_id WHERE (a.sucursal_antes=? OR a.sucursal_despues=?) AND (a.producto_antes=? OR a.producto_despues=?) AND a.creado_en>=? AND a.creado_en<? ORDER BY a.creado_en DESC,a.id DESC LIMIT '.max(1,min(1500,$limite)));
    $start=$desde.' 00:00:00';$end=(new DateTimeImmutable($hasta))->modify('+1 day')->format('Y-m-d').' 00:00:00';
    $st->execute([$sid,$sid,$pid,$pid,$start,$end]);return $st->fetchAll(PDO::FETCH_ASSOC);
}
function gyd_vk_panel(PDO $db,int $sid,int $pid,string $desde,string $hasta):void{
    if($pid<1)return;
    try{
       $test=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gyd_stock_auditoria_real'");
       if(!(int)$test->fetchColumn()){
           echo '<section class="card"><h2>Auditoría de existencias registradas</h2><p class="muted">Aún no está instalada la captura transaccional de existencias.</p></section>';return;
       }
       $rows=gyd_vk_filter($db,$sid,$pid,$desde,$hasta,101);
       $trunc=count($rows)>100;
       $rows=array_slice($rows,0,100);
    }catch(Throwable $e){error_log('G&D kardex auditoria: '.$e->getMessage());echo '<section class="card"><h2>Auditoría de existencias registradas</h2><p class="muted">No fue posible cargar la auditoría. Revisa el registro de errores del servidor.</p></section>';return;}
    $query=http_build_query(['sucursal'=>$sid,'producto'=>$pid,'desde'=>$desde,'hasta'=>$hasta]);
    echo '<section class="card" style="margin-top:18px"><h2 class="sectionTitle">Movimientos comprobados en la base</h2>';
    echo '<p class="muted">Saldos capturados al escribir en General, Farmacia o Almacén, dentro de la misma transacción. No se inventan saldos antiguos. Almacén se muestra por ubicación, no como un total de todas las ubicaciones.</p>';
    echo '<p><a class="btn light" href="erp_stock_auditoria_export.php?'.gyd_vk_e($query).'&amp;formato=xlsx">Descargar Excel (.xlsx)</a> &nbsp; <a class="btn light" href="erp_stock_auditoria_export.php?'.gyd_vk_e($query).'&amp;formato=csv">CSV</a></p>';
    if(!$rows){echo '<p class="muted">Sin cambios verificados para este producto y período desde que se activó la auditoría. Los movimientos históricos anteriores continúan como reconstruidos o no verificables.</p></section>';return;}
    echo '<div class="scroll"><table style="min-width:1150px"><thead><tr><th>Fecha</th><th>Área / ubicación</th><th>Operación</th><th>Antes</th><th>Cambio</th><th>Después</th><th>Usuario</th><th>Origen / folio</th><th>Integridad</th></tr></thead><tbody>';
    foreach($rows as $r){
        $old=$r['existencia_antes'];$new=$r['existencia_despues'];$delta=$r['diferencia'];$incomplete=($r['sucursal_antes']!==null&&$r['sucursal_despues']!==null&&$r['sucursal_antes']!==$r['sucursal_despues'])||($r['producto_antes']!==null&&$r['producto_despues']!==null&&$r['producto_antes']!==$r['producto_despues']);
        $place=(string)($r['ubicacion_despues']??$r['ubicacion_antes']??'');
        $note=$incomplete?'Cambió la identidad o sucursal del registro; revisar':'Registro confirmado en la operación SQL';
        $format=static fn($v)=>$v===null?'—':number_format((int)$v,0,'.',',');
        echo '<tr><td>'.gyd_vk_e($r['creado_en']).'</td><td>'.gyd_vk_e(gyd_vk_warehouse_label($r['area'])).($place!==''?'<br><small>'.gyd_vk_e($place).'</small>':'').'</td><td>'.gyd_vk_e($r['operacion']).'</td><td class="num">'.$format($old).'</td><td class="num">'.($delta===null?'—':(($delta>0?'+':'').$format($delta))).'</td><td class="num">'.$format($new).'</td><td>'.gyd_vk_e($r['responsable']??'Sin usuario asociado').'</td><td>'.gyd_vk_e($r['modulo']??'SQL directo').'<br><small>'.gyd_vk_e($r['folio']??'').'</small></td><td>'.gyd_vk_e($note).'</td></tr>';
    }
    echo '</tbody></table></div>';
    if($trunc)echo '<p class="muted">Mostrando los 100 cambios más recientes. Descarga Excel o CSV para consultar hasta 1,500 cambios del rango.</p>';
    echo '</section>';
}
