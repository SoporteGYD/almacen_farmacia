<?php
declare(strict_types=1);
/** Validación G&D: integración de lectura REAL; nunca ejecuta POST en APIs operativas. */
function gvo_h(mixed $v): string {return htmlspecialchars((string)($v??''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');}
function gvo_authorized(array $u): bool {
    return (int)($u['id']??0)>0 && in_array(strtoupper(trim((string)($u['rol_original']??$u['rol']??''))),['ADMIN','ADMINISTRADOR','DIRECCION','DUEÑO','DUENO','PROPIETARIO'],true);
}
function gvo_base(PDO $db): void {
    if((string)$db->query('SELECT DATABASE()')->fetchColumn() !== 'prueba') throw new DomainException('Este panel solo consulta la base «prueba».');
}
function gvo_schema(PDO $db): array {
    $s=$db->query('SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()');$out=[];
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)$out[$r['TABLE_NAME']][$r['COLUMN_NAME']]=true;
    return $out;
}
function gvo_has(array $schema,string $tab,array $cols=[]): bool {
    if(!isset($schema[$tab]))return false;
    foreach($cols as $c)if(!isset($schema[$tab][$c]))return false;
    return true;
}
function gvo_one(PDO $db,string $sql,array $params=[]): mixed {$s=$db->prepare($sql);$s->execute($params);return $s->fetchColumn();}
function gvo_add(array &$out,string $area,string $titulo,callable $callback): void {
    try {$r=$callback();$estado=(string)($r[0]??'no_disponible');
        if(!in_array($estado,['correcto','revisar','fallido','no_disponible'],true))throw new LogicException('Estado inesperado');
        $out[]=['area'=>$area,'titulo'=>$titulo,'estado'=>$estado,'detalle'=>(string)($r[1]??'')];
    }catch(Throwable $e){error_log('GVO '.$area.' '.$titulo.': '.$e->getMessage());$out[]=['area'=>$area,'titulo'=>$titulo,'estado'=>'no_disponible','detalle'=>'La comprobación no pudo completarse; revisa el log del servidor.'];}
}
function gvo_missing(array &$out,string $area,string $titulo): void {gvo_add($out,$area,$titulo,static fn()=>['no_disponible','Faltan tablas o columnas para esta verificación.']);}
/** Esta rutina NO inicia transacciones ni modifica tablas operativas. */
function gvo_scan(PDO $db,array $user,string $root): array {
    gvo_base($db);$sch=gvo_schema($db);$results=[];
    gvo_add($results,'Entorno','Base de datos',static fn()=>['correcto','Conexión a «prueba» confirmada.']);
    // Ejecuta métodos REALES del modelo POS, solo lectura.
    gvo_add($results,'POS real','Carga del modelo y cajas autorizadas',static function()use($user,$root){
        $path=$root.'/app/models/PuntoVenta.php';if(!is_file($path))return ['no_disponible','No existe el modelo PuntoVenta.php.'];
        require_once $path;
        if(!class_exists('PuntoVenta'))return ['fallido','No se pudo cargar el modelo POS.'];
        $model=new PuntoVenta();$cajas=$model->sucursales($user);
        if(!is_array($cajas))return ['fallido','El método sucursales() no devolvió una lista.'];
        return $cajas?['correcto','Modelo real cargado; '.count($cajas).' cajas accesibles para la sesión.']:['revisar','El modelo responde, pero la sesión no tiene cajas accesibles.'];
    });
    if(gvo_has($sch,'pos_ventas',['id','estado','total'])&&gvo_has($sch,'pos_pagos',['venta_id','monto','estado'])) {
        gvo_add($results,'Ventas','Tickets pagados frente a pagos reales',static function()use($db){
            $n=(int)gvo_one($db,"SELECT COUNT(*) FROM (SELECT v.id,v.total,COALESCE(SUM(CASE WHEN p.estado='CONFIRMADO' THEN p.monto ELSE 0 END),0) cobrado FROM (SELECT id,total FROM pos_ventas WHERE estado='PAGADA' ORDER BY id DESC LIMIT 100) v LEFT JOIN pos_pagos p ON p.venta_id=v.id GROUP BY v.id,v.total HAVING ABS(ROUND((v.total-cobrado)*100))>1) z");
            $total=(int)gvo_one($db,"SELECT COUNT(*) FROM (SELECT id FROM pos_ventas WHERE estado='PAGADA' ORDER BY id DESC LIMIT 100) a");
            if(!$total)return ['revisar','No hay ventas pagadas en la muestra.'];
            return $n?['fallido',"$n diferencias entre ticket y pago en los últimos $total tickets pagados."]:['correcto',"Los últimos $total tickets pagados coinciden con sus pagos confirmados."];
        });
    }else gvo_missing($results,'Ventas','Tickets pagados frente a pagos reales');
    if(gvo_has($sch,'pos_ventas',['id','idempotency_key']))gvo_add($results,'Ventas','Idempotencia registrada',static function()use($db){
        $n=(int)gvo_one($db,"SELECT COUNT(*) FROM (SELECT idempotency_key FROM (SELECT idempotency_key FROM pos_ventas WHERE idempotency_key IS NOT NULL ORDER BY id DESC LIMIT 500) x GROUP BY idempotency_key HAVING COUNT(*)>1) y");
        return $n?['fallido',"Se encontraron $n claves repetidas en hasta 500 ventas."]:['correcto','No se observaron claves repetidas en hasta 500 ventas; falta comprobar reintentos reales.'];
    });else gvo_missing($results,'Ventas','Idempotencia registrada');
    if(gvo_has($sch,'pos_turnos',['id','caja_id','estado']))gvo_add($results,'Cajas','Un turno abierto por caja',static function()use($db){
        $n=(int)gvo_one($db,"SELECT COUNT(*) FROM (SELECT caja_id FROM pos_turnos WHERE estado='ABIERTO' GROUP BY caja_id HAVING COUNT(*)>1) a");
        return $n?['fallido',"$n cajas tienen más de un turno abierto."]:['correcto','No se detectaron turnos simultáneos sobre la misma caja.'];
    });else gvo_missing($results,'Cajas','Un turno abierto por caja');
    if(gvo_has($sch,'pos_turnos',['id','estado','diferencia_efectivo','efectivo_contado']))gvo_add($results,'Cajas','Clasificación de diferencias de cortes',static function()use($db){
        $q=$db->query("SELECT diferencia_efectivo FROM pos_turnos WHERE estado='CERRADO' AND diferencia_efectivo IS NOT NULL ORDER BY id DESC LIMIT 100");
        $rows=$q->fetchAll(PDO::FETCH_COLUMN);$s=0;$f=0;$e=0;
        foreach($rows as $diff){$v=(float)$diff;if($v>0)$s++;elseif($v<0)$f++;else $e++;}
        return $rows?['correcto',"Se clasificaron ".count($rows)." cortes: $s sobrantes, $f faltantes, $e exactos según su signo. La impresión del corte se comprueba por separado."]:['revisar','No hay cortes recientes para contrastar diferencias.'];
    });else gvo_missing($results,'Cajas','Clasificación de diferencias de cortes');
    if(gvo_has($sch,'pos_cortes_denominaciones',['turno_id'])&&gvo_has($sch,'pos_turnos',['id']))gvo_add($results,'Cajas','Comprobantes de denominaciones vinculados',static function()use($db){
        $n=(int)gvo_one($db,'SELECT COUNT(*) FROM pos_cortes_denominaciones d LEFT JOIN pos_turnos t ON t.id=d.turno_id WHERE t.id IS NULL');
        return $n?['fallido',"$n comprobantes sin turno existente."]:['correcto','No se detectaron comprobantes de corte sin turno.'];
    });else gvo_missing($results,'Cajas','Comprobantes de denominaciones vinculados');
    if(gvo_has($sch,'pos_existencias_farmacia',['existencia']))gvo_add($results,'Inventarios','Existencias POS no negativas',static function()use($db){
        $n=(int)gvo_one($db,'SELECT COUNT(*) FROM pos_existencias_farmacia WHERE existencia < 0');
        return $n?['fallido',"$n registros con existencia POS negativa."]:['correcto','No se observaron existencias POS negativas.'];
    });else gvo_missing($results,'Inventarios','Existencias POS no negativas');
    if(gvo_has($sch,'erp_pos_farmacia_control',['existencia']))gvo_add($results,'Inventarios','Conteo de farmacia no negativo',static function()use($db){
        $n=(int)gvo_one($db,'SELECT COUNT(*) FROM erp_pos_farmacia_control WHERE existencia < 0');
        return $n?['fallido',"$n registros físicos con existencia negativa."]:['correcto','No se observaron conteos físicos negativos.'];
    });else gvo_missing($results,'Inventarios','Conteo de farmacia no negativo');
    if(gvo_has($sch,'erp_oc_recepciones',['id','orden_id'])&&gvo_has($sch,'erp_ordenes_compra',['id']))gvo_add($results,'Compras','Recepciones vinculadas a órdenes',static function()use($db){
        $n=(int)gvo_one($db,'SELECT COUNT(*) FROM (SELECT orden_id FROM erp_oc_recepciones ORDER BY id DESC LIMIT 200) r LEFT JOIN erp_ordenes_compra o ON o.id=r.orden_id WHERE o.id IS NULL');
        return $n?['fallido',"$n recepciones recientes sin orden asociada."]:['correcto','Hasta 200 recepciones recientes tienen orden asociada.'];
    });else gvo_missing($results,'Compras','Recepciones vinculadas a órdenes');
    if(gvo_has($sch,'pos_reversiones',['id','operacion_clave']))gvo_add($results,'Devoluciones','Idempotencia de devoluciones',static function()use($db){
        $n=(int)gvo_one($db,'SELECT COUNT(*) FROM (SELECT operacion_clave FROM (SELECT operacion_clave FROM pos_reversiones ORDER BY id DESC LIMIT 500) r GROUP BY operacion_clave HAVING COUNT(*)>1) d');
        return $n?['fallido',"$n claves de reversión duplicadas."]:['correcto','Sin claves repetidas en hasta 500 reversiones.'];
    });else gvo_missing($results,'Devoluciones','Idempotencia de devoluciones');
    gvo_add($results,'Seguridad','Protecciones de rutas instaladas',static function()use($root){
        $path=$root.'/app/helpers/gyd_acl.php';$auth=$root.'/app/helpers/auth.php';
        if(!is_file($path)||!is_file($auth))return ['revisar','No están presentes todas las piezas de control central de permisos.'];
        $s=(string)file_get_contents($auth);
        return str_contains($s,'gyd_acl_exigir_ruta(')?['revisar','La capa central está presente; debe verificarse que una cuenta restringida reciba 403 desde las API.']:['revisar','Hay archivo de ACL, pero no se confirmó su invocación en autenticación.'];
    });
    $counts=['correcto'=>0,'revisar'=>0,'fallido'=>0,'no_disponible'=>0];foreach($results as $r)$counts[$r['estado']]++;
    return ['fecha'=>date('Y-m-d H:i:s'),'resultados'=>$results,'resumen'=>$counts,'aviso'=>'Comprobaciones reales de lectura sobre «prueba». NO se realizaron ventas, retiros, cortes ni otras operaciones de escritura.'];
}
function gvo_scenarios(): array {return [
  'venta_doble'=>['POS','Dos cajeros venden simultáneamente la última unidad','Un solo descuento y ningún inventario negativo'],
  'venta_reintento'=>['POS','Reenviar la misma venta con idéntica clave','Un ticket y un único cobro'],
  'limite'=>['Caja','Superar $3,000 y solicitar otro cobro','La siguiente venta se bloquea hasta realizar retiro'],
  'retiro'=>['Caja','Abrir retiro con contraseña del cajero y repetir confirmación','Un egreso, fondo conservado y comprobante único'],
  'corte'=>['Caja','Solicitar corte con cajero; autorización gerencial por modal','Se rechaza contraseña errónea y el corte cierra el turno'],
  'relevo'=>['Turnos','Relevar cajero con autorización y conteo','Solo el nuevo responsable puede operar'],
  'devolucion'=>['Devoluciones','Devolver y reintentar igual operación','No se duplica reintegro ni inventario'],
  'compra'=>['Compras','Registrar dos veces la misma recepción/factura','No se duplican piezas ni documentos'],
  'rutas'=>['Permisos','Entrar a API restringida directamente por URL','Responde 403 sin ejecutar acciones'],
  'restauracion'=>['Respaldos','Restaurar copia en nueva base temporal','Restauración verificable sin tocar datos originales']
];}
function gvo_result_label(string $n): string {return ['pendiente'=>'Pendiente','aprobado'=>'Aprobado manualmente','fallido'=>'Fallido','bloqueado'=>'No ejecutado'][$n]??'No ejecutado';}
/** El log de evidencia no contiene credenciales. No es una prueba criptográfica de la ejecución. */
function gvo_evidence_dir(string $root): string {return $root.'/storage/gyd_validacion_operativa';}
function gvo_history(string $root): array {
    $dir=gvo_evidence_dir($root); if(!is_dir($dir))return [];
    $files=glob($dir.'/evidencia_*.json')?:[];rsort($files);$out=[];
    foreach(array_slice($files,0,50) as $p){$data=json_decode((string)file_get_contents($p),true);if(is_array($data))$out[]=$data;}
    return $out;
}
function gvo_save(string $root,array $user,string $id,string $estado,string $nota,bool $lab): void {
    $scenarios=gvo_scenarios();if(!isset($scenarios[$id]))throw new DomainException('Escenario desconocido.');
    if(!in_array($estado,['aprobado','fallido','bloqueado'],true))throw new DomainException('Selecciona un resultado válido.');
    if(!$lab)throw new DomainException('Solo puedes documentar pruebas que se realizaron en una copia aislada.');
    $nota=trim($nota);if(strlen($nota)>1000||strlen($nota)<12)throw new DomainException('Describe la evidencia en 12 a 500 caracteres. No escribas contraseñas ni información de clientes.');
    $dir=gvo_evidence_dir($root);if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('No se pudo crear el historial.');
    if(!is_file($dir.'/.htaccess')){
        $deny="<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\nOptions -Indexes\n";
        if(file_put_contents($dir.'/.htaccess',$deny,LOCK_EX)===false)throw new RuntimeException('No se pudo proteger el historial.');
    }
    $safe=['fecha'=>date('Y-m-d H:i:s'),'usuario_id'=>(int)$user['id'],'escenario'=>$id,'resultado'=>$estado,'observacion'=>$nota,'entorno'=>'Laboratorio aislado (declarado por el usuario)','origen'=>'Registro manual, no validación automática'];
    $file=$dir.'/evidencia_'.date('Ymd_His').'_'.bin2hex(random_bytes(6)).'.json';
    if(file_put_contents($file,json_encode($safe,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('No se pudo guardar la evidencia.');
}
