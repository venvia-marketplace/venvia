# VenVia 0.4 — Technical and Operations Manual

Design baseline — 15 November 2026

[Italian version](../it/manuale-tecnico-operativo-0.4.md) · [Project overview](../../README.md)

**Sell and buy wherever and however you want.**

## 1. Purpose and status

VenVia is a multichannel, cross-platform commerce ecosystem, not an infrastructure project. Version 0.4 preserves the functional requirements of 0.2 while replacing the installation and data access architecture. Previously approved functionality must not regress without explicit approval.

This manual consolidates design decisions; it does not certify implemented software. Procedures describe requirements for future installers and modules. No 0.4 package has been built or tested as part of this document.

The first operational goal is VenVia Desk in Server mode, with a graphical installer, standalone PHP and MariaDB environment, runnable Core and secure initial setup. Target platforms are Windows, macOS and Linux; iOS and iPadOS are excluded.

## 2. Architecture and local autonomy

### One Core, two environments

- **VenVia Core:** object-oriented PHP engine, APIs, data model, authorization, transactions and module management. Independent of WordPress.
- **VenVia Desk Server:** installs a web server, PHP, MariaDB and Core on the selected business computer. No VM, dedicated hardware, WordPress or WooCommerce required. It may also serve as a workstation and POS. Only one authorized operational server per store.
- **VenVia Desk Client:** native application for Windows, macOS and Linux; discovers the LAN server or accepts a name/IP manually. Uses Core APIs, not direct database access. Functions depend on operator permissions rather than the machine's technical role.
- **VenVia Web:** optional environment on compatible WordPress hosting or a VPS; integrates Core and WooCommerce for online commerce, network coordination and authorized Desk access. WordPress manages its plugins; Core manages VenVia plugins. Future marketplace and customer apps use authorized APIs.

### Data flow

Desk Client ↔ Desk Server (LAN) ↔ VenVia Web (HTTPS when available) ↔ sales channels.

No mandatory direct links between stores. A store may operate independently and join the network later.

### Infrastructure constraints

No public domain, proprietary router, VPN, static public IP or manual hosts-file edits required at stores. A stable LAN IP is recommended; automatic discovery with manual fallback. Integrated local DNS, if retained, is optional and disabled by default. The server remains a local availability dependency; POS workstations also have an emergency mode.

## 3. Installation and initial setup

### Official packages

Windows: EXE installer. macOS: graphical package signed/notarized as applicable. Linux: graphical package for supported distributions; Ubuntu LTS is the initial reference to validate. Desk must offer Server and Client modes on all three platforms. Web: ZIP for an existing WordPress installation. macOS/Linux package formats and supported version matrix remain open.

### Required Server procedure

1. Launch the official package, select Server, then New Installation or Restore from Backup.
2. Check compatibility, disk space, permissions, ports, conflicts, existing services and data paths; never overwrite installations without authorization.
3. Install isolated components, create a database with unique least-privilege credentials, and register automatically starting services.
4. Configure company/store identity, server name, LAN address, data paths and backups. Create the Technical Administrator at first launch, without predefined users.
5. Enable MFA, generate the unique permanent Master Recovery Key for offline custody, verify recovery and complete setup.
6. Launch Control Center, check service health and prepare client pairing. Web connection is optional.

### Required Client procedure

Select Client, discover the server or enter its name/IP, verify its identity and authorize pairing. Sign in with credentials and MFA according to policy. Configure POS peripherals where applicable. Provide guided repair, updates and promotion to Server. No terminal use for routine operations.

## 4. Identity, security and access

### Roles and responsibilities

One Technical Administrator, separate from the Business Administrator, handles setup, maintenance, migration, updates, recovery and server promotion. Commercial roles: Cashier, Store Manager, Area Manager, Department Manager, Commercial Officer, Supervisor/Commercial Director, Buyer, Logistics Manager, Warehouse Operator and Accounting. Apply granular company/store/function permissions and preserve the affiliate responsibility transfers approved in 0.2.

