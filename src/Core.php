<?php
declare(strict_types=1);

namespace VenVia;

use PDO;
use RuntimeException;
use Throwable;

final class Core {
    public static function root(): string { return dirname(__DIR__); }
    /** Versione dichiarata del codice distribuito; non attesta un deploy collaudato. */
    public static function version(): string {
        $file = self::root().'/VERSION';
        $value = is_file($file) ? file_get_contents($file) : false;
        if (!is_string($value)) return 'non disponibile';
        $version = trim($value);
        return preg_match('/^\d+\.\d+\.\d+(?:\.\d+)?(?:-[A-Za-z0-9.-]+)?$/D', $version) ? $version : 'non disponibile';
    }
    /** Per i confronti SemVer/SDK esclude l'etichetta di ambiente (es. -DEV). */
    public static function numericVersion(): string {
        $version = self::version();
        return $version === 'non disponibile' ? '0.0.0' : explode('-', $version, 2)[0];
    }

    public static function stateDir(): string { return rtrim((string)(getenv('VENVIA_STATE_DIR') ?: '/srv/venvia-data/system/control'), '/'); }
    public static function configPath(): string { return self::stateDir().'/config.php'; }
    public static function staged(): bool { return is_file(self::configPath()); }
    // Fail closed on inaccessible/inconsistent state: bootstrap is allowed only when no administrator exists.
    public static function installed(): bool {
        if (!self::staged()) return false;
        try {
            $c = self::config();
            $pdo = self::db($c);
            $a = $pdo->query('SELECT COUNT(*) FROM vv_technical_admin WHERE id=1')->fetchColumn();
            $i = $pdo->query('SELECT COUNT(*) FROM vv_installation WHERE id=1')->fetchColumn();
            return (int)$a === 1 && (int)$i === 1;
        } catch (\Throwable $e) {
            // A failed DB connection must not silently re-open setup to strangers.
            return false;
        }
    }
    public static function config(): array {
        if (!self::staged()) { throw new RuntimeException('Configurazione non presente'); }
        $config = require self::configPath();
        if (!is_array($config)) { throw new RuntimeException('Configurazione non valida'); }
        return $config;
    }
    public static function db(?array $c = null): PDO {
        $c ??= self::config();
        $db = $c['database'];
        $host = $db['host'];
        $port = (int)$db['port'];
        $name = $db['name'];
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        return new PDO($dsn, $db['user'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }
    public static function csrf(): string {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }
    public static function checkCsrf(): void {
        if (!isset($_POST['csrf']) || !is_string($_POST['csrf']) || !hash_equals(self::csrf(), $_POST['csrf'])) {
            throw new RuntimeException('Sessione scaduta o richiesta non valida. Ricarica la pagina.');
        }
    }
    public static function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    public static function totpSecret(): string { return self::b32(random_bytes(20)); }
    public static function b32(string $bytes): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        for ($i=0; $i<strlen($bytes); $i++) $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        $out = '';
        for ($i=0; $i<strlen($bits); $i+=5) {
            $chunk = substr($bits,$i,5);
            $out .= $alphabet[bindec(str_pad($chunk,5,'0',STR_PAD_RIGHT))];
        }
        return $out;
    }
    public static function unb32(string $s): string {
        $s = strtoupper(preg_replace('/\s+/', '', $s));
        if (!preg_match('/^[A-Z2-7]+$/', $s)) return '';
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        for ($i=0; $i<strlen($s); $i++) $bits .= str_pad(decbin(strpos($alphabet,$s[$i])),5,'0',STR_PAD_LEFT);
        $out = '';
        for ($i=0; $i+8<=strlen($bits); $i+=8) $out .= chr(bindec(substr($bits,$i,8)));
        return $out;
    }
    public static function totp(string $secret, ?int $timestamp = null): string {
        $timestamp ??= time();
        $counter = intdiv($timestamp,30);
        $binary = pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
        $hash = hash_hmac('sha1', $binary, self::unb32($secret), true);
        $offset = ord($hash[19]) & 15;
        $code = ((ord($hash[$offset]) & 127)<<24) | (ord($hash[$offset+1])<<16) | (ord($hash[$offset+2])<<8) | ord($hash[$offset+3]);
        return str_pad((string)($code % 1000000),6,'0',STR_PAD_LEFT);
    }
    public static function verifyTotp(string $secret,string $code): bool {
        if (!preg_match('/^[0-9]{6}$/D', $code)) return false;
        foreach ([-1,0,1] as $w) if (hash_equals(self::totp($secret,time()+$w*30),$code)) return true;
        return false;
    }
    public static function seal(string $secret, string $key): string {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce.sodium_crypto_secretbox($secret,$nonce,$key));
    }
    public static function unseal(string $value,string $key): string {
        $raw = base64_decode($value,true);
        if ($raw === false || strlen($raw)<SODIUM_CRYPTO_SECRETBOX_NONCEBYTES+SODIUM_CRYPTO_SECRETBOX_MACBYTES) throw new RuntimeException('Dato cifrato non valido');
        $nonce = substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$nonce,$key);
        if ($plaintext === false) throw new RuntimeException('Impossibile decifrare il dato');
        return $plaintext;
    }
    public static function audit(PDO $pdo,string $action,string $actor): void {
        $salt = self::installed() ? self::config()['ip_salt'] : 'bootstrap';
        $ip = hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown', $salt);
        $stmt=$pdo->prepare('INSERT INTO vv_audit (action,actor,ip_hash) VALUES (?,?,?)');
        $stmt->execute([$action,$actor,$ip]);
    }
    public static function requireLogin(): void {
        if (($_SESSION['vv_authenticated'] ?? false) !== true) {
            header('Location: /?page=login',true,303); exit;
        }
        // Credential version invalidates ALL previous sessions after recovery.
        try {
            $db=self::db();
            $version=$db->query('SELECT credential_version FROM vv_technical_admin WHERE id=1')->fetchColumn();
            if ($version === false || (int)$version !== (int)($_SESSION['vv_credential_version']??-1)) {
                unset($_SESSION['vv_authenticated'],$_SESSION['vv_username'],$_SESSION['vv_auth_at'],$_SESSION['vv_credential_version']);
                header('Location: /?page=login',true,303); exit;
            }
        } catch (\Throwable $e) {
            error_log('VenVia login session validation failed');
            http_response_code(503); exit('Servizio temporaneamente non disponibile');
        }
    }
    public static function html(string $title,string $body): void {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        $t=self::e($title);
        $authenticated=(($_SESSION['vv_authenticated']??false)===true);
        $pageClass=$authenticated?'app-auth':'app-setup';
        echo '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<meta name="color-scheme" content="light dark"><title>'.$t.' · VenVia</title><link rel="icon" href="/assets/favicon.ico"><link rel="stylesheet" href="/assets/venvia-tokens.css?v=021"><link rel="stylesheet" href="/assets/style.css?v=0211"></head>';
        echo '<body class="'.$pageClass.'"><div class="app-layout">';
        if ($authenticated) {
            echo '<aside class="sidebar" id="sidebar" aria-label="Navigazione tecnica"><div class="sidebar-brand"><a href="/" class="brand-panel" aria-label="VenVia Control Center"><img src="/assets/venvia-logo.png" alt="VénVia"></a><span class="brand-sub">CONTROL CENTER</span></div><div class="sidebar-caption">PANORAMICA TECNICA</div>';
            echo '<nav class="side-nav" aria-label="Sezioni del Control Center"><a class="nav-link" href="/#overview">◫ <span>Dashboard</span></a><a class="nav-link" href="/#system">▤ <span>Sistema e servizi</span></a><a class="nav-link" href="/?page=plugins">▦ <span>Plugin</span></a><a class="nav-link" href="/#maintenance">⬡ <span>Aggiornamenti</span></a><a class="nav-link" href="/#maintenance">▥ <span>Backup e ripristino</span></a><a class="nav-link" href="/#maintenance">⇄ <span>Migrazione</span></a><a class="nav-link" href="/#maintenance">◇ <span>Staging</span></a><a class="nav-link" href="/#security">♢ <span>Sicurezza e certificati</span></a><a class="nav-link" href="/#maintenance">≡ <span>Log e diagnostica</span></a></nav>';
            echo '<div class="sidebar-bottom"><div class="avatar">AT</div><div><strong>Amministratore Tecnico</strong><small>Ambiente DEV · LAB</small></div></div></aside>';
        }
        echo '<div class="main-shell"><header class="topbar">';
        if ($authenticated) echo '<button type="button" id="menuToggle" class="menu-toggle secondary" aria-label="Apri navigazione" aria-expanded="false">☰</button>';
        else echo '<span class="brand-panel"><img src="/assets/venvia-logo.png" alt="VénVia"></span>';
        echo '<div class="topbar-title"><strong>Control Center</strong><small>Ambiente DEV · Core '.self::e(self::version()).'</small></div><span class="topbar-spacer"></span><button type="button" class="theme-toggle secondary" id="themeToggle" aria-label="Cambia tema chiaro o scuro">Tema chiaro/scuro</button></header>';
        echo '<main class="content-area" id="mainContent">'.$body.'</main><footer class="app-footer">VenVia Control Center · Core '.self::e(self::version()).' (codice dichiarato) · Funzioni non collaudate: non utilizzare in produzione</footer></div></div>';
        echo '<div class="nav-overlay" id="navOverlay" hidden></div><script src="/assets/theme.js?v=021" defer></script></body></html>';
    }
    public static function formCsrf(): string { return '<input type="hidden" name="csrf" value="'.self::e(self::csrf()).'">'; }
    public static function error(string $s): string { return '<div class="alert">'.self::e($s).'</div>'; }
    public static function validateDb(array $v): array {
        $host=trim((string)($v['db_host']??'')); $port=(string)($v['db_port']??'3306');
        $name=trim((string)($v['db_name']??'')); $user=trim((string)($v['db_user']??''));
        $password=(string)($v['db_password']??'');
        if (!preg_match('/^[a-zA-Z0-9_.-]{1,253}$/D',$host) || !ctype_digit($port) || (int)$port<1 || (int)$port>65535 || !preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$name) || $user==='') {
            throw new RuntimeException('Parametri database non validi');
        }
        return ['host'=>$host,'port'=>(int)$port,'name'=>$name,'user'=>$user,'password'=>$password];
    }
}
