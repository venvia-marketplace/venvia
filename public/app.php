<?php
declare(strict_types=1);
use VenVia\Core;
use VenVia\Operational;
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Operational.php';
require_once dirname(__DIR__).'/src/InterfaceAPI.php';
require_once dirname(__DIR__).'/src/PluginManager.php';
require_once dirname(__DIR__).'/src/MailService.php';
require_once dirname(__DIR__).'/src/EmailRecovery.php';
require_once dirname(__DIR__).'/src/IdentityRecovery.php';
require_once dirname(__DIR__).'/src/AdminSuccession.php';
if (($_SERVER['HTTPS']??'')!=='on') {http_response_code(403);exit('HTTPS richiesto');}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'self'; script-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Strict-Transport-Security: max-age=31536000');
header('Cache-Control: no-store, max-age=0');
ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
session_name('VENVIA_APP');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
function esc(string $s): string{return Core::e($s);}
function fld(string $label,string $name,string $type='text',string $extra=''):string {return '<label>'.esc($label).'<input type="'.esc($type).'" name="'.esc($name).'" '.$extra.' required></label>'; }
function cs():string {return Core::formCsrf();}
function shell(string $title,string $body,bool $auth=false,?PDO $db=null,?array $ctx=null):void {
  header('Content-Type: text/html; charset=utf-8');
  $css='';$js='';$navigation=[];
  if($auth && $db && $ctx){
    [$css,$js]=\VenVia\InterfaceAPI::assetTags($db);
    $navigation=\VenVia\InterfaceAPI::navigation($db,$ctx);
  }
  $head='<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.esc($title).' · VenVia</title><link rel="icon" href="/assets/favicon.ico"><link rel="stylesheet" href="/assets/venvia-tokens.css?v=022"><link rel="stylesheet" href="/assets/style.css?v=022"><link rel="stylesheet" href="/assets/operative.css?v=022">'.$css.'</head><body>';
  if($auth && $db && $ctx){
    $pluginShell=\VenVia\InterfaceAPI::shell($db,$ctx,$body);
    if($pluginShell!==null){echo $head.$pluginShell.$js.'<script src="/assets/theme.js?v=022" defer></script></body></html>';return;}
  }
  echo $head.'<div class="app-layout">';
  if($auth){
    echo '<aside class="sidebar"><div class="sidebar-brand"><a href="/" class="brand-panel"><img src="/assets/venvia-logo.png" alt="VénVia"></a><span class="brand-sub">GESTIONALE</span></div><nav class="side-nav"><a class="nav-link" href="/">Panoramica</a><a class="nav-link" href="/backend">Amministrazione</a><a class="nav-link" href="/?page=plugins">Moduli attivi</a>';
    foreach($navigation as $entry)echo '<a class="nav-link" href="'.esc($entry['url']).'">'.esc($entry['label']).'</a>';
    $areaLabel=(($ctx['area']??'frontend')==='backend')?'Backend gestionale':'Frontend operativo';
    echo '</nav><div class="sidebar-bottom">'.esc($areaLabel).' · DEV</div></aside>';
  }
  $areaLabel=(($ctx['area']??'frontend')==='backend')?'Backend gestionale':'Frontend operativo';
  $topBrand=$auth?'':'<span class="brand-panel"><img src="/assets/venvia-logo.png" alt="VénVia"></span>';
  echo '<div class="main-shell"><header class="topbar">'.$topBrand.'<div class="topbar-title"><strong>VenVia · '.esc($auth?$areaLabel:'Accesso gestionale').'</strong><small>'.esc(Core::version()).' · Ambiente di laboratorio</small></div><span class="topbar-spacer"></span><button type="button" class="theme-toggle secondary" id="themeToggle">Tema chiaro/scuro</button></header><main class="content-area">'.$body.'</main><footer class="app-footer">VenVia '.esc(Core::version()).' · Nessuna vendita o operazione fiscale abilitata</footer></div></div>'.$js.'<script src="/assets/theme.js?v=022" defer></script></body></html>';
}
function attemptOp(PDO $db,string $ip,bool $valid):void {
 if ($valid) { $s=$db->prepare('DELETE FROM vv_operational_login_attempt WHERE ip_hash=?');$s->execute([$ip]);return;}
 $s=$db->prepare('INSERT INTO vv_operational_login_attempt(ip_hash,attempts,last_attempt) VALUES (?,1,NOW()) ON DUPLICATE KEY UPDATE attempts=IF(last_attempt<NOW()-INTERVAL 15 MINUTE,1,attempts+1),last_attempt=NOW()');$s->execute([$ip]);
}
try {
 if (!Core::installed()) {http_response_code(503);shell('Configurazione necessaria','<section class="hero"><h1>VenVia non configurato</h1><p>Completa la prima installazione sul Control Center.</p></section>');exit;}
 $db=Core::db();
 if (!Operational::schemaAvailable($db)) {http_response_code(503);shell('In attesa di attivazione','<section class="hero"><h1>Backend da attivare</h1><p>Accedi al Control Center: Attivazione backend operativo → Applica schema 0.2.0.</p></section>');exit;}
 $page=(string)($_GET['page']??'dashboard');
 if ($_SERVER['REQUEST_METHOD']==='POST') Core::checkCsrf();
 $error='';
 if ($page==='enroll') {
   $pending=$_SESSION['vv_op_enroll']??null;
   if ($pending && time()-(int)$pending['created']>900) {unset($_SESSION['vv_op_enroll']);$pending=null;}
   if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['stage']??'')==='invite' && !$pending) {
     $token=strtoupper(trim((string)($_POST['invitation']??'')));
     $inv=Operational::validInvite($db,$token);
     if (!$inv) throw new RuntimeException('Invito non valido o scaduto');
     $succession=\VenVia\AdminSuccession::pendingForInvite($db,(int)$inv['id']);
     $existing=(int)$db->query("SELECT COUNT(*) FROM vv_operational_users WHERE role='administrator'")->fetchColumn();
     if ($existing>0 && !$succession) throw new RuntimeException('Occorre un invito di successione autorizzato dal Control Center');
     if ($existing===0 && $succession) throw new RuntimeException('Invito di successione non coerente');
     $_SESSION['vv_op_enroll']=['invite_id'=>(int)$inv['id'],'succession_id'=>$succession?(int)$succession['id']:null,'role'=>$inv['role'],'token_hash'=>hash('sha256',$token),'totp'=>Core::totpSecret(),'recovery'=>'VV-OP-REC-'.strtoupper(bin2hex(random_bytes(32))),'created'=>time()];
     session_regenerate_id(true);header('Location: /?page=enroll',true,303);exit;
   }
   if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['stage']??'')==='complete' && $pending) {
     $username=trim((string)($_POST['username']??''));$email=trim((string)($_POST['email']??''));$pass=(string)($_POST['password']??'');
     if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{3,63}$/D',$username)) throw new RuntimeException('Username non valido');
     if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254) throw new RuntimeException('Email non valida');
     if (strlen($pass)<15 || strlen($pass)>1024 || !preg_match('/[a-zA-Z]/',$pass) || !hash_equals($pass,(string)($_POST['confirm_password']??''))) throw new RuntimeException('Password non valida o non coincidente');
     if (($_POST['saved']??'')!=='1' || !hash_equals(substr($pending['recovery'],-8),strtoupper(trim((string)($_POST['recovery_confirm']??''))))) throw new RuntimeException('Salva e conferma la Recovery Key offline');
     if (!Core::verifyTotp($pending['totp'],(string)($_POST['totp']??''))) throw new RuntimeException('MFA non valido');
     $key=base64_decode(Core::config()['app_key'],true);
     if ($key===false || strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new RuntimeException('Chiave applicativa non valida');
     $db->beginTransaction();
     try {
       $q=$db->prepare('SELECT id FROM vv_operational_invites WHERE id=? AND token_hash=? AND consumed_at IS NULL AND expires_at>NOW() FOR UPDATE');
       $q->execute([$pending['invite_id'],$pending['token_hash']]);
       if (!$q->fetch()) throw new RuntimeException('Invito scaduto o già usato');
       $successorId=$pending['succession_id']??null;
       if ($successorId!==null) {
           $current=\VenVia\AdminSuccession::lockForCompletion($db,(int)$successorId,(int)$pending['invite_id']);
       } elseif ((int)$db->query("SELECT COUNT(*) FROM vv_operational_users WHERE role='administrator'")->fetchColumn()>0) {
           throw new RuntimeException('Amministratore operativo già presente');
       }
       $q=$db->prepare('INSERT INTO vv_operational_users(username,email,role,password_hash,totp_encrypted,recovery_hash) VALUES (?,?,?,?,?,?)');
       $q->execute([$username,$email,'administrator',password_hash($pass,PASSWORD_ARGON2ID),Core::seal($pending['totp'],$key),password_hash($pending['recovery'],PASSWORD_ARGON2ID)]);
       $newAdminId=(int)$db->lastInsertId();
       if ($successorId!==null) \VenVia\AdminSuccession::finishWithinTransaction($db,(int)$successorId,(int)$current['old_admin_id'],$newAdminId);
       $q=$db->prepare('UPDATE vv_operational_invites SET consumed_at=NOW() WHERE id=?');$q->execute([$pending['invite_id']]);
       Core::audit($db,'operative_admin.created',$username);
       $db->commit();
     } catch (Throwable $e) {if($db->inTransaction())$db->rollBack();throw $e;}
     unset($_SESSION['vv_op_enroll']);session_regenerate_id(true);header('Location: /?page=login&created=1',true,303);exit;
   }
   $body='<section class="hero"><div class="eyebrow">ATTIVAZIONE GESTIONALE</div><h1>Attivazione Amministratore gestionale</h1><p>Identità distinta dall’Amministratore Tecnico. Richiesta MFA e Recovery Key offline.</p></section>';
   if (!$pending) $body.='<form method="post">'.cs().'<input type="hidden" name="stage" value="invite">'.fld('Codice invito ricevuto dal Control Center','invitation','password','autocomplete="off"').'<button>Verifica invito</button></form>';
   else {
     $url='otpauth://totp/VenVia%20Operativo:'.rawurlencode('administrator').'?secret='.rawurlencode($pending['totp']).'&issuer=VenVia%20Operativo&algorithm=SHA1&digits=6&period=30';
     $body.='<section class="totp-enroll"><h2>MFA — scansione QR</h2><div class="totp-qr" data-otpauth="'.esc($url).'">Caricamento QR…</div><p>Chiave alternativa: <code>'.esc($pending['totp']).'</code></p></section>';
     $body.='<div class="secrets"><strong>Recovery Key — da conservare offline</strong><code id="recovery">'.esc($pending['recovery']).'</code><button type="button" id="saveRecovery" class="secondary">Scarica Recovery Key</button></div>';
     $body.='<form method="post">'.cs().'<input type="hidden" name="stage" value="complete">'.fld('Nome utente','username').fld('Email','email','email').fld('Password (minimo 15 caratteri)','password','password','minlength="15"').fld('Conferma password','confirm_password','password','minlength="15"').fld('Codice MFA (6 cifre)','totp','text','inputmode="numeric" pattern="[0-9]{6}" maxlength="6"').'<label class="check"><input type="checkbox" name="saved" value="1" required> Recovery Key salvata offline</label>'.fld('Ultimi 8 caratteri Recovery Key','recovery_confirm','text','maxlength="8" minlength="8"').'<button>Attiva Amministratore operativo</button></form><script src="/assets/totp-qr.js?v=021" defer></script><script src="/assets/recovery.js?v=021" defer></script>';
   }
   shell('Attivazione account operativo',$body);exit;
 }
 if ($page==='logout') {if ($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;} $_SESSION=[];session_regenerate_id(true);header('Location: /?page=login',true,303);exit;}
 if($page==='forgot-password'){
   $body='<section class="hero"><h1>Recupera password gestionale</h1><p>Il recupero via email richiede un indirizzo verificato e il codice MFA attuale.</p></section>';
   if($_SERVER['REQUEST_METHOD']==='POST'){
     try{\VenVia\EmailRecovery::request($db,'operational',trim((string)($_POST['username']??'')),(string)($_SERVER['REMOTE_ADDR']??''));}catch(\Throwable $e){error_log('VenVia operative password delivery failed (redacted)');}
     $body.='<div class="success">Se l’account è idoneo, riceverai un’email di recupero.</div>';
   }
   $body.='<form method="post">'.cs().fld('Nome utente','username').'<button>Invia link di recupero</button></form>';
   shell('Password dimenticata',$body);exit;
 }
 if($page==='verify-email'||$page==='reset-password'){
   $token=(string)($_POST['token']??$_GET['token']??'');
   if($_SERVER['REQUEST_METHOD']==='POST'){
     if($page==='verify-email')\VenVia\EmailRecovery::apply($db,'operational','verify',$token);
     else{
       $p=(string)($_POST['password']??'');
       if($p!==(string)($_POST['confirm_password']??''))throw new RuntimeException('Password differenti');
       \VenVia\EmailRecovery::apply($db,'operational','password',$token,$p,(string)($_POST['totp']??''));
     }
     shell('Operazione completata','<div class="success">Operazione completata.</div><p><a href="/?page=login">Accedi</a></p>');exit;
   }
   $body='<section class="hero"><h1>'.($page==='verify-email'?'Verifica indirizzo email':'Nuova password').'</h1></section><form method="post">'.cs().'<input type="hidden" name="token" value="'.esc($token).'">';
   if($page==='reset-password')$body.=fld('Nuova password','password','password','minlength="15" maxlength="256"').fld('Conferma password','confirm_password','password','minlength="15" maxlength="256"').fld('Codice MFA','totp','text','inputmode="numeric" pattern="[0-9]{6}" maxlength="6"');
   $body.='<button type="submit">Conferma</button></form>';shell('Recupero credenziali',$body);exit;
 }
 if($page==='recover') {
   $stage=$_SESSION['vv_op_recover']??null;
   if($stage && time()-(int)$stage['created']>600){unset($_SESSION['vv_op_recover']);$stage=null;}
   $ip=hash_hmac('sha256','op-recovery:'.($_SERVER['REMOTE_ADDR']??'unknown'),Core::config()['ip_salt']);
   if($_SERVER['REQUEST_METHOD']==='POST') {
     $action=(string)($_POST['action']??'');
     if($action==='verify' && !$stage){
       $q=$db->prepare('SELECT attempts FROM vv_operational_login_attempt WHERE ip_hash=? AND last_attempt>NOW()-INTERVAL 15 MINUTE');$q->execute([$ip]);
       if((int)$q->fetchColumn()>=5)throw new RuntimeException('Troppi tentativi: riprova più tardi');
       $username=trim((string)($_POST['username']??''));$key=strtoupper(trim((string)($_POST['recovery_key']??'')));
       $id=\VenVia\IdentityRecovery::checkRecovery($db,'operational',$username,$key);
       attemptOp($db,$ip,$id>0);
       if(!$id)throw new RuntimeException('Dati di recupero non validi');
       $_SESSION['vv_op_recover']=['id'=>$id,'old_key'=>$key,'created'=>time(),'totp'=>Core::totpSecret(),'new_recovery'=>\VenVia\IdentityRecovery::generate('operational')];
       session_regenerate_id(true);header('Location: /?page=recover',true,303);exit;
     }
     if($action==='complete' && $stage){
       $pw=(string)($_POST['password']??'');
       if(!hash_equals($pw,(string)($_POST['confirm_password']??'')))throw new RuntimeException('Le password non coincidono');
       if(($_POST['saved']??'')!=='1'||!hash_equals(substr($stage['new_recovery'],-8),strtoupper(trim((string)($_POST['recovery_confirm']??'')))))throw new RuntimeException('Conferma salvataggio Recovery Key non riuscita');
       if(!Core::verifyTotp($stage['totp'],(string)($_POST['totp']??'')))throw new RuntimeException('Nuovo MFA non valido');
       \VenVia\IdentityRecovery::complete($db,'operational',(int)$stage['id'],$stage['old_key'],$pw,$stage['totp'],$stage['new_recovery']);
       unset($_SESSION['vv_op_recover'],$_SESSION['vv_op_user'],$_SESSION['vv_op_version']);session_regenerate_id(true);
       header('Location: /?page=login&recovered=1',true,303);exit;
     }
     throw new RuntimeException('Procedura di recupero non valida');
   }
   $body='<section class="hero"><h1>Recupero completo account gestionale</h1><p>Richiede la Recovery Key originale. La precedente chiave, password e MFA saranno revocati dopo il completamento.</p></section>';
   if(!$stage){
      $body.='<form method="post">'.cs().'<input type="hidden" name="action" value="verify">'.fld('Username','username').fld('Recovery Key','recovery_key','password','autocomplete="off"').'<button>Verifica Recovery Key</button></form>';
   }else{
      $username=(string)$db->query('SELECT username FROM vv_operational_users WHERE id='.(int)$stage['id'])->fetchColumn();
      $url='otpauth://totp/VenVia%20Operativo:'.rawurlencode($username).'?secret='.rawurlencode($stage['totp']).'&issuer=VenVia%20Operativo&algorithm=SHA1&digits=6&period=30';
      $body.='<div class="totp-qr" data-otpauth="'.esc($url).'">Caricamento QR…</div><p>Chiave MFA nuova: <code>'.esc($stage['totp']).'</code></p><div class="secrets"><strong>Nuova Recovery Key</strong><code id="recovery">'.esc($stage['new_recovery']).'</code><button type="button" id="saveRecovery" class="secondary">Scarica Recovery Key</button></div>';
      $body.='<form method="post">'.cs().'<input type="hidden" name="action" value="complete">'.fld('Nuova password','password','password','minlength="15"').fld('Conferma password','confirm_password','password','minlength="15"').fld('Nuovo codice MFA','totp','text','inputmode="numeric" maxlength="6"').'<label><input type="checkbox" name="saved" value="1" required> Confermo il salvataggio offline</label>'.fld('Ultimi 8 caratteri della nuova Recovery Key','recovery_confirm','text','maxlength="8"').'<button>Completa recupero</button></form><script src="/assets/totp-qr.js" defer></script><script src="/assets/recovery.js" defer></script>';
   }
   shell('Recupero account gestionale',$body);exit;
 }
 if($page==='rotate-recovery') {
   if(!isset($_SESSION['vv_op_user'])){header('Location: /?page=login',true,303);exit;}
   $id=(int)$_SESSION['vv_op_user'];$q=$db->prepare('SELECT active,credential_version FROM vv_operational_users WHERE id=?');$q->execute([$id]);$account=$q->fetch();
   if(!$account||!$account['active']||(int)$account['credential_version']!==(int)($_SESSION['vv_op_version']??-1))throw new RuntimeException('Sessione non valida');
   $pending=$_SESSION['vv_op_rotate']??null;if($pending && time()-(int)$pending['created']>600){unset($_SESSION['vv_op_rotate']);$pending=null;}
   if($_SERVER['REQUEST_METHOD']==='POST') {
      if(($_POST['action']??'')==='start' && !$pending){$_SESSION['vv_op_rotate']=['created'=>time(),'new_recovery'=>\VenVia\IdentityRecovery::generate('operational')];header('Location: /?page=rotate-recovery',true,303);exit;}
      if(($_POST['action']??'')==='complete' && $pending){
        if(($_POST['saved']??'')!=='1'||!hash_equals(substr($pending['new_recovery'],-8),strtoupper(trim((string)($_POST['recovery_confirm']??'')))))throw new RuntimeException('Conferma Recovery Key non valida');
        \VenVia\IdentityRecovery::rotate($db,'operational',$id,(string)($_POST['password']??''),(string)($_POST['totp']??''),$pending['new_recovery']);
        $_SESSION=[];session_regenerate_id(true);header('Location: /?page=login&rotated=1',true,303);exit;
      }
      throw new RuntimeException('Azione non valida');
   }
   $body='<section class="hero"><h1>Rigenera Recovery Key</h1><p>Richiede password attuale e MFA. La precedente Recovery Key sarà invalidata e le sessioni revocate.</p></section>';
   if(!$pending)$body.='<form method="post">'.cs().'<input type="hidden" name="action" value="start"><button>Genera una nuova chiave</button></form>';
   else $body.='<div class="secrets"><code id="recovery">'.esc($pending['new_recovery']).'</code><button type="button" id="saveRecovery">Scarica Recovery Key</button></div><form method="post">'.cs().'<input type="hidden" name="action" value="complete">'.fld('Password attuale','password','password').fld('MFA attuale','totp','text','maxlength="6"').'<label><input type="checkbox" name="saved" value="1" required> Chiave salvata offline</label>'.fld('Ultimi 8 caratteri','recovery_confirm').'<button>Conferma sostituzione</button></form><script src="/assets/recovery.js" defer></script>';
   shell('Sicurezza account',$body);exit;
 }
 if ($page==='login') {
   if (isset($_SESSION['vv_op_user'])) {header('Location: /',true,303);exit;}
   if ($_SERVER['REQUEST_METHOD']==='POST') {
     $ip=hash_hmac('sha256','app:'.($_SERVER['REMOTE_ADDR']??'unknown'),Core::config()['ip_salt']);
     $q=$db->prepare('SELECT attempts FROM vv_operational_login_attempt WHERE ip_hash=? AND last_attempt>NOW()-INTERVAL 15 MINUTE');$q->execute([$ip]);
     if ((int)$q->fetchColumn()>=8) throw new RuntimeException('Troppi tentativi. Riprova più tardi.');
     $q=$db->prepare('SELECT * FROM vv_operational_users WHERE username=? AND active=1');$q->execute([(string)($_POST['username']??'')]);$u=$q->fetch();
     $valid=$u && password_verify((string)($_POST['password']??''),$u['password_hash']);
     if($valid){$key=base64_decode(Core::config()['app_key'],true);$valid=Core::verifyTotp(Core::unseal($u['totp_encrypted'],$key),(string)($_POST['totp']??''));}
     attemptOp($db,$ip,(bool)$valid);
     if($valid){session_regenerate_id(true);$_SESSION['vv_op_user']=(int)$u['id'];$_SESSION['vv_op_version']=(int)$u['credential_version'];$q=$db->prepare('UPDATE vv_operational_users SET last_login_at=NOW() WHERE id=?');$q->execute([$u['id']]);Core::audit($db,'operative.login',$u['username']);header('Location: /',true,303);exit;}
     $error='Credenziali o MFA non validi';
   }
   $body='<section class="hero"><h1>Accesso gestionale</h1><p>Accesso separato dal Control Center, con MFA obbligatoria.</p></section>';
   if (isset($_GET['created'])) $body.='<div class="success">Amministratore operativo creato. Accedi con le nuove credenziali.</div>';
   if($error)$body.=Core::error($error);
   $body.='<form method="post">'.cs().fld('Nome utente','username').fld('Password','password','password').fld('Codice MFA','totp','text','inputmode="numeric" pattern="[0-9]{6}" maxlength="6"').'<button>Accedi</button></form><p><a href="/?page=forgot-password">Password dimenticata?</a> · <a href="/?page=recover">Recupero completo con Recovery Key</a> · Per perdita MFA usa la Recovery Key gestionale.</p>';
   shell('Login gestionale',$body);exit;
 }
 if (!isset($_SESSION['vv_op_user'])) {
   $first=(int)$db->query("SELECT COUNT(*) FROM vv_operational_users WHERE role='administrator'")->fetchColumn();
   header('Location: /?page='.($first?'login':'enroll'),true,303);exit;
 }
 $q=$db->prepare('SELECT id,username,role,active,credential_version FROM vv_operational_users WHERE id=?');
 $q->execute([$_SESSION['vv_op_user']]);$u=$q->fetch();
 if (!$u || !$u['active'] || (int)$u['credential_version']!==(int)($_SESSION['vv_op_version']??-1)) {
   $_SESSION=[];session_regenerate_id(true);header('Location: /?page=login',true,303);exit;
 }
 if($page==='account-email'){
   $body='<section class="hero"><h1>Verifica email gestionale</h1><p>L’email deve essere verificata prima di abilitare il recupero password.</p></section>';
   $q=$db->prepare('SELECT email,email_verified_at,totp_encrypted FROM vv_operational_users WHERE id=?');$q->execute([(int)$u['id']]);$emailAccount=$q->fetch();
   if($_SERVER['REQUEST_METHOD']==='POST'){
     $secret=Core::unseal($emailAccount['totp_encrypted'],base64_decode(Core::config()['app_key'],true));
     if(!Core::verifyTotp($secret,(string)($_POST['totp']??'')))throw new RuntimeException('Codice MFA non valido');
     \VenVia\EmailRecovery::requestVerification($db,'operational',(int)$u['id'],(string)($_SERVER['REMOTE_ADDR']??''));
     $body.='<div class="success">Email di verifica inviata. Controlla la casella.</div>';
   }
   $body.='<p>'.esc((string)$emailAccount['email']).' · '.($emailAccount['email_verified_at']?'Verificata':'Non verificata').'</p>';
   if(!$emailAccount['email_verified_at'])$body.='<form method="post">'.cs().fld('Codice MFA','totp','text','inputmode="numeric" pattern="[0-9]{6}" maxlength="6"').'<button>Invia link di verifica</button></form>';
   shell('Verifica email',$body,true,$db,\VenVia\InterfaceAPI::context($u,'backend'));exit;
 }
 // Asset access stays behind app login and requires an active plugin.
 if(isset($_GET['plugin_asset'])){
   $id=(string)$_GET['plugin_asset'];$file=(string)($_GET['file']??'');
   if(!\VenVia\PluginManager::identifier($id) || !preg_match('#^[a-zA-Z0-9_/-]+\.(?:css|js|png|jpg|jpeg|svg|webp|ico)$#D',$file) || str_contains($file,'..')){http_response_code(404);exit;}
   $q=$db->prepare("SELECT status FROM vv_plugin_registry WHERE plugin_id=?");$q->execute([$id]);
   if($q->fetchColumn()!=='active'){http_response_code(404);exit;}
   $dir=realpath(\VenVia\PluginManager::home().'/'.$id);$candidate=$dir?realpath($dir.'/'.$file):false;
   if(!$dir||!$candidate||!str_starts_with($candidate,$dir.'/')||!is_file($candidate)||is_link($candidate)){http_response_code(404);exit;}
   $ext=strtolower(pathinfo($file,PATHINFO_EXTENSION));
   $types=['css'=>'text/css','js'=>'application/javascript','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','svg'=>'image/svg+xml','webp'=>'image/webp','ico'=>'image/x-icon'];
   header('Content-Type: '.$types[$ext]);header('Content-Length: '.filesize($candidate));readfile($candidate);exit;
 }
 $path=(string)(parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)?:'/');
 $area=($path==='/backend'||str_starts_with($path,'/backend/'))?'backend':'frontend';
 $ctx=\VenVia\InterfaceAPI::context($u,$area);
 if($area==='backend' && !\VenVia\InterfaceAPI::can($ctx,'backend.access')){http_response_code(403);throw new RuntimeException('Accesso non consentito');}
 if($page==='core-api') {
   require_once dirname(__DIR__).'/src/Domain/MasterDataAPI.php';
   require_once dirname(__DIR__).'/src/Domain/ProductStudioAPI.php';
   require_once dirname(__DIR__).'/src/Domain/FiscalHistoryAPI.php';
   require_once dirname(__DIR__).'/src/Domain/MasterDataHttp.php';
   \VenVia\Domain\MasterDataHttp::dispatch($db,$ctx);
 }
 if(isset($_GET['module'])) {
   $id=(string)$_GET['module'];$view=(string)($_GET['view']??'');
   $body=\VenVia\InterfaceAPI::page($db,$id,$view,$ctx);
   shell('Modulo '. $id,$body,true,$db,$ctx);exit;
 }
 if($page==='plugins') {
   $body='<section class="section-panel"><h1>Plugin e moduli</h1><p>Gestione tecnica esclusivamente su control.venvia.it. Qui sono elencati i moduli disponibili.</p>';
   $rows=\VenVia\PluginManager::list($db);
   if(!$rows)$body.='<p>Nessun plugin registrato.</p>';
   foreach($rows as $p)$body.='<p>'.esc($p['plugin_id']).' · '.esc($p['version']).' · '.esc($p['status']).'</p>';
   $body.='</section>';
 }else{
   $body=\VenVia\InterfaceAPI::home($db,$ctx);
   if($body===null){
     if($area==='backend')$body='<section class="hero"><h1>Backend gestionale</h1><p>VenVia Interface non attivo. Interfaccia minima di emergenza disponibile; autenticazione e autorizzazioni restano nel Core.</p></section>';
     else $body='<section class="hero"><h1>Frontend operativo</h1><p>VenVia Interface non attivo. La gestione tecnica dei plugin rimane disponibile nel Control Center.</p></section>';
     $body.=\VenVia\InterfaceAPI::widgetsHtml($db,$ctx);
   }
 }
 $body.='<p><a href="/?page=rotate-recovery">Rigenera Recovery Key</a> · <a href="/?page=account-email">Verifica email di recupero</a></p><form method="post" action="/?page=logout">'.cs().'<button class="secondary">Esci</button></form>';
 shell($area==='backend'?'Backend gestionale':'Frontend operativo',$body,true,$db,$ctx);
} catch (Throwable $e) {
 $safe=$e instanceof RuntimeException && !($e instanceof PDOException);
 if(!$safe) error_log('VenVia operative error (redacted): '.get_class($e));
 http_response_code($safe?400:500);
 shell('Errore',Core::error($safe?$e->getMessage():'Errore interno: controlla i log PHP.').'<p><a href="/">Torna indietro</a></p>');
}
