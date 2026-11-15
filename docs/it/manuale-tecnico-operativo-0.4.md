# VenVia 0.4 — Manuale tecnico operativo

Trascrizione testuale del manuale del 15 novembre 2026, con correzione della politica Master Recovery Key secondo le decisioni approvate.



VenVia 0.4
Manuale tecnico-operativo
Baseline di progettazione • 15 novembre 2026

Vendi e compra dove vuoi e come vuoi.

01 / Finalità e stato
VenVia è un ecosistema commerciale multicanale e multipiattaforma, non un progetto di infrastruttura. La
versione 0.4 recupera i requisiti funzionali della 0.2 e sostituisce l’architettura di installazione e accesso ai
dati. Le funzionalità già approvate non devono regredire salvo decisione esplicita.

Questo manuale consolida le decisioni progettuali disponibili; non certifica software già realizzato. Le
procedure descrivono il comportamento richiesto agli installer e ai moduli futuri. Nessun pacchetto 0.4 è
stato compilato o collaudato nell’ambito di questo documento.

Indice
02 Architettura e autonomia locale
03 Installazione e prima configurazione
04 Identità, sicurezza e accesso
05 Funzioni gestionali e multicanalità
06 API, sincronizzazione e giacenze
07 Control Center e disaster recovery
08 GitHub, distribuzione e migrazione
09 Primo sviluppo: Desk Server e collaudo

Primo obiettivo operativo
Realizzare VenVia Desk configurabile in modalità Server, con installazione grafica, ambiente PHP e MariaDB
autonomo, Core avviabile e prima configurazione sicura. Windows, macOS e Linux sono le piattaforme
obiettivo; iOS e iPadOS non rientrano nel perimetro Desk.







02 / Architettura e autonomia locale

Un Core, due ambienti
VenVia Core: motore PHP orientato agli oggetti, API, modello dati, autorizzazioni, transazioni e gestione dei
moduli. Il Core non dipende da WordPress.

VenVia Desk Server: installa web server, PHP, MariaDB e Core sulla macchina aziendale scelta. Non richiede
VM, hardware dedicato, WordPress o WooCommerce. Può essere contemporaneamente postazione
operativa e cassa. Un solo server operativo autorizzato per negozio.

VenVia Desk Client: applicazione nativa per Windows, macOS e Linux; individua il server in LAN o accetta
nome/IP manuale. Accede alle API del Core, non direttamente al database. Le funzioni dipendono dai
permessi dell’operatore, non dal ruolo tecnico della macchina.

VenVia Web: ambiente opzionale su hosting WordPress compatibile o VPS; integra Core e WooCommerce
per commercio online, coordinamento della rete e accesso autorizzato anche da Desk. WordPress gestisce i
propri plugin, il Core quelli VenVia. Il futuro marketplace e le app clienti utilizzano API autorizzate.

Flusso dati
Desk Client ↔ Desk Server (LAN) ↔ VenVia Web (HTTPS, quando disponibile) ↔ canali commerciali.
Nessun collegamento diretto obbligatorio fra negozi. Un negozio può essere autonomo e associarsi
successivamente alla rete.

Vincoli infrastrutturali
Nessun dominio pubblico, router proprietario, VPN, IP pubblico statico o modifica manuale dei file hosts in
negozio. IP LAN stabile consigliato; ricerca automatica con fallback manuale. DNS locale integrato, se
mantenuto, opzionale e disattivato di default. Il server resta un punto di disponibilità locale: le casse
dispongono anche di una modalità di emergenza.







03 / Installazione e prima configurazione

Pacchetti ufficiali
Desk Windows: installer EXE. Desk macOS: pacchetto grafico firmato/notarizzato secondo i requisiti
applicabili. Desk Linux: pacchetto grafico per distribuzioni supportate; Ubuntu LTS primo riferimento da
validare. Desk deve offrire modalità Server e Client su tutte e tre le piattaforme. VenVia Web: ZIP per
WordPress esistente. Formati macOS/Linux e matrice versioni ancora da definire.

