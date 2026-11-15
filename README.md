# VénVia — Ecosistema gestionale multicanale

**Vendi e compra dove vuoi e come vuoi.**

VenVia è un progetto open source in sviluppo per la gestione integrata di negozi, vendite fisiche e online, catalogo, magazzino, logistica, contabilità e assistenza. L'obiettivo è garantire autonomia operativa locale, rapidità e interoperabilità con piattaforme commerciali presenti e future.

## Architettura 0.4
| Componente | Funzione |
|---|---|
| VenVia Core | Motore PHP indipendente, API versionate e moduli |
| VenVia Desk Server | Ambiente locale PHP/MariaDB, operativo senza Internet |
| VenVia Desk Client | Postazioni Windows, macOS e Linux con accesso autorizzato via LAN |
| VenVia Web | Integrazione WordPress/WooCommerce opzionale, e-commerce e coordinamento negozi |
| VenVia Studio | Catalogo e pubblicazione multicanale |

Lo Store Connector confluisce in Desk. Un solo server autorizzato per negozio; niente hardware dedicato obbligatorio, domini pubblici locali o configurazioni manuali del router.

## Documentazione
- [Manuale tecnico operativo 0.4 (italiano)](docs/it/manuale-tecnico-operativo-0.4.md)

- [Technical and Operations Manual 0.4 (English)](docs/en/technical-operations-manual-0.4.md)

## Roadmap
Prima priorità: Desk Server, installer grafici, setup sicuro e Control Center. Seguono associazione client, backup/ripristino, staging, sincronizzazione e moduli commerciali. GitHub Actions dovrà compilare e testare gli installer sulle tre piattaforme.

## Stato e contributi
Baseline progettuale 0.4 — 15 novembre 2026. Funzioni e installer descritti sono obiettivi di sviluppo, non una release collaudata. Contributi tramite issue, fork e pull request, con revisione prima della distribuzione. Licenza open source e condizioni white label da formalizzare.

Gli installer ufficiali saranno liberamente scaricabili, con verifica di integrità e autenticità; la cifratura protegge i dati e i backup, non il codice sorgente.

## English project overview

VenVia is an open-source project in development for multichannel retail and commerce. A single independent PHP Core powers Desk Server and Client on Windows, macOS and Linux, with optional WordPress/WooCommerce integration through VenVia Web. Local stores operate independently of Internet access; authorized APIs enable synchronization, e-commerce and future applications. The first milestone is a graphical Desk Server installer, secure setup and Control Center. Version 0.4 is a design baseline, not a tested release. License and white-label terms remain to be finalized. Contributions are welcome through issues, forks and pull requests.
