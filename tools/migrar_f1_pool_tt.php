<?php
declare(strict_types=1);
/** ACTIVA INVENTARIO COMPARTIDO SOLO EN BASE PRUEBA. Requiere detener Apache. */
if(PHP_SAPI!=='cli')exit('Solo terminal');
$run=in_array('--migrate',$argv,true);$verify=in_array('--verify',$argv,true);
if($run===$verify){fwrite(STDERR,"Uso: --verify --expect=prueba | --migrate --expect=prueba --ack=LABORATORIO\n");exit(2);}
if(!in_array('--expect=prueba',$argv,true)||($run&&!in_array('--ack=LABORATORIO',$argv,true))){fwrite(STDERR,"BLOQUEADO: base y confirmación de laboratorio obligatorias.\n");exit(2);}
$root=dirname(__DIR__);require_once $root.'/app/config/database.php';
$db=(new Database())->connect();
if($db->query('SELECT DATABASE()')->fetchColumn()!=='prueba')throw new RuntimeException('NO se opera fuera de prueba');
$ids=$db->query("SELECT id,nombre FROM pos_sucursales WHERE id IN (3,4) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if(count($ids)!==2||stripos($ids[0]['nombre'],'TAPACHULA')===false||stripos($ids[1]['nombre'],'TOSCANA')===false)throw new RuntimeException('IDs de sucursal distintos: detener sin cambios');
$idx=$db->query("SELECT COUNT(*) FROM (SELECT INDEX_NAME FROM information_schema.statistics WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pos_existencias_farmacia' AND NON_UNIQUE=0 GROUP BY INDEX_NAME HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)= 'sucursal_id,producto_id') t")->fetchColumn();
if((int)$idx<1)throw new RuntimeException('Falta UNIQUE(sucursal_id,producto_id)');
$config=$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gyd_tt_pool_config'")->fetchColumn();
if($verify){
 $activo=$config?(int)$db->query('SELECT activo FROM gyd_tt_pool_config WHERE id=1')->fetchColumn():0;
 echo 'Base: prueba | Compartido activo: '.($activo?'SI':'NO').PHP_EOL;
 $s=$db->query('SELECT sucursal_id,COUNT(*) registros,COALESCE(SUM(existencia),0) piezas FROM pos_existencias_farmacia WHERE sucursal_id IN (3,4) GROUP BY sucursal_id ORDER BY sucursal_id');
 foreach($s as $r)echo "Sucursal {$r['sucursal_id']}: {$r['registros']} registros / {$r['piezas']} piezas\n";
 if($activo){$bad=$db->query('SELECT COUNT(*) FROM pos_existencias_farmacia WHERE sucursal_id=4 AND existencia<>0')->fetchColumn();echo "Filas independientes Toscana con saldo: $bad\n";if((int)$bad>0)exit(4);}
 exit(0);
}
$must=['app/helpers/pos_inteligente.php'=>'pi_tt_stock_sid(', 'app/models/PuntoVenta.php'=>'$stockSid=pi_tt_stock_sid', 'app/models/PosReversiones.php'=>'pi_tt_stock_sid($this->db,$sucursal)'];
$optional=['app/models/TraspasosGyD.php'=>'pi_tt_stock_sid($this->db,$sid)','public/erp_facturas_aplicar.php'=>'pi_tt_stock_sid($db,$posId)','app/models/PeticionesTraspasosGyD.php'=>'pi_tt_activo($this->db)'];
foreach($must+$optional as $rel=>$marker){$file=$root.'/'.$rel;if(!is_file($file)){if(isset($must[$rel]))throw new RuntimeException('Falta '.$rel);continue;}if(!str_contains((string)file_get_contents($file),$marker))throw new RuntimeException('Código sin adaptar: '.$rel);}
$open=(int)$db->query("SELECT COUNT(*) FROM pos_turnos t JOIN pos_cajas c ON c.id=t.caja_id WHERE t.estado='ABIERTO' AND c.sucursal_id IN (3,4)")->fetchColumn();
if($open)throw new RuntimeException('Cierra los '.$open.' turnos de prueba antes de migrar');
$db->exec("CREATE TABLE IF NOT EXISTS gyd_tt_pool_config (id TINYINT UNSIGNED PRIMARY KEY, activo TINYINT(1) NOT NULL DEFAULT 0, migrado_en DATETIME NULL) ENGINE=InnoDB");
$db->exec('INSERT IGNORE INTO gyd_tt_pool_config(id,activo) VALUES(1,0)');
$db->exec("CREATE TABLE IF NOT EXISTS gyd_tt_pool_snapshot (stock_id BIGINT UNSIGNED PRIMARY KEY, sucursal_id INT NOT NULL, producto_id INT NOT NULL, existencia INT NOT NULL, fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
$triggers=[
 'gyd_tt_guard_insert'=>"CREATE TRIGGER gyd_tt_guard_insert BEFORE INSERT ON pos_existencias_farmacia FOR EACH ROW BEGIN IF NEW.sucursal_id=4 AND (SELECT activo FROM gyd_tt_pool_config WHERE id=1)=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='G&D: escribir saldo compartido en sucursal POS 3'; END IF; END",
 'gyd_tt_guard_update'=>"CREATE TRIGGER gyd_tt_guard_update BEFORE UPDATE ON pos_existencias_farmacia FOR EACH ROW BEGIN IF OLD.sucursal_id=4 AND (SELECT activo FROM gyd_tt_pool_config WHERE id=1)=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='G&D: saldo Toscana es compartido'; END IF; END",
 'gyd_tt_guard_delete'=>"CREATE TRIGGER gyd_tt_guard_delete BEFORE DELETE ON pos_existencias_farmacia FOR EACH ROW BEGIN IF OLD.sucursal_id=4 AND (SELECT activo FROM gyd_tt_pool_config WHERE id=1)=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='G&D: no borrar snapshot de Toscana'; END IF; END"
];
foreach($triggers as $name=>$sql){$st=$db->prepare('SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$st->execute([$name]);$previous=$st->fetchColumn();if($previous===false)$db->exec($sql);elseif(!str_contains((string)$previous,'gyd_tt_pool_config'))throw new RuntimeException('Disparador distinto: '.$name);}
if((int)$db->query('SELECT activo FROM gyd_tt_pool_config WHERE id=1')->fetchColumn()===1){echo "YA MIGRADO. Usa --verify.\n";exit(0);}
if((int)$db->query('SELECT COUNT(*) FROM gyd_tt_pool_snapshot')->fetchColumn()>0)throw new RuntimeException('Snapshot previo no vacío y migración inactiva: revisar manualmente');
if((int)$db->query("SELECT GET_LOCK('gyd_tt_pool_migration',12)")->fetchColumn()!==1)throw new RuntimeException('Otra migración está activa');
try{
 $db->beginTransaction();
 $rows=$db->query('SELECT id,sucursal_id,producto_id,existencia FROM pos_existencias_farmacia WHERE sucursal_id IN (3,4) ORDER BY producto_id,sucursal_id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
 $insert=$db->prepare('INSERT INTO gyd_tt_pool_snapshot(stock_id,sucursal_id,producto_id,existencia) VALUES(?,?,?,?)');$sums=[];
 foreach($rows as $r){$insert->execute([$r['id'],$r['sucursal_id'],$r['producto_id'],$r['existencia']]);$pid=(int)$r['producto_id'];$sums[$pid]=($sums[$pid]??0)+(int)$r['existencia'];if($sums[$pid]<0||$sums[$pid]>2147483647)throw new RuntimeException('Suma inválida producto #'.$pid);}
 ksort($sums,SORT_NUMERIC);
 $up=$db->prepare('INSERT INTO pos_existencias_farmacia(sucursal_id,producto_id,existencia) VALUES(3,?,?) ON DUPLICATE KEY UPDATE existencia=VALUES(existencia)');
 foreach($sums as $pid=>$qty)$up->execute([$pid,$qty]);
 $db->exec('UPDATE pos_existencias_farmacia SET existencia=0 WHERE sucursal_id=4 AND existencia<>0');
 $db->exec('UPDATE gyd_tt_pool_config SET activo=1,migrado_en=NOW() WHERE id=1 AND activo=0');
 $db->commit();echo 'MIGRADO: '.count($sums).' productos con saldo común en POS sucursal 3. Snapshot conservado.\n';
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
finally{$st=$db->prepare('SELECT RELEASE_LOCK(?)');$st->execute(['gyd_tt_pool_migration']);}
