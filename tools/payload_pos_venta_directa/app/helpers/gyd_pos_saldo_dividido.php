<?php
declare(strict_types=1);
/** G&D: la existencia general = Farmacia + ubicaciones válidas de Almacén en Hidalgo/Tuxtla.
 * Consultas FOR UPDATE al cobrar/ajustar. No crea tickets ni mueve piezas de Bodega.
 */
function gyd_pos_saldo_dividido(PDO $db,int $sucursal,int $producto,bool $bloquear=false):array {
    if(!in_array($sucursal,[1,2],true)||$producto<1)throw new DomainException('Sucursal o producto no válido para saldo dividido.');
    $st=$db->prepare('SELECT existencia FROM pos_existencias_farmacia WHERE sucursal_id=? AND producto_id=?'.($bloquear?' FOR UPDATE':''));
    $st->execute([$sucursal,$producto]);$g=$st->fetchColumn();
    $st=$db->prepare('SELECT existencia FROM erp_pos_farmacia_control WHERE sucursal_id=? AND producto_id=?'.($bloquear?' FOR UPDATE':''));
    $st->execute([$sucursal,$producto]);$f=$st->fetchColumn();
    $nombres=$sucursal===1?['CIUDAD HIDALGO','CD HIDALGO']:['TUXTLA','TUXTLA GUTIERREZ','TUXTLA GUTIÉRREZ'];
    $params=array_merge([$producto],$nombres);
    $in=implode(',',array_fill(0,count($nombres),'?'));
    $sql="SELECT pe.existencia FROM producto_existencias pe WHERE pe.producto_id=? AND UPPER(TRIM(pe.sucursal)) COLLATE utf8mb4_general_ci IN ($in) AND TRIM(COALESCE(pe.ubicacion,''))<>'' AND UPPER(TRIM(pe.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION','SIN UBICACIÓN') ORDER BY pe.id".($bloquear?' FOR UPDATE':'');
    $st=$db->prepare($sql);$st->execute($params);$a=0;
    foreach($st->fetchAll(PDO::FETCH_COLUMN) as $v){if((int)$v<0)throw new DomainException('Almacén tiene una existencia negativa: revisa el producto.');$a+=(int)$v;}
    return ['general'=>$g===false?null:(int)$g,'farmacia'=>$f===false?null:(int)$f,'almacen'=>$a,
      'conciliado'=>$g!==false&&$f!==false&&(int)$g===(int)$f+$a];
}
