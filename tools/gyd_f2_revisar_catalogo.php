<?php
declare(strict_types=1);
/** F2 - diagnostico del catalogo. Solo lectura, no requiere sesion web. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo CLI.'); }
$args = $argv ?? [];
if (!in_array('--run', $args, true)) {
    echo "Uso: php tools/gyd_f2_revisar_catalogo.php --run --expect=prueba --port=3307 [--json]\n";
    exit(1);
}
function f2arg(array $args, string $name): ?string {
    foreach ($args as $arg) if (str_starts_with($arg, $name . '=')) return substr($arg, strlen($name)+1);
    return null;
}
$expect = f2arg($args,'--expect'); $port = f2arg($args,'--port');
if ($expect !== 'prueba' || $port === null || !ctype_digit($port)) {
    fwrite(STDERR, "Bloqueado: especifica --expect=prueba --port=3307.\n"); exit(2);
}
require_once __DIR__ . '/../app/config/database.php';
try {
    $pdo=(new Database())->connect();
    $info=$pdo->query('SELECT DATABASE() AS base, @@port AS puerto')->fetch(PDO::FETCH_ASSOC);
    if (($info['base']??'')!==$expect || (int)($info['puerto']??0)!==(int)$port) {
        throw new RuntimeException('La conexion activa no corresponde con la base y puerto esperados.');
    }
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('productos','erp_producto_ficha')")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('productos',$tables,true)) throw new RuntimeException('No existe la tabla productos.');
    $summary=$pdo->query("SELECT COUNT(*) total, COALESCE(SUM(estado=1),0) activos,
      COALESCE(SUM(estado<>1),0) inactivos,
      COALESCE(SUM(codigo IS NULL OR TRIM(codigo)=''),0) sin_codigo,
      COALESCE(SUM(codigo_barras IS NULL OR TRIM(codigo_barras)=''),0) sin_barras,
      COALESCE(SUM(descripcion IS NULL OR TRIM(descripcion)=''),0) sin_descripcion,
      COALESCE(SUM(laboratorio IS NULL OR TRIM(laboratorio)=''),0) sin_laboratorio,
      COALESCE(SUM(categoria_id IS NULL),0) sin_categoria,
      COALESCE(SUM(proveedor_id IS NULL),0) sin_proveedor,
      COALESCE(SUM(unidad_medida IS NULL OR TRIM(unidad_medida)=''),0) sin_unidad
      FROM productos")->fetch(PDO::FETCH_ASSOC);
    $codigoDupl=$pdo->query("SELECT UPPER(TRIM(codigo)) clave,COUNT(*) casos,GROUP_CONCAT(id ORDER BY id) ids FROM productos WHERE codigo IS NOT NULL AND TRIM(codigo)<>'' GROUP BY UPPER(TRIM(codigo)) HAVING COUNT(*)>1 ORDER BY casos DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
    $barrasDupl=$pdo->query("SELECT UPPER(TRIM(codigo_barras)) clave,COUNT(*) casos,GROUP_CONCAT(id ORDER BY id) ids FROM productos WHERE codigo_barras IS NOT NULL AND TRIM(codigo_barras)<>'' GROUP BY UPPER(TRIM(codigo_barras)) HAVING COUNT(*)>1 ORDER BY casos DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
    $cruces=$pdo->query("SELECT a.id producto_codigo,b.id producto_barras,a.codigo valor
      FROM productos a JOIN productos b ON a.id<>b.id
      AND UPPER(TRIM(a.codigo))=UPPER(TRIM(b.codigo_barras))
      WHERE TRIM(a.codigo)<>'' AND TRIM(b.codigo_barras)<>''
      ORDER BY a.id,b.id LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
    $idx=$pdo->query("SELECT INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) columnas
      FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='productos' AND NON_UNIQUE=0 GROUP BY INDEX_NAME ORDER BY INDEX_NAME")->fetchAll(PDO::FETCH_ASSOC);
    $ficha=in_array('erp_producto_ficha',$tables,true);
    $iva=['disponible'=>$ficha];
    if($ficha){
        $iva=$pdo->query("SELECT COUNT(*) AS fichas, COALESCE(SUM(iva_compra IS NULL),0) AS sin_iva_compra FROM erp_producto_ficha")->fetch(PDO::FETCH_ASSOC);
        $iva['disponible']=true;
    }
    $result=['base'=>$info['base'],'puerto'=>(int)$info['puerto'],'modo'=>'SOLO_LECTURA','resumen'=>$summary,'codigo_repetido_muestras'=>$codigoDupl,'barras_repetidas_muestras'=>$barrasDupl,'cruces_codigo_barras_muestras'=>$cruces,'indices_unicos'=>$idx,'ficha_fiscal'=>$iva];
    if(in_array('--json',$args,true))echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
    else {
        echo "REVISION F2 DE CATALOGO - SOLO LECTURA\nBase: {$info['base']} | Puerto: {$info['puerto']}\n";
        foreach($summary as $k=>$v)echo str_pad($k,22).": $v\n";
        echo 'Muestras de codigo duplicado: '.count($codigoDupl)."\n";
        echo 'Muestras de barras duplicadas: '.count($barrasDupl)."\n";
        echo 'Muestras de cruces codigo/barras: '.count($cruces)."\n";
        echo 'Indices unicos: '.implode(', ',array_map(static fn($r)=>$r['columnas'], $idx))."\n";
        echo 'Ficha fiscal: '.($ficha?'SI':'NO')."\n";
        if($ficha)echo 'Fichas sin IVA compra: '.$iva['sin_iva_compra']."\n";
        echo "Resultado informativo; no modifica registros. Para detalles usa --json.\n";
    }
} catch(Throwable $e) {
    fwrite(STDERR, 'REVISION NO COMPLETADA: '.$e->getMessage().PHP_EOL);
    exit(3);
}
