<?php
require_once __DIR__.'/../config/database.php';
final class AsignacionPos {
 private PDO $db;
 public function __construct(){ $this->db=(new Database())->connect(); }
 public function opciones():array {return $this->db->query("SELECT s.id sucursal_id,s.nombre sucursal,c.id caja_id,c.nombre caja FROM pos_sucursales s JOIN pos_cajas c ON c.sucursal_id=s.id WHERE s.activo=1 AND c.activa=1 ORDER BY s.id,c.id")->fetchAll(PDO::FETCH_ASSOC);}
 public function obtener(int $id):?array { $q=$this->db->prepare('SELECT sucursal_id,caja_id FROM pos_usuario_asignaciones WHERE usuario_id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null; }
 public function validar(int $sucursal,int $caja):bool {$q=$this->db->prepare('SELECT 1 FROM pos_cajas c JOIN pos_sucursales s ON s.id=c.sucursal_id WHERE c.id=? AND s.id=? AND c.activa=1 AND s.activo=1');$q->execute([$caja,$sucursal]);return (bool)$q->fetchColumn();}
 public function guardar(int $usuario,int $sucursal,int $caja):void {if(!$this->validar($sucursal,$caja))throw new DomainException('Caja y sucursal POS no corresponden.');$q=$this->db->prepare('INSERT INTO pos_usuario_asignaciones(usuario_id,sucursal_id,caja_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE sucursal_id=VALUES(sucursal_id),caja_id=VALUES(caja_id)');$q->execute([$usuario,$sucursal,$caja]);}
 public function eliminar(int $usuario):void {$q=$this->db->prepare('DELETE FROM pos_usuario_asignaciones WHERE usuario_id=?');$q->execute([$usuario]);}
}
