# VenVia 0.1.3 LAB — Prima installazione e recupero Amministratore Tecnico

**Solo DEV LAN, non esporre a Internet pubblico.**

## Cambiamenti
- Recupero esclusivo con Recovery Key offline da 256 bit, singolo utilizzo.
- Rotazione obbligatoria di password, TOTP e Recovery Key durante il recupero.
- Nessun secondo Amministratore Tecnico; ID 1 invariato.
- `credential_version` incrementato per revocare le vecchie sessioni nelle richieste successive.
- Audit del recupero riuscito, tentativi per IP limitati; completamento transazionale.
- Correzione CSP `img-src 'self'` per il logo originale.
- Testi e versione grafica LAB 0.1.3.

## Deploy controllato
1. Verificare SHA256 dell'archivio e `unzip -t` sul server.
2. Estrarre in staging, eseguire `php -l`, effettuare un backup reale dei file applicativi.
3. Verificare che DB `venvia` e `config.php` siano tuttora vuoti/assenti. Questa release NON è una migrazione automatica di DB già installato.
4. Copiare il contenuto dello staging nella directory applicativa, senza alterare il disco dati o Nginx.
5. Testare pagina setup e CSP in HTTPS. Non inserire credenziali definitive finché i test di sicurezza e restore non sono completi.

## Limitazioni importanti
- L'account di recupero non verifica l'email; soltanto la Recovery Key ad alta entropia permette il recupero.
- Perdita contemporanea di MFA e Recovery Key non è recuperabile in questa LAB.
- Il codice iniziale statico resta soltanto per il laboratorio LAN, e non prova universalmente la titolarità di un'installazione locale/cloud.
- Le sessioni precedenti sono revocate alla successiva richiesta grazie a `credential_version`; non viene garantita revoca delle operazioni già in corso.
- Non sono ancora operativi backup cifrato Google Drive, restore, migrazioni, aggiornamenti applicativi e Store Connector.
- Gli schemi CREATE IF NOT EXISTS non costituiscono un sistema di migrazione automatica per database già in uso.
- Eseguire una simulazione di recupero solo con un'installazione LAB sacrificabile, con backup verificato.
