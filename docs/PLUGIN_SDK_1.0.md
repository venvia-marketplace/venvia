# VenVia Plugin SDK 1.0 — Contratto minimo 0.2.1-DEV

**Destinatari:** tutte le chat che producono plugin VenVia. Il pacchetto ZIP deve contenere `plugin.json` nella sua radice, non dentro un'altra cartella.

## Manifest di esempio
```json
{
  "id": "venvia-interface",
  "name": "VenVia Interface",
  "version": "0.1.0",
  "sdk": "1.0",
  "min_core": "0.2.1",
  "min_php": "8.3.0",
  "dependencies": [],
  "routes": {"frontend": "frontend.php", "backend": "backend.php"}
}
```
Il plugin può includere `.php`, `.css`, `.js`, `.json`, `.html`, `.md`, `.txt`, immagini web. **Non sono consentiti symlink o file fuori dalla radice ZIP.** Lo ZIP non può superare 20 MiB (o il limite PHP più basso).

## Interfaccia dei plugin
Le rotte dichiarate nel manifesto sono file PHP che devono **restituire una stringa HTML** con `return $html;` (non fare `echo`). Il file riceve `$context` con `username`, `role`, `csrf`, `area` (`frontend` o `backend`). Le rotte vengono eseguite soltanto dopo autenticazione operativa, controllo che il plugin sia attivo e verifica della disponibilità dei file. La rotta di backend richiede in aggiunta ruolo `administrator` (vedi limitazione indicata sotto).

URL di accesso nel backend attuale:
- `https://app.venvia.it/?module=venvia-interface`
- `https://app.venvia.it/backend?module=venvia-interface` (**richiede vhost Nginx con try_files verso app.php**).

Asset privati serviti a utenti autenticati via `/?plugin_asset=venvia-interface&file=assets/style.css`. Tutti gli output HTML **devono sfuggire/validare i dati utente**; non implementare login, sessioni o verifiche permessi autonome. Non modificare Core.php, app.php, index.php, migrazioni o il codice di altri plugin. Le risorse per plugin e i nomi devono essere univoci.

## Ciclo di vita
`Control Center → Plugin → Carica ZIP → Verifica manifesto/SHA256 → Installa (disattivato) → Attiva`. Ogni variazione richiede MFA dell'Amministratore Tecnico. Gli aggiornamenti richiedono prima disattivazione e una versione SemVer superiore. Disattivazione non cancella file/dati; rimozione cancella **solo i file** e la voce del registro. **Nessuna migrazione SQL del plugin è eseguita automaticamente in questa release.** Chiedere modifiche del Core nelle chat Core/Control, anziché implementare accessi arbitrari ai dati.

## Sicurezza / limiti di DEV
Il caricamento esegue codice PHP attendibile dopo l'attivazione. Mancano firma obbligatoria, scansione approfondita del codice, staging automatico, backup/rollback verificati, autorizzazioni granulari dei plugin e sandbox. Non utilizzare su server Internet pubblici o in produzione. Il manifesto è una dichiarazione, non un'autorizzazione di capacità. Il sistema di routing è un contratto SDK **minimo e provvisorio**; gli altri moduli devono evitare dipendenze non documentate. Il runtime dovrà essere rafforzato prima della sostituzione del gestionale.
