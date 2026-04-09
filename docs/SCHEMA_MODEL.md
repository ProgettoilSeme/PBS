## Modello dati PBS (Schema + Fields)

PBS salva gli schemi in DB e li usa come sorgente unica per:
- generazione plugin/servizi,
- delta servizio,
- standardizzazione componenti ACF.

### Schema

Un record `pbs_schemas` contiene (minimo):
- `name`
- `slug`
- `source` / `source_post_type`
- `schema_version` (incrementa ad ogni modifica campi)

### Field

Un record `pbs_schema_fields` contiene:
- `db_column` — nome colonna “interno” (TB della DLL) oppure chiave gruppo
- `input_name` — nome input/payload “interno”
- `label`
- `field_type` — tipo PBS (es. `text`, `html`, `url`, `bool`, `array_nested`)
- `flags` (JSON) — metadati UI/behaviour

### Gruppi (field_type = array_nested)

Un “gruppo” è un field con:
- `field_type = array_nested`
- `flags.group` presente

Formato (MVP):
```json
{
  "group": {
    "kind": "text|button|generic|...",
    "mode": "payload",
    "members": [
      { "key": "boxtext", "label": "Boxtext", "field_type": "text", "acf": {...} },
      { "key": "p", "label": "Testo", "field_type": "html", "acf": {...} }
    ]
  }
}
```

Note:
- `kind=generic` indica gruppo “manuale” (nessuna semantica di componente).
- `kind=<componente>` indica gruppo complesso standardizzato (es. da import ACF).

### Operazioni su gruppi (UI)

- **Split**: estrai un member dal gruppo (diventa un field singolo).
- **Copy**: duplica un member fuori dal gruppo senza alterare il gruppo.
- **Group/Merge**: inserisci un field singolo in un gruppo esistente.

