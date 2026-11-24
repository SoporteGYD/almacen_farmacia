<?php
declare(strict_types=1);
/**
 * ERP G&D: consultas de SOLO LECTURA del Catálogo Maestro.
 * Columnas, filtros y ordenamientos provienen exclusivamente de listas de servidor.
 */
function gyd_cr_permitido(string $rol): bool {
    return in_array(strtoupper(trim($rol)), [
        'ADMINISTRADOR','ADMIN','DIRECCION','DUENO','DUEÑO','GERENTE','COMPRAS','CONSULTA'
    ], true);
}
function gyd_cr_ver_costos(string $rol): bool {
    // Misma restricción que Reportes de Almacén: solo administración (Dirección adopta ese rol al iniciar sesión).
    return in_array(strtoupper(trim($rol)), ['ADMINISTRADOR','ADMIN','COMPRAS'], true);
}
function gyd_cr_hay_ficha(PDO $pdo): bool {
    $st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute(['erp_producto_ficha']);
    return (int)$st->fetchColumn() > 0;
}
function gyd_cr_columnas(bool $hayFicha, bool $verCostos): array {
    $c = [
        'codigo'=>['Código','p.codigo','texto'],
        'codigo_barras'=>['Código de barras','p.codigo_barras','texto'],
        'descripcion'=>['Descripción','p.descripcion','texto'],
        'laboratorio'=>['Laboratorio / marca','p.laboratorio','texto'],
        'categoria'=>['Categoría','c.nombre','texto'],
        'proveedor'=>['Proveedor','pr.nombre','texto'],
        'unidad'=>['Unidad de medida','p.unidad_medida','texto'],
        'estado'=>['Estado',"CASE WHEN p.estado=1 THEN 'Activo' ELSE 'Inactivo' END",'texto'],
        'integridad'=>['Ficha de catálogo',"CASE WHEN p.codigo_barras IS NULL OR TRIM(p.codigo_barras)='' OR p.laboratorio IS NULL OR TRIM(p.laboratorio)='' OR p.unidad_medida IS NULL OR TRIM(p.unidad_medida)='' OR c.id IS NULL OR pr.id IS NULL THEN 'Datos incompletos' ELSE 'Completa' END",'texto'],
    ];
    if ($hayFicha) {
        $c += [
            'tipo'=>['Tipo de producto','f.tipo','texto'],
            'subfamilia'=>['Subfamilia','f.subfamilia','texto'],
            'unidad_compra'=>['Unidad de compra','f.unidad_compra','texto'],
            'factor_compra'=>['Factor de compra','f.factor_compra','numero'],
            'iva_compra'=>['IVA de compra (%)','f.iva_compra','impuesto'],
            'iva_venta'=>['IVA de venta (%)','f.iva_venta','impuesto'],
            'ieps'=>['IEPS (%)','f.ieps','impuesto'],
            'margen_sugerido'=>['Margen sugerido (%)','f.margen_sugerido','porcentaje'],
        ];
    }
    if ($verCostos) {
        $c += [
            'costo_ultimo'=>['Último costo','p.costo_ultimo','moneda'],
            'costo_promedio'=>['Costo promedio','p.costo_promedio','moneda4'],
            'precio_compra'=>['Costo de catálogo','p.precio_compra','moneda'],
            'precio_venta'=>['Precio de venta base','p.precio_venta','moneda'],
        ];
        if ($hayFicha) $c['precio_sugerido']=['Precio sugerido','f.precio_sugerido','moneda'];
    }
    return $c;
}
function gyd_cr_solicitud(array $get, array $disponibles, bool $hayFicha): array {
    $default=['codigo','codigo_barras','descripcion','laboratorio','categoria','proveedor','estado'];
    $raw=$get['columnas']??$default;
    if(!is_array($raw))$raw=$default;
    $cols=[];
    foreach($raw as $v){if(is_string($v) && isset($disponibles[$v]) && !in_array($v,$cols,true))$cols[]=$v;}
    if(!$cols)$cols=array_values(array_intersect($default,array_keys($disponibles)));
    $orden=(string)($get['orden']??'descripcion');
    if(!in_array($orden,['descripcion','codigo','categoria','proveedor','estado'],true))$orden='descripcion';
    $estado=(string)($get['estado']??'todos');
    if(!in_array($estado,['todos','activos','inactivos'],true))$estado='todos';
    $completitud=(string)($get['datos']??'todos');
    if(!in_array($completitud,['todos','completos','incompletos','sin_codigo_barras','sin_proveedor','sin_categoria','sin_laboratorio','sin_unidad'],true))$completitud='todos';
    $iva=(string)($get['iva']??'todos');
    if(!$hayFicha || !in_array($iva,['todos','sin_configurar','configurado','cero','ocho','dieciseis'],true))$iva='todos';
    return [
        'q'=>mb_substr(trim((string)($get['q']??'')),0,150),
        'laboratorio'=>mb_substr(trim((string)($get['laboratorio']??'')),0,100),
        'categoria'=>max(0,(int)($get['categoria']??0)),
        'proveedor'=>max(0,(int)($get['proveedor']??0)),
        'estado'=>$estado, 'datos'=>$completitud, 'iva'=>$iva,
        'orden'=>$orden, 'columnas'=>$cols,
        'pagina'=>max(1,min(10000000,(int)($get['pagina']??1))),
    ];
}
function gyd_cr_where(array $s, bool $hayFicha): array {
    $c=[];$p=[];
    if($s['q']!==''){
        foreach(['p.codigo','p.codigo_barras','p.descripcion','p.laboratorio'] as $i=>$col){$k='q'.$i;$terms[]="$col LIKE :$k";$p[$k]='%'.$s['q'].'%';}
        $c[]='('.implode(' OR ',$terms).')';
    }
    if($s['laboratorio']!==''){$c[]='p.laboratorio LIKE :laboratorio';$p['laboratorio']='%'.$s['laboratorio'].'%';}
    if($s['categoria']>0){$c[]='p.categoria_id=:categoria';$p['categoria']=$s['categoria'];}
    if($s['proveedor']>0){$c[]='p.proveedor_id=:proveedor';$p['proveedor']=$s['proveedor'];}
    if($s['estado']==='activos')$c[]='p.estado=1';
    if($s['estado']==='inactivos')$c[]='p.estado=0';
    $sinB="(p.codigo_barras IS NULL OR TRIM(p.codigo_barras)='')";
    $sinL="(p.laboratorio IS NULL OR TRIM(p.laboratorio)='')";
    $sinU="(p.unidad_medida IS NULL OR TRIM(p.unidad_medida)='')";
    $sinC='(c.id IS NULL)';$sinP='(pr.id IS NULL)';
    $incomp='('.implode(' OR ',[$sinB,$sinL,$sinU,$sinC,$sinP]).')';
    $maps=['incompletos'=>$incomp,'completos'=>'NOT '.$incomp,'sin_codigo_barras'=>$sinB,
        'sin_laboratorio'=>$sinL,'sin_unidad'=>$sinU,'sin_categoria'=>$sinC,'sin_proveedor'=>$sinP];
    if(isset($maps[$s['datos']]))$c[]=$maps[$s['datos']];
    if($hayFicha){
        if($s['iva']==='sin_configurar')$c[]='f.iva_compra IS NULL';
        if($s['iva']==='configurado')$c[]='f.iva_compra IS NOT NULL';
        $valores=['cero'=>0,'ocho'=>8,'dieciseis'=>16];
        if(isset($valores[$s['iva']])){$c[]='f.iva_compra=:iva_porcentaje';$p['iva_porcentaje']=$valores[$s['iva']];}
    }
    return [$c?' WHERE '.implode(' AND ',$c):'',$p];
}
function gyd_cr_desde(bool $hayFicha): string {
    return ' FROM productos p LEFT JOIN categorias c ON c.id=p.categoria_id LEFT JOIN proveedores pr ON pr.id=p.proveedor_id '.($hayFicha?' LEFT JOIN erp_producto_ficha f ON f.producto_id=p.id ':'');
}
function gyd_cr_orden(array $s): string {
    $map=['descripcion'=>'p.descripcion ASC','codigo'=>'p.codigo ASC','categoria'=>'c.nombre ASC','proveedor'=>'pr.nombre ASC','estado'=>'p.estado DESC'];
    return ' ORDER BY '.$map[$s['orden']].',p.id ASC';
}
function gyd_cr_consulta(PDO $pdo, array $s, bool $hayFicha, array $columnas, ?int $limite=null, int $offset=0): PDOStatement {
    [$where,$params]=gyd_cr_where($s,$hayFicha);
    $seleccion=[];
    foreach($s['columnas'] as $key){$seleccion[]=$columnas[$key][1].' AS `'.$key.'`';}
    $sql='SELECT '.implode(',',$seleccion).gyd_cr_desde($hayFicha).$where.gyd_cr_orden($s);
    if($limite!==null)$sql.=' LIMIT '.max(1,$limite).' OFFSET '.max(0,$offset);
    $st=$pdo->prepare($sql);$st->execute($params);return $st;
}
function gyd_cr_contar(PDO $pdo, array $s, bool $hayFicha): int {
    [$where,$params]=gyd_cr_where($s,$hayFicha);
    $st=$pdo->prepare('SELECT COUNT(*)'.gyd_cr_desde($hayFicha).$where);
    $st->execute($params);return (int)$st->fetchColumn();
}
function gyd_cr_valor($valor,string $tipo): string {
    if($valor===null || $valor==='')return $tipo==='impuesto'?'Sin configurar':'—';
    if($tipo==='moneda')return '$'.number_format((float)$valor,2,'.',',');
    if($tipo==='moneda4')return '$'.number_format((float)$valor,4,'.',',');
    if($tipo==='porcentaje'||$tipo==='impuesto')return number_format((float)$valor,2,'.',',').'%';
    if($tipo==='numero')return number_format((float)$valor,4,'.',',');
    return (string)$valor;
}
function gyd_cr_h($value):string { return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
