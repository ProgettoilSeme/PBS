#!/usr/bin/env php
<?php
/**
 * Script CLI per calcolare e aggiornare la versione del plugin WordPress.
 *
 * Logica:
 * - Legge la versione attuale dal file principale del plugin (intestazione WordPress).
 * - Trova l'ultimo commit che ha modificato la riga 'Version:' in quel file.
 * - Usa la data di quel commit per cercare issue chiuse su GitHub dopo tale data.
 * - Se trova issue chiuse con label 'major', 'minor' o 'patch', decide il bump di conseguenza.
 * - Se non trova issue rilevanti, analizza i commit locali dopo l'ultimo bump per tag #major/#minor/#patch.
 * - Calcola la nuova versione semantica (major.minor.patch).
 * - Chiede conferma all'utente prima di aggiornare il file del plugin.
 *
 * Requisiti:
 * - PHP CLI con estensione cURL.
 * - Accesso in lettura al repository Git (per git log).
 * - (Opzionale) Token GitHub con permessi 'repo' per aumentare i limiti di rate limit API.
 *
 * Uso:
 * php tools/bump-version.php
 *
 * Puoi esportare GITHUB_TOKEN nell'ambiente per usare un token privato:
 * export GITHUB_TOKEN=tuo_token_github
 *
 * Nota: lo script non esegue il commit delle modifiche. Dopo aver aggiornato
 *       la versione, esegui manualmente `git add` e `git commit`.
 *
 */
// Lo script vive in /tools, il main del plugin è una cartella sopra.
// Override via env:
// - PLUGIN_MAIN (default: pbs.php)
// - GITHUB_OWNER (default: ProgettoilSeme)
// - GITHUB_REPO  (default: PBS)
$pluginFile = dirname(__DIR__) . '/' . (getenv('PLUGIN_MAIN') ?: 'pbs.php');
$githubOwner = getenv('GITHUB_OWNER') ?: 'ProgettoilSeme';
$githubRepo = getenv('GITHUB_REPO') ?: 'PBS';
$githubToken = getenv('GITHUB_TOKEN') ?: null;

/**
 * Termina l'esecuzione stampando un messaggio di errore su STDERR.
 *
 * Scrive il messaggio prefissato e chiude il processo con exit code 1,
 * così che script chiamanti o pipeline possano rilevare il fallimento.
 *
 * @param string $msg Messaggio descrittivo dell'errore.
 * @return void
 */
function fail($msg)
{
    fwrite(STDERR, "❌ Errore: $msg\n");
    exit(1);
}

/**
 * Calcolo nuova versione semantica
 */
function bumpVersion($version, $level)
{
    [$major, $minor, $patch] = array_pad(explode('.', $version), 3, 0);
    switch ($level) {
        case 'major':
            $major++;
            $minor = 0;
            $patch = 0;
            break;
        case 'minor':
            $minor++;
            $patch = 0;
            break;
        case 'patch':
            $patch++;
            break;
    }
    return "$major.$minor.$patch";
}

/**
 * Richiesta GET a GitHub con cURL, header moderni e cattura header risposta.
 * Ritorna array [$httpCode, $responseBody, $responseHeadersAssoc]
 */
function github_get($url, $token = null)
{
    $headers = [
        'User-Agent: VersionBumperScript/1.0',
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    if ($token) {
        $headers[] = "Authorization: Bearer $token";
    }
    if (!function_exists('curl_init')) {
        fail('PHP cURL non disponibile');
    }
    $respHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$respHeaders) {
            $len = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) == 2) {
                $name = strtolower(trim($parts[0]));
                $value = trim($parts[1]);
                // Unisci header ripetuti con virgola
                if (isset($respHeaders[$name])) {
                    $respHeaders[$name] .= ', ' . $value;
                } else {
                    $respHeaders[$name] = $value;
                }
            }
            return $len;
        },
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        fail('cURL error: ' . $err);
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$httpCode, $response, $respHeaders];
}

/**
 * Parsing del Link header per paginazione GitHub.
 * Ritorna array associativo ['next' => url, 'last' => url, ...]
 */
