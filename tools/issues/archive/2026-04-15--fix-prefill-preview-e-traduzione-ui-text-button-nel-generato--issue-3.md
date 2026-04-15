---
title: "Fix: pre-fill (Preview) + traduzione UI Text/Button nel plugin generato (delta incluso)"
labels:
  - patch
  - type:fix
---

## Contesto

Nel flusso PBS:

- `Dettaglio schema (campi) → Preview` permette di impostare **pre-fill** (default/suggerimenti) per campi/gruppi importati da ACF/CPT (es. componenti `text` e `button`).
- La generazione/`Delta servizio` deve propagare queste impostazioni nel **plugin gLib generato**, mantenendo UI “prodotto finale” (non campi descrittivi “sporchi”).

## Problemi riscontrati (riproduzione)

1. In `Preview`, compilando un campo (es. Titolo) e premendo **Salva pre-fill (PBS)**:
   - a volte non salvava nulla (al reload si perdeva tutto).
   - in alcuni casi compariva la notice **“Membro copiato fuori dal gruppo.”** e veniva creato un campo in coda (azione `Copy` eseguita per errore).
2. Anche dopo aver salvato correttamente il pre-fill, nel **plugin generato** (e/o dopo `Delta servizio`) non compariva nessuna traccia dei valori pre-fill.
3. Nel generato:
   - il bottone mostrava la label interna **“Anteprima bottone”** (utile in PBS, ridondante nel plugin finale).
   - i componenti testo complessi venivano resi come **due campi distinti** (`— Boxtext` e `— Testo`), mentre nel CPT il “Boxtext” è un campo interno/invisibile e non deve emergere come input separato.
4. Intelephense segnalava falsi positivi su variabili (`$members`, `$ml`) in `ServiceDelta.php` a causa di stringhe/pattern non sicuri.

## Obiettivo

- `Preview` salva sempre i pre-fill senza side-effect (mai `Copy/Split` accidentali).
- I pre-fill sono usati come **valori di default** nel generato (in UI BE del servizio gLib).
- Per i componenti riconosciuti:
  - `button`: UI compatta, senza heading “Anteprima bottone”.
  - `text`/`title_text`: un solo input principale (HTML editor), senza “— Boxtext / — Testo”.
- `Delta servizio` e generazione completa producono output coerente (stessa logica centralizzata).

## Delta correzioni implementate

### A) Preview: salvataggio pre-fill affidabile (no side-effect)

- Rimossi **form annidati** nel TAB `Preview` (cause principali di submit “rotto” e azioni errate).
- Azioni `Copy` / `Split` dei membri in Preview convertite in **link con nonce** (GET) per evitare collisioni di `action` nel POST del pre-fill.
- `Elimina` nel TAB `Preview` convertito in **link con nonce** (GET) per evitare di annidare un form dentro il form del pre-fill.

### B) Delete: UX + redirect corretto

- Aggiunto bottone `Elimina` accanto a `Edit`:
  - nel TAB `Schema` (riga campo/gruppo)
  - nel TAB `Preview` (card campo/gruppo)
- `handle_field_delete()` supporta `return_tab` + `post_id` (Preview) e legge parametri anche da `$_REQUEST` (compatibile con link GET + nonce).

### C) Pre-fill nel plugin generato (generator + delta)

- Il mapping generato (`match_db_inp_type`) contiene `flags.prefill` (già presente nello schema PBS).
- Ora il generatore:
  - passa `prefill` dentro `args` di Settings API per:
    - campi semplici
    - membri di gruppo
    - editor compatto `button` (componentEditorField)
  - aggiorna `AdminCallbacks::inputField()` per usare `prefill` come **default** quando il valore record è vuoto.
  - aggiorna `componentPreviewField()` per fare merge default/record (record vince).
- `ServiceDelta` patcha anche plugin già generati:
  - inserisce/aggiorna supporto prefill in `AdminCallbacks.php`
  - inserisce `prefill` nei `args` dove mancanti (best-effort).

### D) Regole di traduzione UI (componenti riconosciuti)

- `button`: rimosso heading “Anteprima bottone” dal renderer generato (UI più pulita).
- `text` / `title_text`: regola di rendering “prodotto finale”
  - renderizza **un solo campo** (membro principale = quello di tipo `html` se presente, altrimenti primo membro).
  - label = `groupLabel` (niente suffissi “— Boxtext / — Testo”).
- `ServiceDelta` applica la stessa regola ai servizi già generati inserendo un blocco nel loop Settings API dell’Admin service (best-effort).

### E) Stabilità patch + rumore IDE

- `ServiceDelta.php`: conversione pattern/replacement a stringhe sicure (nowdoc/single-quote) per evitare:
  - parse error PHP
  - falsi warning Intelephense su `$members` / `$ml`

## File coinvolti

- `wp-content/plugins/PBS/templates/admin-schema-fields.php`
- `wp-content/plugins/PBS/src/Admin/Pages/SchemaFieldsPage.php`
- `wp-content/plugins/PBS/src/Services/PluginGenerator.php`
- `wp-content/plugins/PBS/src/Services/ServiceDelta.php`

## Test manuale consigliato

1. `PBS → Dettaglio schema (campi) → Preview`
   - Imposta `text.title = "Pippolandia"` e `button.title = "Pappaccia"` (esempio).
   - `Salva pre-fill (PBS)` e verifica `pbs_ok=prefill_saved`.
   - Reload: i valori restano.
2. `PBS → Delta servizio` sul servizio target:
   - `Applica update (solo mapping)`.
3. Nel servizio gLib generato:
   - TAB `New`: i campi devono partire con i default (se record vuoto).
   - `button`: nessun heading “Anteprima bottone”.
   - `text`: un solo input principale (niente “— Boxtext”).

## Note

- Il pre-fill è un **default**: quando il record contiene già valori, questi devono prevalere (atteso).
- Per ulteriori componenti ACF “standardizzati” la stessa strategia va estesa in modo centralizzato (ComponentRegistry + regole UI/DB/payload).

> Label bump richieste: major | minor | patch (default: patch).
> Pubblica con: `php tools/issue-publish.php --file "2026-04-15--fix-prefill-preview-e-traduzione-ui-text-button-nel-generato.md"`

