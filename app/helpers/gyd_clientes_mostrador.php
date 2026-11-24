<?php
declare(strict_types=1);
/** Clientes de venta de mostrador por sucursal, sin programa de puntos. */
final class GydClientesMostrador
{
    private static function query(PDO $db, string $sql, array $args=[]): PDOStatement {
        $stmt=$db->prepare($sql);$stmt->execute($args);return $stmt;
    }
    public static function sucursalDeCliente(PDO $db,int $clienteId): ?int {
        if($clienteId<1)return null;
        $id=self::query($db,'SELECT sucursal_id FROM gyd_clientes_mostrador WHERE cliente_id=?',[$clienteId])->fetchColumn();
        return $id===false?null:(int)$id;
    }
    public static function listar(PDO $db,int $sucursal,string $busqueda='',int $pagina=1):array {
        if($sucursal<1)throw new DomainException('Selecciona una caja para consultar los clientes.');
        $busqueda=trim($busqueda);
        if(mb_strlen($busqueda,'UTF-8')>100)throw new DomainException('La búsqueda no puede exceder 100 caracteres.');
        $pagina=max(1,min(100000,$pagina));$limite=50;
        // Un mostrador de otra sucursal nunca es visible ni seleccionable.
        $de=' FROM gyd_clientes c LEFT JOIN gyd_clientes_mostrador m ON m.cliente_id=c.id';
        $donde=' WHERE c.activo=1 AND (m.sucursal_id IS NULL OR m.sucursal_id=?)';
        $params=[$sucursal];
        if($busqueda!==''){
            $like='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$busqueda).'%';
            $donde.=' AND (c.nombre LIKE ? OR c.codigo LIKE ? OR c.telefono LIKE ? OR c.correo LIKE ?)';
            array_push($params,$like,$like,$like,$like);
        }
        $total=(int)self::query($db,'SELECT COUNT(*)'.$de.$donde,$params)->fetchColumn();
        $paginas=max(1,(int)ceil($total/$limite));$pagina=min($pagina,$paginas);
        $sql='SELECT c.id,c.codigo,c.nombre,c.telefono,c.correo,c.activo,m.sucursal_id AS sucursal_mostrador'
             .$de.$donde.' ORDER BY (m.sucursal_id=?) DESC,c.nombre ASC,c.id ASC LIMIT '
             .$limite.' OFFSET '.(int)(($pagina-1)*$limite);
        $filas=self::query($db,$sql,[...$params,$sucursal])->fetchAll(PDO::FETCH_ASSOC);
        foreach($filas as &$c){
            $mostrador=$c['sucursal_mostrador']!==null;
            $c['es_mostrador']=$mostrador;
            $c['saldo']=$mostrador?0:GydClientesRewards::saldo($db,(int)$c['id']);
            $c['puntos_en_revision']=$mostrador?false:GydRewardsReversionesV37::hayParcialesPendientes($db,(int)$c['id']);
        }unset($c);
        return ['clientes'=>$filas,'sucursal_id'=>$sucursal,'pagina'=>$pagina,'paginas'=>$paginas,'total'=>$total,'por_pagina'=>$limite];
    }
}
