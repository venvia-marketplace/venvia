<?php
declare(strict_types=1);
namespace VenVia;
use PDO;
use RuntimeException;

/** Security-critical operations. Caller context is server-derived, never supplied by the browser. */
final class IdentityRecovery {
    private const ALLOWED = ['technical'=>'vv_technical_admin', 'operational'=>'vv_operational_users'];
    private static function table(string $scope): string { return self::ALLOWED[$scope] ?? throw new RuntimeException('Ambito non valido'); }
    public static function generate(string $scope):string {
        return ($scope==='technical'?'VV-':'VV-OP-REC-').strtoupper(bin2hex(random_bytes(32)));
    }
    public static function appKey():string {
        $key=base64_decode(Core::config()['app_key'],true);
        if(!is_string($key) || strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)throw new RuntimeException('Chiave applicativa non valida');
        return $key;
    }
    public static function checkRecovery(PDO $db,string $scope,string $username,string $recovery):int {
        $table=self::table($scope);
        if(!preg_match('/^(?:VV-[A-F0-9]{64}|VV-OP-REC-[A-F0-9]{64})$/D',$recovery))return 0;
        if(strlen($username)>64||$username==='')return 0;
        $sql="SELECT id,recovery_hash FROM $table WHERE username=?".($scope==='operational'?' AND active=1':'');
        $s=$db->prepare($sql);$s->execute([$username]);$row=$s->fetch(PDO::FETCH_ASSOC);
        return $row && password_verify($recovery,(string)$row['recovery_hash']) ? (int)$row['id'] : 0;
    }
    /** Single-use recovery, rechecks old key under lock. Changes password, TOTP and recovery hash together. */
    public static function complete(PDO $db,string $scope,int $id,string $oldRecovery,string $newPassword,string $newTotp,string $newRecovery):void {
        $table=self::table($scope);
        if($id<1 || strlen($newPassword)<15 || strlen($newPassword)>1024 || !preg_match('/[a-zA-Z]/',$newPassword))throw new RuntimeException('Password non valida');
        if(!preg_match('/^(?:VV-[A-F0-9]{64}|VV-OP-REC-[A-F0-9]{64})$/D',$newRecovery))throw new RuntimeException('Nuova chiave non valida');
        if(!preg_match('/^[A-Z2-7]{32}$/D',$newTotp))throw new RuntimeException('TOTP non valido');
        $pwHash=password_hash($newPassword,PASSWORD_ARGON2ID);
        $recoveryHash=password_hash($newRecovery,PASSWORD_ARGON2ID);
        $encrypted=Core::seal($newTotp,self::appKey());
        $db->beginTransaction();try {
            $q=$db->prepare("SELECT recovery_hash,username FROM $table WHERE id=?".($scope==='operational'?' AND active=1':'').' FOR UPDATE');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row || !password_verify($oldRecovery,(string)$row['recovery_hash']))throw new RuntimeException('Recovery Key non valida o già utilizzata');
            $q=$db->prepare("UPDATE $table SET password_hash=?,totp_encrypted=?,recovery_hash=?,credential_version=credential_version+1 WHERE id=?");
            $q->execute([$pwHash,$encrypted,$recoveryHash,$id]);
            Core::audit($db,$scope.'.full_recovery',(string)$row['username']);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    /** Authenticated key rotation; password+TOTP required and checked again under row lock. */
    public static function rotate(PDO $db,string $scope,int $id,string $password,string $otp,string $newRecovery):void {
        $table=self::table($scope);
        if($id<1||!preg_match('/^(?:VV-[A-F0-9]{64}|VV-OP-REC-[A-F0-9]{64})$/D',$newRecovery))throw new RuntimeException('Dati non validi');
        $db->beginTransaction();try{
            $q=$db->prepare("SELECT username,password_hash,totp_encrypted FROM $table WHERE id=?".($scope==='operational'?' AND active=1':'').' FOR UPDATE');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
            $ok=$row && password_verify($password,(string)$row['password_hash']);
            if($ok)$ok=Core::verifyTotp(Core::unseal((string)$row['totp_encrypted'],self::appKey()),$otp);
            if(!$ok)throw new RuntimeException('Verifica dell’identità non riuscita');
            $q=$db->prepare("UPDATE $table SET recovery_hash=?,credential_version=credential_version+1 WHERE id=?");
            $q->execute([password_hash($newRecovery,PASSWORD_ARGON2ID),$id]);
            Core::audit($db,$scope.'.recovery_rotated',(string)$row['username']);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
