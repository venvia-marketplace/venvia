# VenVia 0.2.1-DEV — Plugin Manager
- Control Center `/?page=plugins`: caricamento ZIP con anteprima manifesto + SHA256 e MFA per installazione, attivazione, disattivazione, aggiornamento (solo da disattivato), rimozione del codice.
- Valida percorsi ZIP, symlink, duplicati e limiti dimensionali; pacchetti non si eseguono durante l'upload.
- Directory SFTP privata `/var/www/venvia/plugins` e staging di upload protetto sotto `/srv/venvia-data/system/control`.
- Backend operativo: hook minimi per rotte frontend e backend di plugin attivi e distribuzione di risorse statiche a utenti autenticati.
- SDK 1.0 minimo nel documento `PLUGIN_SDK_1.0.md`.
- Nessuna nuova migrazione DB. Compatibile con le 5 tabelle già create nella 0.2.0.
- **DEV non pronto per produzione:** firme obbligatorie, backup automatici, test staging automatico, rollback verificato, separazione dei privilegi della distribuzione, ACL granulari, retention/diagnostica avanzata non implementati.
