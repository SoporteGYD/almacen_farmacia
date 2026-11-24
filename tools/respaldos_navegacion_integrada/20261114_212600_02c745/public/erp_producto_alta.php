<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/pos_inteligente.php'; // GYD_F1_POOL_TT_20261103
require_once __DIR__.'/../app/helpers/erp_productos_unicos.php';
requireLogin();
$usuario=currentUser();$rol=strtoupper(trim((string)($usuario['rol']??'')));
if(!in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION','DUEÑO','DUENO','COMPRAS'],true)){http_response_code(403);exit('Solo Compras / Administración / Dirección puede administrar la ficha maestra.');}
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
if(empty($_SESSION['gyd_ficha_csrf']))$_SESSION['gyd_ficha_csrf']=bin2hex(random_bytes(24));
$token=$_SESSION['gyd_ficha_csrf'];
function h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function limite($v,int $n):string{return mb_substr(trim((string)$v),0,$n,'UTF-8');}
function optionSel($a,$b):string{return (string)$a===(string)$b?'selected':'';}
$pdo=(new Database())->connect();
$categorias=$pdo->query('SELECT id,nombre FROM categorias WHERE estado=1 ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
$proveedores=$pdo->query('SELECT id,nombre FROM proveedores WHERE estado=1 ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
$id=max(0,(int)($_GET['id']??$_POST['id']??0));$actual=null;$ficha=[];$equiv=[];$alts=[];$error='';$guardado=false;
if($id>0){$q=$pdo->prepare('SELECT * FROM productos WHERE id=?');$q->execute([$id]);$actual=$q->fetch(PDO::FETCH_ASSOC)?:null;if(!$actual){http_response_code(404);exit('Producto inexistente.');}
  $q=$pdo->prepare('SELECT * FROM erp_producto_ficha WHERE producto_id=?');$q->execute([$id]);$ficha=$q->fetch(PDO::FETCH_ASSOC)?:[];
  $q=$pdo->prepare('SELECT codigo FROM erp_producto_equivalencias WHERE producto_id=? ORDER BY id');$q->execute([$id]);$equiv=$q->fetchAll(PDO::FETCH_COLUMN);
  $q=$pdo->prepare('SELECT proveedor_id FROM erp_producto_proveedores WHERE producto_id=? ORDER BY prioridad');$q->execute([$id]);$alts=$q->fetchAll(PDO::FETCH_COLUMN);
}
if(($_GET['api']??'')==='comprobar'){
 header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
 $cod=limite($_GET['codigo']??'',100);$bar=limite($_GET['codigo_barras']??'',150);$desc=limite($_GET['descripcion']??'',255);
 $hit=gyd_producto_duplicado($pdo,$cod,$bar,$desc,$id?:null);
 echo json_encode(['duplicado'=>$hit!==null,'registro'=>$hit,'mensaje'=>$hit?gyd_duplicado_mensaje($hit):'Sin coincidencias por código, barras o descripción.'],JSON_UNESCAPED_UNICODE);exit;
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!hash_equals($token,(string)($_POST['csrf']??''))){http_response_code(400);$error='Sesión de seguridad caducada.';}
 elseif(($_POST['action']??'')!=='guardar'){$error='Acción inválida.';}
 else{
   $codigo=limite($_POST['codigo']??'',100);$barras=limite($_POST['codigo_barras']??'',150);
   $nombre=limite($_POST['nombre']??'',255);$cat=($_POST['categoria_id']??'')!==''?(int)$_POST['categoria_id']:null;
   $pr=($_POST['proveedor_id']??'')!==''?(int)$_POST['proveedor_id']:null;
   $lab=limite($_POST['laboratorio']??'',120);
   $un=limite($_POST['unidad_medida']??'PZA',50);$costo=(string)($_POST['costo_inicial']??'0');$precio=(string)($_POST['precio_inicial']??'0');
   $fields=['tipo'=>70,'subfamilia'=>120,'descripcion_ampliada'=>1500,'unidad_compra'=>20,'unidad_venta'=>20,'unidad_detallista'=>20,'unidad_auxiliar'=>20,'observaciones'=>2000,'imagen_referencia'=>255];
   $vals=[];foreach($fields as $k=>$max)$vals[$k]=limite($_POST[$k]??'',$max);
   $decFields=['factor_compra'=>1,'factor_venta'=>1,'factor_detallista'=>null,'factor_auxiliar'=>null,'iva_compra'=>null,'iva_venta'=>null,'ieps'=>null,'margen_sugerido'=>null,'precio_sugerido'=>null,'piezas_cambio_precio'=>null,'descuento_1'=>null,'descuento_2'=>null,'descuento_3'=>null,'descuento_4'=>null,'descuento_general'=>null,'costo_flete_unitario'=>null,'puntos_monto_compra'=>null,'puntos_otorgar'=>null,'puntos_canje'=>null];
   foreach($decFields as $k=>$default){$raw=trim((string)($_POST[$k]??''));$vals[$k]=$raw===''?$default:$raw;}
   foreach(['no_inventariable','es_compuesto','rastreo_lote_caducidad','numeros_serie','precio_libre','precio_costo_promedio'] as $k)$vals[$k]=isset($_POST[$k])?1:0;
   $vals['metodo_precio']=($_POST['metodo_precio']??'')==='MARK_DOWN'?'MARK_DOWN':'MARK_UP';
   $equivRaw=preg_split('/[\r\n,;]+/u',(string)($_POST['equivalentes']??''))?:[];
   $equivN=[];foreach($equivRaw as $x){$x=gyd_normalizar_codigo($x);if($x!=='')$equivN[]=$x;}$equivN=array_values(array_unique($equivN));
   $altIds=array_values(array_unique(array_map('intval',(array)($_POST['proveedores_adicionales']??[]))));$altIds=array_filter($altIds,fn($x)=>$x>0 && $x!==$pr);
   $validCat=array_map(fn($x)=>(int)$x['id'],$categorias);$validPr=array_map(fn($x)=>(int)$x['id'],$proveedores);
   if(!$actual && $codigo==='')$error='El código principal es obligatorio.';
   elseif($nombre==='')$error='El nombre de artículo es obligatorio.';
   elseif($cat!==null&&!in_array($cat,$validCat,true))$error='Categoría inválida.';
   elseif($pr!==null&&!in_array($pr,$validPr,true))$error='Proveedor inválido.';
   elseif(array_diff($altIds,$validPr))$error='Hay proveedores adicionales inválidos.';
   elseif(count($equivN)>25)$error='Máximo 25 códigos equivalentes.';
   elseif(!$actual && $vals['iva_compra']===null)$error='Indica el IVA de compra en Otros impuestos antes de dar de alta el producto (0% es válido si corresponde).';
   elseif(!is_numeric($costo)||!is_numeric($precio)||(float)$costo<0||(float)$precio<0||(float)$costo>9999999||(float)$precio>9999999)$error='Costo o precio inicial inválido.';
   else{
     foreach(['factor_compra','factor_venta','factor_detallista','factor_auxiliar'] as $k){if($vals[$k]!==null&&(!is_numeric($vals[$k])||(float)$vals[$k]<=0||(float)$vals[$k]>1000000))$error='Relaciones de unidades inválidas.';}
     foreach(['iva_compra','iva_venta','ieps','margen_sugerido','precio_sugerido','descuento_1','descuento_2','descuento_3','descuento_4','descuento_general','costo_flete_unitario','puntos_monto_compra'] as $k){if($vals[$k]!==null&&(!is_numeric($vals[$k])||(float)$vals[$k]<0||(float)$vals[$k]>10000000))$error='Impuestos, margen o precio sugerido inválidos.';}
     foreach(['piezas_cambio_precio','puntos_otorgar','puntos_canje'] as $k){if($vals[$k]!==null&&(!ctype_digit((string)$vals[$k])||(int)$vals[$k]>1000000))$error='Cantidad de unidades o puntos inválida.';}
     foreach(['iva_compra','iva_venta','ieps','descuento_1','descuento_2','descuento_3','descuento_4','descuento_general'] as $k){if($vals[$k]!==null&&((float)$vals[$k]>100))$error='El porcentaje no puede superar 100.';}
     if(in_array($codigo,$equivN,true)||($barras!==''&&in_array(gyd_normalizar_codigo($barras),$equivN,true)))$error='Un código equivalente no puede repetir el código principal o las barras del mismo artículo.';
   }
   $bloqueo=false;
   if($error===''){
    try{
      $bloqueo=(int)$pdo->query("SELECT GET_LOCK('gyd_catalogo_producto_write',10)")->fetchColumn()===1;
      if(!$bloqueo)throw new RuntimeException('Otro usuario está registrando un producto. Intenta nuevamente.');
      $pdo->beginTransaction();
      if($actual){
       $stmt=$pdo->prepare('SELECT * FROM productos WHERE id=? FOR UPDATE');$stmt->execute([$id]);$vigente=$stmt->fetch(PDO::FETCH_ASSOC);
       if(!$vigente)throw new RuntimeException('El artículo ya no existe.');
       $codigo=(string)$vigente['codigo']; // el código principal nunca se altera desde la ficha.
      }else{$vigente=null;}
      $dup=gyd_producto_duplicado($pdo,$codigo,$barras,$nombre,$id?:null);
      if($dup)throw new RuntimeException(gyd_duplicado_mensaje($dup));
      foreach($equivN as $eq){
       $hit=gyd_producto_duplicado($pdo,$eq,$eq,'',$id?:null);
       if($hit)throw new RuntimeException('El equivalente '.$eq.' ya pertenece a otro producto #'.$hit['id']);
       if($actual && ($eq===gyd_normalizar_codigo($codigo)||$eq===gyd_normalizar_codigo($barras)))throw new RuntimeException('Equivalente ya usado por este producto.');
      }
      if(!$actual){
       $st=$pdo->prepare("INSERT INTO productos(codigo,codigo_barras,descripcion,categoria_id,proveedor_id,laboratorio,unidad_medida,precio_compra,costo_ultimo,costo_promedio,precio_venta,stock_minimo,stock_maximo,ubicacion,existencia_bodega,sucursal,estado) VALUES(?,?,?,?,?,?,?,?,?,?,?,0,0,NULL,0,NULL,1)");
       $st->execute([$codigo,$barras!==''?$barras:null,$nombre,$cat,$pr,$lab!==''?$lab:null,$un!==''?$un:null,$costo,$costo,$costo,$precio]);$id=(int)$pdo->lastInsertId();
      }else{
       $st=$pdo->prepare('UPDATE productos SET codigo_barras=?,descripcion=?,categoria_id=?,proveedor_id=?,laboratorio=?,unidad_medida=? WHERE id=?');
       $st->execute([$barras!==''?$barras:null,$nombre,$cat,$pr,$lab!==''?$lab:null,$un!==''?$un:null,$id]);
      }
      $cols=array_keys($vals);$values=array_values($vals);
      $insertCols='producto_id,'.implode(',',$cols).',creado_por,actualizado_por';
      $insertMarks=implode(',',array_fill(0,count($cols)+3,'?'));
      $upCols=implode(',',array_map(fn($x)=>$x.'=VALUES('.$x.')',$cols));
      $st=$pdo->prepare('INSERT INTO erp_producto_ficha('.$insertCols.') VALUES ('.$insertMarks.') ON DUPLICATE KEY UPDATE '.$upCols.',actualizado_por=VALUES(actualizado_por)');
      $st->execute(array_merge([$id],$values,[(int)($usuario['id']??0)?:null,(int)($usuario['id']??0)?:null]));
      $pdo->prepare('DELETE FROM erp_producto_equivalencias WHERE producto_id=?')->execute([$id]);
      $st=$pdo->prepare('INSERT INTO erp_producto_equivalencias(producto_id,codigo) VALUES(?,?)');foreach($equivN as $v)$st->execute([$id,$v]);
      $pdo->prepare('DELETE FROM erp_producto_proveedores WHERE producto_id=?')->execute([$id]);
      $st=$pdo->prepare('INSERT INTO erp_producto_proveedores(producto_id,proveedor_id,prioridad) VALUES(?,?,?)');$i=1;foreach($altIds as $v)$st->execute([$id,$v,$i++]);
      $datos=['codigo'=>$codigo,'barras'=>$barras,'nombre'=>$nombre,'categoria'=>$cat,'proveedor'=>$pr,'ficha'=>$vals,'equivalentes'=>$equivN,'proveedores_adicionales'=>$altIds];
      $st=$pdo->prepare('INSERT INTO erp_producto_historial_ficha(producto_id,usuario_id,accion,datos_anteriores,datos_nuevos) VALUES(?,?,?,?,?)');
      $st->execute([$id,(int)($usuario['id']??0)?:null,$actual?'ACTUALIZAR':'ALTA',$vigente?json_encode($vigente,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE):null,json_encode($datos,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)]);
      $pdo->commit();$guardado=true;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e instanceof RuntimeException?$e->getMessage():'No fue posible guardar. Revisa duplicados, estructura SQL y registro de errores.';error_log('G&D ficha producto: '.$e->getMessage());}
    finally{if($bloqueo){try{$pdo->query("SELECT RELEASE_LOCK('gyd_catalogo_producto_write')");}catch(Throwable $ignore){}}}
   }
   if($guardado){header('Location: erp_producto_alta.php?id='.$id.'&guardado=1');exit;}
   // conservar los valores capturados cuando hay error.
   $ficha=$vals;
 }
}
$actual=$id?($pdo->prepare('SELECT * FROM productos WHERE id=?')):null;
if($actual instanceof PDOStatement){$actual->execute([$id]);$actual=$actual->fetch(PDO::FETCH_ASSOC)?:null;}
if($actual && $_SERVER['REQUEST_METHOD']!=='POST'){
 $st=$pdo->prepare('SELECT * FROM erp_producto_ficha WHERE producto_id=?');$st->execute([$id]);$ficha=$st->fetch(PDO::FETCH_ASSOC)?:[];
 $st=$pdo->prepare('SELECT codigo FROM erp_producto_equivalencias WHERE producto_id=? ORDER BY id');$st->execute([$id]);$equiv=$st->fetchAll(PDO::FETCH_COLUMN);
 $st=$pdo->prepare('SELECT proveedor_id FROM erp_producto_proveedores WHERE producto_id=?');$st->execute([$id]);$alts=$st->fetchAll(PDO::FETCH_COLUMN);
}
function vf(string $key,$default=''){global $ficha;return $ficha[$key]??$default;}
$stocksPOS=[];$stocksAL=[];$history=[];$ventasPOS=[];
if($id>0){
 try{$st=$pdo->prepare('SELECT s.nombre AS sucursal,s.id sid,x.existencia FROM pos_existencias_farmacia x INNER JOIN pos_sucursales s ON s.id=x.sucursal_id WHERE x.producto_id=? ORDER BY s.nombre');$st->execute([$id]);$stocksPOS=$st->fetchAll(PDO::FETCH_ASSOC);if(pi_tt_activo($pdo)){foreach($stocksPOS as &$r){if((int)$r['sid']===3){$r['sucursal']='TAPACHULA / TOSCANA (saldo único)';}elseif((int)$r['sid']===4){$r['existencia']='Ver saldo compartido';}}unset($r);}}catch(Throwable $e){error_log('POS stock ficha: '.$e->getMessage());}
 try{$st=$pdo->prepare('SELECT sucursal,ubicacion,existencia FROM producto_existencias WHERE producto_id=? ORDER BY sucursal,ubicacion');$st->execute([$id]);$stocksAL=$st->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){error_log('Almacén stock ficha: '.$e->getMessage());}
 try{
  $st=$pdo->prepare("SELECT s.nombre AS sucursal,COUNT(DISTINCT v.id) AS tickets,COALESCE(SUM(d.cantidad),0) AS piezas,COALESCE(SUM(d.total_renglon),0) AS total FROM pos_venta_detalle d INNER JOIN pos_ventas v ON v.id=d.venta_id INNER JOIN pos_sucursales s ON s.id=v.sucursal_id WHERE d.producto_id=? AND v.estado='PAGADA' GROUP BY s.id,s.nombre ORDER BY s.nombre");$st->execute([$id]);$ventasPOS=$st->fetchAll(PDO::FETCH_ASSOC);
 }catch(Throwable $e){error_log('Ventas por producto ficha: '.$e->getMessage());}
 $st=$pdo->prepare('SELECT fecha,accion,usuario_id FROM erp_producto_historial_ficha WHERE producto_id=? ORDER BY id DESC LIMIT 15');$st->execute([$id]);$history=$st->fetchAll(PDO::FETCH_ASSOC);
}
$nombreDef=(string)($actual['descripcion']??'');$codigoDef=(string)($actual['codigo']??'');$barraDef=(string)($actual['codigo_barras']??'');
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D | Ficha maestra WinCaja</title><link rel="stylesheet" href="assets/css/erp_ficha_win.css"></head>
<body><header class="head"><div><strong>Distribución G&D · ERP</strong><span>Catálogo Maestro / Ficha del artículo</span></div><div class="headright"><a href="erp_catalogo_maestro.php">← Catálogo</a><a href="productos.php">Productos de Almacén</a></div></header>
<main><div class="bar"><div><h1><?= $id?'Editar ficha maestra #'.(int)$id:'Alta de nuevo artículo' ?></h1><p>Misma referencia para Compras y Almacén · Inventarios independientes · Sin duplicar productos</p></div><div class="actions"><button form="frm" type="submit" class="primary">✓ Guardar</button><button type="button" id="clear" class="secondary">Borrar captura</button><a href="erp_catalogo_maestro.php" class="secondary">Cancelar / Salir</a></div></div>
<?php if($error):?><div class="alert bad" role="alert"><?=h($error)?></div><?php endif;?>
<?php if($actual && (int)($actual['estado']??1)===0):?><div class="alert warn">Este artículo ya existe pero está INACTIVO. Se conserva su ID e historial; reactívalo desde el módulo de Productos si corresponde, sin crear otro registro.</div><?php endif;?>
<?php if(($_GET['guardado']??'')==='1'):?><div class="alert good">Ficha guardada. El producto es único y aparece en Compras y Almacén. No se añadió ni modificó ninguna existencia.</div><?php endif;?>
<div id="duplicado" class="alert warn" hidden></div>
<div class="alert soft"><b>Inventarios separados:</b> el alta crea únicamente la ficha general con existencia inicial <strong>0</strong>. El stock de Farmacia/POS y el de Almacén se administran por sus movimientos autorizados; no se sincronizan ni se suman automáticamente.</div>
<form id="frm" method="post" autocomplete="off"><input type="hidden" name="action" value="guardar"><input type="hidden" name="csrf" value="<?=h($token)?>"><input type="hidden" name="id" value="<?= (int)$id ?>">
<section class="toparea"><div class="fields"><label><span>Artículo (código) *</span><input id="codigo" name="codigo" maxlength="100" value="<?=h($_POST['codigo']??$codigoDef)?>" <?= $id?'readonly':'' ?> required></label><label><span>Código de barras (opcional)</span><input id="codigo_barras" name="codigo_barras" maxlength="150" value="<?=h($_POST['codigo_barras']??$barraDef)?>"></label><label><span>Tipo</span><input name="tipo" value="<?=h(vf('tipo'))?>" maxlength="70" placeholder="Medicamento / Abarrotes..."></label><label><span>Subfamilia</span><input name="subfamilia" value="<?=h(vf('subfamilia'))?>" maxlength="120"></label><label><span>Categoría</span><select name="categoria_id"><option value="">Selecciona categoría</option><?php foreach($categorias as $c):?><option value="<?=$c['id']?>" <?= optionSel($_POST['categoria_id']??($actual['categoria_id']??''),$c['id'])?>><?=h($c['nombre'])?></option><?php endforeach;?></select></label><label><span>Proveedor principal</span><select name="proveedor_id"><option value="">Sin proveedor</option><?php foreach($proveedores as $p):?><option value="<?=$p['id']?>" <?= optionSel($_POST['proveedor_id']??($actual['proveedor_id']??''),$p['id'])?>><?=h($p['nombre'])?></option><?php endforeach;?></select></label><label class="full"><span>Nombre / descripción comercial *</span><input id="nombre" name="nombre" maxlength="255" value="<?=h($_POST['nombre']??$nombreDef)?>" required placeholder="Descripción única en Compras y Almacén"></label><label class="full"><span>Descripción ampliada / presentación</span><textarea name="descripcion_ampliada" rows="2"><?=h(vf('descripcion_ampliada'))?></textarea></label><label class="full"><span>Laboratorio / marca</span><input name="laboratorio" value="<?=h($_POST['laboratorio']??($actual['laboratorio']??''))?>"></label></div>
<div class="fields"><h3>Unidades y relaciones</h3><div class="unitsgrid"><span></span><b>Compra</b><b>Venta</b><b>Detallista</b><b>Auxiliar</b><b>Unidad</b><?php foreach(['compra','venta','detallista','auxiliar'] as $k):?><input aria-label="Unidad <?=$k?>" name="unidad_<?=$k?>" value="<?=h(vf('unidad_'.$k,'PZA'))?>" maxlength="20"><?php endforeach;?><b>Relación</b><?php foreach(['compra','venta','detallista','auxiliar'] as $k):?><input aria-label="Factor <?=$k?>" type="number" min="0.0001" step="0.0001" name="factor_<?=$k?>" value="<?=h(vf('factor_'.$k,in_array($k,['compra','venta'],true)?'1':''))?>"><?php endforeach;?></div>
<label><span>Unidad principal de catálogo</span><input name="unidad_medida" value="<?=h($_POST['unidad_medida']??($actual['unidad_medida']??'PZA'))?>" maxlength="50"></label>
<div class="options"><h3>Opciones del artículo</h3><?php foreach(['numeros_serie'=>'Números de serie','precio_libre'=>'Precio siempre libre','no_inventariable'=>'No inventariable','es_compuesto'=>'Es compuesto','rastreo_lote_caducidad'=>'Existencias por lote y caducidad','precio_costo_promedio'=>'Calcular precio con costo promedio'] as $k=>$txt):?><label class="check"><input type="checkbox" name="<?=$k?>" value="1" <?= (int)vf($k)===1?'checked':'' ?>><?=h($txt)?></label><?php endforeach;?><p>Estas opciones son metadatos descriptivos; no activan por sí solas funciones de punto de venta.</p></div></div></section>
<nav class="tabs" aria-label="Secciones de la ficha"><button class="tab active" data-tab="precios" type="button">Precios</button><button class="tab" data-tab="descuentos" type="button">Descuentos y flete</button><button class="tab" data-tab="estadisticas" type="button">Estadísticas de ventas</button><button class="tab" data-tab="observaciones" type="button">Observaciones</button><button class="tab" data-tab="equivalentes" type="button">Códigos equivalentes</button><button class="tab" data-tab="proveedores" type="button">Proveedores</button><button class="tab" data-tab="impuestos" type="button">Otros impuestos</button><button class="tab" data-tab="existencias" type="button">Existencias</button><button class="tab" data-tab="historial" type="button">Historial</button></nav>
<section class="tabcontent active" id="panel-precios"><h2>Precios y costos</h2><p class="hint">En una ficha nueva puedes indicar costo y precio inicial sin registrar piezas. Al editar, los valores vigentes son de solo lectura; los cambios efectivos se realizan desde sus módulos autorizados.</p><div class="grid4">
<label><span>Costo inicial (solo nuevo)</span><input name="costo_inicial" type="number" min="0" step="0.01" value="<?=h($_POST['costo_inicial']??($actual['precio_compra']??'0.00'))?>" <?= $id?'readonly':''?>></label><label><span>Precio de venta inicial (solo nuevo)</span><input name="precio_inicial" type="number" min="0" step="0.01" value="<?=h($_POST['precio_inicial']??($actual['precio_venta']??'0.00'))?>" <?=$id?'readonly':''?>></label><label><span>% margen sugerido</span><input name="margen_sugerido" type="number" min="0" step="0.01" value="<?=h(vf('margen_sugerido'))?>"></label><label><span>Precio sugerido (no se aplica al POS)</span><input name="precio_sugerido" type="number" min="0" step="0.01" value="<?=h(vf('precio_sugerido'))?>"></label><label><span>Método de cálculo</span><select name="metodo_precio"><option value="MARK_UP" <?=optionSel(vf('metodo_precio','MARK_UP'),'MARK_UP')?>>Mark up</option><option value="MARK_DOWN" <?=optionSel(vf('metodo_precio'),'MARK_DOWN')?>>Mark down</option></select></label><label><span>Cantidad para cambio de precio</span><input name="piezas_cambio_precio" type="number" min="0" step="1" value="<?=h(vf('piezas_cambio_precio'))?>"></label></div>
<?php if($actual):?><div class="readonly"><b>Costo último actual:</b> $<?=number_format((float)($actual['costo_ultimo']??$actual['precio_compra']??0),2)?> · <b>Costo promedio:</b> $<?=number_format((float)($actual['costo_promedio']??0),4)?> · <b>Precio base:</b> $<?=number_format((float)($actual['precio_venta']??0),2)?></div><?php endif;?></section>
<section class="tabcontent" id="panel-descuentos"><h2>Descuentos y flete</h2><p class="hint">Parámetros de compra registrados en ficha. No cambian precios, costos del Kardex ni facturas existentes.</p><div class="grid4"><?php foreach(['descuento_1'=>'Descuento 1 %','descuento_2'=>'Descuento 2 %','descuento_3'=>'Descuento 3 %','descuento_4'=>'Descuento 4 %','descuento_general'=>'Descuento general %','costo_flete_unitario'=>'Flete unitario $'] as $k=>$caption):?><label><span><?=h($caption)?></span><input type="number" min="0" step="0.01" name="<?=$k?>" value="<?=h(vf($k))?>"></label><?php endforeach;?></div><h3>Promoción de puntos por frecuencia (referencia)</h3><div class="grid4"><label><span>Monto de compra $</span><input type="number" name="puntos_monto_compra" min="0" step="0.01" value="<?=h(vf('puntos_monto_compra'))?>"></label><label><span>Puntos a otorgar</span><input type="number" name="puntos_otorgar" min="0" step="1" value="<?=h(vf('puntos_otorgar'))?>"></label><label><span>Puntos de canje</span><input type="number" name="puntos_canje" min="0" step="1" value="<?=h(vf('puntos_canje'))?>"></label></div></section>
<section class="tabcontent" id="panel-estadisticas"><h2>Estadísticas de ventas por sucursal</h2><p class="hint">Resumen informativo de ventas PAGADAS del POS de pruebas conectado. No incluye WinCaja ni Pharmacy Lite sin integración.</p><table><thead><tr><th>Sucursal</th><th>Tickets</th><th>Piezas</th><th>Importe de renglones</th></tr></thead><tbody><?php foreach($ventasPOS as $v):?><tr><td><?=h($v['sucursal'])?></td><td><?=h($v['tickets'])?></td><td><?=h($v['piezas'])?></td><td>$<?=number_format((float)$v['total'],2)?></td></tr><?php endforeach;?><?php if(!$ventasPOS):?><tr><td colspan="4">Sin ventas pagadas registradas para este producto.</td></tr><?php endif;?></tbody></table></section>
<section class="tabcontent" id="panel-observaciones"><h2>Observaciones</h2><textarea name="observaciones" rows="5" placeholder="Notas de Compras sobre este artículo"><?=h(vf('observaciones'))?></textarea><label><span>Referencia de imagen (ruta o URL; no se descarga)</span><input name="imagen_referencia" maxlength="255" value="<?=h(vf('imagen_referencia'))?>"></label></section>
<section class="tabcontent" id="panel-equivalentes"><h2>Códigos equivalentes</h2><p class="hint">Un código por línea. No puede coincidir con el principal, el de barras ni los códigos de otros artículos.</p><textarea name="equivalentes" rows="5" placeholder="Código 1&#10;Código 2"><?=h($_POST['equivalentes']??implode("\n",$equiv))?></textarea></section>
<section class="tabcontent" id="panel-proveedores"><h2>Proveedores alternativos</h2><p class="hint">El proveedor principal se elige arriba. Puedes seleccionar varios proveedores alternativos.</p><select name="proveedores_adicionales[]" multiple size="6" style="min-height:150px"><?php foreach($proveedores as $p):?><option value="<?=$p['id']?>" <?=in_array((int)$p['id'],array_map('intval',(array)($_POST['proveedores_adicionales']??$alts)),true)?'selected':''?>><?=h($p['nombre'])?></option><?php endforeach;?></select><p class="hint">En Windows mantén Ctrl para seleccionar varios.</p></section>
<section class="tabcontent" id="panel-impuestos"><h2>IVA / IEPS por artículo</h2><p class="hint">IVA compra: fuente oficial para órdenes de compra, incluido su importador TXT/CSV/Excel/PDF. Debe configurarse explícitamente (0% es válido, vacío significa sin configurar). IVA venta y otros impuestos no cambian automáticamente POS ni CFDI.</p><div class="grid4"><label><span>IVA compra %</span><input name="iva_compra" type="number" min="0" max="100" step="0.01" value="<?=h(vf('iva_compra'))?>" placeholder="Configurar 0, 8, 16..." <?=!$id?'required':''?>></label><label><span>IVA venta %</span><input name="iva_venta" type="number" min="0" step="0.01" value="<?=h(vf('iva_venta'))?>"></label><label><span>IEPS %</span><input name="ieps" type="number" min="0" step="0.01" value="<?=h(vf('ieps'))?>"></label></div></section>
<section class="tabcontent" id="panel-existencias"><h2>Existencias reales (solo lectura)</h2><p class="hint">No sumar ni trasladar estos saldos: corresponden a procesos distintos. El alta no escribe en ninguna tabla de stock.</p><div class="stocks"><div><h3>Farmacia / POS (inventario general por sucursal)</h3><table><thead><tr><th>Sucursal</th><th>Piezas</th></tr></thead><tbody><?php foreach($stocksPOS as $s):?><tr><td><?=h($s['sucursal'])?></td><td><?=h($s['existencia'])?></td></tr><?php endforeach;?><?php if(!$stocksPOS):?><tr><td colspan="2">Sin existencias POS registradas (0)</td></tr><?php endif;?></tbody></table></div><div><h3>Almacén (solo ubicaciones de almacén)</h3><table><thead><tr><th>Sucursal</th><th>Ubicación</th><th>Piezas</th></tr></thead><tbody><?php foreach($stocksAL as $s):?><tr><td><?=h($s['sucursal'])?></td><td><?=h($s['ubicacion']??'SIN UBICACIÓN')?></td><td><?=h($s['existencia'])?></td></tr><?php endforeach;?><?php if(!$stocksAL):?><tr><td colspan="3">Sin existencias de almacén registradas (0)</td></tr><?php endif;?></tbody></table></div></div></section>
<section class="tabcontent" id="panel-historial"><h2>Historial de ficha</h2><table><thead><tr><th>Fecha</th><th>Acción</th><th>Usuario ID</th></tr></thead><tbody><?php foreach($history as $e):?><tr><td><?=h($e['fecha'])?></td><td><?=h($e['accion'])?></td><td><?=h($e['usuario_id'])?></td></tr><?php endforeach;?><?php if(!$history):?><tr><td colspan="3">No hay ediciones registradas por este módulo.</td></tr><?php endif;?></tbody></table><p class="hint">Movimientos de inventario se consultan en sus propios módulos y Kardex.</p></section>
</form><div class="footeractions"><button form="frm" type="submit" class="primary">Guardar ficha del artículo</button><a href="erp_catalogo_maestro.php" class="secondary">Regresar al catálogo</a></div></main>
<script>
const btns=[...document.querySelectorAll('.tab')],panels=[...document.querySelectorAll('.tabcontent')];
function goTab(id){btns.forEach(x=>x.classList.toggle('active',x.dataset.tab===id));panels.forEach(x=>x.classList.toggle('active',x.id==='panel-'+id))}
btns.forEach(b=>b.addEventListener('click',()=>goTab(b.dataset.tab)));
document.getElementById('clear').addEventListener('click',()=>{if(confirm('¿Borrar únicamente lo capturado y no guardado en este formulario?'))location.href='erp_producto_alta.php<?= $id?'?id='.$id:'' ?>'});
let req=null;let tm;async function comprobar(){const cod=document.getElementById('codigo').value.trim(),bar=document.getElementById('codigo_barras').value.trim(),nom=document.getElementById('nombre').value.trim(),out=document.getElementById('duplicado');if(!cod&&!bar&&!nom){out.hidden=true;return}if(req)req.abort();req=new AbortController();const p=new URLSearchParams({api:'comprobar',codigo:cod,codigo_barras:bar,descripcion:nom});try{const x=await fetch('erp_producto_alta.php?id=<?=(int)$id?>&'+p,{signal:req.signal,cache:'no-store'});const d=await x.json();if(!x.ok)return;out.hidden=!d.duplicado;if(d.duplicado)out.textContent=d.mensaje+' Abre esa ficha para completar su información en vez de crear otra.';}catch(e){}}
['codigo','codigo_barras','nombre'].forEach(id=>document.getElementById(id).addEventListener('input',()=>{clearTimeout(tm);tm=setTimeout(comprobar,350)}));
</script>
<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=1">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=1"></script>
<?php endif; ?>
</body></html>
