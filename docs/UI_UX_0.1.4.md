# VenVia 0.1.4-LAB — Integrazione UI/UX

Origine grafica: VenVia_Design_System_1.0.0_2026-11-06. Logo ufficiale originale **non ricolorato**.

- Solo vista `Core::html()` e dashboard di presentazione, CSS, JavaScript del tema e asset grafici.
- Nessuna modifica a `Bootstrap.php`, `schema.sql`, `private/bootstrap.php`, autenticazione, MFA o recupero.
- Le sezioni infrastrutturali rimangono non operative: visualizzazione onesta degli stati, nessun sistema monitorato fittiziamente.
- `public/assets/venvia-logo.png` contiene la variante trasparente ufficiale, su superficie bianca per garantire leggibilità in tema scuro.
- Token UI dal design system v1.0.0; stato di baseline ancora da validare definitivamente con audit WCAG.
- Nuove risorse `/assets/favicon.ico`, `/assets/venvia-tokens.css`, `/assets/style.css`, `/assets/theme.js`.
- Nessuna modifica di file fuori da `/var/www/venvia` e nessun requisito di nuovi permessi.
- AVVERTENZA: questa è una release LAB; non esporre a Internet pubblico.

## Aggiornamento controllato
Non estrarre direttamente sopra la produzione senza staging e copia di sicurezza. Confrontare prima i file personalizzati. Distribuire su un ambiente isolato, eseguire `php -l` su tutti i PHP e verificare anche il login e il recupero. Eseguire test di browser, accessibilità e cache.
