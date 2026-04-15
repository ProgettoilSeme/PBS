## PBS — Power Bridge SQL

PBS (Power Bridge SQL) è un plugin WordPress che funge da **generatore on‑demand** di nuovi plugin
“gLib‑compliant” a partire da uno **schema dati** definito manualmente o importato da **CPT/ACF**.

Obiettivo concettuale e specifica: vedere `main.md`.

---

## Versione

- Versione plugin: `0.0.2`
- Data: `2026-04-15`

---

## Cosa fa (MVP)

- Gestisce schemi dati interni (entità PBS “Schema” + “Fields”) salvati su tabelle proprietarie.
- UI admin per:
  - `Schemi` + `Dettaglio schema (campi)` con TAB `List/New/Edit`,
  - import ACF (con filtro `elements` e policy di raggruppamento B),
  - gestione gruppi: split/copy member, merge field→group, creazione gruppi.
- Genera un **nuovo plugin** in `wp-content/plugins/<plugin-slug>/` con namespace incrementale `gLib_fxx`,
  includendo:
  - struttura minima gLib (`Init.php`, `Api/SettingsApi.php`, `Supports/.../BaseController.php`),
  - servizio “standard” con UI BE a 3 TAB (`List/New/Edit/Help`), CRUD base, Settings API.
- `Check gLib`: whitelist + scan plugin per cartelle `gLib/` o `gLib_fNN/` + report compatibilità.
- `Delta servizio`: analisi delta schema→servizio (solo mapping; DB non modificato automaticamente), warning + SQL suggeriti.

Policy: ciò che viene generato è intenzionalmente “leggero” e non implementa migrazioni automatiche
né delta DB/DDL in runtime nel plugin target.

---

## Struttura (plugin PBS)

Percorsi relativi alla root del plugin `PBS/`:

- `pbs.php`  
  Entry point del plugin (autoload PSR‑4 minimale, activation hook per schema DB PBS).

- `src/DB.php`  
  Definizione e creazione tabelle PBS (`pbs_schemas`, `pbs_schema_fields`, `pbs_generations`).

- `src/Admin/Menu.php`  
  Menu admin PBS.

- `src/Admin/Pages/`  
  Pagine admin:
  - `SchemaListPage` (lista schemi),
  - `SchemaNewPage` (creazione schema),
  - `SchemaDetailPage` (struttura + editor campo),
  - `GeneratePage` (generazione plugin).

- `src/Repository/`  
  Repository DB:
  - `SchemaRepository`, `FieldRepository`, `GenerationRepository`.

- `src/Services/`  
  Servizi “leggeri”:
  - `ACFImporter` (import bozza schema da ACF),
  - `ComponentRegistry` (standardizzazione campi complessi ACF),
  - `CompatibilityValidator` (validazioni minime),
  - `PluginGenerator` (scrittura skeleton del plugin target + `gLib_fxx` incrementale).

- `templates/`  
  Template admin:
  - `admin-schemas.php`,
  - `admin-schema-new.php`,
  - `admin-schema-fields.php`,
  - `admin-generate.php`.

---

## Roadmap (prossimi step suggeriti)

- Validazione compatibilità più ricca (collisioni, tipi, mapping IN/OUT completo, report strutturato).
- Gestione `ord` (ordinamento campi) e UX avanzata (drag&drop / move up&down).
- Field editor più vicino allo standard gLib (`match_db_inp_type`, flags UI/FE/BE, serializzati).
- Generazione plugin target più completa:
  - triade reale Admin/Base/Callbacks con wiring menu,
  - template UI BE a 3 TAB (Main/Edit/Help),
  - scaffolding JS coerente con standard (src/scripts → assets/scripts).
- Registro generazioni e “regeneration” controllata (diff tra Schema Version e Generation Version).

---

## Changelog



### v0.0.2 - 2026-04-15
- #3: Fix: pre-fill (Preview) + traduzione UI Text/Button nel plugin generato (delta incluso)
### v0.0.1 - 2026-04-09
- #1: Bootstrap PBS repo: docs + tools + bump workflow
### 0.0.0 — 2026-04-09

- Baseline PBS:
  - tabelle proprietarie (`pbs_schemas`, `pbs_schema_fields`, `pbs_generations`);
  - UI admin completa per schemi + campi (TAB, gruppi, import ACF).
- Standardizzazione componenti ACF iniziali:
  - gruppi complessi con `flags.group.kind` (es. `text`, `button`);
  - members normalizzati + tipi PBS standard via `ComponentRegistry`.
- Generatore plugin “gLib-compliant” (light) + servizio standard con BE UI a 3 TAB e create-table on-demand (solo se mancante).
- Check gLib (whitelist + scan + report) e Delta servizio (mapping + warning + SQL suggeriti; no auto DB changes).
