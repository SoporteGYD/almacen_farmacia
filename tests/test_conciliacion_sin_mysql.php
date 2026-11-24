<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers/erp_facturas_oc_documental.php';
class FacturaTestPDO extends PDO {
    public array $datos=[];
    public function __construct(array $datos){$this->datos=$datos;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{
        return new FacturaTestStmt($query,$this->datos);
    }
}
class FacturaTestStmt extends PDOStatement {
    private string $sql;private array $data;private array $rows=[];private mixed $scalar=0;
    public function __construct(string $sql,array $data){$this->sql=$sql;$this->data=$data;}
    public function execute(?array $params=null):bool{
        if(str_contains($this->sql,'FROM erp_ordenes_compra_detalle d LEFT JOIN productos'))
            $this->rows=[['producto_id'=>5,'codigo'=>'A1','descripcion'=>'Prueba','solicitado'=>$this->data['orden'],'costo'=>'32.2900','activo'=>1]];
        elseif(str_contains($this->sql,'SELECT COUNT(*) FROM erp_oc_recepciones'))
            $this->scalar=$this->data['tiene_recepcion']?1:0;
        elseif((str_contains($this->sql,'SELECT rd.producto_id,SUM(rd.cantidad_piezas)') || str_contains($this->sql,'SELECT d.producto_id,COALESCE(SUM(d.cantidad_piezas)')))
            $this->rows=$this->data['recibido']>0?[['producto_id'=>5,'recibidas'=>$this->data['recibido'],'n'=>$this->data['recibido']]]:[];
        elseif(str_contains($this->sql,'SELECT fd.producto_id,SUM(fd.cantidad_facturada)'))
            $this->rows=$this->data['facturado']>0?[['producto_id'=>5,'facturadas'=>$this->data['facturado']]]:[];
        elseif(str_contains($this->sql,"rd.tipo='ACEPTADA'"))
            $this->rows=$this->data['aceptado']>0?[['producto_id'=>5,'n'=>$this->data['aceptado']]]:[];
        return true;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,...$args):array{return $this->rows;}
    public function fetchColumn(int $column=0):mixed{return $this->scalar;}
}
function igual(mixed $actual,mixed $esperado,string $titulo):void{
    if($actual!==$esperado)throw new RuntimeException("FALLO $titulo: ".var_export($actual,true).' != '.var_export($esperado,true));
    echo "OK: $titulo\n";
}
$casos=[
 ['orden'=>100,'recibido'=>98,'facturado'=>0,'tiene_recepcion'=>true,'sugerido'=>98,'disponible'=>100,'por_recibir'=>2],
 ['orden'=>100,'recibido'=>98,'facturado'=>98,'tiene_recepcion'=>true,'sugerido'=>2,'disponible'=>2,'por_recibir'=>2],
 ['orden'=>100,'recibido'=>0,'facturado'=>0,'tiene_recepcion'=>false,'sugerido'=>100,'disponible'=>100,'por_recibir'=>100],
 ['orden'=>100,'recibido'=>98,'facturado'=>100,'tiene_recepcion'=>true,'sugerido'=>null,'disponible'=>0,'por_recibir'=>2],
];
foreach($casos as $index=>$c){
 $db=new FacturaTestPDO($c);
 $s=ef_oc_saldo_documental($db,11);
 igual($s['totales']['por_recibir'],$c['por_recibir'],"caso ".($index+1)." pendiente físico");
 igual($s['totales']['facturables'],$c['disponible'],"caso ".($index+1)." capacidad documento");
 igual($s['productos'][0]['qty']??null,$c['sugerido'],"caso ".($index+1)." propuesta de factura");
}
// Límite de aceptación para verificación, sin modificar existencias.
$d=['id'=>71,'producto_id'=>5,'descripcion_snapshot'=>'Prueba'];
$entrada=[['id'=>71,'tipo'=>'ACEPTADA','cantidad'=>2]];
$db=new FacturaTestPDO(['orden'=>100,'recibido'=>98,'facturado'=>100,'aceptado'=>96,'tiene_recepcion'=>true]);
ef_oc_proteger_aceptacion($db,11,[$d],$entrada);echo "OK: aceptar 2 de 98 disponibles (96 previamente aceptadas)\n";
$entrada=[['id'=>71,'tipo'=>'ACEPTADA','cantidad'=>3]];
try{ef_oc_proteger_aceptacion($db,11,[$d],$entrada);throw new RuntimeException('FALLO: permitió exceder recepción');}
catch(RuntimeException $e){if(str_contains($e->getMessage(),'FALLO'))throw $e;echo "OK: impide aceptar 99 cuando solo hay 98 recibidas\n";}