### Local and web security

Mandatory MFA according to Core policy, strong passwords, controlled recovery, revocable sessions and audit logs. TLS on the LAN: proposed private identities/certificates with verified Desk pairing, without mandatory public certificates. No indiscriminate certificate acceptance. MariaDB must not be exposed to clients. Public Web requires trusted HTTPS and automatic renewal; ACME DNS-01 where applicable.

### Server exclusivity

Each store has a stable identity; each server has its own identity and revocable operational authorization. Proposed signed, progressively numbered server authority, retained by clients, rejecting earlier generations. The central server recognizes only the current instance when connected. A TLS certificate alone does not grant authority.

Offline promotion requires verified isolation of the old server and technical authorization. An isolated machine cannot receive revocations: do not promise absolute exclusivity during network partitions. Quarantine the former server; allow re-entry only as a client or through a newly authorized procedure.

## 5. Business functions and multichannel commerce

### Scope preserved from 0.2

- **VenVia Studio:** unified product records, codes/EAN, descriptions, images, videos, categories, variants and prices. Create from Desk or Web; explicit authorized online publication. Shared catalog with commercial data separated by ownership and permissions.
- **Sales and POS:** discounts within thresholds, below-cost sales subject to approval, fiscal cash registers, documents and sales recovery. The standalone Store Connector is discontinued; all its functions move into Desk, including a durable local queue and minimal emergency catalog. Fiscal compliance and devices must be tested before real use.
- **Logistics and inventory:** receipts, issues, stocktaking, adjustments by Area Manager or higher, weighted average cost, optional freight inclusion in cost, ownership-aware transfers, single or segmented warehouses. Accounting/statistics, technical support/repairs, new and used goods, marketing, social media and digital signage.
- **Affiliates:** local autonomy, separation of stock, costs, customers and documents; authorization rules up to Store Manager and transfers of responsibility involving Area Manager/Supervisor according to the approved structure. Preserve history and revoke obsolete access.

### Channels

WooCommerce is the initial online sales channel, not the master ERP. A proprietary marketplace remains the goal. Customers will be able to view availability by store (IKEA-style), buy online and use future apps. No unauthorized third-party plugins; explicitly approved exceptions may include payments, carriers and other authorized components.

## 6. APIs, synchronization and inventory

### API-first

Documented, versioned interfaces for catalog, stock, orders, customers, documents and modules. User/application authentication, least-privilege scopes, company isolation, audit and access limits. No external application queries MariaDB directly. Desk and Web share the data model and domain rules.

### Operations and performance

POS, Studio, accounting and logistics operate through the LAN server even without Internet. Synchronization is asynchronous and must not block local transactions. If the server fails, POS retains essential operations in a protected durable queue, with recovery and deduplication. Other functions may remain unavailable until recovery.

### Proposed synchronization implementation

Global IDs, atomic transactions, persistent events, idempotency, retries, checkpoints and reconciliation. Store servers initiate outbound HTTPS connections to Web. Define ownership of each data item and field-level conflicts; no indiscriminate database overwrites.

### Sellable availability

Protection of the display unit and the choice between a selected store and aggregated stock are approved requirements. Show availability by store, distinguish physical/sellable/reserved stock, and indicate the last update. During disconnection, do not promise real-time stock: online allocation quotas or suspension of sales against stale data are proposals awaiting approval. Reservations and reconciliation must prevent double sales to the extent connectivity permits.

## 7. Control Center and disaster recovery

### Technical functions

Control Center handles setup, configuration, service health, backups, updates, staging, migrations, rollback and recovery. Business backend installs official modules through an authorized process; controlled emergency removal with file access for the Technical Administrator. Do not require SFTP for Desk installations.

### Backup and promotion

