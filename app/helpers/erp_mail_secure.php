<?php
declare(strict_types=1);
/** Clave privada independiente de MySQL, fuera de /public y fuera de Git. */
function erpMailKey(): string {
    $path = __DIR__.'/../config/erp_mail.key';
    if (!is_file($path)) throw new RuntimeException('Falta app/config/erp_mail.key. Genera la clave con scripts/generar_clave_correo.php.');
    $key=base64_decode(trim((string)file_get_contents($path)),true);
    if ($key===false || strlen($key)!==32) throw new RuntimeException('La clave de correo no es válida.');
    return $key;
}
function erpMailEncrypt(string $secret): string {
    $nonce=random_bytes(12); $tag='';
    $cipher=openssl_encrypt($secret,'aes-256-gcm',erpMailKey(),OPENSSL_RAW_DATA,$nonce,$tag,'erp-gyd-correo');
    if ($cipher===false) throw new RuntimeException('No fue posible proteger la credencial.');
    return base64_encode($nonce.$tag.$cipher);
}
function erpMailDecrypt(string $value): string {
    $blob=base64_decode($value,true);
    if($blob===false || strlen($blob)<29) throw new RuntimeException('Credencial cifrada inválida.');
    $secret=openssl_decrypt(substr($blob,28),'aes-256-gcm',erpMailKey(),OPENSSL_RAW_DATA,substr($blob,0,12),substr($blob,12,16),'erp-gyd-correo');
    if($secret===false) throw new RuntimeException('No se pudo descifrar la credencial.');
    return $secret;
}
