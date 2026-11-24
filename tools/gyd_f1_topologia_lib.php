<?php
declare(strict_types=1);
/** F1 / 03-nov-2026. Funciones puras para auditoría de sucursales; no modifican datos. */
function gydF1Nombre(string $nombre): string {
    $nombre = trim(strtoupper(strtr($nombre, ['á'=>'A','é'=>'E','í'=>'I','ó'=>'O','ú'=>'U','ñ'=>'N','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N'])));
    return preg_replace('/\s+/', ' ', $nombre) ?: '';
}
function gydF1Canonico(string $nombre): ?string {
    $v = gydF1Nombre($nombre);
    return match ($v) {
        'CD HIDALGO', 'CIUDAD HIDALGO' => 'CIUDAD HIDALGO',
        'TUXTLA', 'TUXTLA GUTIERREZ' => 'TUXTLA GUTIERREZ',
        'TAPACHULA' => 'TAPACHULA',
        'TOSCANA', 'PLAZA TOSCANA' => 'PLAZA TOSCANA',
        default => null
    };
}
/** Detecta alias duplicados, ausencia de sucursales y mapeos antiguos por ID. */
function gydF1AnalizarSucursales(array $rows): array {
    $porNombre=[];$alertas=[];
    foreach ($rows as $r) {
        $nombre=(string)($r['nombre']??'');
        $canon=gydF1Canonico($nombre);
        if ($canon===null) { $alertas[]=['nivel'=>'REVISAR','mensaje'=>'Sucursal POS no reconocida: '.$nombre]; continue; }
        $porNombre[$canon][]=(int)($r['id']??0);
    }
    foreach (['CIUDAD HIDALGO','TUXTLA GUTIERREZ','TAPACHULA','PLAZA TOSCANA'] as $necesaria) {
        $ids=$porNombre[$necesaria]??[];
        if (!$ids) $alertas[]=['nivel'=>'BLOQUEANTE','mensaje'=>'Sucursal POS faltante: '.$necesaria];
        elseif(count($ids)>1) $alertas[]=['nivel'=>'BLOQUEANTE','mensaje'=>'Sucursales POS duplicadas por alias: '.$necesaria];
    }
    // pi_almacen() del código de referencia asigna 1 -> Hidalgo, 2 -> Tuxtla.
    foreach ([1=>'CIUDAD HIDALGO',2=>'TUXTLA GUTIERREZ'] as $id=>$esperado) {
        if (isset($porNombre[$esperado]) && !in_array($id,$porNombre[$esperado],true)) {
            $alertas[]=['nivel'=>'BLOQUEANTE','mensaje'=>'Revisar pi_almacen(): el POS usa IDs 1/2 fijos y '.$esperado.' no tiene ID '.$id];
        }
    }
    return ['ids_por_sucursal'=>$porNombre,'alertas'=>$alertas];
}
function gydF1Hallazgo(string $clave,string $nivel,string $resumen,array $datos=[]): array {
    return ['clave'=>$clave,'estado'=>$nivel,'detalle'=>$resumen,'datos'=>$datos];
}
/** Sólo consultas de lectura. Se comprueba el esquema por lista blanca. */
function gydF1Ejecutar(PDO $db,string $esperada='prueba'): array {
    if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$esperada)) throw new InvalidArgumentException('Nombre de base esperado inválido.');
    $base=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    if (!hash_equals($esperada,$base)) throw new RuntimeException('BLOQUEADO: base actual distinta de la esperada.');
    $out=['base'=>$base,'fecha'=>date(DATE_ATOM),'modo'=>'SOLO_LECTURA','hallazgos'=>[]];
    $all=['pos_sucursales','almacenes','pos_cajas','pos_existencias_farmacia','producto_existencias','erp_pos_farmacia_control','pos_usuario_asignaciones','usuarios'];
    $st=$db->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
    $st->execute();$presentes=array_fill_keys(array_map('strtolower',$st->fetchAll(PDO::FETCH_COLUMN)),true);
    $out['tablas']=[];
    foreach($all as $t) {
        $ok=isset($presentes[strtolower($t)]);
        $out['tablas'][$t]=$ok;
        if(!$ok)$out['hallazgos'][]=gydF1Hallazgo('TABLA_'.strtoupper($t),'NO_VERIFICADO','No está disponible la tabla '.$t.' en esta base.');
    }
    if ($out['tablas']['pos_sucursales']) {
        $rows=$db->query('SELECT id,nombre,activo FROM pos_sucursales ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $a=gydF1AnalizarSucursales($rows);
        $out['pos_sucursales']=$rows;
        foreach($a['alertas'] as $al)$out['hallazgos'][]=gydF1Hallazgo('SUCURSAL_POS',$al['nivel'],$al['mensaje']);
        $by=$a['ids_por_sucursal'];
        if (($out['tablas']['pos_existencias_farmacia']??false) && !empty($by['TAPACHULA']) && !empty($by['PLAZA TOSCANA'])) {
            $tap=$by['TAPACHULA'][0];$tos=$by['PLAZA TOSCANA'][0];
            $st=$db->prepare('SELECT COUNT(*) FROM pos_existencias_farmacia a INNER JOIN pos_existencias_farmacia b ON b.producto_id=a.producto_id AND b.sucursal_id=? WHERE a.sucursal_id=? AND a.existencia>0 AND b.existencia>0');
            $st->execute([$tos,$tap]);$cantidad=(int)$st->fetchColumn();
            $st=$db->prepare('SELECT a.producto_id,a.existencia tapachula,b.existencia toscana FROM pos_existencias_farmacia a INNER JOIN pos_existencias_farmacia b ON b.producto_id=a.producto_id AND b.sucursal_id=? WHERE a.sucursal_id=? AND a.existencia>0 AND b.existencia>0 ORDER BY a.producto_id LIMIT 20');
            $st->execute([$tos,$tap]);$ejemplos=$st->fetchAll(PDO::FETCH_ASSOC);
            $out['hallazgos'][]=gydF1Hallazgo('STOCK_COMPARTIDO','REVISAR','El POS de referencia gestiona saldos por sucursal; validar el inventario único Tapachula/Toscana antes de efectuar ajustes.', ['productos_con_existencias_en_ambas'=>$cantidad,'muestras'=>$ejemplos]);
        }else{
            $out['hallazgos'][]=gydF1Hallazgo('STOCK_COMPARTIDO','NO_VERIFICADO','No se puede comprobar el inventario compartido Tapachula/Toscana con esta estructura.');
        }
    }
    if($out['tablas']['almacenes']){
        $rows=$db->query('SELECT id,nombre,estado FROM almacenes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $out['almacenes']=$rows;
        $out['hallazgos'][]=gydF1Hallazgo('MAPEO_ALMACENES','REVISAR','Contrastar los IDs de almacenes reales con pi_almacen() y con el maestro de ubicaciones.',['almacenes'=>$rows]);
    }
    if($out['tablas']['pos_cajas'] && $out['tablas']['pos_sucursales']){
        $st=$db->query('SELECT COUNT(*) FROM pos_cajas c LEFT JOIN pos_sucursales s ON s.id=c.sucursal_id WHERE s.id IS NULL OR (c.activa=1 AND s.activo<>1)');
        $n=(int)$st->fetchColumn();
        $out['hallazgos'][]=gydF1Hallazgo('CAJAS_SUCURSAL',$n?'BLOQUEANTE':'CORRECTO',$n?'Cajas huérfanas o activas en sucursales inactivas: '.$n:'Sin cajas huérfanas ni activas en sucursales inactivas.',['casos'=>$n]);
    }
    if($out['tablas']['pos_usuario_asignaciones'] && $out['tablas']['usuarios'] && $out['tablas']['pos_cajas']){
        $n=(int)$db->query('SELECT COUNT(*) FROM pos_usuario_asignaciones a LEFT JOIN pos_cajas c ON c.id=a.caja_id LEFT JOIN usuarios u ON u.id=a.usuario_id WHERE c.id IS NULL OR u.id IS NULL OR c.sucursal_id<>a.sucursal_id')->fetchColumn();
        $out['hallazgos'][]=gydF1Hallazgo('ASIGNACIONES_CAJA',$n?'BLOQUEANTE':'CORRECTO',$n?'Asignaciones de usuarios con caja o sucursal incoherente: '.$n:'No se encontraron asignaciones incoherentes.',['casos'=>$n]);
    }
    if($out['tablas']['pos_existencias_farmacia']){
        $n=(int)$db->query('SELECT COUNT(*) FROM pos_existencias_farmacia WHERE existencia < 0')->fetchColumn();
        $out['hallazgos'][]=gydF1Hallazgo('STOCK_POS_NEGATIVO',$n?'BLOQUEANTE':'CORRECTO','Filas POS con existencia negativa: '.$n,['casos'=>$n]);
    }
    if($out['tablas']['producto_existencias']){
        $rows=$db->query('SELECT COALESCE(NULLIF(TRIM(sucursal),\'\'),\'(sin sucursal)\') sucursal, COUNT(*) registros, COALESCE(SUM(existencia),0) piezas FROM producto_existencias GROUP BY COALESCE(NULLIF(TRIM(sucursal),\'\'),\'(sin sucursal)\') ORDER BY registros DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);
        $out['origenes_producto_existencias']=$rows;
        $n=(int)$db->query('SELECT COUNT(*) FROM producto_existencias WHERE existencia<0')->fetchColumn();
        $out['hallazgos'][]=gydF1Hallazgo('STOCK_BODEGA_NEGATIVO',$n?'BLOQUEANTE':'CORRECTO','Filas bodega con existencia negativa: '.$n,['casos'=>$n]);
    }
    if($out['tablas']['erp_pos_farmacia_control']){
        $n=(int)$db->query('SELECT COUNT(*) FROM erp_pos_farmacia_control WHERE existencia<0')->fetchColumn();
        $out['hallazgos'][]=gydF1Hallazgo('STOCK_FISICO_FARMACIA_NEGATIVO',$n?'BLOQUEANTE':'CORRECTO','Filas de control físico de farmacia con existencia negativa: '.$n,['casos'=>$n]);
    }
    $out['resumen']=array_count_values(array_column($out['hallazgos'],'estado'));
    $out['conclusion']='Este reporte NO demuestra que los saldos sean correctos ni concilia WinCaja. Requiere revisión de ubicación y pruebas integrales.';
    return $out;
}