function parse_link_header($linkHeader)
{
    $links = [];
    if (!$linkHeader)
        return $links;
    foreach (explode(',', $linkHeader) as $part) {
        $section = explode(';', trim($part));
        if (count($section) < 2)
            continue;
        $url = trim($section[0], '<> ');
        $rel = null;
        foreach (array_slice($section, 1) as $seg) {
            $seg = trim($seg);
            if (stripos($seg, 'rel=') === 0) {
                $rel = trim(substr($seg, 4), '"\' ');
            }
        }
        if ($rel)
            $links[$rel] = $url;
    }
    return $links;
}

/**
 * Wrapper robusto per consumare l'API issues (state=closed) con paginazione.
 * Restituisce array associativo decodificato (lista issues).
 * In caso di errore, stampa messaggio/URL documentazione e termina.
 */
function fetchGitHubIssues($owner, $repo, $sinceIso, $token)
{
    // Per limitare le chiamate, pagina a 100 risultati
    $url = "https://api.github.com/repos/$owner/$repo/issues?state=closed&since=" . urlencode($sinceIso) . "&per_page=100";

    $all = [];
    while ($url) {
        [$code, $body, $hdrs] = github_get($url, $token);

        if ($code !== 200) {
            $msg = 'Errore sconosciuto';
            $docs = null;
            $json = json_decode($body, true);
            if (is_array($json)) {
                $msg = $json['message'] ?? $msg;
                $docs = $json['documentation_url'] ?? $docs;
            }
            fwrite(STDERR, "HTTP $code: $msg\n");
            if ($docs)
                fwrite(STDERR, "Docs: $docs\n");
            fail("Errore HTTP $code nella richiesta GitHub");
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            fwrite(STDERR, "Body non JSON o non valido: $body\n");
            fail("Risposta GitHub non valida");
        }
        $all = array_merge($all, $data);

        $links = parse_link_header($hdrs['link'] ?? null);
        $url = $links['next'] ?? null; // continua se esiste pagina successiva
    }

    return $all;
}

/**
 * Aggiornamento versione nel file plugin (intestazione WordPress)
 */
function aggiornaVersioneNelFile($file, $vecchia, $nuova)
{
    $contenuto = file_get_contents($file);
    $regex = '/^(Version:\s*)(["\']?)' . preg_quote($vecchia, '/') . '(["\']?)(\s*)$/mi';

    $sostituito = preg_replace_callback($regex, function ($m) use ($nuova) {
        return $m[1] . $m[2] . $nuova . $m[3] . $m[4];
    }, $contenuto, -1, $count);

    if ($count === 0) {
        fail("⚠️  Versione non aggiornata: riga non trovata o formattazione inattesa.");
    }

    file_put_contents($file, $sostituito);
    echo "✅ Versione aggiornata nel file plugin: $nuova\n";
}

/**
 * Aggiorna la versione all'interno del README (md o txt).
 *
 * Regole supportate:
 * - Markdown: "Versione: `x.y.z`" o "Version: `x.y.z`" (con o senza backtick)
 * - readme.txt: "Stable tag: x.y.z"
 *
 * Non interrompe il flusso se non trova corrispondenze: stampa un messaggio informativo.
 *
 * @param string      $file        Percorso del README.
 * @param string      $nuova       Nuova versione da impostare.
 * @param string|null $releaseDate Data di rilascio da impostare (formato YYYY-MM-DD) oppure null per non modificarla.
 * @return bool                    true se effettuata almeno una sostituzione, altrimenti false.
 */
