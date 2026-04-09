# PBS - Power Bridge SQL

Il progetto serve a inserire tra un form builder (anche dotato di struttura dati proprietaria tipo ACF) e il DB gestito da WP non tipizzato un adatper layer dedicato alla gestione SQL.

In altre parole, on demand si vuole ottenere tramite interfacce e opportune impostazioni, la generazione di codice PHP che contempli un layer indipendente in grado di gestire i flussi di dati tra un CPT customizzato (ad esempio con ACF) e strutture dati complesse e payload su DB che bypassano quelle WP immaginate per metadati.

L'idea è strutturalmente quella di replicare l'impianto di progetti gLib compliant sfruttando l'estensibilità dei servizi componenti. In pratica si mira a un plugin di appoggio in grado di "generare" servizi gLib da aggiuntere (secondo standard già definiti) a liberie esistenti che alimentandosi delle "maschere" nei CPT definiti "manualmente" con ACF, possano gestire flussi dati SQL massimizzando le query (che diventano tipizzate) fornendo di base:
- servizi di recupero dati "a freddo" (richiamo diretto primitive da FE quando si accede alla pagina richiamabili dai template del tema) 
- servizi AJAX "a caldo" (richiamo di servizi in pagina per il recupero in tempo reale tramite post JS)
- UI BE "standard" (che di fatto replicano i dati del form definito nel CPT)
- payload 
- DLL 

La struttura replicata del servizio deve rimanere "tripartita" + template UI BE:
- una classe Admin per l'interfaccia dei servizi a freddo e la definizione delle strutture dati necessarie alle settings API WP (e quindi la gestione UI BE del servizio)
- una classe Base per l'interazione con l'ambiente e il DB
- una classe Callback per radunare tutte le callback

Il servizio potrà essere agganciato a un plugin UI BE esistente o potrà generare un nuovo plugin conservando però la struttura gLib. Inoltre potrà agganciarsi a un menu ADM (cioè di un servizio dedicato alle utenze) o CFG (cioè di un servizio dedicato all'amministrazione) del progetto esistente o nuovo.

Si immagina che questo bridge possa gestire i delta. Cioè se viene rimodellato il CPT deve essere in grado di adattare il servizio perché ne colga le differenze.

Quindi il fine è un layer tra il CPT e il database, l'obbiettivo è sia la generazione dedicata del servizio UI BE, sia la realizzazione di un interfaccia che consenta interrogazioni complesse con SQL dedicate.
Questo perché ACF è certamente utile a definire una modellazione di dati anche complessa ma per accesso singolo. Nel momento in cui si intende infatti salvaguardare l'accesso complesso al DB, con ACF (e i meta WP) si fa a scapito dell'accessibilità e della performance e in specie quando la massa di dati da gestire cresce. In altre parole WP non è adatto a un uso professionale e per lo sviluppo di siti complessi.

Tuttavia la questione apre diversi scenari e in specie riguardo sia la adattabilità (quanto lo vogliamo generico) che la dipendenza da tool di terze parti (ACF) per un tale approccio.

Per la prima: non si sta cercando di difinire un "qualunque" tipo di servizio gLib, ma uno specifico TIPO di servizio che gestisce flussi di dati indipendenti tra UI (sia BE che FE) e DB, che parte da uno schema (form builider) che va interpretato e incorporato e che quindi estende alle funzionalità SQL la gestione dei flussi di quei dati via precisi standard aziendali (definiti in progetti come LSA o SEA) e secondo la guida di interfacce UI BE che stabiliscono il grado di adattabilità. L'interfaccia UI BE, quindi, deve poter definire lo schema dati indipendentemente dai CPT o ACF, in via proprietaria: devo poter aggiungere al payload un field, il tipo e la gestione interna (se deve o meno ad esempio essere esposto al FE o se è un campo complesso (serializzato) e/o con I/O FE Json che può essere definito a mano direttamente dall'UI o preso dal CPT definito da ACF e convertito nelle logiche propietarie.

Tale standard riguarda: 
- la gestione di un UI BE tramite settings API con tabella principale le cui colonne sono di campi specificati con flag (in fallback le prime 3) nell'UI BE di questo plugin e con Actions standard Edit/Delete: tre TAB il main per la tabella (con paginazione) e secondo per Edit/New e il terzo con Help.
- la gestione di un payload con UI FE che esponga metodi tramite interfaccia richiamabili da FE
- la gestione di DLL con la creazione dinamica della TB se manca nel DB

Il CPT definito con ACF poi, potrà o meno rimanere agganciato, mantenere i dati in via ridondata, parziale o nulla (nessun dato mantenuto dei meta). Oppure funzioanre solo da punto di partenza per rigenerare un altro CPT indipendente da ACF, magari solo per la SEO e quindi parziale. Nell'import dal CPT della struttura dati, da logica ACF proprietaria a logica interna gLib orientata a SQL, la conversione della struttura dati potrà essere fatta senza spezzare le due logiche e in via robusta, con verifica di compatibilità: se infatti un domani ACF aggiungesse nuovi elementi che poi sono intercettati da questo plugin, possono essere segnalati, isolati per aggiornamenti e adeguamenti, separando così la logica e la dipendenza. 

Sia che si tratti di generare un nuovo plugin da Zero sia che si tratti di agganciarsi a uno esistente, sarà verificata la compatibilità gLib per operare le necessarie modifiche atte a estendere il codice.

Infine, verrà mantenuta nel map dell'UI BE e quindi nella definizione del payload la dissociazione nominale tra la definizione del campo nel DB e il nome nel _POST nella request (e/o della INPUT se non gestita da JS) con cui il medesimo è mappato, così come da specifiche gLib.