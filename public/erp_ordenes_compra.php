<?php
/** Entrada principal: conserva URL y menús existentes; muestra nueva captura WinCaja. */
declare(strict_types=1);
if($_SERVER['REQUEST_METHOD']!=='GET'){
 http_response_code(405);header('Allow: GET');exit('Esta pantalla fue actualizada. Recarga Ordenes de Compra antes de guardar.');
}
$query=[];
if(isset($_GET['ver']))$query['ver']=filter_var($_GET['ver'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
header('Location: erp_oc_win.php'.($query&&$query['ver']?'?'.http_build_query($query):''),true,302);exit;