function aggiornaVersioneReadme($file, $nuova, ?string $releaseDate = null)
{
    if (!file_exists($file)) {
        return false;
    }

    $contenuto = file_get_contents($file);
    $before = $contenuto;

    // Passo 1: Versione (IT) con backtick opzionale
    // Passo 0: sezione Versione del README LSA (bullet standard del progetto)
    // - Versione plugin (header `main.php`): `x.y.z`
    $contenuto = preg_replace_callback(
        '/^(\s*[-*]\s*Versione\s+plugin\s*\(header\s*`main\.php`\)\s*:\s*)(`?)[0-9.]+(`?)/mi',
        function ($m) use ($nuova) {
            return $m[1] . ($m[2] ?: '') . $nuova . ($m[3] ?: '');
        },
        $contenuto
    );

    // - Data di rilascio: `YYYY-MM-DD`
    if ($releaseDate !== null) {
        $contenuto = preg_replace_callback(
            '/^(\s*[-*]\s*Data\s+di\s+rilascio\s*:\s*)(`?)[0-9]{4}-[0-9]{2}-[0-9]{2}(`?)/mi',
            function ($m) use ($releaseDate) {
                return $m[1] . ($m[2] ?: '') . $releaseDate . ($m[3] ?: '');
            },
            $contenuto
        );
    }

    $contenuto = preg_replace_callback(
        '/^(Versione\s*:\s*)(`?)[^`\r\n]+(`?)/mi',
        function ($m) use ($nuova) {
            $prefix = $m[1];
            $open   = $m[2] ?: '';
            $close  = $m[3] ?: '';
            return $prefix . $open . $nuova . $close;
        },
        $contenuto
    );

    // Passo 2: Version (EN) con backtick opzionale
    $contenuto = preg_replace_callback(
        '/^(Version\s*:\s*)(`?)[^`\r\n]+(`?)/mi',
        function ($m) use ($nuova) {
            $prefix = $m[1];
            $open   = $m[2] ?: '';
            $close  = $m[3] ?: '';
            return $prefix . $open . $nuova . $close;
        },
        $contenuto
    );

    // Passo 3: Data di rilascio (solo se richiesto)
    if ($releaseDate !== null) {
        $contenuto = preg_replace_callback(
            '/^(Data\s+di\s+rilascio\s*:\s*)(`?)[^`\r\n]+(`?)/mi',
            function ($m) use ($releaseDate) {
                $prefix = $m[1];
                $open   = $m[2] ?: '';
                $close  = $m[3] ?: '';
                return $prefix . $open . $releaseDate . $close;
            },
            $contenuto
        );
    }

    // Passo 4: Stable tag in readme.txt
    $contenuto = preg_replace_callback(
        '/^(Stable\s+tag\s*:\s*).+$/mi',
        function ($m) use ($nuova) {
            return $m[1] . $nuova;
        },
        $contenuto
    );

    if ($contenuto !== null && $contenuto !== $before) {
        file_put_contents($file, $contenuto);
        echo "✅ Versione aggiornata nel README ($file): $nuova\n";
        return true;
    }

    echo "ℹ️ Nessuna riga Version/Stable tag trovata in $file\n";
    return false;
}

/**
 * Aggiorna la sezione "## Changelog" nel README.
 *
 * Non è bloccante: in caso di problemi stampa solo un messaggio informativo.
 *
 * Comportamento:
 * - Costruisce un blocco "### vX.Y.Z – YYYY-MM-DD" a partire da:
 *   - elenco issue chiuse ($issues),
 *   - elenco commit ($commits) dopo l'ultimo bump.
 * - Se NON esiste ancora "## Changelog", crea la sezione in coda con il solo blocco corrente.
 * - Se esiste già "## Changelog" con contenuto:
 *   - chiede all'utente se SOSTITUIRE il contenuto precedente
 *     oppure ACCODARE il nuovo blocco in testa, lasciando i blocchi storici sotto.
 *
 * @param string $file        Path README.md
 * @param string $version     Nuova versione (es. 2.8.5)
 * @param string $releaseDate Data release (YYYY-MM-DD)
 * @param array  $issues      Lista issue GitHub (decodifica JSON API)
 * @param array  $commits     Lista dei commit [$hash,$subject,$author]
 * @return bool               true se il file è stato modificato
 */
