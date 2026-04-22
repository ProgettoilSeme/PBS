---
title: "Feat: centralizzare la DLL preview e bloccare Split per componenti tipizzati"
labels:
  - patch
---

## Contesto

- In PBS il dettaglio campi e il generatore stanno convergendo verso una DLL preview centrale.
- Alcune operazioni distruttive sullo schema possono rompere componenti tipizzati e vanno limitate.

## Obiettivo

- Introdurre una preview DLL editabile per schema, riusata da generator e delta.
- Disabilitare `Split` per i gruppi tipizzati `text` e `button`, lasciando disponibile `Copy`.
- Aggiungere un flusso sicuro per le tabelle del delta, con conferma e whitelist.

## Note tecniche

- File coinvolti:
  - `src/Admin/Pages/DeltaServicePage.php`
  - `src/Admin/Pages/SchemaFieldsPage.php`
  - `src/Services/PluginGenerator.php`
  - `src/Services/ServiceDelta.php`
  - `templates/admin-delta-service.php`
  - `templates/admin-schema-fields.php`
  - `templates/admin-schemas-tabs.php`
- Verificare che il dialog di conferma comune venga incluso nei template modificati.
- La issue resta in `tools/issues/drafts/` finché non viene pubblicata con `tools/issue-publish.php`.

## Checklist (opzionale)

- [ ] Verificare preview DLL su schema esistente
- [ ] Verificare che `Split` sia bloccato solo per `text` e `button`
- [ ] Verificare che il delta mostri e droppi solo tabelle consentite

> Label bump richieste: major | minor | patch (default: patch).
> Pubblica con: php tools/issue-publish.php --file "2026-04-22--pbs-dll-preview-e-split-locked.md"
