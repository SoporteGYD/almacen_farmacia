<?php
/**
 * Instalador CONSERVADOR para ERP G&D: no sustituye Producto.php ni ProductoController.php.
 * Ejecutar solo en CLI después de respaldar BD. --check para simular, --apply para aplicar.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {http_response_code(403);exit("Solo terminal CLI\n");}
$mode=$argv[1]??'--check';if(!in_array($mode,['--check','--apply'],true)){exit("Uso: php tools/instalar_ficha_catalogo.php --check | --apply\n");}
$root=dirname(__DIR__);$changes=[];$problems=[];
function modify(string $relative,callable $transform):void{
 global $root,$changes,$problems;
 $full=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
 if(!is_file($full)){$problems[]="$relative no existe";return;}
 $from=file_get_contents($full);if($from===false){$problems[]="$relative no se puede leer";return;}
 try{$to=$transform($from);}catch(Throwable $e){$problems[]="$relative: ".$e->getMessage();return;}
 if($from!==$to)$changes[$full]=[$from,$to,$relative];
 echo ($from===$to?'YA INTEGRADO':'ACTUALIZAR')." $relative\n";
}
function replace_required(string $source,string $find,string $new,string $context):string{
 if(str_contains($source,$new))return $source;
 if(!str_contains($source,$find))throw new RuntimeException("No se encontró el bloque esperado: $context. No se sobrescribe este archivo.");
 return str_replace($find,$new,$source);
}
modify('app/controllers/ProductoController.php',function(string $s):string{
 $anchor="require_once __DIR__ . '/../helpers/audit.php';";
 $s=replace_required($s,$anchor,$anchor."\nrequire_once __DIR__ . '/../helpers/erp_productos_unicos.php';",'include verificador');
 if(str_contains($s,'gyd_producto_duplicado('))return $s;
 $before=<<<'PHPBLOCK'
        if ($this->productoModel->existsByCodigo($data['codigo'], $id)) {
            return [
                'success' => false,
                'message' => 'Ya existe un producto con ese código en el catálogo.'
            ];
        }
PHPBLOCK;
 $after=<<<'PHPBLOCK'
        // Verifica el catálogo compartido: código, barras, descripción y equivalencias.
        $pdoUnicos = (new Database())->connect();
        $existente = gyd_producto_duplicado($pdoUnicos, (string)$data['codigo'], (string)(($data['codigo_barras'] ?? '') ?: $data['codigo']), (string)$data['descripcion'], $id);
        if ($existente) return ['success'=>false,'message'=>gyd_duplicado_mensaje($existente)];
PHPBLOCK;
 return replace_required($s,$before,$after,'validación de ProductoController::validate');
});
modify('app/models/Producto.php',function(string $s):string{
 $anchor="require_once __DIR__ . '/Proveedor.php';";
 $s=replace_required($s,$anchor,$anchor."\nrequire_once __DIR__ . '/../helpers/erp_productos_unicos.php';",'include modelo');
 foreach(['create'=>'update','update'=>'reactivate','reactivate'=>'crearOActualizarCatalogo'] as $method=>$next){
  $start=strpos($s,'    public function '.$method.'(');$end=$start===false?false:strpos($s,'    public function '.$next.'(',$start);
  if($start===false||$end===false)throw new RuntimeException("No se localizó Producto::$method para insertar el cerrojo sin tocar inventarios.");
  $part=substr($s,$start,$end-$start);if(str_contains($part,'gyd_catalogo_producto_write'))continue;
  $opening=strpos($part,'{');$closing=strrpos($part,'}');if($opening===false||$closing===false||$closing<=$opening)throw new RuntimeException("Estructura irregular en Producto::$method");
  $exclude=$method==='create'?'null':'(int)$id';
  $prefix="\n        \$gyd_lock = (int)\$this->conn->query(\"SELECT GET_LOCK('gyd_catalogo_producto_write',10)\")->fetchColumn() === 1;\n".
          "        if (!\$gyd_lock) return false;\n".
          "        try {\n".
          "            \$hit = gyd_producto_duplicado(\$this->conn, (string)(\$data['codigo'] ?? ''), (string)((\$data['codigo_barras'] ?? '') ?: (\$data['codigo'] ?? '')), (string)(\$data['descripcion'] ?? ''), $exclude);\n".
          "            if (\$hit) return false;\n";
  $suffix="\n        } finally {\n            \$this->conn->query(\"SELECT RELEASE_LOCK('gyd_catalogo_producto_write')\");\n        }\n";
  $patched=substr($part,0,$opening+1).$prefix.substr($part,$opening+1,$closing-$opening-1).$suffix.substr($part,$closing);
  $s=substr($s,0,$start).$patched.substr($s,$end);
 }
 return $s;
});
modify('public/erp_catalogo_maestro.php',function(string $s):string{
 if(!str_contains($s,'erp_producto_alta.php')){
  $link='';
  $link.=<<<'LINKPHP'
<?php if(in_array($rol,['ADMINISTRADOR','ADMIN','COMPRAS','DIRECCION','DUENO','DUEÑO'],true)): ?>
LINKPHP;
  $link.='<a href="erp_producto_alta.php" style="background:white;color:#143d6c">+ Alta de artículo (WinCaja)</a>';
  $link.='<?php endif; ?>';
  $s=replace_required($s,'<a href="productos.php">← Volver a Productos</a>',$link.'<a href="productos.php">← Volver a Productos</a>','enlace catálogo');
 }
 // Permitir lectura del Catálogo Maestro a Compras y Dirección sin conceder acceso a inventarios.
 $orig="'ADMINISTRADOR','ADMIN','GERENTE','CONSULTA'";
 $nuevo="'ADMINISTRADOR','ADMIN','GERENTE','CONSULTA','COMPRAS','DIRECCION','DUENO','DUEÑO'";
 if(str_contains($s,$orig) && !str_contains($s,$nuevo))$s=str_replace($orig,$nuevo,$s);
 if(!str_contains($s,"../app/helpers/erp_productos_unicos.php")){
  $a="require_once __DIR__ . '/../app/config/database.php';";
  $s=replace_required($s,$a,$a."\nrequire_once __DIR__ . '/../app/helpers/erp_productos_unicos.php';",'verificador catálogo');
 }
 if(!str_contains($s,'gyd_producto_duplicado(') && str_contains($s,'editar_producto')){
  $a='SELECT id,codigo_barras,descripcion,laboratorio,categoria_id,proveedor_id,unidad_medida FROM productos';
  if(str_contains($s,$a))$s=str_replace($a,'SELECT id,codigo,codigo_barras,descripcion,laboratorio,categoria_id,proveedor_id,unidad_medida FROM productos',$s);
  $a=<<<'PHPBLOCK'
     if($codigoBarras!==''){
      $dup=$pdo->prepare('SELECT id FROM productos WHERE codigo_barras=? AND id<>? LIMIT 1');$dup->execute([$codigoBarras,$id]);
      if($dup->fetch())throw new RuntimeException('Ese código de barras ya pertenece a otro producto.');
     }
PHPBLOCK;
  $b=<<<'PHPBLOCK'
     $dup=gyd_producto_duplicado($pdo,(string)$anterior['codigo'],$codigoBarras,$descripcion,$id);
     if($dup)throw new RuntimeException(gyd_duplicado_mensaje($dup));
PHPBLOCK;
  $s=replace_required($s,$a,$b,'editor de Catálogo Maestro');
 }
 return $s;
});
modify('public/productos.php',function(string $s):string{
 if(str_contains($s,'erp_producto_alta.php'))return $s;
 return replace_required($s,'<h2>Catálogo de Productos</h2>', '<h2>Catálogo de Productos</h2><?php if ($esAdmin): ?><a href="erp_producto_alta.php" style="display:inline-block;margin-top:10px;color:#12568d;font-weight:bold">+ Alta avanzada · Compras / WinCaja</a><?php endif; ?>','enlace Productos almacén');
});
if($problems){foreach($problems as $x)fwrite(STDERR,"ERROR: $x\n");fwrite(STDERR,"Sin modificaciones. No se sobrescribió nada.\n");exit(2);}
foreach($changes as [$old,$new,$path]){
 $temp=tempnam(sys_get_temp_dir(),'gyd_php_');file_put_contents($temp,$new);
 $cmd=escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($temp).' 2>&1';exec($cmd,$out,$code);unlink($temp);
 if($code!==0){fwrite(STDERR,"ERROR SINTAXIS $path: ".implode(' ',$out)."\nSin cambios.\n");exit(3);}
}
if($mode==='--check'){echo "VERIFICACIÓN OK. ".count($changes)." archivos se pueden actualizar. Para aplicar ejecuta --apply.\n";exit(0);}
$dir=$root.'/respaldo_erp_ficha_'.date('Ymd_His');if(!is_dir($dir)&&!mkdir($dir,0775,true)){fwrite(STDERR,"No se pudo crear respaldo.\n");exit(4);}
foreach($changes as [$old,$new,$path]){
 $full=$root.'/'.$path;$copy=$dir.'/'.$path;$parent=dirname($copy);if(!is_dir($parent))mkdir($parent,0775,true);
 if(!copy($full,$copy)){fwrite(STDERR,"ERROR al respaldar $path. Se detuvo.\n");exit(5);}
}
foreach($changes as [$old,$new,$path]){
 if(file_put_contents($root.'/'.$path,$new,LOCK_EX)===false){fwrite(STDERR,"No se pudo modificar $path, restaura el respaldo $dir\n");exit(6);}
 echo "APLICADO: $path\n";
}
echo "Instalación lista. Respaldo en $dir\n";