function aggiornaRoadmapReadme($file, string $version, string $releaseDate, array $issues, array $commits): bool
{
    if (!is_file($file) || !is_readable($file) || !is_writable($file)) {
        echo "ℹ️ README non accessibile per aggiornare il changelog ($file)\n";
        return false;
    }

    $content = file_get_contents($file);
    if ($content === false) {
        echo "ℹ️ Impossibile leggere il README per il changelog ($file)\n";
        return false;
    }

    $lines = [];

    // Prima gli issue (se disponibili)
    if (!empty($issues)) {
        $lines[] = '- Issues chiuse:';
        foreach ($issues as $issue) {
            $num   = $issue['number'] ?? null;
            $title = $issue['title'] ?? '';
            if ($num === null && $title === '') {
                continue;
            }
            $labelNames = [];
            foreach ($issue['labels'] ?? [] as $label) {
                $name = $label['name'] ?? '';
                if ($name !== '') {
                    $labelNames[] = $name;
                }
            }
            $labelSuffix = $labelNames ? ' [' . implode(', ', $labelNames) . ']' : '';
            if ($num !== null) {
                $lines[] = sprintf('  - #%d %s%s', (int) $num, $title, $labelSuffix);
            } else {
                $lines[] = sprintf('  - %s%s', $title, $labelSuffix);
            }
        }
    }

    // Poi i commit (sintesi, se presenti)
    if (!empty($commits)) {
        $lines[] = empty($issues) ? '- Modifiche dai commit:' : '- Commit collegati:';
        foreach ($commits as [$hash, $subject, $author]) {
            $lines[] = sprintf('  - %s  %s (by %s)', $hash, $subject, $author);
        }
    }

    if (empty($lines)) {
        echo "ℹ️ Nessuna issue/commit fornito per il changelog v$version; nessun aggiornamento README.\n";
        return false;
    }

    // Blocco changelog proposto per la nuova versione.
    $block = "### v{$version} – {$releaseDate}\n"
           . implode("\n", $lines) . "\n";

    $pos = strpos($content, '## Changelog');

    if ($pos === false) {
        // Nessuna sezione pre‑esistente: crea la sezione da zero in coda.
        $content .= "\n\n## Changelog\n\n" . $block . "\n";
    } else {
        // Esiste già una sezione "## Changelog": chiedi se sostituire o accodare.
        $existing = substr($content, $pos);
        $hasExistingBody = trim($existing) !== '';

        // Se non c'è corpo significativo, comportati come "sostituisci".
        $mode = 'replace';
        if ($hasExistingBody) {
            echo "\n📘 Trovata sezione \"## Changelog\" esistente nel README.\n";
            echo "    Vuoi SOSTITUIRE il changelog precedente o ACCODARE la nuova versione in testa?\n";
            echo "    [S]ostituisci / [A]ccoda (nuova versione sopra le precedenti)  [S/a]: ";
            $ans = strtolower(trim(fgets(STDIN) ?: ''));
            if ($ans === 'a') {
                $mode = 'append';
            }
        }

        if ($mode === 'replace') {
            // Mantieni solo la parte prima di "## Changelog" e ricrea la sezione.
            $content = rtrim(substr($content, 0, $pos));
            $content .= "\n\n## Changelog\n\n" . $block . "\n";
        } else {
            // Mantieni l'intestazione "## Changelog" e infila il nuovo blocco subito dopo,
            // lasciando il corpo esistente in coda.
            $before = substr($content, 0, $pos);
            // Trova la fine della riga "## Changelog"
            $newlinePos = strpos($content, "\n", $pos);
            if ($newlinePos === false) {
                $newlinePos = strlen($content);
            } else {
                $newlinePos++; // includi il newline
            }
            $header = substr($content, $pos, $newlinePos - $pos);
            $body   = substr($content, $newlinePos);

            $content = rtrim($before) . "\n\n"
                     . rtrim($header) . "\n\n"
                     . rtrim($block) . "\n\n"
                     . ltrim($body);
        }
    }

    if (file_put_contents($file, $content) === false) {
        echo "ℹ️ Impossibile scrivere il changelog nel README ($file)\n";
        return false;
    }

    echo "✅ Changelog v$version aggiunto al README ($file)\n";
    return true;
}

/**
 * Aggiorna il file CHANGELOG.md (release notes per Git).
 *
 * Comportamento:
 * - Se il file non esiste, lo crea con header `# Changelog`.
 * - Prepend del blocco della nuova versione subito dopo l'header.
 * - Se il blocco per quella versione esiste gia', non fa nulla.
 */
