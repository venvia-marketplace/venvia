# VenVia 0.2.0-DEV — release di predisposizione

Evoluzione non distruttiva dalla 0.1.5-LAB; mantiene asset e flusso tecnico già attivi.

## Aggiunto
- Front controller `public/app.php` per Backend Operativo su vhost HTTPS separato.
- Prima creazione Amministratore operativo con token monouso generato dal Control Center e MFA TOTP; cookie di sessione separato.
- Migrazione SQL **additiva** tramite pulsante autenticato nel Control Center: tabelle operative, inviti e plugin registry.
- Dashboard operativa dimostrativa, plugin registry in sola lettura.
- Diagnostica base (versione PHP, estensioni, identificazione OS, versione MariaDB e spazio disco) visibile al solo Amministratore Tecnico.
- Specifiche per futuro System Agent privilegiato; aggiorna ambiente e certificati **non ancora operativo**.
- Design System VenVia 1.0.0 riutilizzato.

## Limiti e avvisi
- NON pronto per vendite, cassa, dati fiscali o uso Internet pubblico.
- Un solo utente operativo Amministratore, provisioning successivi ruoli/user CRUD e recovery NON ancora abilitati.
- Recovery Key operativa emessa e salvata come hash, ma **manca la procedura self-service di recupero**; collaudo obbligatorio prima di produzione.
- Verifica email, reset credenziali, sistema di update/backup, staging e agent privilegiato ancora da implementare.
- SQL migrazione DDL non completamente atomico; fare backup DB prima dell'operazione. Ripetibile con CREATE TABLE IF NOT EXISTS.
- La UI plugin **non installa ancora plugin**. L'unico punto autorizzato per questa funzione, una volta implementata, sarà il Backend Operativo.
