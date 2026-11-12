<?php
declare(strict_types=1);
namespace VenVia;
use PDO;
use RuntimeException;

/** Successione amministratore gestionale. Non sostituisce l’autenticazione o l’autorizzazione tecnica. */
final class AdminSuccession {
    public static function issue(PDO $db,string $technicalUsername):string {
        $token='VV-OP-'.strtoupper(bin2hex(random_bytes(32)));
        $db->beginTransaction();
        try {
            $users=$db->query("SELECT id FROM vv_operational_users WHERE role='administrator' AND active=1 FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
            if(count($users)!==1)throw new RuntimeException('È richiesto esattamente un amministratore gestionale attivo');
            $db->exec("UPDATE vv_admin_succession SET status='expired' WHERE status='pending' AND expires_at<=NOW()");
            if((int)$db->query("SELECT COUNT(*) FROM vv_admin_succession WHERE status='pending'")->fetchColumn()>0)
                throw new RuntimeException('Esiste già una sostituzione in attesa; attendere la scadenza o annullarla con procedura autorizzata');
            $s=$db->prepare("INSERT INTO vv_operational_invites(token_hash,role,created_by,expires_at) VALUES (?,'administrator',?,DATE_ADD(NOW(), INTERVAL 20 MINUTE))");
            $s->execute([hash('sha256',$token),$technicalUsername]);
            $inviteId=(int)$db->lastInsertId();
            $s=$db->prepare("INSERT INTO vv_admin_succession(invite_id,old_admin_id,created_by,expires_at) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL 20 MINUTE))");
            $s->execute([$inviteId,(int)$users[0],$technicalUsername]);
            Core::audit($db,'operative_admin.succession_requested',$technicalUsername);
            $db->commit();
            return $token;
        } catch(\Throwable $e) {if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function pendingForInvite(PDO $db,int $inviteId):?array {
        $s=$db->prepare("SELECT id,old_admin_id FROM vv_admin_succession WHERE invite_id=? AND status='pending' AND expires_at>NOW()");
        $s->execute([$inviteId]);return $s->fetch(PDO::FETCH_ASSOC)?:null;
    }
    /** To be called inside enrollment transaction before creating successor. */
    public static function lockForCompletion(PDO $db,int $requestId,int $inviteId):array {
        if(!$db->inTransaction())throw new RuntimeException('Transazione richiesta');
        $s=$db->prepare("SELECT id,old_admin_id FROM vv_admin_succession WHERE id=? AND invite_id=? AND status='pending' AND expires_at>NOW() FOR UPDATE");
        $s->execute([$requestId,$inviteId]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('Invito di sostituzione scaduto o già utilizzato');
        $s=$db->prepare("SELECT id FROM vv_operational_users WHERE id=? AND role='administrator' AND active=1 FOR UPDATE");
        $s->execute([(int)$row['old_admin_id']]);
        if(!$s->fetchColumn())throw new RuntimeException('Amministratore precedente non più attivo');
        $s=$db->query("SELECT COUNT(*) FROM vv_operational_users WHERE role='administrator' AND active=1");
        if((int)$s->fetchColumn()!==1)throw new RuntimeException('Stato amministratori incoerente');
        return $row;
    }
    /** Atomically disable predecessor, revoke sessions via credential_version, preserve id and audit. */
    public static function finishWithinTransaction(PDO $db,int $requestId,int $oldId,int $newId):void {
        if(!$db->inTransaction()||$oldId<1||$newId<1||$oldId===$newId)throw new RuntimeException('Stato successione non valido');
        $s=$db->prepare("UPDATE vv_operational_users SET active=0,credential_version=credential_version+1 WHERE id=? AND role='administrator' AND active=1");
        $s->execute([$oldId]);if($s->rowCount()!==1)throw new RuntimeException('Revoca precedente non riuscita');
        $s=$db->prepare("UPDATE vv_admin_succession SET status='completed',new_admin_id=?,completed_at=NOW() WHERE id=? AND old_admin_id=? AND status='pending'");
        $s->execute([$newId,$requestId,$oldId]);if($s->rowCount()!==1)throw new RuntimeException('Completamento successione non riuscito');
        Core::audit($db,'operative_admin.succession_completed',(string)$oldId.'>'.$newId);
    }
}
