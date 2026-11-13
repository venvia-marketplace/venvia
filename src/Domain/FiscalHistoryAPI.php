<?php
declare(strict_types=1);
namespace VenVia\Domain;
use PDO;
use RuntimeException;
use InvalidArgumentException;

/** Historical legal identities. Immutable snapshots and draft transition plans.
 * No document issuer activation, stock ownership transfer or retroactive rewriting here.
 */
final class FiscalHistoryAPI {
 private MasterDataAPI $master;
 private int $actor;
 private int $company;
 public function __construct(private PDO $db,array $trustedContext) {
  $this->master=new MasterDataAPI($db,$trustedContext);
  $this->actor=(int)$trustedContext['user_id'];
  $primary=$this->master->primaryCompany();
  if(!$primary)throw new RuntimeException('Configurare prima l’azienda principale');
  $this->company=(int)$primary['id'];
 }
 private static function pos(int $id):int {if($id<1)throw new InvalidArgumentException('ID non valido');return $id;}
 private function audit(string $event,?int $subject,?int $snapshot,?int $assignment,array $details=[]):void {
  $q=$this->db->prepare('INSERT INTO vv_fiscal_change_audit(company_id,actor_user_id,event_type,subject_id,snapshot_id,assignment_id,details_json) VALUES(?,?,?,?,?,?,?)');
  $q->execute([$this->company,$this->actor,$event,$subject,$snapshot,$assignment,json_encode($details,JSON_THROW_ON_ERROR)]);
 }
 public function registerSubject(string $code,array $fiscal):array {
  $code=trim($code);
  if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D',$code))throw new InvalidArgumentException('Codice identità non valido');
  $fields=['legal_name','vat_country','vat_number','tax_code','pec','sdi_code','street','postal_code','city','province','country'];
  foreach($fiscal as $k=>$v)if(!in_array($k,$fields,true)||($v!==null&&!is_string($v)))throw new InvalidArgumentException('Campo fiscale non valido');
  $legal=trim((string)($fiscal['legal_name']??''));if($legal===''||mb_strlen($legal)>200)throw new InvalidArgumentException('Ragione sociale obbligatoria');
  $lengths=['vat_country'=>2,'vat_number'=>32,'tax_code'=>32,'pec'=>254,'sdi_code'=>7,'street'=>200,'postal_code'=>16,'city'=>100,'province'=>16,'country'=>2];
  foreach($lengths as $k=>$max)if(isset($fiscal[$k])&&mb_strlen($fiscal[$k])>$max)throw new InvalidArgumentException('Campo troppo lungo: '.$k);
  foreach(['country','vat_country'] as $k)if(isset($fiscal[$k])&&!preg_match('/^[A-Z]{2}$/D',$fiscal[$k]))throw new InvalidArgumentException('Codice paese non valido');
  foreach(['country','vat_country'] as $k)if(isset($fiscal[$k])&&!preg_match('/^[A-Z]{2}$/D',$fiscal[$k]))throw new InvalidArgumentException('Codice paese non valido');
  if(isset($fiscal['pec'])&&!filter_var($fiscal['pec'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('PEC non valida');
  $this->db->beginTransaction();try{
   $s=$this->db->prepare('INSERT INTO vv_fiscal_subjects(company_id,subject_code) VALUES(?,?)');$s->execute([$this->company,$code]);$subject=(int)$this->db->lastInsertId();
   $cols=array_merge(['company_id'=>$this->company,'subject_id'=>$subject,'registered_by'=>$this->actor],$fiscal);
   $keys=array_keys($cols);$sql='INSERT INTO vv_fiscal_snapshots (`'.implode('`,`',$keys).'`) VALUES ('.implode(',',array_fill(0,count($keys),'?')).')';
   $s=$this->db->prepare($sql);$s->execute(array_values($cols));$snapshot=(int)$this->db->lastInsertId();
   $this->audit('subject_registered',$subject,$snapshot,null,['fields'=>array_keys($fiscal)]);
   $this->db->commit();return ['subject_id'=>$subject,'snapshot_id'=>$snapshot,'subject_code'=>$code];
  }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function newSnapshot(int $subjectId,array $fiscal):array {
  self::pos($subjectId);
  $stmt=$this->db->prepare('SELECT subject_code FROM vv_fiscal_subjects WHERE id=? AND company_id=?');$stmt->execute([$subjectId,$this->company]);
  if(!$stmt->fetchColumn())throw new InvalidArgumentException('Soggetto inesistente');
  // Snapshot changes are immutable; same subject ID is retained for ordinary changes.
  $allowed=['legal_name','vat_country','vat_number','tax_code','pec','sdi_code','street','postal_code','city','province','country'];
  foreach($fiscal as $k=>$v)if(!in_array($k,$allowed,true)||($v!==null&&!is_string($v)))throw new InvalidArgumentException('Campo non consentito');
  $q=$this->db->prepare('SELECT * FROM vv_fiscal_snapshots WHERE company_id=? AND subject_id=? ORDER BY id DESC LIMIT 1');$q->execute([$this->company,$subjectId]);$old=$q->fetch(PDO::FETCH_ASSOC);
  if(!$old)throw new RuntimeException('Snapshot precedente mancante');
  $fields=[];foreach($allowed as $k)$fields[$k]=$fiscal[$k]??$old[$k];
  if(!is_string($fields['legal_name'])||trim($fields['legal_name'])==='')throw new InvalidArgumentException('Ragione sociale mancante');
  // Validate against same constraints as a newly registered subject using private validator by rolling through explicit checks.
  $len=['legal_name'=>200,'vat_country'=>2,'vat_number'=>32,'tax_code'=>32,'pec'=>254,'sdi_code'=>7,'street'=>200,'postal_code'=>16,'city'=>100,'province'=>16,'country'=>2];
  foreach($fields as $k=>$v)if($v!==null&&mb_strlen($v)>$len[$k])throw new InvalidArgumentException('Campo troppo lungo');
  foreach(['country','vat_country'] as $k)if($fields[$k]!==null&&!preg_match('/^[A-Z]{2}$/D',$fields[$k]))throw new InvalidArgumentException('Paese non valido');
  if($fields['pec']!==null&&!filter_var($fields['pec'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('PEC non valida');
  $this->db->beginTransaction();try{
   $cols=array_merge(['company_id'=>$this->company,'subject_id'=>$subjectId,'registered_by'=>$this->actor],$fields);
   $keys=array_keys($cols);$q=$this->db->prepare('INSERT INTO vv_fiscal_snapshots (`'.implode('`,`',$keys).'`) VALUES ('.implode(',',array_fill(0,count($keys),'?')).')');$q->execute(array_values($cols));$id=(int)$this->db->lastInsertId();
   $this->audit('snapshot_registered',$subjectId,$id,null,['fields'=>array_keys($fiscal)]);
   $this->db->commit();return ['subject_id'=>$subjectId,'snapshot_id'=>$id];
  }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function subjects():array {
  $q=$this->db->prepare('SELECT s.id,s.subject_code,s.created_at FROM vv_fiscal_subjects s WHERE s.company_id=? ORDER BY s.id');$q->execute([$this->company]);return $q->fetchAll(PDO::FETCH_ASSOC);
 }
 public function snapshots(int $subjectId):array {
  $q=$this->db->prepare('SELECT f.* FROM vv_fiscal_snapshots f WHERE f.company_id=? AND f.subject_id=? ORDER BY f.id');$q->execute([$this->company,self::pos($subjectId)]);return $q->fetchAll(PDO::FETCH_ASSOC);
 }
 public function proposeTransition(int $storeId,int $snapshotId,string $from,string $reason):array {
  self::pos($storeId);self::pos($snapshotId);$reason=trim($reason);
  $dt=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$from);if(!$dt||$dt->format('Y-m-d H:i:s')!==$from)throw new InvalidArgumentException('Data di decorrenza non valida');
  if($reason===''||mb_strlen($reason)>200)throw new InvalidArgumentException('Motivo obbligatorio');
  $this->db->beginTransaction();try{
   $s=$this->db->prepare('SELECT id FROM vv_stores WHERE id=? AND company_id=? AND active=1 FOR UPDATE');$s->execute([$storeId,$this->company]);if(!$s->fetchColumn())throw new InvalidArgumentException('Negozio non autorizzato');
   $s=$this->db->prepare('SELECT 1 FROM vv_fiscal_snapshots WHERE id=? AND company_id=?');$s->execute([$snapshotId,$this->company]);if(!$s->fetchColumn())throw new InvalidArgumentException('Identità fiscale non autorizzata');
   // While activations are not implemented, accept at most one non-void proposal per store.
   $s=$this->db->prepare("SELECT 1 FROM vv_store_fiscal_assignments WHERE store_id=? AND status<>'void' LIMIT 1");
   $s->execute([$storeId]);if($s->fetchColumn())throw new RuntimeException('Proposta fiscale già presente per il negozio');
   $s=$this->db->prepare('INSERT INTO vv_store_fiscal_assignments(company_id,store_id,snapshot_id,effective_from,status,reason,recorded_by) VALUES(?,?,?,?,?,?,?)');
   $s->execute([$this->company,$storeId,$snapshotId,$from,'draft',$reason,$this->actor]);$id=(int)$this->db->lastInsertId();
   $this->audit('transition_proposed',null,$snapshotId,$id,['store_id'=>$storeId,'effective_from'=>$from]);
   $this->db->commit();return ['id'=>$id,'store_id'=>$storeId,'snapshot_id'=>$snapshotId,'effective_from'=>$from,'status'=>'draft'];
  }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function transitions(int $storeId):array {
  $q=$this->db->prepare('SELECT id,store_id,snapshot_id,effective_from,effective_until,status,reason,recorded_at FROM vv_store_fiscal_assignments WHERE company_id=? AND store_id=? ORDER BY effective_from,id');
  $q->execute([$this->company,self::pos($storeId)]);return $q->fetchAll(PDO::FETCH_ASSOC);
 }
}
