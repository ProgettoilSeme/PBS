# PBS — Power Bridge SQL

PBS (Power Bridge SQL) è un *generatore on‑demand* di servizi “gLib‑compliant” pensato per mettere un layer tipizzato tra:

- **uno schema sorgente** (form builder / CPT custom / struttura dati, es. ACF o equivalente),
- e **un database SQL** progettato per query e payload complessi (fuori dal modello WP “meta non tipizzato”).

Lo scopo è produrre, tramite configurazione e interfacce dedicate, **codice PHP** (e asset correlati) che implementi un servizio coerente con gli standard aziendali (LSA/SEA), capace di gestire flussi dati *BE + FE* e di massimizzare prestazioni e accessibilità del dato (query tipizzate).

## Obiettivo

Generare un *servizio standard* che fornisca:

- **servizi “a freddo”**: primitive richiamabili da template/tema quando si accede a una pagina;
- **servizi AJAX “a caldo”**: endpoint per recupero/aggiornamento dati in tempo reale via JS;
- **UI BE standard**: pannello admin (Settings API) che replica la maschera dati e la logica di editing;
- **payload**: definizione dei campi esposti e del loro mapping IN/OUT;
- **DDL (nel lessico storico “DLL”)**: definizione schema/tabelle proprietarie necessarie al servizio.

## Architettura del servizio generato (triade)

La struttura del servizio deve rimanere **tripartita**, più template UI BE:

- **Admin**: wiring WordPress (menu, Settings API, enqueue, routing, servizi “a freddo”);
- **Base**: logica di dominio/DB/DDL (CRUD, query, mapping IN/OUT, schema_def, helper);
- **Callbacks**: callback di Settings API e handler AJAX / admin‑post, con sanitize e gestione azioni.

## Terminologia: i “delta” (due significati distinti)

PBS usa lo stesso termine “delta” in due contesti differenti:

1) **Delta DB ↔ DDL (LSA‑style)**  
   Differenza tra lo schema presente nel DB e lo schema atteso (DDL).  
   Nei plugin gLib compatibili vige la massima: **nessuna mutazione automatica dello schema in runtime**.

2) **Delta Schema‑sorgente ↔ Servizio (PBS‑style)**  
   Differenza tra l’evoluzione dello schema di input (form builder + schema dati) e il servizio generato
   (mapping, payload, UI BE, query, DDL, compatibilità).  
   Questo delta è gestito da PBS come **processo on‑demand**, esplicito e controllato.

## Principi (vincoli non negoziabili)

- **On‑demand, non automatico**: PBS non “auto‑adatta” nulla in runtime; prima si configura, poi si genera.
- **Compatibilità prima della generazione**: il processo deve produrre *report*, *warning*, *errori bloccanti*,
  e (se previsto) un *piano di migrazione*.
- **gLib compliance**: ciò che viene integrato in plugin gLib compatibili deve rispettare:
  - policy **NO AUTO dbDelta/ALTER** in FE/BE runtime;
  - flussi **IN/OUT centralizzati** tramite mapping (es. `match_db_inp_type`) e sanitize/output coerenti;
  - uso di wrapper/Library ove previsto dagli standard di progetto (no istanze dirette nei template).

## Politica schema (DDL) nei plugin target

- Il servizio generato può includere `schema_def`, metodi di check e `ensure_schema()`, ma **non deve**
  eseguire creazioni/alterazioni automaticamente durante l’uso normale (FE/BE).
- La “creazione dinamica” delle tabelle (se mancano) è ammessa **solo** in un contesto esplicito e manuale
  (strumento di allineamento / comando amministrativo dedicato), mai come side‑effect di una pagina o di una call AJAX.

## Processo di generazione (schema → servizio)

In sintesi, PBS deve:

1. acquisire lo **schema sorgente** (CPT/ACF o definizione proprietaria);
2. normalizzarlo in un **modello interno** (tipi, flags, regole di visibilità, serializzati, ecc.);
3. validare **compatibilità** e produrre un report (warning/errori + note per migrazione);
4. generare gli artefatti del servizio (triade + template + mapping/payload + DDL);
5. integrare nel plugin target (aggancio menu ADM/CFG e wiring coerente con gLib).

## Compatibilità, warning ed errori (prima della generazione)

La validazione è parte integrante della generazione: PBS deve fermarsi in presenza di incompatibilità
e produrre un report leggibile (per umani) e, ove utile, strutturato (per tooling).

### Errori bloccanti (esempi)

