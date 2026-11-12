<?php
declare(strict_types=1);
namespace VenVia;
use PDO;
use RuntimeException;

final class EmailRecovery {
    public static function available(PDO $db):bool {
        return (int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='vv_email_recovery'")->fetchColumn()===1;
    }
    public static function request(PDO $db,string $scope,string $username,string $ip):void {
        if(!self::available($db)||!MailService::configured())return;
        if(!in_array($scope,['technical','operational'],true)||strlen($username)>64)return;
        $iphash=hash_hmac('sha256','email-reset:'.$scope.':'.$ip,Core::config()['ip_salt']);
        $q=$db->prepare('SELECT COUNT(*) FROM vv_email_recovery WHERE ip_hash=? AND created_at>NOW()-INTERVAL 1 HOUR');
        $q->execute([$iphash]);if((int)$q->fetchColumn()>=5)return;
        $table=$scope==='technical'?'vv_technical_admin':'vv_operational_users';
        $q=$db->prepare("SELECT id,email,email_verified_at FROM $table WHERE username=?".($scope==='operational'?' AND active=1':''));$q->execute([$username]);$u=$q->fetch();
        if(!$u || !$u['email_verified_at'])return;
        self::issue($db,$scope,(int)$u['id'],(string)$u['email'],'password',$iphash);
    }
    public static function requestVerification(PDO $db,string $scope,int $id,string $ip):void {
        if(!self::available($db)||!MailService::configured())throw new RuntimeException('SMTP o schema recupero non configurati');
        $table=$scope==='technical'?'vv_technical_admin':'vv_operational_users';
        if(!in_array($scope,['technical','operational'],true))throw new RuntimeException('Account non valido');
        $q=$db->prepare("SELECT email,email_verified_at FROM $table WHERE id=?");$q->execute([$id]);$row=$q->fetch();
        if(!$row)throw new RuntimeException('Account non disponibile');
        if($row['email_verified_at'])throw new RuntimeException('Email già verificata');
        $iphash=hash_hmac('sha256','email-verify:'.$scope.':'.$ip,Core::config()['ip_salt']);
        $q=$db->prepare('SELECT COUNT(*) FROM vv_email_recovery WHERE ip_hash=? AND created_at>NOW()-INTERVAL 1 HOUR');$q->execute([$iphash]);
        if((int)$q->fetchColumn()>=3)throw new RuntimeException('Troppi tentativi: riprova più tardi');
        self::issue($db,$scope,$id,(string)$row['email'],'verify',$iphash);
    }
    private static function issue(PDO $db,string $scope,int $id,string $email,string $kind,string $iphash):void {
        $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);
        $q=$db->prepare('INSERT INTO vv_email_recovery (token_hash,account_scope,account_id,purpose,ip_hash,expires_at) VALUES (?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 15 MINUTE))');
        $q->execute([$hash,$scope,$id,$kind,$iphash]);
        $urls=Core::config()['public_urls']??[];
        $base=(string)($urls[$scope]??($scope==='technical'?'https://control.venvia.it':'https://app.venvia.it'));
        if(!preg_match('#^https://[a-zA-Z0-9.:-]+$#D',$base))throw new RuntimeException('URL pubblico di recupero non valido');
        $page=$kind==='verify'?'verify-email':'reset-password';
        $link=$base.'/?page='.$page.'&token='.$token;
        $subject=$kind==='verify'?'VenVia - verifica indirizzo email':'VenVia - recupero password';
        $body="È stata richiesta un'operazione sull'account VenVia.\n\nApri questo link entro 15 minuti:\n".$link."\n\nSe non hai richiesto questa operazione, ignora il messaggio. Non condividere il link.";
        try{MailService::send($email,$subject,$body);}catch(\Throwable $e){$db->prepare('DELETE FROM vv_email_recovery WHERE token_hash=?')->execute([$hash]);error_log('VenVia email delivery failed (redacted): '.get_class($e));throw new RuntimeException('Invio email non riuscito. Verifica la configurazione SMTP.');}
    }
    public static function apply(PDO $db,string $scope,string $kind,string $token,?string $password=null,?string $mfa=null):void {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token)||!in_array($scope,['technical','operational'],true)||!in_array($kind,['verify','password'],true))throw new RuntimeException('Link non valido');
        if($kind==='password' && ($password===null||strlen($password)<15||strlen($password)>256||$mfa===null))throw new RuntimeException('Password non valida');
        $db->beginTransaction();
        try{
            $q=$db->prepare('SELECT * FROM vv_email_recovery WHERE token_hash=? AND account_scope=? AND purpose=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE');$q->execute([hash('sha256',$token),$scope,$kind]);$ticket=$q->fetch();
            if(!$ticket)throw new RuntimeException('Link scaduto o già utilizzato');
            if((int)$ticket['failed_attempts']>=5)throw new RuntimeException('Link bloccato dopo troppi tentativi');
            $table=$scope==='technical'?'vv_technical_admin':'vv_operational_users';
            $q=$db->prepare("SELECT id,totp_encrypted,email_verified_at FROM $table WHERE id=? FOR UPDATE");$q->execute([(int)$ticket['account_id']]);$account=$q->fetch();
            if(!$account)throw new RuntimeException('Account non disponibile');
            if($kind==='password'){
                if(!$account['email_verified_at'])throw new RuntimeException('Email non verificata');
                $secret=Core::unseal($account['totp_encrypted'],base64_decode(Core::config()['app_key'],true));
                if(!Core::verifyTotp($secret,(string)$mfa)){
                    $db->prepare('UPDATE vv_email_recovery SET failed_attempts=failed_attempts+1 WHERE token_hash=?')->execute([hash('sha256',$token)]);
                    $db->commit();
                    throw new RuntimeException('MFA non valido');
                }
                $q=$db->prepare("UPDATE $table SET password_hash=?,credential_version=credential_version+1 WHERE id=?");$q->execute([password_hash($password,PASSWORD_ARGON2ID),(int)$account['id']]);
            }else{
                $q=$db->prepare("UPDATE $table SET email_verified_at=NOW() WHERE id=? AND email_verified_at IS NULL");$q->execute([(int)$account['id']]);
            }
            $db->prepare('UPDATE vv_email_recovery SET used_at=NOW() WHERE token_hash=?')->execute([hash('sha256',$token)]);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