Procedura Server richiesta
1. Avviare il pacchetto ufficiale e scegliere Server, quindi Nuova installazione o Ripristina da backup.
2. Verificare compatibilità, spazio, permessi, porte, conflitti, servizi esistenti e percorso dati; non
   sovrascrivere installazioni senza autorizzazione.
3. Installare componenti isolati, creare database con credenziali uniche e limitate, registrare servizi ad avvio
   automatico.
4. Configurare identità azienda/negozio, nome server, indirizzo LAN, percorsi dati e backup. Creare
   l’Amministratore Tecnico al primo avvio, senza utenti predefiniti.
5. Attivare MFA, generare la Master Recovery Key unica e permanente da custodire offline, verificare recupero e
   completare il setup.
6. Avviare Control Center, controllare salute dei servizi e predisporre l’associazione dei client. Collegamento
   Web facoltativo.

Procedura Client richiesta
Selezionare Client, rilevare il server oppure inserirne nome/IP; verificare l’identità e autorizzare
l’associazione. Accedere con credenziali e MFA secondo le policy. Configurare eventuali periferiche di cassa.
Consentire riparazione, aggiornamento e promozione a Server tramite procedura guidata. Nessun terminale
per le operazioni ordinarie.







04 / Identità, sicurezza e accesso

Ruoli e responsabilità
Amministratore Tecnico unico, separato dall’Amministratore gestionale: setup, manutenzione, migrazioni,
aggiornamenti, ripristini e promozione server. Ruoli commerciali: Cassiere, Store Manager, Area Manager,
Responsabile Reparto, Commerciale, Supervisor/Direttore Commerciale, Buyer, Responsabile Logistica,
Magazziniere e Contabilità. Applicare permessi granulari, per azienda, negozio e funzione; mantenere i
passaggi di competenza degli affiliati approvati nella 0.2.

Sicurezza locale e web
MFA obbligatoria secondo le policy del Core, password robuste, recovery controllato, sessioni revocabili, log
di audit. TLS anche in LAN: proposta di identità/certificati privati con associazione verificata nell’app Desk,
senza certificato pubblico obbligatorio. Nessuna accettazione indiscriminata di certificati. MariaDB non
esposto ai client. Per Web pubblico, HTTPS con certificato attendibile e rinnovo automatico; ACME DNS-01 se
applicabile.

Esclusività del server
Ogni negozio ha identità stabile; ogni server ha identità propria e autorizzazione operativa revocabile.
Proposta: generazione progressiva firmata dell’autorità server, conservata anche dai client, con rifiuto di
generazioni precedenti. Il server centrale riconosce solo l’istanza vigente quando connesso. Il certificato TLS
da solo non conferisce autorità.

La promozione offline richiede isolamento verificato del vecchio server e autorizzazione tecnica. Una
macchina isolata non può ricevere revoche: non promettere esclusività assoluta durante una partizione di
rete. Il server precedente va posto in quarantena e può rientrare solo come client o mediante nuova
procedura autorizzata.







05 / Funzioni gestionali e multicanalità

Perimetro da preservare dalla 0.2
VenVia Studio: anagrafica unica, codici/EAN, descrizioni, immagini, video, categorie, varianti, prezzi.
Creazione da Desk o Web; pubblicazione online esplicita e autorizzata. Catalogo comune, dati commerciali
separati secondo proprietà e permessi.

Vendite e cassa: POS, sconti entro soglia, sottocosto con approvazione, registratore telematico, documenti e
recupero delle vendite. Store Connector non esiste più autonomamente: tutte le sue funzioni confluiscono in
Desk, inclusa la coda locale durevole e la copia minima del catalogo per emergenze. Fiscalità e dispositivi da
collaudare prima dell’uso reale.

Logistica e magazzino: carichi, scarichi, inventario, rettifiche da Area Manager in su, costo medio ponderato,
trasporto opzionale nel costo, trasferimenti secondo proprietà. Gestione monomagazzino o segmentata;
contabilità e statistiche, assistenza tecnica/riparazioni, nuovo/usato, marketing, social e signage.

