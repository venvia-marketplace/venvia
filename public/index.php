<?php
declare(strict_types=1);
use VenVia\Core;
use VenVia\Bootstrap;
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bootstrap.php';
require dirname(__DIR__).'/src/Operational.php';
require dirname(__DIR__).'/src/Environment.php';
require_once dirname(__DIR__).'/src/InterfaceAPI.php';
require_once dirname(__DIR__).'/src/PluginManager.php';
require_once dirname(__DIR__).'/src/MailService.php';
require_once dirname(__DIR__).'/src/EmailRecovery.php';
require_once dirname(__DIR__).'/src/IdentityRecovery.php';
require_once dirname(__DIR__).'/src/AdminSuccession.php';

// HTTPS mandatory, including behind trusted TLS terminating proxy (proxy support NOT enabled yet).
if (($_SERVER['HTTPS']??'') !== 'on') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('VenVia richiede HTTPS.');
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; style-src \'self\'; script-src \'self\'; form-action \'self\'; base-uri \'none\'; frame-ancestors \'none\'');
header('Strict-Transport-Security: max-age=31536000');
ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_secure','1');
session_name('VENVIA_CONTROL');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();

function input(string $label,string $name,string $type='text',string $extra=''): string {
    return '<label>'.Core::e($label).'<input name="'.Core::e($name).'" type="'.Core::e($type).'" '.$extra.' required></label>';
}
function err(Throwable $e): string { return Core::error($e->getMessage()); }
function notice(string $message): string { return '<p class="muted">'.Core::e($message).'</p>'; }
function attempt(PDO $db,string $ip,bool $success): void {
    if ($success) {
        $s=$db->prepare('DELETE FROM vv_login_attempt WHERE ip_hash=?'); $s->execute([$ip]); return;
    }
    $s=$db->prepare('INSERT INTO vv_login_attempt (ip_hash,attempts,last_attempt) VALUES (?,1,NOW()) ON DUPLICATE KEY UPDATE attempts=IF(last_attempt < NOW()-INTERVAL 15 MINUTE,1,attempts+1),last_attempt=NOW()');
    $s->execute([$ip]);
}
function locked(PDO $db,string $ip): bool {
    $s=$db->prepare('SELECT attempts FROM vv_login_attempt WHERE ip_hash=? AND last_attempt > NOW()-INTERVAL 15 MINUTE');
    $s->execute([$ip]); return (int)$s->fetchColumn()>=8;
}

function lockedRecovery(PDO $db,string $ip): bool {
    $s=$db->prepare('SELECT attempts FROM vv_recovery_attempt WHERE ip_hash=? AND last_attempt > NOW()-INTERVAL 15 MINUTE');
    $s->execute([$ip]); return (int)$s->fetchColumn()>=5;
}
function recordRecoveryAttempt(PDO $db,string $ip,bool $success): void {
    if ($success) { $s=$db->prepare('DELETE FROM vv_recovery_attempt WHERE ip_hash=?'); $s->execute([$ip]); return; }
    $s=$db->prepare('INSERT INTO vv_recovery_attempt (ip_hash,attempts,last_attempt) VALUES (?,1,NOW()) ON DUPLICATE KEY UPDATE attempts=IF(last_attempt < NOW()-INTERVAL 15 MINUTE,1,attempts+1),last_attempt=NOW()');
    $s->execute([$ip]);
}

