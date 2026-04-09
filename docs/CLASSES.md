## Classi principali (PBS)

Questa pagina è un indice rapido (non esaustivo) delle classi “core” e del loro ruolo.

### Entry / bootstrap

- `pbs.php`
  - autoload `PBS\\*` da `src/`
  - activation hook → `PBS\\DB::ensure_schema()`
  - `PBS\\Plugin::register()`

### DB / Repository

- `src/DB.php`
  - definizione tabelle PBS
  - creazione schema DB PBS

- `src/Repository/SchemaRepository.php`
  - CRUD schema + bump versione schema

- `src/Repository/FieldRepository.php`
  - CRUD campi (fields) per schema

- `src/Repository/GenerationRepository.php`
  - storico generazioni (plugin/servizi creati)

### UI Admin (Pages)

- `src/Admin/Menu.php`
  - registra menu PBS

- `src/Admin/Pages/SchemaListPage.php`
  - lista schemi

- `src/Admin/Pages/SchemaNewPage.php`
  - creazione schema

- `src/Admin/Pages/SchemaFieldsPage.php`
  - gestione campi + gruppi (TAB `List/New/Edit`)
  - import ACF
  - split/copy/merge/group management

- `src/Admin/Pages/GeneratePage.php`
  - wizard generazione nuovo plugin

- `src/Admin/Pages/GlibCheckPage.php`
  - whitelist + scan compatibilità gLib

- `src/Admin/Pages/DeltaServicePage.php`
  - selezione plugin+gLib+servizio + analisi/applica delta (solo mapping)

### Services

- `src/Services/ACFImporter.php`
  - legge field groups ACF e normalizza fields PBS
  - policy “solo elements” e raggruppamento B

- `src/Services/ComponentRegistry.php`
  - standard componenti (kinds) e members/tipi PBS
  - normalizzazione key member (es. prefissi clone)

- `src/Services/PluginGenerator.php`
  - genera plugin target + gLib `gLib_fNN` + servizio standard

- `src/Services/GlibScanner.php`
  - scan plugin per cartelle gLib e report compatibilità

- `src/Services/GlibServiceScanner.php`
  - elenco servizi in una gLib (Api/Services/*)

- `src/Services/ServiceDelta.php`
  - diff schema→servizio (added/removed/changed)
  - SQL suggeriti per DB
  - apply: update `match_db_inp_type` (solo mapping)

