# Checklist collaudo manuale DEV (prima di consegnare i plugin)

1. PHP `zip` attivo, directory plugin leggibile via SFTP `antonio` e scrivibile da PHP-FPM `www-data`; la directory non deve essere servita da Nginx.
2. Control Center: login tecnico MFA; pagina Plugin Manager visibile e protetta da sessione; caricamento ZIP solo con CSRF.
3. Carica un pacchetto conforme SDK: verifica manifesto e SHA256; conferma MFA, installa disattivato, poi attiva.
4. Apri `app.venvia.it/?module=<id>` e `app.venvia.it/backend?module=<id>` con un account gestionale abilitato. Un utente non amministratore NON deve poter aprire la rotta backend.
5. Disattiva: le rotte non devono essere eseguibili. Verifica che file e database siano conservati.
6. Simula via SFTP il rinominare la directory del plugin: il Core deve segnalare file mancanti, senza perdere account tecnico o configurazioni di altri plugin. Ripristina la directory prima di riattivare.
7. Prova ZIP con `../`, file sconosciuti, link simbolici, manifesto mancante, versione errata e duplicati; devono essere rifiutati. Prova con PHP zip disabilitato e verifica messaggio esplicito.
8. Verifica aggiornamento con versione più alta a plugin disattivato e blocco di downgrade/dipendenze mancanti.
9. Controlla log applicativi e audit `vv_audit`; verifica il backup manuale dei dati prima di prove distruttive.

**Limite fondamentale:** test PHP lint e ZIP integrity di questa release non equivalgono a test funzionali sul server; collaudo completo richiesto prima di considerare il Plugin Manager pronto.
