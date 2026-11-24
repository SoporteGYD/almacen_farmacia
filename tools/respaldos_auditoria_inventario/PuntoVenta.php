<?php
declare(strict_types=1);
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../helpers/pos_inteligente.php';
require_once __DIR__.'/../helpers/gyd_pos_saldo_dividido.php';
require_once __DIR__.'/../helpers/gyd_clientes_rewards.php';
require_once __DIR__.'/../helpers/gyd_cortes_autorizados.php';
require_once __DIR__.'/../helpers/gyd_acceso_previo.php';
require_once __DIR__.'/../helpers/gyd_retiros_directos.php';
require_once __DIR__.'/../helpers/pos_corte_denominaciones.php';
require_once __DIR__.'/../helpers/gyd_habilitar_caja.php';

/** POS en base de PRUEBAS. Nunca cambia producto_existencias ni movimientos antiguos. */
final class PuntoVenta {
    private PDO $db;
    public function __construct() { $this->db=(new Database())->connect(); }
    public static function cents($value): int {
        $s=trim((string)$value);
        if (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/',$s)) throw new DomainException('Importe inválido: utiliza pesos y hasta 2 decimales.');
        [$whole,$dec]=array_pad(explode('.',$s,2),2,'');
        return (int)$whole*100+(int)str_pad($dec,2,'0');
    }
    private static function pesos(int $n):string {return number_format($n/100,2,'.','');}
    private function q(string $sql,array $p=[]):PDOStatement { $s=$this->db->prepare($sql);$s->execute($p);return $s; }
    public function sucursales(array $u):array {
        $result=$this->q('SELECT s.id,s.nombre,s.ticket_sin_importes,c.id caja_id,c.nombre caja_nombre,c.codigo codigo_caja FROM pos_sucursales s JOIN pos_cajas c ON c.sucursal_id=s.id WHERE s.activo=1 AND c.activa=1 ORDER BY s.id')->fetchAll();
        if (in_array(strtoupper((string)($u['rol_original']??$u['rol']??'')),['ADMINISTRADOR','ADMIN','DIRECCION'],true)) return $result;
        // La asignación POS no depende del almacén de bodega.
        if (($u['rol']??'')!=='CAJERO') return [];
        $asig=$this->q('SELECT sucursal_id,caja_id FROM pos_usuario_asignaciones WHERE usuario_id=? LIMIT 1',[(int)($u['id']??0)])->fetch(PDO::FETCH_ASSOC);
        if (!$asig) return [];
        return array_values(array_filter($result,static fn($r)=>(int)$r['id']===(int)$asig['sucursal_id'] && (int)$r['caja_id']===(int)$asig['caja_id']));
    }
    public function cajaAutorizada(array $u,int $caja):array {
        foreach ($this->sucursales($u) as $r) if ((int)$r['caja_id']===$caja) return $r;
        throw new DomainException('Caja no autorizada para esta cuenta. Asigna la sucursal correcta.');
    }
    public function productos(string $buscar,int $sucursal):array {
        $buscar=trim($buscar);
        if (strlen($buscar)<2) return [];
        $like='%'.$buscar.'%';
        $stockSid=pi_tt_stock_sid($this->db,$sucursal);
        return $this->enriquecerFisico($this->q("SELECT p.id,p.codigo,p.codigo_barras,p.descripcion, pp.precio_publico,COALESCE(st.existencia,0) existencia,pm.cantidad_minima,pm.precio_mayoreo,pr.modalidad_presentacion,pr.piezas_por_caja,pr.precio_caja
            ,(SELECT COALESCE(SUM(e2.existencia),0) FROM pos_existencias_farmacia e2 JOIN pos_sucursales s2 ON s2.id=e2.sucursal_id WHERE e2.producto_id=p.id AND s2.nombre='TAPACHULA') AS stock_tapachula
            ,(SELECT COALESCE(SUM(e2.existencia),0) FROM pos_existencias_farmacia e2 JOIN pos_sucursales s2 ON s2.id=e2.sucursal_id WHERE e2.producto_id=p.id AND s2.nombre='PLAZA TOSCANA') AS stock_toscana
            ,(SELECT COALESCE(SUM(e2.existencia),0) FROM pos_existencias_farmacia e2 JOIN pos_sucursales s2 ON s2.id=e2.sucursal_id WHERE e2.producto_id=p.id AND s2.nombre='TUXTLA GUTIERREZ') AS stock_tuxtla
            ,(SELECT COALESCE(SUM(e2.existencia),0) FROM pos_existencias_farmacia e2 JOIN pos_sucursales s2 ON s2.id=e2.sucursal_id WHERE e2.producto_id=p.id AND s2.nombre='CIUDAD HIDALGO') AS stock_hidalgo FROM productos p LEFT JOIN pos_precios pp ON pp.producto_id=p.id AND pp.sucursal_id=? LEFT JOIN pos_existencias_farmacia st ON st.producto_id=p.id AND st.sucursal_id=? LEFT JOIN pos_precios_mayoreo pm ON pm.producto_id=p.id AND pm.sucursal_id=? LEFT JOIN pos_presentaciones pr ON pr.producto_id=p.id AND pr.sucursal_id=? WHERE p.estado=1 AND (p.codigo LIKE ? OR p.codigo_barras LIKE ? OR p.descripcion LIKE ?) ORDER BY (p.codigo=? OR p.codigo_barras=?) DESC,p.descripcion LIMIT 25",[$sucursal,$stockSid,$sucursal,$sucursal,$like,$like,$like,$buscar,$buscar])->fetchAll(),$sucursal);
    }
    /** Paginated live catalog: priced items by default; all active items on request. */
    public function catalogo(string $buscar,int $sucursal,int $pagina=1,bool $soloPrecio=true):array {
        $buscar=trim($buscar); $pagina=max(1,min(10000,$pagina)); $limit=30;
        if(mb_strlen($buscar)>120) throw new DomainException('Búsqueda demasiado larga.');
        $where=' WHERE p.estado=1'; $params=[];
        if($soloPrecio){$where.=' AND pp.precio_publico>0';}
        if($buscar!==''){
            $where.=' AND (p.codigo LIKE ? OR p.codigo_barras LIKE ? OR p.descripcion LIKE ?)';
            $like='%'.$buscar.'%';$params=[$like,$like,$like];
        }
        $stockSid=pi_tt_stock_sid($this->db,$sucursal);
        $join=' FROM productos p LEFT JOIN pos_precios pp ON pp.producto_id=p.id AND pp.sucursal_id='.(int)$sucursal;
        $total=(int)$this->q('SELECT COUNT(*)'.$join.$where,$params)->fetchColumn();
        $paginas=max(1,(int)ceil($total/$limit));$pagina=min($pagina,$paginas);
        $sql='SELECT p.id,p.codigo,p.codigo_barras,p.descripcion,pp.precio_publico,COALESCE(st.existencia,0) existencia,pm.cantidad_minima,pm.precio_mayoreo,pr.modalidad_presentacion,pr.piezas_por_caja,pr.precio_caja'."\n            ,(SELECT COALESCE(SUM(e2.existencia),0) FROM pos_existencias_farmacia e2 JOIN pos_sucursales s2 ON s2.id=e2.sucursal_id WHERE e2.producto_id=p.id AND s2.nombre='TAPACHULA') AS stock_tapachula\n            ,(SELECT COALESCE(SUM(e2.existencia),0) FROM pos_existencias_farmacia e2 JOIN pos_sucursales s2 ON s2.id=e2.sucursal_id WHERE e2.producto_id=p.id AND s2.nombre='PLAZA TOSCANA') AS stock_toscana\n            ,(SELECT COALESCE(SUM(e2.existencia),0) FROM pos_existencias_farmacia e2 JOIN pos_sucursales s2 ON s2.id=e2.sucursal_id WHERE e2.producto_id=p.id AND s2.nombre='TUXTLA GUTIERREZ') AS stock_tuxtla\n            ,(SELECT COALESCE(SUM(e2.existencia),0) FROM pos_existencias_farmacia e2 JOIN pos_sucursales s2 ON s2.id=e2.sucursal_id WHERE e2.producto_id=p.id AND s2.nombre='CIUDAD HIDALGO') AS stock_hidalgo"
            .$join.' LEFT JOIN pos_existencias_farmacia st ON st.producto_id=p.id AND st.sucursal_id='.(int)$stockSid
            .' LEFT JOIN pos_precios_mayoreo pm ON pm.producto_id=p.id AND pm.sucursal_id='.(int)$sucursal
            .' LEFT JOIN pos_presentaciones pr ON pr.producto_id=p.id AND pr.sucursal_id='.(int)$sucursal
            .$where.' ORDER BY '.($buscar!==''?'(p.codigo = ? OR p.codigo_barras = ?) DESC, (p.codigo LIKE ? OR p.codigo_barras LIKE ?) DESC,':'').'p.descripcion ASC LIMIT '.(int)$limit.' OFFSET '.(int)(($pagina-1)*$limit);
        if($buscar!=='') {$params[]=$buscar;$params[]=$buscar;$params[]=$buscar.'%';$params[]=$buscar.'%';}
        return ['productos'=>$this->enriquecerFisico($this->q($sql,$params)->fetchAll(),$sucursal),'total'=>$total,'pagina'=>$pagina,'paginas'=>$paginas,'por_pagina'=>$limit];
    }
    private function enriquecerFisico(array $productos,int $sucursal):array {
       foreach($productos as &$p){
         $s=pi_ubicacion($this->db,$sucursal,(int)$p['id']);
         $p['stock_farmacia']=$s['farmacia'];$p['stock_almacen']=$s['almacen'];
         $p['almacen_disponible']=$s['almacen_disponible'];$p['minimo_farmacia']=$s['minimo'];
         $p['conteo_configurado']=$s['configurada'];$p['tiene_almacen']=$s['tiene_almacen'];
         $p['inventario_compartido']=false;
         $p['inventario_pool']=null;
          // Informativo F1. NO sustituye `existencia` ni el descuento de `cobrar()`.
          $p['stock_compartido_referencial']=null;
       }unset($p);return $productos;
    }
    /** Volume price policy per product and branch; null values disable the tier. */
    public function configurarMayoreo(int $sucursal,int $producto,?string $precio,?int $minima,int $user,string $motivo):array {
        if($sucursal<1||$producto<1||mb_strlen(trim($motivo))<4)throw new DomainException('Producto o motivo inválido.');
        $this->db->beginTransaction();
        try {
            $base=$this->q('SELECT precio_publico FROM pos_precios WHERE sucursal_id=? AND producto_id=? FOR UPDATE',[$sucursal,$producto])->fetchColumn();
            if($base===false||self::cents($base)<=0)throw new DomainException('Configura primero el precio público del producto.');
            if($precio===null||$precio===''){
                $this->q('DELETE FROM pos_precios_mayoreo WHERE sucursal_id=? AND producto_id=?',[$sucursal,$producto]);
                $det=['producto_id'=>$producto,'accion'=>'DESACTIVAR_PRECIO_MAYOREO','motivo'=>$motivo];
            }else{
                $n=self::cents($precio);
                if($n<=0||$n>self::cents($base)||$minima===null||$minima<2||$minima>100000)throw new DomainException('Precio mayoreo inválido: debe ser positivo, no exceder el público y exigir 2 o más piezas.');
                $this->q('INSERT INTO pos_precios_mayoreo(sucursal_id,producto_id,cantidad_minima,precio_mayoreo,actualizado_por) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE cantidad_minima=VALUES(cantidad_minima),precio_mayoreo=VALUES(precio_mayoreo),actualizado_por=VALUES(actualizado_por),actualizado_en=NOW()',[$sucursal,$producto,$minima,self::pesos($n),$user]);
                $det=['producto_id'=>$producto,'cantidad_minima'=>$minima,'precio_mayoreo'=>self::pesos($n),'motivo'=>$motivo];
            }
            $this->audit($user,null,'CONFIGURAR_MAYOREO',$det);$this->db->commit();return ['ok'=>true];
        }catch(Throwable $e){$this->db->rollBack();throw $e;}
    }
    public function configurar(int $sucursal,int $producto,string $precio,int $stock,int $user,string $motivo):array {
        if ($producto<=0||$sucursal<=0||$stock<0||$stock>9999999) throw new DomainException('Producto, sucursal o existencia inválida.');
        $cent=self::cents($precio);if($cent<=0)throw new DomainException('El precio debe ser mayor que cero.');
        if (trim($motivo)==='') throw new DomainException('Escribe un motivo del ajuste.');
        $stockSid=pi_tt_stock_sid($this->db,$sucursal);
        $this->db->beginTransaction();
        try {
            $r=$this->q('SELECT id FROM productos WHERE id=? AND estado=1',[$producto])->fetch();if(!$r)throw new DomainException('No existe el producto.');
            $old=$this->q('SELECT precio_publico FROM pos_precios WHERE sucursal_id=? AND producto_id=? FOR UPDATE',[$sucursal,$producto])->fetchColumn();
            $this->q('INSERT INTO pos_precios(sucursal_id,producto_id,precio_publico,actualizado_por) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE precio_publico=VALUES(precio_publico),actualizado_por=VALUES(actualizado_por),vigente_desde=NOW()',[$sucursal,$producto,self::pesos($cent),$user]);
            $this->q('INSERT INTO pos_precios_historial(sucursal_id,producto_id,precio_anterior,precio_nuevo,usuario_id,motivo) VALUES(?,?,?,?,?,?)',[$sucursal,$producto,$old===false?null:$old,self::pesos($cent),$user,mb_substr($motivo,0,250)]);
            $this->q('INSERT INTO pos_existencias_farmacia(sucursal_id,producto_id,existencia) VALUES(?,?,?) ON DUPLICATE KEY UPDATE existencia=VALUES(existencia)',[$stockSid,$producto,$stock]);
            $this->audit($user,null,'CONFIGURAR_PRODUCTO',['producto_id'=>$producto,'sucursal_id'=>$sucursal,'precio'=>self::pesos($cent),'existencia'=>$stock,'motivo'=>$motivo]);
            $this->db->commit();return ['ok'=>true];
        } catch(Throwable $e){$this->db->rollBack();throw $e;}
    }
    /** Configuración POS por farmacia. El inventario se expresa siempre en piezas. */
    public function configurarPresentacion(int $sucursal,int $producto,string $modalidad,int $piezas,string $precioCaja,int $usuario):array {
        $modalidad=strtoupper(trim($modalidad));
        if(!in_array($modalidad,['PIEZA','CAJA','AMBAS'],true)) throw new DomainException('Presentación inválida.');
        if($piezas<1||$piezas>100000)throw new DomainException('La caja debe contener entre 1 y 100000 piezas.');
        // La caja no tiene precio independiente; se calcula desde el precio de cada pieza.
        $this->db->beginTransaction();
        try{
            if(!$this->q('SELECT id FROM productos WHERE id=? AND estado=1',[$producto])->fetchColumn())throw new DomainException('Producto inexistente.');
            $this->q('INSERT INTO pos_presentaciones(sucursal_id,producto_id,modalidad_presentacion,piezas_por_caja,precio_caja,actualizado_por) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE modalidad_presentacion=VALUES(modalidad_presentacion),piezas_por_caja=VALUES(piezas_por_caja),precio_caja=VALUES(precio_caja),actualizado_por=VALUES(actualizado_por)',[$sucursal,$producto,$modalidad,$piezas,null,$usuario]);
            $this->audit($usuario,null,'CONFIGURAR_PRESENTACION',['producto_id'=>$producto,'sucursal_id'=>$sucursal,'modalidad'=>$modalidad,'piezas_por_caja'=>$piezas,'precio_caja'=>null]);
            $this->db->commit();return ['ok'=>true];
        }catch(Throwable $e){$this->db->rollBack();throw $e;}
    }
    private function audit(int $u,?int $venta,string $a,array $detail=[]):void {$this->q('INSERT INTO pos_auditoria(usuario_id,venta_id,accion,detalle) VALUES(?,?,?,?)',[$u,$venta,$a,json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);}
    public function turno(int $caja):?array {
        $r=$this->q("SELECT t.*,u.nombre cajero FROM pos_turnos t JOIN usuarios u ON u.id=t.usuario_apertura_id WHERE t.caja_id=? AND t.estado='ABIERTO' ORDER BY t.id DESC LIMIT 1",[$caja])->fetch();return $r?:null;
    }
    /** Estado de efectivo para controles visuales. La venta se vuelve a validar en el servidor. */
    public function efectivoTurno(array $u,int $caja):array {
        $this->cajaAutorizada($u,$caja);
        $turno=$this->turno($caja);
        if(!$turno)return ['esperadoCentavos'=>0,'limiteCentavos'=>300000,'bloqueado'=>false];
        $res=gd_cash_totals($this->db,(int)$turno['id']);
        return ['esperadoCentavos'=>$res['esperado'],'limiteCentavos'=>300000,'bloqueado'=>$res['esperado']>300000,'turnoId'=>(int)$turno['id']];
    }
    public function abrirTurno(array $u,int $caja,string $fondo,string $numeroCaja,string $claveLogin):array {
        $this->cajaAutorizada($u,$caja);$c=self::cents($fondo);
        $numeroValidado=gyd_habilitar_caja_autorizar($this->db,$u,$caja,$numeroCaja,$claveLogin);
        if($c>300000)throw new DomainException('El fondo de apertura no puede exceder $3,000.00.');
        $this->db->beginTransaction();
        try {
            $this->q('SELECT id FROM pos_cajas WHERE id=? FOR UPDATE',[$caja]);
            if($this->turno($caja))throw new DomainException('Ya existe un turno abierto en esta caja.');
            $this->q('INSERT INTO pos_turnos(caja_id,usuario_apertura_id,fondo_inicial) VALUES(?,?,?)',[$caja,$u['id'],self::pesos($c)]);
            $id=(int)$this->db->lastInsertId();$this->audit((int)$u['id'],null,'APERTURA_CAJA',['turno'=>$id,'numero_caja'=>$numeroValidado,'fondo'=>self::pesos($c)]);$this->db->commit();return ['turno_id'=>$id];
        }catch(Throwable $e){$this->db->rollBack();throw $e;}
    }
    public function cerrarTurno(array $u,int $caja,string $contado,array $denominaciones,string $supervisorLogin="",string $supervisorPassword=""):array {
        $rolSolicitante=gyd_corte_rol($u['rol_original']??$u['rol']??'');
        if ($rolSolicitante==='CAJERO') {
            $this->cajaAutorizada($u,$caja); // Solamente su caja POS asignada.
        } else {
            gyd_corte_exigir($u);gyd_corte_caja_permitida($this->db,$u,$caja);
        }
        $n=self::cents($contado);
        // Las credenciales se verifican en el modal previo, nunca en el conteo.
        // Este método vuelve a comprobar el permiso contra la caja/turno real.
        $corte=PosCorteDenominaciones::calcular($denominaciones);
        if($corte['total_centavos']!==$n)throw new DomainException('El conteo por denominaciones no coincide con el total de efectivo.');
        $this->db->beginTransaction();
        try {
            $turno=$this->q("SELECT * FROM pos_turnos WHERE caja_id=? AND estado='ABIERTO' ORDER BY id DESC LIMIT 1 FOR UPDATE",[$caja])->fetch();
            if(!$turno)throw new DomainException('No hay turno abierto.');
            $autorizacion=gyd_gate_validar('corte',$u,$caja,(int)$turno['id']);
            // La sesión operadora NO concede permiso de cierre: siempre debe existir
            // una firma válida de un usuario privilegiado distinto si opera CAJERO.
            $idOperador=(int)($u['id']??0);
            $idAutorizador=(int)$autorizacion['id'];
            if ($idOperador<1 || $idAutorizador<1 ||
                ($rolSolicitante==='CAJERO' && $idAutorizador===$idOperador) ||
                ($rolSolicitante!=='CAJERO' && $idAutorizador!==$idOperador)) {
                throw new DomainException('La firma del corte no corresponde al operador autorizado.');
            }
            $cuentaOperador=$this->q('SELECT rol,estado FROM usuarios WHERE id=? FOR UPDATE',[$idOperador])->fetch(PDO::FETCH_ASSOC);
            if (!$cuentaOperador || (int)$cuentaOperador['estado']!==1 || gyd_corte_rol($cuentaOperador['rol'])!==$rolSolicitante)
                throw new DomainException('La cuenta que solicita el corte ya no tiene autorización para esta operación.');
            $cuentaAutorizador=$this->q('SELECT rol,estado FROM usuarios WHERE id=? FOR UPDATE',[$idAutorizador])->fetch(PDO::FETCH_ASSOC);
            if (!$cuentaAutorizador || (int)$cuentaAutorizador['estado']!==1 ||
                !gyd_corte_permitido($cuentaAutorizador['rol']) ||
                gyd_corte_rol($cuentaAutorizador['rol'])!==gyd_corte_rol($autorizacion['rol']))
                throw new DomainException('La autorización ha perdido validez. Solicita nuevamente la clave del responsable.');
            $r=$this->q("SELECT COALESCE(SUM(CASE WHEN pg.metodo='EFECTIVO' AND (v.estado='PAGADA' OR (v.estado='CANCELADA' AND EXISTS (SELECT 1 FROM pos_reversiones grc WHERE grc.venta_id=v.id AND grc.tipo='CANCELACION_TOTAL' AND grc.estado='APLICADA' AND grc.turno_reembolso_id=v.turno_id))) THEN pg.monto ELSE 0 END),0) efectivo FROM pos_pagos pg JOIN pos_ventas v ON v.id=pg.venta_id WHERE v.turno_id=? AND pg.estado='CONFIRMADO'",[$turno['id']])->fetch();
            $otros=$this->q("SELECT COALESCE(SUM(CASE WHEN tipo='INGRESO' THEN monto WHEN tipo='RETIRO' THEN -monto ELSE 0 END),0) AS otros FROM pos_movimientos_caja WHERE turno_id=?",[$turno['id']])->fetch();
            $esperado=self::cents($turno['fondo_inicial'])+self::cents($r['efectivo'])+(int)round(((float)$otros['otros'])*100);$dif=$n-$esperado;$resultadoCorte=gyd_corte_resultado($dif);
            $this->q("UPDATE pos_turnos SET estado='CERRADO',cerrado_en=NOW(),usuario_cierre_id=?,efectivo_contado=?,diferencia_efectivo=? WHERE id=?",[$autorizacion['id'],self::pesos($n),self::pesos($dif),$turno['id']]);
            $this->q('INSERT INTO pos_cortes_denominaciones(turno_id,usuario_id,desglose_json,total_contado) VALUES (?,?,?,?)',[$turno['id'],(int)$u['id'],json_encode($corte['detalle'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),self::pesos($n)]);
            $this->q('INSERT INTO gyd_cortes_autorizaciones(turno_id,cajero_id,autorizador_id,autorizador_rol,modalidad,resultado,importe_diferencia) VALUES (?,?,?,?,?,?,?)',[(int)$turno['id'],(int)$turno['usuario_apertura_id'],(int)$autorizacion['id'],$autorizacion['rol'],$autorizacion['modo'],$resultadoCorte['tipo'],$resultadoCorte['importe']]);
            $this->audit((int)$u['id'],null,'CORTE_CAJA_AUTORIZADO',['turno'=>(int)$turno['id'],'cajero_apertura_id'=>(int)$turno['usuario_apertura_id'],'usuario_solicitante_id'=>(int)$u['id'],'usuario_cierre_id'=>(int)$autorizacion['id'],'autoriza'=>(int)$autorizacion['id'],'rol'=>(string)$autorizacion['rol'],'resultado'=>$resultadoCorte['tipo'],'importe'=>$resultadoCorte['importe']]);
            $this->audit((int)$u['id'],null,'CIERRE_CAJA',['turno'=>$turno['id'],'cajero_apertura_id'=>(int)$turno['usuario_apertura_id'],'solicitante_id'=>(int)$u['id'],'autorizador_id'=>(int)$autorizacion['id'],'esperado'=>self::pesos($esperado),'contado'=>self::pesos($n),'diferencia'=>self::pesos($dif)]);$this->db->commit();gyd_gate_limpiar('corte');return ['esperado'=>self::pesos($esperado),'diferencia'=>self::pesos($dif),'turno_id'=>(int)$turno['id'],'resultado'=>$resultadoCorte['tipo'],'importe_diferencia'=>$resultadoCorte['importe'],'caja_cerrada'=>true];
        }catch(Throwable $e){$this->db->rollBack();throw $e;}
    }
    public function cobrar(array $u,int $caja,array $items,array $pagos,string $key,string $quienLleva='',string $clienteNombre=''):array {
        $info=$this->cajaAutorizada($u,$caja);$sucursal=(int)$info['id'];
        $quienLleva=trim(preg_replace('/\s+/u',' ',$quienLleva)??'');
        $clienteNombre=trim(preg_replace('/\s+/u',' ',$clienteNombre)??'');
        if(mb_strlen($clienteNombre,'UTF-8')>120 || preg_match('/[\x00-\x1F\x7F]/',$clienteNombre)) throw new DomainException('Nombre del cliente inválido.');
        if(mb_strlen($quienLleva,'UTF-8')>120 || preg_match('/[\x00-\x1F\x7F]/',$quienLleva))
            throw new DomainException('El nombre de quien lleva debe tener como máximo 120 caracteres y no incluir controles.');
        if((int)($info['ticket_sin_importes']??0)===1 && mb_strlen($quienLleva,'UTF-8')<2)
            throw new DomainException('En Ciudad Hidalgo debes indicar quién lleva la mercancía.');
        if(!preg_match('/^[a-f0-9-]{36}$/i',$key))throw new DomainException('Identificador de venta inválido.');
        if(!$items||count($items)>80)throw new DomainException('Agrega productos (máximo 80 renglones).');
        $locksPOS=[];
        $stockSid=pi_tt_stock_sid($this->db,$sucursal);
        $this->db->beginTransaction();
        try {
            $turno=$this->q("SELECT * FROM pos_turnos WHERE caja_id=? AND estado='ABIERTO' ORDER BY id DESC LIMIT 1 FOR UPDATE",[$caja])->fetch();
            // Consultar luego del bloqueo serializa ventas repetidas en la misma caja.
            $dup=$this->q('SELECT id,folio,usuario_id FROM pos_ventas WHERE idempotency_key=? FOR UPDATE',[$key])->fetch();
            if($dup){if((int)$dup['usuario_id']!==(int)$u['id'])throw new DomainException('Operación no autorizada.');$this->db->commit();return ['venta_id'=>$dup['id'],'folio'=>$dup['folio'],'repetida'=>true];}
            // La selección se firmó en la misma sesión con CSRF, caja y clave idempotente.
            $gdClienteId=GydClientesRewards::clienteSesion($key,$caja);
            if($gdClienteId!==null){
                $fichaCliente=GydClientesRewards::validar($this->db,$gdClienteId,true);
                $clienteNombre=(string)$fichaCliente['nombre'];
            }
            if(!$turno)throw new DomainException('Debes abrir caja antes de cobrar.');
            if(($u['rol']??'')!=='ADMINISTRADOR'&&(int)$turno['usuario_apertura_id']!==(int)$u['id'])throw new DomainException('Este turno pertenece a otro cajero.');
            // Límite estricto: puede terminar la venta que rebase $3,000, pero NO iniciar otra.
            // El bloqueo ocurre en la misma transacción que serializa cobros y retiros por turno.
            $saldoAntes=gd_cash_totals($this->db,(int)$turno['id']);
            if($saldoAntes['esperado']>300000)
                throw new DomainException('RETIRO OBLIGATORIO: la caja tiene $'.number_format($saldoAntes['esperado']/100,2,'.',',').'. El límite es $3,000.00. Registra e imprime el retiro de efectivo antes de otra venta.');
            // Cada renglón distingue producto + presentación; se descuenta siempre en piezas.
            $renglones=[];
            foreach($items as $item){
                $id=(int)($item['id']??0);$qty=(int)($item['cantidad']??0);
                $presentacion=strtoupper(trim((string)($item['presentacion']??'PIEZA')));
                if($id<1||$qty<1||$qty>100000||!in_array($presentacion,['PIEZA','CAJA'],true))throw new DomainException('Cantidad o presentación inválida.');
                $clave=$id.':'.$presentacion;
                if(isset($renglones[$clave]))throw new DomainException('Presentación duplicada en la venta.');
                $especial=null;
                if(isset($item['precio_especial'])&&$item['precio_especial']!==null&&$item['precio_especial']!==''){
                    if(($u['rol']??'')!=='ADMINISTRADOR')throw new DomainException('Precio especial exclusivo del administrador.');
                    $motivo=trim((string)($item['motivo']??''));
                    if(mb_strlen($motivo)<5||mb_strlen($motivo)>250)throw new DomainException('Motivo del precio especial inválido.');
                    $especial=['precio'=>self::cents($item['precio_especial']),'motivo'=>$motivo];
                }
                $renglones[$clave]=['id'=>$id,'cantidad'=>$qty,'presentacion'=>$presentacion,'especial'=>$especial];
            }
            if(count($renglones)>70)throw new DomainException('Demasiados renglones.');
            ksort($renglones);
            // Todos los productos se bloquean en orden numérico para evitar intercalaciones
            // entre cobros simultáneos y movimientos de inventario.
            $idsCandado=array_values(array_unique(array_map(static fn($it)=>(int)$it['id'],array_values($renglones))));
            sort($idsCandado,SORT_NUMERIC);
            foreach($idsCandado as $pidCandado) $locksPOS[]=pi_bloquear_pos($this->db,$sucursal,$pidCandado);
            $det=[];$total=0;$necesario=[];$stockIds=[];
            foreach($renglones as $it){
                $id=$it['id'];$qty=$it['cantidad'];$pres=$it['presentacion'];
                $p=$this->q('SELECT p.id,p.codigo,p.descripcion,p.estado,pp.precio_publico,st.existencia,st.id stock_id,pm.cantidad_minima,pm.precio_mayoreo,pr.modalidad_presentacion,pr.piezas_por_caja,pr.precio_caja FROM productos p LEFT JOIN pos_precios pp ON pp.producto_id=p.id AND pp.sucursal_id=? LEFT JOIN pos_existencias_farmacia st ON st.producto_id=p.id AND st.sucursal_id=? LEFT JOIN pos_precios_mayoreo pm ON pm.producto_id=p.id AND pm.sucursal_id=? LEFT JOIN pos_presentaciones pr ON pr.producto_id=p.id AND pr.sucursal_id=? WHERE p.id=? FOR UPDATE',[$sucursal,$stockSid,$sucursal,$sucursal,$id])->fetch();
                if(!$p||(int)$p['estado']!==1)throw new DomainException('Producto no disponible.');
                $mode=$p['modalidad_presentacion']??'PIEZA';
                if($mode!=='AMBAS'&&$mode!==$pres)throw new DomainException('Presentación no autorizada para '.$p['descripcion']);
                $factor=$pres==='CAJA'?(int)($p['piezas_por_caja']??0):1;
                if($factor<1||$qty>intdiv(100000000,$factor))throw new DomainException('Equivalencia de cajas inválida.');
                $unidades=$qty*$factor;
                $necesario[$id]=($necesario[$id]??0)+$unidades;
                if($necesario[$id]>(int)($p['existencia']??0))throw new DomainException('Existencia POS insuficiente para '.$p['descripcion'].'; comprueba cajas y piezas.');
                $stockIds[$id]=(int)$p['stock_id'];
                $base=$p['precio_publico']===null?0:self::cents($p['precio_publico']);
                if($base<=0)throw new DomainException('Falta precio por pieza para '.$p['descripcion']);
                if($pres==='CAJA'){
                    // Precio por caja = precio por pieza x piezas por caja (en centavos).
                    $price=$base*$factor;$unitBase=$price;
                }else{
                    $volumen=($p['precio_mayoreo']!==null&&(int)$p['cantidad_minima']>=2&&$qty>=(int)$p['cantidad_minima'])?self::cents($p['precio_mayoreo']):0;
                    $price=$volumen>0&&$volumen<=$base?$volumen:$base;$unitBase=$base;
                }
                if($it['especial']!==null){$price=$it['especial']['precio'];if($price<=0||$price>99999999999)throw new DomainException('Precio especial inválido.');}
                $line=$price*$qty;$total+=$line;if($total>99999999999)throw new DomainException('Total excedido.');
                $det[]=['id'=>$id,'codigo'=>$p['codigo'],'nombre'=>$p['descripcion'],'cantidad'=>$qty,'presentacion'=>$pres,'factor'=>$factor,'unidades'=>$unidades,'precio'=>$price,'base'=>$unitBase,'importe'=>$line,'descuento'=>max(0,($unitBase-$price)*$qty),'especial'=>$it['especial']];
            }

            // No se generan peticiones/tickets a Bodega desde POS.
            // En Hidalgo/Tuxtla el cajero vende exclusivamente unidades FÍSICAS
            // disponibles en Farmacia. Un traspaso físico desde Almacén se registra
            // fuera del POS y NO se simula al cobrar.
            $origenes=[];$ticketOrigen=[];
            foreach($necesario as $pid=>$uds){
                foreach($items as $raw){
                    if((int)($raw['id']??0)===(int)$pid && (int)($raw['almacen_ticket_id']??0)>0)
                        throw new DomainException('La venta directa no acepta tickets ni solicitudes antiguas de Almacén.');
                }
                if(!pi_almacen($sucursal)){$origenes[$pid]='FARMACIA_GENERAL';continue;}
                $split=gyd_pos_saldo_dividido($this->db,$sucursal,(int)$pid,true);
                if(!$split['conciliado'])
                    throw new DomainException('Inventario sin conciliar para producto #'.$pid.'. En Catálogo Maestro registra el conteo físico: General debe ser Farmacia + Almacén.');
                if($uds>(int)$split['farmacia'])
                    throw new DomainException('Farmacia no tiene piezas físicas suficientes para producto #'.$pid.'. Realiza primero un movimiento interno desde Almacén; el POS no envía solicitudes.');
                $origenes[$pid]='FARMACIA';
            }
            if(!$pagos||count($pagos)>3)throw new DomainException('Selecciona la forma de pago.');
            $sum=0;$rec=0;$seen=[];$norm=[];
            foreach($pagos as $pg){
                $met=strtoupper(trim((string)($pg['metodo']??'')));if(!in_array($met,['EFECTIVO','TARJETA','TRANSFERENCIA'],true)||isset($seen[$met]))throw new DomainException('Forma de pago inválida o duplicada.');$seen[$met]=true;
                $m=self::cents($pg['monto']??'');if($m<=0)throw new DomainException('Importe de pago inválido.');$sum+=$m;
                $ref=trim((string)($pg['referencia']??''));if($met!=='EFECTIVO' && (strlen($ref)<3||strlen($ref)>120||empty($pg['confirmado'])))throw new DomainException('Confirma y registra la referencia del pago electrónico.');
                if($met==='EFECTIVO') {$rec=self::cents($pg['recibido']??'');if($rec<$m)throw new DomainException('Efectivo recibido insuficiente.');}
                $tipoTarjeta=null;
                if($met==='TARJETA'){
                    $tipoTarjeta=strtoupper(trim((string)($pg['tipo_tarjeta']??'')));
                    if(!in_array($tipoTarjeta,['DEBITO','CREDITO'],true))throw new DomainException('Selecciona tarjeta de débito o crédito.');
                }
                $norm[]=['metodo'=>$met,'monto'=>$m,'referencia'=>$met==='EFECTIVO'?null:$ref,'tipo_tarjeta'=>$tipoTarjeta];
            }
            if($sum!==$total)throw new DomainException('Los pagos no coinciden con el total de la venta.');
            $cambio=$rec-(isset($seen['EFECTIVO'])?(int)($norm[array_search('EFECTIVO',array_column($norm,'metodo'),true)]['monto']):0);
            // Subtotal bruto y descuento por línea, incluso cuando un precio especial es superior al público.
            $bruto=array_sum(array_map(static fn(array $d)=>$d['base']*$d['cantidad'],$det));
            $descuento=array_sum(array_map(static fn(array $d)=>$d['descuento'],$det));
            if($bruto-$descuento!==$total) {$bruto=$total+$descuento;}
            $this->q("INSERT INTO pos_ventas(folio,sucursal_id,caja_id,turno_id,usuario_id,estado,subtotal,descuento,total,efectivo_recibido,cambio,quien_lleva,cliente_nombre,idempotency_key,creada_en,pagada_en) VALUES(?,?,?,?,?,'PAGADA',?,?,?,?,?,?,?,?,NOW(),NOW())",['TMP-'.$key,$sucursal,$caja,$turno['id'],$u['id'],self::pesos($bruto),self::pesos($descuento),self::pesos($total),self::pesos($rec),self::pesos($cambio),$quienLleva!==''?$quienLleva:null,$clienteNombre!==''?$clienteNombre:null,$key]);
            $venta=(int)$this->db->lastInsertId();$folio=sprintf('PV-%02d-%07d',$sucursal,$venta);
            $this->q('UPDATE pos_ventas SET folio=? WHERE id=?',[$folio,$venta]);
            foreach($det as $d){
                $this->q('INSERT INTO pos_venta_detalle(venta_id,producto_id,codigo_snapshot,descripcion_snapshot,cantidad,precio_unitario,descuento,total_renglon,presentacion,piezas_por_presentacion,unidades_descontadas) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$venta,$d['id'],$d['codigo'],$d['nombre'],$d['cantidad'],self::pesos($d['precio']),self::pesos($d['descuento']),self::pesos($d['importe']),$d['presentacion'],$d['factor'],$d['unidades']]);
                if($d['especial']!==null){$this->audit((int)$u['id'],$venta,'AUTORIZAR_PRECIO_ESPECIAL',['producto_id'=>$d['id'],'precio_publico'=>self::pesos($d['base']),'precio_especial'=>self::pesos($d['precio']),'cantidad'=>$d['cantidad'],'motivo'=>$d['especial']['motivo']]);}
                // Descuento conjunto por producto después de validar todos los renglones.
            }
            $alertasPOS=[];
            foreach($necesario as $pid=>$uds){
                $up=$this->q('UPDATE pos_existencias_farmacia SET existencia=existencia-? WHERE id=? AND existencia>=?',[$uds,$stockIds[$pid],$uds]);
                if($up->rowCount()!==1)throw new DomainException('Existencias insuficientes; venta cancelada.');
                if(($origenes[$pid]??'')==='FARMACIA'){
                    $res=$this->q('UPDATE erp_pos_farmacia_control SET existencia=existencia-? WHERE sucursal_id=? AND producto_id=? AND existencia>=?',[$uds,$sucursal,$pid,$uds]);
                    if($res->rowCount()!==1)throw new DomainException('Existencia física insuficiente en Farmacia. Venta cancelada sin descontar stock.');
                }

            }
            // Guardar el origen físico al cobrar. Solo las ventas posteriores a esta migración
            // pueden revertirse automáticamente sin adivinar su procedencia.
            foreach ($necesario as $pid=>$uds) {
                $origenDb=($origenes[$pid]??'')==='FARMACIA_GENERAL'?'FARMACIA_GENERAL':($origenes[$pid]??'');
                if(!in_array($origenDb,['FARMACIA','ALMACEN','FARMACIA_GENERAL'],true))
                    throw new DomainException('No se identificó el origen físico de la venta.');
                $this->q('INSERT INTO pos_venta_origenes (venta_id,producto_id,origen,piezas,almacen_ticket_id) VALUES (?,?,?,?,?)',
                  [$venta,$pid,$origenDb,$uds,$ticketOrigen[$pid]??null]);
            }
            foreach($norm as $p){$this->q("INSERT INTO pos_pagos(venta_id,metodo,monto,referencia,tipo_tarjeta,estado,confirmado_en) VALUES(?,?,?,?,?,'CONFIRMADO',NOW())",[$venta,$p['metodo'],self::pesos($p['monto']),$p['referencia'],$p['tipo_tarjeta']]);}
            // Clientes y puntos comparten la transacción con venta/pagos/inventario.
            // Sin cliente: el cobro V35 se mantiene intacto.
            $recompensa=$gdClienteId!==null?GydClientesRewards::acumular($this->db,$gdClienteId,$venta,$sucursal,$det,(int)$u['id']):null;
            $this->audit((int)$u['id'],$venta,'COBRO_VENTA',['folio'=>$folio,'total'=>self::pesos($total),'cambio'=>self::pesos($cambio),'quien_lleva'=>$quienLleva]);$this->db->commit();
            GydClientesRewards::limpiarSesion($key);
            foreach(array_reverse($locksPOS) as $claveCandado) pi_liberar_pos($this->db,$claveCandado);
            return ['venta_id'=>$venta,'folio'=>$folio,'cambio'=>self::pesos($cambio),'repetida'=>false,'alertas_farmacia'=>$alertasPOS,'recompensa'=>$recompensa];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();foreach(array_reverse($locksPOS) as $claveCandado) pi_liberar_pos($this->db,$claveCandado);throw $e;}
    }
    public function ticket(int $id,array $u):?array {
        $sale=$this->q('SELECT v.*,s.nombre sucursal,s.ticket_sin_importes,c.nombre caja_nombre,us.nombre cajero FROM pos_ventas v JOIN pos_sucursales s ON s.id=v.sucursal_id JOIN pos_cajas c ON c.id=v.caja_id JOIN usuarios us ON us.id=v.usuario_id WHERE v.id=?',[$id])->fetch();
        if(!$sale)return null;
        if(($u['rol']??'')!=='ADMINISTRADOR' && ((int)$sale['usuario_id']!==(int)$u['id'] || !in_array((int)$sale['caja_id'],array_map(static fn($r)=>(int)$r['caja_id'],$this->sucursales($u)),true))) throw new DomainException('No tienes permiso para consultar este ticket.');
        $sale['items']=$this->q('SELECT * FROM pos_venta_detalle WHERE venta_id=? ORDER BY id',[$id])->fetchAll();
        $sale['pagos']=$this->q('SELECT * FROM pos_pagos WHERE venta_id=? ORDER BY id',[$id])->fetchAll();return $sale;
    }
    public function historial(array $u,int $caja):array { $this->cajaAutorizada($u,$caja); return $this->q("SELECT v.id,v.folio,v.total,v.pagada_en,v.usuario_id,v.estado FROM pos_ventas v WHERE v.caja_id=? ORDER BY v.id DESC LIMIT 30",[$caja])->fetchAll(); }
}
