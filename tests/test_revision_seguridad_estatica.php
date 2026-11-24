<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$checks=[
'endpoint de reseteo web inhabilitado'=>str_contains(file_get_contents($root.'/public/create_admin.php'),'http_response_code(410)') && !str_contains(file_get_contents($root.'/public/create_admin.php'),"password_hash('admin123'"),
'política de sesión limitada'=>str_contains(file_get_contents($root.'/app/helpers/auth.php'),'60 * 60 * 24 * 30'),
'importación solo privilegiados'=>str_contains(file_get_contents($root.'/public/importar_productos.php'),'GD_ALLOW_DIRECT_STOCK_IMPORT') && str_contains(file_get_contents($root.'/public/importar_productos.php'),'import_excel_csrf'),
'archivo privado protegido vía Apache'=>is_file($root.'/.htaccess') && is_file($root.'/public/.htaccess'),
'BD backup coincide con configuración desarrollo'=>str_contains(file_get_contents($root.'/public/respaldos.php'),"getenv('GD_DB_NAME')"),
];
$failed=0;
foreach($checks as $label=>$ok){echo ($ok?'OK: ':'ERROR: ').$label.PHP_EOL;if(!$ok)$failed++;}
exit($failed?1:0);
