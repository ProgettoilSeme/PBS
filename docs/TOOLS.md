## Tools (CLI) — Issue + Versioning

PBS adotta lo stesso workflow “issue as doc” di LSA, tramite script CLI in `tools/`.

### Struttura

- `tools/issues/drafts/` — bozze issue in markdown (front matter + body)
- `tools/issues/archive/` — bozze archiviate dopo la pubblicazione

### Draft issue (locale)

```bash
php tools/issue-draft.php -t "Titolo issue" --labels "patch,type:chore"
```

### Pubblica issue (GitHub)

Richiede `gh` configurato.

```bash
php tools/issue-publish.php --file "YYYY-MM-DD--titolo.md"
```

### Bump versione “con issue”

Richiede `git` + `gh` configurati e repo remoto raggiungibile.

Env consigliate:
- `GITHUB_OWNER`
- `GITHUB_REPO`
- `DEFAULT_BRANCH` (es. `main`)
- `PLUGIN_MAIN` (default PBS: `pbs.php`)

```bash
php tools/bump-with-issue.php --issue 123
```

