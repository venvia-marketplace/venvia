<?php
declare(strict_types=1);
namespace VenVia\Domain;
use PDO;
use RuntimeException;
use InvalidArgumentException;
final class ProductStudioAPI {
 private MasterDataAPI $master;
 private int $actor;
 public function __construct(private PDO $db, array $context) {
  $this->master=new MasterDataAPI($db,$context);
  $this->actor=(int)$context['user_id'];
 }
 private static function id(mixed $v):int {if(!is_scalar($v)||!preg_match('/^[1-9][0-9]*$/D',(string)$v))throw new InvalidArgumentException('ID non valido');return (int)$v;}
 private function primary():int {$c=$this->master->primaryCompany();if(!$c)throw new RuntimeException('Configurare prima l’azienda principale');return (int)$c['id'];}
 private function audit(string $entity,int $id,string $event,array $fields):void {
  $s=$this->db->prepare('INSERT INTO vv_masterdata_audit(entity,entity_id,event,actor_user_id,actor_role,company_id,payload_json) VALUES (?,?,?,?,?,?,?)');
  $s->execute([$entity,$id,$event,$this->actor,'administrator',$this->primary(),json_encode(['fields'=>$fields],JSON_THROW_ON_ERROR)]);
 }
 public function categories():array {return $this->db->query('SELECT id,name,slug,parent_id,active,revision FROM vv_product_categories ORDER BY name LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);}
 public function createCategory(string $name,string $slug,?int $parentId=null):array {
  $this->primary();$name=trim($name);$slug=trim($slug);
  if($name===''||mb_strlen($name)>160||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug)||strlen($slug)>160)throw new InvalidArgumentException('Categoria non valida');
  if($parentId!==null){$s=$this->db->prepare('SELECT id FROM vv_product_categories WHERE id=?');$s->execute([self::id($parentId)]);if(!$s->fetchColumn())throw new InvalidArgumentException('Categoria padre inesistente');}
  $this->db->beginTransaction();try{
   $s=$this->db->prepare('INSERT INTO vv_product_categories(name,slug,parent_id) VALUES (?,?,?)');$s->execute([$name,$slug,$parentId]);$id=(int)$this->db->lastInsertId();$this->audit('categories',$id,'create',['name','slug','parent_id']);$this->db->commit();return ['id'=>$id,'name'=>$name,'slug'=>$slug,'parent_id'=>$parentId];
  }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function attachCategory(int $productId,int $categoryId):void {
  if(!$this->master->get('products',self::id($productId)))throw new InvalidArgumentException('Prodotto inesistente');
  $this->db->beginTransaction();try{
   $s=$this->db->prepare('INSERT INTO vv_product_category_links(product_id,category_id) VALUES(?,?)');$s->execute([$productId,self::id($categoryId)]);
   $this->audit('product_categories',$productId,'attach',['category_id']);$this->db->commit();
  }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function createVariant(int $productId,string $sku,array $attributes):array {
  if(!$this->master->get('products',self::id($productId)))throw new InvalidArgumentException('Prodotto inesistente');
  $sku=trim($sku);if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/D',$sku))throw new InvalidArgumentException('SKU variante non valido');
  if(!$attributes||count($attributes)>30)throw new InvalidArgumentException('Attributi variante obbligatori');
  foreach($attributes as $key=>$value)if(!is_string($key)||strlen($key)>80||!is_string($value)||strlen($value)>160||$value==='')throw new InvalidArgumentException('Attributo variante non valido');
  $this->db->beginTransaction();try{
   $s=$this->db->prepare('INSERT INTO vv_product_variants(product_id,sku,attributes_json) VALUES(?,?,?)');$s->execute([$productId,$sku,json_encode($attributes,JSON_THROW_ON_ERROR)]);
   $id=(int)$this->db->lastInsertId();$this->audit('variants',$id,'create',['product_id','sku','attributes_json']);$this->db->commit();return ['id'=>$id,'product_id'=>$productId,'sku'=>$sku,'attributes'=>$attributes];
  }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function variants(int $productId):array {
  if(!$this->master->get('products',self::id($productId)))throw new InvalidArgumentException('Prodotto inesistente');
  $s=$this->db->prepare('SELECT id,product_id,sku,attributes_json,status,revision FROM vv_product_variants WHERE product_id=?');$s->execute([$productId]);return $s->fetchAll(PDO::FETCH_ASSOC);
 }
 public function searchProducts(string $query,int $limit=30):array {
  $this->primary();$query=trim($query);if(mb_strlen($query)>160||$limit<1||$limit>100)throw new InvalidArgumentException('Parametri ricerca non validi');
  $escaped=str_replace(['\\','%','_'],['\\\\','\%','\_'],$query);
  $s=$this->db->prepare("SELECT DISTINCT p.id,p.sku,p.title,p.brand,p.status,p.revision FROM vv_products p LEFT JOIN vv_product_codes c ON c.product_id=p.id WHERE p.sku LIKE ? OR p.title LIKE ? OR c.code LIKE ? ORDER BY p.id DESC LIMIT $limit");
  $like='%'.$escaped.'%';$s->execute([$like,$like,$like]);return $s->fetchAll(PDO::FETCH_ASSOC);
 }
 public function setPrice(int $productId,?int $storeId,?int $variantId,string $amountNet,string $currency='EUR'):array {
  $productId=self::id($productId);$this->master->get('products',$productId)??throw new InvalidArgumentException('Prodotto inesistente');$cid=$this->primary();
  if($currency!=='EUR'||!preg_match('/^(?:0|[1-9][0-9]{0,10})(?:\.[0-9]{1,4})?$/D',$amountNet))throw new InvalidArgumentException('Prezzo netto non valido');
  if($storeId!==null){$storeId=self::id($storeId);$s=$this->db->prepare('SELECT 1 FROM vv_stores WHERE id=? AND company_id=? AND active=1');$s->execute([$storeId,$cid]);if(!$s->fetchColumn())throw new InvalidArgumentException('Negozio non autorizzato');}
  if($variantId!==null){$variantId=self::id($variantId);$s=$this->db->prepare('SELECT 1 FROM vv_product_variants WHERE id=? AND product_id=?');$s->execute([$variantId,$productId]);if(!$s->fetchColumn())throw new InvalidArgumentException('Variante non valida');}
  $this->db->beginTransaction();try{
   $s=$this->db->prepare('SELECT id,revision FROM vv_product_prices WHERE company_id=? AND store_slot=? AND product_id=? AND variant_slot=? AND currency=? FOR UPDATE');$s->execute([$cid,$storeId??0,$productId,$variantId??0,$currency]);$existing=$s->fetch(PDO::FETCH_ASSOC);
   if($existing){$s=$this->db->prepare('UPDATE vv_product_prices SET amount_net=?,revision=revision+1 WHERE id=?');$s->execute([$amountNet,$existing['id']]);$id=(int)$existing['id'];}
   else{$s=$this->db->prepare('INSERT INTO vv_product_prices(company_id,store_id,product_id,variant_id,currency,amount_net) VALUES(?,?,?,?,?,?)');$s->execute([$cid,$storeId,$productId,$variantId,$currency,$amountNet]);$id=(int)$this->db->lastInsertId();}
   $this->audit('prices',$id,$existing?'update':'create',['amount_net']);$this->db->commit();return ['id'=>$id,'company_id'=>$cid,'store_id'=>$storeId,'product_id'=>$productId,'variant_id'=>$variantId,'currency'=>$currency,'amount_net'=>$amountNet];
  }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function prices(int $productId):array {
  if(!$this->master->get('products',self::id($productId)))throw new InvalidArgumentException('Prodotto inesistente');
  $s=$this->db->prepare('SELECT id,store_id,product_id,variant_id,currency,amount_net,status,revision FROM vv_product_prices WHERE company_id=? AND product_id=? ORDER BY id');$s->execute([$this->primary(),$productId]);return $s->fetchAll(PDO::FETCH_ASSOC);
 }
 /** Metadata only: no upload, no public URLs, no trust in arbitrary filesystem path. */
 public function media(int $productId):array {
  if(!$this->master->get('products',self::id($productId)))throw new InvalidArgumentException('Prodotto inesistente');
  $s=$this->db->prepare('SELECT id,variant_id,storage_key,mime_type,alt_text,position FROM vv_product_media WHERE product_id=? ORDER BY position,id');$s->execute([$productId]);return $s->fetchAll(PDO::FETCH_ASSOC);
 }
 public function bulkUpdateProducts(array $items):array {
  if(!$items||count($items)>100)throw new InvalidArgumentException('Massimo 100 prodotti per modifica massiva');
  // Atomic batch. Calls internal update() which starts transactions: explicitly refuse until proper batch transaction orchestration.
  throw new RuntimeException('Modifiche massive non ancora abilitate: attesa motore transazionale dedicato');
 }
}
