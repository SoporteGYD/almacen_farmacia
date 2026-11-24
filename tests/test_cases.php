<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers/erp_facturas_oc_parciales.php';
class FakeStatement extends PDOStatement {
  public string $sql;
  public function __construct(string $sql){$this->sql=$sql;}
  public function execute(?array $params=null):bool{return true;}
  public function fetchAll(int $mode=PDO::FETCH_DEFAULT,...$args):array{
    if(str_contains($this->sql,'SUM(d.cantidad_piezas) AS solicitado'))return $GLOBALS['scenario']['pedido'];
    if(str_contains($this->sql,'SUM(rd.cantidad_piezas) recibidas'))return $GLOBALS['scenario']['recibidas'];
    if(str_contains($this->sql,'SUM(fd.cantidad_facturada) facturadas'))return $GLOBALS['scenario']['facturadas'];
    throw new RuntimeException('unknown query');
  }
  public function fetchColumn(int $column=0):mixed{return $GLOBALS['scenario']['nrec'];}
}
class FakeDB extends PDO {
  public function __construct(){}
  public function prepare(string $query,array $options=[]):PDOStatement|false{return new FakeStatement($query);}
}
function runScenario(string $name, int $nrec,int $rec,int $fac,int $expected):void{
 $GLOBALS['scenario']=['pedido'=>[['producto_id'=>10,'codigo'=>'750','descripcion'=>'Producto demo','solicitado'=>62,'costo'=>'10.0000','producto_activo'=>1]],'nrec'=>$nrec,'recibidas'=>$rec?[['producto_id'=>10,'recibidas'=>$rec]]:[],'facturadas'=>$fac?[['producto_id'=>10,'facturadas'=>$fac]]:[]];
 $s=ef_oc_saldo_facturable(new FakeDB(),3);
 if($s['totales']['facturables']!==$expected)throw new Exception('FAIL: '.$name.' got '. $s['totales']['facturables']);
 echo 'OK '.$name.': '.$s['totales']['facturables'].' disponibles, '.$s['totales']['por_recibir'].' por recibir'.PHP_EOL;
}
runScenario('sin recepcion',0,0,0,62);
runScenario('parcial 60/62',1,60,0,60);
runScenario('60 facturadas',1,60,60,0);
runScenario('llegaron faltantes 2',2,62,60,2);
runScenario('todas facturadas',2,62,62,0);
