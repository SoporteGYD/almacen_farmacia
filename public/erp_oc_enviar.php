<?php
/** Política G&D: el envío previo a proveedores queda deshabilitado.
 * Toda OC ahora se envía exclusivamente a gerentes desde erp_oc_win_enviar.php.
 */
declare(strict_types=1);
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');exit('Ruta antigua inhabilitada: ahora las OC solo se envían a gerentes.');}
$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
header('Location: '.($id?'erp_oc_win_enviar.php?id='.$id:'erp_oc_win.php'),true,303);
exit;
