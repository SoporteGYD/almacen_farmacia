<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {http_response_code(403);exit('Solo terminal.');}
$path=__DIR__.'/../app/config/erp_mail.key';
if (file_exists($path)) {echo "La clave ya existe; no fue reemplazada.\n";exit(0);}
if(file_put_contents($path,base64_encode(random_bytes(32)).PHP_EOL,LOCK_EX)===false){fwrite(STDERR,"No se pudo crear la clave.\n");exit(1);}
@chmod($path,0600);
echo "Clave creada en app/config/erp_mail.key. Respáldala de forma segura y exclúyela de Git.\n";
