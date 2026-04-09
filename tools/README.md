### Cartella `tools/` – strumenti di servizio per PBS

Questa cartella contiene **script da riga di comando** e tool di manutenzione usati per:
- workflow “Issue as doc” (draft → publish → archive)
- bump versione semver guidato da una issue

Script principali:

- `tools/issue-draft.php` / `tools/issue-publish.php`  
  Crea un draft `.md` (front matter + body) e lo pubblica come Issue GitHub via GitHub CLI (`gh`).
  I draft sono in `tools/issues/drafts/` e vengono archiviati in `tools/issues/archive/`.

- `tools/bump-with-issue.php`  
  Bump semver + aggiornamento header plugin (`pbs.php`) + changelog in `README.md`.
  Richiede `git` e `gh` configurati.
  Modalità:
  - `--plan` (solo piano, nessuna modifica)
  - `--no-commit` (modifica file ma non commit/push/PR)

Note:
- Per PBS, il file plugin di default è `pbs.php` (override con env `PLUGIN_MAIN`).
- I default repo/branch sono impostati nello script ma possono essere sovrascritti con env.
