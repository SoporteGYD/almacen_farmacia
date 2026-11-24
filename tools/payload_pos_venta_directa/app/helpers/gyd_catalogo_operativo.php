<?php
declare(strict_types=1);
/** G&D F2: Configuración del POS y conteo físico exclusivamente desde Catálogo Maestro.
 * No cambia existencias POS ni almacén. Disponible sólo en prueba:3307.
 */
require_once __DIR__.'/pos_inteligente.php';
require_once __DIR__.'/gyd_pos_saldo_dividido.php';

function gyd_co_guardia(PDO $db): void {
    $st=$db->query('SELECT DATABASE() AS base, @@port AS puerto');
    $entorno=$st->fetch(PDO::FETCH_ASSOC) ?: [];
    if (strtolower((string)($entorno['base']??''))!=='prueba' || (int)($entorno['puerto']??0)!==3307) {
        throw new DomainException('Esta operación está autorizada únicamente para la BD prueba en el puerto 3307.');
    }
}
function gyd_co_decimal($valor,bool $aceptarVacio=false): string {
    $s=trim((string)$valor);
    if ($aceptarVacio && $s==='')return '';
    if (!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D',$s) || (float)$s<=0 || (float)$s>99999999.99) {
        throw new DomainException('El precio público debe ser positivo, sin separadores y con máximo dos decimales.');
    }
    return number_format((float)$s,2,'.','');
}
function gyd_co_guardar_precio(PDO $db,array $u,array $post): string {
    gyd_co_guardia($db);
    $s=filter_var($post['sucursal']??null,FILTER_VALIDATE_INT);
    $p=filter_var($post['producto_id']??null,FILTER_VALIDATE_INT);
    $actor=(int)($u['id']??0);
    $modo=strtoupper(trim((string)($post['modalidad']??'')));
    $factor=filter_var($post['piezas_por_caja']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
    $motivo=trim((string)($post['motivo']??''));
    $nuevo=gyd_co_decimal($post['precio_publico']??'');
    $original=gyd_co_decimal($post['precio_original']??'',true);
    $modoOriginal=strtoupper(trim((string)($post['modalidad_original']??'')));
    $factorOriginal=trim((string)($post['piezas_original']??''));
    if (!in_array($s,[1,2,3,4],true) || !$p || $p<1 || $actor<1 ||
        !in_array($modo,['PIEZA','CAJA','AMBAS'],true) || $factor===false ||
        !preg_match('/^(?:0|[1-9][0-9]{0,5})$/D',$factorOriginal) ||
        !in_array($modoOriginal,['','PIEZA','CAJA','AMBAS'],true) ||
        mb_strlen($motivo)<6 || mb_strlen($motivo)>250) {
        throw new DomainException('Revisa sucursal, producto, presentación, piezas por caja y motivo (6 a 250 caracteres).');
    }
    $db->beginTransaction();
    try {
        $chk=$db->prepare('SELECT id FROM productos WHERE id=? AND estado=1 FOR UPDATE');
        $chk->execute([$p]);if (!$chk->fetchColumn())throw new DomainException('Producto inactivo o inexistente.');
        $st=$db->prepare('SELECT precio_publico FROM pos_precios WHERE sucursal_id=? AND producto_id=? FOR UPDATE');
        $st->execute([$s,$p]);$raw=$st->fetchColumn();$previo=$raw===false||$raw===null?'':number_format((float)$raw,2,'.','');
        $st=$db->prepare('SELECT modalidad_presentacion,piezas_por_caja FROM pos_presentaciones WHERE sucursal_id=? AND producto_id=? FOR UPDATE');
        $st->execute([$s,$p]);$pres=$st->fetch(PDO::FETCH_ASSOC)?:[];
        $modoPrev=strtoupper((string)($pres['modalidad_presentacion']??''));$factorPrev=(int)($pres['piezas_por_caja']??0);
        if ($previo!==$original || $modoPrev!==$modoOriginal || $factorPrev!==(int)$factorOriginal) {
            throw new DomainException('El precio o presentación cambió desde que abriste la ficha. Actualiza el catálogo antes de guardar.');
        }
        $cambioPrecio=$previo!==$nuevo;$cambioPres=$modoPrev!==$modo || $factorPrev!==(int)$factor;
        if ($cambioPrecio) {
            $db->prepare('INSERT INTO pos_precios(sucursal_id,producto_id,precio_publico,actualizado_por) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE precio_publico=VALUES(precio_publico),actualizado_por=VALUES(actualizado_por),vigente_desde=NOW()')->execute([$s,$p,$nuevo,$actor]);
            $db->prepare('INSERT INTO pos_precios_historial(sucursal_id,producto_id,precio_anterior,precio_nuevo,usuario_id,motivo) VALUES(?,?,?,?,?,?)')->execute([$s,$p,$previo===''?null:$previo,$nuevo,$actor,$motivo]);
        }
        if ($cambioPres) {
            $db->prepare('INSERT INTO pos_presentaciones(sucursal_id,producto_id,modalidad_presentacion,piezas_por_caja,precio_caja,actualizado_por) VALUES(?,?,?,?,NULL,?) ON DUPLICATE KEY UPDATE modalidad_presentacion=VALUES(modalidad_presentacion),piezas_por_caja=VALUES(piezas_por_caja),precio_caja=NULL,actualizado_por=VALUES(actualizado_por)')->execute([$s,$p,$modo,$factor,$actor]);
        }
        if ($cambioPrecio || $cambioPres) {
            $payload=json_encode(['origen'=>'CATALOGO_MAESTRO','sucursal'=>$s,'producto'=>$p,'precio_anterior'=>$previo,'precio_nuevo'=>$nuevo,'modo_anterior'=>$modoPrev,'modo_nuevo'=>$modo,'piezas_anteriores'=>$factorPrev,'piezas_nuevas'=>$factor,'motivo'=>$motivo],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $db->prepare('INSERT INTO pos_auditoria(usuario_id,venta_id,accion,detalle) VALUES(?,NULL,?,?)')->execute([$actor,'FICHA_COMERCIAL_CATALOGO',$payload]);
        }
        $db->commit();
        return $cambioPrecio || $cambioPres ? 'Precio y presentación guardados. Ninguna existencia cambió.' : 'No se detectaron cambios en el precio ni la presentación.';
    } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function gyd_co_guardar_conteo(PDO $db,array $u,array $post): string {
    gyd_co_guardia($db);
    $s=filter_var($post['sucursal']??null,FILTER_VALIDATE_INT);
    $p=filter_var($post['producto_id']??null,FILTER_VALIDATE_INT);
    $n=filter_var($post['existencia']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>10000000]]);
    $motivo=trim((string)($post['motivo']??''));$actor=(int)($u['id']??0);
    $origExist=trim((string)($post['existencia_original']??''));
    if (!in_array($s,[1,2],true)||!$p||$p<1||$actor<1||$n===false||
        mb_strlen($motivo)<5||mb_strlen($motivo)>250||($origExist!==''&&!preg_match('/^\d+$/D',$origExist))) {
        throw new DomainException('Revisa producto, sucursal, piezas físicas y motivo (mínimo 5 caracteres).');
    }
    $bloqueo=pi_bloquear_pos($db,(int)$s,(int)$p);
    try {
      $db->beginTransaction();
      try {
        $st=$db->prepare('SELECT id FROM productos WHERE id=? AND estado=1 FOR UPDATE');$st->execute([$p]);
        if(!$st->fetchColumn())throw new DomainException('Producto inexistente o inactivo.');
        $antes=gyd_pos_saldo_dividido($db,(int)$s,(int)$p,true);
        $ePrev=$antes['farmacia']===null?'':(string)$antes['farmacia'];
        if($origExist!==$ePrev)throw new DomainException('El conteo físico cambió mientras editabas. Actualiza la ficha.');
        $prevMin=0;
        $st=$db->prepare('SELECT minimo FROM erp_pos_farmacia_control WHERE sucursal_id=? AND producto_id=?');$st->execute([$s,$p]);
        $minDato=$st->fetchColumn();if($minDato!==false)$prevMin=(int)$minDato;
        // El mínimo no gobierna ventas ni peticiones; se conserva solo en historial heredado.
        $db->prepare('INSERT INTO erp_pos_farmacia_control(sucursal_id,producto_id,existencia,minimo,actualizado_por) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE existencia=VALUES(existencia),actualizado_por=VALUES(actualizado_por)')->execute([$s,$p,$n,$prevMin,$actor]);
        $totalNuevo=$n+$antes['almacen'];
        $db->prepare('INSERT INTO pos_existencias_farmacia(sucursal_id,producto_id,existencia) VALUES(?,?,?) ON DUPLICATE KEY UPDATE existencia=VALUES(existencia)')->execute([$s,$p,$totalNuevo]);
        $db->prepare('INSERT INTO erp_pos_conteos_historial(sucursal_id,producto_id,anterior,nueva,minimo_anterior,minimo_nuevo,usuario_id,motivo) VALUES(?,?,?,?,?,?,?,?)')->execute([$s,$p,$antes['farmacia'],$n,$prevMin,$prevMin,$actor,$motivo]);
        $json=json_encode(['origen'=>'CATALOGO_MAESTRO','sucursal'=>$s,'producto'=>$p,'general_anterior'=>$antes['general'],'general_nuevo'=>$totalNuevo,'farmacia_anterior'=>$antes['farmacia'],'farmacia_nueva'=>$n,'almacen'=>$antes['almacen'],'motivo'=>$motivo],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $db->prepare('INSERT INTO pos_auditoria(usuario_id,venta_id,accion,detalle) VALUES(?,NULL,?,?)')->execute([$actor,'CONCILIACION_FARMACIA_GENERAL',$json]);
        $db->commit();
        return 'Conteo guardado. General = Farmacia ('.$n.') + Almacén ('.$antes['almacen'].') = '.$totalNuevo.' piezas. Sin peticiones ni tickets.';
      }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }finally{pi_liberar_pos($db,$bloqueo);}
}
