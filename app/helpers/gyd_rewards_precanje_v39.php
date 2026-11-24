<?php
declare(strict_types=1);
/**
 * G&D Rewards V39 / PRECANJE.
 * No aplica canjes, no crea movimientos de puntos y no modifica stock, caja, POS ni tickets.
 * La ejecución real permanece bloqueada hasta integrar y verificar las devoluciones parciales.
 */
require_once __DIR__.'/gyd_clientes_rewards.php';
require_once __DIR__.'/gyd_rewards_catalogo_v38.php';

final class GydRewardsPrecanjeV39 {
    public const CANJES_ACTIVOS = false; // BLOQUEO INCONDICIONAL, no activar manualmente.
    private static function q(PDO $db,string $sql,array $param=[]):PDOStatement {
        $s=$db->prepare($sql);$s->execute($param);return $s;
    }
    private static function laboratorio(PDO $db):void {
        GydRewardsCatalogoV38::laboratorio($db);
    }
    private static function entero(mixed $v,string $nombre):int {
        $n=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($n===false)throw new DomainException('Valor inválido de '.$nombre.'.');
        return $n;
    }
    private static function pos():void {
        require_once __DIR__.'/../models/PuntoVenta.php';
    }
    /** Sólo sucursales/cajas permitidas por la asignación POS real del usuario. */
    public static function cajasAutorizadas(array $usuario):array {
        self::pos();return (new PuntoVenta())->sucursales($usuario);
    }
    private static function sucursalAutorizada(array $usuario,int $caja):int {
        self::pos();$info=(new PuntoVenta())->cajaAutorizada($usuario,$caja);
        return (int)$info['id'];
    }
    /** Stock físico que una futura entrega consumiría. Lectura solamente. */
    private static function stock(PDO $db,int $sucursal,int $producto):array {
        if(in_array($sucursal,[1,2],true)){
            require_once __DIR__.'/gyd_pos_saldo_dividido.php';
            $r=gyd_pos_saldo_dividido($db,$sucursal,$producto,false);
            $fisico=$r['farmacia'];
            return ['existencia'=>$fisico===null?0:max(0,(int)$fisico),'conciliado'=>(bool)$r['conciliado'],
                'mensaje'=>$r['conciliado']?'Farmacia física':'General distinto de Farmacia + Almacén'];
        }
        require_once __DIR__.'/pos_inteligente.php';
        $stockId=pi_tt_stock_sid($db,$sucursal);
        $x=self::q($db,'SELECT existencia FROM pos_existencias_farmacia WHERE sucursal_id=? AND producto_id=?',[$stockId,$producto])->fetchColumn();
        return ['existencia'=>$x===false?0:max(0,(int)$x),'conciliado'=>$x!==false,
            'mensaje'=>'Inventario general compartido (sólo consulta)'];
    }
    public static function buscarClientes(PDO $db,string $q):array {
        self::laboratorio($db);
        $rows=GydClientesRewards::buscar($db,$q);
        return array_map(static fn(array $r)=>[
            'id'=>(int)$r['id'],'codigo'=>(string)$r['codigo'],'nombre'=>(string)$r['nombre'],
            'telefono'=>(string)($r['telefono']??''),'saldo'=>(int)$r['saldo'],
            'en_revision'=>(bool)$r['puntos_en_revision']
        ],$rows);
    }
    public static function ofertas(PDO $db,array $usuario,int $caja):array {
        self::laboratorio($db);$sucursal=self::sucursalAutorizada($usuario,$caja);
        $lista=GydRewardsCatalogoV38::listado($db,$sucursal,150);
        $salida=[];
        foreach($lista as $r){
            if((string)$r['estado']!=='ACTIVO')continue;
            $stk=self::stock($db,$sucursal,(int)$r['producto_id']);
            $salida[]=[
                'id'=>(int)$r['id'],'producto_id'=>(int)$r['producto_id'],
                'codigo'=>(string)$r['codigo'],'producto'=>(string)$r['descripcion'],
                'puntos'=>(int)$r['puntos_requeridos'],'apto'=>(bool)$r['habilitable'],
                'existencia'=>$stk['existencia'],'stock_conciliado'=>$stk['conciliado'],
                'disponible'=>((bool)$r['habilitable'] && $stk['conciliado'] && $stk['existencia']>=1),
                'advertencia'=>!(bool)$r['habilitable']?(string)$r['razon']:
                    (!$stk['conciliado']?'Inventario pendiente de conciliación':($stk['existencia']<1?'Sin existencia':'Lista para solicitud de prueba'))
            ];
        }
        return ['sucursal_id'=>$sucursal,'ofertas'=>$salida,'canjes_habilitados'=>false];
    }
    public static function cotizar(PDO $db,array $usuario,int $caja,int $clienteId,int $catalogoId):array {
        self::laboratorio($db);
        $sucursal=self::sucursalAutorizada($usuario,$caja);
        $cliente=GydClientesRewards::validar($db,$clienteId);
        $catalogo=self::q($db,'SELECT id,producto_id,sucursal_id,estado,puntos_requeridos,costo_referencia_centavos,precio_referencia_centavos FROM gyd_rewards_catalogo_v38 WHERE id=?',[$catalogoId])->fetch(PDO::FETCH_ASSOC);
        if(!$catalogo || (int)$catalogo['sucursal_id']!==$sucursal || (string)$catalogo['estado']!=='ACTIVO')throw new DomainException('Recompensa no aprobada para esta sucursal.');
        $p=GydRewardsCatalogoV38::evaluarProducto($db,$sucursal,(int)$catalogo['producto_id']);
        $ok=$p['apto'] && $p['precio_centavos']!==null && $p['costo_centavos']!==null;
        $ok=$ok && (int)$catalogo['puntos_requeridos']>=(int)$p['minimo_puntos'];
        // La cotización exige que precio y costo sigan iguales a lo autorizado en V38.
        $ok=$ok && (int)$catalogo['precio_referencia_centavos']===(int)$p['precio_centavos']
               && (int)$catalogo['costo_referencia_centavos']===(int)$p['costo_centavos'];
        $stk=self::stock($db,$sucursal,(int)$catalogo['producto_id']);
        $saldo=GydClientesRewards::saldo($db,$clienteId);
        $revision=GydRewardsReversionesV37::hayParcialesPendientes($db,$clienteId);
        $puntos=(int)$catalogo['puntos_requeridos'];
        $apto=$ok && $stk['conciliado'] && $stk['existencia']>0 && !$revision && $saldo>=$puntos;
        $motivo=!$ok?'Catálogo pendiente de revisión comercial':
            (!$stk['conciliado']?'Inventario sin conciliar':
            ($stk['existencia']<1?'No hay unidades físicas disponibles':
            ($revision?'Cliente con devolución parcial pendiente':
            ($saldo<$puntos?'Puntos provisionales insuficientes':'Solicitable sólo como simulación; NO autoriza entrega'))));
        return [
            'cliente_id'=>$clienteId,'cliente'=>(string)$cliente['nombre'],'catalogo_id'=>$catalogoId,
            'producto_id'=>(int)$catalogo['producto_id'],'producto'=>(string)$p['descripcion'],
            'sucursal_id'=>$sucursal,'caja_id'=>$caja,'puntos_requeridos'=>$puntos,
            'saldo_provisional'=>$saldo,'saldo_hipotetico'=>max(0,$saldo-$puntos),
            'existencia_fisica'=>$stk['existencia'],'stock_conciliado'=>$stk['conciliado'],
            'pendiente_devolucion'=>$revision,'apto_para_solicitud'=>$apto,'razon'=>$motivo,
            'canje_real_habilitado'=>false,
            'aviso'=>'Esta cotización NO reserva puntos ni mercancía y NO es un canje.'
        ];
    }
    /** Registra una intención auditable. No reserva ni descuenta puntos/stock. */
    public static function solicitar(PDO $db,array $usuario,array $datos):array {
        self::laboratorio($db);
        $clienteId=self::entero($datos['cliente_id']??null,'cliente');
        $catalogoId=self::entero($datos['catalogo_id']??null,'recompensa');
        $caja=self::entero($datos['caja_id']??null,'caja');
        $actor=self::entero($usuario['id']??null,'usuario');
        $clave=strtolower(trim((string)($datos['clave']??'')));
        if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$clave))
            throw new DomainException('Identificador único de solicitud inválido.');
        if($db->inTransaction())throw new LogicException('Solicitud con transacción abierta.');
        $db->beginTransaction();
        try {
            // Serializar solicitudes por cliente. No constituyen reservas.
            $fila=self::q($db,'SELECT id FROM gyd_clientes WHERE id=? FOR UPDATE',[$clienteId])->fetchColumn();
            if(!$fila)throw new DomainException('Cliente no encontrado.');
            $prev=self::q($db,'SELECT * FROM gyd_rewards_solicitudes_v39 WHERE clave=? FOR UPDATE',[$clave])->fetch(PDO::FETCH_ASSOC);
            if($prev){
                if((int)$prev['usuario_id']!==$actor || (int)$prev['cliente_id']!==$clienteId || (int)$prev['catalogo_id']!==$catalogoId || (int)$prev['caja_id']!==$caja)
                    throw new DomainException('Clave de solicitud reutilizada con información diferente.');
                $db->commit();return ['id'=>(int)$prev['id'],'estado'=>(string)$prev['estado'],'repetida'=>true,'canje_real_habilitado'=>false];
            }
            $sim=self::cotizar($db,$usuario,$caja,$clienteId,$catalogoId);
            if(!$sim['apto_para_solicitud'])throw new DomainException('No es posible preparar la solicitud: '.$sim['razon']);
            self::q($db,"INSERT INTO gyd_rewards_solicitudes_v39
                (clave,cliente_id,sucursal_id,caja_id,catalogo_id,producto_id,puntos_snapshot,saldo_snapshot,stock_snapshot,estado,usuario_id)
                VALUES(?,?,?,?,?,?,?,?,?,'PENDIENTE',?)",[
                $clave,$clienteId,$sim['sucursal_id'],$caja,$catalogoId,$sim['producto_id'],
                $sim['puntos_requeridos'],$sim['saldo_provisional'],$sim['existencia_fisica'],$actor
            ]);
            $id=(int)$db->lastInsertId();$db->commit();
            return ['id'=>$id,'estado'=>'PENDIENTE','repetida'=>false,'canje_real_habilitado'=>false,
                'aviso'=>'Solicitud de simulación guardada. NO entregar producto ni descontar puntos.'];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function solicitudes(PDO $db,array $usuario,int $caja):array {
        self::laboratorio($db);$sucursal=self::sucursalAutorizada($usuario,$caja);
        $rol=strtoupper((string)($usuario['rol']??''));
        $esAdmin=in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION'],true);
        $filtro=$esAdmin?'':' AND x.usuario_id=?';
        $p=[$sucursal];if(!$esAdmin)$p[]=(int)($usuario['id']??0);
        return self::q($db,"SELECT x.id,x.estado,x.creado_en,x.puntos_snapshot,x.stock_snapshot,x.usuario_id,
             c.codigo AS cliente_codigo,c.nombre AS cliente,p.descripcion AS producto
             FROM gyd_rewards_solicitudes_v39 x
             JOIN gyd_clientes c ON c.id=x.cliente_id JOIN productos p ON p.id=x.producto_id
             WHERE x.sucursal_id=?$filtro ORDER BY x.id DESC LIMIT 35",$p)->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function cancelar(PDO $db,array $usuario,int $id,string $motivo):array {
        self::laboratorio($db);
        $actor=self::entero($usuario['id']??null,'usuario');
        $motivo=trim($motivo);
        if(mb_strlen($motivo,'UTF-8')<8||mb_strlen($motivo,'UTF-8')>240)throw new DomainException('Indica un motivo entre 8 y 240 caracteres.');
        $db->beginTransaction();
        try {
            $r=self::q($db,'SELECT * FROM gyd_rewards_solicitudes_v39 WHERE id=? FOR UPDATE',[$id])->fetch(PDO::FETCH_ASSOC);
            if(!$r)throw new DomainException('Solicitud inexistente.');
            $rol=strtoupper((string)($usuario['rol']??''));
            if(!in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION'],true) && (int)$r['usuario_id']!==$actor)
                throw new DomainException('Sólo puedes cancelar tus propias solicitudes.');
            self::sucursalAutorizada($usuario,(int)$r['caja_id']);
            if((string)$r['estado']==='CANCELADA'){$db->commit();return ['id'=>$id,'estado'=>'CANCELADA','repetida'=>true];}
            self::q($db,"UPDATE gyd_rewards_solicitudes_v39 SET estado='CANCELADA',cancelado_por=?,cancelado_en=NOW(),motivo_cancelacion=? WHERE id=?",[$actor,$motivo,$id]);
            $db->commit();return ['id'=>$id,'estado'=>'CANCELADA','repetida'=>false];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    /** Guardia permanente: no hay función de ejecución de canjes en V39. */
    public static function ejecutarCanje():never {
        throw new DomainException('Canje REAL BLOQUEADO: falta conciliar devoluciones parciales e integrar el descuento atómico con inventarios y puntos.');
    }
}
