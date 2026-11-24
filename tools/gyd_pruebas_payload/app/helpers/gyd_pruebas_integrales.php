<?php
declare(strict_types=1);
/** Farmacia G&D — Centro de Pruebas Integrales. Las comprobaciones NO modifican operaciones. */
function gpi_h(mixed $v): string { return htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function gpi_role(array $u): bool {
    $role=strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));
    return (int)($u['id']??0)>0 && in_array($role,['ADMIN','ADMINISTRADOR','DIRECCION','DUEÑO','DUENO','PROPIETARIO'],true);
}
function gpi_one(PDO $db,string $sql,array $p=[]): mixed { $s=$db->prepare($sql);$s->execute($p);return $s->fetchColumn(); }
function gpi_rows(PDO $db,string $sql,array $p=[]): array { $s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC); }
function gpi_guard(PDO $db): void {
    $name=(string)gpi_one($db,'SELECT DATABASE()');
    if($name!=='prueba')throw new DomainException('El panel requiere la base de pruebas «prueba»; conexión actual: '.($name?:'sin base').'.');
}
function gpi_schema(PDO $db): array {
    $r=gpi_rows($db,'SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()');
    $out=[];foreach($r as $x)$out[$x['TABLE_NAME']][$x['COLUMN_NAME']]=true;
    return $out;
}
function gpi_has(array $schema,string $table,array $columns=[]): bool {
    if(!isset($schema[$table]))return false;
    foreach($columns as $c)if(!isset($schema[$table][$c]))return false;
    return true;
}
function gpi_check(array &$r,string $area,string $name,callable $fn): void {
    try{
        $value=$fn();$status=$value[0]??'no_disponible';
        if(!in_array($status,['correcto','revisar','fallido','no_disponible'],true))throw new LogicException('Estado inválido.');
        $r[]=['area'=>$area,'titulo'=>$name,'estado'=>$status,'detalle'=>$value[1]??'','accion'=>$value[2]??''];
    }catch(Throwable $e){
        error_log('G&D pruebas integrales '.$area.'/'.$name.': '.$e->getMessage());
        $r[]=['area'=>$area,'titulo'=>$name,'estado'=>'no_disponible','detalle'=>'La consulta no pudo verificarse con este esquema.','accion'=>'Revisar el log de PHP y la definición de tablas.'];
    }
}
function gpi_not_available(array &$out,string $area,string $name): void {
    gpi_check($out,$area,$name,static fn()=>['no_disponible','No se encontraron todas las tablas o columnas necesarias.','Comprobar migraciones y volver a ejecutar.']);
}
function gpi_run(PDO $db,string $projectRoot): array {
    gpi_guard($db);
    $schema=gpi_schema($db);$out=[];
    gpi_check($out,'Entorno','Conexión de laboratorio',static function()use($db){
        $start=microtime(true);$ok=(int)gpi_one($db,'SELECT 1');
        return $ok===1?['correcto','Conexión a «prueba» y SELECT 1 correcto ('.round((microtime(true)-$start)*1000,1).' ms).']:['fallido','La consulta SELECT 1 falló.'];
    });
    $required=['pos_turnos','pos_ventas','pos_venta_detalle','pos_pagos','pos_movimientos_caja','pos_existencias_farmacia','erp_pos_farmacia_control','erp_ordenes_compra','erp_oc_recepciones','erp_facturas','usuarios'];
    gpi_check($out,'Entorno','Estructura operativa',static function()use($schema,$required){
        $missing=array_values(array_filter($required,static fn($t)=>!isset($schema[$t])));
        return $missing?['revisar','Faltan tablas requeridas por alguna prueba: '.implode(', ',$missing),'Comprobar que las migraciones correspondan a la base «prueba».']:['correcto','Se encontraron las '.count($required).' tablas necesarias para las comprobaciones principales.'];
    });
    if(gpi_has($schema,'pos_turnos',['id','caja_id','estado'])){
        gpi_check($out,'Cajas','Un solo turno abierto por caja',static function()use($db){
            $x=gpi_rows($db,"SELECT caja_id,COUNT(*) AS total FROM pos_turnos WHERE estado='ABIERTO' GROUP BY caja_id HAVING COUNT(*)>1 LIMIT 5");
            return $x?['fallido',count($x).' cajas (máximo 5 mostradas) tienen turnos abiertos duplicados.','Revisar aperturas; no cerrar automáticamente.']:['correcto','No se encontraron cajas con turnos abiertos simultáneos.'];
        });
    }else gpi_not_available($out,'Cajas','Un solo turno abierto por caja');
    if(gpi_has($schema,'pos_turnos',['estado','cerrado_en','efectivo_contado','diferencia_efectivo'])){
        gpi_check($out,'Cajas','Cierres con conteo documentado',static function()use($db){
            $n=(int)gpi_one($db,"SELECT COUNT(*) FROM (SELECT estado,cerrado_en,efectivo_contado,diferencia_efectivo FROM pos_turnos WHERE estado='CERRADO' ORDER BY id DESC LIMIT 200) t WHERE t.efectivo_contado IS NULL OR t.diferencia_efectivo IS NULL OR t.cerrado_en IS NULL");
            return $n?['revisar',"$n de los últimos 200 turnos cerrados tienen algún dato de cierre incompleto.",'Comprobar si corresponden a cortes anteriores a las mejoras de denominaciones.']:['correcto','Los últimos 200 cierres disponibles no tienen campos obligatorios vacíos.'];
        });
    }else gpi_not_available($out,'Cajas','Cierres con conteo documentado');
    if(gpi_has($schema,'pos_ventas',['id','estado','total','pagada_en']) && gpi_has($schema,'pos_pagos',['venta_id','monto','estado'])){
        gpi_check($out,'Ventas','Pagos y total del ticket',static function()use($db){
            $sql="SELECT COUNT(*) FROM (SELECT v.id,v.total,COALESCE(SUM(CASE WHEN p.estado='CONFIRMADO' THEN p.monto ELSE 0 END),0) cobrado FROM (SELECT id,total FROM pos_ventas WHERE estado='PAGADA' ORDER BY id DESC LIMIT 200) v LEFT JOIN pos_pagos p ON p.venta_id=v.id GROUP BY v.id,v.total HAVING ABS(ROUND((v.total-cobrado)*100))>1) z";
            $n=(int)gpi_one($db,$sql);
            $cantidad=(int)gpi_one($db,"SELECT COUNT(*) FROM (SELECT id FROM pos_ventas WHERE estado='PAGADA' ORDER BY id DESC LIMIT 200) v");
            if($cantidad===0)return ['revisar','No existen tickets PAGADOS en la muestra para contrastar pagos.','Ejecutar una venta aislada de laboratorio antes de la validación funcional.'];
            return $n?['fallido',"$n diferencias entre importe y pagos en los últimos 200 tickets PAGADOS.",'Revisar los folios y métodos de pago sin alterar cobros.']:['correcto','No hay diferencias en la muestra de hasta 200 tickets PAGADOS. No implica auditar todas las ventas.'];
        });
    }else gpi_not_available($out,'Ventas','Pagos y total del ticket');
    if(gpi_has($schema,'pos_ventas',['id','idempotency_key'])){
        gpi_check($out,'Ventas','Identificadores de venta repetidos',static function()use($db){
            $sql="SELECT COUNT(*) FROM (SELECT idempotency_key FROM (SELECT idempotency_key FROM pos_ventas WHERE idempotency_key IS NOT NULL ORDER BY id DESC LIMIT 500) x GROUP BY idempotency_key HAVING COUNT(*)>1) d";
            $n=(int)gpi_one($db,$sql);
            return $n?['fallido',"$n identificadores duplicados en hasta 500 ventas recientes.",'Revisar la prevención de cobros duplicados.']:['correcto','Sin claves duplicadas en la muestra de 500 ventas recientes.'];
        });
        gpi_check($out,'Concurrencia','Restricción UNIQUE de venta',static function()use($db){
            $sql="SELECT INDEX_NAME,MAX(NON_UNIQUE) no_unico,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pos_ventas' GROUP BY INDEX_NAME";
            $indexes=gpi_rows($db,$sql);$unique=false;
            foreach($indexes as $i)if((int)$i['no_unico']===0 && $i['cols']==='idempotency_key')$unique=true;
            return $unique?['correcto','Existe índice UNIQUE individual en idempotency_key. Ayuda a evitar ventas repetidas.']:['revisar','No se confirmó un índice UNIQUE individual para idempotency_key.','Revisar migración y el comportamiento ante solicitudes simultáneas; no crear índices desde el panel.'];
        });
    }else{gpi_not_available($out,'Ventas','Identificadores de venta repetidos');gpi_not_available($out,'Concurrencia','Restricción UNIQUE de venta');}
    if(gpi_has($schema,'pos_pagos',['id','venta_id'])&&gpi_has($schema,'pos_ventas',['id'])){
        gpi_check($out,'Ventas','Pagos sin venta',static function()use($db){
            $n=(int)gpi_one($db,"SELECT COUNT(*) FROM (SELECT venta_id FROM pos_pagos ORDER BY id DESC LIMIT 500) p LEFT JOIN pos_ventas v ON v.id=p.venta_id WHERE v.id IS NULL");
            return $n?['fallido',"$n pagos recientes no tienen venta asociada.",'Identificar registros huérfanos antes de conciliar.']:['correcto','Los últimos 500 pagos tienen una venta asociada.'];
        });
    }else gpi_not_available($out,'Ventas','Pagos sin venta');
    foreach([['pos_existencias_farmacia','Existencias POS'],['erp_pos_farmacia_control','Conteo físico de Farmacia']] as [$table,$label]){
        if(gpi_has($schema,$table,['existencia']))gpi_check($out,'Inventarios',$label,static function()use($db,$table){
            $n=(int)gpi_one($db,'SELECT COUNT(*) FROM '.$table.' WHERE existencia < 0');
            return $n?['fallido',"$n productos con existencia negativa.",'Revisar movimientos y origen de ajustes.']:['correcto','No se detectaron existencias negativas.'];
        });else gpi_not_available($out,'Inventarios',$label);
    }
    if(gpi_has($schema,'pos_existencias_farmacia',['sucursal_id','producto_id'])){
        gpi_check($out,'Inventarios','Duplicados por sucursal y producto',static function()use($db){
            $n=(int)gpi_one($db,'SELECT COUNT(*) FROM (SELECT sucursal_id,producto_id FROM pos_existencias_farmacia GROUP BY sucursal_id,producto_id HAVING COUNT(*)>1 LIMIT 20) x');
            return $n?['fallido',"$n combinaciones duplicadas (máximo 20 mostradas).",'Verificar claves e inventarios antes de vender.']:['correcto','No existen duplicados de sucursal/producto.'];
        });
    }else gpi_not_available($out,'Inventarios','Duplicados por sucursal y producto');
    if(gpi_has($schema,'pos_reversiones',['id','operacion_clave'])){
        gpi_check($out,'Devoluciones','Operaciones de devolución duplicadas',static function()use($db){
            $n=(int)gpi_one($db,"SELECT COUNT(*) FROM (SELECT operacion_clave FROM (SELECT operacion_clave FROM pos_reversiones ORDER BY id DESC LIMIT 500) x GROUP BY operacion_clave HAVING COUNT(*)>1) d");
            return $n?['fallido',"$n operaciones duplicadas en la muestra de 500 registros.",'Revisar reversión y reembolsos asociados.']:['correcto','Sin duplicados por clave de operación en la muestra de devoluciones.'];
        });
    }else gpi_not_available($out,'Devoluciones','Operaciones de devolución duplicadas');
    if(gpi_has($schema,'erp_oc_recepciones',['id','orden_id'])&&gpi_has($schema,'erp_ordenes_compra',['id'])){
        gpi_check($out,'Compras','Recepciones vinculadas a orden',static function()use($db){
            $n=(int)gpi_one($db,"SELECT COUNT(*) FROM (SELECT orden_id FROM erp_oc_recepciones ORDER BY id DESC LIMIT 300) r LEFT JOIN erp_ordenes_compra oc ON oc.id=r.orden_id WHERE oc.id IS NULL");
            return $n?['fallido',"$n recepciones recientes sin orden existente.",'Comprobar registros antes de efectuar entradas por factura.']:['correcto','Las últimas 300 recepciones tienen orden existente.'];
        });
    }else gpi_not_available($out,'Compras','Recepciones vinculadas a orden');
    if(gpi_has($schema,'erp_facturas',['id','captura_token'])){
        gpi_check($out,'Compras','Token de captura de facturas',static function()use($db){
            $n=(int)gpi_one($db,"SELECT COUNT(*) FROM (SELECT captura_token FROM (SELECT captura_token FROM erp_facturas WHERE captura_token IS NOT NULL ORDER BY id DESC LIMIT 300) x GROUP BY captura_token HAVING COUNT(*)>1) d");
            return $n?['fallido',"$n tokens repetidos en hasta 300 facturas.",'Revisar protección contra doble captura.']:['correcto','Sin tokens repetidos en la muestra de facturas.'];
        });
    }else gpi_not_available($out,'Compras','Token de captura de facturas');
    gpi_check($out,'Seguridad','Restricciones de ruta presentes',static function()use($projectRoot,$schema){
        $aclFile=is_file($projectRoot.'/app/helpers/gyd_acl.php');
        $aclRole=isset($schema['gyd_acl_roles']);$aclUser=isset($schema['gyd_acl_usuarios']);
        $auth=is_file($projectRoot.'/app/helpers/auth.php')?(string)file_get_contents($projectRoot.'/app/helpers/auth.php'):'';
        return $aclFile&&$aclRole&&$aclUser&&str_contains($auth,'gyd_acl_exigir_ruta(')?
            ['revisar','La capa de ACL está presente, pero esto no demuestra que cubra todas las rutas.','Ejecutar las pruebas guiadas de acceso directo por URL y API.']:
            ['revisar','No se confirmó completa la capa de ACL.','Revisar Usuarios y Permisos; probar con cuentas limitadas.'];
    });
    gpi_check($out,'Concurrencia','Motor transaccional de tablas críticas',static function()use($db){
        $need=['pos_turnos','pos_ventas','pos_pagos','pos_existencias_farmacia'];
        $rows=gpi_rows($db,"SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('pos_turnos','pos_ventas','pos_pagos','pos_existencias_farmacia')");
        $engines=[];foreach($rows as $r)$engines[$r['TABLE_NAME']]=strtoupper((string)$r['ENGINE']);
        $bad=[];foreach($need as $table)if(($engines[$table]??'')!=='INNODB')$bad[]=$table;
        return $bad?['revisar','Sin motor InnoDB comprobado: '.implode(', ',$bad),'Revisar la instalación antes de pruebas simultáneas.']:['correcto','Las tablas POS críticas utilizan InnoDB. Esto NO acredita que el POS resista ventas simultáneas.'];
    });
    $counts=['correcto'=>0,'revisar'=>0,'fallido'=>0,'no_disponible'=>0];foreach($out as $item)$counts[$item['estado']]++;
    return ['fecha'=>date('Y-m-d H:i:s'),'entorno'=>'prueba','estado'=>'automatica_solo_lectura','resultados'=>$out,'resumen'=>$counts,'limitacion'=>'La revisión automatizada no ejecuta cobros ni ensaya concurrencia real; las pruebas manuales guiadas requieren laboratorio aislado.'];
}
function gpi_scenarios(): array {
    return [
      'venta_doble'=>['Ventas simultáneas','En dos sesiones del laboratorio, intentar cobrar la misma existencia limitada al mismo tiempo.','Solo una transacción descuenta stock si no hay unidades suficientes; ningún stock negativo ni doble ticket.'],
      'idempotencia'=>['Reintento de venta','Reenviar la misma operación de laboratorio con el mismo identificador, simulando doble clic o reintento.','Un solo cobro y un solo ticket; el reintento devuelve la referencia existente.'],
      'retiro_limite'=>['Efectivo superior a $3,000','Superar el límite con venta de laboratorio e intentar otra venta. Confirmar retiro mediante contraseña.','La siguiente venta se bloquea hasta retirar; no se solicita usuario y el retiro queda asociado al cajero correcto.'],
      'corte_modal'=>['Corte y autorización','Solicitar corte desde una cuenta de cajero de laboratorio; aprobar con credenciales de Gerencia.','Modal previo, rechazo de contraseña errónea, corte único y caja cerrada inmediatamente.'],
      'corte_retiro'=>['Corte y retiro a la vez','Con dos sesiones de laboratorio, iniciar retiro y corte del mismo turno.','No hay doble egreso ni corte inconsistente; solo estados válidos después del bloqueo de filas.'],
      'devolucion'=>['Devolución repetida','Devolver parcialmente una venta de laboratorio y repetir la solicitud con la misma clave.','No se reintegra inventario ni efectivo dos veces.'],
      'oc_facturas'=>['Recepciones simultáneas','Registrar dos intentos simultáneos de recepción de la misma orden y factura de laboratorio.','Sin duplicar piezas ni facturas; pendientes coherentes y folios auditables.'],
      'permisos'=>['Acceso directo a rutas','Con usuario restringido de laboratorio, escribir URLs directas de reportes, POS y APIs protegidas.','Las restricciones se aplican en servidor; el menú no es la única barrera.'],
      'restauracion'=>['Restauración de respaldo','Generar respaldo del laboratorio y restaurarlo en una base temporal diferente.','Tablas y totales comparados; sin sobrescribir «prueba» ni una base productiva.'],
    ];
}
function gpi_cell(mixed $v): string {
    $s=(string)($v??'');return preg_match('/^\s*[=+@\-]/u',$s)?"'".$s:$s;
}
