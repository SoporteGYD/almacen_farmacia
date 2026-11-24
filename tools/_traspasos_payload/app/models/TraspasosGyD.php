<?php
declare(strict_types=1);
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../helpers/pos_inteligente.php';

/** Transferencias entre sucursales con stock POS y físico, en transacciones InnoDB. */
final class TraspasosGyD {
    private PDO $db;
    public function __construct() {$this->db=(new Database())->connect();}
    public function db():PDO {return $this->db;}
    private function q(string $sql,array $args=[]):PDOStatement {$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private static function rol(array $u):string{return strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));}
    public static function global(array $u):bool{return in_array(self::rol($u),['ADMINISTRADOR','ADMIN','DIRECCION'],true);}
    public static function sucursalUsuario(array $u):int {return match((int)($u['almacen_id']??0)){1=>1,3=>2,default=>0};}
    private function acceso(array $u,int $sucursal):void {
        if(self::global($u))return;
        if(!in_array(self::rol($u),['GERENTE','ENCARGADO'],true)||self::sucursalUsuario($u)!==$sucursal)throw new DomainException('No tienes permisos para operar esta sucursal.');
    }
    public function sucursales():array {return $this->q("SELECT id,nombre FROM pos_sucursales WHERE activo=1 ORDER BY id")->fetchAll();}
    private static function tipoValido(int $sid,string $tipo):bool {return in_array($sid,[1,2],true)?in_array($tipo,['ALMACEN','FARMACIA'],true):($tipo==='UNICO' && in_array($sid,[3,4],true));}
    private function validarPar(int $origen,int $destino,string $oTipo,string $dTipo):void {
        if($origen===$destino||!self::tipoValido($origen,$oTipo)||!self::tipoValido($destino,$dTipo))throw new DomainException('Sucursales y ubicaciones de origen/destino inválidas. Los movimientos internos deben usar Resurtidos.');
        if(in_array($origen,[3,4],true)&&in_array($destino,[3,4],true))throw new DomainException('Tapachula y Toscana comparten inventario. El traslado entre ambas requiere configurar primero sus ubicaciones físicas; no se ajustará dos veces el mismo stock.');
        $rows=$this->q('SELECT id FROM pos_sucursales WHERE activo=1 AND id IN (?,?)',[$origen,$destino])->fetchAll();
        if(count($rows)!==2)throw new DomainException('Sucursal desactivada o inexistente.');
    }
    public function listado(array $u):array {
        $sql='SELECT t.*,so.nombre origen,sd.nombre destino,us.nombre solicitante FROM erp_traspasos t JOIN pos_sucursales so ON so.id=t.origen_id JOIN pos_sucursales sd ON sd.id=t.destino_id JOIN usuarios us ON us.id=t.solicitado_por';
        $p=[];if(!self::global($u)){$s=self::sucursalUsuario($u);if(!$s)throw new DomainException('El usuario no tiene sucursal operativa asignada.');$sql.=' WHERE (t.origen_id=? OR t.destino_id=?)';$p=[$s,$s];}
        return $this->q($sql.' ORDER BY t.id DESC LIMIT 120',$p)->fetchAll();
    }
    public function ver(array $u,int $id):array {
        $r=$this->q('SELECT t.*,so.nombre origen,sd.nombre destino FROM erp_traspasos t JOIN pos_sucursales so ON so.id=t.origen_id JOIN pos_sucursales sd ON sd.id=t.destino_id WHERE t.id=?',[$id])->fetch();
        if(!$r)throw new DomainException('Traspaso no encontrado.');
        if(!self::global($u)&&!in_array(self::sucursalUsuario($u),[(int)$r['origen_id'],(int)$r['destino_id']],true))throw new DomainException('No tienes acceso a este traspaso.');
        $r['items']=$this->q('SELECT d.*,p.codigo,p.descripcion,pe.ubicacion FROM erp_traspasos_detalle d JOIN productos p ON p.id=d.producto_id LEFT JOIN producto_existencias pe ON pe.id=d.ubicacion_origen_id WHERE d.traspaso_id=? ORDER BY p.descripcion',[$id])->fetchAll();
        $r['eventos']=$this->q('SELECT e.*,u.nombre autor FROM erp_traspasos_eventos e JOIN usuarios u ON u.id=e.usuario_id WHERE e.traspaso_id=? ORDER BY e.id DESC',[$id])->fetchAll();
        $r['recepciones']=$this->q('SELECT rec.id,rec.creado_en,rec.observaciones,u.nombre FROM erp_traspasos_recepciones rec JOIN usuarios u ON u.id=rec.usuario_id WHERE rec.traspaso_id=? ORDER BY rec.id DESC',[$id])->fetchAll();
        return $r;
    }
    private function evento(int $id,array $u,string $accion,string $detalle=''):void {$this->q('INSERT INTO erp_traspasos_eventos (traspaso_id,usuario_id,accion,detalle) VALUES (?,?,?,?)',[$id,(int)$u['id'],$accion,$detalle]);}
    public function crear(array $u,array $post):int {
        $ori=(int)($post['origen_id']??0);$des=(int)($post['destino_id']??0);
        $ot=strtoupper((string)($post['origen_tipo']??''));$dt=strtoupper((string)($post['destino_tipo']??''));
        $modalidad=(string)($post['modalidad']??'');
        if(!in_array($modalidad,['SOLICITUD','DIRECTO'],true))throw new DomainException('Modalidad inválida.');
        $this->validarPar($ori,$des,$ot,$dt);
        // Solicitud la inicia destino; envío directo, origen.
        $this->acceso($u,$modalidad==='SOLICITUD'?$des:$ori);
        $items=json_decode((string)($post['items_json']??''),true);
        if(!is_array($items)||count($items)<1||count($items)>60)throw new DomainException('Agrega de 1 a 60 productos.');
        $map=[];foreach($items as $i){
            if(!is_array($i))throw new DomainException('Partida inválida.');
            $codigo=trim((string)($i['codigo']??''));$cant=(int)($i['cantidad']??0);
            if($codigo===''||mb_strlen($codigo)>150||$cant<1||$cant>100000)throw new DomainException('Código o cantidad inválidos.');
            $p=$this->q('SELECT id FROM productos WHERE estado=1 AND (codigo=? OR codigo_barras=?) LIMIT 2',[$codigo,$codigo])->fetchAll();
            if(count($p)!==1)throw new DomainException('Producto no encontrado o código ambiguo: '.$codigo);
            $pid=(int)$p[0]['id'];if(isset($map[$pid]))throw new DomainException('Producto repetido: '.$codigo);
            $map[$pid]=$cant;
        }
        ksort($map,SORT_NUMERIC);$obs=trim((string)($post['observaciones']??''));if(mb_strlen($obs)>500)throw new DomainException('Observaciones demasiado largas.');
        $this->db->beginTransaction();try {
            $this->q('INSERT INTO erp_traspasos (folio,origen_id,destino_id,origen_tipo,destino_tipo,modalidad,estado,solicitado_por,observaciones) VALUES (?,?,?,?,?,?,?,?,?)', ['TMP-'.bin2hex(random_bytes(8)),$ori,$des,$ot,$dt,$modalidad,$modalidad==='SOLICITUD'?'SOLICITADO':'POR_PREPARAR',(int)$u['id'],$obs]);
            $id=(int)$this->db->lastInsertId();$folio='TR-'.str_pad((string)$id,8,'0',STR_PAD_LEFT);
            $this->q('UPDATE erp_traspasos SET folio=? WHERE id=?',[$folio,$id]);
            foreach($map as $pid=>$cant)$this->q('INSERT INTO erp_traspasos_detalle (traspaso_id,producto_id,solicitado) VALUES (?,?,?)',[$id,$pid,$cant]);
            $this->evento($id,$u,'CREAR_'.$modalidad);$this->db->commit();return $id;
        }catch(Throwable $e){$this->db->rollBack();throw $e;}
    }
    /** Ubicaciones físicas por producto para seleccionar antes del despacho. */
    public function ubicaciones(int $sid,int $pid):array {
        if(!in_array($sid,[1,2],true))return [];
        $n=$sid===1?["CIUDAD HIDALGO","CD HIDALGO"]:["TUXTLA","TUXTLA GUTIERREZ","TUXTLA GUTIÉRREZ"];
        $in=implode(',',array_fill(0,count($n),'?'));
        return $this->q("SELECT id,ubicacion,existencia FROM producto_existencias WHERE producto_id=? AND UPPER(TRIM(sucursal)) COLLATE utf8mb4_general_ci IN ($in) AND TRIM(COALESCE(ubicacion,''))<>'' AND UPPER(TRIM(ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION','SIN UBICACIÓN') ORDER BY ubicacion,id",array_merge([$pid],$n))->fetchAll();
    }
    private function cabLock(int $id):array {
        $t=$this->q('SELECT * FROM erp_traspasos WHERE id=? FOR UPDATE',[$id])->fetch();if(!$t)throw new DomainException('Traspaso inexistente.');return $t;
    }
    public function preparar(array $u,int $id,array $locs):void {
        $this->db->beginTransaction();try {
            $t=$this->cabLock($id);$this->acceso($u,(int)$t['origen_id']);
            if(!in_array($t['estado'],['SOLICITADO','POR_PREPARAR'],true))throw new DomainException('Este traspaso ya fue preparado o procesado.');
            $it=$this->q('SELECT * FROM erp_traspasos_detalle WHERE traspaso_id=? ORDER BY producto_id FOR UPDATE',[$id])->fetchAll();
            foreach($it as $r){
                $loc=null;
                if($t['origen_tipo']==='ALMACEN'){
                    $loc=(int)($locs[(int)$r['producto_id']]??0);$validas=array_column($this->ubicaciones((int)$t['origen_id'],(int)$r['producto_id']),'id');
                    if(!in_array($loc,array_map('intval',$validas),true))throw new DomainException('Selecciona una ubicación física válida para cada producto.');
                }
                $this->q('UPDATE erp_traspasos_detalle SET ubicacion_origen_id=? WHERE traspaso_id=? AND producto_id=?',[$loc,$id,(int)$r['producto_id']]);
            }
            $this->q("UPDATE erp_traspasos SET estado='PREPARADO',preparado_por=?,preparado_en=NOW() WHERE id=?",[(int)$u['id'],$id]);
            $this->evento($id,$u,'PREPARAR');$this->db->commit();
        }catch(Throwable $e){$this->db->rollBack();throw $e;}
    }
    private function bloqueo(int $sid,int $pid,array &$locks):void {$locks[]=pi_bloquear_pos($this->db,$sid,$pid);}
    private function release(array $locks):void {foreach(array_reverse($locks) as $k)pi_liberar_pos($this->db,$k);}
    private function bajarOrigen(array $t,array $d):void {
        $sid=(int)$t['origen_id'];$pid=(int)$d['producto_id'];$n=(int)$d['solicitado'];
        $st=$this->q('UPDATE pos_existencias_farmacia SET existencia=existencia-? WHERE sucursal_id=? AND producto_id=? AND existencia>=?',[$n,$sid,$pid,$n]);
        if($st->rowCount()!==1)throw new DomainException('POS origen insuficiente para producto #'.$pid.'.');
        if($t['origen_tipo']==='FARMACIA'){
            $st=$this->q('UPDATE erp_pos_farmacia_control SET existencia=existencia-? WHERE sucursal_id=? AND producto_id=? AND existencia>=?',[$n,$sid,$pid,$n]);
            if($st->rowCount()!==1)throw new DomainException('Conteo físico de Farmacia insuficiente, producto #'.$pid.'.');
            $r=$this->q('SELECT existencia,minimo FROM erp_pos_farmacia_control WHERE sucursal_id=? AND producto_id=?',[$sid,$pid])->fetch();if($r)pi_alerta($this->db,$sid,$pid,(int)$r['existencia'],(int)$r['minimo']);
        }elseif($t['origen_tipo']==='ALMACEN'){
            $estado=pi_ubicacion($this->db,$sid,$pid);
            if((int)$estado['almacen_disponible']<$n)throw new DomainException('El Almacén tiene reservas o stock insuficiente, producto #'.$pid.'.');
            $loc=(int)($d['ubicacion_origen_id']??0);$validas=array_map('intval',array_column($this->ubicaciones($sid,$pid),'id'));
            if(!in_array($loc,$validas,true))throw new DomainException('La ubicación física de origen ya no es válida.');
            $st=$this->q('UPDATE producto_existencias SET existencia=existencia-? WHERE id=? AND producto_id=? AND existencia>=?',[$n,$loc,$pid,$n]);
            if($st->rowCount()!==1)throw new DomainException('Existencia insuficiente en ubicación física de Almacén, producto #'.$pid.'.');
        }
    }
    public function despachar(array $u,int $id):void {
        $locks=[];
        try {
            // Evitar intercalaciones entre traspasos/POS del mismo producto; mismo orden de adquisición.
            $basic=$this->q('SELECT origen_id FROM erp_traspasos WHERE id=?',[$id])->fetch();if(!$basic)throw new DomainException('Traspaso inexistente.');
            $items=$this->q('SELECT producto_id FROM erp_traspasos_detalle WHERE traspaso_id=? ORDER BY producto_id',[$id])->fetchAll();
            foreach($items as $r)$this->bloqueo((int)$basic['origen_id'],(int)$r['producto_id'],$locks);
            $this->db->beginTransaction();
            $t=$this->cabLock($id);$this->acceso($u,(int)$t['origen_id']);
            if($t['estado']!=='PREPARADO')throw new DomainException('Solo puede despacharse un traspaso preparado.');
            $ds=$this->q('SELECT * FROM erp_traspasos_detalle WHERE traspaso_id=? ORDER BY producto_id FOR UPDATE',[$id])->fetchAll();
            foreach($ds as $d){$this->bajarOrigen($t,$d);$this->q('UPDATE erp_traspasos_detalle SET enviado=solicitado WHERE traspaso_id=? AND producto_id=?',[$id,(int)$d['producto_id']]);}
            $this->q("UPDATE erp_traspasos SET estado='EN_TRANSITO',despachado_por=?,despachado_en=NOW() WHERE id=?",[(int)$u['id'],$id]);
            $this->evento($id,$u,'DESPACHO','Descontado del origen; pendiente de recepción en destino.');
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}finally{$this->release($locks);}
    }
    private function acreditarDestino(array $t,int $pid,int $n):void {
        if($n===0)return;$sid=(int)$t['destino_id'];
        if($t['destino_tipo']==='UNICO'){
            $this->q('INSERT INTO pos_existencias_farmacia (sucursal_id,producto_id,existencia) VALUES (?,?,?) ON DUPLICATE KEY UPDATE existencia=existencia+VALUES(existencia)',[$sid,$pid,$n]);
        }elseif($t['destino_tipo']==='FARMACIA'){
            $r=$this->q('SELECT existencia FROM erp_pos_farmacia_control WHERE sucursal_id=? AND producto_id=? FOR UPDATE',[$sid,$pid])->fetch();
            if(!$r)throw new DomainException('Configura el conteo físico inicial de Farmacia en destino antes de recibir producto #'.$pid.'.');
            $this->q('UPDATE erp_pos_farmacia_control SET existencia=existencia+? WHERE sucursal_id=? AND producto_id=?',[$n,$sid,$pid]);
            $this->q('INSERT INTO pos_existencias_farmacia (sucursal_id,producto_id,existencia) VALUES (?,?,?) ON DUPLICATE KEY UPDATE existencia=existencia+VALUES(existencia)',[$sid,$pid,$n]);
            $s=$this->q('SELECT existencia,minimo FROM erp_pos_farmacia_control WHERE sucursal_id=? AND producto_id=?',[$sid,$pid])->fetch();pi_alerta($this->db,$sid,$pid,(int)$s['existencia'],(int)$s['minimo']);
        }elseif($t['destino_tipo']==='ALMACEN'){
            // Posición de recepción dedicada. No crear doble entrada de Compras ni tocar costos.
            $nombre=$sid===1?'CIUDAD HIDALGO':'TUXTLA GUTIERREZ';$ubi='TRASPASOS RECIBIDOS';
            $r=$this->q('SELECT id FROM producto_existencias WHERE producto_id=? AND UPPER(TRIM(sucursal))=? AND UPPER(TRIM(ubicacion))=? ORDER BY id LIMIT 1 FOR UPDATE',[$pid,$nombre,$ubi])->fetchColumn();
            if($r){$this->q('UPDATE producto_existencias SET existencia=existencia+? WHERE id=?',[$n,(int)$r]);}
            else {$this->q('INSERT INTO producto_existencias (producto_id,sucursal,ubicacion,existencia) VALUES (?,?,?,?)',[$pid,$nombre,$ubi,$n]);}
            $this->q('INSERT INTO pos_existencias_farmacia (sucursal_id,producto_id,existencia) VALUES (?,?,?) ON DUPLICATE KEY UPDATE existencia=existencia+VALUES(existencia)',[$sid,$pid,$n]);
        }
    }
    public function recibir(array $u,int $id,array $cantidades,string $token,string $observaciones):void {
        if(!preg_match('/^[a-f0-9]{32}$/',$token))throw new DomainException('Identificador de recepción inválido.');
        if(mb_strlen($observaciones)>500)throw new DomainException('Observaciones demasiado largas.');
        $locks=[];
        try {
            $basica=$this->q('SELECT destino_id FROM erp_traspasos WHERE id=?',[$id])->fetch();if(!$basica)throw new DomainException('Traspaso no encontrado.');
            $productos=$this->q('SELECT producto_id FROM erp_traspasos_detalle WHERE traspaso_id=? ORDER BY producto_id',[$id])->fetchAll();
            foreach($productos as $p)$this->bloqueo((int)$basica['destino_id'],(int)$p['producto_id'],$locks);
            $this->db->beginTransaction();
            $t=$this->cabLock($id);$this->acceso($u,(int)$t['destino_id']);
            if(!in_array($t['estado'],['EN_TRANSITO','PARCIAL','INCIDENCIA'],true))throw new DomainException('El traspaso no tiene mercancía pendiente de recibir.');
            if($this->q('SELECT id FROM erp_traspasos_recepciones WHERE token=?',[$token])->fetch())throw new DomainException('Esta recepción ya fue registrada.');
            $items=$this->q('SELECT * FROM erp_traspasos_detalle WHERE traspaso_id=? ORDER BY producto_id FOR UPDATE',[$id])->fetchAll();
            if(count($cantidades)!==count($items))throw new DomainException('La recepción debe indicar las cantidades de todas las partidas.');
            $recibir=[];$pendiente=0;$sum=0;
            foreach($items as $d){
                $pid=(int)$d['producto_id'];$prev=(int)$d['recibido'];$total=(int)$d['enviado'];$raw=$cantidades[$pid]??null;
                if(!is_string($raw)&&!is_int($raw))throw new DomainException('Cantidad inválida para producto #'.$pid);
                if(!preg_match('/^\d{1,7}$/',(string)$raw))throw new DomainException('La cantidad debe ser un entero no negativo.');
                $n=(int)$raw;if($n>$total-$prev)throw new DomainException('La recepción supera lo pendiente del producto #'.$pid.'. Registra el excedente como incidencia, no como stock.');
                $recibir[$pid]=$n;$pendiente+=$total-$prev-$n;$sum+=$n;
            }
            if($sum===0 && trim($observaciones)==='')throw new DomainException('Indica las cantidades recibidas o una incidencia en observaciones.');
            if($sum===0 && trim($observaciones)!==''){
                $this->evento($id,$u,'INCIDENCIA',$observaciones);
                $this->q("UPDATE erp_traspasos SET estado='INCIDENCIA' WHERE id=?",[$id]);
                $this->db->commit();return;
            }
            $this->q('INSERT INTO erp_traspasos_recepciones (traspaso_id,token,usuario_id,observaciones) VALUES (?,?,?,?)',[$id,$token,(int)$u['id'],$observaciones]);$rid=(int)$this->db->lastInsertId();
            foreach($recibir as $pid=>$n){if($n===0)continue;
                $this->acreditarDestino($t,$pid,$n);
                $this->q('UPDATE erp_traspasos_detalle SET recibido=recibido+? WHERE traspaso_id=? AND producto_id=?',[$n,$id,$pid]);
                $this->q('INSERT INTO erp_traspasos_recepciones_detalle (recepcion_id,producto_id,cantidad) VALUES (?,?,?)',[$rid,$pid,$n]);
            }
            $estado=$pendiente===0?'COMPLETADO':(trim($observaciones)!==''?'INCIDENCIA':'PARCIAL');
            $this->q('UPDATE erp_traspasos SET estado=?,recibido_por=?,completado_en=IF(?=\'COMPLETADO\',NOW(),NULL) WHERE id=?',[$estado,(int)$u['id'],$estado,$id]);
            $this->evento($id,$u,'RECEPCION',"Piezas acreditadas: $sum. Pendientes: $pendiente. ".$observaciones);
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}finally{$this->release($locks);}
    }
    public function cancelar(array $u,int $id,string $motivo):void {
        $motivo=trim($motivo);if(mb_strlen($motivo)<5||mb_strlen($motivo)>500)throw new DomainException('Indica un motivo de 5 a 500 caracteres.');
        $this->db->beginTransaction();try{$t=$this->cabLock($id);
            $retiraSolicitud=$t['modalidad']==='SOLICITUD' && $t['estado']==='SOLICITADO' && (int)$t['solicitado_por']===(int)$u['id'];
            if(!$retiraSolicitud)$this->acceso($u,(int)$t['origen_id']);
            if(!in_array($t['estado'],['SOLICITADO','POR_PREPARAR','PREPARADO'],true))throw new DomainException('La mercancía ya fue despachada; no puede cancelarse sin una operación inversa.');
            $this->q("UPDATE erp_traspasos SET estado='CANCELADO' WHERE id=?",[$id]);$this->evento($id,$u,'CANCELAR',$motivo);$this->db->commit();
        }catch(Throwable $e){$this->db->rollBack();throw $e;}
    }
}
