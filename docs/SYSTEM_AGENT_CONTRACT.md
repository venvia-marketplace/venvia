# VenVia System Agent — contratto tecnico (SPECIFICA, NON INSTALLATO)

## Principi
- Il processo HTTP (PHP-FPM) non esegue `sudo`, shell, `apt`, `systemctl`, `certbot`, né legge indiscriminatamente i log di sistema.
- Un agente separato, gestito dal sistema operativo, esporrà un canale locale autenticato (Unix socket con controllo permessi + messaggi firmati/nonce e replay protection). Nessun endpoint pubblico o porte aggiuntive.
- Allowlist stretta di azioni predefinite, argomenti tipizzati, nessun input trasformato direttamente in un comando shell, niente privilegi permanenti a PHP-FPM.
- L'agente potrà verificare stato, versioni, pacchetti aggiornabili, servizi, certificati e risorse; interventi critici richiedono conferma MFA fresca, job schedulato, audit e checkpoint prima dell'esecuzione.

## Funzioni previste
- `system.inventory` / `system.updates.scan` (read-only)
- `php.inventory`, `database.inventory`, `webserver.inventory`, `service.health`, `tls.certificate.status`
- `logs.query` (filtri sicuri, redazione segreti, limiti di righe, scadenza, accesso solo autorizzato)
- `maintenance.preflight` (spazio, integrità backup consistente, health check, compatibilità plugin)
- `maintenance.packages.apply` (installazione di aggiornamenti per release consentite; gestione riavvio e conferma post-avvio)
- `maintenance.os.release-upgrade` (procedura separata, **non abilitata automaticamente**; richiede snapshot o recovery dell'intero sistema)
- `tls.renew` (tramite client ACME ufficiale e challenge DNS-01 già configurata)

## Sicurezza/portabilità
- Su VPS/server Ubuntu con privilegi adeguati: agente privilegiato dedicato.
- Hosting condiviso: solo i controlli consentiti; aggiornamenti OS/PHP/DB sotto responsabilità del provider, con visualizzazione e avvisi.
- Nessun aggiornamento automatico in 0.2.0. UI mostra esplicitamente "non disponibile".
- Controllo qualità: test su staging isolato, produzione→staging dati protetti; staging→produzione pacchetto codice/SQL approvato, mai database staging.
