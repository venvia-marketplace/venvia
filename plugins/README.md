# Plugin VenVia (v0.2.1-DEV)
Directory privata: `/var/www/venvia/plugins/`, **mai** sotto `public/`.
L'installazione quotidiana avviene dal Control Center, pagina `/?page=plugins`, tramite ZIP.
SFTP (`antonio`) è dedicato al recupero: rimozione/renomina di una directory fa fallire la sua attivazione, senza cancellare il registro DB e i dati.
ATTENZIONE: PHP di un plugin attivo è codice attendibile che gira con i privilegi dell'applicazione. In questa release DEV non sono disponibili firma obbligatoria, sandbox, backup automatico, rollback transazionale o staging automatico; usare solo su DEV LAN e pacchetti verificati.

Non inserire credenziali o dati sensibili nelle directory plugin.
