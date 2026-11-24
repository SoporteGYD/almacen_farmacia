<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo consola.');}
require_once __DIR__.'/../app/helpers/gyd_simulador_lab.php';

function sim_exec(PDO $db,string $sql,array $args=[]):PDOStatement{$s=$db->prepare($sql);$s->execute($args);return $s;}
function sim_scalar(PDO $db,string $sql,array $args=[]):mixed{return sim_exec($db,$sql,$args)->fetchColumn();}
function sim_setup(PDO $db):void {
    gyd_sim_guard($db,false);
    $schema=[
      "CREATE TABLE IF NOT EXISTS sim_control (id TINYINT PRIMARY KEY, marker VARCHAR(90) NOT NULL) ENGINE=InnoDB",
      "CREATE TABLE IF NOT EXISTS sim_runs (run_key CHAR(24) PRIMARY KEY, iniciado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP, finalizado_en TIMESTAMP NULL DEFAULT NULL, estado VARCHAR(20) NOT NULL, aprobadas INT NOT NULL DEFAULT 0, fallidas INT NOT NULL DEFAULT 0, no_ejecutadas INT NOT NULL DEFAULT 0) ENGINE=InnoDB",
      "CREATE TABLE IF NOT EXISTS sim_results (id BIGINT AUTO_INCREMENT PRIMARY KEY, run_key CHAR(24) NOT NULL, codigo VARCHAR(40) NOT NULL, estado VARCHAR(20) NOT NULL, detalle VARCHAR(800) NOT NULL, trabajadores_json TEXT NULL, INDEX idx_sim_results_run (run_key)) ENGINE=InnoDB",
      "CREATE TABLE IF NOT EXISTS sim_sales (id BIGINT AUTO_INCREMENT PRIMARY KEY, run_key CHAR(24) NOT NULL, request_key VARCHAR(80) NOT NULL, importe DECIMAL(12,2) NOT NULL, UNIQUE KEY uq_sim_sale (run_key,request_key)) ENGINE=InnoDB",
      "CREATE TABLE IF NOT EXISTS sim_stock (run_key CHAR(24) PRIMARY KEY,cantidad INT NOT NULL) ENGINE=InnoDB",
      "CREATE TABLE IF NOT EXISTS sim_cash (run_key CHAR(24) PRIMARY KEY, efectivo DECIMAL(12,2) NOT NULL, fondo DECIMAL(12,2) NOT NULL) ENGINE=InnoDB",
      "CREATE TABLE IF NOT EXISTS sim_withdrawals (id BIGINT AUTO_INCREMENT PRIMARY KEY,run_key CHAR(24) NOT NULL, monto DECIMAL(12,2) NOT NULL) ENGINE=InnoDB",
      "CREATE TABLE IF NOT EXISTS sim_shifts (run_key CHAR(24) PRIMARY KEY, estado VARCHAR(15) NOT NULL, contado DECIMAL(12,2) DEFAULT NULL) ENGINE=InnoDB",
      "CREATE TABLE IF NOT EXISTS sim_cuts (id BIGINT AUTO_INCREMENT PRIMARY KEY,run_key CHAR(24) NOT NULL,importe DECIMAL(12,2) NOT NULL, UNIQUE KEY uq_sim_cut (run_key)) ENGINE=InnoDB",
      "CREATE TABLE IF NOT EXISTS sim_invoices (id BIGINT AUTO_INCREMENT PRIMARY KEY,run_key CHAR(24) NOT NULL,proveedor INT NOT NULL,folio VARCHAR(40) NOT NULL, UNIQUE KEY uq_sim_invoice (run_key,proveedor,folio)) ENGINE=InnoDB",
      "CREATE TABLE IF NOT EXISTS sim_refunds (run_key CHAR(24) PRIMARY KEY,devuelto TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB",
      "CREATE TABLE IF NOT EXISTS sim_return_events (id BIGINT AUTO_INCREMENT PRIMARY KEY,run_key CHAR(24) NOT NULL,importe DECIMAL(12,2) NOT NULL, UNIQUE KEY uq_sim_return (run_key)) ENGINE=InnoDB",
    ];
    foreach($schema as $sql) $db->exec($sql);
    $marker=sim_scalar($db,'SELECT marker FROM sim_control WHERE id=1');
    if($marker!==false && $marker!==GYD_SIM_MARKER)throw new RuntimeException('Marcador de laboratorio incompatible. No se cambiará.');
    if($marker===false)sim_exec($db,'INSERT INTO sim_control (id,marker) VALUES (1,?)',[GYD_SIM_MARKER]);
    gyd_sim_guard($db);
    foreach(['sim_runs','sim_results','sim_sales','sim_stock','sim_cash','sim_withdrawals','sim_shifts','sim_cuts','sim_invoices','sim_refunds','sim_return_events'] as $table){
       if(!in_array($table,$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),true))throw new RuntimeException('Falta tabla de laboratorio '.$table);
       $engine=sim_scalar($db,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
       if(strtoupper((string)$engine)!=='INNODB')throw new RuntimeException('El laboratorio requiere InnoDB: '.$table);
    }
    echo "Laboratorio inicializado. Base: ".GYD_SIM_DB."; ninguna tabla operativa fue modificada.\n";
}
function sim_worker(PDO $db,string $scenario,string $run,int $actor):array {
    if(!isset(gyd_sim_scenarios()[$scenario]))throw new RuntimeException('Escenario desconocido.');
    if(!preg_match('/^[a-f0-9]{24}$/',$run)||!in_array($actor,[1,2],true))throw new RuntimeException('Parámetros de trabajador inválidos.');
    if(sim_scalar($db,'SELECT estado FROM sim_runs WHERE run_key=?',[$run])!=='RUNNING')throw new RuntimeException('La ejecución no está activa.');
    $db->beginTransaction();
    try {
        if($scenario==='venta_unica') {
            sim_exec($db,'INSERT INTO sim_sales (run_key,request_key,importe) VALUES (?,?,?)',[$run,'MISMA-OPERACION','125.50']);
            usleep(80000);$db->commit();return ['resultado'=>'creada'];
        }
        if($scenario==='stock_concurrente') {
            $n=sim_exec($db,'UPDATE sim_stock SET cantidad=cantidad-1 WHERE run_key=? AND cantidad>0',[$run])->rowCount();
            usleep(70000);$db->commit();return ['resultado'=>$n===1?'vendida':'sin_stock'];
        }
        if($scenario==='retiro_concurrente') {
            $row=sim_exec($db,'SELECT efectivo,fondo FROM sim_cash WHERE run_key=? FOR UPDATE',[$run])->fetch();
            if(!$row)throw new RuntimeException('Caja de laboratorio inexistente.');
            $monto=round((float)$row['efectivo']-(float)$row['fondo'],2);
            if($monto>0){sim_exec($db,'UPDATE sim_cash SET efectivo=? WHERE run_key=?',[$row['fondo'],$run]);sim_exec($db,'INSERT INTO sim_withdrawals(run_key,monto) VALUES(?,?)',[$run,number_format($monto,2,'.','')]);}
            usleep(70000);$db->commit();return ['resultado'=>$monto>0?'retirado':'sin_excedente'];
        }
        if($scenario==='corte_unico') {
            $n=sim_exec($db,"UPDATE sim_shifts SET estado='CERRADO', contado=1500.00 WHERE run_key=? AND estado='ABIERTO'",[$run])->rowCount();
            if($n===1)sim_exec($db,'INSERT INTO sim_cuts(run_key,importe) VALUES (?,?)',[$run,'1500.00']);
            usleep(70000);$db->commit();return ['resultado'=>$n===1?'cerrado':'ya_cerrado'];
        }
        if($scenario==='factura_unica') {
            sim_exec($db,'INSERT INTO sim_invoices (run_key,proveedor,folio) VALUES (?,?,?)',[$run,7,'F-001']);
            usleep(70000);$db->commit();return ['resultado'=>'recibida'];
        }
        if($scenario==='devolucion_unica') {
            $n=sim_exec($db,'UPDATE sim_refunds SET devuelto=1 WHERE run_key=? AND devuelto=0',[$run])->rowCount();
            if($n===1)sim_exec($db,'INSERT INTO sim_return_events (run_key,importe) VALUES (?,?)',[$run,'78.40']);
            usleep(70000);$db->commit();return ['resultado'=>$n===1?'devuelta':'ya_devuelta'];
        }
        throw new LogicException('Escenario inválido.');
    }catch(PDOException $e){
        if($db->inTransaction())$db->rollBack();
        if($e->getCode()==='23000')return ['resultado'=>'duplicada_bloqueada'];
        throw $e;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function sim_spawn(string $scenario,string $run):?array {
    if(!function_exists('proc_open'))return null;
    $workers=[];
    try {
      foreach([1,2] as $id){
        $desc=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        $proc=@proc_open([PHP_BINARY,__FILE__,'--worker',$scenario,$run,(string)$id],$desc,$pipes,__DIR__,null,['bypass_shell'=>true]);
        if(!is_resource($proc))throw new RuntimeException('No se pudieron crear procesos paralelos.');
        fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        $workers[]=['proc'=>$proc,'pipes'=>$pipes,'out'=>'','err'=>'','done'=>false];
      }
      $start=microtime(true);
      while(true){
        $all=true;
        foreach($workers as &$w){
          $w['out'].=stream_get_contents($w['pipes'][1]);$w['err'].=stream_get_contents($w['pipes'][2]);
          if(proc_get_status($w['proc'])['running'])$all=false;
        }unset($w);
        if($all)break;
        if(microtime(true)-$start>12)throw new RuntimeException('Se excedió el tiempo de espera de los trabajadores.');
        usleep(90000);
      }
      $results=[];
      foreach($workers as &$w){
        $w['out'].=stream_get_contents($w['pipes'][1]);$w['err'].=stream_get_contents($w['pipes'][2]);
        fclose($w['pipes'][1]);fclose($w['pipes'][2]);proc_close($w['proc']);$w['done']=true;
        $result=json_decode(trim($w['out']),true);
        if(!is_array($result)||!isset($result['resultado']))throw new RuntimeException('Un trabajador no devolvió un resultado válido.');
        $results[]=$result['resultado'];
      }unset($w);
      return $results;
    } finally {
      foreach($workers as $w) if(!$w['done']){
        @proc_terminate($w['proc']);
        foreach($w['pipes'] as $p)if(is_resource($p))@fclose($p);
        @proc_close($w['proc']);
      }
    }
}
function sim_prep(PDO $db,string $scenario,string $run):void{
    switch($scenario){
        case 'stock_concurrente':sim_exec($db,'INSERT INTO sim_stock (run_key,cantidad) VALUES (?,1)',[$run]);break;
        case 'retiro_concurrente':sim_exec($db,'INSERT INTO sim_cash(run_key,efectivo,fondo) VALUES (?,3200.00,1500.00)',[$run]);break;
        case 'corte_unico':sim_exec($db,"INSERT INTO sim_shifts(run_key,estado) VALUES (?,'ABIERTO')",[$run]);break;
        case 'devolucion_unica':sim_exec($db,'INSERT INTO sim_refunds (run_key,devuelto) VALUES (?,0)',[$run]);break;
    }
}
function sim_verify(PDO $db,string $scenario,string $run,array $workers):array{
    sort($workers);
    $result='fallido';$detail='Inconsistencia en las transacciones simuladas.';
    $n=0;$w=[];
    switch($scenario){
      case 'venta_unica':
        $n=(int)sim_scalar($db,'SELECT COUNT(*) FROM sim_sales WHERE run_key=?',[$run]);
        $result=($n===1&&$workers===['creada','duplicada_bloqueada'])?'correcto':'fallido';
        $detail="Ventas creadas: $n; se espera 1, y el segundo intento debe rechazarse.";break;
      case 'stock_concurrente':
        $n=(int)sim_scalar($db,'SELECT cantidad FROM sim_stock WHERE run_key=?',[$run]);
        $result=($n===0&&$workers===['sin_stock','vendida'])?'correcto':'fallido';
        $detail="Stock final: $n (inicial 1), sin permitir la segunda venta.";break;
      case 'retiro_concurrente':
        $cash=(float)sim_scalar($db,'SELECT efectivo FROM sim_cash WHERE run_key=?',[$run]);
        $sum=(float)sim_scalar($db,'SELECT COALESCE(SUM(monto),0) FROM sim_withdrawals WHERE run_key=?',[$run]);
        $num=(int)sim_scalar($db,'SELECT COUNT(*) FROM sim_withdrawals WHERE run_key=?',[$run]);
        $result=($num===1&&abs($cash-1500)<0.001&&abs($sum-1700)<0.001&&$workers===['retirado','sin_excedente'])?'correcto':'fallido';
        $detail="De $3,200 se retiraron $".number_format($sum,2)." ($num comprobante); quedaron $".number_format($cash,2)." de fondo.";break;
      case 'corte_unico':
        $state=sim_scalar($db,'SELECT estado FROM sim_shifts WHERE run_key=?',[$run]);
        $num=(int)sim_scalar($db,'SELECT COUNT(*) FROM sim_cuts WHERE run_key=?',[$run]);
        $result=($state==='CERRADO'&&$num===1&&$workers===['cerrado','ya_cerrado'])?'correcto':'fallido';
        $detail="Turno: $state; cortes generados: $num (esperado 1).";break;
      case 'factura_unica':
        $num=(int)sim_scalar($db,'SELECT COUNT(*) FROM sim_invoices WHERE run_key=?',[$run]);
        $result=($num===1&&$workers===['duplicada_bloqueada','recibida'])?'correcto':'fallido';
        $detail="Recepciones registradas de factura F-001: $num (esperado 1).";break;
      case 'devolucion_unica':
        $flag=(int)sim_scalar($db,'SELECT devuelto FROM sim_refunds WHERE run_key=?',[$run]);
        $num=(int)sim_scalar($db,'SELECT COUNT(*) FROM sim_return_events WHERE run_key=?',[$run]);
        $result=($flag===1&&$num===1&&$workers===['devuelta','ya_devuelta'])?'correcto':'fallido';
        $detail="Devoluciones aplicadas: $num; venta marcada como devuelta.";break;
    }
    return [$result,$detail];
}
function sim_run_all(PDO $db):void{
    gyd_sim_guard($db);
    $run=bin2hex(random_bytes(12));sim_exec($db,"INSERT INTO sim_runs(run_key,estado) VALUES (?,'RUNNING')",[$run]);
    echo "Iniciando pruebas transaccionales aisladas: $run\n";
    $ok=0;$failed=0;$skip=0;
    foreach(gyd_sim_scenarios() as $scenario=>$label){
        try{
            sim_prep($db,$scenario,$run);
            $out=sim_spawn($scenario,$run);
            if($out===null){$status='no_ejecutada';$detail='Esta instalación de PHP no permite crear dos procesos simultáneos.';$out=[];$skip++;}
            else {[$status,$detail]=sim_verify($db,$scenario,$run,$out);if($status==='correcto')$ok++;else $failed++;}
        }catch(Throwable $e){$status='fallido';$detail='La prueba no terminó: '.mb_substr($e->getMessage(),0,250);$out=[];$failed++;}
        sim_exec($db,'INSERT INTO sim_results(run_key,codigo,estado,detalle,trabajadores_json) VALUES (?,?,?,?,?)',[$run,$scenario,$status,$detail,json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        echo strtoupper($status).' | '.$label.' | '.$detail.PHP_EOL;
    }
    sim_exec($db,"UPDATE sim_runs SET estado='FINALIZADO',finalizado_en=NOW(),aprobadas=?,fallidas=?,no_ejecutadas=? WHERE run_key=?",[$ok,$failed,$skip,$run]);
    echo "RESULTADO: $ok correctas, $failed fallidas, $skip no ejecutadas.\n";
    echo "Consulta: http://localhost/almacen-farmacia2/public/erp_simulador_operaciones.php\n";
    echo "Aviso: esto prueba operaciones transaccionales simuladas, NO todas las rutas del POS real.\n";
}
$command=$argv[1]??'--help';
if(!in_array($command,['--help','--check','--setup','--run','--worker'],true)){fwrite(STDERR,"Comando desconocido.\n");exit(2);}
if($command==='--help'){
    echo "Uso: php tools/gyd_simulador_operaciones_v21.php --check | --setup | --run\n";
    echo "Primero crea una base VACÍA llamada ".GYD_SIM_DB." en phpMyAdmin. No ejecutar en bases operativas.\n";exit;
}
try {
    $db=gyd_sim_connection();
    gyd_sim_guard($db,$command!=='--setup');
    if($command==='--check'){
        $needed=array_keys(gyd_sim_scenarios());echo "COMPATIBLE: laboratorio aislado ".GYD_SIM_DB." verificado. Escenarios ".count($needed).".\n";
        if(!function_exists('proc_open'))echo "AVISO: proc_open no está disponible; las pruebas concurrentes no podrán ejecutarse.\n";
    }elseif($command==='--setup')sim_setup($db);
    elseif($command==='--run')sim_run_all($db);
    else {
        $scenario=$argv[2]??'';$run=$argv[3]??'';$actor=(int)($argv[4]??0);
        echo json_encode(sim_worker($db,$scenario,$run,$actor),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
    }
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1);}