- collisione di nomi: due campi normalizzati producono lo stesso `db_column` o lo stesso `input_name`;
- tipo non supportato dal mapping IN/OUT (assenza di regola di sanitize/serialize);
- campo richiesto senza strategia di default/backfill (migrazione impossibile senza perdita o rottura);
- breaking change su chiavi primarie/unique/index attesi dal servizio (es. cambio semantica ID);
- tentativo di generare DDL che richiederebbe **ALTER automatici in runtime** nel plugin target.

### Warning (esempi)

- campi ACF sconosciuti/non mappabili: il campo viene escluso o marcato “manual review”;
- rename compatibile (input name cambiato, DB invariato) ma con impatto su UI/JS;
- variazione di tipo “soft” che richiede casting/normalizzazione (es. `text`→`text_area`);
- variazioni su campi serializzati (nuove chiavi) che richiedono aggiornamento di query `LIKE` o helper.

### Output di validazione (minimo)

- lista errori bloccanti (con riferimento al campo e alla regola violata);
- lista warning (con suggerimento operativo);
- diff sintetico: *schema sorgente → modello interno → mapping/payload → DDL*.

## Versioning (schema e generazioni)

PBS deve gestire due versionamenti distinti:

- **Schema Version**: versione del modello dati (schema normalizzato + mapping/payload) registrata in PBS.
- **Generation Version**: versione di output generata a partire da una Schema Version (artefatti + plugin).

Regole pratiche:

- ogni modifica al modello (aggiunta/rimozione/rinomina/tipo/flag) incrementa la Schema Version;
- ogni generazione produce una nuova Generation Version (anche a parità di schema) se cambia il target
  (plugin slug, namespace `gLib_fxx`, layout, preset di UI, ecc.);
- l’output generato deve contenere metadati minimi: `schema_id`, `schema_version`, `generation_version`,
  `gLib_fxx` usata e timestamp.

## Output attesi (artefatti)

- Classi PHP del servizio (Admin/Base/Callbacks) e template UI BE standard.
- Mapping IN/OUT (`match_db_inp_type` e derivati) + payload FE.
- DDL / schema definition + routine di verifica/allineamento **manuale** (mai automatiche in runtime).
- (Se previsto) scaffolding JS coerente con lo standard di progetto (sorgenti in `src/scripts`, build in `assets/scripts`).

## Modalità di integrazione

Il servizio generato può:

- essere **agganciato** a un plugin UI BE esistente (aggiunta di un nuovo servizio/feature),
- oppure generare un **nuovo plugin** che conserva la struttura gLib.

In entrambi i casi, PBS deve poter agganciare la voce di menu nel punto corretto:

- **ADM**: menu/contesto dedicato alle utenze operative,
- **CFG**: menu/contesto dedicato all’amministrazione/configurazione.

## Standard UI BE (Settings API)

La UI BE standard prevede:

- tabella principale con colonne determinate da flag/configurazione (fallback: prime 3);
- Actions standard **Edit / Delete**;
- tre TAB:
  1. **Main**: tabella (con paginazione),
  2. **Edit/New**: form di modifica/creazione,
  3. **Help**: documentazione/istruzioni del servizio.

## Schema sorgente e indipendenza da tool terzi (ACF)

Lo schema input può provenire da CPT/ACF, ma PBS deve poter operare anche con una definizione proprietaria:

- è possibile **importare** una struttura dati da ACF e convertirla in logica interna gLib orientata a SQL;
- la conversione deve essere **robusta** e con verifica di compatibilità (se ACF aggiunge elementi ignoti,
  questi devono poter essere segnalati/isolati per adeguamenti);
- il CPT può:
  - restare agganciato mantenendo dati ridondati/parziali/nessuni (nessun vincolo a conservare i meta),
  - oppure fungere solo da base per rigenerare un CPT indipendente (es. SEO), anche parziale.

## Mapping e dissociazione nominale (contratto IN/OUT)

PBS deve mantenere (nel mapping UI BE e quindi nel payload) la dissociazione nominale tra:

- **nome colonna nel DB**, e
- **nome del campo nel _POST/request** (o `input name`), anche quando la UI è gestita via JS.

Questa dissociazione è parte delle specifiche gLib e permette evoluzione dello schema DB senza vincolare
la semantica dell’interfaccia (e viceversa), purché il mapping resti coerente e versionato.

## Perché PBS (motivazione tecnica)

ACF e i meta WP sono utili per modellazioni anche complesse *ma* ottimizzate per accesso “singolo” e
non per interrogazioni complesse su grandi moli di dati. Per casi professionali e siti complessi,
il modello meta non tipizzato degrada:

- accessibilità del dato,
- performance,
- capacità di query avanzate.

PBS nasce per introdurre un layer SQL tipizzato e servizi coerenti (BE/FE) senza rompere gli standard gLib
e senza introdurre delta automatici in runtime.
