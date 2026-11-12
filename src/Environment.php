<?php
declare(strict_types=1);
namespace VenVia;
use PDO;
final class Environment {
 public static function panel(PDO $db): string {
   $os='Non rilevato';
   if (is_readable('/etc/os-release')) {
     $raw=file_get_contents('/etc/os-release') ?: '';
     if (preg_match('/^PRETTY_NAME="?([^"\n]+)"?$/m',$raw,$m)) $os=trim($m[1],'"');
   }
   $disk=@disk_free_space(Core::root());
   $free=$disk===false?'Non accessibile':number_format($disk/1073741824,1,',','.').' GiB liberi (filesystem applicativo)';
   try {$dbv=(string)$db->query('SELECT VERSION()')->fetchColumn();}catch (\Throwable $e) {$dbv='Non disponibile';}
   $ext=[]; foreach (['pdo_mysql','sodium','openssl','mbstring'] as $x) $ext[]=$x.': '.(extension_loaded($x)?'presente':'assente');
   $rows=[
    'Sistema operativo'=>$os.' (lettura identificativa)',
    'PHP Runtime'=>PHP_VERSION.' · '.PHP_SAPI,
    'Estensioni'=>implode(' · ',$ext),
    'MariaDB (connessione PDO)'=>$dbv,
    'Spazio libero'=>$free,
    'Nginx/Apache'=>'Stato servizio: verifica tramite System Agent non ancora disponibile',
    'Certificati TLS'=>'Validità/scadenza: verifica automatica non ancora disponibile',
    'Aggiornamenti Ubuntu/PHP/MariaDB'=>'Installazione disabilitata: System Agent non ancora attivo',
    'Anomalie e log'=>'Aggregazione e consultazione: da implementare',
   ];
   $html='<section class="section-panel" id="system"><h2>Sistema e diagnostica</h2><p class="muted">Valori letti al momento della richiesta. Non attestano lo stato complessivo dei servizi né la sicurezza dell’host.</p><div class="service-list">';
   foreach($rows as $label=>$val) $html.='<div><strong>'.Core::e($label).'</strong><span>'.Core::e($val).'</span></div>';
   return $html.'</div></section>';
 }
}