function aggiornaChangelogFile(string $file, string $version, string $releaseDate, array $issues, array $commits): bool
{
    $lines = [];

    if (!empty($issues)) {
        $lines[] = '- Issues chiuse:';
        foreach ($issues as $issue) {
            $num   = $issue['number'] ?? null;
            $title = $issue['title'] ?? '';
            if ($num === null && $title === '') {
                continue;
            }
            $labelNames = [];
            foreach ($issue['labels'] ?? [] as $label) {
                $name = $label['name'] ?? '';
                if ($name !== '') {
                    $labelNames[] = $name;
                }
            }
            $labelSuffix = $labelNames ? ' [' . implode(', ', $labelNames) . ']' : '';
            if ($num !== null) {
                $lines[] = sprintf('  - #%d %s%s', (int) $num, $title, $labelSuffix);
            } else {
                $lines[] = sprintf('  - %s%s', $title, $labelSuffix);
            }
        }
    }

    if (!empty($commits)) {
        $lines[] = empty($issues) ? '- Modifiche dai commit:' : '- Commit collegati:';
        foreach ($commits as [$hash, $subject, $author]) {
            $lines[] = sprintf('  - %s  %s (by %s)', $hash, $subject, $author);
        }
    }

    if (empty($lines)) {
        echo "ℹ️ Nessuna issue/commit fornito per CHANGELOG v$version; nessun aggiornamento.\n";
        return false;
    }

    $block = "## v{$version} – {$releaseDate}\n" . implode("\n", $lines) . "\n\n";

    if (!file_exists($file)) {
        $header = "# Changelog\n\nQuesto file e' mantenuto da `tools/bump-version.php`.\n\n";
        if (file_put_contents($file, $header . $block) === false) {
            echo "ℹ️ Impossibile creare CHANGELOG ($file)\n";
            return false;
        }
        echo "✅ CHANGELOG creato e aggiornato ($file)\n";
        return true;
    }

    $content = file_get_contents($file);
    if ($content === false) {
        echo "ℹ️ Impossibile leggere CHANGELOG ($file)\n";
        return false;
    }

    if (strpos($content, "## v{$version} –") !== false) {
        echo "ℹ️ CHANGELOG gia' contiene v$version ($file)\n";
        return false;
    }

    // Inserisci subito dopo il primo header (# Changelog / ## Changelog) se presente.
    if (preg_match('/^(#\\s+Changelog.*\n)(\n)?/m', $content, $m, PREG_OFFSET_CAPTURE)) {
        $start = $m[0][1] + strlen($m[0][0]);
        $content = substr($content, 0, $start) . "\n" . $block . ltrim(substr($content, $start));
    } else {
        $content = "# Changelog\n\n" . $block . $content;
    }

    if (file_put_contents($file, $content) === false) {
        echo "ℹ️ Impossibile scrivere CHANGELOG ($file)\n";
        return false;
    }

    echo "✅ CHANGELOG v$version aggiunto ($file)\n";
    return true;
}


/**
 * Prova a leggere la scadenza del token dagli header GitHub.
 * GitHub può restituire l'header 'github-authentication-token-expiration' (non garantito).
 */
function getTokenExpiry($token): ?string
{
    if (!$token)
        return null;

    // Chiamata leggera: /rate_limit
    [$code, $body, $hdrs] = github_get("https://api.github.com/rate_limit", $token);
    if ($code !== 200) {
        // Non blocco l'esecuzione; semplicemente non mostro la scadenza.
        return null;
    }
    foreach ($hdrs as $name => $value) {
        if ($name === 'github-authentication-token-expiration') {
            // Restituisco così com’è (ISO/GMT). L'output principale lo formatterà.
            return $value;
        }
    }
    return null;
}

/**
 * Analizza i commit dopo un certo commit e decide il livello di bump in base a #major/#minor/#patch
 */
