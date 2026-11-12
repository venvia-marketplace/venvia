# VenVia 0.1.5-LAB — MFA con QR offline

- QR TOTP visualizzato durante il primo setup e durante recupero account.
- Libreria JavaScript QR locale: nessuna richiesta a servizi esterni.
- Nessun cambiamento a schema SQL, chiavi generate, recupero, credenziali, Nginx o certificati.
- Se la sessione MFA è scaduta (15 minuti), è necessario ripetere il setup; non rimuovere configurazioni a mano.
- Il QR è un segreto: non effettuare screenshot o registrazioni dello schermo.
- Il QR è generato dall'URI TOTP già presente nella schermata.
- Per l'installazione sopra 0.1.4 copiare SOLO public/index.php, public/assets/style.css, public/assets/totp-qr.js e src/Core.php.
- Gli altri file sono inclusi per completezza della release, ma non devono essere sovrascritti senza procedura di rilascio.

LAB: rimangono da collaudare integrazione MFA end-to-end, recupero, sicurezza della prima installazione, backup e migrazione. Non usare in produzione.
