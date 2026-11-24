<?php
declare(strict_types=1);
/** Catálogo de lectura para traspasos. No modifica productos ni existencias. */
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
function tc_response(int $code,array $data):never {
    http_response_code($code);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if($_SERVER['REQUEST_METHOD']!=='GET')tc_response(405,['error'=>'Método no permitido.']);
require_once __DIR__.'/../app/models/TraspasosGyD.php';
$u=currentUser()?:[];
$rol=strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));
if(!in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION','GERENTE','ENCARGADO'],true)
   ||(int)($u['id']??0)<1)tc_response(403,['error'=>'Sin autorización para consultar traspasos.']);
try {
    $sucursal=(int)($_GET['origen_id']??0);
    $destino=(int)($_GET['destino_id']??0);
    $tipo=strtoupper(trim((string)($_GET['origen_tipo']??'')));
    $modo=strtoupper(trim((string)($_GET['modalidad']??'')));
    $q=trim((string)($_GET['q']??''));
    $pagina=max(1,min(100000,(int)($_GET['pagina']??1)));
    if(strlen($q)>150)tc_response(422,['error'=>'La búsqueda es demasiado larga.']);
    if(!in_array($modo,['SOLICITUD','DIRECTO'],true))tc_response(422,['error'=>'Selecciona una modalidad válida.']);
    if(!in_array($sucursal,[1,2,3,4],true) || !(
        in_array($sucursal,[1,2],true)?in_array($tipo,['ALMACEN','FARMACIA'],true):$tipo==='UNICO'
    ))tc_response(422,['error'=>'Selecciona una sucursal y ubicación de origen válidas.']);
    if(!TraspasosGyD::global($u)){
        $asig=TraspasosGyD::sucursalUsuario($u);
        $permitida=$modo==='SOLICITUD'?$destino:$sucursal;
        if($asig===0 || $asig!==$permitida)tc_response(403,['error'=>'No tienes autorización para consultar esta operación.']);
    }
    $m=new TraspasosGyD();$db=$m->db();
    $st=$db->prepare('SELECT COUNT(*) FROM pos_sucursales WHERE id=? AND activo=1');$st->execute([$sucursal]);
    if((int)$st->fetchColumn()!==1)tc_response(422,['error'=>'Sucursal no disponible.']);
    if($modo==='SOLICITUD'){
        $st->execute([$destino]);if((int)$st->fetchColumn()!==1)tc_response(422,['error'=>'Selecciona primero la sucursal que solicita.']);
    }
    $limite=20;$offset=($pagina-1)*$limite;
    $patron='%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%';
    $filtro="p.estado=1";$p=[];
    if($q!==''){
        $filtro.=" AND (p.codigo LIKE ? ESCAPE '!' OR p.codigo_barras LIKE ? ESCAPE '!' OR p.descripcion LIKE ? ESCAPE '!')";
        $p=[$patron,$patron,$patron];
    }
    $s=$db->prepare('SELECT COUNT(*) FROM productos p WHERE '.$filtro);$s->execute($p);
    $total=(int)$s->fetchColumn();
    $sql='SELECT p.id,p.codigo,p.codigo_barras,p.descripcion FROM productos p WHERE '.$filtro;
    if($q!==''){
        $sql.=' ORDER BY (p.codigo=? OR p.codigo_barras=?) DESC, p.descripcion ASC, p.id ASC';
        $p[]=$q;$p[]=$q;
    }else{$sql.=' ORDER BY p.descripcion ASC,p.id ASC';}
    $sql.=' LIMIT '.$limite.' OFFSET '.(int)$offset;
    $s=$db->prepare($sql);$s->execute($p);$encontrados=$s->fetchAll(PDO::FETCH_ASSOC);
    $productos=[];
    foreach($encontrados as $r){
        $estado=pi_ubicacion($db,$sucursal,(int)$r['id']);
        if($tipo==='ALMACEN'){$disponible=(int)$estado['almacen_disponible'];$etiqueta='Almacén disponible';}
        elseif($tipo==='FARMACIA'){$disponible=$estado['farmacia']===null?null:max(0,(int)$estado['farmacia']);$etiqueta='Farmacia física';}
        else{$disponible=(int)$estado['general_disponible'];$etiqueta='POS disponible';}
        $productos[]=['id'=>(int)$r['id'],'codigo'=>(string)$r['codigo'],
           'codigo_barras'=>(string)($r['codigo_barras']??''),'descripcion'=>(string)$r['descripcion'],
           'disponible'=>$disponible,'etiqueta'=>$etiqueta];
    }
    tc_response(200,['productos'=>$productos,'total'=>$total,'pagina'=>$pagina,'por_pagina'=>$limite,
       'paginas'=>(int)ceil($total/$limite)]);
}catch(Throwable $e){
    error_log('Traspasos catálogo: '.$e->getMessage());
    tc_response(500,['error'=>'No fue posible consultar el catálogo. Verifica conexión, tablas y permisos.']);
}