function detectBumpFromCommits($sinceCommit)
{
    $log = shell_exec("git log $sinceCommit..HEAD --pretty='%h|%s|%an'");
    $lines = array_filter(explode("\n", $log));
    if (empty($lines))
        return [null, []];

    $hasMajor = $hasMinor = $hasPatch = false;
    $commits = [];

    foreach ($lines as $line) {
        [$hash, $subject, $author] = array_map('trim', explode('|', $line, 3));
        $commits[] = [$hash, $subject, $author];

        $msg = strtolower($subject);
        if (strpos($msg, '#major') !== false)
            $hasMajor = true;
        elseif (strpos($msg, '#minor') !== false)
            $hasMinor = true;
        elseif (strpos($msg, '#patch') !== false)
            $hasPatch = true;
    }

    $bump = 'patch';
    if ($hasMajor)
        $bump = 'major';
    elseif ($hasMinor)
        $bump = 'minor';

    return [$bump, $commits];
}

// === INTRO ===
$expiry = $githubToken ? getTokenExpiry($githubToken) : null;
$tokenStatus = $githubToken ? '✅ impostato' : '❌ mancante';
$expiryInfo = $expiry ? ' (scadenza token: ' . date('Y-m-d', strtotime($expiry)) . ')' : '';

echo <<<EOT
🔧 Script "bump-version.php"
────────────────────────────
🧩 Calcolo automatico della PROSSIMA VERSIONE del plugin WordPress.

📁 Plugin file:      $pluginFile
🌐 GitHub repo:      $githubOwner/$githubRepo
🔑 GitHub token:     $tokenStatus$expiryInfo

────────────────────────────

EOT;

// NOTE: Labels are required on GitHub for issue-based bump detection.
// If missing, run: php tools/ensure-github-labels.php (requires GITHUB_TOKEN).


// === VERSIONE CORRENTE ===

if (!file_exists($pluginFile))
    fail("File non trovato: $pluginFile");
$content = file_get_contents($pluginFile);
if (!preg_match('/^Version:\s*(.+)$/mi', $content, $match)) {
    fail("Campo Version: non trovato");
}
$currentVersion = trim($match[1]);
echo "📦 Versione attuale: $currentVersion\n";

// === ULTIMO COMMIT CON 'Version:' ===

$versionCommit = trim(shell_exec("git log -G'^Version:\\s*' -1 --format='%H' -- \"$pluginFile\""));
if (!$versionCommit) {
    $versionCommit = trim(shell_exec("git log -1 --format='%H' -- \"$pluginFile\""));
    echo "⚠️  Nessuna modifica 'Version:' rilevata, uso fallback ultima modifica al file\n";
}

$versionDate = trim(shell_exec("git show -s --format='%cI' $versionCommit"));
echo "🕒 Ultimo bump registrato nel commit: $versionCommit ($versionDate)\n";

// === GITHUB: ISSUE CHIUSE ===

$issues = fetchGitHubIssues($githubOwner, $githubRepo, $versionDate, $githubToken);
$issueCount = count($issues);
echo "🐙 Issue chiuse rilevate da GitHub dopo $versionDate: $issueCount\n";

if ($githubToken) {
    // Lightweight hint: if labels are not configured, bump will rely on commit tags (#major/#minor/#patch).
    echo "ℹ️  Se non esistono label GitHub (major/minor/patch/bug), esegui: php tools/ensure-github-labels.php\n";
}


$increment = null;
if ($issueCount > 0) {
    $hasMajor = $hasMinor = $hasPatch = false;
    foreach ($issues as $issue) {
        // /issues include anche PR; consideriamo comunque le label se presenti
        foreach ($issue['labels'] ?? [] as $label) {
            $name = strtolower($label['name'] ?? '');
            if ($name === 'major')
                $hasMajor = true;
            elseif ($name === 'minor')
                $hasMinor = true;
            elseif (in_array($name, ['patch', 'bug'], true))
                $hasPatch = true;
        }
    }

    if ($hasMajor)
        $increment = 'major';
    elseif ($hasMinor)
        $increment = 'minor';
    elseif ($hasPatch)
        $increment = 'patch';
}

// === COMMIT LOCALI (per fallback e delta) ===

[$bumpFromCommits, $commitList] = detectBumpFromCommits($versionCommit);

if (!$increment) {
    echo "🔍 Nessuna issue chiusa rilevante. Analizzo i commit locali...\n";

    if ($bumpFromCommits) {
        echo "📝 Commit rilevati dopo ultimo bump:\n";
        foreach ($commitList as [$hash, $subject, $author]) {
            echo " - $hash  $subject (by $author)\n";
        }
        $increment = $bumpFromCommits;
    } else {
        echo "ℹ️ Nessun commit locale rilevante dopo il bump $currentVersion\n";
        echo "🚀 La versione rimane: $currentVersion\n";
        exit(0);
    }
}

