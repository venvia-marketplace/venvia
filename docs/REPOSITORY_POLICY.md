# VenVia — Policy repository (bozza)

## Branch
- `main`: sorgenti di release approvate, da proteggere.
- `develop`: integrazione delle modifiche in sviluppo.
- `feature/*` e `fix/*`: modifiche isolate con pull request.
- Release tag: `vX.Y.Z`.

## Sicurezza
- Richiedere PR e verifiche prima del merge su `main`; vietare force-push.
- Integrare lint PHP, test e scansione segreti.
- Non distribuire automaticamente modifiche da GitHub a VenVia.
- Non pubblicare password, chiavi, configurazioni locali, dump DB o documenti operativi.
- Il file `.gitignore` è solo una difesa aggiuntiva: controllare i file prima del commit.

## Rilasci
1. Modifiche su branch dedicati.
2. Revisione e test di sicurezza/compatibilità.
3. ZIP versionato con manifest, changelog, controlli, backup e rollback.
4. Collaudo su staging tramite Control Center «Clona in staging».
5. Promozione approvata tramite «Migra su VenVia» con checkpoint verificati e rollback.
6. Non sostituire mai il DB operativo con una copia staging.

## Compatibilità
Prima di importare il Core esistente, verificare la struttura reale di `/var/www/venvia`. Non cambiare i percorsi del server sulla base di questa bozza.
