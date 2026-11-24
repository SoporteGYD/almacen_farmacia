<?php
/**
 * G&D - Análisis de inventarios de SOLO LECTURA.
 * Cada sucursal tiene su stock independiente. Sin creación ni ajuste de cantidades.
 */
declare(strict_types=1);

function gai_html(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function gai_num(?int $v): string { return $v === null ? '—' : number_format($v, 0, '.', ','); }
function gai_lower(string $value):string {return function_exists('mb_strtolower') ? mb_strtolower($value,'UTF-8') : strtolower($value);}
function gai_branches(): array { return [1=>'Ciudad Hidalgo',2=>'Tuxtla Gutiérrez',3=>'Tapachula',4=>'Plaza Toscana']; }
function gai_table_exists(PDO $db, string $table): bool {
    $st=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute([$table]); return (int)$st->fetchColumn()===1;
}
function gai_check_connection(PDO $db): void {
    $r=$db->query('SELECT DATABASE() AS nombre, @@port AS puerto')->fetch(PDO::FETCH_ASSOC);
    if(($r['nombre']??'')!=='prueba' || (int)($r['puerto']??0)!==3307) {
        throw new RuntimeException('Este panel está habilitado exclusivamente para la base de pruebas prueba:3307.');
    }
}
/** Datos exactos o NULL si no existe conteo; nunca reemplazar una fila ausente por cero. */
function gai_make_alerts(int $sid, array $p, ?int $general, ?int $farmacia, int $almacen, int $negAlmacen=0, ?int $minAlmacen=null): array {
    $out=[];
    $add=static function(string $tipo,string $nivel,string $detalle,?int $diferencia=null) use (&$out):void {
        $out[]=['tipo'=>$tipo,'nivel'=>$nivel,'detalle'=>$detalle,'diferencia'=>$diferencia];
    };
    if($general===null) {
        $add('SIN_REGISTRO','alta','No existe registro de stock General POS para esta sucursal. No se supone existencia cero.');
    } else {
        if($general<0)$add('NEGATIVO','critica','General POS presenta una existencia negativa.');
        if($general===0)$add('AGOTADO','media','La existencia General POS registrada es cero.');
    }
    if(in_array($sid,[1,2],true)) {
        if($farmacia===null){
            $add('SIN_CONTEO','alta','No existe conteo de Farmacia para este producto; la relación General = Farmacia + Almacén no se puede verificar.');
        } else {
            if($farmacia<0)$add('NEGATIVO','critica','Farmacia tiene una existencia negativa.');
            if($general!==null){
                $delta=$general-($farmacia+$almacen);
                if($delta!==0)$add('DESCUADRE',abs($delta)>5?'critica':'alta','La suma de Farmacia + Almacén no coincide con el General POS. Requiere investigar movimientos y conteos; no se ajustará automáticamente.',$delta);
            }
        }
        if($negAlmacen>0){
            $add('NEGATIVO','critica','Almacén registra '.$negAlmacen.' ubicación(es) con existencia negativa'.($minAlmacen!==null?' (mínimo '.gai_num($minAlmacen).')':'').'.');
        }
    }
    return $out;
}
/**
 * Escaneo por lotes: a lo sumo una consulta por tabla, sin consultas por producto.
 * Los conteos actuales NO son saldos históricos.
 */
function gai_scan(PDO $db,int $dias=30): array {
    if(!in_array($dias,[7,15,30],true))$dias=30;
    gai_check_connection($db);
    foreach(['productos','pos_existencias_farmacia','erp_pos_farmacia_control','producto_existencias'] as $t){
        if(!gai_table_exists($db,$t))throw new RuntimeException('Falta tabla para consultar inventarios: '.$t);
    }
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->beginTransaction();
    try{
        $products=$db->query('SELECT id,codigo,descripcion FROM productos WHERE estado=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $pids=[];foreach($products as $p)$pids[(int)$p['id']]=true;
        $general=[];
        $s=$db->query('SELECT sucursal_id,producto_id,existencia FROM pos_existencias_farmacia WHERE sucursal_id IN (1,2,3,4)');
        while($r=$s->fetch(PDO::FETCH_ASSOC)){
            $sid=(int)$r['sucursal_id'];$pid=(int)$r['producto_id'];
            $general[$sid][$pid]=(int)$r['existencia'];
        }
        $farmacia=[];
        $s=$db->query('SELECT sucursal_id,producto_id,existencia FROM erp_pos_farmacia_control WHERE sucursal_id IN (1,2)');
        while($r=$s->fetch(PDO::FETCH_ASSOC)){
            $farmacia[(int)$r['sucursal_id']][(int)$r['producto_id']]=(int)$r['existencia'];
        }
        $almacen=[];
        $sql="SELECT producto_id,
            CASE
               WHEN UPPER(TRIM(sucursal)) COLLATE utf8mb4_general_ci IN ('CIUDAD HIDALGO','CD HIDALGO') THEN 1
               WHEN UPPER(TRIM(sucursal)) COLLATE utf8mb4_general_ci IN ('TUXTLA','TUXTLA GUTIERREZ','TUXTLA GUTIÉRREZ') THEN 2
               ELSE 0 END AS sid,
            SUM(CASE WHEN TRIM(COALESCE(ubicacion,''))<>'' AND UPPER(TRIM(ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION','SIN UBICACIÓN') THEN GREATEST(existencia,0) ELSE 0 END) AS stock,
            SUM(CASE WHEN existencia<0 THEN 1 ELSE 0 END) AS negativos,
            MIN(existencia) AS minimo
            FROM producto_existencias
            WHERE UPPER(TRIM(sucursal)) COLLATE utf8mb4_general_ci IN ('CIUDAD HIDALGO','CD HIDALGO','TUXTLA','TUXTLA GUTIERREZ','TUXTLA GUTIÉRREZ')
            GROUP BY producto_id,sid";
        $s=$db->query($sql);
        while($r=$s->fetch(PDO::FETCH_ASSOC)){
            $sid=(int)$r['sid'];if(!in_array($sid,[1,2],true))continue;
            $almacen[$sid][(int)$r['producto_id']]=['stock'=>(int)$r['stock'],'negativos'=>(int)$r['negativos'],'minimo'=>(int)$r['minimo']];
        }
        $pool='INEXISTENTE';
        if(gai_table_exists($db,'gyd_tt_pool_config')){
            $active=(int)$db->query('SELECT COALESCE(MAX(activo),0) FROM gyd_tt_pool_config')->fetchColumn();
            $pool=$active?'ACTIVO':'INACTIVO';
        }
        $auditStatus=['estado'=>'AUSENTE','disparadores'=>0,'avisos'=>[]];
        $triggerNames=['gyd_stock_pg_ai','gyd_stock_pg_au','gyd_stock_pg_ad','gyd_stock_fc_ai','gyd_stock_fc_au','gyd_stock_fc_ad','gyd_stock_pe_ai','gyd_stock_pe_au','gyd_stock_pe_ad'];
        $quoted=implode(',',array_fill(0,count($triggerNames),'?'));
        $st=$db->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME IN ('.$quoted.')');
        $st->execute($triggerNames);$auditStatus['disparadores']=(int)$st->fetchColumn();
        if(gai_table_exists($db,'gyd_stock_auditoria_real')){
            $auditStatus['estado']=$auditStatus['disparadores']===9?'ACTIVA':'PARCIAL';
            // Comprobación documental limitada a inconsistencias probables, no presume que toda venta tenga referencia.
            $st=$db->query("SELECT id, creado_en,area,operacion,producto_antes,producto_despues,sucursal_antes,sucursal_despues,
                existencia_antes,existencia_despues,diferencia,folio
                FROM gyd_stock_auditoria_real
                WHERE creado_en >= (NOW() - INTERVAL ".$dias." DAY) AND operacion='UPDATE'
                AND ((existencia_antes IS NOT NULL AND existencia_despues IS NOT NULL AND diferencia IS NOT NULL
                      AND diferencia <> existencia_despues-existencia_antes)
                    OR (NOT (producto_antes <=> producto_despues)) OR (NOT (sucursal_antes <=> sucursal_despues)))
                ORDER BY id DESC ");
            $auditStatus['avisos']=$st->fetchAll(PDO::FETCH_ASSOC);
        }
        $rows=[];$stats=['revisados'=>0,'alertas'=>0,'criticas'=>0,'sin_registro'=>0,'descuadres'=>0,'agotados'=>0,'negativos'=>0,'sin_conteo'=>0,'auditoria'=>0];
        foreach(gai_branches() as $sid=>$branch){
            foreach($products as $p){
                $pid=(int)$p['id'];$g=$general[$sid][$pid]??null;
                $f=in_array($sid,[1,2],true)?($farmacia[$sid][$pid]??null):null;
                $wh=$almacen[$sid][$pid]??['stock'=>0,'negativos'=>0,'minimo'=>null];
                $issues=gai_make_alerts($sid,$p,$g,$f,(int)$wh['stock'],(int)$wh['negativos'],$wh['minimo']===null?null:(int)$wh['minimo']);
                $stats['revisados']++;
                foreach($issues as $issue){
                    $type=$issue['tipo'];$stats['alertas']++;
                    if($issue['nivel']==='critica')$stats['criticas']++;
                    $statKey=['SIN_REGISTRO'=>'sin_registro','SIN_CONTEO'=>'sin_conteo','DESCUADRE'=>'descuadres','AGOTADO'=>'agotados','NEGATIVO'=>'negativos'][$type]??null;
                    if($statKey)$stats[$statKey]++;
                    $rows[]=['sucursal_id'=>$sid,'sucursal'=>$branch,'producto_id'=>$pid,
                        'codigo'=>(string)$p['codigo'],'producto'=>(string)$p['descripcion'],'general'=>$g,'farmacia'=>$f,
                        'almacen'=>in_array($sid,[1,2],true)?(int)$wh['stock']:null,
                        'diferencia'=>$issue['diferencia'],'tipo'=>$type,'nivel'=>$issue['nivel'],'detalle'=>$issue['detalle'],
                        'fecha'=>null];
                }
            }
        }
        foreach($auditStatus['avisos'] as $r){
            $sid=(int)($r['sucursal_despues']??$r['sucursal_antes']??0);
            if(!isset(gai_branches()[$sid]))continue;
            $pid=(int)($r['producto_despues']??$r['producto_antes']??0);
            $pname='Producto #'.$pid;
            $code='—';
            // Lookup only from already loaded products, never another query per anomaly.
            if($pid>0 && isset($pids[$pid])){
                // map built below in caller if possible; avoid inventing product labels.
                $pname='Producto #'.$pid;
            }
            $rows[]=['sucursal_id'=>$sid,'sucursal'=>gai_branches()[$sid],'producto_id'=>$pid,
                'codigo'=>$code,'producto'=>$pname,'general'=>null,'farmacia'=>null,'almacen'=>null,'diferencia'=>null,
                'tipo'=>'AUDITORIA','nivel'=>'alta','detalle'=>'Cambio de identidad, sucursal o diferencia no consistente en registro de auditoría #'.(int)$r['id'].'. Revisar el movimiento; no se modifica ningún saldo.',
                'fecha'=>$r['creado_en']];
            $stats['alertas']++;$stats['auditoria']++;
        }
        $db->commit();
        return ['rows'=>$rows,'stats'=>$stats,'pool'=>$pool,'audit'=>$auditStatus,'count_products'=>count($products),'consultado_en'=>date('Y-m-d H:i:s')];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function gai_filter(array $rows,int $sid,string $tipo,string $nivel,string $q): array {
    $search=gai_lower(trim($q));
    return array_values(array_filter($rows,static function(array $r)use($sid,$tipo,$nivel,$search):bool{
        if($sid!==0 && (int)$r['sucursal_id']!==$sid)return false;
        if($tipo!=='TODOS' && $r['tipo']!==$tipo)return false;
        if($nivel!=='TODOS' && $r['nivel']!==$nivel)return false;
        if($search!=='' && !str_contains(gai_lower($r['codigo'].' '.$r['producto']),$search))return false;
        return true;
    }));
}
