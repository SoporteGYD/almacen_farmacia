<?php
declare(strict_types=1);
/** Informes POS: consultas de solo lectura, coherentes con el panel de cajas. */
require_once __DIR__.'/gyd_panel_cajas.php';

function gr_datos(PDO $db,string $desde,string $hasta,int $sucursal,string $estado):array {
    $panel=gac_cargar($db,$desde,$hasta,$sucursal,$estado);
    $turnos=$panel['turnos'];
    $sucursales=[];
    foreach($panel['sucresumen'] as $r){
        $sid=(int)$r['id'];
        $sucursales[$sid]=[
            'id'=>$sid,'nombre'=>(string)$r['nombre'],
            'turnos'=>(int)$r['turnos'],'abiertos'=>(int)$r['abiertos'],'cerrados'=>(int)$r['cerrados'],
            'cobro_efectivo'=>(int)$r['efectivo'],'cobro_tarjeta'=>(int)$r['tarjeta'],
            'cobro_transferencia'=>(int)$r['transferencia'],'salidas'=>(int)$r['retiros'],
            'reembolso_efectivo'=>0,'reembolso_electronico'=>0,'venta_vigente'=>0,
            'pagadas'=>0,'canceladas'=>0,'alertas'=>(int)$r['alertas'],
            'cortes_con_diferencia'=>0,'faltantes'=>0,'sobrantes'=>0,
        ];
    }
    foreach($turnos as $t){
        $sid=(int)$t['sucursal_id'];
        if(!isset($sucursales[$sid]))continue;
        $sucursales[$sid]['reembolso_efectivo']+=(int)$t['reembolso_efectivo'];
        $sucursales[$sid]['reembolso_electronico']+=(int)$t['reembolso_tarjeta']+(int)$t['reembolso_transferencia'];
        $sucursales[$sid]['pagadas']+=(int)$t['ventas_pagadas'];
        $sucursales[$sid]['canceladas']+=(int)$t['ventas_canceladas'];
        if($t['diferencia']!==null){
            $dif=(int)$t['diferencia'];
            if($dif!==0)$sucursales[$sid]['cortes_con_diferencia']++;
            if($dif<0)$sucursales[$sid]['faltantes']+=abs($dif);
            if($dif>0)$sucursales[$sid]['sobrantes']+=$dif;
        }
    }
    $cajeros=[];
    if($turnos){
        $ids=array_map(static fn(array $t)=>(int)$t['id'],$turnos);
        $ph=implode(',',array_fill(0,count($ids),'?'));
        // Ventas: únicamente estado PAGADA. No se suman PENDIENTE, BORRADOR o CANCELADA.
        foreach(gac_query($db,"SELECT v.sucursal_id,v.usuario_id,u.nombre cajero,
            SUM(CASE WHEN v.estado='PAGADA' THEN 1 ELSE 0 END) pagadas,
            SUM(CASE WHEN v.estado='CANCELADA' THEN 1 ELSE 0 END) canceladas,
            COALESCE(SUM(CASE WHEN v.estado='PAGADA' THEN v.total ELSE 0 END),0) venta_vigente
            FROM pos_ventas v LEFT JOIN usuarios u ON u.id=v.usuario_id
            WHERE v.turno_id IN ($ph) AND v.estado IN ('PAGADA','CANCELADA')
            GROUP BY v.sucursal_id,v.usuario_id,u.nombre",$ids) as $r){
            $sid=(int)$r['sucursal_id'];$uid=(int)$r['usuario_id'];$clave=$sid.':'.$uid;
            $cajeros[$clave]=[
                'sucursal_id'=>$sid,'sucursal'=>$sucursales[$sid]['nombre']??'No identificada',
                'usuario_id'=>$uid,'cajero'=>(string)($r['cajero']??'Usuario no identificado'),
                'pagadas'=>(int)$r['pagadas'],'canceladas'=>(int)$r['canceladas'],
                'venta_vigente'=>gac_centavos($r['venta_vigente']),
                'efectivo'=>0,'tarjeta'=>0,'transferencia'=>0,
            ];
            if(isset($sucursales[$sid]))$sucursales[$sid]['venta_vigente']+=$cajeros[$clave]['venta_vigente'];
        }
        // Agrupar pagos en una consulta separada evita multiplicar el importe de una venta mixta.
        foreach(gac_query($db,"SELECT v.sucursal_id,v.usuario_id,p.metodo,
            COALESCE(SUM(p.monto),0) importe
            FROM pos_pagos p JOIN pos_ventas v ON v.id=p.venta_id
            WHERE v.turno_id IN ($ph) AND v.estado='PAGADA' AND p.estado='CONFIRMADO'
            AND p.metodo IN ('EFECTIVO','TARJETA','TRANSFERENCIA')
            GROUP BY v.sucursal_id,v.usuario_id,p.metodo",$ids) as $r){
            $clave=(int)$r['sucursal_id'].':'.(int)$r['usuario_id'];
            if(!isset($cajeros[$clave]))continue;
            $key=match((string)$r['metodo']){'EFECTIVO'=>'efectivo','TARJETA'=>'tarjeta','TRANSFERENCIA'=>'transferencia'};
            $cajeros[$clave][$key]=gac_centavos($r['importe']);
        }
    }
    uasort($cajeros,static fn($a,$b)=>[$a['sucursal_id'],$a['cajero']]<=>[$b['sucursal_id'],$b['cajero']]);
    $sucursales=array_values($sucursales);
    $resumen=[
        'ventas_vigentes'=>array_sum(array_column($sucursales,'venta_vigente')),
        'pagadas'=>array_sum(array_column($sucursales,'pagadas')),
        'canceladas'=>array_sum(array_column($sucursales,'canceladas')),
        'reembolsos'=>array_sum(array_column($sucursales,'reembolso_efectivo'))+array_sum(array_column($sucursales,'reembolso_electronico')),
        'retiros'=>array_sum(array_column($sucursales,'salidas')),
        'faltantes'=>array_sum(array_column($sucursales,'faltantes')),
        'sobrantes'=>array_sum(array_column($sucursales,'sobrantes')),
    ];
    return ['sucursales'=>$sucursales,'cajeros'=>array_values($cajeros),'turnos'=>$turnos,
        'resumen'=>$resumen,'panel'=>$panel,'limitado'=>(bool)$panel['limitado']];
}
/** Neutralizar celdas que Excel puede interpretar como fórmula al abrir un CSV. */
function gr_csv_celda(mixed $valor):string {
    $s=(string)$valor;
    if(preg_match('/^-?\d+(?:\.\d+)?$/D',$s))return $s; // importe ya validado, numérico para Excel
    if(preg_match('/^[\s\x00-\x1F]*[=+\-@]/u',$s))return "'".$s;
    return $s;
}
function gr_exportar_csv(array $datos,string $tipo,string $desde,string $hasta):void {
    if(!in_array($tipo,['sucursales','cajeros','turnos'],true))throw new DomainException('Tipo de exportación no válido.');
    if($datos['limitado'])throw new DomainException('El filtro supera 250 turnos. Reduce el rango antes de exportar para evitar un reporte incompleto.');
    $nombre='gyd_reporte_'.$tipo.'_'.$desde.'_'.$hasta.'.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$nombre.'"');
    header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
    $out=fopen('php://output','wb');if(!$out)throw new RuntimeException('No se pudo generar el CSV.');
    fwrite($out,"\xEF\xBB\xBF");
    $send=static function(array $values)use($out):void{fputcsv($out,array_map('gr_csv_celda',$values),';','"','');};
    $mon=static fn(?int $centavos)=>$centavos===null?'':number_format($centavos/100,2,'.','');
    if($tipo==='sucursales'){
        $send(['Sucursal','Turnos','Abiertos','Cerrados','Ventas vigentes MXN','Ventas pagadas','Ventas canceladas',
            'Cobros caja efectivo MXN','Cobros caja tarjeta MXN','Cobros caja transferencia MXN','Salidas caja MXN',
            'Reembolsos efectivo MXN (incluidos en salidas)','Reembolsos electrónicos MXN',
            'Faltantes acumulados MXN','Sobrantes acumulados MXN','Turnos con alertas']);
        foreach($datos['sucursales'] as $s)$send([$s['nombre'],$s['turnos'],$s['abiertos'],$s['cerrados'],$mon($s['venta_vigente']),$s['pagadas'],$s['canceladas'],
            $mon($s['cobro_efectivo']),$mon($s['cobro_tarjeta']),$mon($s['cobro_transferencia']),$mon($s['salidas']),
            $mon($s['reembolso_efectivo']),$mon($s['reembolso_electronico']),$mon($s['faltantes']),$mon($s['sobrantes']),$s['alertas']]);
    }elseif($tipo==='cajeros'){
        $send(['Sucursal','Cajero','ID cajero','Ventas pagadas','Ventas canceladas','Ventas vigentes MXN',
            'Pagos en efectivo de ventas vigentes MXN','Tarjeta ventas vigentes MXN','Transferencia ventas vigentes MXN']);
        foreach($datos['cajeros'] as $c)$send([$c['sucursal'],$c['cajero'],$c['usuario_id'],$c['pagadas'],$c['canceladas'],$mon($c['venta_vigente']),$mon($c['efectivo']),$mon($c['tarjeta']),$mon($c['transferencia'])]);
    }else{
        $send(['Turno','Sucursal','Caja','Fecha apertura','Fecha cierre','Estado','Usuario apertura','Usuario cierre',
            'Autorizador','Ventas pagadas','Canceladas','Fondo inicial MXN','Cobros efectivo MXN','Cobros tarjeta MXN',
            'Cobros transferencia MXN','Ingresos MXN','Salidas MXN','Reembolsos efectivo incluidos en salidas MXN',
            'Efectivo esperado MXN','Efectivo contado MXN','Diferencia MXN','Alertas']);
        foreach($datos['turnos'] as $t)$send([$t['id'],$t['sucursal'],$t['caja'],$t['abierto_en'],$t['cerrado_en'],$t['estado'],
            $t['abierto_por'],$t['cerrado_por'],$t['autorizador'],$t['ventas_pagadas'],$t['ventas_canceladas'],$mon($t['fondo']),
            $mon($t['efectivo']),$mon($t['tarjeta']),$mon($t['transferencia']),$mon($t['ingresos']),$mon($t['retiros']),
            $mon($t['reembolso_efectivo']),$mon($t['esperado']),$mon($t['contado']),$mon($t['diferencia']),implode(' | ',$t['avisos'])]);
    }
    fclose($out);
}
