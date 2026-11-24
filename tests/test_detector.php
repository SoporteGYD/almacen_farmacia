<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/erp_cambio_precios_auto.php';
final class PrecioTestStatement extends PDOStatement{
    public array $filas=[];
    public function __construct(array $filas){$this->filas=$filas;}
    public function execute(?array $params=null):bool{return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,...$args):array{return $this->filas;}
}
final class PrecioTestPDO extends PDO{
    public array $filas=[];
    public function __construct(array $rows){$this->filas=$rows;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new PrecioTestStatement($this->filas);}
}
$rows=[
  ['detalle_id'=>1,'costo_base'=>'32.2900','costo_factura'=>'35.0000','aceptadas'=>220],
  ['detalle_id'=>2,'costo_base'=>'21.00','costo_factura'=>'21.00','aceptadas'=>20],
  ['detalle_id'=>3,'costo_base'=>'0.00','costo_factura'=>'17.00','aceptadas'=>4],
  ['detalle_id'=>4,'costo_base'=>'45.00','costo_factura'=>'43.00','aceptadas'=>15],
];
$r=cp_auto_pendientes(new PrecioTestPDO($rows),99);
if(count($r)!==2 || array_column($r,'detalle_id')!==[1,4]){throw new RuntimeException('Detección de costo distinta de lo esperado.');}
if(cp_auto_pendientes(new PrecioTestPDO($rows),0)!==[]){throw new RuntimeException('Factura vacía debe devolver vacío.');}
foreach([['35.0000','MARK_UP','15.71','40.50'],['35.0000','MARK_DOWN','20','43.75']] as [$c,$modo,$m,$esperado]){
 $v=cp_precio_sugerido($c,$modo,$m);if($v!==$esperado)throw new RuntimeException("Cálculo $modo incorrecto: $v");
}
if(cp_autorizado(['rol'=>'GERENTE']) || !cp_autorizado(['rol'=>'COMPRAS']))throw new RuntimeException('Permisos incorrectos.');
echo "OK detector, precios y roles.\n";
