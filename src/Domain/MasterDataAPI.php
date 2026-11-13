<?php
declare(strict_types=1);
namespace VenVia\Domain;

use PDO;
use InvalidArgumentException;
use RuntimeException;

/** Internal, server-side SDK API. No public HTTP or SQL access from client JS.
 *  Only first operative administrator is authorized in this DEV tranche.
 *  Future role/store membership MUST be resolved by Core, not supplied by plugins.
 */
final class MasterDataAPI {
    private const SPEC = [
        'companies' => ['table'=>'vv_companies','fields'=>['legal_name','trade_name','vat_country','vat_number','tax_code','rea','pec','sdi_code','email','phone','street','postal_code','city','province','country','status']],
        'stores' => ['table'=>'vv_stores','fields'=>['code','name','active']],
        'parties' => ['table'=>'vv_parties','fields'=>['store_id','party_type','customer','supplier','legal_name','given_name','family_name','vat_country','vat_number','tax_code','email','phone','street','postal_code','city','province','country','source_system','external_ref','status']],
        'products' => ['table'=>'vv_products','fields'=>['sku','title','description','product_type','unit','vat_rate','brand','mpn','status']],
        'warehouses' => ['table'=>'vv_warehouses','fields'=>['store_id','code','name','warehouse_type','active']],
    ];
    private int $userId;
    private string $role;
    private string $area;

