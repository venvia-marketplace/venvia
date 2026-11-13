# VenVia 0.2.0-DEV — Handoff Backend + Frontend

## Contratto architetturale vincolante
- Un solo prodotto/Core PHP per locale/VPS/hosting, UI coerente col Design System 1.0.0.
- Control Center (control.venvia.it): primo bootstrap tecnico, MFA, Core/dipendenze, aggiornamenti, backup cifrati, ripristini, migrazioni locale↔cloud, ambienti, diagnostica. **Non installa plugin.**
- Backend operativo (app.venvia.it): utenze/ruoli operativi, catalogo, magazzino, vendite, POS, clienti/fornitori, **unico punto di gestione/installazione plugin**.
- API (api.venvia.it): da attivare per Store Connector e integrazioni, autenticazione macchina separata e idempotenza.
- Staging isolato: produzione→staging clona dati con protezione e disattivazione integrazioni; staging→produzione solo codice e migrazioni SQL esplicite, mai DB di staging.

## Disponibile in questa release
- `public/app.php`: front controller operativo con sessione e cookie distinti da Control, MFA TOTP, primo amministratore tramite invito di 20 min del Control Center.
- `src/Operational.php`: migrazione additiva e provisioning invito.
- `src/Environment.php`: diagnostica base in sola lettura (OS release, PHP, PDO MariaDB, spazio filesystem); gli altri stati dichiarati non disponibili.
- `database/migrations/020_operational.sql`: tabelle utenti operativi, inviti, rate limiting e registro plugin; nessuna modifica alle tabelle precedenti.
- `plugins/` privata fuori da public e gestione plugin nel BE **predisposta ma non eseguibile**.
- Operazioni di aggiornamento Ubuntu, PHP/MariaDB/Nginx, rinnovo TLS e lettura log: **non eseguibili** finché non è sviluppato e collaudato il System Agent privilegiato, con allowlist di comandi, aggiornamenti firmati, preflight/backup, audit e rollback.

## Priorità immediata Backend + Frontend
1. Autenticazione utenti operativi con ruoli granulari, MFA e recupero sicuro, gestione inviti, scadenze e revoche.
2. Catalogo unificato (prodotti nuovi/usati, varianti, barcode, prezzi e categorie), negozi, giacenze per proprietario.
3. Importatore XLSX Edomea con mapping, dry-run, report errori e riconciliazione delle giacenze.
4. Vendita/cassa, resi, pagamenti, documenti e audit.
5. Plugin manager nel backend operativo: pacchetti firmati/versionati, staging, backup, dipendenze, compatibilità, attivazione/disattivazione, gestione crash via SFTP.
6. API versionate e Store Connector, operatività offline con riconciliazione.

## Attenzione
Questa è una base di sviluppo **non pronta per produzione**. Manca recupero sicuro dell'operativo, verifica email, verifica reale backup/restore e migrazione, autorizzazioni complete, idoneità fiscale/POS e molti test. Non attivare public Internet prima di hardening.
