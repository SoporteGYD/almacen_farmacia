<?php
declare(strict_types=1);
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../helpers/pos_inteligente.php';
require_once __DIR__.'/../helpers/gyd_rewards_lineas_v47.php';

/** Reversas de POS con trazabilidad, sin alterar las salidas físicas ya surtidas. */
final class PosReversiones {
    private PDO $db;
    public function __construct() { $this->db=(new Database())->connect(); }
    private function query(string $sql,array $params=[]):PDOStatement {
        $st=$this->db->prepare($sql);$st->execute($params);return $st;
    }
    private static function cantidadDinero(mixed $n):int {
        $s=(string)$n;
        if(!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/',$s))throw new DomainException('Importe inválido.');
        [$a,$b]=array_pad(explode('.',$s,2),2,'');
        return (int)$a*100+(int)str_pad($b,2,'0');
    }
    private static function moneda(int $cent):string {return number_format($cent/100,2,'.','');}
    private static function permiso(array $user):void {
        $rol=strtoupper(trim((string)($user['rol_original']??$user['rol']??'')));
        if(!in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION'],true))throw new DomainException('Solo ADMINISTRADOR o DIRECCIÓN pueden autorizar devoluciones.');
    }
    public function buscar(string $buscar):array {
        $buscar=mb_substr(trim($buscar),0,75);
        if($buscar==='')return [];
        $st=$this->query("SELECT v.id,v.folio,v.estado,v.total,v.pagada_en,v.sucursal_id,s.nombre sucursal,u.nombre cajero
            FROM pos_ventas v JOIN pos_sucursales s ON s.id=v.sucursal_id JOIN usuarios u ON u.id=v.usuario_id
            WHERE v.folio LIKE ? ORDER BY v.id DESC LIMIT 25",['%'.$buscar.'%']);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    public function venta(int $id):?array {
        $st=$this->query('SELECT v.*,s.nombre sucursal,u.nombre cajero,c.nombre caja,t.estado turno_estado FROM pos_ventas v JOIN pos_sucursales s ON s.id=v.sucursal_id JOIN usuarios u ON u.id=v.usuario_id JOIN pos_cajas c ON c.id=v.caja_id JOIN pos_turnos t ON t.id=v.turno_id WHERE v.id=?',[$id]);
        $v=$st->fetch(PDO::FETCH_ASSOC);
        if(!$v)return null;
        $v['detalle']=$this->query('SELECT d.*,o.origen,o.almacen_ticket_id FROM pos_venta_detalle d LEFT JOIN pos_venta_origenes o ON o.venta_id=d.venta_id AND o.producto_id=d.producto_id WHERE d.venta_id=? ORDER BY d.id',[$id])->fetchAll(PDO::FETCH_ASSOC);
        $v['reversiones']=$this->query('SELECT r.*,u.nombre autorizado_por FROM pos_reversiones r JOIN usuarios u ON u.id=r.usuario_id WHERE r.venta_id=? ORDER BY r.id DESC',[$id])->fetchAll(PDO::FETCH_ASSOC);
        $v['origen_legacy']=$this->query('SELECT vl.*,u.nombre verificado_por FROM pos_origen_legacy_verificaciones vl JOIN usuarios u ON u.id=vl.usuario_id WHERE vl.venta_id=? ORDER BY vl.id',[$id])->fetchAll(PDO::FETCH_ASSOC);
        return $v;
    }
    public function ticketsSinCobrar():array {
        return $this->query("SELECT t.id,t.resurtido_id,t.sucursal_id,t.producto_id,t.cantidad,r.folio,r.estado,r.salida_id,r.almacen_id,d.cantidad_surtida,p.descripcion,u.nombre cajero
            FROM erp_pos_tickets_almacen t JOIN resurtidos r ON r.id=t.resurtido_id
            JOIN resurtido_detalles d ON d.resurtido_id=r.id AND d.producto_id=t.producto_id
            JOIN productos p ON p.id=t.producto_id JOIN usuarios u ON u.id=t.usuario_id
            WHERE t.venta_id IS NULL AND r.estado IN ('PENDIENTE','EN_PROCESO','PARCIAL')
            ORDER BY t.id DESC LIMIT 70")->fetchAll(PDO::FETCH_ASSOC);
    }
    private function audit(int $usuario,?int $venta,string $accion,array $dato):void {
        $this->query('INSERT INTO pos_auditoria(usuario_id,venta_id,accion,detalle) VALUES (?,?,?,?)',
            [$usuario,$venta,$accion,json_encode($dato,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }
    /** Verifica documentalmente la procedencia de una venta histórica. SIN tocar existencias ni caja. */
    public function verificarOrigenHistorico(array $usr,array $post):string {
        self::permiso($usr);
        $uid=(int)($usr['id']??0);
        $ventaId=(int)($post['venta_id']??0);
        $productoId=(int)($post['producto_id']??0);
        $origen=strtoupper(trim((string)($post['origen_historico']??'')));
        $evidencia=trim((string)($post['evidencia']??''));
        $folio=trim((string)($post['confirmacion_folio']??''));
        $revisado=((string)($post['verificacion_documental']??''))==='1';
        if($uid<1||$ventaId<1||$productoId<1||!in_array($origen,['FARMACIA','ALMACEN','FARMACIA_GENERAL'],true))
            throw new DomainException('Venta, producto u origen no válidos.');
        if(mb_strlen($evidencia)<15||mb_strlen($evidencia)>500||!$revisado)
            throw new DomainException('Describe la evidencia (15 a 500 caracteres) y confirma la verificación documental.');
        $this->db->beginTransaction();
        try {
            $v=$this->query('SELECT id,folio,estado,sucursal_id FROM pos_ventas WHERE id=? FOR UPDATE',[$ventaId])->fetch(PDO::FETCH_ASSOC);
            if(!$v||$v['estado']!=='PAGADA')throw new DomainException('Solo puedes verificar ventas pagadas no canceladas.');
            if(!hash_equals(strtoupper((string)$v['folio']),strtoupper($folio)))throw new DomainException('Escribe el folio exacto de la venta para confirmar.');
            $sucursal=(int)$v['sucursal_id'];$almacen=pi_almacen($sucursal);
            if(!$almacen && $origen!=='FARMACIA_GENERAL')throw new DomainException('En esta sucursal con inventario único selecciona FARMACIA GENERAL.');
            if($almacen && $origen==='FARMACIA_GENERAL')throw new DomainException('En esta sucursal debes verificar si salió de FARMACIA o ALMACÉN.');
            $detalles=$this->query('SELECT id,cantidad,piezas_por_presentacion,unidades_descontadas FROM pos_venta_detalle WHERE venta_id=? AND producto_id=? FOR UPDATE',[$ventaId,$productoId])->fetchAll(PDO::FETCH_ASSOC);
            if(!$detalles)throw new DomainException('Ese producto no pertenece a la venta.');
            $piezas=0;
            foreach($detalles as $d){
                $cantidad=(int)$d['cantidad'];$factor=(int)($d['piezas_por_presentacion']??1);
                if($cantidad<1||$factor<1)throw new DomainException('Equivalencia histórica inválida. Registrar incidencia para revisión.');
                $piezasLinea=$cantidad*$factor;
                if($piezasLinea<1||$piezasLinea>100000000)throw new DomainException('Piezas fuera de rango.');
                $piezas+=$piezasLinea;
            }
            if($piezas>100000000)throw new DomainException('Piezas fuera de rango.');
            if($this->query('SELECT producto_id FROM pos_venta_origenes WHERE venta_id=? AND producto_id=? FOR UPDATE',[$ventaId,$productoId])->fetchColumn()!==false)
                throw new DomainException('El origen de este producto ya fue registrado. No se permite modificarlo silenciosamente.');
            if($this->query('SELECT id FROM pos_origen_legacy_verificaciones WHERE venta_id=? AND producto_id=? FOR UPDATE',[$ventaId,$productoId])->fetchColumn()!==false)
                throw new DomainException('Ya existe una verificación histórica para este producto.');
            if($this->query('SELECT id FROM erp_pos_tickets_almacen WHERE venta_id=? AND producto_id=? LIMIT 1',[$ventaId,$productoId])->fetchColumn()!==false)
                throw new DomainException('Esta venta ya tiene un ticket POS vinculado. Usa el historial de tickets, no un origen manual.');
            if($this->query("SELECT id FROM pos_reversiones WHERE venta_id=? AND producto_id=? AND estado='APLICADA' LIMIT 1",[$ventaId,$productoId])->fetchColumn()!==false)
                throw new DomainException('Ya existen reversas aplicadas para este producto; requiere conciliación manual.');
            if($origen==='FARMACIA'){
                $ctl=$this->query('SELECT existencia FROM erp_pos_farmacia_control WHERE sucursal_id=? AND producto_id=?',[$sucursal,$productoId])->fetchColumn();
                if($ctl===false)throw new DomainException('No hay conteo físico configurado de Farmacia. Configúralo y verifica el saldo antes de registrar el origen.');
            }
            $this->query('INSERT INTO pos_venta_origenes(venta_id,producto_id,origen,piezas,almacen_ticket_id) VALUES (?,?,?,?,NULL)',[$ventaId,$productoId,$origen,$piezas]);
            $this->query('INSERT INTO pos_origen_legacy_verificaciones(venta_id,producto_id,origen,piezas,evidencia,usuario_id) VALUES (?,?,?,?,?,?)',[$ventaId,$productoId,$origen,$piezas,$evidencia,$uid]);
            $this->audit($uid,$ventaId,'VERIFICAR_ORIGEN_HISTORICO',['producto_id'=>$productoId,'origen'=>$origen,'piezas'=>$piezas,'evidencia'=>$evidencia,'inventario_modificado'=>false]);
            $this->db->commit();
            return 'Origen histórico verificado: '.$origen.' ('.$piezas.' piezas). No se modificaron existencias ni caja.';
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    /** Solo cancela reservas POS NO COBRADAS que todavia no generaron ninguna salida. */
    public function cancelarReserva(int $tid,array $usr,string $motivo):string {
        self::permiso($usr);
        $uid=(int)($usr['id']??0);$motivo=trim($motivo);
        if($uid<1||$tid<1||mb_strlen($motivo)<8||mb_strlen($motivo)>500)throw new DomainException('Motivo obligatorio de 8 a 500 caracteres.');
        $st=$this->query('SELECT sucursal_id,producto_id FROM erp_pos_tickets_almacen WHERE id=?',[$tid]);$pre=$st->fetch(PDO::FETCH_ASSOC);
        if(!$pre)throw new DomainException('Ticket POS no encontrado.');
        $candado=pi_bloquear_pos($this->db,(int)$pre['sucursal_id'],(int)$pre['producto_id']);
        try {
            $this->db->beginTransaction();
            $t=$this->query('SELECT * FROM erp_pos_tickets_almacen WHERE id=? FOR UPDATE',[$tid])->fetch(PDO::FETCH_ASSOC);
            if(!$t||$t['venta_id']!==null)throw new DomainException('El ticket ya está cobrado; usa una cancelación de venta.');
            $r=$this->query('SELECT * FROM resurtidos WHERE id=? FOR UPDATE',[$t['resurtido_id']])->fetch(PDO::FETCH_ASSOC);
            $d=$this->query('SELECT cantidad_surtida FROM resurtido_detalles WHERE resurtido_id=? AND producto_id=? FOR UPDATE',[$t['resurtido_id'],$t['producto_id']])->fetchColumn();
            if(!$r||!in_array($r['estado'],['PENDIENTE','EN_PROCESO','PARCIAL'],true)||$r['salida_id']!==null||(float)$d>0)
                throw new DomainException('Almacén ya inició o confirmó una salida. Requiere revisión física.');
            $this->query("UPDATE resurtidos SET estado='CANCELADO' WHERE id=?",[$r['id']]);
            $this->query('INSERT INTO pos_tickets_cancelados_erp(ticket_id,resurtido_id,usuario_id,motivo) VALUES (?,?,?,?)',[$tid,$r['id'],$uid,$motivo]);
            $this->audit($uid,null,'CANCELAR_RESERVA_POS',['ticket'=>$tid,'resurtido'=>$r['folio'],'motivo'=>$motivo]);
            $this->db->commit();return 'Solicitud '.$r['folio'].' cancelada. Reserva liberada; el stock general nunca fue descontado.';
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        finally {pi_liberar_pos($this->db,$candado);}
    }
    /** Cancelaciones totales o devoluciones parciales. Todos los productos deben tener origen registrado. */
    public function procesar(array $usr,array $post):string {
        self::permiso($usr);
        $uid=(int)($usr['id']??0);
        $ventaId=(int)($post['venta_id']??0);
        $tipo=(string)($post['tipo']??'');
        $clave=(string)($post['operacion_clave']??'');
        $motivo=trim((string)($post['motivo']??''));
        $medio=(string)($post['medio']??'');
        $referencia=trim((string)($post['referencia']??''));
        $fisico=((string)($post['fisico_confirmado']??''))==='1';
        $reembolso=((string)($post['reembolso_confirmado']??''))==='1';
        if($uid<1||$ventaId<1||!preg_match('/^[a-f0-9-]{36}$/i',$clave))throw new DomainException('Solicitud inválida.');
        if(!in_array($tipo,['CANCELACION_TOTAL','DEVOLUCION_PARCIAL','INCIDENCIA'],true))throw new DomainException('Operación inválida.');
        if(mb_strlen($motivo)<8||mb_strlen($motivo)>500)throw new DomainException('Describe el motivo con al menos 8 caracteres (máximo 500).');
        if($tipo==='INCIDENCIA')return $this->incidencia($uid,$ventaId,$clave,$motivo);
        if(!$fisico||!$reembolso)throw new DomainException('Confirma la recepción física de la mercancía y la ejecución real del reembolso.');
        if(!in_array($medio,['EFECTIVO','TARJETA','TRANSFERENCIA','OTRO'],true))throw new DomainException('Indica el medio real del reembolso.');
        if($medio!=='EFECTIVO' && mb_strlen($referencia)<4)throw new DomainException('Para reembolsos electrónicos o especiales registra una referencia (mínimo 4 caracteres).');
        if(mb_strlen($referencia)>120)throw new DomainException('Referencia demasiado larga.');
        $pre=$this->query('SELECT sucursal_id FROM pos_ventas WHERE id=?',[$ventaId])->fetch(PDO::FETCH_ASSOC);
        if(!$pre)throw new DomainException('Venta no encontrada.');
        $sucursal=(int)$pre['sucursal_id'];
        $locks=[];
        try {
            $this->db->beginTransaction();
            $v=$this->query('SELECT * FROM pos_ventas WHERE id=? FOR UPDATE',[$ventaId])->fetch(PDO::FETCH_ASSOC);
            if(!$v||$v['estado']!=='PAGADA')throw new DomainException('Solo se procesan ventas PAGADAS no canceladas.');
            if((int)$v['sucursal_id']!==$sucursal)throw new DomainException('Sucursal no coincide.');
            // Un mismo identificador nunca puede convertirse en una segunda operación.
            if($this->query('SELECT id FROM pos_reversiones WHERE operacion_clave=?',[$clave])->fetchColumn())
                throw new DomainException('La operación ya fue aplicada. Actualiza el historial.');
            $detalles=$this->query('SELECT * FROM pos_venta_detalle WHERE venta_id=? ORDER BY producto_id,id FOR UPDATE',[$ventaId])->fetchAll(PDO::FETCH_ASSOC);
            $origenes=$this->query('SELECT * FROM pos_venta_origenes WHERE venta_id=? ORDER BY producto_id FOR UPDATE',[$ventaId])->fetchAll(PDO::FETCH_ASSOC);
            $porProducto=[];foreach($origenes as $o)$porProducto[(int)$o['producto_id']]=$o;
            if(!$detalles||!$origenes)throw new DomainException('Venta histórica sin origen físico registrado: requiere conciliación manual, no reversa automática.');
            $listaProductos=array_values(array_unique(array_map(static fn($d)=>(int)$d['producto_id'],$detalles)));
            sort($listaProductos,SORT_NUMERIC);
            foreach($listaProductos as $pid) {
                if(!isset($porProducto[$pid]))throw new DomainException('No se conoce el origen de uno de los productos; registre una incidencia.');
                $locks[]=pi_bloquear_pos($this->db,$sucursal,$pid);
            }
            $anteriores=$this->query("SELECT detalle_id,COALESCE(SUM(cantidad_presentaciones),0) devuelto,COALESCE(SUM(importe),0) importe FROM pos_reversiones WHERE venta_id=? AND tipo='DEVOLUCION_PARCIAL' AND estado='APLICADA' GROUP BY detalle_id",[$ventaId])->fetchAll(PDO::FETCH_ASSOC);
            $prev=[];foreach($anteriores as $p)$prev[(int)$p['detalle_id']]=['qty'=>(int)$p['devuelto'],'cents'=>self::cantidadDinero($p['importe'])];
            if($tipo==='CANCELACION_TOTAL' && $prev)throw new DomainException('Ya existen devoluciones parciales. No puede cancelarse toda la venta otra vez.');
            $movimientos=[];$totalCent=0;$detId=null;$producto=null;$cantidad=0;$udsDevolucion=0;
            if($tipo==='CANCELACION_TOTAL'){
                // Al cancelar el turno original sigue abierto: de otro modo su cierre cambiaría retroactivamente.
                $turnoEstado=$this->query('SELECT estado FROM pos_turnos WHERE id=? FOR UPDATE',[$v['turno_id']])->fetchColumn();
                if($turnoEstado!=='ABIERTO')throw new DomainException('Turno original cerrado. Registra incidencia para conciliación financiera y autorización.');
                foreach($detalles as $d){
                    $id=(int)$d['producto_id'];$factor=(int)($d['piezas_por_presentacion']??1);
                    if($factor<1)throw new DomainException('Equivalencia de caja inválida.');
                    $movimientos[$id]=($movimientos[$id]??0)+(int)$d['cantidad']*$factor;
                }
                $totalCent=self::cantidadDinero($v['total']);
            }else{
                $detId=(int)($post['detalle_id']??0);$cantidad=(int)($post['cantidad']??0);
                $d=null;foreach($detalles as $fila)if((int)$fila['id']===$detId){$d=$fila;break;}
                if(!$d||$cantidad<1)throw new DomainException('Selecciona una partida y una cantidad válidas.');
                $id=(int)$d['producto_id'];$o=$porProducto[$id];
                if($o['origen']==='ALMACEN')throw new DomainException('La devolución parcial de mercancía surtida por Almacén necesita recepción y ubicación física; registra una incidencia.');
                $restantes=(int)$d['cantidad']-($prev[$detId]['qty']??0);
                if($restantes<1||$cantidad>$restantes)throw new DomainException('Esa partida ya fue devuelta o la cantidad excede el saldo.');
                // Conserva centavos exactos al devolver el último remanente de un renglón.
                $totalLinea=self::cantidadDinero($d['total_renglon']);
                $totalCent=$cantidad===$restantes?$totalLinea-($prev[$detId]['cents']??0):(int)round($totalLinea*$cantidad/(int)$d['cantidad']);
                if($totalCent<=0)throw new DomainException('Importe de devolución inválido.');
                $producto=$id;$udsDevolucion=$cantidad*max(1,(int)($d['piezas_por_presentacion']??1));
                $movimientos[$id]=$udsDevolucion;
            }
            $ticketsParaCancelar=[];
            // Las verificaciones documentales de ventas antiguas NO reconstruyen
            // movimientos físicos anteriores: exigir que lo devuelto no esté ya
            // incluido en el conteo vigente, evitando una segunda acreditación.
            foreach($movimientos as $pid=>$uds){
                $legacy=$this->query('SELECT id FROM pos_origen_legacy_verificaciones WHERE venta_id=? AND producto_id=?',[$ventaId,$pid])->fetchColumn();
                if($legacy!==false && (string)($post['historico_fuera_de_conteo']??'')!=='1')
                    throw new DomainException('Venta histórica: confirma expresamente que las piezas devueltas NO están incluidas en el conteo actual ni fueron restituidas antes.');
            }
            foreach($movimientos as $pid=>$uds){
                $o=$porProducto[$pid];
                if($o['origen']==='ALMACEN'){
                    if($tipo!=='CANCELACION_TOTAL')throw new DomainException('Las ventas con origen ALMACÉN requieren incidencia y devolución física documentada; no se acredita Almacén automáticamente.');
                    $tid=(int)($o['almacen_ticket_id']??0);
                    if($tid<1)throw new DomainException('Venta histórica desde ALMACÉN sin ticket original rastreable: solo incidencia y conciliación manual; no se modificará stock.');
                    $t=$this->query('SELECT * FROM erp_pos_tickets_almacen WHERE id=? FOR UPDATE',[$tid])->fetch(PDO::FETCH_ASSOC);
                    if(!$t||(int)$t['venta_id']!==$ventaId)throw new DomainException('No se encontró el ticket cobrado vinculado.');
                    $r=$this->query('SELECT * FROM resurtidos WHERE id=? FOR UPDATE',[$t['resurtido_id']])->fetch(PDO::FETCH_ASSOC);
                    $surtida=$this->query('SELECT cantidad_surtida FROM resurtido_detalles WHERE resurtido_id=? AND producto_id=? FOR UPDATE',[$t['resurtido_id'],$pid])->fetchColumn();
                    if(!$r||!in_array($r['estado'],['PENDIENTE','EN_PROCESO','PARCIAL'],true)||$r['salida_id']!==null||(float)$surtida>0)
                        throw new DomainException('Almacén ya surtió o inició salida. No se devolverá stock automáticamente; registrar incidencia y gestionar devolución física.');
                    $ticketsParaCancelar[]=$t;
                }
            }
            // GYD_STOCK_FOLIO_REVERSA_2026: enlazar la devolución con su folio sin modificar stock.
            $this->query('SET @gyd_stock_folio=?', [(string)$v['folio']]);
            // POS general: el cobro ya lo había descontado. Restaurar UNA VEZ.
            foreach($movimientos as $pid=>$uds){
                $stock=$this->query('SELECT id,existencia FROM pos_existencias_farmacia WHERE sucursal_id=? AND producto_id=? FOR UPDATE',[pi_tt_stock_sid($this->db,$sucursal),$pid])->fetch(PDO::FETCH_ASSOC);
                if(!$stock)throw new DomainException('No existe el registro general POS del producto '.$pid);
                if($uds<1||$uds>100000000)throw new DomainException('Cantidad fuera de límites.');
                $this->query('UPDATE pos_existencias_farmacia SET existencia=existencia+? WHERE id=?',[$uds,$stock['id']]);
                if($porProducto[$pid]['origen']==='FARMACIA'){
                    $ctl=$this->query('SELECT existencia,minimo FROM erp_pos_farmacia_control WHERE sucursal_id=? AND producto_id=? FOR UPDATE',[$sucursal,$pid])->fetch(PDO::FETCH_ASSOC);
                    if(!$ctl)throw new DomainException('No existe conteo físico de Farmacia. Registra incidencia en lugar de ajustar.');
                    $this->query('UPDATE erp_pos_farmacia_control SET existencia=existencia+? WHERE sucursal_id=? AND producto_id=?',[$uds,$sucursal,$pid]);
                    pi_alerta($this->db,$sucursal,$pid,(int)$ctl['existencia']+$uds,(int)$ctl['minimo']);
                }
            }
            // Desligar la venta antes de cancelar el resurtido: el disparador impide cancelar tickets vendidos.
            foreach($ticketsParaCancelar as $ticket){
                $this->query('UPDATE erp_pos_tickets_almacen SET venta_id=NULL WHERE id=? AND venta_id=?',[$ticket['id'],$ventaId]);
                $this->query("UPDATE resurtidos SET estado='CANCELADO' WHERE id=?",[$ticket['resurtido_id']]);
                $this->query('INSERT INTO pos_tickets_cancelados_erp(ticket_id,resurtido_id,usuario_id,motivo) VALUES (?,?,?,?)',[$ticket['id'],$ticket['resurtido_id'],$uid,'Reversa venta '.$v['folio'].': '.$motivo]);
            }
            // Caja: el dinero recibido originalmente se conserva en el libro aunque la venta se cancele.
            // Cada reembolso EN EFECTIVO crea UN egreso físico, en el turno actual y dentro de esta transacción.
            $turno=null;
            if($tipo==='CANCELACION_TOTAL' || $medio==='EFECTIVO'){
                $t=$this->query("SELECT id,fondo_inicial FROM pos_turnos WHERE caja_id=? AND estado='ABIERTO' ORDER BY id DESC LIMIT 1 FOR UPDATE",[$v['caja_id']])->fetch(PDO::FETCH_ASSOC);
                if(!$t)throw new DomainException('Para devolver efectivo o cancelar el ticket abre primero un turno en la caja.');
                $turno=(int)$t['id'];
                if($tipo==='CANCELACION_TOTAL' && $turno!==(int)$v['turno_id'])
                    throw new DomainException('La cancelación total requiere el turno original abierto. Registra incidencia para conciliación manual.');
            }
            if($medio==='EFECTIVO'){
                // En este momento la venta todavía está PAGADA; el saldo la incluye. Se protege contra retiros superiores al disponible.
                $cobrado=$this->query("SELECT COALESCE(SUM(pg.monto),0) FROM pos_pagos pg JOIN pos_ventas v ON v.id=pg.venta_id WHERE v.turno_id=? AND pg.metodo='EFECTIVO' AND pg.estado='CONFIRMADO' AND (v.estado='PAGADA' OR (v.estado='CANCELADA' AND EXISTS (SELECT 1 FROM pos_reversiones grc WHERE grc.venta_id=v.id AND grc.tipo='CANCELACION_TOTAL' AND grc.estado='APLICADA' AND grc.turno_reembolso_id=v.turno_id)))",[$turno])->fetchColumn();
                $cashMov=$this->query("SELECT tipo,COALESCE(SUM(monto),0) monto FROM pos_movimientos_caja WHERE turno_id=? AND tipo IN ('INGRESO','RETIRO') GROUP BY tipo",[$turno])->fetchAll(PDO::FETCH_ASSOC);
                $saldo=self::cantidadDinero($t['fondo_inicial'])+self::cantidadDinero($cobrado);
                foreach($cashMov as $cm){$c=self::cantidadDinero($cm['monto']);$saldo+=($cm['tipo']==='INGRESO'?$c:-$c);}
                if($totalCent>$saldo)throw new DomainException('No hay suficiente efectivo disponible para realizar este reembolso. Revisa el arqueo y registra una incidencia; no se modificó nada.');
                $concepto='Devolución de '.$v['folio'].' / '.($tipo==='CANCELACION_TOTAL'?'cancelación total':'partida '.$detId).' / '.$clave;
                $this->query("INSERT INTO pos_movimientos_caja(turno_id,usuario_id,tipo,monto,motivo) VALUES (?,?,'RETIRO',?,?)",[$turno,$uid,self::moneda($totalCent),$concepto]);
            }
            if($tipo==='CANCELACION_TOTAL'){
                $this->query("UPDATE pos_ventas SET estado='CANCELADA',cancelada_en=NOW(),motivo_cancelacion=? WHERE id=? AND estado='PAGADA'",[$motivo,$ventaId]);
            }
            $this->query("INSERT INTO pos_reversiones(operacion_clave,venta_id,tipo,estado,detalle_id,producto_id,cantidad_presentaciones,piezas,importe,medio_reembolso,referencia_reembolso,motivo,usuario_id,turno_reembolso_id) VALUES (?, ?, ?, 'APLICADA', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$clave,$ventaId,$tipo,$detId,$producto,$cantidad,$udsDevolucion,self::moneda($totalCent),$medio,$referencia?:null,$motivo,$uid,isset($turno)?(int)$turno:null]);
            $reversionNuevaId=(int)$this->db->lastInsertId();
            GydRewardsLineasV47::aplicarReversion($this->db,$reversionNuevaId,$ventaId,$tipo,$detId,$uid);
            $this->audit($uid,$ventaId,$tipo,['motivo'=>$motivo,'importe'=>self::moneda($totalCent),'piezas'=>$movimientos,'medio_reembolso'=>$medio,'referencia'=>$referencia,'tickets_cancelados'=>array_column($ticketsParaCancelar,'id')]);
            $this->db->commit();
            return ($tipo==='CANCELACION_TOTAL'?'Venta anulada':'Devolución parcial registrada').'. Existencias y auditoría actualizadas. Reembolso declarado como realizado por el responsable.';
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        finally {foreach(array_reverse($locks) as $l)pi_liberar_pos($this->db,$l);}
    }
    private function incidencia(int $uid,int $ventaId,string $clave,string $motivo):string {
        $this->db->beginTransaction();
        try{
            $venta=$this->query('SELECT id FROM pos_ventas WHERE id=? FOR UPDATE',[$ventaId])->fetchColumn();
            if(!$venta)throw new DomainException('No se encontró la venta.');
            $this->query("INSERT INTO pos_reversiones(operacion_clave,venta_id,tipo,estado,motivo,usuario_id) VALUES (?,?,'INCIDENCIA','PENDIENTE_REVISION',?,?)",[$clave,$ventaId,$motivo,$uid]);
            $this->audit($uid,$ventaId,'INCIDENCIA_DEVOLUCION',['motivo'=>$motivo]);
            $this->db->commit();return 'Incidencia registrada para revisión; no se modificó inventario ni caja.';
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
