<?php
declare(strict_types=1);
/** Centro de Diagnóstico G&D: consultas de diagnóstico; nunca corrige datos operativos. */
function gdiag_h(mixed $v): string { return htmlspecialchars((string)($v??''), ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function gdiag_role(array $u): bool {
    $role=strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));
    return (int)($u['id']??0)>0 && in_array($role,['ADMIN','ADMINISTRADOR','DIRECCION','DUEÑO','DUENO','PROPIETARIO'],true);
}
function gdiag_q(PDO $db,string $sql,array $params=[]):array { $stmt=$db->prepare($sql);$stmt->execute($params);return $stmt->fetchAll(PDO::FETCH_ASSOC); }
function gdiag_one(PDO $db,string $sql,array $params=[]):mixed { $stmt=$db->prepare($sql);$stmt->execute($params);return $stmt->fetchColumn(); }
function gdiag_table(PDO $db,string $name):bool { return (bool)gdiag_one($db,'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1',[$name]); }
function gdiag_cols(PDO $db,string $table,array $names):bool {
    $list=array_column(gdiag_q($db,'SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?',[$table]),'column_name');
    return count(array_diff($names,$list))===0;
}
function gdiag_add(array &$checks,string $area,string $title,string $level,string $detail,string $action=''):void {
    if(!in_array($level,['ok','atencion','problema','no_disponible'],true))throw new DomainException('Clasificación inválida.');
    $checks[]=['area'=>$area,'titulo'=>$title,'estado'=>$level,'detalle'=>$detail,'accion'=>$action];
}
function gdiag_test(array &$checks,string $area,string $title,callable $fn):void {
    try{$item=$fn();gdiag_add($checks,$area,$title,$item[0],$item[1],$item[2]??'');}
    catch(Throwable $e){error_log('G&D diagnóstico ['.$area.'/'.$title.']: '.$e->getMessage());gdiag_add($checks,$area,$title,'no_disponible','No se pudo completar esta comprobación.','Consultar el registro de errores PHP y comprobar la estructura de tablas.');}
}
function gdiag_backup_info(string $root):array {
    $isWin=PHP_OS_FAMILY==='Windows';
    $dir=$isWin?$root.DIRECTORY_SEPARATOR.'backups'.DIRECTORY_SEPARATOR.'inventario':'/backups/almacen_farmacia';
    if(!is_dir($dir))return ['atencion','No se encontró la carpeta de respaldos prevista para este servidor.','Comprobar la configuración de respaldos y su ruta de destino.'];
    if(!is_readable($dir))return ['no_disponible','No hay permisos de lectura para examinar el directorio de respaldos.','Revisar los permisos del servicio web.'];
    $latest=null;$time=0;$size=0;$seen=0;
    $it=new DirectoryIterator($dir);
    foreach($it as $f){if(!$f->isFile() || !$f->isReadable() || !preg_match('/\.(sql|sql\.gz|gz|zip)$/i',$f->getFilename()))continue;
      $seen++;$t=$f->getMTime();if($t>$time){$latest=$f->getFilename();$time=$t;$size=$f->getSize();}}
    if($latest===null)return ['atencion','No hay archivos de respaldo SQL/ZIP reconocibles en la carpeta configurada.','Verificar la tarea de respaldos automáticos.'];
    $hours=(time()-$time)/3600; $stamp=date('d/m/Y H:i',$time);
    $summary='Último archivo detectado: '.$stamp.' · '.number_format($size/1048576,2,'.',',').' MB. No se ha comprobado que pueda restaurarse.';
    if($size===0)return ['problema',$summary,'El archivo está vacío: generar y probar un nuevo respaldo.'];
    if($hours>24)return ['atencion',$summary,'Comprobar la programación de respaldos y ejecutar una restauración de prueba.'];
    return ['ok',$summary,'Programar pruebas periódicas de restauración.'];
}
function gdiag_run(PDO $db,string $root):array {
    // Evita ejecutar accidentalmente comprobaciones intensivas sobre producción.
    $active=(string)gdiag_one($db,'SELECT DATABASE()');
    if($active!=='prueba')throw new DomainException('Este diagnóstico está habilitado únicamente para la base de pruebas «prueba».');
    $checks=[];
    gdiag_test($checks,'Base de datos','Conexión y respuesta',static function()use($db):array{
        $start=microtime(true);$value=(int)gdiag_one($db,'SELECT 1');$ms=round((microtime(true)-$start)*1000,1);
        return $value===1?['ok','Conexión activa a MySQL; respuesta a SELECT 1: '.$ms.' ms.']:['problema','La comprobación de conexión devolvió un resultado inesperado.'];
    });
    $groups=[
      'POS'=>['pos_sucursales','pos_cajas','pos_turnos','pos_ventas','pos_venta_detalle','pos_pagos','pos_movimientos_caja'],
      'Inventarios'=>['pos_existencias_farmacia','erp_pos_farmacia_control'],
      'Compras'=>['erp_ordenes_compra','erp_oc_recepciones','erp_facturas'],
      'Seguridad'=>['usuarios','pos_auditoria'],
    ];
    $has=[];
    foreach($groups as $area=>$tables){
        $missing=[];
        foreach($tables as $table){try{$has[$table]=gdiag_table($db,$table);}catch(Throwable $e){$has[$table]=false;error_log('G&D tabla '.$table.': '.$e->getMessage());}if(!$has[$table])$missing[]=$table;}
        gdiag_add($checks,$area,'Tablas operativas',$missing?($area==='POS'?'problema':'atencion'):'ok',$missing?'Faltan: '.implode(', ',$missing):'Se encontraron las tablas esperadas del módulo.',$missing?'Revisar la instalación y migraciones correspondientes.':'');
    }
    if($has['pos_ventas'] && $has['pos_pagos'] && gdiag_cols($db,'pos_ventas',['id','total','estado','pagada_en']) && gdiag_cols($db,'pos_pagos',['venta_id','monto','estado'])){
      gdiag_test($checks,'POS','Ventas y pagos (últimos 30 días)',static function()use($db):array{
        $row=gdiag_q($db,"SELECT COUNT(*) revisadas, COALESCE(SUM(IF(ABS(v.total-COALESCE(p.cobrado,0))>0.009,1,0)),0) diferencias FROM pos_ventas v LEFT JOIN (SELECT pg.venta_id,SUM(pg.monto) cobrado FROM pos_pagos pg JOIN pos_ventas v2 ON v2.id=pg.venta_id WHERE pg.estado='CONFIRMADO' AND v2.estado='PAGADA' AND v2.pagada_en>=DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY pg.venta_id) p ON p.venta_id=v.id WHERE v.estado='PAGADA' AND v.pagada_en>=DATE_SUB(NOW(), INTERVAL 30 DAY)")[0];
        $n=(int)$row['revisadas'];$dif=(int)$row['diferencias'];
        if($n===0)return ['atencion','No hay ventas pagadas recientes para comprobar.','Realizar ventas de prueba y repetir el diagnóstico.'];
        return $dif?['problema',$dif.' de '.$n.' ventas pagadas presentan diferencias entre total y pagos confirmados.','Revisar los folios y conciliarlos; no modificar pagos sin evidencia.']:['ok',$n.' ventas pagadas revisadas; sus pagos confirmados coinciden con el total.'];
      });
    }else gdiag_add($checks,'POS','Ventas y pagos','no_disponible','No están disponibles las columnas necesarias para comprobar pagos.');
    if($has['pos_turnos'] && gdiag_cols($db,'pos_turnos',['caja_id','estado','abierto_en'])){
      gdiag_test($checks,'Cajas','Turnos simultáneos',static function()use($db):array{
        $n=(int)gdiag_one($db,"SELECT COUNT(*) FROM (SELECT caja_id FROM pos_turnos WHERE estado='ABIERTO' GROUP BY caja_id HAVING COUNT(*)>1) d");
        return $n?['problema',$n.' cajas registran más de un turno abierto.','Comprobar las aperturas duplicadas antes de realizar un corte.']:['ok','No se detectaron cajas con más de un turno abierto.'];
      });
      gdiag_test($checks,'Cajas','Turnos prolongados',static function()use($db):array{
        $n=(int)gdiag_one($db,"SELECT COUNT(*) FROM pos_turnos WHERE estado='ABIERTO' AND abierto_en<DATE_SUB(NOW(), INTERVAL 12 HOUR)");
        return $n?['atencion',$n.' turnos llevan más de 12 horas abiertos.','Revisar responsables y horarios; no cerrarlos automáticamente.']:['ok','No hay turnos abiertos con más de 12 horas.'];
      });
    }else gdiag_add($checks,'Cajas','Turnos','no_disponible','Falta la tabla de turnos o columnas requeridas.');
    if($has['pos_turnos'] && gdiag_cols($db,'pos_turnos',['estado','cerrado_en','efectivo_contado','diferencia_efectivo'])){
      gdiag_test($checks,'Cajas','Cortes con datos incompletos',static function()use($db):array{
        $n=(int)gdiag_one($db,"SELECT COUNT(*) FROM pos_turnos WHERE estado='CERRADO' AND cerrado_en>=DATE_SUB(NOW(), INTERVAL 30 DAY) AND (efectivo_contado IS NULL OR diferencia_efectivo IS NULL)");
        return $n?['atencion',$n.' cortes recientes tienen campos de conteo o diferencia vacíos.','Verificar si corresponden a cierres anteriores a los módulos de denominaciones.']:['ok','No se detectaron campos obligatorios de efectivo vacíos en cierres recientes.'];
      });
    }
    foreach(['pos_existencias_farmacia','erp_pos_farmacia_control'] as $table){
      if(!$has[$table]||!gdiag_cols($db,$table,['existencia']))continue;
      gdiag_test($checks,'Inventarios',$table==='pos_existencias_farmacia'?'Existencias POS':'Conteos físicos de Farmacia',static function()use($db,$table):array{
        $n=(int)gdiag_one($db,'SELECT COUNT(*) FROM '.$table.' WHERE existencia<0');
        return $n?['problema',$n.' registros tienen existencias negativas.','Investigar movimientos y corregir mediante el procedimiento auditado del ERP.']:['ok','No se detectaron existencias negativas.'];
      });
    }
    gdiag_add($checks,'Inventarios','Conciliación entre almacenes','atencion','Este panel no suma stocks de Tapachula y Toscana, porque comparten inventario; no se presupone equivalencia física entre depósitos.','Utilizar el módulo de Conciliación de Inventarios para comparar ubicaciones y evidencias.');
    if($has['erp_ordenes_compra'] && gdiag_cols($db,'erp_ordenes_compra',['estado','creado_en'])){
      gdiag_test($checks,'Compras','Órdenes abiertas',static function()use($db):array{
        $n=(int)gdiag_one($db,"SELECT COUNT(*) FROM erp_ordenes_compra WHERE estado IN ('BORRADOR','EMITIDA')");
        return ['ok',$n.' órdenes en borrador o emitidas (dato informativo, no necesariamente incidencia).'];
      });
    }
    if($has['erp_facturas'] && gdiag_cols($db,'erp_facturas',['estado'])){
      gdiag_test($checks,'Compras','Facturas por revisar',static function()use($db):array{
        $n=(int)gdiag_one($db,"SELECT COUNT(*) FROM erp_facturas WHERE estado IN ('REGISTRADA','EN_REVISION','CON_INCIDENCIAS')");
        return $n?['atencion',$n.' facturas aún no están verificadas.','Revisar Verificación de Facturas y pendientes de Compras.']:['ok','No hay facturas en estados pendientes de verificación.'];
      });
    }
    gdiag_test($checks,'Seguridad','Restricciones centralizadas',static function()use($db,$root):array{
      $acl=is_file($root.'/app/helpers/gyd_acl.php');$auth=is_file($root.'/app/helpers/auth.php')?(string)file_get_contents($root.'/app/helpers/auth.php'):'';
      $tables=gdiag_table($db,'gyd_acl_roles') && gdiag_table($db,'gyd_acl_usuarios');
      if($acl&&$tables&&str_contains($auth,'gyd_acl_exigir_ruta('))return ['ok','La capa central de restricciones, sus tablas y el control de rutas están presentes.','Probar los permisos con cuentas de prueba; la revisión estática no demuestra cobertura total de rutas.'];
      return ['atencion','No se confirmó la instalación completa de restricciones centralizadas en rutas.','Comprobar el módulo Usuarios y Permisos y probar acceso directo por URL.'];
    });
    gdiag_test($checks,'Respaldos','Último respaldo disponible',static fn()=>gdiag_backup_info($root));
    $count=['ok'=>0,'atencion'=>0,'problema'=>0,'no_disponible'=>0];foreach($checks as $r)$count[$r['estado']]++;
    return ['fecha'=>date('Y-m-d H:i:s'),'resumen'=>$count,'resultados'=>$checks,'entorno'=>'prueba','nota'=>'Las pruebas no modifican operaciones. Un estado correcto no garantiza recuperación ni seguridad total.'];
}
