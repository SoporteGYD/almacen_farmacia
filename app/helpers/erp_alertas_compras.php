<?php
declare(strict_types=1);
/** G&D: alertas calculadas con facturas del proveedor, sin escrituras de BD. */
if (!function_exists('gd_cp_group')) require_once __DIR__.'/erp_comparador_proveedores.php';
function gd_ac_num(mixed $value, float $default, float $min, float $max):float {
    if (!is_scalar($value) || !is_numeric((string)$value))return $default;
    $n=(float)$value;
    return is_finite($n)?max($min,min($max,$n)):$default;
}
function gd_ac_filters(array $get):array {
    $base=gd_cp_parameters($get);
    $type=is_string($get['tipo']??null)?$get['tipo']:'TODAS';
    if(!in_array($type,['TODAS','SUBIDA','MARGEN_BAJO','PERDIDA','OPORTUNIDAD','SIN_PRECIO'],true))$type='TODAS';
    $base['tipo']=$type;
    $base['subida']=gd_ac_num($get['subida']??20,20,1,300);
    $base['margen']=gd_ac_num($get['margen']??15,15,0,99);
    $base['ahorro']=gd_ac_num($get['ahorro']??8,8,1,99);
    return $base;
}
function gd_ac_recent(array $r):bool {return ($r['costo']??null)!==null && !$r['antiguo'] && !$r['riesgo'] && ($r['dias']??-1)>=0;}
function gd_ac_event(array $p,array $provider,string $kind,string $severity,string $message,?float $delta=null):array {
    return ['tipo'=>$kind,'nivel'=>$severity,'producto_id'=>(int)$p['producto_id'],
      'codigo'=>$p['codigo'],'descripcion'=>$p['descripcion'],'proveedor_id'=>(int)($provider['id']??0),'factura_id'=>(int)($provider['factura_id']??0),'proveedor'=>$provider['proveedor']??'—',
      'fecha'=>$provider['fecha']??'','factura'=>$provider['factura']??'',
      'costo'=>$provider['costo']??null,'precio'=>$p['precio'],
      // GYD_ALERTAS_FIX_V2_IDS: preservar factura y proveedor de la compra consultada.
      'factura_id'=>(int)($provider['factura_id']??0),'proveedor_id'=>(int)($provider['id']??0),
      'variacion'=>$delta,'texto'=>$message];
}
/** $products = resultado gd_cp_group; solo facturas con proveedores validos. */
function gd_ac_build(array $products,array $filter):array {
    $output=[];
    foreach($products as $p){
        $recent=[];
        foreach($p['proveedores'] as $prov){
            if(!gd_ac_recent($prov))continue;
            $recent[]=$prov;
            $previous=$prov['anterior']??null;
            $increase=gd_cp_percent($prov['costo'],$previous);
            if($increase!==null&&$increase>=$filter['subida']){
                $output[]=gd_ac_event($p,$prov,'SUBIDA','ALTA',
                'El costo de factura subió frente a la factura anterior del mismo proveedor.',$increase);
            }
        }
        if(!$recent)continue;
        // Fecha e ID de factura: identifica la última compra observada dentro del periodo.
        usort($recent,static function($a,$b){
            $d=strcmp((string)$b['fecha'],(string)$a['fecha']);
            return $d?:((int)$b['factura_id']<=> (int)$a['factura_id']);
        });
        $last=$recent[0];$price=$p['precio'];
        if($price===null){
            $output[]=gd_ac_event($p,$last,'SIN_PRECIO','MEDIA',
               'No existe un precio positivo registrado para la sucursal elegida; no se calcula margen.');
        }else{
            $margin=gd_cp_margin($last['costo'],$price);
            if($margin!==null&&$margin<0){
                $output[]=gd_ac_event($p,$last,'PERDIDA','ALTA',
                    'El precio público actual está por debajo del último costo facturado observado.', $margin);
            }elseif($margin!==null&&$margin<$filter['margen']){
                $output[]=gd_ac_event($p,$last,'MARGEN_BAJO','MEDIA',
                    'El margen bruto estimado es inferior al mínimo seleccionado.', $margin);
            }
        }
        $best=$recent[0];
        foreach($recent as $candidate){if($candidate['costo']<$best['costo'])$best=$candidate;}
        if(count($recent)>1 && $last['id']!==$best['id'] && $best['costo']>0){
            // Potencial unitario frente a la última factura observada, no una oferta vigente.
            $delta=100*($last['costo']-$best['costo'])/$last['costo'];
            if($delta>=$filter['ahorro']){
                $output[]=gd_ac_event($p,$last,'OPORTUNIDAD','MEDIA',
                  'Se facturó a '.$last['proveedor'].' a un costo mayor que el observado con '.$best['proveedor'].' ('.$best['fecha'].'). Costo alternativo histórico: '.gd_cp_money($best['costo']).'. Diferencia por pieza: '.gd_cp_money($last['costo']-$best['costo']).'. No es cotización vigente.', $delta);
            }
        }
    }
    $order=['ALTA'=>0,'MEDIA'=>1];
    usort($output,static function($a,$b)use($order){return ($order[$a['nivel']]<=>$order[$b['nivel']])?:strcmp($b['fecha'],$a['fecha'])?:strcmp($a['codigo'],$b['codigo']);});
    return $output;
}
function gd_ac_type_name(string $type):string {
    return ['SUBIDA'=>'Aumento de costo','MARGEN_BAJO'=>'Margen bajo','PERDIDA'=>'Precio por debajo del costo',
      'OPORTUNIDAD'=>'Posible ahorro','SIN_PRECIO'=>'Precio no configurado'][$type]??$type;
}
function gd_ac_type_filter(array $items,string $type):array {
    return $type==='TODAS'?$items:array_values(array_filter($items,static fn($a)=>$a['tipo']===$type));
}
function gd_ac_export_safe(mixed $v):string {return gd_cp_csv_text($v);}