Affiliati: autonomia locale, separazione di giacenze, costi, clienti e documenti; regole autorizzative fino a
Store Manager e trasferimenti di competenza Area Manager/Supervisor secondo assetto approvato.
Conservare storico e revocare accessi non più spettanti.

Canali
WooCommerce è il canale web iniziale, non il gestionale master. Il marketplace proprietario resta obiettivo. Il
cliente potrà consultare disponibilità per negozio (modello IKEA), acquistare online e usare future app.
Nessun uso di plugin terzi non autorizzati; eventuali eccezioni approvate per pagamenti, corrieri e altri
componenti espliciti.







06 / API, sincronizzazione e giacenze

API-first
Interfacce documentate e versionate per catalogo, giacenze, ordini, clienti, documenti e moduli.
Autenticazione per utente/applicazione, scope minimi, isolamento aziende, audit e limiti di accesso. Nessuna
app esterna interroga direttamente MariaDB. Il modello dati e le regole di dominio sono condivisi tra Desk e
Web.

Operatività e velocità
Casse, Studio, contabilità e logistica operano sul server LAN anche senza Internet. La sincronizzazione è
asincrona e non deve bloccare le transazioni locali. In caso di guasto del server, la cassa conserva le
operazioni essenziali in una coda durevole protetta, con recupero e deduplicazione. Le altre funzioni possono
restare indisponibili fino al ripristino.

Sincronizzazione proposta da implementare
Identificativi globali, transazioni atomiche, eventi persistenti, idempotenza, retry, checkpoint e
riconciliazione. Il server del negozio comunica con Web tramite connessioni HTTPS in uscita. Definire la
proprietà di ogni dato e i conflitti per campo; nessuna sovrascrittura indiscriminata del database.

Disponibilità vendibile
Già approvata la protezione del pezzo esposto e la scelta tra negozio selezionato e disponibilità aggregata.
Mostrare giacenze per negozio, distinguere fisico/vendibile/riservato e indicare l’ultimo aggiornamento.
Durante disconnessioni, non promettere stock in tempo reale: proposta di quote online o sospensione delle
vendite sui dati scaduti, da approvare. Le prenotazioni e la riconciliazione devono evitare doppie vendite per
quanto consentito dalla connettività.







07 / Control Center e disaster recovery

Funzioni tecniche
Control Center gestisce setup, configurazioni, salute servizi, backup, aggiornamenti, staging, migrazioni,
rollback e ripristino. Backend gestionale installa moduli ufficiali tramite flusso autorizzato; rimozione di
emergenza controllata, con accesso ai file per il Tecnico. Non rendere SFTP un requisito delle installazioni
Desk.

Backup e promozione
Backup consistenti, cifrati, verificati e ripristinabili su installazione pulita: database, media, documenti,
configurazioni, identità e versioni. Destinazione esterna programmabile; Google Drive cifrato previsto in
precedenza, USB opzionale per le casse. Recovery key offline; definire conservazione, frequenza, RPO/RTO e
verifica periodica.

Ripristina come Server: isolare vecchio server, verificare backup e compatibilità, ripristinare su client
compatibile, attribuire nuova autorità, riallacciare gli altri client e riconciliare operazioni successive al backup.
Non è failover istantaneo; possibili perdite dopo l’ultimo backup se non recuperabili dalle code.

Staging e aggiornamento
«Clona in staging»: preflight, backup verificato, clone isolato, credenziali e integrazioni separate,
preservazione dello staging precedente fino a swap validato. Evitare emissioni fiscali, pagamenti e
pubblicazioni reali.

«Migra su VenVia»: promuovere solo modifiche collaudate e approvate, con checkpoint, migrazioni dati
controllate e preservazione dei dati reali. Mai sostituire automaticamente il DB live con quello di staging.
Entrambe le operazioni reversibili: rollback automatico ove sicuro e manuale autorizzato, con verifica di
consistenza.







