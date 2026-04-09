## Componenti standard (ACF → PBS)

PBS considera i “campi complessi” provenienti da ACF come **componenti standardizzati**:
il gruppo non è solo un contenitore generico, ma un “tipo componente” con semantica fissa.

### Registry

Il mapping è centralizzato in:
- `src/Services/ComponentRegistry.php`

Ogni componente definisce:
- `kind` (es. `text`, `button`)
- `members` (key + label + tipo PBS)

### Componenti (MVP)

#### `text`

Rappresenta un blocco “testo” composto da:
- `boxtext` (`text`) — riassunto/occhiello (plain text)
- `p` (`html`) — corpo (wysiwyg / html sanitizzato)

#### `button`

Rappresenta una CTA complessa composta da:
- `icon` (`text`)
- `title` (`text`)
- `link` (`url`)
- `target_blank` (`bool`)
- `hidden_text` (`bool`)

### Normalizzazione key member

In presenza di clone/prefix ACF, le key possono arrivare prefissate:
- `text_boxtext` → `boxtext`
- `button_title` → `title`

La normalizzazione è eseguita in import tramite `ComponentRegistry::normalize_member_key()`.

### Impatto sul codice generato (target)

Nel codice generato, un gruppo viene salvato su **una singola colonna** (LONGTEXT) come JSON.
In BE/FE viene decodificato a array associativo.

La semantica “typed” (`flags.group.kind`) è mantenuta nel mapping `match_db_inp_type` per:
- rendere il delta più sicuro (detect mismatch schema↔servizio),
- permettere evoluzioni future (UI specifiche per componenti).

