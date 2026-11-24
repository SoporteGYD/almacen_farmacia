<?php
declare(strict_types=1);
/* Documentos auxiliares del POS: no son cobros ni CFDI. */
final class GydPosDocumentos {
    private PDO $pdo;
    private PuntoVenta $pos;
    private array $usuario;

    public function __construct(array $usuario, PuntoVenta $pos) {
        $this->usuario=$usuario;
        $this->pos=$pos;
        $this->pdo=(new Database())->connect();
    }
    private function caja(int $id): void { $this->pos->cajaAutorizada($this->usuario,$id); }
    private function usuarioId():int {return (int)($this->usuario['id']??0);}
    public function listar(int $caja):array {
        $this->caja($caja);
        $s=$this->pdo->prepare('SELECT id,tipo,titulo,cliente_nombre,creado_en,actualizado_en FROM gyd_pos_documentos WHERE caja_id=? AND usuario_id=? ORDER BY actualizado_en DESC,id DESC LIMIT 80');
        $s->execute([$caja,$this->usuarioId()]);
        return ['documentos'=>$s->fetchAll(PDO::FETCH_ASSOC)];
    }
    public function detalle(int $caja,int $id):array {
        $this->caja($caja);
        if($id<=0)throw new DomainException('Documento no válido.');
        $s=$this->pdo->prepare('SELECT id,tipo,titulo,cliente_nombre,quien_lleva,items_json,actualizado_en FROM gyd_pos_documentos WHERE id=? AND caja_id=? AND usuario_id=? LIMIT 1');
        $s->execute([$id,$caja,$this->usuarioId()]);
        $x=$s->fetch(PDO::FETCH_ASSOC);
        if(!$x)throw new DomainException('No se encontró el documento o no tienes acceso.');
        $items=json_decode((string)$x['items_json'],true);
        if(!is_array($items))throw new DomainException('El documento tiene un formato de partidas inválido.');
        unset($x['items_json']);$x['items']=$items;
        return ['documento'=>$x];
    }
    public function guardar(int $caja,array $data):array {
        $this->caja($caja);
        $tipo=strtoupper(trim((string)($data['tipo']??'')));
        if(!in_array($tipo,['PENDIENTE','COTIZACION'],true))throw new DomainException('Selecciona venta pendiente o cotización.');
        $titulo=trim((string)($data['titulo']??''));
        if($titulo===''||strlen($titulo)>120)throw new DomainException('Escribe un nombre corto para identificar el documento.');
        $cliente=trim((string)($data['cliente_nombre']??''));$lleva=trim((string)($data['quien_lleva']??''));
        if(strlen($cliente)>120||strlen($lleva)>120)throw new DomainException('Nombre demasiado largo.');
        $items=$data['items']??null;
        if(!is_array($items)||!count($items)||count($items)>150)throw new DomainException('Agrega entre 1 y 150 artículos.');
        $normal=[];
        foreach($items as $item){
            if(!is_array($item))throw new DomainException('Formato de producto inválido.');
            $id=filter_var($item['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $cantidad=filter_var($item['cantidad']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
            $pres=strtoupper((string)($item['presentacion']??''));
            $codigo=trim((string)($item['codigo']??''));
            if(!$id||!$cantidad||!in_array($pres,['PIEZA','CAJA'],true)||strlen($codigo)>100)throw new DomainException('Hay artículos con cantidades o presentaciones inválidas.');
            $k=$id.':'.$pres;
            if(isset($normal[$k]))throw new DomainException('Hay una partida duplicada.');
            $normal[$k]=['id'=>(int)$id,'cantidad'=>(int)$cantidad,'presentacion'=>$pres,'codigo'=>$codigo];
        }
        $json=json_encode(array_values($normal),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if(strlen($json)>65000)throw new DomainException('Demasiados productos en el documento.');
        $s=$this->pdo->prepare('INSERT INTO gyd_pos_documentos(usuario_id,caja_id,tipo,titulo,cliente_nombre,quien_lleva,items_json) VALUES(?,?,?,?,?,?,?)');
        $s->execute([$this->usuarioId(),$caja,$tipo,$titulo,$cliente,$lleva,$json]);
        return ['id'=>(int)$this->pdo->lastInsertId(),'tipo'=>$tipo,'mensaje'=>'Documento guardado. No se generó venta ni se descontó inventario.'];
    }
    public function eliminar(int $caja,int $id):array {
        $this->caja($caja);
        $s=$this->pdo->prepare('DELETE FROM gyd_pos_documentos WHERE id=? AND caja_id=? AND usuario_id=?');
        $s->execute([$id,$caja,$this->usuarioId()]);
        if($s->rowCount()!==1)throw new DomainException('No se encontró el documento.');
        return ['ok'=>true];
    }
    public function repetir(int $caja,int $ventaId):array {
        $this->caja($caja);
        if($ventaId<=0)throw new DomainException('Indica un ticket válido.');
        $ticket=$this->pos->ticket($ventaId,$this->usuario);
        if(!$ticket || (int)($ticket['caja_id']??0)!==$caja || (string)($ticket['estado']??'')!=='PAGADA'){
            throw new DomainException('No se encontró un ticket pagado en la caja actual.');
        }
        $items=[];
        foreach(($ticket['items']??[]) as $x){
            $items[]=['id'=>(int)$x['producto_id'],'codigo'=>(string)$x['codigo_snapshot'],'cantidad'=>(int)$x['cantidad'],'presentacion'=>(string)$x['presentacion']];
        }
        return ['documento'=>['tipo'=>'REPETIR_VENTA','titulo'=>'Repetir '.$ticket['folio'],'cliente_nombre'=>(string)($ticket['cliente_nombre']??''),'quien_lleva'=>(string)($ticket['quien_lleva']??''),'items'=>$items]];
    }
}
