<?php
require_once __DIR__.'/../config/database.php';
final class AsignacionPos {
 private PDO $db;
 public function __construct(){ $this->db=(new Database())->connect(); }
 public function opciones():array {return $this->db->query("SELECT s.id sucursal_id,s.nombre sucursal,c.id caja_id,c.nombre caja FROM pos_sucursales s JOIN pos_cajas c ON c.sucursal_id=s.id WHERE s.activo=1 AND c.activa=1 ORDER BY s.id,c.id")->fetchAll(PDO::FETCH_ASSOC);}
 public function obtener(int $id):?array { $q=$this->db->prepare('SELECT sucursal_id,caja_id FROM pos_usuario_asignaciones WHERE usuario_id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null; }
 public function validar(int $sucursal,int $caja):bool {$q=$this->db->prepare('SELECT 1 FROM pos_cajas c JOIN pos_sucursales s ON s.id=c.sucursal_id WHERE c.id=? AND s.id=? AND c.activa=1 AND s.activo=1');$q->execute([$caja,$sucursal]);return (bool)$q->fetchColumn();}
 public function guardar(int $usuario,int $sucursal,int $caja):void {if(!$this->validar($sucursal,$caja))throw new DomainException('Caja y sucursal POS no corresponden.');$q=$this->db->prepare('INSERT INTO pos_usuario_asignaciones(usuario_id,sucursal_id,caja_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE sucursal_id=VALUES(sucursal_id),caja_id=VALUES(caja_id)');$q->execute([$usuario,$sucursal,$caja]);}
 /** Vincula el número físico de caja a la cuenta: 01 y 001 son equivalentes. */
 public static function normalizarNumero(string $numero):string {
    $numero=trim($numero);
    if(!preg_match('/^[0-9]{1,3}$/D',$numero) || (int)$numero<1)throw new DomainException('Número de caja inválido: escribe 001 a 999.');
    return str_pad((string)(int)$numero,3,'0',STR_PAD_LEFT);
 }
 public function guardarPorNumero(int $usuario,int $sucursal,string $numero):void {
    if($usuario<=0 || $sucursal<=0)throw new DomainException('Usuario y sucursal obligatorios.');
    $numero=self::normalizarNumero($numero);
    if((string)$this->db->query('SELECT DATABASE()')->fetchColumn()!=='prueba')throw new DomainException('Sólo se permite asignar cajas en la base prueba.');
    $this->db->beginTransaction();
    try {
        $q=$this->db->prepare('SELECT nombre FROM pos_sucursales WHERE id=? AND activo=1 FOR UPDATE');$q->execute([$sucursal]);$s=$q->fetch(PDO::FETCH_ASSOC);
        if(!$s)throw new DomainException('Selecciona una sucursal activa.');
        $q=$this->db->prepare('SELECT id,codigo,activa FROM pos_cajas WHERE sucursal_id=? FOR UPDATE');$q->execute([$sucursal]);
        $id=0;
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if(!preg_match('/(?:^|[^0-9])([0-9]{1,3})$/',trim((string)$r['codigo']),$m) || (int)$m[1]<1)continue;
            if(str_pad((string)(int)$m[1],3,'0',STR_PAD_LEFT)!==$numero)continue;
            if((int)$r['activa']!==1)throw new DomainException('La caja asignada está deshabilitada.');
            if($id!==0)throw new DomainException('Se detectaron cajas con numeración duplicada.');
            $id=(int)$r['id'];
        }
        if($id===0){
            $q=$this->db->prepare('INSERT INTO pos_cajas(sucursal_id,codigo,nombre,activa) VALUES(?,?,?,1)');
            $q->execute([$sucursal,'CAJA-'.$numero,'Caja '.$numero.' - '.$s['nombre']]);
            $id=(int)$this->db->lastInsertId();
        }
        $q=$this->db->prepare('INSERT INTO pos_usuario_asignaciones(usuario_id,sucursal_id,caja_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE sucursal_id=VALUES(sucursal_id),caja_id=VALUES(caja_id)');
        $q->execute([$usuario,$sucursal,$id]);
        $this->db->commit();
    }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function eliminar(int $usuario):void {$q=$this->db->prepare('DELETE FROM pos_usuario_asignaciones WHERE usuario_id=?');$q->execute([$usuario]);}
}
