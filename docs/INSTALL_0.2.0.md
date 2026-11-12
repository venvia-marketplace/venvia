# Installazione VenVia 0.2.0-DEV (da 0.1.5)

1. Verificare SHA256 e `unzip -t`; verificare compatibilità PHP e database e backup **del codice e del DB** (inclusa possibilità di restore).
2. Estrarre in una directory temporanea, eseguire `php -l` su tutti i PHP; non copiare `.env`, password, log o dati dalla directory di staging.
3. Copiare la directory `venvia-0.2.0/.` in `/var/www/venvia/` con gli stessi permessi, **senza eliminare** file esistenti. Directory `plugins/` deve rimanere fuori da root web.
4. Tenere `app.venvia.it` non esposto fino al termine della configurazione; registrare e attivare vhost HTTPS specifico usando `docs/nginx-app.venvia.it.conf.example` come modello dopo confronto con Nginx attuale. Non sostituire automaticamente gli altri vhost.
5. Su control.venvia.it accedere con Amministratore Tecnico + MFA, entrare in Attivazione backend operativo, eseguire la migrazione additiva, generare invito.
6. Aprire app.venvia.it, inserire invito, creare Amministratore operativo **differente** con password/MFA e salvare Recovery Key offline.
7. Verificare logout/login separati e assenza di accesso dall'altro portale.

**Rollback:** conservare backup del codice; la migrazione 020 è additiva. Non cancellare tabelle operative senza procedura di ripristino verificata.
