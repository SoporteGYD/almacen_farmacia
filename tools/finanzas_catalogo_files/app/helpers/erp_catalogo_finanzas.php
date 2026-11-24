<?php
declare(strict_types=1);
/** Panel analítico G&D. Consultas solo lectura, importes unitarios estimativos por pieza. */
function gd_fc_admin(string $rol):bool {return in_array(strtoupper(trim($rol)),['ADMINISTRADOR','ADMIN'],true);}
function gd_fc_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function gd_fc_tabla(PDO $db,string $tabla):bool {
    $st=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute([$tabla]);return (int)$st->fetchColumn()>0;
}
function gd_fc_columna(PDO $db,string $tabla,string $columna):bool {
    $st=$db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $st->execute([$tabla,$columna]);return (int)$st->fetchColumn()>0;
}
function gd_fc_num($v):?float{return ($v===null||$v==='')?null:(is_numeric($v)?(float)$v:null);}
function gd_fc_div(?float $a,?float $b):?float {return ($a!==null && $b!==null && $b>0)?$a/$b:null;}
function gd_fc_pct(?float $a,?float $b):?float {$d=gd_fc_div($a,$b);return $d===null?null:100*$d;}
function gd_fc_price($v):?float {$n=gd_fc_num($v);return $n!==null&&$n>0?$n:null;}
function gd_fc_calc(array $r,bool $sucursal):array {
    $last=gd_fc_price($r['costo_ultimo']??null);$avg=gd_fc_price($r['costo_promedio']??null);
    $buy=gd_fc_price($r['precio_compra']??null);$base=gd_fc_price($r['precio_venta']??null);
    $branch=gd_fc_price($r['precio_sucursal']??null);$price=$sucursal?$branch:$base;
    $may=gd_fc_price($r['precio_mayoreo']??null);$sug=gd_fc_price($r['precio_sugerido']??null);
    $prev=gd_fc_price($r['precio_anterior']??null);
    $flete=gd_fc_num($r['costo_flete_unitario']??null);$factor=gd_fc_price($r['factor_compra']??null);
    $porc=gd_fc_num($r['margen_sugerido']??null);$metodo=(string)($r['metodo_precio']??'');
    $costoFlete=($last!==null && $flete!==null && $flete>=0)?$last+$flete:null;
    $objetivo=null;
    if($last!==null && $porc!==null && $porc>=0){
      if($metodo==='MARK_UP')$objetivo=$last*(1+$porc/100);
      if($metodo==='MARK_DOWN'&&$porc<100)$objetivo=$last/(1-$porc/100);
    }
    $resultado=$r;
    $stock=$sucursal?gd_fc_num($r['stock_sucursal']??null):null;
    $resultado['valor_stock_ultimo']=($stock!==null && $last!==null)?$stock*$last:null;
    $resultado['valor_stock_promedio']=($stock!==null && $avg!==null)?$stock*$avg:null;
    $resultado['utilidad_potencial_stock']=($stock!==null && $last!==null && $price!==null)?$stock*($price-$last):null;
    $resultado['precio_activo']=$price;
    $resultado['origen_precio']=$sucursal?'Precio POS de sucursal':'Precio base de catálogo';
    $resultado['delta_ultimo_promedio']=($last!==null && $avg!==null)?$last-$avg:null;
    $resultado['variacion_ultimo_promedio']=($last!==null && $avg!==null)?gd_fc_pct($last-$avg,$avg):null;
    $resultado['variacion_ultimo_catalogo']=($last!==null && $buy!==null)?gd_fc_pct($last-$buy,$buy):null;
    $resultado['utilidad_ultimo']=($last!==null && $price!==null)?$price-$last:null;
    $resultado['utilidad_promedio']=($avg!==null && $price!==null)?$price-$avg:null;
    $resultado['margen_ultimo']=($last!==null && $price!==null)?gd_fc_pct($price-$last,$price):null;
    $resultado['margen_promedio']=($avg!==null && $price!==null)?gd_fc_pct($price-$avg,$price):null;
    $resultado['ganancia_sobre_ultimo']=($last!==null && $price!==null)?gd_fc_pct($price-$last,$last):null;
    $resultado['ganancia_sobre_promedio']=($avg!==null && $price!==null)?gd_fc_pct($price-$avg,$avg):null;
    $resultado['costo_sobre_venta']=($last!==null && $price!==null)?gd_fc_pct($last,$price):null;
    $resultado['costo_mas_flete']=$costoFlete;
    $resultado['utilidad_con_flete']=($price!==null && $costoFlete!==null)?$price-$costoFlete:null;
    $resultado['margen_con_flete']=($price!==null && $costoFlete!==null)?gd_fc_pct($price-$costoFlete,$price):null;
    $resultado['precio_objetivo_calculado']=$objetivo;
    $resultado['diferencia_precio_objetivo']=($objetivo!==null && $price!==null)?$price-$objetivo:null;
    $resultado['diferencia_precio_sugerido']=($sug!==null && $price!==null)?$price-$sug:null;
    $resultado['variacion_precio_anterior']=($prev!==null && $price!==null)?gd_fc_pct($price-$prev,$prev):null;
    $resultado['diferencia_precio_anterior']=($prev!==null && $price!==null)?$price-$prev:null;
    $resultado['margen_mayoreo_ultimo']=($last!==null && $may!==null)?gd_fc_pct($may-$last,$may):null;
    $resultado['utilidad_mayoreo_ultimo']=($last!==null && $may!==null)?$may-$last:null;
    $resultado['costo_por_caja']=($last!==null && $factor!==null)?$last*$factor:null;
    $resultado['precio_por_caja']=($price!==null && $factor!==null)?$price*$factor:null;
    $resultado['alerta_margen']=$price===null?'Sin precio':($last===null?'Sin último costo':($price<$last?'Venta bajo último costo':(($resultado['margen_ultimo']??0)<10?'Margen menor a 10%':'Margen por revisar')));
    if($price!==null && $last!==null && $price>=$last && ($resultado['margen_ultimo']??0)>=10)$resultado['alerta_margen']='Margen positivo';
    foreach($r as $clave=>$valor){
      if(!preg_match('/^precio_pos_(\d+)$/',(string)$clave,$m))continue;
      $p=gd_fc_price($valor);
      $resultado['margen_pos_'.$m[1]]=($p!==null&&$last!==null)?gd_fc_pct($p-$last,$p):null;
      $resultado['markup_pos_'.$m[1]]=($p!==null&&$last!==null)?gd_fc_pct($p-$last,$last):null;
    }
    return $resultado;
}
function gd_fc_metas():array {
    // [Nombre visible, tipo, grupo]. Una única lista controla pantalla y exportaciones.
    return [
      'codigo'=>['Código','texto','Identificación'], 'codigo_barras'=>['Código de barras','texto','Identificación'],
      'descripcion'=>['Descripción','texto','Identificación'], 'proveedor'=>['Proveedor','texto','Identificación'],
      'categoria'=>['Categoría','texto','Identificación'], 'laboratorio'=>['Laboratorio','texto','Identificación'],
      'unidad_medida'=>['Unidad de medida','texto','Identificación'], 'estado_texto'=>['Estado','texto','Identificación'],
      'costo_ultimo'=>['Último costo registrado','moneda4','Costos'],
      'costo_promedio'=>['Costo promedio ponderado','moneda4','Costos'],
      'precio_compra'=>['Costo del catálogo (referencia)','moneda4','Costos'],
      'delta_ultimo_promedio'=>['Diferencia último − promedio','moneda4','Costos'],
      'variacion_ultimo_promedio'=>['Variación último vs. promedio (%)','porcentaje','Costos'],
      'variacion_ultimo_catalogo'=>['Variación último vs. costo catálogo (%)','porcentaje','Costos'],
      'costo_flete_unitario'=>['Flete unitario registrado','moneda4','Costos'],
      'costo_mas_flete'=>['Último costo + flete registrado','moneda4','Costos'],
      'precio_venta'=>['Precio base del catálogo','moneda','Precios'],
      'precio_sucursal'=>['Precio público POS (sucursal elegida)','moneda','Precios'],
      'precio_activo'=>['Precio de comparación','moneda','Precios'],
      'precio_anterior'=>['Precio anterior (historial POS)','moneda','Precios'],
      'diferencia_precio_anterior'=>['Diferencia vs. precio anterior ($)','moneda','Precios'],
      'variacion_precio_anterior'=>['Variación vs. precio anterior (%)','porcentaje','Precios'],
      'precio_mayoreo'=>['Precio mayoreo por pieza','moneda','Precios'],
      'cantidad_minima'=>['Piezas mínimas para mayoreo','numero','Precios'],
      'precio_sugerido'=>['Precio sugerido guardado','moneda','Precios'],
      'margen_sugerido'=>['Porcentaje objetivo guardado','porcentaje','Precios'],
      'metodo_precio_texto'=>['Método de fijación de precio','texto','Precios'],
      'precio_objetivo_calculado'=>['Precio objetivo calculado con último costo','moneda','Precios'],
      'diferencia_precio_objetivo'=>['Precio actual − objetivo ($)','moneda','Precios'],
      'diferencia_precio_sugerido'=>['Precio actual − sugerido ($)','moneda','Precios'],
      'utilidad_ultimo'=>['Utilidad bruta teórica vs. último ($)','moneda4','Rentabilidad'],
      'margen_ultimo'=>['Margen s/ venta vs. último (%)','porcentaje','Rentabilidad'],
      'ganancia_sobre_ultimo'=>['Ganancia s/ costo último (%)','porcentaje','Rentabilidad'],
      'utilidad_promedio'=>['Utilidad bruta teórica vs. promedio ($)','moneda4','Rentabilidad'],
      'margen_promedio'=>['Margen s/ venta vs. promedio (%)','porcentaje','Rentabilidad'],
      'ganancia_sobre_promedio'=>['Ganancia s/ costo promedio (%)','porcentaje','Rentabilidad'],
      'costo_sobre_venta'=>['Último costo / precio (%)','porcentaje','Rentabilidad'],
      'utilidad_con_flete'=>['Utilidad teórica con flete ($)','moneda4','Rentabilidad'],
      'margen_con_flete'=>['Margen teórico con flete (%)','porcentaje','Rentabilidad'],
      'utilidad_mayoreo_ultimo'=>['Utilidad mayoreo vs. último ($)','moneda4','Rentabilidad'],
      'margen_mayoreo_ultimo'=>['Margen mayoreo vs. último (%)','porcentaje','Rentabilidad'],
      'alerta_margen'=>['Alerta de rentabilidad','texto','Rentabilidad'],
      'unidad_compra'=>['Unidad de compra','texto','Presentaciones'],
      'factor_compra'=>['Piezas por unidad de compra','numero','Presentaciones'],
      'costo_por_caja'=>['Costo teórico por caja','moneda4','Presentaciones'],
      'precio_por_caja'=>['Precio teórico por caja','moneda','Presentaciones'],
      'stock_sucursal'=>['Existencias POS en sucursal','numero','Existencias POS'],
      'valor_stock_ultimo'=>['Valor POS a último costo ($)','moneda4','Existencias POS'],
      'valor_stock_promedio'=>['Valor POS a costo promedio ($)','moneda4','Existencias POS'],
      'utilidad_potencial_stock'=>['Utilidad bruta potencial del stock ($)','moneda4','Existencias POS'],
      'iva_compra'=>['IVA de compra registrado (%)','impuesto','Impuestos'],
      'iva_venta'=>['IVA de venta registrado (%)','impuesto','Impuestos'],
      'ieps'=>['IEPS registrado (%)','impuesto','Impuestos'],
      'descuento_general'=>['Descuento general ficha (%)','porcentaje','Descuentos'],
      'descuento_1'=>['Descuento 1 ficha (%)','porcentaje','Descuentos'],
      'descuento_2'=>['Descuento 2 ficha (%)','porcentaje','Descuentos'],
      'descuento_3'=>['Descuento 3 ficha (%)','porcentaje','Descuentos'],
      'descuento_4'=>['Descuento 4 ficha (%)','porcentaje','Descuentos'],
    ];
}
function gd_fc_fmt($v,string $tipo):string {
    if($v===null||$v==='')return in_array($tipo,['impuesto'],true)?'Sin configurar':'—';
    if($tipo==='moneda')return '$'.number_format((float)$v,2,'.',',');
    if($tipo==='moneda4')return '$'.number_format((float)$v,4,'.',',');
    if($tipo==='porcentaje'||$tipo==='impuesto')return number_format((float)$v,2,'.',',').'%';
    if($tipo==='numero')return number_format((float)$v,2,'.',',');
    return (string)$v;
}
