<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/helpers/erp_productos_unicos.php';
require_once __DIR__ . '/../app/helpers/gyd_catalogo_operativo.php'; // F2 ficha comercial, conteo físico
requireLogin();
$usuario=currentUser();
$rol=strtoupper((string)($usuario['rol']??''));
$puedeEditar=in_array($rol,['ADMINISTRADOR','ADMIN'],true);
$puedeOperarPos=in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION'],true);
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
if(empty($_SESSION['erp_catalogo_csrf']))$_SESSION['erp_catalogo_csrf']=bin2hex(random_bytes(24));
$csrf=$_SESSION['erp_catalogo_csrf'];
$mensaje='';$errorEdicion='';
if(!in_array($rol,['ADMINISTRADOR','ADMIN','GERENTE','CONSULTA','COMPRAS','DIRECCION','DUENO','DUEÑO'],true)){http_response_code(403);exit('No tienes permiso para consultar el Catálogo Maestro.');}
function esc($v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function visible($v):string{return trim((string)$v)!==''?(string)$v:'Por completar';}
/** La tasa fiscal puede ser NULL (sin configurar) o 0.00 (configurada). */
function gydFiscalPorcentaje($valor,string $etiqueta):?string{
 if(!is_string($valor))throw new RuntimeException('El valor de '.$etiqueta.' no es válido.');
 $valor=trim($valor);
 if($valor==='')return null;
 if(!preg_match('/^(?:[0-9]{1,3})(?:\.[0-9]{1,2})?$/D',$valor) || (float)$valor>100){
  throw new RuntimeException('El porcentaje de '.$etiqueta.' debe estar entre 0 y 100, con máximo dos decimales.');
 }
 return number_format((float)$valor,2,'.','');
}
function gydFiscalEtiqueta($valor):string{return $valor===null||$valor===''?'Sin configurar':number_format((float)$valor,2,'.','').' %';}

$q=substr(trim((string)($_GET['q']??'')),0,100);
$filtro=(string)($_GET['filtro']??'todos');
$opciones=['todos'=>'Todos los productos','activos'=>'Productos activos','inactivos'=>'Productos inactivos','incompletos'=>'Datos incompletos','sin_codigo_barras'=>'Sin código de barras','sin_laboratorio'=>'Sin laboratorio','sin_categoria'=>'Sin categoría','sin_proveedor'=>'Sin proveedor','sin_unidad'=>'Sin unidad de medida','sin_iva_compra'=>'IVA compra sin configurar'];
if(!isset($opciones[$filtro]))$filtro='todos';
$sinBarra="(p.codigo_barras IS NULL OR TRIM(p.codigo_barras)='')";
$sinLaboratorio="(p.laboratorio IS NULL OR TRIM(p.laboratorio)='')";
$sinCategoria='(p.categoria_id IS NULL OR c.id IS NULL)';
$sinProveedor='(p.proveedor_id IS NULL OR pr.id IS NULL)';
$sinUnidad="(p.unidad_medida IS NULL OR TRIM(p.unidad_medida)='')";
$sinIvaCompra='f.iva_compra IS NULL';
$incompleto="($sinBarra OR $sinLaboratorio OR $sinCategoria OR $sinProveedor OR $sinUnidad)";
$conds=[];$params=[];
if($q!==''){$conds[]='(p.codigo LIKE :a OR p.codigo_barras LIKE :b OR p.descripcion LIKE :c OR p.laboratorio LIKE :d)';foreach(['a','b','c','d'] as $k)$params[$k]='%'.$q.'%';}
$map=['activos'=>'p.estado=1','inactivos'=>'p.estado=0','incompletos'=>$incompleto,'sin_codigo_barras'=>$sinBarra,'sin_laboratorio'=>$sinLaboratorio,'sin_categoria'=>$sinCategoria,'sin_proveedor'=>$sinProveedor,'sin_unidad'=>$sinUnidad,'sin_iva_compra'=>$sinIvaCompra];
if(isset($map[$filtro]))$conds[]=$map[$filtro];
$where=$conds?' WHERE '.implode(' AND ',$conds):'';
$joins=' FROM productos p LEFT JOIN categorias c ON c.id=p.categoria_id LEFT JOIN proveedores pr ON pr.id=p.proveedor_id LEFT JOIN erp_producto_ficha f ON f.producto_id=p.id ';
$select='SELECT p.id,p.codigo,p.codigo_barras,p.descripcion,p.laboratorio,p.categoria_id,p.proveedor_id,p.unidad_medida,p.estado,c.nombre AS categoria_nombre,pr.nombre AS proveedor_nombre,f.iva_compra,f.iva_venta,f.ieps ';
$filas=[];$total=0;$resumen=[];$categorias=[];$proveedores=[];$error='';$pagina=1;$paginas=1;
try{
 $pdo=(new Database())->connect();
 // F2: precio, presentación y conteo físico se guardan desde la ficha del catálogo.
 if($_SERVER['REQUEST_METHOD']==='POST' && in_array((string)($_POST['accion']??''),['guardar_precio_pos','guardar_conteo_farmacia'],true)){
  try {
   if(!$puedeOperarPos)throw new DomainException('No tienes autorización para configurar precios ni conteos.');
   if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new DomainException('Formulario expirado. Actualiza la página.');
   $accion=(string)$_POST['accion'];
   if($accion==='guardar_precio_pos')gyd_co_guardar_precio($pdo,$usuario,$_POST);
   else gyd_co_guardar_conteo($pdo,$usuario,$_POST);
   header('Location: erp_catalogo_maestro.php?'.http_build_query(['q'=>$q,'filtro'=>$filtro,'pagina'=>(int)($_POST['pagina']??1),'config_guardada'=>1]),true,303);exit;
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('F2 catalogo comercial: '.$e->getMessage());$errorEdicion=($e instanceof DomainException)?$e->getMessage():'No se pudo guardar la ficha. Consulta el registro de errores.';}
 }
 // ERP: guardado de campos descriptivos, sin inventarios ni importes.
 if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion']??'')==='editar_producto'){
  if(!$puedeEditar){http_response_code(403);$errorEdicion='No tienes permiso para editar productos.';}
  elseif(!hash_equals($csrf,(string)($_POST['csrf']??''))){http_response_code(400);$errorEdicion='La sesión del formulario caducó. Actualiza la página.';}
  else{
   $id=(int)($_POST['producto_id']??0);
   $descripcion=trim((string)($_POST['descripcion']??''));
   $codigoBarras=trim((string)($_POST['codigo_barras']??''));
   $laboratorio=trim((string)($_POST['laboratorio']??''));
   $unidad=trim((string)($_POST['unidad_medida']??''));
   $categoriaId=($_POST['categoria_id']??'')!==''?(int)$_POST['categoria_id']:null;
   $proveedorId=($_POST['proveedor_id']??'')!==''?(int)$_POST['proveedor_id']:null;
   $fiscalEntrada=[];
   try{
    foreach(['iva_compra'=>'IVA de compra','iva_venta'=>'IVA de venta','ieps'=>'IEPS'] as $clave=>$etiqueta){
      $fiscalEntrada[$clave]=gydFiscalPorcentaje($_POST[$clave]??'', $etiqueta);
    }
   }catch(RuntimeException $e){$errorEdicion=$e->getMessage();}

   if($errorEdicion!==''){}
   elseif($id<1||$descripcion===''||mb_strlen($descripcion)>255||mb_strlen($codigoBarras)>150||mb_strlen($laboratorio)>120||mb_strlen($unidad)>50){$errorEdicion='Verifica la descripción y la longitud de los campos.';}
   elseif($categoriaId!==null && (int)$pdo->query('SELECT COUNT(*) FROM categorias WHERE id='.(int)$categoriaId)->fetchColumn()===0){$errorEdicion='La categoría seleccionada no existe.';}
   elseif($proveedorId!==null && (int)$pdo->query('SELECT COUNT(*) FROM proveedores WHERE id='.(int)$proveedorId)->fetchColumn()===0){$errorEdicion='El proveedor seleccionado no existe.';}
   else{
    try{
     $pdo->beginTransaction();
     $prev=$pdo->prepare('SELECT id,codigo,codigo_barras,descripcion,laboratorio,categoria_id,proveedor_id,unidad_medida FROM productos WHERE id=? FOR UPDATE');$prev->execute([$id]);$anterior=$prev->fetch(PDO::FETCH_ASSOC);
     if(!$anterior)throw new RuntimeException('El producto ya no existe.');
     $stFiscal=$pdo->prepare('SELECT iva_compra,iva_venta,ieps FROM erp_producto_ficha WHERE producto_id=? FOR UPDATE');
     $stFiscal->execute([$id]);
     $fiscalAnterior=$stFiscal->fetch(PDO::FETCH_ASSOC)?:['iva_compra'=>null,'iva_venta'=>null,'ieps'=>null];

     $dup=gyd_producto_duplicado($pdo,(string)$anterior['codigo'],$codigoBarras,$descripcion,$id);
     if($dup)throw new RuntimeException(gyd_duplicado_mensaje($dup));
     $st=$pdo->prepare('UPDATE productos SET descripcion=?, codigo_barras=?, laboratorio=?, categoria_id=?, proveedor_id=?, unidad_medida=? WHERE id=?');
     $st->execute([$descripcion,$codigoBarras!==''?$codigoBarras:null,$laboratorio!==''?$laboratorio:null,$categoriaId,$proveedorId,$unidad!==''?$unidad:null,$id]);
     // Upsert únicamente de impuestos; NO altera las otras columnas de la ficha, costos ni existencias.
     $stFiscal=$pdo->prepare('INSERT INTO erp_producto_ficha (producto_id,iva_compra,iva_venta,ieps,creado_por,actualizado_por) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE iva_compra=VALUES(iva_compra),iva_venta=VALUES(iva_venta),ieps=VALUES(ieps),actualizado_por=VALUES(actualizado_por)');
     $idActor=(int)($usuario['id']??0)?:null;
     $stFiscal->execute([$id,$fiscalEntrada['iva_compra'],$fiscalEntrada['iva_venta'],$fiscalEntrada['ieps'],$idActor,$idActor]);

     $anterior['impuestos_catalogo']=$fiscalAnterior;
     $nuevo=['descripcion'=>$descripcion,'codigo_barras'=>$codigoBarras,'laboratorio'=>$laboratorio,'categoria_id'=>$categoriaId,'proveedor_id'=>$proveedorId,'unidad_medida'=>$unidad,'impuestos_catalogo'=>$fiscalEntrada];

     $actorId=(int)($usuario['id']??0);
     $audit=$pdo->prepare('INSERT INTO auditoria_movimientos (usuario_id, usuario_nombre, usuario_login, usuario_rol, modulo, accion, entidad, registro_id, descripcion, datos_anteriores, datos_nuevos, direccion_ip, metodo_http, url) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
     $audit->execute([$actorId?:null,(string)($usuario['nombre']??''),(string)($usuario['usuario']??''),$rol,'ERP Catálogo Maestro','EDITAR_PRODUCTO','producto',(string)$id,'Actualizó datos descriptivos del catálogo maestro.',json_encode($anterior,JSON_UNESCAPED_UNICODE),json_encode($nuevo,JSON_UNESCAPED_UNICODE),(string)($_SERVER['REMOTE_ADDR']??''),'POST',(string)($_SERVER['REQUEST_URI']??'')]);
     $histFicha=$pdo->prepare('INSERT INTO erp_producto_historial_ficha(producto_id,usuario_id,accion,datos_anteriores,datos_nuevos) VALUES(?,?,?,?,?)');
     $histFicha->execute([$id,$actorId?:null,'IMPUESTOS',json_encode($fiscalAnterior,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),json_encode($fiscalEntrada,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)]);
     $pdo->commit();
     header('Location: erp_catalogo_maestro.php?'.http_build_query(['q'=>$q,'filtro'=>$filtro,'pagina'=>(int)($_POST['pagina']??1),'guardado'=>1]));exit;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('ERP editar producto: '.$e->getMessage());$errorEdicion=$e instanceof RuntimeException?$e->getMessage():'No fue posible guardar. Revisa el registro de errores.';}
   }
  }
 }
 $categorias=$pdo->query('SELECT id,nombre FROM categorias ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
 $proveedores=$pdo->query('SELECT id,nombre FROM proveedores ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
 $resumen=$pdo->query("SELECT COUNT(*) AS total,COALESCE(SUM(p.estado=1),0) AS activos,COALESCE(SUM($incompleto),0) AS incompletos,COALESCE(SUM($sinProveedor),0) AS sin_proveedor,COALESCE(SUM($sinCategoria),0) AS sin_categoria,COALESCE(SUM($sinUnidad),0) AS sin_unidad $joins")->fetch(PDO::FETCH_ASSOC)?:[];
 $st=$pdo->prepare('SELECT COUNT(*) '.$joins.$where);$st->execute($params);$total=(int)$st->fetchColumn();
 if(($_GET['export']??'')==='csv'){
  header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="catalogo_maestro_gerencial_gyd.csv"');
  $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['Código','Código de barras','Producto','Laboratorio','Categoría','Proveedor asignado','Unidad','IVA compra %','IVA venta %','IEPS %','Estado']);
  $st=$pdo->prepare($select.$joins.$where.' ORDER BY p.descripcion ASC');$st->execute($params);
  while($r=$st->fetch(PDO::FETCH_ASSOC))fputcsv($out,[$r['codigo'],$r['codigo_barras'],$r['descripcion'],$r['laboratorio'],$r['categoria_nombre'],$r['proveedor_nombre'],$r['unidad_medida'],$r['iva_compra'],$r['iva_venta'],$r['ieps'],(int)$r['estado']===1?'Activo':'Inactivo']);
  fclose($out);exit;
 }
 $pagina=max(1,(int)($_GET['pagina']??1));$paginas=max(1,(int)ceil($total/30));$pagina=min($pagina,$paginas);$offset=($pagina-1)*30;
 $st=$pdo->prepare($select.$joins.$where.' ORDER BY p.descripcion ASC LIMIT 30 OFFSET '.$offset);$st->execute($params);$filas=$st->fetchAll(PDO::FETCH_ASSOC);
 // Hasta 30 productos por página: cargamos los datos operativos para mostrarlos en un solo modal.
 $gydDatos=[];
 if($puedeOperarPos && $filas){
  $ids=array_map('intval',array_column($filas,'id'));
  foreach($ids as $id){$gydDatos[$id]=[];foreach([1,2,3,4] as $sid)$gydDatos[$id][$sid]=['precio'=>'','modalidad'=>'','piezas'=>0,'fisico'=>null,'minimo'=>null,'stock'=>null,'almacen'=>0];}
  $holders=implode(',',array_fill(0,count($ids),'?'));
  $st=$pdo->prepare('SELECT producto_id,sucursal_id,precio_publico FROM pos_precios WHERE producto_id IN ('.$holders.')');$st->execute($ids);
  foreach($st->fetchAll(PDO::FETCH_ASSOC) as $v){$id=(int)$v['producto_id'];$sid=(int)$v['sucursal_id'];if(isset($gydDatos[$id][$sid]))$gydDatos[$id][$sid]['precio']=$v['precio_publico']===null?'':number_format((float)$v['precio_publico'],2,'.','');}
  $st=$pdo->prepare('SELECT producto_id,sucursal_id,modalidad_presentacion,piezas_por_caja FROM pos_presentaciones WHERE producto_id IN ('.$holders.')');$st->execute($ids);
  foreach($st->fetchAll(PDO::FETCH_ASSOC) as $v){$id=(int)$v['producto_id'];$sid=(int)$v['sucursal_id'];if(isset($gydDatos[$id][$sid])){$gydDatos[$id][$sid]['modalidad']=(string)$v['modalidad_presentacion'];$gydDatos[$id][$sid]['piezas']=(int)$v['piezas_por_caja'];}}
  $st=$pdo->prepare('SELECT producto_id,sucursal_id,existencia,minimo FROM erp_pos_farmacia_control WHERE sucursal_id IN(1,2) AND producto_id IN ('.$holders.')');$st->execute($ids);
  foreach($st->fetchAll(PDO::FETCH_ASSOC) as $v){$id=(int)$v['producto_id'];$sid=(int)$v['sucursal_id'];if(isset($gydDatos[$id][$sid])){$gydDatos[$id][$sid]['fisico']=(int)$v['existencia'];$gydDatos[$id][$sid]['minimo']=(int)$v['minimo'];}}
  $st=$pdo->prepare("SELECT producto_id,UPPER(TRIM(sucursal)) COLLATE utf8mb4_general_ci AS nombre,existencia FROM producto_existencias WHERE producto_id IN (".$holders.") AND TRIM(COALESCE(ubicacion,''))<>'' AND UPPER(TRIM(ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION','SIN UBICACIÓN')");$st->execute($ids);
  foreach($st->fetchAll(PDO::FETCH_ASSOC) as $v){$id=(int)$v['producto_id'];$nom=trim((string)$v['nombre']);$sid=in_array($nom,['CIUDAD HIDALGO','CD HIDALGO'],true)?1:(in_array($nom,['TUXTLA','TUXTLA GUTIERREZ','TUXTLA GUTIÉRREZ'],true)?2:0);if($sid&&isset($gydDatos[$id][$sid]))$gydDatos[$id][$sid]['almacen']=($gydDatos[$id][$sid]['almacen']??0)+(int)$v['existencia'];}
  $st=$pdo->prepare('SELECT producto_id,sucursal_id,existencia FROM pos_existencias_farmacia WHERE sucursal_id IN(1,2,3) AND producto_id IN ('.$holders.')');$st->execute($ids);
  foreach($st->fetchAll(PDO::FETCH_ASSOC) as $v){$id=(int)$v['producto_id'];$sid=(int)$v['sucursal_id'];if(isset($gydDatos[$id][$sid])){$gydDatos[$id][$sid]['stock']=(int)$v['existencia'];if($sid===3)$gydDatos[$id][4]['stock']=(int)$v['existencia'];}}
 }

}catch(Throwable $e){error_log('ERP catalogo gerencial: '.$e->getMessage());$error='No se pudo consultar el catálogo. Revisa la conexión o la estructura de las tablas.';}
function urlPag(int $p,string $q,string $f):string{return '?'.http_build_query(['q'=>$q,'filtro'=>$f,'pagina'=>$p]);}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ERP G&D | Catálogo Maestro</title><style>
:root{font-family:system-ui,-apple-system,'Segoe UI',sans-serif;color:#153354;background:#eef3f9}*{box-sizing:border-box}body{margin:0}button,input,select{font:inherit}button,a{cursor:pointer}header{background:linear-gradient(115deg,#102e50,#194d81);border-bottom:4px solid #d62843;color:#fff;padding:17px clamp(16px,3vw,42px);display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}header h1{font-size:23px;margin:0}header p{margin:4px 0 0;color:#d2e0f0;font-size:13px}header a{border:1px solid #8baed4;padding:10px 15px;border-radius:10px;color:white;text-decoration:none;font-weight:700}main{max-width:1550px;margin:auto;padding:22px}.intro{margin:0 0 18px;color:#56708b}.notice{padding:13px 16px;background:#fff6df;color:#6e5217;border:1px solid #e6cb8d;border-radius:11px;margin-bottom:14px}.stats{display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:13px}.stat{display:block;background:white;border:1px solid #e0e9f3;border-radius:15px;padding:19px;text-decoration:none;box-shadow:0 4px 14px #14365c0a;color:inherit;min-height:112px}.stat:hover{border-color:#90b4df}.stat strong{font-size:29px;color:#165caa;display:block}.stat span{font-size:13px;color:#61748c}.card{background:white;border-radius:16px;padding:22px;margin-top:17px;box-shadow:0 4px 20px #17365c0c}.card h2{font-size:21px;margin:0 0 5px}.muted{color:#63778c;font-size:13px}.tools{display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-top:17px}.tools label{display:grid;gap:6px;font-size:13px;font-weight:700}.tools label:first-child{flex:2;min-width:220px}.tools label:nth-child(2){flex:1;min-width:180px}.tools input,.tools select{width:100%;border:1px solid #c6d7e8;border-radius:9px;padding:12px;min-height:45px;background:#fff}.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:45px;padding:11px 16px;border:0;border-radius:9px;background:#185eb8;color:white;font-weight:700;text-decoration:none;white-space:nowrap}.btn.secondary{background:#eaf1fa;color:#164b85}.tablewrap{overflow:auto;border:1px solid #e3ecf6;border-radius:11px;margin-top:13px}table{border-collapse:collapse;width:100%;min-width:900px;font-size:13px}th{background:#1d5687;color:white;text-align:left;padding:13px 12px}td{padding:13px 12px;border-bottom:1px solid #e7eef6;vertical-align:middle}tbody tr:hover{background:#f7faff}.product{font-weight:750}.small{font-size:12px;color:#617890}.status{display:inline-block;background:#e6f6e9;color:#207b38;padding:4px 8px;border-radius:20px;font-weight:700;font-size:12px}.status.off{background:#fdebe8;color:#a53f34}.pending{color:#ad5d12}.editbtn{background:#155fba;color:white;border:0;border-radius:8px;padding:9px 11px;font-weight:700;margin-left:5px}.editgrid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.editgrid label{display:grid;gap:5px;font-size:13px;font-weight:700}.editgrid input,.editgrid select{padding:10px;border:1px solid #bed1e5;border-radius:8px;width:100%}.editgrid .wide{grid-column:1/-1}.editactions{display:flex;justify-content:end;margin-top:20px;gap:10px}.fiscal-fields{border:1px solid #d4e3f4;border-radius:12px;margin:15px 0 8px;padding:13px;background:#f4f8fe}.fiscal-fields legend{font-weight:800;color:#164574;padding:0 6px}@media(max-width:520px){.editgrid{grid-template-columns:1fr}}.details{background:#ecf3fc;border:1px solid #d7e5f7;border-radius:8px;color:#164f94;padding:8px 11px;font-weight:700}.meta{font-size:13px;color:#5c7288;margin:17px 0 0}.pagination{display:flex;align-items:center;justify-content:end;gap:11px;margin-top:17px}.pagination a{border:1px solid #c2d5e9;text-decoration:none;color:#15559c;border-radius:8px;padding:8px 12px}dialog{border:0;border-radius:16px;box-shadow:0 24px 75px #0d284c50;padding:0;width:min(560px,calc(100vw - 24px));max-height:90vh;overflow:auto}dialog::backdrop{background:#091b32a8}.modalhead{background:#143d6c;color:#fff;padding:20px 24px;display:flex;justify-content:space-between;align-items:center;gap:10px}.modalhead h2{margin:0;font-size:20px}.modalhead button{background:transparent;color:#fff;border:0;font-size:28px}.modalbody{padding:21px 24px}.detailrow{display:grid;grid-template-columns:140px 1fr;gap:12px;padding:10px 0;border-bottom:1px solid #e5edf6;font-size:14px}.detailrow span:first-child{color:#63758a}.footnote{margin-top:16px;color:#64778a;font-size:12px}@media(max-width:850px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}main{padding:12px}.tools>*{width:100%}.tools label{flex:auto!important}.card{padding:15px}}@media(max-width:450px){.stat{padding:13px}.stat strong{font-size:24px}.detailrow{grid-template-columns:1fr;gap:3px}}
 .gyd-modal{width:min(860px,calc(100vw - 24px))}.gyd-sep{border:1px solid #dce7f3;border-radius:11px;padding:14px;margin:12px 0;background:#f9fcff}.gyd-help{color:#58718a;font-size:13px;line-height:1.5;margin:7px 0}.gyd-opt{padding:11px;border:1px solid #c6d7e8;border-radius:8px;width:100%}.gyd-pair{display:grid;grid-template-columns:1fr 1fr;gap:10px}.gyd-pair label{font-weight:700;font-size:13px;display:grid;gap:5px}.gyd-pair input,.gyd-pair select{padding:10px;border:1px solid #c6d7e8;border-radius:8px;width:100%}@media(max-width:650px){.gyd-pair{grid-template-columns:1fr}}
</style></head><body><header><div><h1>G&D | Catálogo Maestro</h1><p>ERP · Información general de productos</p></div><?php if(in_array($rol,['ADMINISTRADOR','ADMIN','COMPRAS','DIRECCION','DUENO','DUEÑO'],true)): ?><a href="erp_producto_alta.php" style="background:white;color:#143d6c">+ Alta de artículo (WinCaja)</a><?php endif; ?><a href="<?= $rol==='COMPRAS'?'erp_compras_inicio.php':'productos.php' ?>">← Volver</a><a href="erp_catalogo_reportes.php" style="background:#dbeafb;color:#153c65">📊 Reportes personalizados</a><a href="erp_catalogo_auditoria.php" style="background:#e4f6e9;color:#155b3a">🔎 Auditoría del catálogo</a><?php if($puedeEditar):?><a href="erp_catalogo_finanzas.php" style="background:#fff0d7;color:#744800">💰 Costos y rentabilidad</a><?php endif;?><?php /* GYD_COMPRAS_FINANZAS_CATALOGO_V1 */ if($rol==='COMPRAS'):?><nav aria-label="Herramientas de Compras" style="display:flex;gap:7px;flex-wrap:wrap"><a href="erp_catalogo_reportes.php" style="background:#fff;color:#17456d">Reportes</a><a href="erp_catalogo_finanzas.php" style="background:#fff;color:#17456d">Costos y márgenes</a><a href="erp_alertas_compras.php" style="background:#fff;color:#17456d">Alertas</a></nav><?php endif;?></header><main><div class="notice"><b>Catálogo Maestro:</b> desde la ficha de cada producto, Administración y Dirección configuran precio por sucursal, pieza/caja y conteo físico de Farmacia. Las entradas por factura administran mercancía; el stock general cambia por ventas, movimientos y conciliaciones físicas autorizadas.</div><?php if(($_GET['config_guardada']??'')==='1'):?><div class="notice" style="background:#e6f6ec;color:#1a663c">Configuración guardada con historial de cambios.</div><?php endif;?><?php if(($_GET['guardado']??'')==='1'):?><div class="notice" style="background:#e6f6ec;color:#1a663c">Producto actualizado y modificación registrada correctamente.</div><?php endif;?><?php if($errorEdicion):?><div class="notice" role="alert"><?=esc($errorEdicion)?></div><?php endif;?><p class="intro">Consulta qué artículos tenemos registrados, cómo están clasificados y cuáles necesitan completar su información.</p>
<?php if($error):?><div class="notice"><?=esc($error)?></div><?php else:?>
<section class="stats">
<a class="stat" href="erp_catalogo_maestro.php"><strong><?=number_format((int)($resumen['total']??0))?></strong><span>Productos registrados</span></a>
<a class="stat" href="?filtro=activos"><strong><?=number_format((int)($resumen['activos']??0))?></strong><span>Productos activos</span></a>
<a class="stat" href="?filtro=incompletos"><strong><?=number_format((int)($resumen['incompletos']??0))?></strong><span>Productos con datos incompletos</span></a>
<a class="stat" href="?filtro=sin_proveedor"><strong><?=number_format((int)($resumen['sin_proveedor']??0))?></strong><span>Sin proveedor asignado</span></a>
</section>
<section class="card"><h2>Consultar productos</h2><div class="muted">Busca por nombre o código. Selecciona «Ver detalles» para consultar la información completa.</div>
<form class="tools" method="get"><label>Buscar producto<input name="q" value="<?=esc($q)?>" placeholder="Código, nombre o laboratorio; ejemplo: 7611"></label><label>Mostrar<select name="filtro"><?php foreach($opciones as $k=>$txt):?><option value="<?=esc($k)?>" <?=$filtro===$k?'selected':''?>><?=esc($txt)?></option><?php endforeach;?></select></label><button class="btn" type="submit">Buscar</button><a class="btn secondary" href="erp_catalogo_maestro.php">Limpiar</a><a class="btn secondary" href="?<?=esc(http_build_query(['q'=>$q,'filtro'=>$filtro,'export'=>'csv']))?>">Exportar CSV</a></form>
<p class="meta"><?=number_format($total)?> productos encontrados · Página <?=$pagina?> de <?=$paginas?></p>
<div class="tablewrap"><table><thead><tr><th>Producto</th><th>Código de barras</th><th>Laboratorio</th><th>Categoría</th><th>Información</th><th>Acción</th></tr></thead><tbody>
<?php foreach($filas as $r):$faltantes=[];foreach(['Código de barras'=>'codigo_barras','Laboratorio'=>'laboratorio','Categoría'=>'categoria_nombre','Proveedor'=>'proveedor_nombre','Unidad de medida'=>'unidad_medida'] as $nombre=>$clave){if(trim((string)($r[$clave]??''))==='')$faltantes[]=$nombre;}$detalles=['Producto'=>$r['descripcion'],'Código'=>$r['codigo'],'Código de barras'=>visible($r['codigo_barras']),'Laboratorio'=>visible($r['laboratorio']),'Categoría'=>visible($r['categoria_nombre']),'Proveedor asignado'=>visible($r['proveedor_nombre']),'Unidad de medida'=>visible($r['unidad_medida']),'IVA de compra'=>gydFiscalEtiqueta($r['iva_compra']),'IVA de venta'=>gydFiscalEtiqueta($r['iva_venta']),'IEPS'=>gydFiscalEtiqueta($r['ieps']),'Estado'=>((int)$r['estado']===1?'Activo':'Inactivo'),'Datos pendientes'=>$faltantes?implode(', ',$faltantes):'Ninguno'];$editable=['id'=>$r['id'],'descripcion'=>$r['descripcion'],'codigo_barras'=>$r['codigo_barras'],'laboratorio'=>$r['laboratorio'],'categoria_id'=>$r['categoria_id'],'proveedor_id'=>$r['proveedor_id'],'unidad_medida'=>$r['unidad_medida'],'iva_compra'=>$r['iva_compra'],'iva_venta'=>$r['iva_venta'],'ieps'=>$r['ieps']];?><tr>
<td class="product"><?=esc($r['descripcion'])?><div class="small">Código: <?=esc($r['codigo'])?></div></td><td><?=esc(visible($r['codigo_barras']))?></td><td><?=esc(visible($r['laboratorio']))?></td><td><?=esc(visible($r['categoria_nombre']))?></td><td><?php if((int)$r['estado']!==1):?><span class="status off">Inactivo</span><?php elseif($faltantes):?><span class="pending">Datos por completar</span><?php else:?><span class="status">Completo</span><?php endif;?></td>
<td><button type="button" class="details" data-producto="<?=esc(json_encode($detalles,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE))?>">Ver detalles</button><?php if($puedeOperarPos):?><button type="button" class="editbtn gyd-ficha-pos" data-id="<?=(int)$r['id']?>" data-nombre="<?=esc($r['descripcion'])?>">Precio / inventario</button><?php endif;?><?php if($puedeEditar):?><a class="finance-product" style="display:inline-block;background:#fff2df;color:#724c15;border-radius:6px;padding:7px;margin-left:4px;text-decoration:none;font-weight:700" href="erp_catalogo_finanzas.php?producto_id=<?=(int)$r['id']?>">Costos y márgenes</a><?php endif;?><?php if($puedeEditar):?><button type="button" class="editbtn" data-editar="<?=esc(json_encode($editable,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE))?>">Editar</button><?php endif;?></td></tr><?php endforeach;?><?php if(!$filas):?><tr><td colspan="6">No se encontraron productos con esos criterios.</td></tr><?php endif;?></tbody></table></div>
<nav class="pagination"><?php if($pagina>1):?><a href="<?=esc(urlPag($pagina-1,$q,$filtro))?>">← Anterior</a><?php endif;?><span><?=$pagina?> / <?=$paginas?></span><?php if($pagina<$paginas):?><a href="<?=esc(urlPag($pagina+1,$q,$filtro))?>">Siguiente →</a><?php endif;?></nav>
<p class="footnote">El proveedor mostrado es el asignado directamente al producto. Algunas compras históricas pueden registrar otro proveedor. «Por completar» indica un campo sin valor en el catálogo actual.</p>
</section><?php endif;?></main><dialog id="detalle"><div class="modalhead"><h2>Detalle del producto</h2><button id="cerrarDetalle" aria-label="Cerrar">×</button></div><div class="modalbody" id="contenidoDetalle"></div></dialog><?php if($puedeOperarPos):?>
<dialog id="gydCatalogoModal" class="gyd-modal"><div class="modalhead"><h2 id="gydNombreProducto">Configurar producto</h2><button id="gydCerrarCatalogo" type="button" aria-label="Cerrar">×</button></div><div class="modalbody">
 <p class="gyd-help"><b>Configuración centralizada.</b> El precio y la presentación son por sucursal; las existencias de Tapachula/Toscana se comparten. El conteo físico de Farmacia solo corresponde a Ciudad Hidalgo y Tuxtla; el mínimo ya no se usa para vender ni generar solicitudes.</p>
 <label style="display:block;font-weight:700">Sucursal
 <select id="gydSucursalPos" class="gyd-opt"><option value="1">Ciudad Hidalgo</option><option value="2">Tuxtla Gutiérrez</option><option value="3">Tapachula</option><option value="4">Plaza Toscana</option></select></label>
 <p class="gyd-help" id="gydStockPos">Stock general POS: — (solo consulta)</p>
 <section class="gyd-sep"><h3>Precio público y presentación de venta</h3>
 <form method="post" id="gydPrecioForm"><input type="hidden" name="accion" value="guardar_precio_pos"><input type="hidden" name="csrf" value="<?=esc($csrf)?>"><input type="hidden" name="pagina" value="<?=$pagina?>"><input type="hidden" name="producto_id"><input type="hidden" name="sucursal"><input type="hidden" name="precio_original"><input type="hidden" name="modalidad_original"><input type="hidden" name="piezas_original">
 <div class="gyd-pair"><label>Precio por pieza (MXN)<input name="precio_publico" type="number" min="0.01" max="99999999.99" step="0.01" required></label><label>Forma de venta<select name="modalidad"><option value="PIEZA">Solo pieza</option><option value="CAJA">Solo caja</option><option value="AMBAS">Pieza y caja</option></select></label><label>Piezas por caja<input name="piezas_por_caja" type="number" min="1" max="100000" step="1" required></label><label>Precio por caja calculado<input id="gydPrecioCaja" readonly value="—" aria-label="Precio calculado por caja"></label></div>
 <p class="gyd-help">El precio por caja se obtiene multiplicando precio por pieza × piezas por caja. Guardar nunca modifica existencias.</p><label class="gyd-help">Motivo del cambio<input class="gyd-opt" name="motivo" minlength="6" maxlength="250" placeholder="Ej. Cambio comercial autorizado" required></label>
 <div class="editactions"><button type="submit" class="btn">Guardar precio y presentación</button></div></form></section>
 <section id="gydConteoSection" class="gyd-sep"><h3>Conteo físico de Farmacia</h3>
 <p class="gyd-help">Solo Ciudad Hidalgo y Tuxtla: al guardar este conteo, el GENERAL se recalcula como Farmacia + Almacén. No se cambian las piezas de Bodega, ni se generan peticiones. Verifica físicamente las cantidades antes de guardar.</p>
 <form method="post" id="gydConteoForm"><input type="hidden" name="accion" value="guardar_conteo_farmacia"><input type="hidden" name="csrf" value="<?=esc($csrf)?>"><input type="hidden" name="pagina" value="<?=$pagina?>"><input type="hidden" name="producto_id"><input type="hidden" name="sucursal"><input type="hidden" name="existencia_original"><input type="hidden" name="minimo_original">
 <div class="gyd-pair"><label>Piezas físicas contadas en Farmacia<input type="number" name="existencia" min="0" max="10000000" required></label><label>Almacén (consulta)<input id="gydStockAlmacen" readonly value="—"></label></div><p id="gydTotalConciliado" class="gyd-help">Total general resultante: —</p>
 <label class="gyd-help">Motivo o referencia del conteo<input name="motivo" class="gyd-opt" minlength="5" maxlength="250" placeholder="Ej. Conteo físico y validación" required></label>
 <div class="editactions"><button class="btn" type="submit">Guardar conteo y conciliar general</button></div></form></section>
 </div></dialog>
<?php endif;?><?php if($puedeEditar):?><dialog id="editor"><div class="modalhead"><h2>Editar producto</h2><button type="button" id="cerrarEditor" aria-label="Cerrar">×</button></div><form method="post" class="modalbody" id="formEditar"><input type="hidden" name="accion" value="editar_producto"><input type="hidden" name="csrf" value="<?=esc($csrf)?>"><input type="hidden" name="producto_id" id="editarId"><input type="hidden" name="pagina" value="<?=$pagina?>"><div class="editgrid"><label class="wide">Descripción <input id="editarDescripcion" name="descripcion" maxlength="255" required></label><label>Código de barras<input id="editarCodigo" name="codigo_barras" maxlength="150"></label><label>Laboratorio<input id="editarLaboratorio" name="laboratorio" maxlength="120"></label><label>Categoría<select id="editarCategoria" name="categoria_id"><option value="">Por completar</option><?php foreach($categorias as $c):?><option value="<?=esc($c['id'])?>"><?=esc($c['nombre'])?></option><?php endforeach;?></select></label><label>Proveedor<select id="editarProveedor" name="proveedor_id"><option value="">Por completar</option><?php foreach($proveedores as $pr):?><option value="<?=esc($pr['id'])?>"><?=esc($pr['nombre'])?></option><?php endforeach;?></select></label><label class="wide">Unidad de medida<input id="editarUnidad" name="unidad_medida" maxlength="50" placeholder="Ej. PIEZA"></label></div>
<fieldset class="fiscal-fields"><legend>Impuestos del Catálogo Maestro</legend><div class="editgrid"><label>IVA compra (%)<input id="editarIvaCompra" name="iva_compra" type="number" min="0" max="100" step="0.01" inputmode="decimal" placeholder="Sin configurar"></label><label>IVA venta (%)<input id="editarIvaVenta" name="iva_venta" type="number" min="0" max="100" step="0.01" inputmode="decimal" placeholder="Sin configurar"></label><label class="wide">IEPS (%)<input id="editarIeps" name="ieps" type="number" min="0" max="100" step="0.01" inputmode="decimal" placeholder="Sin configurar"></label></div><p class="small">El 0% es una tasa configurada; dejarlo vacío significa sin configurar. El IVA de compra alimenta las Órdenes de Compra. Verifica el tratamiento fiscal de cada artículo antes de guardar; no se aplica automáticamente el 16%.</p></fieldset>
<p class="small">Los precios, costos, inventarios y equivalencias de cajas se administran en sus módulos correspondientes. Esta operación no modifica facturas históricas ni POS.</p><p class="small"><a id="enlaceFichaFiscal" href="erp_producto_alta.php" target="_blank" rel="noopener">Abrir ficha fiscal completa ↗</a></p><div class="editactions"><button class="btn secondary" type="button" id="cancelarEditor">Cancelar</button><button class="btn" type="submit">Guardar cambios</button></div></form></dialog><?php endif;?><script>
const dlg=document.getElementById('detalle');document.querySelectorAll('[data-producto]').forEach(btn=>btn.addEventListener('click',()=>{let d;try{d=JSON.parse(btn.dataset.producto)}catch{return}const cont=document.getElementById('contenidoDetalle');cont.replaceChildren();Object.entries(d).forEach(([k,v])=>{const row=document.createElement('div');row.className='detailrow';const label=document.createElement('span');label.textContent=k;const value=document.createElement('strong');value.textContent=v;row.append(label,value);cont.append(row)});dlg.showModal()}));document.getElementById('cerrarDetalle').addEventListener('click',()=>dlg.close());
<?php if($puedeEditar):?>const ed=document.getElementById('editor');document.querySelectorAll('[data-editar]').forEach(btn=>btn.addEventListener('click',()=>{const x=JSON.parse(btn.dataset.editar);for(const [key,id] of [['id','editarId'],['descripcion','editarDescripcion'],['codigo_barras','editarCodigo'],['laboratorio','editarLaboratorio'],['categoria_id','editarCategoria'],['proveedor_id','editarProveedor'],['unidad_medida','editarUnidad'],['iva_compra','editarIvaCompra'],['iva_venta','editarIvaVenta'],['ieps','editarIeps']])document.getElementById(id).value=x[key]??'';document.getElementById('enlaceFichaFiscal').href='erp_producto_alta.php?id='+encodeURIComponent(String(x.id));ed.showModal()}));document.getElementById('cerrarEditor').onclick=()=>ed.close();document.getElementById('cancelarEditor').onclick=()=>ed.close();<?php endif;?>
</script>
<script>
<?php if($puedeOperarPos):?>
function gydTotalEstimado(){const sid=Number(document.getElementById('gydSucursalPos').value);const d=(gydCatalogoDatos[gydPid]||{})[sid]||{};const f=Number(document.getElementById('gydConteoForm').elements.namedItem('existencia').value)||0;document.getElementById('gydTotalConciliado').textContent='General resultante = '+f+' (Farmacia) + '+Number(d.almacen||0)+' (Almacén) = '+(f+Number(d.almacen||0))+' piezas.';}
const gydCatalogoDatos=<?=json_encode($gydDatos??[],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const gydModal=document.getElementById('gydCatalogoModal'),gydPrecio=document.getElementById('gydPrecioForm'),gydConteo=document.getElementById('gydConteoForm'),gydSuc=document.getElementById('gydSucursalPos');let gydPid=0;
function gydActualizarCaja(){const p=Number(gydPrecio.elements.namedItem('precio_publico').value),f=Number(gydPrecio.elements.namedItem('piezas_por_caja').value);document.getElementById('gydPrecioCaja').value=Number.isFinite(p*f)&&p>0&&Number.isInteger(f)&&f>0?'$'+(p*f).toLocaleString('es-MX',{minimumFractionDigits:2,maximumFractionDigits:2}):'—';}
function gydCargarSucursal(){const sid=Number(gydSuc.value),data=(gydCatalogoDatos[gydPid]||{})[sid]||{};
 const price=data.precio??'',modo=data.modalidad??'',piezas=Number(data.piezas)||0;
 gydPrecio.elements.namedItem('producto_id').value=String(gydPid);gydPrecio.elements.namedItem('sucursal').value=String(sid);
 gydPrecio.elements.namedItem('precio_publico').value=price;gydPrecio.elements.namedItem('precio_original').value=price;
 gydPrecio.elements.namedItem('modalidad').value=['PIEZA','CAJA','AMBAS'].includes(modo)?modo:'PIEZA';gydPrecio.elements.namedItem('modalidad_original').value=modo;
 gydPrecio.elements.namedItem('piezas_por_caja').value=piezas||1;gydPrecio.elements.namedItem('piezas_original').value=String(piezas);
 gydPrecio.elements.namedItem('motivo').value='';gydActualizarCaja();
 document.getElementById('gydStockPos').textContent='Existencia general POS: '+(data.stock==null?'Sin saldo configurado':Number(data.stock).toLocaleString('es-MX')+' piezas')+(sid===3||sid===4?' · Inventario compartido Tapachula/Toscana':'')+'. Esta cantidad es solo de consulta.';
 const mostrar=sid===1||sid===2;document.getElementById('gydConteoSection').hidden=!mostrar;
 gydConteo.elements.namedItem('producto_id').value=String(gydPid);gydConteo.elements.namedItem('sucursal').value=String(sid);
 gydConteo.elements.namedItem('existencia').value=data.fisico??0;document.getElementById('gydStockAlmacen').value=Number(data.almacen||0)+' piezas';gydTotalEstimado();
 gydConteo.elements.namedItem('existencia_original').value=data.fisico==null?'':String(data.fisico);gydConteo.elements.namedItem('minimo_original').value=data.minimo==null?'':String(data.minimo);
 gydConteo.elements.namedItem('motivo').value='';
}
document.querySelectorAll('.gyd-ficha-pos').forEach(btn=>btn.addEventListener('click',()=>{gydPid=Number(btn.dataset.id);document.getElementById('gydNombreProducto').textContent='Ficha comercial: '+btn.dataset.nombre;gydSuc.value='1';gydCargarSucursal();gydModal.showModal();}));
gydSuc.addEventListener('change',gydCargarSucursal);gydConteo.elements.namedItem('existencia').addEventListener('input',gydTotalEstimado);['precio_publico','piezas_por_caja'].forEach(n=>gydPrecio.elements.namedItem(n).addEventListener('input',gydActualizarCaja));
document.getElementById('gydCerrarCatalogo').onclick=()=>gydModal.close();
gydPrecio.addEventListener('submit',e=>{if(!confirm('¿Guardar precio y presentación en esta sucursal? No cambiará el inventario.'))e.preventDefault();});
gydConteo.addEventListener('submit',e=>{if(!confirm('¿Confirmas el conteo físico? Se actualizará la existencia general a Farmacia + Almacén, dejando registro de auditoría.'))e.preventDefault();});
<?php endif;?>
</script>
<script src="assets/js/gyd_feedback_modal.js?v=5" defer></script>

<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=4">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=4"></script>
<?php endif; ?>
</body></html>
