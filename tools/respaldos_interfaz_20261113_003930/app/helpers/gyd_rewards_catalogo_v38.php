<?php
declare(strict_types=1);
/** G&D V38 · Catálogo administrativo. NO autoriza redenciones ni afecta las ventas/stock. */
final class GydRewardsCatalogoV38 {
    public const VALOR_PUNTO_CENTAVOS = 10; // Referencia de V36; no saldo redimible.
    public const COBERTURA_COSTO_PCT = 80; // Premio cuesta <=80% del valor de puntos reservado.
    public const MARGEN_MINIMO_PCT = 20;  // Margen bruto en precio normal por pieza.
    private static function q(PDO $db,string $sql,array $p=[]):PDOStatement {
        $s=$db->prepare($sql);$s->execute($p);return $s;
    }
    public static function laboratorio(PDO $db):void {
        $r=$db->query('SELECT DATABASE() AS base,@@port AS puerto')->fetch(PDO::FETCH_ASSOC);
        if(strtolower((string)($r['base']??''))!=='prueba' || (int)($r['puerto']??0)!==3307)
            throw new RuntimeException('El catálogo V38 sólo opera en prueba:3307.');
    }
    /** Parseo decimal exacto en centavos, sin round(float) ni valores negativos. */
    public static function centavos(?string $valor,bool $costo=false):?int {
        if($valor===null)return null;
        $valor=trim($valor);
        if(!preg_match('/^\d{1,9}(?:\.\d{1,4})?$/D',$valor))return null;
        [$entero,$decimal]=array_pad(explode('.',$valor,2),2,'');
        // Precios y costos se leen como DECIMAL: costo hacia arriba y precio hacia abajo
        // para no sobreestimar el margen bruto en ningún caso.
        $cuatro=str_pad($decimal,4,'0');
        return (int)$entero*100+(int)substr($cuatro,0,2)+($costo && (int)substr($cuatro,2,2)>0?1:0);
    }
    /** Formula pura: cobertura de costo y 20% margen de referencia. */
    public static function evaluarImportes(?int $precio,?int $costo,bool $activo=true):array {
        if(!$activo)return ['apto'=>false,'razon'=>'Producto o sucursal inactiva.','minimo_puntos'=>null,'margen_pct'=>null];
        if($precio===null||$precio<=0)return ['apto'=>false,'razon'=>'Precio público por sucursal ausente o inválido.','minimo_puntos'=>null,'margen_pct'=>null];
        if($costo===null||$costo<=0)return ['apto'=>false,'razon'=>'No hay costo de lote positivo verificable.','minimo_puntos'=>null,'margen_pct'=>null];
        $margen=round(100*($precio-$costo)/$precio,2);
        if($costo*100>$precio*(100-self::MARGEN_MINIMO_PCT))
            return ['apto'=>false,'razon'=>'Margen bruto inferior al 20% exigido.','minimo_puntos'=>null,'margen_pct'=>$margen];
        // 1 punto reserva $0.10 (10 centavos); se cubre como máximo 80% de esa reserva.
        // Evitar división flotante: ceil(costo / 8) con intdiv y resto.
        $centavosCubiertos=self::VALOR_PUNTO_CENTAVOS*self::COBERTURA_COSTO_PCT/100; // 8
        $min=intdiv($costo,(int)$centavosCubiertos)+(($costo%(int)$centavosCubiertos)!==0?1:0);
        if($min>2000000)return ['apto'=>false,'razon'=>'Recompensa excede límite administrativo de 2,000,000 puntos.','minimo_puntos'=>null,'margen_pct'=>$margen];
        return ['apto'=>true,'razon'=>'Costo y margen de referencia aptos; pendiente revisión comercial y legal.','minimo_puntos'=>max(1,$min),'margen_pct'=>$margen];
    }
    private static function normalizarFila(array $r):array {
        $precio=self::centavos(isset($r['precio_publico'])?(string)$r['precio_publico']:null);
        $costo=self::centavos(isset($r['costo_maximo'])?(string)$r['costo_maximo']:null,true);
        $a=self::evaluarImportes($precio,$costo,(int)($r['producto_activo']??0)===1 && (int)($r['sucursal_activa']??0)===1);
        return array_merge($r,$a,[
            'producto_id'=>(int)$r['producto_id'],'sucursal_id'=>(int)$r['sucursal_id'],
            'precio_centavos'=>$precio,'costo_centavos'=>$costo,
            'referencia_puntos_pesos'=>$a['minimo_puntos']!==null?number_format($a['minimo_puntos']*self::VALOR_PUNTO_CENTAVOS/100,2,'.',''):null
        ]);
    }
    public static function sucursales(PDO $db):array {
        self::laboratorio($db);
        return self::q($db,'SELECT id,nombre FROM pos_sucursales WHERE activo=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function buscarProductos(PDO $db,int $sucursal,string $busqueda):array {
        self::laboratorio($db);
        if($sucursal<=0)throw new DomainException('Selecciona una sucursal.');
        $busqueda=trim($busqueda);
        if(mb_strlen($busqueda,'UTF-8')<2||mb_strlen($busqueda,'UTF-8')>100)return [];
        $like='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$busqueda).'%';
        $rs=self::q($db,'SELECT p.id AS producto_id, p.codigo,p.codigo_barras,p.descripcion,p.estado AS producto_activo,
                   s.id AS sucursal_id,s.nombre AS sucursal_nombre,s.activo AS sucursal_activa,
                   pp.precio_publico, (SELECT MAX(l.costo_unitario) FROM lotes l WHERE l.producto_id=p.id AND l.costo_unitario>0) costo_maximo
                   FROM productos p JOIN pos_sucursales s ON s.id=?
                   LEFT JOIN pos_precios pp ON pp.producto_id=p.id AND pp.sucursal_id=s.id
                   WHERE p.estado=1 AND (p.codigo LIKE ? OR p.codigo_barras LIKE ? OR p.descripcion LIKE ?)
                   ORDER BY (p.codigo=? OR p.codigo_barras=?) DESC,p.descripcion LIMIT 30',
                   [$sucursal,$like,$like,$like,$busqueda,$busqueda])->fetchAll(PDO::FETCH_ASSOC);
        return array_map([self::class,'normalizarFila'],$rs);
    }
    public static function evaluarProducto(PDO $db,int $sucursal,int $producto):array {
        self::laboratorio($db);
        if($sucursal<1||$producto<1)throw new DomainException('Sucursal o producto inválido.');
        $r=self::q($db,'SELECT p.id AS producto_id,p.codigo,p.codigo_barras,p.descripcion,p.estado AS producto_activo,
                s.id AS sucursal_id,s.nombre AS sucursal_nombre,s.activo AS sucursal_activa,
                pp.precio_publico,(SELECT MAX(l.costo_unitario) FROM lotes l WHERE l.producto_id=p.id AND l.costo_unitario>0) costo_maximo
                FROM productos p JOIN pos_sucursales s ON s.id=?
                LEFT JOIN pos_precios pp ON pp.producto_id=p.id AND pp.sucursal_id=s.id
                WHERE p.id=? LIMIT 1',[$sucursal,$producto])->fetch(PDO::FETCH_ASSOC);
        if(!$r)throw new DomainException('El producto o la sucursal no existe.');
        return self::normalizarFila($r);
    }
    private static function legible(array $r):array {
        $actual=self::evaluarImportes(
            self::centavos(isset($r['precio_publico'])?(string)$r['precio_publico']:null),
            self::centavos(isset($r['costo_maximo'])?(string)$r['costo_maximo']:null,true),
            (int)($r['producto_activo']??0)===1&&(int)($r['sucursal_activa']??0)===1
        );
        $precio=self::centavos(isset($r['precio_publico'])?(string)$r['precio_publico']:null);
        $costo=self::centavos(isset($r['costo_maximo'])?(string)$r['costo_maximo']:null,true);
        $r=array_merge($r,$actual);
        $r['habilitable']=(string)($r['estado']??'')==='ACTIVO' && $actual['apto'] &&
            $precio===(int)$r['precio_referencia_centavos'] &&
            $costo===(int)$r['costo_referencia_centavos'] &&
            (int)$r['puntos_requeridos']>=(int)$actual['minimo_puntos'];
        if((string)($r['estado']??'')==='ACTIVO' && !$r['habilitable'])
            $r['razon']='Revisar: costo, precio, margen o puntos cambiaron. Catálogo no autorizable.';
        $r['precio_actual_centavos']=$precio;
        $r['costo_actual_centavos']=$costo;
        return $r;
    }
    public static function listado(PDO $db,int $sucursal=0,int $limite=80):array {
        self::laboratorio($db);$limite=max(1,min($limite,200));
        $where=$sucursal>0?'WHERE c.sucursal_id=?':'';
        $rows=self::q($db,"SELECT c.*,p.codigo,p.descripcion,p.estado AS producto_activo,s.nombre AS sucursal_nombre,s.activo AS sucursal_activa,
               pp.precio_publico,(SELECT MAX(l.costo_unitario) FROM lotes l WHERE l.producto_id=c.producto_id AND l.costo_unitario>0) costo_maximo
               FROM gyd_rewards_catalogo_v38 c JOIN productos p ON p.id=c.producto_id
               JOIN pos_sucursales s ON s.id=c.sucursal_id
               LEFT JOIN pos_precios pp ON pp.producto_id=c.producto_id AND pp.sucursal_id=c.sucursal_id
               $where ORDER BY c.id DESC LIMIT $limite",$sucursal>0?[$sucursal]:[])->fetchAll(PDO::FETCH_ASSOC);
        return array_map([self::class,'legible'],$rows);
    }
    public static function auditoria(PDO $db,int $id):array {
        self::laboratorio($db);if($id<1)throw new DomainException('ID inválido.');
        return self::q($db,'SELECT id,usuario_id,accion,motivo,antes_json,despues_json,creado_en
           FROM gyd_rewards_catalogo_auditoria_v38 WHERE catalogo_id=? ORDER BY id DESC LIMIT 30',[$id])->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function guardar(PDO $db,array $input,int $usuario):array {
        self::laboratorio($db);
        if($usuario<1)throw new DomainException('Usuario no identificado.');
        $sucursal=filter_var($input['sucursal_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $producto=filter_var($input['producto_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $puntos=filter_var($input['puntos_requeridos']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>2000000]]);
        $estado=(string)($input['estado']??'BORRADOR');
        $motivo=trim((string)($input['motivo']??''));
        $obs=trim((string)($input['observaciones']??''));
        if(!$sucursal||!$producto||!$puntos)throw new DomainException('Producto, sucursal o puntos inválidos.');
        if(!in_array($estado,['BORRADOR','ACTIVO','PAUSADO'],true))throw new DomainException('Estado inválido.');
        if(mb_strlen($motivo,'UTF-8')<10||mb_strlen($motivo,'UTF-8')>240)throw new DomainException('Escribe un motivo de 10 a 240 caracteres.');
        if(mb_strlen($obs,'UTF-8')>240)throw new DomainException('Observaciones demasiado extensas.');
        $datos=self::evaluarProducto($db,(int)$sucursal,(int)$producto);
        if(!$datos['apto'])throw new DomainException('Producto no elegible: '.$datos['razon']);
        if((int)$puntos<(int)$datos['minimo_puntos'])
            throw new DomainException('Insuficientes puntos: exige al menos '.$datos['minimo_puntos'].' para cubrir el costo de referencia.');
        $db->beginTransaction();
        try {
            $prev=self::q($db,'SELECT * FROM gyd_rewards_catalogo_v38 WHERE sucursal_id=? AND producto_id=? FOR UPDATE',[$sucursal,$producto])->fetch(PDO::FETCH_ASSOC);
            // Revalidar una vez dentro de la transacción, sin alterar precios ni costos.
            $datos=self::evaluarProducto($db,(int)$sucursal,(int)$producto);
            if(!$datos['apto'] || (int)$puntos<(int)$datos['minimo_puntos'])throw new DomainException('El costo o el margen del producto cambió; revisa puntos.');
            if($prev){
                self::q($db,'UPDATE gyd_rewards_catalogo_v38 SET puntos_requeridos=?,costo_referencia_centavos=?,precio_referencia_centavos=?,estado=?,observaciones=?,autorizado_por=? WHERE id=?',
                    [$puntos,$datos['costo_centavos'],$datos['precio_centavos'],$estado,$obs,$usuario,$prev['id']]);
                $id=(int)$prev['id'];$accion='ACTUALIZAR';
            }else{
                self::q($db,'INSERT INTO gyd_rewards_catalogo_v38(sucursal_id,producto_id,puntos_requeridos,costo_referencia_centavos,precio_referencia_centavos,estado,observaciones,autorizado_por) VALUES(?,?,?,?,?,?,?,?)',
                    [$sucursal,$producto,$puntos,$datos['costo_centavos'],$datos['precio_centavos'],$estado,$obs,$usuario]);
                $id=(int)$db->lastInsertId();$accion='CREAR';
            }
            $after=self::q($db,'SELECT * FROM gyd_rewards_catalogo_v38 WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
            self::q($db,'INSERT INTO gyd_rewards_catalogo_auditoria_v38(catalogo_id,usuario_id,accion,motivo,antes_json,despues_json) VALUES(?,?,?,?,?,?)',
                [$id,$usuario,$accion,$motivo,$prev?json_encode($prev,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):null,json_encode($after,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            $db->commit();return ['id'=>$id,'estado'=>$estado,'puntos_requeridos'=>(int)$puntos,'minimo_puntos'=>$datos['minimo_puntos'],'canje_habilitado'=>false];
        } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function pausar(PDO $db,int $id,string $motivo,int $usuario):array {
        self::laboratorio($db);
        if($id<1||$usuario<1)throw new DomainException('Registro o usuario inválido.');
        $motivo=trim($motivo);
        if(mb_strlen($motivo,'UTF-8')<10||mb_strlen($motivo,'UTF-8')>240)throw new DomainException('Indica motivo de 10 a 240 caracteres.');
        $db->beginTransaction();
        try {
            $prev=self::q($db,'SELECT * FROM gyd_rewards_catalogo_v38 WHERE id=? FOR UPDATE',[$id])->fetch(PDO::FETCH_ASSOC);
            if(!$prev)throw new DomainException('Recompensa inexistente.');
            self::q($db,"UPDATE gyd_rewards_catalogo_v38 SET estado='PAUSADO',autorizado_por=? WHERE id=?",[$usuario,$id]);
            $after=self::q($db,'SELECT * FROM gyd_rewards_catalogo_v38 WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
            self::q($db,'INSERT INTO gyd_rewards_catalogo_auditoria_v38(catalogo_id,usuario_id,accion,motivo,antes_json,despues_json) VALUES(?,?,?,?,?,?)',
               [$id,$usuario,'PAUSAR',$motivo,json_encode($prev,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($after,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            $db->commit();return ['id'=>$id,'estado'=>'PAUSADO','canje_habilitado'=>false];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
