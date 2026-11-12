<?php
declare(strict_types=1);
namespace VenVia\Domain;
use PDO;
use Throwable;
use InvalidArgumentException;
use RuntimeException;
final class MasterDataHttp {
 public static function dispatch(PDO $db,array $context):never {
  header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
  if(($context['area']??'')!=='backend'){self::reply(403,['ok'=>false,'error'=>'Accesso non consentito']);}
  $method=(string)($_SERVER['REQUEST_METHOD']??'GET');
  if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');self::reply(405,['ok'=>false,'error'=>'Metodo non consentito']);}
  $action=(string)($_GET['action']??'');
  try {
   $api=new MasterDataAPI($db,$context);$studio=new ProductStudioAPI($db,$context);$fiscal=new FiscalHistoryAPI($db,$context);
   if($method==='GET'){
    $entity=(string)($_GET['entity']??'');
    $result=match($action){
     'company'=>$api->primaryCompany(),
     'fiscal_subjects'=>$fiscal->subjects(),
     'fiscal_snapshots'=>$fiscal->snapshots((int)($_GET['subject_id']??0)),
     'fiscal_transitions'=>$fiscal->transitions((int)($_GET['store_id']??0)),
     'list'=>$api->list($entity,(int)($_GET['limit']??50),(int)($_GET['offset']??0)),
     'get'=>$api->get($entity,(int)($_GET['id']??0)),
     'product_codes'=>$api->productCodes((int)($_GET['product_id']??0)),
     'product_search'=>$studio->searchProducts((string)($_GET['q']??''),(int)($_GET['limit']??30)),
     'categories'=>$studio->categories(),
     'variants'=>$studio->variants((int)($_GET['product_id']??0)),
     'prices'=>$studio->prices((int)($_GET['product_id']??0)),
     'media'=>$studio->media((int)($_GET['product_id']??0)),
     default=>throw new InvalidArgumentException('Azione non disponibile')
    };
   }else{
    // Core checkCsrf() already executed by app.php before dispatch. All writes require form csrf and JSON payload.
    $json=(string)($_POST['payload']??'');if(strlen($json)>65536)throw new InvalidArgumentException('Payload troppo grande');
    $data=json_decode($json,true,64,JSON_THROW_ON_ERROR);if(!is_array($data)||array_is_list($data))throw new InvalidArgumentException('Payload deve essere oggetto JSON');
    $entity=(string)($data['entity']??'');$values=$data['values']??null;
    $result=match($action){
     'create'=>$api->create($entity,self::fields($values)),
     'fiscal_register'=>$fiscal->registerSubject((string)($data['subject_code']??''),self::fields($data['fiscal']??null)),
     'fiscal_new_snapshot'=>$fiscal->newSnapshot((int)($data['subject_id']??0),self::fields($data['fiscal']??null)),
     'fiscal_transition_draft'=>$fiscal->proposeTransition((int)($data['store_id']??0),(int)($data['snapshot_id']??0),(string)($data['effective_from']??''),(string)($data['reason']??'')),
     'update'=>$api->update($entity,(int)($data['id']??0),(int)($data['revision']??0),self::fields($values)),
     'product_code'=>$api->registerProductCode((int)($data['product_id']??0),(string)($data['type']??''),(string)($data['code']??'')),
     'category_create'=>$studio->createCategory((string)($data['name']??''),(string)($data['slug']??''),isset($data['parent_id'])?(int)$data['parent_id']:null),
     'category_attach'=>$studio->attachCategory((int)($data['product_id']??0),(int)($data['category_id']??0)),
     'variant_create'=>$studio->createVariant((int)($data['product_id']??0),(string)($data['sku']??''),self::fields($data['attributes']??null)),
     'price_set'=>$studio->setPrice((int)($data['product_id']??0),isset($data['store_id'])?(int)$data['store_id']:null,isset($data['variant_id'])?(int)$data['variant_id']:null,(string)($data['amount_net']??''),(string)($data['currency']??'EUR')),
     default=>throw new InvalidArgumentException('Azione non disponibile')
    };
   }
   self::reply(200,['ok'=>true,'data'=>$result]);
  }catch(InvalidArgumentException|\JsonException $e){self::reply(422,['ok'=>false,'error'=>$e->getMessage()]);}
   catch(RuntimeException $e){self::reply(409,['ok'=>false,'error'=>'Operazione non disponibile, non autorizzata oppure in conflitto']);}
   catch(Throwable $e){error_log('VenVia MasterData HTTP: '.get_class($e));self::reply(500,['ok'=>false,'error'=>'Errore interno']);}
 }
 private static function fields(mixed $data):array {if(!is_array($data)||array_is_list($data))throw new InvalidArgumentException('Oggetto obbligatorio');return $data;}
 private static function reply(int $code,array $json):never {http_response_code($code);echo json_encode($json,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);exit;}
}
