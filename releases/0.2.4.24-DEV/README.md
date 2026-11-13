# VenVia 0.2.4.24-DEV — GitHub Verified Candidate

Aggiunge la preparazione privata di una release candidata proveniente dalla GitHub App in sola lettura.

- Nessuna installazione nello staging web.
- Nessuna promozione in produzione.
- Nessuna modifica ai database.
- Verifica asset GitHub SHA-256 e manifest interno.
- Il deploy in produzione via SCP è transitorio e richiede preflight, backup e rollback.

Il pacchetto di installazione è distribuito separatamente come ZIP di transizione. Non creare release approvate prima di completare il controllo di identità del commit, i test di download effettivi e il workflow di approvazione. Vedere il README nello ZIP per comandi.

**Attenzione**: il downloader rifiuta redirect esterni; il download di asset GitHub può quindi essere bloccato in maniera sicura fino a quando il gestore dei redirect ammessi sarà implementato e collaudato.