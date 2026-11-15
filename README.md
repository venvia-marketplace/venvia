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

## Roadmap
Prima priorità: Desk Server, installer grafici, setup sicuro e Control Center. Seguono associazione client, backup/ripristino, staging, sincronizzazione e moduli commerciali. GitHub Actions dovrà compilare e testare gli installer sulle tre piattaforme.

## Stato e contributi
Baseline progettuale 0.4 — 15 novembre 2026. Funzioni e installer descritti sono obiettivi di sviluppo, non una release collaudata. Contributi tramite issue, fork e pull request, con revisione prima della distribuzione. Licenza open source e condizioni white label da formalizzare.

Gli installer ufficiali saranno liberamente scaricabili, con verifica di integrità e autenticità; la cifratura protegge i dati e i backup, non il codice sorgente.
