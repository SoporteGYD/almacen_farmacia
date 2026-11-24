<?php
declare(strict_types=1);
/** Farmacia G&D V12 - Auditoría de cajas; consultas SELECT exclusivamente. */
function gac12_fecha(string $v): bool {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    return $d!==false && $d->format('Y-m-d')===$v;
}
function gac12_periodo(string $desde,string $hasta):array {
    if(!gac12_fecha($desde)||!gac12_fecha($hasta)||$desde>$hasta)throw new DomainException('Fechas inválidas.');
    $a=new DateTimeImmutable($desde);$b=new DateTimeImmutable($hasta);
    if($a->diff($b)->days>92)throw new DomainException('Elige máximo 93 días por consulta.');
    return [$desde.' 00:00:00',$b->modify('+1 day')->format('Y-m-d').' 00:00:00'];
}
function gac12_centavos(mixed $n):?int {
    if($n===null)return null;
    $s=trim((string)$n);
    if(!preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,2}))?$/D',$s,$m))throw new DomainException('Importe inesperado en la base de datos.');
    $value=(int)$m[2]*100+(int)str_pad($m[3]??'',2,'0');
    return $m[1]==='-'?-$value:$value;
}
function gac12_decimal(?int $v):string {
    if($v===null)return '';
    return ($v<0?'-':'').intdiv(abs($v),100).'.'.str_pad((string)(abs($v)%100),2,'0',STR_PAD_LEFT);
}
function gac12_dinero(?int $v):string {
    return $v===null?'—':($v<0?'-':'').'$'.number_format(abs($v)/100,2,'.',',');
}
function gac12_h(mixed $v):string {return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function gac12_query(PDO $db,string $sql,array $args=[]):array {
    $s=$db->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);
}
function gac12_existe(PDO $db,string $name):bool {
    return (bool)gac12_query($db,'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1',[$name]);
}
function gac12_rol_permitido(array $u):bool {
    $r=strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));
    return (int)($u['id']??0)>0 && in_array($r,['ADMINISTRADOR','ADMIN','DIRECCION','GERENTE','DUENO','DUEÑO','PROPIETARIO'],true);
}
function gac12_tipo(?int $d):string {
    if($d===null)return 'SIN CONTEO';
    return $d>0?'SOBRANTE':($d<0?'FALTANTE':'EXACTO');
}
function gac12_csvtexto(mixed $v):string {
    $s=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u',' ',(string)($v??''))??'';
    return preg_match('/^\s*[=+@\-]/u',$s)?"'".$s:$s;
}
/** Retorna hasta 300 filas por sección; revela truncamiento para no dar un reporte falsamente completo. */
function gac12_reporte(PDO $db,string $desde,string $hasta,int $sucursal):array {
    [$ini,$fin]=gac12_periodo($desde,$hasta);
    foreach(['pos_sucursales','pos_cajas','pos_turnos','usuarios','pos_movimientos_caja','pos_auditoria'] as $table){
        if(!gac12_existe($db,$table))throw new RuntimeException('Falta una tabla obligatoria del POS: '.$table);
    }
    $hasCortes=gac12_existe($db,'gyd_cortes_autorizaciones');
    $hasBoveda=gac12_existe($db,'gyd_retiros_boveda');
    $hasIntentosCorte=gac12_existe($db,'gyd_cortes_intentos');
    $hasIntentosRetiro=gac12_existe($db,'gyd_retiros_intentos_clave');
    $hasDen=gac12_existe($db,'pos_cortes_denominaciones');
    $avisos=[];
    if(!$hasCortes)$avisos[]='No existe gyd_cortes_autorizaciones: el autorizador del corte no se puede consultar.';
    if(!$hasBoveda)$avisos[]='No existe gyd_retiros_boveda: se mostrarán retiros genéricos de caja, sin afirmar que fueron entregados en bóveda.';
    if(!$hasIntentosCorte)$avisos[]='Sin tabla de intentos de corte: no hay evidencia histórica de contraseñas incorrectas en ese acceso.';
    if(!$hasIntentosRetiro)$avisos[]='Sin tabla de intentos de retiro: no hay evidencia histórica de contraseñas incorrectas en ese acceso.';
    $sucursales=gac12_query($db,'SELECT id,nombre FROM pos_sucursales ORDER BY id');
    if($sucursal>0 && !in_array($sucursal,array_map(static fn(array $x)=>(int)$x['id'],$sucursales),true))throw new DomainException('Sucursal no válida.');
    $fCaja=$sucursal>0?' AND c.sucursal_id=?':'';
    $pCaja=$sucursal>0?[$sucursal]:[];
    // Estado real actual: siempre consulta todas las cajas activas, sin modificar turnos.
    $cajas=gac12_query($db,"SELECT c.id,c.nombre caja,s.nombre sucursal,t.id turno_id,t.estado turno_estado,t.abierto_en,u.nombre cajero
        FROM pos_cajas c JOIN pos_sucursales s ON s.id=c.sucursal_id
        LEFT JOIN pos_turnos t ON t.id=(SELECT MAX(t2.id) FROM pos_turnos t2 WHERE t2.caja_id=c.id AND t2.estado='ABIERTO')
        LEFT JOIN usuarios u ON u.id=t.usuario_apertura_id WHERE c.activa=1 $fCaja ORDER BY s.nombre,c.nombre",$pCaja);
    $joinAutor=$hasCortes?"LEFT JOIN gyd_cortes_autorizaciones ca ON ca.turno_id=t.id LEFT JOIN usuarios autorizador ON autorizador.id=ca.autorizador_id":"";
    $selectAutor=$hasCortes?"ca.autorizador_id,autorizador.nombre autorizador,ca.modalidad,ca.resultado resultado_autorizado":"NULL autorizador_id,NULL autorizador,NULL modalidad,NULL resultado_autorizado";
    $joinDen=$hasDen?'LEFT JOIN pos_cortes_denominaciones den ON den.turno_id=t.id':' ';
    $selectDen=$hasDen?'den.id comprobante_id':'NULL comprobante_id';
    $args=[$ini,$fin,$ini,$fin];$scope='';
    if($sucursal>0){$scope=' AND c.sucursal_id=?';$args[]=$sucursal;}
    $turnos=gac12_query($db,"SELECT t.id,t.caja_id,t.estado,t.abierto_en,t.cerrado_en,t.fondo_inicial,t.efectivo_contado,t.diferencia_efectivo,
       c.nombre caja,s.nombre sucursal,u.nombre cajero,uc.nombre usuario_cierre,$selectAutor,$selectDen
       FROM pos_turnos t JOIN pos_cajas c ON c.id=t.caja_id JOIN pos_sucursales s ON s.id=c.sucursal_id
       LEFT JOIN usuarios u ON u.id=t.usuario_apertura_id LEFT JOIN usuarios uc ON uc.id=t.usuario_cierre_id
       $joinAutor $joinDen WHERE ((t.abierto_en>=? AND t.abierto_en<?) OR (t.cerrado_en>=? AND t.cerrado_en<?)) $scope
       ORDER BY COALESCE(t.cerrado_en,t.abierto_en) DESC,t.id DESC LIMIT 301",$args);
    if(count($turnos)>300){$avisos[]='Más de 300 turnos encontrados. Se muestran los 300 primeros; reduce el periodo para exportar todos.';array_pop($turnos);}
    $resumen=['abiertas'=>0,'cerradas'=>0,'faltantes'=>0,'sobrantes'=>0,'exactos'=>0,'importe_faltantes'=>0,'importe_sobrantes'=>0,'retiros'=>0,'importe_retiros'=>0,'intentos_fallidos'=>0];
    foreach($cajas as $c){if($c['turno_id']!==null)$resumen['abiertas']++;}
    foreach($turnos as &$t){
        $t['fondo_c']=gac12_centavos($t['fondo_inicial']);
        $t['contado_c']=gac12_centavos($t['efectivo_contado']);
        $t['dif_c']=gac12_centavos($t['diferencia_efectivo']);
        $t['tipo_dif']=$t['estado']==='CERRADO'?gac12_tipo($t['dif_c']):'PENDIENTE';
        $t['esperado_c']=$t['contado_c']!==null&&$t['dif_c']!==null?$t['contado_c']-$t['dif_c']:null;
        if($t['estado']==='CERRADO'){
            $resumen['cerradas']++;
            if($t['tipo_dif']==='FALTANTE'){$resumen['faltantes']++;$resumen['importe_faltantes']+=abs($t['dif_c']);}
            elseif($t['tipo_dif']==='SOBRANTE'){$resumen['sobrantes']++;$resumen['importe_sobrantes']+=$t['dif_c'];}
            elseif($t['tipo_dif']==='EXACTO')$resumen['exactos']++;
        }
    }unset($t);
    if($hasBoveda){
        $argsR=[$ini,$fin];$f=$sucursal>0?' AND c.sucursal_id=?':'';if($sucursal>0)$argsR[]=$sucursal;
        $retiros=gac12_query($db,"SELECT b.id,b.turno_id,b.creado_en,b.importe,b.movimiento_id,c.nombre caja,s.nombre sucursal,u.nombre cajero,
          'BOVEDA' clase FROM gyd_retiros_boveda b JOIN pos_cajas c ON c.id=b.caja_id JOIN pos_sucursales s ON s.id=c.sucursal_id
          LEFT JOIN usuarios u ON u.id=b.cajero_id WHERE b.creado_en>=? AND b.creado_en<? $f ORDER BY b.creado_en DESC,b.id DESC LIMIT 301",$argsR);
    }else{
        $argsR=[$ini,$fin];$f=$sucursal>0?' AND c.sucursal_id=?':'';if($sucursal>0)$argsR[]=$sucursal;
        $retiros=gac12_query($db,"SELECT m.id,m.turno_id,m.creado_en,m.monto importe,m.id movimiento_id,c.nombre caja,s.nombre sucursal,u.nombre cajero,
          'RETIRO_NO_CLASIFICADO' clase FROM pos_movimientos_caja m JOIN pos_turnos t ON t.id=m.turno_id
          JOIN pos_cajas c ON c.id=t.caja_id JOIN pos_sucursales s ON s.id=c.sucursal_id
          LEFT JOIN usuarios u ON u.id=m.usuario_id WHERE m.creado_en>=? AND m.creado_en<? AND m.tipo='RETIRO' $f
          ORDER BY m.creado_en DESC,m.id DESC LIMIT 301",$argsR);
    }
    if(count($retiros)>300){$avisos[]='Más de 300 retiros encontrados. Se muestran los 300 primeros; reduce el periodo para exportar todos.';array_pop($retiros);}
    foreach($retiros as &$r){$r['importe_c']=gac12_centavos($r['importe']);$resumen['retiros']++;$resumen['importe_retiros']+=$r['importe_c'];}unset($r);
    // Intenciones de autenticación sin sucursal en el esquema. Se presentan como actividad global.
    $intentos=[];
    if($hasIntentosCorte){
        foreach(gac12_query($db,"SELECT i.id,i.creado_en,i.exitoso AS valido,u.nombre usuario,'CORTE' origen
         FROM gyd_cortes_intentos i LEFT JOIN usuarios u ON u.id=i.solicitante_id WHERE i.creado_en>=? AND i.creado_en<?
         ORDER BY i.id DESC LIMIT 301",[$ini,$fin]) as $r)$intentos[]=$r;
    }
    if($hasIntentosRetiro){
        foreach(gac12_query($db,"SELECT i.id,i.creado_en,i.correcto AS valido,u.nombre usuario,'RETIRO' origen
         FROM gyd_retiros_intentos_clave i LEFT JOIN usuarios u ON u.id=i.usuario_id WHERE i.creado_en>=? AND i.creado_en<?
         ORDER BY i.id DESC LIMIT 301",[$ini,$fin]) as $r)$intentos[]=$r;
    }
    usort($intentos,static fn($a,$b)=>strcmp((string)$b['creado_en'],(string)$a['creado_en']) ?: ((int)$b['id']<=>(int)$a['id']));
    if(count($intentos)>300){$avisos[]='Los intentos de acceso superan 300 registros; reduce las fechas para revisarlos todos.';$intentos=array_slice($intentos,0,300);}
    foreach($intentos as $i)if(!(int)$i['valido'])$resumen['intentos_fallidos']++;
    // pos_auditoria no posee FK de sucursal: evita atribuir eventos a una caja sin evidencia.
    $eventos=gac12_query($db,"SELECT a.id,a.creado_en,a.accion,a.venta_id,u.nombre usuario FROM pos_auditoria a LEFT JOIN usuarios u ON u.id=a.usuario_id
     WHERE a.creado_en>=? AND a.creado_en<? AND (a.accion LIKE 'CIERRE_%' OR a.accion LIKE 'APERTURA_%' OR a.accion LIKE 'RETIRO_%' OR a.accion LIKE 'CORTE_%' OR a.accion LIKE 'AUTORIZAR_%')
     ORDER BY a.creado_en DESC,a.id DESC LIMIT 301",[$ini,$fin]);
    if(count($eventos)>300){$avisos[]='Más de 300 eventos de auditoría; reduce el periodo para verlos todos.';array_pop($eventos);}
    return compact('sucursales','cajas','turnos','retiros','intentos','eventos','resumen','avisos');
}
