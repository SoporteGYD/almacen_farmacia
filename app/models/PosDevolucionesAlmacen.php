<?php
declare(strict_types=1);
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../helpers/pos_inteligente.php';

/** Devoluciones de venta POS cuyo origen fue Almacén. No afecta tickets originales. */
final class PosDevolucionesAlmacen {
    private PDO $db;
    public function __construct() {$this->db=(new Database())->connect();}
    private function q(string $sql,array $params=[]):PDOStatement {$s=$this->db->prepare($sql);$s->execute($params);return $s;}
    private static function perm(array $u):int {
        $rol=strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));
        if(!in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION'],true))throw new DomainException('Requiere ADMINISTRADOR o DIRECCIÓN.');
        $id=(int)($u['id']??0);if($id<1)throw new DomainException('Sesión inválida.');return $id;
    }
    private static function cent(mixed $n):int {
        if(!preg_match('/^\d{1,11}(?:\.\d{1,2})?$/',(string)$n))throw new DomainException('Importe inválido.');
        [$ent,$dec]=array_pad(explode('.',(string)$n,2),2,'');
        return (int)$ent*100+(int)str_pad($dec,2,'0');
    }
    private static function pesos(int $n):string {return number_format($n/100,2,'.','');}
    private function aud(int $uid,int $vid,string $accion,array $datos):void {
        $this->q('INSERT INTO pos_auditoria(usuario_id,venta_id,accion,detalle) VALUES (?,?,?,?)',[$uid,$vid,$accion,json_encode($datos,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }
    public function pendientes():array {
        return $this->q("SELECT x.*,v.folio venta_folio,v.sucursal_id,p.descripcion,u.nombre solicitante
            FROM pos_devoluciones_almacen x JOIN pos_ventas v ON v.id=x.venta_id
            JOIN productos p ON p.id=x.producto_id JOIN usuarios u ON u.id=x.solicitado_por
            ORDER BY (x.estado='SOLICITADA') DESC,x.id DESC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC);
    }
    public function opciones(int $ventaId):array {
        if($ventaId<1)return [];
        return $this->q("SELECT d.id,d.producto_id,d.descripcion_snapshot,d.cantidad,d.presentacion,d.piezas_por_presentacion,d.total_renglon,
               o.origen,o.almacen_ticket_id,COALESCE((SELECT SUM(x.cantidad_presentaciones) FROM pos_devoluciones_almacen x
                  WHERE x.detalle_id=d.id AND x.estado='SOLICITADA'),0) reservado,
               COALESCE((SELECT SUM(r.cantidad_presentaciones) FROM pos_reversiones r
                  WHERE r.detalle_id=d.id AND r.tipo='DEVOLUCION_PARCIAL' AND r.estado='APLICADA'),0) devuelto
            FROM pos_venta_detalle d LEFT JOIN pos_venta_origenes o ON o.venta_id=d.venta_id AND o.producto_id=d.producto_id
            WHERE d.venta_id=? ORDER BY d.id",[$ventaId])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function ubicaciones(int $producto,int $sucursal):array {
        $alm=pi_almacen($sucursal);if(!$alm)return [];
        $loc=$alm===1?['CIUDAD HIDALGO','CD HIDALGO']:['TUXTLA','TUXTLA GUTIERREZ','TUXTLA GUTIÉRREZ'];
        $ph=implode(',',array_fill(0,count($loc),'?'));
        return $this->q("SELECT id,sucursal,ubicacion,existencia FROM producto_existencias WHERE producto_id=?
            AND UPPER(TRIM(sucursal)) COLLATE utf8mb4_general_ci IN ($ph)
            AND TRIM(COALESCE(ubicacion,''))<>''
            AND UPPER(TRIM(ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION','SIN UBICACIÓN')
            ORDER BY sucursal,ubicacion",array_merge([$producto],$loc))->fetchAll(PDO::FETCH_ASSOC);
    }
    private function validarTicket(int $ventaId,int $pid,int $ticketId,int $solicitadas):array {
        $t=$this->q('SELECT * FROM erp_pos_tickets_almacen WHERE id=? AND venta_id=? AND producto_id=? FOR UPDATE',[$ticketId,$ventaId,$pid])->fetch(PDO::FETCH_ASSOC);
        if(!$t)throw new DomainException('La partida no tiene un ticket de Almacén vinculado a esta venta. Registra incidencia.');
        $r=$this->q('SELECT * FROM resurtidos WHERE id=? FOR UPDATE',[$t['resurtido_id']])->fetch(PDO::FETCH_ASSOC);
        $d=$this->q('SELECT cantidad_surtida FROM resurtido_detalles WHERE resurtido_id=? AND producto_id=? FOR UPDATE',[$t['resurtido_id'],$pid])->fetchColumn();
        // No reconstruir los stocks de una venta que no tiene salida física inequívoca.
        if(!$r||$r['estado']!=='SURTIDO'||$r['salida_id']===null||$d===false||round((float)$d)<$solicitadas)
            throw new DomainException('Este ticket no consta totalmente surtido con salida física. Requiere conciliación manual.');
        $mov=$this->q("SELECT id FROM movimientos WHERE id=? AND tipo_movimiento='SALIDA' AND cancelado=0 AND almacen_id=?",[(int)$r['salida_id'],(int)$r['almacen_id']])->fetchColumn();
        if($mov===false)throw new DomainException('No se pudo validar la salida real original de Almacén.');
        return $r;
    }
    /** Solicitud de devolución, SIN alteración de stocks ni de pagos. */
    public function solicitar(array $user,array $post):string {
        $uid=self::perm($user);$vid=(int)($post['venta_id']??0);$detId=(int)($post['detalle_id']??0);
        $cant=filter_var($post['cantidad']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
        $clave=(string)($post['clave']??'');$motivo=trim((string)($post['motivo']??''));
        if($vid<1||$detId<1||!$cant||!preg_match('/^[a-f0-9]{32}$/',$clave)||mb_strlen($motivo)<8||mb_strlen($motivo)>500)
            throw new DomainException('Completa partida, cantidad y motivo de 8 a 500 caracteres.');
        $this->db->beginTransaction();
        try{
            $v=$this->q('SELECT id,estado,sucursal_id FROM pos_ventas WHERE id=? FOR UPDATE',[$vid])->fetch(PDO::FETCH_ASSOC);
            if(!$v||$v['estado']!=='PAGADA'||!pi_almacen((int)$v['sucursal_id']))throw new DomainException('La venta debe estar PAGADA y pertenecer a una sucursal con Almacén.');
            $d=$this->q('SELECT * FROM pos_venta_detalle WHERE id=? AND venta_id=? FOR UPDATE',[$detId,$vid])->fetch(PDO::FETCH_ASSOC);
            if(!$d)throw new DomainException('La partida no pertenece a la venta.');
            $pid=(int)$d['producto_id'];$factor=(int)($d['piezas_por_presentacion']??0);
            if($factor<1||$factor>100000)throw new DomainException('Equivalencia de presentación inválida.');
            $orig=$this->q('SELECT origen,almacen_ticket_id,piezas FROM pos_venta_origenes WHERE venta_id=? AND producto_id=? FOR UPDATE',[$vid,$pid])->fetch(PDO::FETCH_ASSOC);
            if(!$orig||$orig['origen']!=='ALMACEN'||(int)$orig['almacen_ticket_id']<1)throw new DomainException('No se verificó origen ALMACÉN con ticket POS rastreable; registra una incidencia.');
            $this->validarTicket($vid,$pid,(int)$orig['almacen_ticket_id'],(int)$orig['piezas']);
            $prev=(int)$this->q("SELECT COALESCE(SUM(cantidad_presentaciones),0) FROM pos_devoluciones_almacen WHERE detalle_id=? AND estado='SOLICITADA'",[$detId])->fetchColumn();
            $dev=(int)$this->q("SELECT COALESCE(SUM(cantidad_presentaciones),0) FROM pos_reversiones WHERE detalle_id=? AND estado='APLICADA' AND tipo='DEVOLUCION_PARCIAL'",[$detId])->fetchColumn();
            $rest=(int)$d['cantidad']-$prev-$dev;
            if($rest<1||$cant>$rest)throw new DomainException('Cantidad excede las presentaciones pendientes de devolver.');
            $pieces=$cant*$factor;
            if($pieces>100000000)throw new DomainException('Piezas fuera de rango.');
            $total=self::cent($d['total_renglon']);
            $prevImporte=self::cent($this->q("SELECT COALESCE(SUM(importe),0) FROM pos_reversiones WHERE detalle_id=? AND estado='APLICADA' AND tipo='DEVOLUCION_PARCIAL'",[$detId])->fetchColumn());
            $reservadoImporte=self::cent($this->q("SELECT COALESCE(SUM(importe),0) FROM pos_devoluciones_almacen WHERE detalle_id=? AND estado='SOLICITADA'",[$detId])->fetchColumn());
            $saldoCent=$total-$prevImporte-$reservadoImporte;
            if($saldoCent<=0)throw new DomainException('Esta partida ya agotó su importe reembolsable.');
            $importe=($cant===$rest)?$saldoCent:(int)round($total*$cant/(int)$d['cantidad']);
            if($importe>$saldoCent)$importe=$saldoCent;
            if($importe<=0)throw new DomainException('Importe inválido; revisa el ticket.');
            $this->q('INSERT INTO pos_devoluciones_almacen(clave,venta_id,detalle_id,producto_id,ticket_id,cantidad_presentaciones,piezas,importe,motivo,solicitado_por) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$clave,$vid,$detId,$pid,(int)$orig['almacen_ticket_id'],$cant,$pieces,self::pesos($importe),$motivo,$uid]);
            $id=(int)$this->db->lastInsertId();
            $this->aud($uid,$vid,'DEVOLUCION_ALMACEN_SOLICITADA',['id'=>$id,'partida'=>$detId,'piezas'=>$pieces,'sin_movimientos'=>true]);
            $this->db->commit();return 'Solicitud de devolución #'.$id.' registrada. Sin movimiento de inventario ni dinero.';
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    /** Confirma devolución apta físicamente recibida y reembolsada: stock general + stock de almacén UNA VEZ. */
    public function aplicar(array $user,array $post):string {
        $uid=self::perm($user);$id=(int)($post['devolucion_id']??0);$locId=(int)($post['ubicacion_id']??0);
        $recibe=trim((string)($post['recibido_por']??''));$evidencia=trim((string)($post['evidencia']??''));
        $medio=(string)($post['medio']??'');$referencia=trim((string)($post['referencia']??''));
        if($id<1||$locId<1||mb_strlen($recibe)<3||mb_strlen($recibe)>150||mb_strlen($evidencia)<12||mb_strlen($evidencia)>500)
            throw new DomainException('Indica ubicación, receptor y evidencia de la devolución física.');
        if(!in_array($medio,['EFECTIVO','TARJETA','TRANSFERENCIA','OTRO'],true)||($medio!=='EFECTIVO'&&mb_strlen($referencia)<4)||mb_strlen($referencia)>120)
            throw new DomainException('Selecciona medio del reembolso realizado y su referencia.');
        if(($post['mercancia_apta']??'')!=='1'||($post['devolucion_fisica']??'')!=='1'||($post['reembolso_realizado']??'')!=='1')
            throw new DomainException('Se requiere confirmar mercancía APTA, recepción física y reembolso YA realizado.');
        $pre=$this->q('SELECT x.producto_id,v.sucursal_id FROM pos_devoluciones_almacen x JOIN pos_ventas v ON v.id=x.venta_id WHERE x.id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if(!$pre)throw new DomainException('Solicitud no encontrada.');
        $lock=pi_bloquear_pos($this->db,(int)$pre['sucursal_id'],(int)$pre['producto_id']);
        try{
            $this->db->beginTransaction();
            $x=$this->q('SELECT * FROM pos_devoluciones_almacen WHERE id=? FOR UPDATE',[$id])->fetch(PDO::FETCH_ASSOC);
            if(!$x||$x['estado']!=='SOLICITADA')throw new DomainException('Esta solicitud ya fue atendida o requiere revisión. No puede aplicarse dos veces.');
            $v=$this->q('SELECT * FROM pos_ventas WHERE id=? FOR UPDATE',[$x['venta_id']])->fetch(PDO::FETCH_ASSOC);
            if(!$v||$v['estado']!=='PAGADA'||(int)$v['sucursal_id']!==(int)$pre['sucursal_id'])throw new DomainException('La venta ya no está pagada o cambió de sucursal.');
            $orig=$this->q('SELECT * FROM pos_venta_origenes WHERE venta_id=? AND producto_id=? FOR UPDATE',[$x['venta_id'],$x['producto_id']])->fetch(PDO::FETCH_ASSOC);
            if(!$orig||$orig['origen']!=='ALMACEN'||(int)$orig['almacen_ticket_id']!==(int)$x['ticket_id'])throw new DomainException('No coincide el origen del ticket original.');
            $this->validarTicket((int)$x['venta_id'],(int)$x['producto_id'],(int)$x['ticket_id'],(int)$orig['piezas']);
            $alm=pi_almacen((int)$v['sucursal_id']);
            $loc=$this->q('SELECT * FROM producto_existencias WHERE id=? AND producto_id=? FOR UPDATE',[$locId,$x['producto_id']])->fetch(PDO::FETCH_ASSOC);
            if(!$loc||!$alm)throw new DomainException('Ubicación no válida para ese producto.');
            $validLoc=$this->ubicaciones((int)$x['producto_id'],(int)$v['sucursal_id']);
            if(!in_array($locId,array_map(static fn(array $row)=>(int)$row['id'],$validLoc),true))
                throw new DomainException('Esta ubicación no pertenece al Almacén de la sucursal.');
            $stock=$this->q('SELECT id FROM pos_existencias_farmacia WHERE sucursal_id=? AND producto_id=? FOR UPDATE',[$v['sucursal_id'],$x['producto_id']])->fetchColumn();
            if($stock===false)throw new DomainException('El producto no tiene existencia general configurada en el POS.');
            $turno=null;
            if($medio==='EFECTIVO'){
                $turno=$this->q("SELECT id FROM pos_turnos WHERE caja_id=? AND estado='ABIERTO' ORDER BY id DESC LIMIT 1 FOR UPDATE",[$v['caja_id']])->fetchColumn();
                if(!$turno)throw new DomainException('Abre un turno en la caja original para registrar retiro de efectivo, o registra una incidencia financiera.');
            }
            // El reintegro físico SOLO es válido para mercancía apta que no fue ya capturada en otro conteo.
            if(($post['no_contabilizada']??'')!=='1')throw new DomainException('Confirma que las piezas NO se sumaron antes al inventario.');
            $this->q('UPDATE producto_existencias SET existencia=existencia+? WHERE id=?',[(int)$x['piezas'],$locId]);
            $this->q('UPDATE pos_existencias_farmacia SET existencia=existencia+? WHERE id=?',[(int)$x['piezas'],$stock]);
            $ref='DEVOLUCION POS '.$v['folio'].' / solicitud #'.$id;
            $folio='DVP-'.$alm.'-'.$id;
            $this->q("INSERT INTO movimientos(folio,tipo_movimiento,fecha,almacen_id,usuario_id,referencia,tipo_operacion,observaciones) VALUES (?,'ENTRADA',NOW(),?, ?,?, 'DEVOLUCION_POS',?)",
                [$folio,$alm,$uid,$ref,mb_substr('Verificada por: '.$recibe.' | '.$evidencia,0,500)]);
            $mov=(int)$this->db->lastInsertId();
            $this->q('INSERT INTO movimiento_detalle(movimiento_id,producto_id,cantidad,costo_unitario,precio_unitario,ubicacion) VALUES (?,?,?,0,0,?)',
                [$mov,$x['producto_id'],$x['piezas'],$loc['ubicacion']]);
            if($medio==='EFECTIVO')$this->q("INSERT INTO pos_movimientos_caja(turno_id,usuario_id,tipo,monto,motivo) VALUES (?,?,'RETIRO',?,?)",
                [$turno,$uid,$x['importe'],'Devolución Almacén '.$v['folio'].' / #'.$id]);
            // Reintegro a POS y Almacén, sin reabrir/editar ni revertir el ticket de surtido original.
            $this->q("INSERT INTO pos_reversiones(operacion_clave,venta_id,tipo,estado,detalle_id,producto_id,cantidad_presentaciones,piezas,importe,medio_reembolso,referencia_reembolso,motivo,usuario_id,turno_reembolso_id) VALUES (?,?,'DEVOLUCION_PARCIAL','APLICADA',?,?,?,?,?,?,?,?,?,?)",
                [sprintf('%s-%s-%s-%s-%s',substr($x['clave'],0,8),substr($x['clave'],8,4),substr($x['clave'],12,4),substr($x['clave'],16,4),substr($x['clave'],20,12)),
                 $x['venta_id'],$x['detalle_id'],$x['producto_id'],$x['cantidad_presentaciones'],$x['piezas'],$x['importe'],$medio,$referencia?:null,$x['motivo'],$uid,$turno?:null]);
            $this->q("UPDATE pos_devoluciones_almacen SET estado='APLICADA',ubicacion_existencia_id=?,movimiento_id=?,medio_reembolso=?,referencia_reembolso=?,recibido_por=?,evidencia=?,aprobado_por=?,aprobado_en=NOW() WHERE id=? AND estado='SOLICITADA'",
                [$locId,$mov,$medio,$referencia?:null,$recibe,$evidencia,$uid,$id]);
            $this->aud($uid,(int)$x['venta_id'],'DEVOLUCION_ALMACEN_APLICADA',[
                'devolucion_id'=>$id,'ticket_id'=>$x['ticket_id'],'movimiento_id'=>$mov,'ubicacion_id'=>$locId,
                'piezas'=>$x['piezas'],'importe'=>$x['importe'],'medio'=>$medio,'referencia'=>$referencia,
                'evidencia'=>$evidencia,'recibido_por'=>$recibe,'stock_pos_restaurado'=>true,'stock_almacen_restaurado'=>true]);
            $this->db->commit();
            return 'Devolución #'.$id.' aplicada: POS general y Almacén +'.$x['piezas'].' piezas. Movimiento '.$folio.'. El reembolso queda documentado, no se ejecuta en el banco.';
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        finally{pi_liberar_pos($this->db,$lock);}
    }
    public function revisar(array $user,array $post):string {
        $uid=self::perm($user);$id=(int)($post['devolucion_id']??0);$motivo=trim((string)($post['motivo_revision']??''));
        if($id<1||mb_strlen($motivo)<10||mb_strlen($motivo)>500)throw new DomainException('Explica qué falta resolver (10 a 500 caracteres).');
        $this->db->beginTransaction();try{
            $x=$this->q('SELECT * FROM pos_devoluciones_almacen WHERE id=? FOR UPDATE',[$id])->fetch(PDO::FETCH_ASSOC);
            if(!$x||$x['estado']!=='SOLICITADA')throw new DomainException('La solicitud ya fue atendida.');
            $this->q("UPDATE pos_devoluciones_almacen SET estado='REQUIERE_REVISION',observaciones_revision=?,aprobado_por=?,aprobado_en=NOW() WHERE id=?",[$motivo,$uid,$id]);
            $this->aud($uid,(int)$x['venta_id'],'DEVOLUCION_ALMACEN_REVISION',['id'=>$id,'motivo'=>$motivo,'inventario_modificado'=>false]);
            $this->db->commit();return 'Solicitud #'.$id.' enviada a revisión. No se modificó inventario ni caja.';
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
