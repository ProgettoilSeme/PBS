## Delta servizio (Schema PBS → Servizio esistente)

### Obiettivo

Adattare in modo **mirato** un servizio esistente (gLib) quando lo schema PBS cambia.

Policy:
- **no automatic DB migrations**
- PBS produce:
  - warning (soglie / mismatch)
  - SQL suggeriti (ALTER ecc.)
  - update “solo mapping” nel Base*.php (se richiesto)

### Attuale perimetro (MVP)

- campi aggiunti
- campi rimossi
- campi modificati (type / group kind) → warning + update mapping (no DB)

### Safety checks (MVP)

- confronto `TABLE_KEY` del servizio vs `plugin_slug + schema_slug` (atteso)
- warning per delta percentuale > 30%
- warning per campi “changed” (tipo/kind diverso)

### Applicazione (MVP)

Il pulsante “Applica update”:
- riscrive solo l’array `match_db_inp_type` nel Base*.php
- mantiene le entry esistenti dove compatibili
- rigenera le entry quando type/kind non coincidono più con lo schema PBS

