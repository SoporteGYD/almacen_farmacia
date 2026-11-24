<?php
declare(strict_types=1);
/** Carga optativa de inventario DEMO propio de Toscana. NO es un conteo físico real. */
$mode=$argv[1]??'--help';
if(!in_array($mode,['--check','--apply','--verify'],true)){
 echo "Uso: php tools/cargar_existencia_demo_toscana.php --check|--apply|--verify\n";exit(0);
}
try {
 $root=dirname(__DIR__);
 require_once $root.'/app/config/database.php';
 require_once $root.'/app/helpers/pos_inteligente.php';
 $db=(new Database())->connect();
 $base=(string)$db->query('SELECT DATABASE()')->fetchColumn();
 $port=(int)$db->query('SELECT @@port')->fetchColumn();
 if($base!=='prueba'||$port!==3307)throw new RuntimeException('Sólo se admiten datos de PRUEBA en prueba:3307.');
 $flag=$db->query('SELECT activo FROM gyd_tt_pool_config WHERE id=1')->fetchColumn();
 if((int)$flag!==0||pi_tt_stock_sid($db,4)!==4||pi_tt_activo($db))throw new RuntimeException('Instala primero la separación de inventarios.');
 $sql="SELECT p.id,e.existencia AS anterior
       FROM productos p
       JOIN pos_precios pr ON pr.producto_id=p.id AND pr.sucursal_id=4 AND pr.precio_publico>0
       LEFT JOIN pos_existencias_farmacia e ON e.producto_id=p.id AND e.sucursal_id=4
       WHERE p.estado=1 AND (e.id IS NULL OR e.existencia=0)
       ORDER BY p.id";
 $rows=$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
 $existing=(int)$db->query('SELECT COUNT(*) FROM pos_existencias_farmacia WHERE sucursal_id=4 AND existencia>0')->fetchColumn();
 echo 'Toscana: productos sin existencia positiva elegibles='.count($rows).'; productos con existencia positiva preservados='.$existing.PHP_EOL;
 if($mode==='--check'){
  echo 'LISTO: --apply creará valores DEMO propios de Toscana de 8 a 41 piezas. NO son existencias físicas.' . PHP_EOL;exit(0);
 }
 if($mode==='--verify'){
   $table=(int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gyd_pos_stock_demo_toscana'")->fetchColumn();
   if($table)echo 'Productos identificados como DEMO: '.$db->query('SELECT COUNT(*) FROM gyd_pos_stock_demo_toscana')->fetchColumn().PHP_EOL;
   echo 'Toscana: aún sin existencia positiva='.count($rows).PHP_EOL;exit(0);
 }
 $db->exec("CREATE TABLE IF NOT EXISTS gyd_pos_stock_demo_toscana (
      producto_id INT NOT NULL PRIMARY KEY,
      existencia_previa INT NULL,
      existencia_demo_inicial INT NOT NULL,
      creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      fuente VARCHAR(80) NOT NULL DEFAULT 'SIMULACION_INDEPENDIENTE_TOSCANA'
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $db->beginTransaction();
 try{
  $up=$db->prepare('INSERT INTO pos_existencias_farmacia(sucursal_id,producto_id,existencia) VALUES (4,?,?) ON DUPLICATE KEY UPDATE existencia=IF(existencia=0,VALUES(existencia),existencia)');
  $log=$db->prepare("INSERT IGNORE INTO gyd_pos_stock_demo_toscana(producto_id,existencia_previa,existencia_demo_inicial) VALUES (?,?,?)");
  $count=0;
  foreach($rows as $row){
    $pid=(int)$row['id'];$quantity=8+(($pid*17+52)%34);
    $up->execute([$pid,$quantity]);
    $log->execute([$pid,$row['anterior']===null?null:(int)$row['anterior'],$quantity]);
    $count++;
  }
  $db->commit();
  echo 'DEMO APLICADO: '.$count.' productos de Toscana, sin copiar existencias de Tapachula.' . PHP_EOL;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage().PHP_EOL);exit(1);}