    public function __construct(private PDO $db, array $trustedContext) {
        $this->userId=(int)($trustedContext['user_id']??0);
        $this->role=(string)($trustedContext['role']??'');
        $this->area=(string)($trustedContext['area']??'');
        $caps=$trustedContext['capabilities']??[];
        // Fail closed until Core implements server-resolved roles and store membership.
        if($this->userId<1 || $this->role!=='administrator' || !in_array('backend.access',$caps,true) || $this->area!=='backend') {
            throw new RuntimeException('Master Data: permesso negato; in questa versione è richiesto l’Amministratore gestionale nel backend.');
        }
        $stmt=$db->prepare('SELECT 1 FROM vv_operational_users WHERE id=? AND role=? AND active=1');
        $stmt->execute([$this->userId,'administrator']);
        if(!$stmt->fetchColumn())throw new RuntimeException('Account operativo non attivo');
    }
    private static function def(string $entity): array {
        return self::SPEC[$entity]??throw new InvalidArgumentException('Risorsa Master Data non riconosciuta');
    }
    private static function positive(mixed $v): int {
        if((is_int($v)||is_string($v)) && preg_match('/^[1-9][0-9]*$/D',(string)$v) && (int)$v>0)return (int)$v;
        throw new InvalidArgumentException('ID non valido');
    }
    private static function clean(string $entity, array $input): array {
        $def=self::def($entity);
        foreach($input as $k=>$v) if(!in_array($k,$def['fields'],true)) throw new InvalidArgumentException('Campo non ammesso: '.$k);
        foreach($input as $k=>$v){
            if($v===null)continue;
            if(in_array($k,['active','customer','supplier'],true)) {
                if(!in_array($v,[0,1,true,false],true))throw new InvalidArgumentException('Flag non valido: '.$k);
                $input[$k]=(int)(bool)$v;continue;
            }
            if($k==='store_id'){$input[$k]=self::positive($v);continue;}
            if(!is_string($v) && !is_int($v))throw new InvalidArgumentException('Tipo campo non valido: '.$k);
            $v=trim((string)$v);
            if(strlen($v)>2000)throw new InvalidArgumentException('Campo troppo lungo: '.$k);
            $input[$k]=($v==='')?null:$v;
        }
        $len=['code'=>32,'sku'=>80,'name'=>160,'title'=>240,'legal_name'=>200,'given_name'=>100,'family_name'=>100,'email'=>254,'phone'=>64,'vat_number'=>32,'tax_code'=>32,'unit'=>16,'brand'=>120,'mpn'=>100,'source_system'=>60,'external_ref'=>160,'country'=>2,'vat_country'=>2,'status'=>8,'party_type'=>12,'warehouse_type'=>12,'product_type'=>7,'sdi_code'=>7];
        foreach($input as $k=>$v)if(is_string($v)&&isset($len[$k])&&strlen($v)>$len[$k])throw new InvalidArgumentException('Lunghezza campo non valida: '.$k);
        foreach(['country','vat_country'] as $k)if(isset($input[$k])) {
            $input[$k]=strtoupper($input[$k]);
            if(!preg_match('/^[A-Z]{2}$/D',$input[$k]))throw new InvalidArgumentException('Codice paese non valido');
        }
        if(isset($input['email']) && !filter_var($input['email'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Email non valida');
        $enums=['status'=>['active','inactive'],'party_type'=>['individual','organization'],'product_type'=>['goods','service'],'warehouse_type'=>['central','store','transit','service']];
        foreach($enums as $k=>$allowed)if(isset($input[$k])&&!in_array($input[$k],$allowed,true))throw new InvalidArgumentException('Valore non consentito: '.$k);
        if(isset($input['vat_rate'])) {
            if(!is_numeric($input['vat_rate']) || (float)$input['vat_rate']<0 || (float)$input['vat_rate']>100 || !preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D',(string)$input['vat_rate']))throw new InvalidArgumentException('Aliquota IVA non valida');
        }
        foreach(['code','sku'] as $k)if(isset($input[$k]) && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D',$input[$k]))throw new InvalidArgumentException('Codice non valido: '.$k);
        return $input;
    }
    private function primaryId(): int {
        $ids=$this->db->query("SELECT id FROM vv_companies WHERE company_type='primary'")->fetchAll(PDO::FETCH_COLUMN);
        if(count($ids)!==1)throw new RuntimeException('Prima configurare un’unica azienda principale');
        return (int)$ids[0];
    }
    public function primaryCompany(): ?array {
        $s=$this->db->query("SELECT * FROM vv_companies WHERE company_type='primary'");
        return $s->fetch(PDO::FETCH_ASSOC)?:null;
    }
    /** Read is limited to the primary company's data; affiliate data are intentionally inaccessible. */
    public function list(string $entity, int $limit=50, int $offset=0): array {
        $def=self::def($entity);$this->primaryId();
        if($limit<1||$limit>100||$offset<0||$offset>1000000)throw new InvalidArgumentException('Paginazione non valida');
        $sql='SELECT * FROM '.$def['table'];$args=[];
        if($entity==='companies')$sql.=" WHERE company_type='primary'";
        elseif($entity!=='products'){$sql.=' WHERE company_id=?';$args[]=$this->primaryId();}
        $sql.=' ORDER BY id DESC LIMIT '.$limit.' OFFSET '.$offset;
        $q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function get(string $entity, int $id): ?array {
        $def=self::def($entity);$this->primaryId();$id=self::positive($id);
        $sql='SELECT * FROM '.$def['table'].' WHERE id=?';$args=[$id];
        if($entity==='companies')$sql.=" AND company_type='primary'";
        elseif($entity!=='products'){$sql.=' AND company_id=?';$args[]=$this->primaryId();}
        $q=$this->db->prepare($sql);$q->execute($args);return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }
    private function validateParty(array $row): void {
        if(empty($row['customer']) && empty($row['supplier']))throw new InvalidArgumentException('Cliente o fornitore obbligatorio');
        if(($row['party_type']??'')==='organization' && empty($row['legal_name']))throw new InvalidArgumentException('Ragione sociale obbligatoria');
        if(($row['party_type']??'')==='individual' && (empty($row['given_name']) || empty($row['family_name'])))throw new InvalidArgumentException('Nome e cognome obbligatori');
        if(!in_array($row['party_type']??null,['organization','individual'],true))throw new InvalidArgumentException('Tipo anagrafica obbligatorio');
        // Client records are scoped to a store, never shared across stores by default.
        if(!empty($row['customer']) && empty($row['store_id']))throw new InvalidArgumentException('Il cliente richiede un negozio proprietario');
        if(!empty($row['store_id']))$this->validateStore((int)$row['store_id']);
    }
    private function validateStore(int $storeId): void {
        $q=$this->db->prepare('SELECT 1 FROM vv_stores WHERE id=? AND company_id=? AND active=1');
        $q->execute([$storeId,$this->primaryId()]);
        if(!$q->fetchColumn())throw new InvalidArgumentException('Negozio non valido o inattivo');
    }
    private function validate(string $entity,array $row): void {
        if($entity==='companies'&&empty($row['legal_name']))throw new InvalidArgumentException('Ragione sociale obbligatoria');
        if(in_array($entity,['stores','warehouses'],true)&& (empty($row['code'])||empty($row['name'])))throw new InvalidArgumentException('Codice e nome obbligatori');
        if($entity==='products'&&(empty($row['sku'])||empty($row['title'])))throw new InvalidArgumentException('SKU e nome prodotto obbligatori');
        if($entity==='parties')$this->validateParty($row);
        if($entity==='warehouses'){
            $type=$row['warehouse_type']??'store';
            if($type==='store'&&empty($row['store_id']))throw new InvalidArgumentException('Magazzino negozio: negozio obbligatorio');
            if(!empty($row['store_id']))$this->validateStore((int)$row['store_id']);
        }
    }
    private function audit(string $entity,int $id,string $event,array $changes,?int $companyId): void {
        $q=$this->db->prepare('INSERT INTO vv_masterdata_audit(entity,entity_id,event,actor_user_id,actor_role,company_id,payload_json) VALUES (?,?,?,?,?,?,?)');
        // Do not put personal data or credentials in general technical log; record field names only.
        $q->execute([$entity,$id,$event,$this->userId,$this->role,$companyId,json_encode(['fields'=>array_keys($changes)],JSON_THROW_ON_ERROR)]);
    }
    public function create(string $entity,array $values): array {
        $def=self::def($entity);$values=self::clean($entity,$values);
        if($entity!=='companies')$this->primaryId();
        if($entity==='companies') {
            if($this->primaryCompany()!==null)throw new RuntimeException('Azienda principale già configurata');
            $values['company_type']='primary';
        } elseif($entity!=='products') $values['company_id']=$this->primaryId();
        $this->validate($entity,$values);
        if($entity==='parties'){
            $values['customer']=$values['customer']??0;$values['supplier']=$values['supplier']??0;
            $this->validateParty($values);
        }
        $columns=array_keys($values);
        if(!$columns)throw new InvalidArgumentException('Dati mancanti');
        $sql='INSERT INTO '.$def['table'].' (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')';
        $this->db->beginTransaction();
        try {
            $s=$this->db->prepare($sql);$s->execute(array_values($values));
            $id=(int)$this->db->lastInsertId();
            $this->audit($entity,$id,'create',$values,$entity==='products'?null:($entity==='companies'?$id:(int)$values['company_id']));
            $this->db->commit();return $this->get($entity,$id)??throw new RuntimeException('Anagrafica non recuperabile');
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    /** Optimistic locking: caller MUST supply revision read from GET. */
    public function update(string $entity,int $id,int $expectedRevision,array $changes): array {
        $def=self::def($entity);$id=self::positive($id);$expectedRevision=self::positive($expectedRevision);
        $changes=self::clean($entity,$changes);
        if(!$changes)throw new InvalidArgumentException('Nessuna modifica richiesta');
        $this->db->beginTransaction();
        try {
            $old=$this->get($entity,$id);if(!$old)throw new RuntimeException('Anagrafica inesistente o non autorizzata');
            if((int)$old['revision']!==$expectedRevision)throw new RuntimeException('Conflitto di revisione: ricaricare il record');
            if(in_array($entity,['parties','warehouses'],true) && array_key_exists('store_id',$changes) && (string)($changes['store_id']??'')!==(string)($old['store_id']??'')) throw new RuntimeException('Trasferimento proprietà negozio non consentito in questa versione');
            $merged=array_merge($old,$changes);$this->validate($entity,$merged);
            $sets=[];$args=[];foreach($changes as $key=>$value){$sets[]='`'.$key.'`=?';$args[]=$value;}
            $sql='UPDATE '.$def['table'].' SET '.implode(',',$sets).',revision=revision+1 WHERE id=? AND revision=?';
            $args[]=$id;$args[]=$expectedRevision;
            $s=$this->db->prepare($sql);$s->execute($args);
            if($s->rowCount()!==1)throw new RuntimeException('Conflitto di revisione: nessun aggiornamento');
            $this->audit($entity,$id,'update',$changes,$entity==='products'?null:(int)($old['company_id']??$id));
            $this->db->commit();return $this->get($entity,$id)??throw new RuntimeException('Anagrafica non recuperabile');
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    /** Product codes belong to the shared catalog. Duplicates are rejected by DB. */
    public function registerProductCode(int $productId,string $type,string $code): array {
        $productId=self::positive($productId);
        if(!$this->get('products',$productId))throw new InvalidArgumentException('Prodotto non trovato');
        if(!in_array($type,['EAN','UPC','GTIN','OTHER'],true))throw new InvalidArgumentException('Tipo codice non consentito');
        $code=trim($code);
        if($code===''||strlen($code)>80||!preg_match('/^[A-Za-z0-9._-]+$/D',$code))throw new InvalidArgumentException('Codice articolo non valido');
        $this->db->beginTransaction();
        try{
            $stmt=$this->db->prepare('INSERT INTO vv_product_codes(product_id,code_type,code) VALUES (?,?,?)');
            $stmt->execute([$productId,$type,$code]);
            $id=(int)$this->db->lastInsertId();
            $this->audit('product_codes',$id,'create',['product_id'=>$productId,'code_type'=>$type,'code'=>$code],null);
            $this->db->commit();
            return ['id'=>$id,'product_id'=>$productId,'code_type'=>$type,'code'=>$code];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function productCodes(int $productId): array {
        $productId=self::positive($productId);
        if(!$this->get('products',$productId))throw new InvalidArgumentException('Prodotto non trovato');
        $q=$this->db->prepare('SELECT id,product_id,code_type,code FROM vv_product_codes WHERE product_id=? ORDER BY id');
        $q->execute([$productId]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
