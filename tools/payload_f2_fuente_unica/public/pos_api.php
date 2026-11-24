<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$u=currentUser()??[];
if (!in_array(($u['rol']??''),['CAJERO','ADMINISTRADOR','DIRECCION'],true)) {http_response_code(403);echo json_encode(['error'=>'No autorizado']);exit;}
require_once __DIR__.'/../app/models/PuntoVenta.php';
require_once __DIR__.'/../app/helpers/gyd_pos_documentos.php';
require_once __DIR__.'/../app/helpers/gyd_acceso_previo.php';
require_once __DIR__.'/../app/helpers/gyd_retiros_directos.php';
require_once __DIR__.'/../app/helpers/gyd_cortes_autorizados.php';
try {
    $model=new PuntoVenta();$accion=(string)($_GET['action']??'');
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $caja=(int)($_GET['caja_id']??0);
        if($accion==='documentos_listar'){$out=(new GydPosDocumentos($u,$model))->listar($caja);}
        elseif($accion==='documentos_detalle'){$out=(new GydPosDocumentos($u,$model))->detalle($caja,(int)($_GET['documento_id']??0));}
        elseif($accion==='documentos_repetir'){$out=(new GydPosDocumentos($u,$model))->repetir($caja,(int)($_GET['venta_id']??0));}
        elseif($accion==='sucursales'){$out=['sucursales'=>$model->sucursales($u)];}
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
            case 'documentos_guardar': $out=(new GydPosDocumentos($u,$model))->guardar($caja,$post);break;
            case 'documentos_eliminar': $out=(new GydPosDocumentos($u,$model))->eliminar($caja,(int)($post['documento_id']??0));break;
            case 'acceso_previo':
                $operacion=(string)($post['operacion']??'');
                if(!in_array($operacion,['retiro','corte'],true))throw new DomainException('Operación no permitida.');
                $model->cajaAutorizada($u,$caja);
                $turno=$model->turno($caja);
                if(!$turno)throw new DomainException('Abre un turno antes de continuar.');
                $db=(new Database())->connect();
                $nombreBD=(string)$db->query('SELECT DATABASE()')->fetchColumn();
                if($nombreBD!=='prueba')throw new DomainException('El acceso previo está habilitado solo para la base prueba.');
                $clave=(string)($post['contrasena']??'');
                if($operacion==='retiro'){
                    if((int)$turno['usuario_apertura_id']!==(int)($u['id']??0))throw new DomainException('Solo el cajero que abrió el turno puede realizar el retiro.');
                    gd_verificar_clave_cajero($db,$u,$clave);
                    $autorizador=['id'=>(int)$u['id'],'rol'=>'CAJERO','modo'=>'CLAVE_CAJERO_MODAL'];
                }else{
                    $autorizador=gyd_corte_autorizar($db,$u,$caja,(string)($post['login_autorizador']??''),$clave);
                }
                gyd_gate_guardar($operacion,$u,$caja,(int)$turno['id'],$autorizador);
                $out=['ok'=>true,'operacion'=>$operacion];break;
            case 'acceso_cancelar':
                gyd_gate_limpiar((string)($post['operacion']??''));
                $out=['ok'=>true];break;
            case 'abrir':$out=$model->abrirTurno($u,$caja,(string)($post['fondo']??''));break;
            case 'cerrar':$out=$model->cerrarTurno($u,$caja,(string)($post['contado']??''),(array)($post['denominaciones']??[]),(string)($post['supervisor_login']??''),(string)($post['supervisor_password']??''));break;
            case 'cobrar':$out=$model->cobrar($u,$caja,(array)($post['items']??[]),(array)($post['pagos']??[]),(string)($post['key']??''),(string)($post['quien_lleva']??''),(string)($post['cliente_nombre']??''));break;
            case 'configurar_presentacion':
                throw new DomainException('Esta acción fue retirada del POS. Configura presentaciones al aplicar Entradas por factura.');
            case 'configurar_mayoreo':
                if(($u['rol']??'')!=='ADMINISTRADOR'){http_response_code(403);throw new DomainException('Solo un administrador configura mayoreo.');}
                $c=$model->cajaAutorizada($u,$caja);
                $out=$model->configurarMayoreo((int)$c['id'],(int)($post['producto_id']??0),isset($post['precio_mayoreo'])?(string)$post['precio_mayoreo']:null,isset($post['cantidad_minima'])?(int)$post['cantidad_minima']:null,(int)$u['id'],(string)($post['motivo']??''));break;
            case 'configurar':
                throw new DomainException('Esta acción fue retirada del POS. Los precios y las existencias se administran mediante los movimientos del ERP.');
            default:throw new DomainException('Acción desconocida.');
        }
    }
    echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(DomainException|JsonException $e){http_response_code(http_response_code()===403?403:422);echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
catch(Throwable $e){error_log('POS API: '.$e->getMessage());http_response_code(500);echo json_encode(['error'=>'No se pudo completar la operación. Revisa que hayas importado las tablas POS en la base prueba.']);}