08 / GitHub, distribuzione e migrazione

Sviluppo open source
GitHub come piattaforma ufficiale per codice, issue, test, release e pacchetti. GitHub App sotto controllo
esclusivo del titolare; collaboratori tramite account propri, fork e pull request. Nessuna credenziale privata
distribuita. Branch protetti, revisione e autorizzazione del titolare prima delle release. Licenza open source e
condizioni white label da formalizzare.

Pipeline richiesta
GitHub Actions: build su runner Windows, macOS e Linux; test automatici, pacchetti nativi, manifest delle
dipendenze, checksum, firma ove prevista, pubblicazione in Releases dopo approvazione. ZIP Web e archivio
di ogni aggiornamento/fix con istruzioni di backup, installazione, verifica e rollback; mantenere trasferibilità
SCP per ambienti che la utilizzano. Nessuna compilazione manuale per l’utente.

Tecnologia GUI, packaging, firma, architetture CPU e compatibilità periferiche da determinare tramite
prototipo. Non scegliere automaticamente C# o Inno Setup come vincoli definitivi.

Migrazione dalla 0.2
Preservare l’ambiente DEV esistente e tutti i suoi dati fino al completamento del Core definitivo. Inventariare
componenti, versioni, database, documenti, media, utenti e configurazioni. Esportare in formato portabile;
importare in staging 0.4, verificare integrità e funzioni, approvare e migrare con rollback. Creare
contestualmente installazione White Label pulita con stesso Core, senza dati o credenziali del negozio reale.

Portabilità bidirezionale locale/Web da mantenere nei limiti dei servizi e dei ruoli compatibili. Certificati
pubblici e segreti specifici dell’ambiente vanno gestiti o riemessi in sicurezza; non clonare
indiscriminatamente identità o credenziali.







09 / Primo sviluppo: Desk Server

P0 — prototipo installabile
1. Definire contratto Core/Desk e struttura repository. 2. Preparare pipeline GitHub per le tre piattaforme. 3.
Realizzare launcher e installer Server con prerequisiti, servizi PHP/MariaDB/web server e disinstallazione non
distruttiva. 4. Implementare setup grafico, identità tecnica, MFA/recovery e Control Center minimo. 5.
Aggiungere API di salute e associazione Client. 6. Generare release di laboratorio, non produzione.

Criteri di accettazione
   Installazione pulita e riavvio riusciti su Windows, macOS e Linux supportati, senza VM o WordPress.
   Control Center raggiungibile, database privato, amministratore unico e recovery verificato.
   Client associato tramite nome/IP e rilevamento automatico; rifiuto di server non autorizzato.
   Operazioni LAN senza Internet; benchmark di latenza e carico con più client (soglie da definire prima del
    collaudo).
   Backup esterno e ripristino su seconda macchina; rifiuto del vecchio server e recupero coda cassa.
   Aggiornamento in staging e rollback verificati; pacchetti ufficiali riproducibili e autenticabili.

Decisioni e verifiche ancora aperte
Scelta stack GUI, web server e packaging; versioni OS/CPU; discovery e fiducia crittografica; recovery
offline/MFA; protocollo di sincronizzazione e inventario; compatibilità fiscale; licenze; obiettivi prestazionali e
di recupero. Nessun test è dichiarato superato. Il manuale si basa sulle decisioni recuperate dalle
conversazioni 0.2–0.4, non su un confronto integrale con il file originale della 0.2.






## Precisazioni sulla sicurezza
MFA TOTP (Google Authenticator) per amministratori tecnici e gestionali; altri operatori OTP email a ogni accesso per default, intervallo configurabile globalmente. Modalità offline da validare. Recovery Key ordinaria monouso; Master Recovery Key permanente, esportata una sola volta in TXT con conferma di custodia, destinata anche alla decifratura dei backup. Perdita definitiva della Master rende irrecuperabili i backup cifrati. Nessuna copia aggiuntiva obbligatoria. Non memorizzare in chiaro o nei log.
