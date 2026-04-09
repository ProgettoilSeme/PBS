## Architettura (overview)

PBS è un plugin WordPress che genera **on-demand** nuovi plugin “gLib-compliant” a partire da uno
**schema PBS** (manuale o importato da ACF/CPT).

### Moduli principali

- **DB PBS**: tabelle proprietarie per schemi, campi e generazioni.
  - `pbs_schemas`
  - `pbs_schema_fields`
  - `pbs_generations`

- **UI Admin (PBS)**: pagine per gestire schemi/campi e orchestrarne l’uso.
  - `Schemi`: lista, new/edit, metadati schema.
  - `Dettaglio schema (campi)`: TAB `List/New/Edit`, gruppi e import ACF.
  - `Genera plugin`: genera un nuovo plugin con gLib `gLib_fNN`.
  - `Check gLib`: scan plugin candidati e report compatibilità.
  - `Delta servizio`: diff schema→servizio (solo mapping) + SQL suggeriti.

- **Import ACF (light)**: normalizza campi ACF in un modello PBS.
  - filtro “solo elements”
  - policy di raggruppamento B (elemento complesso → 1 gruppo + members)

- **Componenti standard (ACF→PBS)**:
  - gruppi complessi con `flags.group.kind` (es. `text`, `button`)
  - mapping members/tipi centralizzato in `ComponentRegistry`

- **Generatore plugin**:
  - crea plugin target in `wp-content/plugins/<slug>/`
  - genera gLib `gLib_fNN` (namespace incrementale)
  - genera servizio standard con BE UI a 3 TAB e Settings API

### Policy di sicurezza (MVP)

- PBS non applica automaticamente migrazioni DB sui plugin target.
- Nel target è ammesso solo:
  - **create-table on-demand** se la tabella non esiste (pattern “standard”).
  - **SQL suggeriti** per delta (ALTER ecc.), senza esecuzione automatica.

