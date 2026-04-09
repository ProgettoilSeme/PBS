## Generatore (PBS → plugin gLib)

### Obiettivo

Generare un nuovo plugin WordPress “gLib-compliant” (light) che:
- espone un servizio standard (BE UI + CRUD base),
- usa una DLL (tabella) coerente con lo schema PBS,
- non applica delta DB automatici (solo suggerimenti SQL).

### Output (struttura minima)

Il generatore crea:
- `wp-content/plugins/<plugin-slug>/<plugin-slug>.php`
- `wp-content/plugins/<plugin-slug>/gLib_fNN/Init.php`
- `wp-content/plugins/<plugin-slug>/gLib_fNN/Api/SettingsApi.php`
- `wp-content/plugins/<plugin-slug>/gLib_fNN/Supports/Components/Admin/BaseController.php`
- `wp-content/plugins/<plugin-slug>/gLib_fNN/Api/Services/.../<Service>/Base*.php`
- `wp-content/plugins/<plugin-slug>/gLib_fNN/Api/Services/.../<Service>/Admin*.php`
- `wp-content/plugins/<plugin-slug>/gLib_fNN/Api/Services/.../<Service>/*Callbacks.php`
- `wp-content/plugins/<plugin-slug>/UI/BE/templates/<service>.php` (TAB List/New/Help)

### DLL (tabella)

- `TABLE_KEY` derivata da `plugin_slug + schema_slug` normalizzata (`-` → `_`).
- In runtime: **solo create-table on-demand** quando la tabella non esiste.

### Gruppi / componenti

Un field con `flags.group.members` viene salvato come JSON su una singola colonna.
La logica di sanitize/decode è centralizzata nel `BaseController` generato.

