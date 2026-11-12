<?php
declare(strict_types=1);
namespace VenVia;
use PDO;
use RuntimeException;

final class Operational {
    public static function schemaAvailable(PDO $db): bool {
        $s=$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('vv_operational_users','vv_operational_invites','vv_plugin_registry','vv_core_migrations')");
        return (int)$s->fetchColumn() === 4;
    }
    public static function migrate(PDO $db): void {
        $file=Core::root().'/database/migrations/020_operational.sql';
        $sql=file_get_contents($file);
        if ($sql===false) throw new RuntimeException('Migrazione non disponibile');
        // CREATE TABLE IF NOT EXISTS only, pinned manifest and no dynamic user SQL.
        foreach (explode(';',$sql) as $stmt) {
            $stmt=trim(preg_replace('/^--[^\n]*$/m','',$stmt));
            if ($stmt==='') continue;
            if (!preg_match('/^CREATE TABLE IF NOT EXISTS vv_[a-z_]+\s*\(/i',$stmt)) throw new RuntimeException('Istruzione di migrazione non consentita');
            $db->exec($stmt);
        }
        if (!self::schemaAvailable($db)) throw new RuntimeException('Migrazione incompleta: verifica database');
        $s=$db->prepare('INSERT INTO vv_core_migrations (migration_id,checksum_sha256) VALUES (?,?) ON DUPLICATE KEY UPDATE migration_id=migration_id');
        $s->execute(['020_operational',hash('sha256',$sql)]);
    }
    public static function createFirstInvitation(PDO $db,string $creator): string {
        if (!self::schemaAvailable($db)) throw new RuntimeException('Prima applica la migrazione gestionale');
        if ((int)$db->query("SELECT COUNT(*) FROM vv_operational_users WHERE role='administrator'")->fetchColumn()>0)
            throw new RuntimeException('Amministratore operativo già esistente');
        $token='VV-OP-'.strtoupper(bin2hex(random_bytes(32)));
        $db->exec('DELETE FROM vv_operational_invites WHERE consumed_at IS NULL');
        $s=$db->prepare("INSERT INTO vv_operational_invites(token_hash,role,created_by,expires_at) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL 20 MINUTE))");
        $s->execute([hash('sha256',$token),'administrator',$creator]);
        return $token;
    }
    public static function validInvite(PDO $db,string $token): ?array {
        if (!preg_match('/^VV-OP-[A-F0-9]{64}$/D',$token)) return null;
        $s=$db->prepare('SELECT id,role FROM vv_operational_invites WHERE token_hash=? AND consumed_at IS NULL AND expires_at>NOW()');
        $s->execute([hash('sha256',$token)]);
        return $s->fetch() ?: null;
    }
}
