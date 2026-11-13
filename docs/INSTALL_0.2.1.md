# VenVia 0.2.1-DEV — Installazione su ambiente DEV esistente

**Prerequisiti:** 0.2.0-DEV già installata e migration `020_operational.sql` applicata; Amministratore Tecnico con MFA attivo. Prima della distribuzione verificare ZIP, hash e sintassi PHP. Non eseguire `schema.sql` e non azzerare DB.

1. Backup del Core attuale e dei dati applicativi (compresi config e DB). Il precedente dump SQL può essere strutturalmente completo, ma non sostituisce un test di restore. Non pubblicare backup e credenziali. Verificare anche che la configurazione del vhost `control.venvia.it` sia intatta.
2. Estrai ZIP in staging locale separato e verifica tutti i `.php` con `php -l`.
3. Copia il contenuto di `venvia-0.2.1/` in `/var/www/venvia/`, senza cancellare `/srv/venvia-data/system/control`.
4. Directory SFTP/WEB plugin: `/var/www/venvia/plugins`, **fuori da public**. Mantieni proprietario `antonio` e gruppo `www-data` e permetti a entrambi accesso tramite ACL. Esempio su Ubuntu DEV (adattare al sistema):
```
sudo mkdir -p /var/www/venvia/plugins
sudo chown antonio:www-data /var/www/venvia/plugins
sudo chmod 2770 /var/www/venvia/plugins
sudo setfacl -m u:antonio:rwx,u:www-data:rwx,d:u:antonio:rwx,d:u:www-data:rwx /var/www/venvia/plugins
```
Per file già installati, verificare ACL su **tutti** i discendenti con `getfacl`/`namei -l` e correggere eventuali anomalie; non applicare chmod ricorsivi indiscriminati. La directory temporanea privata dei caricamenti è `/srv/venvia-data/system/control/plugin-upload/` (creata dal web, accessibile solo a `www-data`).
5. Verifica `php -m | grep -i '^zip$'`: la libreria `php8.5-zip` è necessaria per caricare i file ZIP; va installata dal sistema operativo solo se assente. Verifica `upload_max_filesize` e `post_max_size` per il limite effettivo; **non modificarli se non necessari**.
6. Controlla `https://control.venvia.it/?page=plugins` dopo login tecnico. Il backend `app.venvia.it` si integra solo se il suo Nginx vhost è effettivamente attivo; il percorso `/backend` dipende da una regola `try_files` verso `app.php`.

**Attenzione:** un programma web che scrive direttamente la directory dei plugin non è una soluzione adeguata per un'installazione pubblica senza ulteriori controlli. L'agente di distribuzione con privilegi minimi, firme e rollback resta un requisito di produzione.

**Rollback rapido codice:** ripristinare lo snapshot della directory dell'applicazione e conservare DB/config; questa release non ha migrazioni database nuove. Le eventuali installazioni plugin già effettuate devono essere esaminate prima del rollback.
