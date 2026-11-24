<?php
require __DIR__.'/payload/app/helpers/gyd_panel_cajas.php';
class SimStmt extends PDOStatement{
    private string $sql;private SimDB $db;private array $rows=[];
    public function __construct(string $sql,SimDB $db){$this->sql=$sql;$this->db=$db;}
    public function execute(?array $params=null):bool{$this->rows=$this->db->rows($this->sql,$params??[]);return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,...$args):array{return $this->rows;}
}
class SimDB extends PDO{
    public function __construct(){}
    public function prepare(string $sql,array $options=[]):PDOStatement|false{return new SimStmt($sql,$this);}
    public function rows(string $sql,array $args):array{
        if(str_contains($sql,'FROM pos_sucursales WHERE activo=1'))return [['id'=>1,'nombre'=>'CD HIDALGO'],['id'=>2,'nombre'=>'TUXTLA']];
        if(str_contains($sql,'FROM pos_turnos t JOIN pos_cajas'))return [
         ['id'=>10,'caja_id'=>1,'estado'=>'ABIERTO','abierto_en'=>'2026-11-10 10:00:00','cerrado_en'=>null,'fondo_inicial'=>'1500.00','efectivo_contado'=>null,'diferencia_efectivo'=>null,'usuario_apertura_id'=>7,'caja'=>'Caja 01','sucursal_id'=>1,'sucursal'=>'CD HIDALGO','abierto_por'=>'Cajero','cerrado_por'=>null],
         ['id'=>9,'caja_id'=>2,'estado'=>'CERRADO','abierto_en'=>'2026-11-10 08:00:00','cerrado_en'=>'2026-11-10 18:00:00','fondo_inicial'=>'1000.00','efectivo_contado'=>'2680.00','diferencia_efectivo'=>'-20.00','usuario_apertura_id'=>8,'caja'=>'Caja 01','sucursal_id'=>2,'sucursal'=>'TUXTLA','abierto_por'=>'Cajero 2','cerrado_por'=>'Gerente']];
        if(str_contains($sql,'FROM pos_pagos p'))return [ ['turno_id'=>10,'metodo'=>'EFECTIVO','monto'=>'250.00'],['turno_id'=>9,'metodo'=>'EFECTIVO','monto'=>'2000.00'],['turno_id'=>9,'metodo'=>'TARJETA','monto'=>'800.00'],['turno_id'=>9,'metodo'=>'TRANSFERENCIA','monto'=>'50.00'] ];
        if(str_contains($sql,'FROM pos_movimientos_caja'))return [['turno_id'=>9,'tipo'=>'RETIRO','monto'=>'300.00']];
        if(str_contains($sql,'FROM pos_ventas WHERE'))return [['turno_id'=>9,'pagadas'=>5,'canceladas'=>1],['turno_id'=>10,'pagadas'=>2,'canceladas'=>0]];
        if(str_contains($sql,'FROM pos_reversiones'))return [['turno_id'=>9,'medio_reembolso'=>'EFECTIVO','monto'=>'50.00','operaciones'=>1]];
        if(str_contains($sql,'FROM gyd_retiros_boveda'))return [['turno_id'=>9,'importe'=>'250.00','cantidad'=>1]];
        if(str_contains($sql,'FROM gyd_cortes_autorizaciones a'))return [['turno_id'=>9,'autorizador'=>'Gerente','autorizador_rol'=>'GERENTE','cajero_id'=>8]];
        if(str_contains($sql,'FROM pos_cortes_denominaciones'))return [['turno_id'=>9,'total_contado'=>'2680.00']];
        throw new RuntimeException('SQL inesperado: '.$sql);
    }
}
$datos=gac_cargar(new SimDB(),'2026-11-01','2026-11-14',0,'TODOS');
assert(count($datos['turnos'])===2);
assert($datos['resumen']['abiertos']===1&&$datos['resumen']['cerrados']===1);
assert($datos['turnos'][1]['esperado']===270000);
assert($datos['turnos'][1]['diferencia']===-2000);
assert($datos['turnos'][1]['reembolso_efectivo']===5000);
assert($datos['turnos'][0]['diferencia']===null);
assert($datos['resumen']['cortes_diferencia']===1);
foreach(['-5.34','20.50','0.00'] as $v)assert(gac_centavos($v)===(int)round((float)$v*100));
foreach(['1.123','--1','e6','1,000.00'] as $v){try{gac_centavos($v);throw new RuntimeException('Se aceptó '.$v);}catch(DomainException $e){}}
foreach(['CAJERO','ENCARGADO','CONSULTA'] as $rol)assert(!gac_rol_permitido($rol));
foreach(['GERENTE','DIRECCION','CONTABILIDAD','ADMINISTRADOR'] as $rol)assert(gac_rol_permitido($rol));
echo "PRUEBAS OK: 2 turnos, diferencia negativa, pagos y roles.\n";
