<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$u=currentUser()??[];
if (!in_array(($u['rol']??''),['CAJERO','ADMINISTRADOR','DIRECCION'],true)) {http_response_code(403);echo json_encode(['error'=>'No autorizado']);exit;}
require_once __DIR__.'/../app/models/PuntoVenta.php';
try {
    $model=new PuntoVenta();$accion=(string)($_GET['action']??'');
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $caja=(int)($_GET['caja_id']??0);
        if($accion==='sucursales'){$out=['sucursales'=>$model->sucursales($u)];}
        elseif($accion==='catalogo'){
            $c=$model->cajaAutorizada($u,$caja);
            $out=$model->catalogo((string)($_GET['q']??''),(int)$c['id'],(int)($_GET['page']??1),($_GET['solo_precio']??'1')!=='0');
        }
        elseif($accion==='buscar'){
            $c=$model->cajaAutorizada($u,$caja);
            $out=['productos'=>$model->productos((string)($_GET['q']??''),(int)$c['id'])];
        }elseif($accion==='estado'){
            $model->cajaAutorizada($u,$caja);$out=['turno'=>$model->turno($caja),'ventas'=>$model->historial($u,$caja),'efectivo'=>$model->efectivoTurno($u,$caja)];
        }else{throw new DomainException('Acción desconocida.');}
    }else{
        if($_SERVER['REQUEST_METHOD']!=='POST') throw new DomainException('Método no permitido.');
        // Leer una sola vez el cuerpo recibido.
        $json=file_get_contents('php://input');
        if (strlen($json)>100000) throw new DomainException('Solicitud demasiado grande.');
        $post=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($post)||!hash_equals((string)($_SESSION['pos_csrf']??''),(string)($post['csrf']??''))) {http_response_code(403);throw new DomainException('Token de seguridad inválido. Recarga la página.');}
        $caja=(int)($post['caja_id']??0);
        switch($accion){
            case 'abrir':$out=$model->abrirTurno($u,$caja,(string)($post['fondo']??''));break;
            case 'cerrar':$out=$model->cerrarTurno($u,$caja,(string)($post['contado']??''),(array)($post['denominaciones']??[]),(string)($post['supervisor_login']??''),(string)($post['supervisor_password']??''));break;
            case 'cobrar':$out=$model->cobrar($u,$caja,(array)($post['items']??[]),(array)($post['pagos']??[]),(string)($post['key']??''),(string)($post['quien_lleva']??''),(string)($post['cliente_nombre']??''));break;
            case 'configurar_presentacion':
                if(($u['rol']??'')!=='ADMINISTRADOR'){http_response_code(403);throw new DomainException('Solo administradores.');}
                $c=$model->cajaAutorizada($u,$caja);
                $out=$model->configurarPresentacion((int)$c['id'],(int)($post['producto_id']??0),(string)($post['modalidad']??'PIEZA'),(int)($post['piezas_por_caja']??1),(string)($post['precio_caja']??'0'),(int)$u['id']);break;
            case 'configurar_mayoreo':
                if(($u['rol']??'')!=='ADMINISTRADOR'){http_response_code(403);throw new DomainException('Solo un administrador configura mayoreo.');}
                $c=$model->cajaAutorizada($u,$caja);
                $out=$model->configurarMayoreo((int)$c['id'],(int)($post['producto_id']??0),isset($post['precio_mayoreo'])?(string)$post['precio_mayoreo']:null,isset($post['cantidad_minima'])?(int)$post['cantidad_minima']:null,(int)$u['id'],(string)($post['motivo']??''));break;
            case 'configurar':
                if(($u['rol']??'')!=='ADMINISTRADOR'){http_response_code(403);throw new DomainException('Solo el administrador configura precios y existencias.');}
                $c=$model->cajaAutorizada($u,$caja);
                $out=$model->configurar((int)$c['id'],(int)($post['producto_id']??0),(string)($post['precio']??''),(int)($post['stock']??-1),(int)$u['id'],(string)($post['motivo']??''));break;
            default:throw new DomainException('Acción desconocida.');
        }
    }
    echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(DomainException|JsonException $e){http_response_code(http_response_code()===403?403:422);echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
catch(Throwable $e){error_log('POS API: '.$e->getMessage());http_response_code(500);echo json_encode(['error'=>'No se pudo completar la operación. Revisa que hayas importado las tablas POS en la base prueba.']);}