Consistent, encrypted, verified backups restorable on a clean installation: database, media, documents, configuration, identities and versions. Configurable external destination; encrypted Google Drive backup was previously envisaged, and USB is optional for POS. Offline recovery key; retention, frequency, RPO/RTO and periodic checks to be defined.

**Restore as Server:** isolate the former server, verify backup and compatibility, restore on a compatible client, assign new authority, reconnect clients and reconcile operations occurring after the backup. This is not instant failover; operations after the last backup may be lost if unavailable in the queues.

### Staging and updates

**Clone to Staging:** preflight, verified backup, isolated clone, separate credentials/integrations, and preservation of the previous staging environment until a validated swap. Prevent real fiscal issuance, payments and publications.

**Promote to VenVia:** promote only tested and approved changes, with checkpoints, controlled migrations and preservation of live data. Never automatically replace the live database with staging data. Both operations must be reversible: automatic rollback where safe, authorized manual rollback and consistency checks.

## 8. GitHub, distribution and migration

### Open-source development

GitHub is the official platform for source code, issues, testing, releases and packages. GitHub App under the owner's exclusive control; contributors use their own accounts, forks and pull requests. No private credentials are distributed. Protected branches, review and owner authorization before releases. Open-source license and white-label terms remain to be formalized.

### Required pipeline

GitHub Actions builds on Windows, macOS and Linux runners; automated tests, native packages, dependency manifests, checksums, signing as applicable, and publication to Releases after approval. Web ZIP and versioned archives for every update/fix must include backup, installation, verification and rollback instructions. Preserve SCP transferability where used. No manual compilation for users.

GUI technology, packaging, signing, CPU architectures and peripheral compatibility must be determined through prototyping. C# and Inno Setup are not automatically binding choices.

### Migration from 0.2

Preserve the existing DEV environment and its data until the definitive Core is complete. Inventory components, versions, databases, documents, media, users and configuration. Export in a portable format, import into 0.4 staging, verify integrity and functions, approve and migrate with rollback. Also create a clean white-label installation of the same Core, without real store data or credentials.

Maintain bidirectional local/Web portability within compatible services and roles. Public certificates and environment-specific secrets must be handled or reissued securely; do not clone identities or credentials indiscriminately.

## 9. First development milestone: Desk Server

### P0 — installable prototype

1. Define the Core/Desk contract and repository structure.
2. Prepare the GitHub pipeline for all three platforms.
3. Build the launcher and Server installer with prerequisite checks, PHP/MariaDB/web server services and nondestructive uninstall.
4. Implement graphical setup, technical identity, MFA/recovery and minimal Control Center.
5. Add health and client pairing APIs.
6. Produce laboratory releases, not production releases.

### Acceptance criteria

- Clean installation and successful restart on supported Windows, macOS and Linux, without a VM or WordPress.
- Accessible Control Center, private database, unique technical administrator and verified recovery.
- Client pairing by name/IP and automatic discovery; rejection of unauthorized servers.
- LAN operation without Internet; multi-client latency and load benchmarks (thresholds to be defined before testing).
- External backup and restore to a second machine; rejection of the old server and POS queue recovery.
- Verified staging updates and rollback; reproducible, authenticatable official packages.

### Open decisions and verification

GUI stack, web server and packaging; OS/CPU versions; discovery and cryptographic trust; offline recovery/MFA; synchronization and inventory protocols; fiscal compatibility; licensing; performance and recovery targets. No test is declared passed. The manual is based on recovered decisions from 0.2–0.4 discussions, not a full comparison against the original 0.2 document.

## Security clarifications

Technical and business administrators use TOTP MFA (Google Authenticator). Other operators use email OTP at every login by default, with a globally configurable verification interval. Offline policy must be validated. The ordinary Recovery Key is single-use. The Master Recovery Key is permanent, exported once as TXT with confirmation of safe custody, and also enables backup decryption. Permanent loss of the Master Key makes encrypted backups unrecoverable. Additional copies are not mandatory. Never store the key in plaintext or logs.

