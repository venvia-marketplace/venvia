<?php
declare(strict_types=1);
namespace VenVia;

/** Staging preflight inventory only. No write, SQL access, HTTP probes or shell execution. */
final class StagingControl {
    private static function tile(string $title, string $status, string $detail): string {
        $safe=static fn(string $s): string => Core::e($s);
        return '<article class="tile"><small>'.$safe($title).'</small><h2>'.$safe($status).'</h2><p>'.$safe($detail).'</p></article>';
    }
    private static function childOf(string $path, string $root): bool {
        $p=realpath($path); $r=realpath($root);
        return $p!==false && $r!==false && str_starts_with($p, rtrim($r, '/').'/');
    }
    private static function snapshot(): array {
        $main='/var/www/venvia';
        $stage='/var/www/venvia-staging';
        $data='/srv/venvia-data/staging';
        $rootData='/srv/venvia-data';
        $sock='/run/php/php8.5-fpm-venvia-staging.sock';
        $checks=[];
        $add=static function(string $name, string $status, string $msg) use (&$checks): void {
            $checks[]=[$name,$status,$msg];
        };
        $add('Root applicativa staging', is_dir($stage) && !is_link($stage) && !self::childOf($stage,$main) ? 'Rilevata' : 'Non verificata', 'Richiesta una directory separata dalla root principale, non un collegamento simbolico.');
        $add('Directory dati staging', is_dir($data) && !is_link($data) && self::childOf($data,$rootData) ? 'Rilevata' : 'Non verificata', 'Presenza di storage dedicato sotto /srv/venvia-data; permessi ancora da verificare.');
        $add('Socket PHP-FPM dedicato', file_exists($sock) && @filetype($sock)==='socket' ? 'Rilevato' : 'Non verificato', 'Non prova che il pool sia configurato con utente, sessioni e socket sicuri.');
        $add('Runtime principale', is_dir($main) && is_file($main.'/VERSION') ? 'Rilevato' : 'Non verificato', 'Versione e manifest della destinazione vanno confrontati prima del clone.');
        $add('Separazione directory', realpath($main)!==false && realpath($stage)!==false && realpath($main)!==realpath($stage) ? 'Percorsi distinti' : 'Da verificare', 'La distinzione dei percorsi non dimostra isolamento di utenti, ACL o mount.');
        $stageVersion=$stage.'/VERSION';
        $add('Manifest/versione staging',is_file($stageVersion) && is_readable($stageVersion) ? 'File presente' : 'Da verificare', 'Nessuna equivalenza con il Core è assunta automaticamente.');
        $add('Database e grant SQL','Non certificati','Richiede confronto read-only di database, utenti SQL e privilegi tramite canale tecnico autorizzato.');
        $add('Integrazioni esterne e cron','Non certificati','Verificare isolamento WooCommerce, POS, email, pagamenti, webhook, job e backup cloud.');
        $add('TLS dei sottodomini','Non certificato','HTTPS dei tre sottodomini deve essere controllato dalla chat Domini e con probe TLS indipendenti.');
        $add('Backup e restore','Non collaudati','Preflight di clonazione bloccato fino a test di restore consistente in ambiente isolato.');
        return $checks;
    }
    public static function page(): string {
        $checks=self::snapshot();
        $body='<section class="hero"><div class="eyebrow">CONTROL CENTER / AMBIENTI / DEV</div><h1>Staging VenVia</h1><p>Release 0.2.4.6-DEV — inventario e audit di isolamento preliminare di sola lettura. Non avvia copie o migrazioni.</p></section>';
        $body.='<section class="section-panel"><h2>Clone preflight — osservazioni</h2><p class="muted">Gli esiti «Rilevato» non costituiscono attestazione di sicurezza né abilitano la clonazione.</p><div class="metric-grid">';
        foreach($checks as $c){$body.=self::tile($c[0],$c[1],$c[2]);}
        $body.='</div></section>';
        $body.='<section class="section-panel"><h2>Audit isolamento 0.2.4.6</h2><p>Disponibile nel pacchetto il comando CLI <code>sudo php tools/isolation_audit_readonly.php</code> per controllare configurazione FPM, sessioni e indizi di isolamento. Non raccoglie password o token. Database e GRANT restano da validare con audit SQL dedicato; nessuna clonazione abilitata.</p></section>';
        $body.='<section class="section-panel"><h2>Verifiche obbligatorie prima della clonazione</h2><p>Restano da integrare nel Core: confronto manifest, isolamento SQL/FPM/sessioni, snapshot consistente, test restore, sanitizzazione dei dati, disabilitazione integrazioni esterne, MFA step-up, lock e audit.</p><p class="muted">Il comando di diagnostica locale in sola lettura è incluso nel pacchetto ZIP per l’Amministratore Tecnico.</p></section>';
        $body.='<section class="section-panel"><h2>Clona in staging</h2><p>Non disponibile: occorre collaudo end-to-end del motore di clonazione e delle autorizzazioni.</p><button type="button" disabled aria-disabled="true">Clona in staging — non disponibile</button></section>';
        $body.='<section class="section-panel"><h2>Migra su VenVia</h2><p>Non disponibile: rilascio immutabile, QA e approvazione MFA dall’ambiente principale non sono ancora implementati.</p><button type="button" disabled aria-disabled="true">Migra su VenVia — non disponibile</button></section>';
        $body.='<section class="section-panel"><h2>Endpoint staging previsti</h2><p><code>staging.control.venvia.it</code> · <code>staging.app.venvia.it</code> · <code>staging.api.venvia.it</code></p><p class="muted">Nessuna richiesta HTTP o connessione al database è eseguita da questa pagina.</p></section>';
        $body.='<p><a href="/">Torna alla panoramica tecnica</a></p>';
        return $body;
    }
}