echo "✅ Incremento suggerito: $increment\n";
$proposedVersion = bumpVersion($currentVersion, $increment);
$defaultReleaseDate = date('Y-m-d');
echo "🚀 Nuova versione proposta: $proposedVersion\n";
echo "📅 Data di rilascio proposta: $defaultReleaseDate\n";

// === DELTA / CHANGELOG SUGGERITO ===

if (!empty($commitList)) {
    echo "\n📘 Changelog suggerito per v$proposedVersion ($defaultReleaseDate):\n";
    foreach ($commitList as [$hash, $subject, $author]) {
        echo " - $hash  $subject (by $author)\n";
    }
    echo "\n";
} else {
    echo "\nℹ️ Nessun commit rilevato dopo l'ultimo bump; nessun changelog proposto.\n\n";
}

// === AVVISO GENERALE FISSO ===

echo "ℹ️  Avviso: eventuali commit futuri con 'Closes #X' su issue già chiuse NON produrranno effetti su GitHub.\n";
echo "    Se intendi legare modifiche a una issue chiusa, valuta di riaprirla prima del commit.\n";

// === CONFERMA / OVERRIDE MANUALE ===

echo "\n💬 Confermi versione e data proposte?\n";
echo "    [S]ì  /  [N]o (esci)  /  [M]odifica manuale  [S/n/m]: ";
$risposta = strtolower(trim(fgets(STDIN)));

if ($risposta === '' || in_array($risposta, ['s', 'y'], true)) {
    $finalVersion   = $proposedVersion;
    $releaseDate    = $defaultReleaseDate;
} elseif ($risposta === 'm') {
    // Modalità di inserimento manuale
    echo "✏️  Inserisci versione desiderata (x.y.z, vuoto = $proposedVersion): ";
    $manualVersion = trim(fgets(STDIN));
    if ($manualVersion === '') {
        $manualVersion = $proposedVersion;
    }

    if (!preg_match('/^\d+\.\d+\.\d+$/', $manualVersion)) {
        echo "❌ Formato versione non valido. Usa x.y.z (es. 2.8.2).\n";
        echo "❎ Nessuna modifica effettuata.\n";
        exit(1);
    }

    echo "✏️  Inserisci data di rilascio (YYYY-MM-DD, vuoto = $defaultReleaseDate): ";
    $manualDate = trim(fgets(STDIN));
    if ($manualDate === '') {
        $manualDate = $defaultReleaseDate;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $manualDate)) {
        echo "❌ Formato data non valido. Usa YYYY-MM-DD (es. $defaultReleaseDate).\n";
        echo "❎ Nessuna modifica effettuata.\n";
        exit(1);
    }

    $finalVersion = $manualVersion;
    $releaseDate  = $manualDate;
} else {
    echo "❎ Nessuna modifica effettuata.\n";
    exit(0);
}

echo "\n✅ Versione finale: $finalVersion\n";
echo "✅ Data di rilascio: $releaseDate\n";

// === SCRITTURA FILE ===

aggiornaVersioneNelFile($pluginFile, $currentVersion, $finalVersion);

// Aggiorna anche README.md e/o readme.txt se presenti nella root del plugin
$baseDir   = dirname(__DIR__);
$readmeMd  = $baseDir . '/README.md';
$readmeTxt = $baseDir . '/readme.txt';

aggiornaVersioneReadme($readmeMd, $finalVersion, $releaseDate);
aggiornaVersioneReadme($readmeTxt, $finalVersion, null);

// Tenta di aggiornare anche il changelog / roadmap nel README principale (non bloccante)
// Solo se esiste almeno un'issue o un commit da riportare.
if (is_file($readmeMd) && (!empty($issues) || !empty($commitList))) {
    aggiornaRoadmapReadme($readmeMd, $finalVersion, $releaseDate, $issues ?? [], $commitList ?? []);
}

echo "🎉 Aggiornamento versione completato.\n";
