<?php
declare(strict_types=1);
namespace VenVia;
use RuntimeException;
use Throwable;

final class Bootstrap {
    public static function prepare(array $post): void {
        $meta = require Core::root().'/private/bootstrap.php';
        $expected = $meta['setup_hash'];
        if (!is_string($post['setup_code']??null) || !hash_equals($expected,hash('sha256',trim($post['setup_code'])))) {
            throw new RuntimeException('Codice di attivazione non valido');
        }
        $db=Core::validateDb($post);
        if (Core::staged()) {
            $old=Core::config()['database'];
            if (!hash_equals(hash('sha256',json_encode($old)),hash('sha256',json_encode($db)))) {
                throw new RuntimeException('È presente un setup incompleto collegato a un diverso database. Non è consentito cambiare database durante il recupero.');
            }
        }
        $pdo=Core::db(['database'=>$db]);
        $existing=$pdo->query("SHOW TABLES LIKE 'vv_technical_admin'")->fetchColumn();
        if ($existing && $pdo->query('SELECT COUNT(*) FROM vv_technical_admin')->fetchColumn()>0) {
            throw new RuntimeException('Il database contiene già un amministratore tecnico: non è consentito inizializzarlo nuovamente.');
        }
        $username=trim((string)($post['username']??''));
        $email=trim((string)($post['email']??''));
        $pw=(string)($post['password']??'');
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{3,63}$/D',$username)) throw new RuntimeException('Username: 4-64 caratteri, lettere, numeri, punti, trattini o underscore.');
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254) throw new RuntimeException('Indirizzo email non valido');
        if (strlen($pw)<15 || strlen($pw)>1024 || !preg_match('/[a-zA-Z]/',$pw)) throw new RuntimeException('Password: almeno 15 caratteri, massimo 1024, includere lettere.');
        if (!hash_equals($pw,(string)($post['confirm_password']??''))) throw new RuntimeException('Le password non coincidono');
        if (!defined('PASSWORD_ARGON2ID')) throw new RuntimeException('Argon2id non disponibile su PHP');
        $_SESSION['pending_setup']=[
            'db'=>$db,'username'=>$username,'email'=>$email,
            'password_hash'=>password_hash($pw,PASSWORD_ARGON2ID),
            'totp'=>Core::totpSecret(),
            'recovery'=>'VV-'.strtoupper(bin2hex(random_bytes(32))),
            'created'=>time(),
        ];
        session_regenerate_id(true);
    }
    public static function complete(string $code,string $ack): void {
        $pending=$_SESSION['pending_setup']??null;
        if (!$pending || time()-$pending['created']>900) throw new RuntimeException('Sessione di attivazione scaduta: ricomincia.');
        if ($ack!=='1') throw new RuntimeException('Conferma di aver conservato offline la Recovery Key');
        if (!Core::verifyTotp($pending['totp'],$code)) throw new RuntimeException('Codice MFA non valido');
        $dir=Core::stateDir();
        if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('Area dati protetta non predisposta. Interrompi e verifica i permessi.');
        $lock=fopen($dir.'/.setup.lock','c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Installazione già in corso');
        try {
            if (Core::installed()) throw new RuntimeException('VenVia è già installato');
            $pdo=Core::db(['database'=>$pending['db']]);
            $schema=file_get_contents(Core::root().'/database/schema.sql');
            if ($schema===false) throw new RuntimeException('Schema SQL non trovato');
            foreach (array_filter(array_map('trim',explode(';',$schema))) as $sql) $pdo->exec($sql);
            // An already-present technical administrator can never be replaced during setup.
            if ((int)$pdo->query('SELECT COUNT(*) FROM vv_technical_admin')->fetchColumn() !== 0 ||
                (int)$pdo->query('SELECT COUNT(*) FROM vv_installation')->fetchColumn() !== 0) {
                throw new RuntimeException('Installazione parziale o esistente: operazione bloccata. Non cancellare i dati; avvia una procedura di recupero verificata.');
            }
            // Write protected configuration BEFORE committing database identities. If interrupted,
            // the same code and DB details may retry safely without creating another identity.
            if (Core::staged()) {
                $config=Core::config();
            } else {
                $config=[
                    'database'=>$pending['db'],
                    'installation_id'=>self::uuid(),
                    'app_key'=>base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
                    'ip_salt'=>bin2hex(random_bytes(32)),
                ];
                $file=Core::configPath();
                $tmp=$dir.'/.config.'.bin2hex(random_bytes(6));
                $data="<?php\ndeclare(strict_types=1);\nreturn ".var_export($config,true).";\n";
                if (file_put_contents($tmp,$data,LOCK_EX)===false) throw new RuntimeException('Impossibile scrivere la configurazione protetta');
                if (!chmod($tmp,0600) || !rename($tmp,$file)) { @unlink($tmp); throw new RuntimeException('Impossibile attivare la configurazione'); }
            }
            $key=base64_decode($config['app_key'],true);
            if ($key===false || strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new RuntimeException('Chiave applicativa non valida');
            $pdo->beginTransaction();
            try {
                $stmt=$pdo->prepare('INSERT INTO vv_installation (id,installation_id,version) VALUES (1,?,?)');
                $stmt->execute([$config['installation_id'],'0.1.3']);
                $stmt=$pdo->prepare('INSERT INTO vv_technical_admin (id,username,email,password_hash,totp_encrypted,recovery_hash) VALUES (1,?,?,?,?,?)');
                $stmt->execute([
                    $pending['username'],$pending['email'],$pending['password_hash'],
                    Core::seal($pending['totp'],$key),password_hash($pending['recovery'],PASSWORD_ARGON2ID),
                ]);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            unset($_SESSION['pending_setup']);
            session_regenerate_id(true);
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    private static function uuid(): string {
        $b=random_bytes(16);
        $b[6]=chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8]=chr((ord($b[8]) & 0x3f) | 0x80);
        $h=bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
}