// Never reopen bootstrap if configuration exists but DB is inaccessible/inconsistent.
// Interrupted empty DB installs are recoverable via the same provisioning code.
if (Core::staged() && !Core::installed()) {
    try {
        $db = Core::db();
        if ((int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'vv_technical_admin'")->fetchColumn() > 0) {
            if ((int)$db->query('SELECT COUNT(*) FROM vv_technical_admin')->fetchColumn() > 0) {
                http_response_code(503);
                exit('Stato di installazione non coerente: intervento tecnico richiesto.');
            }
        }
    } catch (\Throwable $e) {
        error_log('VenVia bootstrap state check failed: '.$e->getMessage());
        http_response_code(503);
        exit('Database non disponibile durante il recupero setup.');
    }
}
$installed=Core::installed();
$page=(string)($_GET['page']??($installed?'dashboard':'setup'));
if (!$installed && !in_array($page,['setup','mfa'],true)) $page='setup';
if ($installed && in_array($page,['setup','mfa'],true)) $page='login';
$body='';
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') Core::checkCsrf();
    if ($page==='setup') {
        // Keep every preliminary step in a short-lived HTTPS session. No DB rows
        // or admin identity are created until successful MFA confirmation.
        $setup = $_SESSION['vv_setup'] ?? ['step'=>1,'created'=>time()];
        if (time() - (int)$setup['created'] > 900) { unset($_SESSION['vv_setup'],$_SESSION['pending_setup']); $setup=['step'=>1,'created'=>time()]; }
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            $action=(string)($_POST['setup_step']??'');
            if ($action==='authorization') {
                $meta=require Core::root().'/private/bootstrap.php';
                $attempts=(int)($_SESSION['vv_setup_failures']??0);
                if ($attempts>=5) throw new RuntimeException('Troppi tentativi di attivazione. Chiudi la sessione e richiedi assistenza.');
                $candidate=trim((string)($_POST['setup_code']??''));
                if ($candidate==='' || !hash_equals($meta['setup_hash'],hash('sha256',$candidate))) {
                    $_SESSION['vv_setup_failures']=$attempts+1;
                    throw new RuntimeException('Codice iniziale non valido');
                }
                $_SESSION['vv_setup_failures']=0;
                $setup['code']=$candidate;
                $setup['step']=2;
            } elseif ($action==='database' && ($setup['step']??0)===2) {
                $dbParams=Core::validateDb($_POST);
                $pdo=Core::db(['database'=>$dbParams]);
                $tables=$pdo->query("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema=DATABASE() AND TABLE_NAME IN ('vv_installation','vv_technical_admin')")->fetchAll(PDO::FETCH_COLUMN);
                if (count($tables)>0 && !Core::staged()) throw new RuntimeException('Il database non risulta vuoto per VenVia. Procedura interrotta per proteggere i dati.');
                if (Core::staged() && Core::config()['database'] !== $dbParams) throw new RuntimeException('Configurazione in sospeso: collegare il database già associato alla precedente installazione.');
                $setup['db']=$dbParams;
                $setup['step']=3;
            } elseif ($action==='administrator' && ($setup['step']??0)===3) {
                $combined=array_merge($_POST,[
                  'setup_code'=>$setup['code'],
                  'db_host'=>$setup['db']['host'], 'db_port'=>(string)$setup['db']['port'],
                  'db_name'=>$setup['db']['name'], 'db_user'=>$setup['db']['user'],
                  'db_password'=>$setup['db']['password'],
                ]);
                Bootstrap::prepare($combined);
                unset($_SESSION['vv_setup']);
                header('Location: /?page=mfa',true,303); exit;
            } else {
                throw new RuntimeException('Passaggio non valido, ricarica la pagina.');
            }
            $_SESSION['vv_setup']=$setup;
            session_regenerate_id(true);
            header('Location: /?page=setup',true,303); exit;
        }
        $step=(int)$setup['step'];
        $body='<section class="hero"><div class="eyebrow">CONFIGURAZIONE INIZIALE · PASSAGGIO '. $step .' DI 4</div><h1>Benvenuto in VenVia</h1><p>Installer web del Control Center. Nessun account amministrativo predefinito.</p></section>';
        $body.='<nav class="steps" aria-label="Avanzamento installazione"><span class="'.($step===1?'selected':'').'">1 · Autorizzazione</span><span class="'.($step===2?'selected':'').'">2 · Database</span><span class="'.($step===3?'selected':'').'">3 · Amministratore</span><span>4 · MFA</span></nav>';
        if ($step===1) {
            $body.='<form method="post">'.Core::formCsrf().'<input type="hidden" name="setup_step" value="authorization"><h2>Autorizzazione dell’installazione</h2><p class="muted">Utilizza il codice iniziale consegnato separatamente e custodito offline. La versione LAB non è utilizzabile su Internet pubblico.</p>'.input('Codice di attivazione','setup_code','password','autocomplete="off"').'<button type="submit">Verifica codice e continua</button></form>';
        } elseif ($step===2) {
            $body.='<form method="post">'.Core::formCsrf().'<input type="hidden" name="setup_step" value="database"><h2>Collegamento a MariaDB</h2>'.input('Host database','db_host','text','value="127.0.0.1"').input('Porta','db_port','number','value="3306"').input('Nome database vuoto','db_name').input('Utente database','db_user').input('Password database','db_password','password','autocomplete="new-password"').'<button type="submit">Verifica connessione</button></form>';
        } else {
            $body.='<form method="post">'.Core::formCsrf().'<input type="hidden" name="setup_step" value="administrator"><h2>Amministratore Tecnico unico</h2><p class="muted">L’identità viene registrata soltanto dopo verifica MFA. L’email non è ancora verificata in questa release LAB.</p>'.input('Nome utente','username','text','autocomplete="username"').input('Email di recupero','email','email').input('Password (minimo 15 caratteri)','password','password','autocomplete="new-password" minlength="15"').input('Conferma password','confirm_password','password','autocomplete="new-password" minlength="15"').'<button type="submit">Continua alla verifica MFA</button></form>';
        }
        $body.=notice('Core '.Core::e(Core::version()).' (codice distribuito) · Ambiente DEV. Le funzioni di backup, ripristino e migrazione automatica non sono ancora collaudate.');
        Core::html('Prima configurazione',$body); exit;
    }
    if ($page==='mfa') {
        $p=$_SESSION['pending_setup']??null;
        if (!$p || time()-$p['created']>900) { header('Location: /?page=setup',true,303); exit; }
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            if (!hash_equals(substr((string)$_SESSION['pending_setup']['recovery'],-8),strtoupper(trim((string)($_POST['recovery_confirm']??''))))) throw new RuntimeException('Verifica Recovery Key non riuscita');
            Bootstrap::complete((string)($_POST['totp']??''),(string)($_POST['saved']??''));
            header('Location: /?page=login&installed=1',true,303); exit;
        }
        $url='otpauth://totp/VenVia:'.rawurlencode($p['username']).'?secret='.rawurlencode($p['totp']).'&issuer=VenVia&algorithm=SHA1&digits=6&period=30';
        $body='<section class="hero"><div class="eyebrow">PASSAGGIO 4 DI 4</div><h1>Proteggi l’account tecnico</h1><p>Configura un’app di autenticazione TOTP con la chiave seguente. Nessun account viene salvato finché il codice non viene verificato.</p></section>';
        $body.='<section class="totp-enroll"><h2>Scansiona il QR code</h2><p>Apri l’app di autenticazione e aggiungi un account tramite fotocamera. Il QR è generato localmente nel browser.</p><div class="totp-qr" data-otpauth="'.Core::e($url).'">Caricamento QR…</div><p class="muted">Non fotografare, condividere o inviare il QR code.</p></section>';
        $body.='<div class="secrets"><div><strong>Chiave MFA (alternativa manuale)</strong><code>'.Core::e($p['totp']).'</code></div><div><strong>Recovery Key — custodisci offline</strong><code id="recovery">'.Core::e($p['recovery']).'</code><button type="button" id="saveRecovery" class="secondary">Scarica Recovery Key</button></div></div>';
        $body.='<details><summary>Configurazione manuale dell’autenticatore</summary><p>URI TOTP (da copiare solo su un dispositivo fidato):</p><code>'.Core::e($url).'</code></details>';
        $body.='<form method="post">'.Core::formCsrf().input('Codice a 6 cifre generato dall’app','totp','text','inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"').'<label class="check"><input type="checkbox" name="saved" value="1" required> Confermo di aver salvato la Recovery Key offline</label>'.input('Ultimi 8 caratteri della Recovery Key (verifica salvataggio)','recovery_confirm','text','autocomplete="off" minlength="8" maxlength="8"').'<button>Completa prima installazione</button></form>';
        $body.='<script src="/assets/totp-qr.js?v=020" defer></script><script src="/assets/recovery.js" defer></script>';
        Core::html('Attivazione MFA',$body); exit;
    }
    if ($page==='logout') {
        if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); exit('Metodo non consentito'); }
        $_SESSION=[]; session_regenerate_id(true);
        header('Location: /?page=login',true,303); exit;
    }
    if ($page==='reset-password' || $page==='verify-email') {
        $db=Core::db();$token=(string)($_POST['token']??$_GET['token']??'');
        if($_SERVER['REQUEST_METHOD']==='POST') {
            if($page==='verify-email'){
                \VenVia\EmailRecovery::apply($db,'technical','verify',$token);
                Core::html('Email verificata','<div class="success">Indirizzo email verificato.</div><p><a href="/?page=login">Accedi</a></p>');exit;
            }
            $p=(string)($_POST['password']??'');$c=(string)($_POST['confirm_password']??'');
            if($p!==$c)throw new RuntimeException('Le password non coincidono');
            \VenVia\EmailRecovery::apply($db,'technical','password',$token,$p,(string)($_POST['totp']??''));
            Core::html('Password aggiornata','<div class="success">Password aggiornata. Accedi con la nuova password e il codice MFA.</div><p><a href="/?page=login">Accedi</a></p>');exit;
        }
        $body='<section class="hero"><h1>'.($page==='verify-email'?'Verifica email':'Ripristina password').'</h1></section>';
        $body.='<form method="post">'.Core::formCsrf().'<input type="hidden" name="token" value="'.Core::e($token).'">';
        if($page==='reset-password')$body.=input('Nuova password (almeno 15 caratteri)','password','password','autocomplete="new-password" minlength="15" maxlength="256"').input('Conferma nuova password','confirm_password','password','autocomplete="new-password" minlength="15" maxlength="256"').input('Codice MFA attuale','totp','text','inputmode="numeric" pattern="[0-9]{6}" maxlength="6"');
        $body.='<button type="submit">Conferma</button></form>';
        Core::html($page==='verify-email'?'Verifica email':'Nuova password',$body);exit;
    }
    if ($page==='forgot-password') {
        $body='<section class="hero"><h1>Recupera la password</h1><p>Disponibile solo per indirizzi email verificati. Il codice MFA rimane obbligatorio.</p></section>';
        if($_SERVER['REQUEST_METHOD']==='POST'){
            try {\VenVia\EmailRecovery::request(Core::db(),'technical',trim((string)($_POST['username']??'')),(string)($_SERVER['REMOTE_ADDR']??''));}catch(\Throwable $e){error_log('VenVia password request delivery failed (redacted)');}
            $body.='<div class="success">Se l’account è idoneo, riceverai un’email con le istruzioni.</div>';
        }
        $body.='<form method="post">'.Core::formCsrf().input('Nome utente tecnico','username','text','autocomplete="username"').'<button type="submit">Invia link di recupero</button></form><p><a href="/?page=recover">Hai perso anche l’MFA? Utilizza la Recovery Key.</a></p>';
        Core::html('Recupero password',$body);exit;
    }
    if ($page==='recover') {
        $db=Core::db();
        $stage=$_SESSION['vv_recovery']??null;
        if ($stage && (time()-(int)$stage['created']>600)) {
            unset($_SESSION['vv_recovery']); $stage=null;
        }
        $ip=hash_hmac('sha256','recovery:'.($_SERVER['REMOTE_ADDR']??'unknown'),Core::config()['ip_salt']);
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            $action=(string)($_POST['recovery_action']??'');
            if ($action==='verify' && !$stage) {
                if (lockedRecovery($db,$ip)) throw new RuntimeException('Troppi tentativi. Riprova più tardi.');
                $admin=$db->query('SELECT username,recovery_hash FROM vv_technical_admin WHERE id=1')->fetch();
                $user=trim((string)($_POST['username']??''));
                $key=strtoupper(trim((string)($_POST['recovery_key']??'')));
                $valid=$admin && hash_equals((string)$admin['username'],$user)
                    && preg_match('/^VV-[A-F0-9]{64}$/D',$key) && password_verify($key,(string)$admin['recovery_hash']);
                recordRecoveryAttempt($db,$ip,(bool)$valid);
                if (!$valid) throw new RuntimeException('Dati di recupero non validi');
                $_SESSION['vv_recovery']=[
                    'old_key'=>$key, 'created'=>time(),
                    'totp'=>Core::totpSecret(),
                    'new_recovery'=>'VV-'.strtoupper(bin2hex(random_bytes(32))),
                ];
                session_regenerate_id(true);
                header('Location: /?page=recover',true,303); exit;
            }
            if ($action==='complete' && $stage) {
                $pw=(string)($_POST['password']??'');
                $confirmation=(string)($_POST['confirm_password']??'');
                if (strlen($pw)<15 || strlen($pw)>1024 || !preg_match('/[a-zA-Z]/',$pw)) throw new RuntimeException('Password: almeno 15 caratteri e una lettera');
                if (!hash_equals($pw,$confirmation)) throw new RuntimeException('Le password non coincidono');
                if (!hash_equals(substr($stage['new_recovery'],-8),strtoupper(trim((string)($_POST['recovery_confirm']??''))))) throw new RuntimeException('Conferma Recovery Key non valida');
                if ((string)($_POST['saved']??'')!=='1') throw new RuntimeException('Conferma il salvataggio offline della Recovery Key');
                if (!Core::verifyTotp($stage['totp'],(string)($_POST['totp']??''))) throw new RuntimeException('Codice MFA non valido');
                $key=base64_decode(Core::config()['app_key'],true);
                if ($key===false || strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new RuntimeException('Chiave applicativa non valida');
                $newPassHash=password_hash($pw,PASSWORD_ARGON2ID);
                $newRecoveryHash=password_hash($stage['new_recovery'],PASSWORD_ARGON2ID);
                $newTotp=Core::seal($stage['totp'],$key);
                $db->beginTransaction();
                try {
                    $old=$db->query('SELECT username,recovery_hash FROM vv_technical_admin WHERE id=1 FOR UPDATE')->fetch();
                    if (!$old || !password_verify($stage['old_key'],(string)$old['recovery_hash'])) throw new RuntimeException('Recovery Key già utilizzata o non valida');
                    $stmt=$db->prepare('UPDATE vv_technical_admin SET password_hash=?,totp_encrypted=?,recovery_hash=?,credential_version=credential_version+1 WHERE id=1');
                    $stmt->execute([$newPassHash,$newTotp,$newRecoveryHash]);
                    Core::audit($db,'technical_admin.recovery_completed',(string)$old['username']);
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    throw $e;
                }
                // Invalidate current session; credential_version invalidates other sessions.
                $_SESSION=[];
                session_regenerate_id(true);
                header('Location: /?page=login&recovered=1',true,303); exit;
            }
            throw new RuntimeException('Operazione di recupero non valida');
        }
        $body='<section class="hero"><div class="eyebrow">RECUPERO DI EMERGENZA · LAB</div><h1>Recupero Amministratore Tecnico</h1><p>La Recovery Key è monouso. Non verrà creato un secondo account. Email non ancora verificata: non è un fattore di recupero in questa versione.</p></section>';
        if (!$stage) {
            $body.='<form method="post">'.Core::formCsrf().'<input type="hidden" name="recovery_action" value="verify"><h2>Verifica della Recovery Key offline</h2>'.input('Nome utente tecnico','username','text','autocomplete="username"').input('Recovery Key completa','recovery_key','password','autocomplete="off"').'<button type="submit">Verifica e continua</button></form>';
        } else {
            $recoveryUsername=(string)$db->query('SELECT username FROM vv_technical_admin WHERE id=1')->fetchColumn();
            $recoveryUrl='otpauth://totp/VenVia:'.rawurlencode($recoveryUsername).'?secret='.rawurlencode($stage['totp']).'&issuer=VenVia&algorithm=SHA1&digits=6&period=30';
            $body.='<section class="totp-enroll"><h2>Scansiona il nuovo QR code</h2><p>Configura il nuovo autenticatore. La vecchia chiave MFA verrà sostituita al completamento.</p><div class="totp-qr" data-otpauth="'.Core::e($recoveryUrl).'">Caricamento QR…</div></section>';
            $body.='<div class="secrets"><div><strong>Nuova chiave MFA (alternativa manuale)</strong><code>'.Core::e($stage['totp']).'</code></div><div><strong>Nuova Recovery Key — sostituisce la precedente</strong><code id="recovery">'.Core::e($stage['new_recovery']).'</code><button type="button" id="saveRecovery" class="secondary">Scarica Recovery Key</button></div></div>';
            $body.='<link rel="stylesheet" href="/assets/venvia-recovery-checkbox-0.2.4.3-ui1.css">';
            $body.='<form method="post">'.Core::formCsrf().'<input type="hidden" name="recovery_action" value="complete"><h2>Nuove credenziali</h2>'.input('Nuova password (minimo 15 caratteri)','password','password','autocomplete="new-password" minlength="15"').input('Conferma nuova password','confirm_password','password','autocomplete="new-password" minlength="15"').input('Codice MFA nuovo (6 cifre)','totp','text','inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"').'<label class="vv-recovery-confirm" for="vv-recovery-saved"><input id="vv-recovery-saved" type="checkbox" name="saved" value="1" required><span class="vv-recovery-confirm-text">Confermo di aver salvato la nuova Recovery Key offline</span></label>'.input('Ultimi 8 caratteri della nuova Recovery Key','recovery_confirm','text','autocomplete="off" minlength="8" maxlength="8"').'<button type="submit">Conferma recupero e revoca le sessioni precedenti</button></form><script src="/assets/totp-qr.js?v=020" defer></script><script src="/assets/recovery.js" defer></script>';
        }
        $body.=notice('Il recupero è destinato esclusivamente a installazioni DEV. La verifica email e il recupero per perdita della Recovery Key richiedono un distinto progetto di sicurezza.');
        Core::html('Recupero account tecnico',$body); exit;
    }
    if ($page==='login') {
        if (($_SESSION['vv_authenticated']??false)===true) { header('Location: /',true,303); exit; }
        $error='';
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            $db=Core::db();
            $ip=hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'unknown',Core::config()['ip_salt']);
            if (locked($db,$ip)) $error='Accesso temporaneamente limitato. Riprova tra 15 minuti.';
            else {
                $admin=$db->query('SELECT * FROM vv_technical_admin WHERE id=1')->fetch();
                $user=(string)($_POST['username']??'');
                $pass=(string)($_POST['password']??'');
                $otp=(string)($_POST['totp']??'');
                $valid=$admin && hash_equals($admin['username'],$user) && password_verify($pass,$admin['password_hash']);
                if ($valid) {
                    $totp=Core::unseal($admin['totp_encrypted'],base64_decode(Core::config()['app_key'],true));
                    $valid=Core::verifyTotp($totp,$otp);
                }
                attempt($db,$ip,(bool)$valid);
                if ($valid) {
                    session_regenerate_id(true);
                    $_SESSION['vv_authenticated']=true;
                    $_SESSION['vv_username']=$admin['username'];
                    $_SESSION['vv_auth_at']=time();
                    $_SESSION['vv_credential_version']=(int)$admin['credential_version'];
                    $db->exec('UPDATE vv_technical_admin SET last_login_at=NOW() WHERE id=1');
                    Core::audit($db,'technical_admin.login',$admin['username']);
                    header('Location: /',true,303); exit;
                }
                $error='Credenziali o codice MFA non validi';
            }
        }
        $body='<section class="hero"><div class="eyebrow">CONTROL CENTER</div><h1>Accesso amministratore tecnico</h1><p>Usa username, password e codice dell’autenticatore.</p></section>';
        if (isset($_GET['recovered'])) $body.='<div class="success">Recupero completato. Le precedenti credenziali e Recovery Key non sono più valide.</div>';
        if (isset($_GET['installed'])) $body.='<div class="success">Installazione iniziale completata. Conserva la Recovery Key.</div>';
        if ($error) $body.=Core::error($error);
        $body.='<form method="post">'.Core::formCsrf().input('Username','username','text','autocomplete="username"').input('Password','password','password','autocomplete="current-password"').input('Codice MFA','totp','text','inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"').'<button>Accedi</button></form><p><a href="/?page=forgot-password">Password dimenticata? Recupera tramite email</a> · <a href="/?page=recover">Recupero totale con Recovery Key</a></p>';
        Core::html('Accesso tecnico',$body); exit;
    }
    Core::requireLogin();
    $db=Core::db();
    if ($page==='staging') {
        if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); exit('Metodo non consentito'); }
        require_once dirname(__DIR__).'/src/StagingControl.php';
        \VenVia\Core::html('Ambienti · Staging', \VenVia\StagingControl::page());
        exit;
    }
    if($page==='rotate-recovery'){
        $db=Core::db();
        $pending=$_SESSION['vv_tech_rotate']??null;
        if($pending && time()-(int)$pending['created']>600){unset($_SESSION['vv_tech_rotate']);$pending=null;}
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $action=(string)($_POST['action']??'');
            if($action==='start' && !$pending){
                $_SESSION['vv_tech_rotate']=['created'=>time(),'new_recovery'=>\VenVia\IdentityRecovery::generate('technical')];
                header('Location: /?page=rotate-recovery',true,303);exit;
            }
            if($action==='complete' && $pending){
                if(($_POST['saved']??'')!=='1'||!hash_equals(substr($pending['new_recovery'],-8),strtoupper(trim((string)($_POST['recovery_confirm']??'')))))throw new RuntimeException('Conferma Recovery Key non valida');
                \VenVia\IdentityRecovery::rotate($db,'technical',1,(string)($_POST['password']??''),(string)($_POST['totp']??''),$pending['new_recovery']);
                $_SESSION=[];session_regenerate_id(true);header('Location: /?page=login&rotated=1',true,303);exit;
            }
            throw new RuntimeException('Azione non valida');
        }
        $body='<section class="hero"><h1>Rigenera Recovery Key tecnica</h1><p>Richiede password e MFA attuali. La vecchia chiave verrà invalidata e tutte le sessioni revocate.</p></section>';
        if(!$pending)$body.='<form method="post">'.Core::formCsrf().'<input type="hidden" name="action" value="start"><button>Genera Recovery Key</button></form>';
        else $body.='<div class="secrets"><code id="recovery">'.Core::e($pending['new_recovery']).'</code><button type="button" id="saveRecovery">Scarica Recovery Key</button></div><form method="post">'.Core::formCsrf().'<input type="hidden" name="action" value="complete">'.input('Password attuale','password','password').input('Codice MFA attuale','totp','text','maxlength="6"').'<label><input type="checkbox" name="saved" value="1" required> Recovery Key salvata offline</label>'.input('Ultimi 8 caratteri','recovery_confirm').'<button>Conferma e revoca sessioni</button></form><script src="/assets/recovery.js" defer></script>';
        Core::html('Sicurezza amministratore tecnico',$body);exit;
    }
    if ($page==='plugins') {
        $db=Core::db();
        $alert='';$pending=$_SESSION['vv_plugin_stage']??null;
        if($pending && time()-(int)$pending['at']>900){\VenVia\PluginManager::discard($pending);unset($_SESSION['vv_plugin_stage']);$pending=null;}
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $action=(string)($_POST['plugin_action']??'');
            if($action==='upload'){
                if($pending)\VenVia\PluginManager::discard($pending);
                $pending=\VenVia\PluginManager::stage($_FILES['plugin_zip']??[]);
                $_SESSION['vv_plugin_stage']=$pending;
                $alert='Pacchetto verificato. Controlla manifesto e SHA256 prima di confermare.';
            }elseif($action==='cancel'){
                \VenVia\PluginManager::discard($pending);unset($_SESSION['vv_plugin_stage']);$pending=null;
                $alert='Caricamento annullato.';
            }else{
                // Require a fresh TOTP on EACH lifecycle change, including activation and removal.
                $otp=(string)($_POST['confirm_totp']??'');
                $admin=$db->query('SELECT totp_encrypted FROM vv_technical_admin WHERE id=1')->fetch();
                $key=base64_decode(Core::config()['app_key'],true);
                if(!$admin||$key===false||!Core::verifyTotp(Core::unseal($admin['totp_encrypted'],$key),$otp))throw new RuntimeException('Verifica MFA richiesta o codice non valido.');
                if($action==='install'){
                    if(!$pending)throw new RuntimeException('Carica prima un pacchetto.');
                    if(($_POST['trusted']??'')!=='1')throw new RuntimeException('Conferma esplicitamente che il codice proviene da una fonte verificata.');
                    $id=\VenVia\PluginManager::install($db,$pending);
                    unset($_SESSION['vv_plugin_stage']);$pending=null;
                    Core::audit($db,'plugin.install.'. $id,(string)$_SESSION['vv_username']);
                    $alert='Plugin installato ma disattivato: '. $id .'. Ora puoi attivarlo.';
                }elseif(in_array($action,['activate','deactivate','remove'],true)){
                    $id=(string)($_POST['plugin_id']??'');
                    \VenVia\PluginManager::change($db,$id,$action);
                    Core::audit($db,'plugin.'.$action.'.'.$id,(string)$_SESSION['vv_username']);
                    $alert='Operazione completata: '.$action.' · '.$id;
                }else throw new RuntimeException('Operazione sconosciuta.');
            }
        }
        $body='<section class="hero"><div class="eyebrow">CONTROL CENTER / PLUGIN</div><h1>Gestione plugin VenVia</h1><p>Carica ZIP direttamente dal browser. SFTP è riservato alla manutenzione di emergenza.</p></section>';
        $body.='<div class="notice"><strong>DEV:</strong> i plugin PHP sono codice eseguibile. Installa soltanto pacchetti verificati e testati in staging. Firme digitali, backup/rollback automatici e isolamento forte non sono ancora disponibili. Non usare in produzione.</div>';
        if($alert)$body.='<div class="success">'.Core::e($alert).'</div>';
        $body.='<section class="section-panel"><h2>Directory plugin SFTP</h2><p><code>'.Core::e(\VenVia\PluginManager::home()).'</code> · '.(\VenVia\PluginManager::isWritable()?'Pronta per installazione web':'Non scrivibile: configura permessi come da guida').'</p></section>';
        $body.='<section class="section-panel"><h2>1. Aggiungi un plugin</h2>';
        if(!$pending)$body.='<form method="post" enctype="multipart/form-data">'.Core::formCsrf().'<input type="hidden" name="plugin_action" value="upload"><label>Pacchetto ZIP (massimo 20 MB, salvo limite PHP inferiore)<input type="file" name="plugin_zip" accept=".zip,application/zip" required></label><button type="submit">Carica e verifica ZIP</button></form>';
        else {
            $m=$pending['manifest'];
            $body.='<p><strong>'.Core::e($m['name']).'</strong> · <code>'.Core::e($m['id']).'</code> · v'.Core::e($m['version']).'</p><p>SHA256: <code>'.Core::e($pending['sha256']).'</code></p><p>SDK '.Core::e($m['sdk']).' · Core minimo '.Core::e($m['min_core']??'0.2.0').'</p><p>Dipendenze: '.Core::e(implode(', ',$m['dependencies']) ?: 'nessuna').'</p>';
            $body.='<form method="post">'.Core::formCsrf().'<input type="hidden" name="plugin_action" value="install"><label class="check"><input type="checkbox" name="trusted" value="1" required> Confermo che il pacchetto proviene da uno sviluppatore autorizzato ed è stato collaudato in staging</label><label>Nuovo codice MFA dell’Amministratore Tecnico<input name="confirm_totp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label><button type="submit">Installa plugin (disattivato)</button></form><form method="post">'.Core::formCsrf().'<input type="hidden" name="plugin_action" value="cancel"><button class="secondary">Annulla caricamento</button></form>';
        }
        $body.='</section><section class="section-panel"><h2>2. Plugin installati</h2>';
        $rows=\VenVia\PluginManager::list($db);
        if(!$rows)$body.='<p>Nessun plugin installato.</p>';
        foreach($rows as $p){
            $id=(string)$p['plugin_id'];$exists=is_file(\VenVia\PluginManager::home().'/'.$id.'/plugin.json');
            $body.='<div class="plugin-entry"><h3>'.Core::e($id).' · '.Core::e((string)$p['version']).'</h3><p>Stato: '.Core::e((string)$p['status']).($exists?'':' · File mancanti: recupero SFTP richiesto').'</p>';
            foreach(($p['status']==='active'?['deactivate']:['activate','remove']) as $action){
                if($action==='activate'&&!$exists)continue;
                $label=['activate'=>'Attiva','deactivate'=>'Disattiva','remove'=>'Rimuovi file (dati conservati)'][$action];
                $body.='<form method="post">'.Core::formCsrf().'<input type="hidden" name="plugin_action" value="'.Core::e($action).'"><input type="hidden" name="plugin_id" value="'.Core::e($id).'"><label>Codice MFA per '.Core::e($label).'<input type="text" name="confirm_totp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label><button class="secondary" type="submit">'.Core::e($label).'</button></form>';
            }
            $body.='</div>';
        }
        $body.='</section><p><a href="/">Torna alla dashboard</a></p>';
        Core::html('Gestione plugin',$body);exit;
    }
    if ($page==='smtp') {
        $alert='';$current=[];
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $action=(string)($_POST['smtp_action']??'');
            if(!in_array($action,['save','test','verify-email'],true))throw new RuntimeException('Operazione non riconosciuta');
            $admin=$db->query('SELECT username,email,email_verified_at,totp_encrypted FROM vv_technical_admin WHERE id=1')->fetch();
            $key=base64_decode(Core::config()['app_key'],true);
            if(!Core::verifyTotp(Core::unseal($admin['totp_encrypted'],$key),(string)($_POST['confirm_totp']??'')))throw new RuntimeException('Conferma MFA non valida');
            if($action==='save'){
                \VenVia\MailService::save($_POST);
                Core::audit($db,'technical.smtp_configured',(string)$admin['username']);
                $alert='Impostazioni SMTP salvate in forma cifrata. Esegui un invio di prova.';
            }elseif($action==='test'){
                $target=trim((string)($_POST['test_to']??''));
                \VenVia\MailService::send($target,'VenVia - email di prova','Questo è un messaggio di prova generato dal Control Center.');
                Core::audit($db,'technical.smtp_test',(string)$admin['username']);
                $alert='Server SMTP: messaggio accettato. Verifica la casella destinataria.';
            }else{
                \VenVia\EmailRecovery::requestVerification($db,'technical',1,(string)($_SERVER['REMOTE_ADDR']??''));
                $alert='Se la configurazione è valida, riceverai il link di verifica all’email tecnica registrata.';
            }
        }
        try{$current=\VenVia\MailService::publicSettings();}catch(\Throwable $e){$alert='Impostazioni SMTP non leggibili. Nessun dato salvato automaticamente.';}
        $body='<section class="hero"><h1>Servizio email / SMTP</h1><p>Configurazione tecnica comune per le email di sicurezza. STARTTLS (587) o TLS implicito (465), con verifica certificato.</p></section>';
        if($alert)$body.='<div class="success">'.Core::e($alert).'</div>';
        $field=static fn(string $label,string $name,string $value,string $type='text')=>'<label>'.Core::e($label).'<input type="'.Core::e($type).'" name="'.Core::e($name).'" value="'.Core::e($value).'" required></label>';
        $body.='<section class="section-panel"><h2>Parametri SMTP</h2><form method="post">'.Core::formCsrf().'<input type="hidden" name="smtp_action" value="save">';
        $body.=$field('Server SMTP','host',(string)($current['host']??''));
        $body.=$field('Porta','port',(string)($current['port']??587),'number');
        $mode=(string)($current['mode']??'starttls');
        $body.='<label>Crittografia<select name="mode"><option value="starttls"'.($mode==='starttls'?' selected':'').'>STARTTLS</option><option value="tls"'.($mode==='tls'?' selected':'').'>TLS implicito</option></select></label>';
        $body.=$field('Utente SMTP','username',(string)($current['username']??''));
        $body.='<label>Password SMTP (vuota = conserva la precedente)<input type="password" name="password" autocomplete="new-password"></label>';
        $body.=$field('Email mittente','from',(string)($current['from']??''),'email');
        $body.=$field('Nome mittente','name',(string)($current['name']??'VenVia'));
        $body.=$field('Codice MFA tecnico','confirm_totp','','text');
        $body.='<button type="submit">Salva SMTP</button></form></section>';
        $body.='<section class="section-panel"><h2>Email di prova</h2><form method="post">'.Core::formCsrf().'<input type="hidden" name="smtp_action" value="test">'.$field('Destinatario test','test_to','','email').$field('Codice MFA tecnico','confirm_totp','').'<button type="submit">Invia email di prova</button></form></section>';
        $verified=$db->query('SELECT email,email_verified_at FROM vv_technical_admin WHERE id=1')->fetch();
        $body.='<section class="section-panel"><h2>Verifica email di recupero</h2><p>Indirizzo registrato: '.Core::e((string)$verified['email']).' · '.($verified['email_verified_at']?'Verificato':'Non ancora verificato').'</p>';
        if(!$verified['email_verified_at'])$body.='<form method="post">'.Core::formCsrf().'<input type="hidden" name="smtp_action" value="verify-email">'.$field('Codice MFA tecnico','confirm_totp','').'<button type="submit">Invia link di verifica email</button></form>';
        $body.='</section>';
        Core::html('SMTP e recupero credenziali',$body);exit;
    }
    if ($page==='operations') {
        $alert=''; $secret=null;
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            $action=(string)($_POST['action']??'');
            if ($action==='migrate') {
                \VenVia\Operational::migrate($db);
                Core::audit($db,'core.migration.020',(string)$_SESSION['vv_username']);
                $alert='Schema gestionale predisposto: tabelle additive create/verificate.';
            } elseif ($action==='invite') {
                $secret=\VenVia\Operational::createFirstInvitation($db,(string)$_SESSION['vv_username']);
                Core::audit($db,'operative_admin.invited',(string)$_SESSION['vv_username']);
                $alert='Invito iniziale creato, valido 20 minuti. Conservalo riservato.';
            } elseif ($action==='replace_admin') {
                $otp=(string)($_POST['confirm_totp']??'');
                $admin=$db->query('SELECT totp_encrypted FROM vv_technical_admin WHERE id=1')->fetch();
                $key=base64_decode(Core::config()['app_key'],true);
                if(!$admin || !is_string($key) || strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES ||
                   !Core::verifyTotp(Core::unseal((string)$admin['totp_encrypted'],$key),$otp)) {
                    throw new RuntimeException('Conferma MFA tecnico obbligatoria');
                }
                $secret=\VenVia\AdminSuccession::issue($db,(string)$_SESSION['vv_username']);
                $alert='Invito di successione creato (20 minuti). Il vecchio amministratore resta attivo fino alla conferma del nuovo.';
            } else throw new RuntimeException('Operazione non consentita');
        }
        $ready=\VenVia\Operational::schemaAvailable($db);
        $body='<section class="hero"><div class="eyebrow">CONTROL CENTER / PROVISIONING</div><h1>Attivazione backend operativo</h1><p>La creazione del primo Amministratore operativo è autorizzata dall’Amministratore Tecnico, ma l’account e la MFA sono creati su app.venvia.it.</p></section>';
        if ($alert) $body.='<div class="success">'.Core::e($alert).'</div>';
        $body.='<section class="section-panel"><h2>1. Schema dati condiviso</h2><p>Versione 020: creazione additiva delle sole tabelle gestionali; nessuna cancellazione o importazione di dati.</p>';
        if (!$ready) $body.='<form method="post">'.Core::formCsrf().'<input type="hidden" name="action" value="migrate"><button type="submit">Applica schema gestionale 0.2.0</button></form>';
        else $body.='<p>Schema gestionale disponibile.</p>';
        $body.='</section><section class="section-panel"><h2>2. Primo Amministratore operativo</h2>';
        if ($ready) {
            $count=(int)$db->query("SELECT COUNT(*) FROM vv_operational_users WHERE role='administrator'")->fetchColumn();
            if ($count) {
                $list=$db->query("SELECT id,username,active,created_at FROM vv_operational_users WHERE role='administrator' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
                $body.='<p>Gli amministratori precedenti restano archiviati. La successione disattiva il precedente soltanto quando il nuovo completa MFA e Recovery Key.</p><ul>';
                foreach($list as $item) $body.='<li>'.Core::e((string)$item['username']).' — '.($item['active']?'Attivo':'Disattivato').' (ID '.(int)$item['id'].')</li>';
                $body.='</ul>';
                $body.='<form method="post">'.Core::formCsrf().'<input type="hidden" name="action" value="replace_admin">'.input('Conferma MFA tecnico','confirm_totp','text','inputmode="numeric" maxlength="6"').'<button type="submit">Genera invito di sostituzione (20 minuti)</button></form>';
            } else $body.='<p>Il codice viene mostrato una sola volta.</p><form method="post">'.Core::formCsrf().'<input type="hidden" name="action" value="invite"><button type="submit">Genera invito iniziale</button></form>';
        } else $body.='<p>Completa il primo passaggio.</p>';
        if ($secret!==null) $body.='<p><strong>Codice monouso:</strong></p><div class="secrets"><code>'.Core::e($secret).'</code></div>';
        $body.='</section><p><a href="/">Torna alla dashboard</a></p>';
        Core::html('Attivazione backend operativo',$body); exit;
    }
    $installation=$db->query('SELECT installation_id,version,installed_at FROM vv_installation WHERE id=1')->fetch();
    $body='<section class="hero" id="overview"><div class="eyebrow">VENVIA / CONTROL CENTER / DEV</div><h1>Panoramica tecnica</h1><p>Benvenuto, '.Core::e((string)$_SESSION['vv_username']).'. Gestione e manutenzione dell’installazione VenVia.</p></section>';
    $body.='<div class="notice"><strong>Ambiente di laboratorio.</strong> Le informazioni sono limitate a quelle confermate dal Core. Aggiornamenti, backup, staging e monitoraggio servizi non sono ancora collegati.</div>';
    $body.='<section class="metric-grid" aria-label="Riepilogo"><article class="tile"><small>CORE</small><h2>'.Core::e(Core::version()).'</h2><p>Versione dichiarata del codice</p></article><article class="tile"><small>DATABASE</small><h2>Connesso</h2><p>Connessione PDO riuscita</p></article><article class="tile"><small>BACKUP</small><h2>Non attivo</h2><p>Da sviluppare e collaudare</p></article><article class="tile"><small>STAGING</small><h2>Da verificare</h2><p>Stato operativo non collegato</p></article></section>';
    $body.='<section class="section-panel"><h2>Ambienti · Staging</h2><p>Diagnostica preliminare e pianificazione della clonazione/promozione.</p><p><a href="/?page=staging">Apri gestione Staging</a></p></section>';
    $body.=\VenVia\Environment::panel($db);
    $body.='<section class="section-panel"><h2>Backend operativo</h2><p>Attiva app.venvia.it, prepara il database gestionale e genera l’invito per il primo Amministratore operativo.</p><p><a href="/?page=operations">Apri attivazione backend operativo</a></p></section>';
    $body.='<section class="section-panel"><h2>Plugin e moduli</h2><p>Installa e gestisci i plugin dal Control Center. SFTP per emergenze.</p><p><a href="/?page=plugins">Apri Plugin Manager</a></p></section>';
    $body.='<section class="section-panel"><h2>Sicurezza account</h2><p><a href="/?page=rotate-recovery">Rigenera Recovery Key tecnica</a></p></section>';
    $body.='<section class="section-panel"><h2>SMTP e notifiche</h2><p>Configura e verifica il servizio di posta tecnica.</p><p><a href="/?page=smtp">Apri impostazioni SMTP</a></p></section>';
    $body.='<section class="section-panel" id="maintenance"><h2>Manutenzione, ambienti e moduli</h2><p class="muted">Backup, ripristino, migrazione locale/cloud, staging e aggiornamenti rimangono funzioni pianificate: nessuna operazione è attivabile da questa versione LAB.</p></section>';
    $body.='<section class="section-panel" id="security"><h2>Identità e sicurezza</h2><p class="muted">Accesso protetto da autenticazione e MFA. La verifica email e il recupero credenziali richiedono collaudo funzionale; le funzioni tecniche avanzate non sono ancora operative.</p></section>';
    $body.='<section class="metadata"><p><strong>ID installazione:</strong> '.Core::e((string)$installation['installation_id']).'</p><p><strong>Versione prima installazione (storica):</strong> '.Core::e((string)$installation['version']).'</p><p><strong>Installato il:</strong> '.Core::e((string)$installation['installed_at']).'</p></section>';
    $body.='<form method="post" action="/?page=logout">'.Core::formCsrf().'<button class="secondary">Esci</button></form>';
    $body.=notice('Core '.Core::e(Core::version()).' (codice dichiarato): SMTP e recupero email disponibili per collaudo DEV; backup automatici, ripristino, migrazione e aggiornamenti tecnici non ancora operativi. Non utilizzare in produzione.');
    Core::html('Control Center',$body);
} catch (Throwable $e) {
    // Protect DB errors, secrets and filesystem paths from being disclosed to unauthenticated users.
    $known=$e instanceof RuntimeException && !($e instanceof PDOException);
    $message=$known ? $e->getMessage() : 'Errore interno. Verifica i log PHP del server.';
    if (!$known) error_log('VenVia error: '.$e->getMessage());
    http_response_code($known?400:500);
    $back=Core::installed() ? 'login' : (isset($_SESSION['pending_setup'])?'mfa':'setup');
    Core::html('Problema di configurazione',Core::error($message).'<p><a href="/?page='.Core::e($back).'">Torna indietro</a></p>');
}
