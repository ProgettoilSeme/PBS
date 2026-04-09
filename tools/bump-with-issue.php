#!/usr/bin/env php
<?php
/**
 * bump-with-issue.php — versionamento “snello” basato su GitHub Issue.
 *
 * Requisiti:
 * - git
 * - gh (GitHub CLI) configurato (gh auth login) oppure token in env.
 *
 * Uso:
 *   php tools/bump-with-issue.php --issue 123
 *   php tools/bump-with-issue.php            # auto: sceglie una issue aperta
 *
 * Env (opzionali):
 * - GITHUB_OWNER (default: ProgettoilSeme)
 * - GITHUB_REPO  (default: PBS)
 * - DEFAULT_BRANCH (default: main)
 * - PLUGIN_MAIN (default: pbs.php)
 *
 * Labels bump (obbligatorie sulla issue):
 * - major | minor | patch
 *
 * Il bump è determinato dalla label con priorità major > minor > patch.
 *
 * Operazioni:
 * - legge issue + labels
 * - calcola nuova versione (semver)
 * - aggiorna Version: nel file plugin
 * - aggiorna Changelog in README.md
 * - commit su branch dedicato
 * - push + PR + merge (squash) + tag
 *
 * Sicurezza:
 * - Per default esegue la merge. Usa --no-merge per fermarsi dopo la PR.
 * - Per default crea il tag; usa --no-tag per evitarlo.
 */
declare(strict_types=1);

const EXIT_USAGE = 2;

final class BumpScriptException extends RuntimeException {
	public int $exitCode;
	public function __construct(string $message, int $exitCode = 1) {
		parent::__construct($message);
		$this->exitCode = $exitCode;
	}
}

function fail(string $msg, int $code = 1): void {
	throw new BumpScriptException($msg, $code);
}

function info(string $msg): void {
	fwrite(STDOUT, "• $msg\n");
}

function sh(string $cmd, ?array &$outLines = null, bool $allowFail = false): string {
	$descriptorspec = [
		1 => ['pipe', 'w'],
		2 => ['pipe', 'w'],
	];
	$proc = proc_open($cmd, $descriptorspec, $pipes);
	if (!is_resource($proc)) {
		fail("Impossibile eseguire comando: $cmd");
	}
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$exit = proc_close($proc);
	if ($exit !== 0 && !$allowFail) {
		$err = trim($stderr);
		if ($err === '') {
			$err = trim($stdout);
		}
		fail("Comando fallito ($exit): $cmd\n$err");
	}
	$outLines = array_values(array_filter(preg_split('/\r\n|\r|\n/', (string)$stdout)));
	return (string)$stdout;
}

function sh_exit_code(string $cmd, ?string &$stdout = null, ?string &$stderr = null): int {
	$descriptorspec = [
		1 => ['pipe', 'w'],
		2 => ['pipe', 'w'],
	];
	$proc = proc_open($cmd, $descriptorspec, $pipes);
	if (!is_resource($proc)) {
		fail("Impossibile eseguire comando: $cmd");
	}
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$exit = proc_close($proc);
	return is_int($exit) ? $exit : 1;
}

function git(string $args, ?array &$outLines = null, bool $allowFail = false): string {
	$repo = getcwd();
	// Evita “dubious ownership” senza dover scrivere su ~/.gitconfig.
	$cmd = 'git -c safe.directory=' . escapeshellarg($repo) . ' ' . $args;
	return sh($cmd, $outLines, $allowFail);
}

function gh(string $args, ?array &$outLines = null, bool $allowFail = false): string {
	$cmd = 'gh ' . $args;
	return sh($cmd, $outLines, $allowFail);
}

function slugify(string $s): string {
	$s = strtolower(trim($s));
	$s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s);
	$s = trim((string)$s, '-');
	if ($s === '') {
		return 'issue';
	}
	return substr($s, 0, 50);
}

function parseArgs(array $argv): array {
	$args = [
		'issue' => null, // se null: auto pick
		'plan' => false,
		'no_commit' => false,
		'no_merge' => false,
		'no_tag' => false,
		'no_release' => true, // default: non crea release (solo tag).
		'init_labels' => false,
	];

	for ($i = 1; $i < count($argv); $i++) {
		$k = $argv[$i];
		if ($k === '--issue' && isset($argv[$i + 1])) {
			$args['issue'] = (int)$argv[++$i];
			continue;
		}
		if ($k === '--auto') {
			$args['issue'] = null;
			continue;
		}
		if ($k === '--plan') {
			$args['plan'] = true;
			continue;
		}
		if ($k === '--no-commit') {
			$args['no_commit'] = true;
			continue;
		}
		if ($k === '--no-merge') {
			$args['no_merge'] = true;
			continue;
		}
		if ($k === '--no-tag') {
			$args['no_tag'] = true;
			continue;
		}
		if ($k === '--release') {
			$args['no_release'] = false;
			continue;
		}
		if ($k === '--init-labels') {
			$args['init_labels'] = true;
			continue;
		}
		if ($k === '--help' || $k === '-h') {
			$args['help'] = true;
			continue;
		}
		fail("Argomento non riconosciuto: $k", EXIT_USAGE);
	}

	if (!empty($args['help'])) {
		$help = <<<TXT
Uso:
  php tools/bump-with-issue.php --issue <N> [--plan] [--no-commit] [--no-merge] [--no-tag] [--release] [--init-labels]

Note:
  - richiede una label bump sulla issue: major|minor|patch
  - --plan: non modifica il repo, stampa solo bump/target version
  - --no-commit: applica le modifiche ai file ma si ferma prima di commit/push/PR
  - default: merge squash + tag vX.Y.Z (no release). Usa --release per creare anche release.
TXT;
		fwrite(STDOUT, $help . "\n");
		exit(0);
	}

	if ($args['issue'] !== null && $args['issue'] <= 0) {
		fail("Numero issue non valido.", EXIT_USAGE);
	}

	return $args;
}

function bumpVersion(string $version, string $level): string {
	$parts = array_map('intval', array_pad(explode('.', $version), 3, '0'));
	[$major, $minor, $patch] = $parts;
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
		default:
			fail("Livello bump non valido: $level");
	}
	return "{$major}.{$minor}.{$patch}";
}

function formatDateIt(string $ymd): string {
	// Input atteso: YYYY-MM-DD (usato in changelog).
	if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) {
		return $ymd;
	}
	$year = $m[1];
	$month = (int)$m[2];
	$day = (int)$m[3];
	$months = [
		1 => 'gen', 2 => 'feb', 3 => 'mar', 4 => 'apr', 5 => 'mag', 6 => 'giu',
		7 => 'lug', 8 => 'ago', 9 => 'set', 10 => 'ott', 11 => 'nov', 12 => 'dic',
	];
	$mon = $months[$month] ?? str_pad((string)$month, 2, '0', STR_PAD_LEFT);
	return $day . ' ' . $mon . ' ' . $year;
}

function readPluginVersion(string $pluginFile): string {
	if (!is_readable($pluginFile)) {
		fail("File plugin non leggibile: $pluginFile");
	}
	$content = file_get_contents($pluginFile);
	if (!is_string($content)) {
		fail("Impossibile leggere: $pluginFile");
	}
	// Supporta intestazione WP sia in stile "Version:" sia dentro PHPDoc " * Version:".
	if (!preg_match('/^\s*(?:\*\s*)?Version:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$/mi', $content, $m)) {
		fail("Riga Version: non trovata in $pluginFile");
	}
	return trim($m[1]);
}

function writePluginReleased(string $pluginFile, string $releasedIt): void {
	$content = file_get_contents($pluginFile);
	if (!is_string($content)) {
		fail("Impossibile leggere: $pluginFile");
	}

	// Se esiste già, aggiorna.
	$regex = '/^(\s*(?:\*\s*)?Released:\s*).+(\s*)$/mi';
	$count = 0;
	$updated = preg_replace_callback($regex, function ($m) use ($releasedIt, &$count) {
		$count++;
		return (string)($m[1] ?? 'Released: ') . $releasedIt . (string)($m[2] ?? '');
	}, $content);
	if (!is_string($updated)) {
		fail("Aggiornamento Released fallito in $pluginFile");
	}
	if ($count > 0) {
		file_put_contents($pluginFile, $updated);
		return;
	}

	// Altrimenti inserisci subito dopo la riga Version: nel blocco header.
	$insertRegex = '/^(\s*)(\*\s*)?Version:\s*[0-9]+\.[0-9]+\.[0-9]+(\s*)$/mi';
	$inserted = 0;
	$withInsert = preg_replace_callback($insertRegex, function ($m) use ($releasedIt, &$inserted) {
		$inserted++;
		$indent = (string)($m[1] ?? '');
		$star = (string)($m[2] ?? '');
		$line = $indent . $star . 'Version: ' . preg_replace('/^.*Version:\s*/i', '', trim((string)($m[0] ?? '')));
		$prefix = $indent . ($star !== '' ? $star : '');
		// Ricostruisci: mantieni la riga Version originale (m[0]) e aggiungi Released con stesso prefisso.
		return (string)($m[0] ?? '') . "\n" . $prefix . 'Released: ' . $releasedIt;
	}, $updated, 1);

	if (!is_string($withInsert) || $inserted < 1) {
		// Non blocchiamo il bump se non troviamo la riga Version: (ma dovrebbe esserci sempre).
		info("Nota: impossibile inserire Released in $pluginFile (riga Version non trovata per insert).");
		return;
	}
	file_put_contents($pluginFile, $withInsert);
}

function writePluginVersion(string $pluginFile, string $old, string $new): void {
	$content = file_get_contents($pluginFile);
	if (!is_string($content)) {
		fail("Impossibile leggere: $pluginFile");
	}
	$regex = '/^(\s*(?:\*\s*)?Version:\s*)([0-9]+\.[0-9]+\.[0-9]+)(\s*)$/mi';
	$count = 0;
	$repl = preg_replace_callback($regex, function ($m) use ($new, &$count) {
		$count++;
		return (string)($m[1] ?? '') . $new . (string)($m[3] ?? '');
	}, $content);
	if (!is_string($repl) || $count < 1) {
		fail("Aggiornamento Version fallito in $pluginFile");
	}
	file_put_contents($pluginFile, $repl);
	info("Aggiornata versione plugin: $old -> $new");

	// Sanity: rilegge e verifica che la riga Version sia ancora parsabile.
	$check = readPluginVersion($pluginFile);
	if ($check !== $new) {
		fail("Version aggiornata ma non coerente ($check). File: $pluginFile");
	}
}

function ensureChangelogInReadme(string $readmePath): void {
	if (!file_exists($readmePath)) {
		return;
	}
	$raw = file_get_contents($readmePath);
	if (!is_string($raw)) {
		fail("Impossibile leggere README: $readmePath");
	}
	if (preg_match('/^##\s+Changelog\s*$/mi', $raw)) {
		return;
	}
	// Inserisci dopo il primo H1.
	$lines = preg_split('/\r\n|\r|\n/', $raw);
	if (!is_array($lines)) {
		return;
	}
	$out = [];
	$inserted = false;
	foreach ($lines as $idx => $line) {
		$out[] = $line;
		if (!$inserted && preg_match('/^#\s+LSA\s*$/', trim($line))) {
			$out[] = '';
			$out[] = '## Changelog';
			$out[] = '';
			$out[] = '_Generato da `tools/bump-with-issue.php`._';
			$out[] = '';
			$inserted = true;
		}
	}
	if (!$inserted) {
		$out[] = '';
		$out[] = '## Changelog';
		$out[] = '';
		$out[] = '_Generato da `tools/bump-with-issue.php`._';
		$out[] = '';
	}
	file_put_contents($readmePath, implode("\n", $out));
}

function updateReadmePluginMeta(string $readmePath, string $newVersion, string $releaseDate): void {
	if (!file_exists($readmePath)) {
		return;
	}
	$raw = file_get_contents($readmePath);
	if (!is_string($raw)) {
		fail("Impossibile leggere README: $readmePath");
	}

	$before = $raw;

	// Pattern legacy (LSA):
	// - Versione plugin (header `main.php`): `x.y.z`
	$raw = preg_replace_callback(
		'/^(\s*[-*]\s*Versione\s+plugin\s*\(header\s*`main\.php`\)\s*:\s*)(`?)[0-9.]+(`?)/mi',
		function ($m) use ($newVersion) {
			return (string)($m[1] ?? '') . (string)($m[2] ?? '') . $newVersion . (string)($m[3] ?? '');
		},
		$raw
	);

	// Pattern PBS README:
	// - Versione plugin: `x.y.z`
	$raw = preg_replace_callback(
		'/^(\s*[-*]\s*Versione\s+plugin\s*:\s*)(`?)[0-9.]+(`?)/mi',
		function ($m) use ($newVersion) {
			return (string)($m[1] ?? '') . (string)($m[2] ?? '') . $newVersion . (string)($m[3] ?? '');
		},
		$raw
	);

	// Pattern legacy (LSA):
	// - Data di rilascio: `YYYY-MM-DD`
	$raw = preg_replace_callback(
		'/^(\s*[-*]\s*Data\s+di\s+rilascio\s*:\s*)(`?)[0-9]{4}-[0-9]{2}-[0-9]{2}(`?)/mi',
		function ($m) use ($releaseDate) {
			return (string)($m[1] ?? '') . (string)($m[2] ?? '') . $releaseDate . (string)($m[3] ?? '');
		},
		$raw
	);

	// Pattern PBS README:
	// - Data: `YYYY-MM-DD`
	$raw = preg_replace_callback(
		'/^(\s*[-*]\s*Data\s*:\s*)(`?)[0-9]{4}-[0-9]{2}-[0-9]{2}(`?)/mi',
		function ($m) use ($releaseDate) {
			return (string)($m[1] ?? '') . (string)($m[2] ?? '') . $releaseDate . (string)($m[3] ?? '');
		},
		$raw
	);

	if (!is_string($raw)) {
		fail("Aggiornamento metadati README fallito: $readmePath");
	}
	if ($raw !== $before) {
		file_put_contents($readmePath, $raw);
		info("Aggiornati metadati README: versione/data -> v{$newVersion}, {$releaseDate}");
	}
}

function prependChangelogEntry(string $readmePath, string $version, string $date, int $issueNumber, string $issueTitle): void {
	if (!file_exists($readmePath)) {
		return;
	}
	$raw = file_get_contents($readmePath);
	if (!is_string($raw)) {
		fail("Impossibile leggere README: $readmePath");
	}
	ensureChangelogInReadme($readmePath);
	$raw = file_get_contents($readmePath);
	if (!is_string($raw)) {
		fail("Impossibile rileggere README: $readmePath");
	}
	$entry = "### v{$version} - {$date}\n- #{$issueNumber}: {$issueTitle}\n";

	// Inserisci subito dopo il blocco "## Changelog" (+ eventuale riga descrittiva).
	$lines = preg_split('/\r\n|\r|\n/', $raw);
	$out = [];
	$inChangelog = false;
	$inserted = false;
	for ($i = 0; $i < count($lines); $i++) {
		$line = $lines[$i];
		$out[] = $line;
		if (!$inChangelog && preg_match('/^##\s+Changelog\s*$/', trim($line))) {
			$inChangelog = true;
			continue;
		}
		if ($inChangelog && !$inserted) {
			// salta righe vuote e la riga descrittiva, poi inserisci.
			$peek = trim($line);
			if ($peek === '' || stripos($peek, '_generato da') === 0) {
				continue;
			}
			// Inserisci entry prima del primo contenuto reale del changelog.
			array_splice($out, count($out) - 1, 0, ['', rtrim($entry)]);
			$inserted = true;
		}
	}
	if ($inChangelog && !$inserted) {
		$out[] = '';
		$out[] = rtrim($entry);
	}
	file_put_contents($readmePath, implode("\n", $out));
	info("Aggiornato README changelog: v$version");
}

function ensureBumpLabels(string $owner, string $repo): void {
	$wanted = [
		['name' => 'major', 'color' => 'b60205', 'description' => 'Bump major (incompatible)'],
		['name' => 'minor', 'color' => '0e8a16', 'description' => 'Bump minor (feature)'],
		['name' => 'patch', 'color' => '1d76db', 'description' => 'Bump patch (fix)'],
	];
	$out = gh('label list --limit 200 --repo ' . escapeshellarg("$owner/$repo"), $lines, true);
	$existing = [];
	foreach ($lines ?? [] as $line) {
		// output: "name\tcolor\tdescription"
		$parts = preg_split("/\t+/", $line);
		if (!$parts || !isset($parts[0])) continue;
		$existing[strtolower(trim($parts[0]))] = true;
	}
	foreach ($wanted as $lab) {
		if (isset($existing[$lab['name']])) {
			continue;
		}
		info("Creo label: {$lab['name']}");
		gh(
			'label create ' . escapeshellarg($lab['name']) .
			' --color ' . escapeshellarg($lab['color']) .
			' --description ' . escapeshellarg($lab['description']) .
			' --repo ' . escapeshellarg("$owner/$repo")
		);
	}
}

function determineBumpLevel(array $labels): ?string {
	$set = array_fill_keys(array_map(fn($l) => strtolower((string)$l), $labels), true);
	if (isset($set['major'])) return 'major';
	if (isset($set['minor'])) return 'minor';
	if (isset($set['patch'])) return 'patch';
	return null;
}

function originAheadBehind(string $branch): array {
	// Ritorna [ahead, behind] rispetto a origin/<branch>.
	git('fetch origin ' . escapeshellarg($branch));
	$range = 'origin/' . $branch . '...' . $branch;
	git('rev-list --left-right --count ' . escapeshellarg($range), $lines);
	$raw = trim(implode("\n", $lines));
	$parts = preg_split('/\s+/', $raw);
	if (!is_array($parts) || count($parts) < 2) {
		return [0, 0];
	}
	// Output: "<left> <right>" dove:
	// - left  = commit presenti solo in origin/<branch>  => local è "behind"
	// - right = commit presenti solo in <branch>         => local è "ahead"
	$behind = intval($parts[0]);
	$ahead = intval($parts[1]);
	return [$ahead, $behind];
}

function syncToOriginBranch(string $branch): void {
	// Aggiorna local <branch> da origin/<branch> in modo sicuro.
	git('fetch origin ' . escapeshellarg($branch));
	git('checkout ' . escapeshellarg($branch));
	// Fast-forward only: se non è possibile, fermati (evita di leggere versioni vecchie).
	git('merge --ff-only ' . escapeshellarg('origin/' . $branch));
	[$ahead, $behind] = originAheadBehind($branch);
	if ($behind > 0) {
		fail("Branch '$branch' non allineata a origin/$branch (behind=$behind). Fai pull/rebase e riprova.");
	}
	if ($ahead > 0) {
		// Caso comune: l'utente ha commit locali su main non ancora pushati.
		// Li pubblichiamo prima di procedere, così PR/merge hanno una base coerente.
		info("Branch '$branch' ahead di origin/$branch (ahead=$ahead): push automatico.");
		git('push origin ' . escapeshellarg($branch));
		[$ahead2, $behind2] = originAheadBehind($branch);
		if ($behind2 > 0) {
			fail("Branch '$branch' non allineata a origin/$branch dopo push (behind=$behind2).");
		}
		if ($ahead2 > 0) {
			fail("Branch '$branch' non allineata a origin/$branch dopo push (ahead=$ahead2).");
		}
	}
}

function listOpenIssues(string $owner, string $repo): array {
	$json = gh(
		'issue list --state open --limit 50 --repo ' . escapeshellarg("$owner/$repo") .
		' --json number,title,updatedAt,createdAt,labels'
	);
	$data = json_decode($json, true);
	if (!is_array($data)) {
		fail("Impossibile leggere lista issue (JSON).");
	}
	return $data;
}

function pickIssueNumber(string $owner, string $repo, array &$pickedRow = null): int {
	$issues = listOpenIssues($owner, $repo);
	if (count($issues) === 0) {
		fail("Nessuna issue aperta su $owner/$repo. Passa --issue <N> oppure aprine una.");
	}

	// Normalizza e ordina per updatedAt desc.
	usort($issues, function ($a, $b) {
		$ua = strtotime((string)($a['updatedAt'] ?? '')) ?: 0;
		$ub = strtotime((string)($b['updatedAt'] ?? '')) ?: 0;
		return $ub <=> $ua;
	});

	// Preferisci issue che hanno label bump.
	foreach ($issues as $row) {
		$labels = [];
		foreach (($row['labels'] ?? []) as $lab) {
			if (is_array($lab) && isset($lab['name'])) {
				$labels[] = (string)$lab['name'];
			}
		}
		if (determineBumpLevel($labels)) {
			$pickedRow = $row;
			return (int)$row['number'];
		}
	}

	// Fallback: prendi la più recente (ma poi fallirà se mancano label bump quando la carichiamo).
	$pickedRow = $issues[0];
	return (int)$issues[0]['number'];
}

function updateWpReadmeTxt(string $path, string $oldVersion, string $newVersion, string $date, int $issueNumber, string $issueTitle): void {
	if (!file_exists($path)) {
		return;
	}
	$raw = file_get_contents($path);
	if (!is_string($raw) || $raw === '') {
		return;
	}

	$before = $raw;
	$raw = preg_replace_callback('/^(Stable tag:\s*).+$/mi', function ($m) use ($newVersion) {
		return (string)($m[1] ?? 'Stable tag: ') . $newVersion;
	}, $raw);

	// Changelog: aggiungi una entry semplice in cima (sotto "== Changelog ==").
	$lines = preg_split('/\r\n|\r|\n/', $raw);
	if (!is_array($lines)) {
		return;
	}
	$out = [];
	$inChangelog = false;
	$inserted = false;
	for ($i = 0; $i < count($lines); $i++) {
		$line = $lines[$i];
		$out[] = $line;
		if (!$inChangelog && trim($line) === '== Changelog ==') {
			$inChangelog = true;
			// Inserisci subito dopo la riga "== Changelog ==".
			$out[] = '';
			$out[] = '= ' . $newVersion . ' =';
			$out[] = '* #' . $issueNumber . ': ' . $issueTitle;
			$out[] = '';
			$inserted = true;
			continue;
		}
	}

	if (!$inserted) {
		// Se non c'è sezione changelog, appende in fondo.
		$out[] = '';
		$out[] = '== Changelog ==';
		$out[] = '';
		$out[] = '= ' . $newVersion . ' =';
		$out[] = '* #' . $issueNumber . ': ' . $issueTitle;
		$out[] = '';
	}

	$after = implode("\n", $out);
	if ($after !== $before) {
		file_put_contents($path, $after);
		info("Aggiornato readme.txt (Stable tag + Changelog): $newVersion");
	}
}

function currentBranchName(): string {
	$lines = null;
	git('branch --show-current', $lines, true);
	$name = trim(implode("\n", $lines ?? []));
	return $name !== '' ? $name : 'DETACHED';
}

function currentHeadSha(): string {
	$lines = null;
	git('rev-parse HEAD', $lines);
	return trim(implode("\n", $lines ?? []));
}

function rollback(array $ctx, string $reason): void {
	info('Rollback: ' . $reason);

	// Best-effort remote cleanup (issue branch / PR / tag) — non tocca mai main remoto.
	if (!empty($ctx['prUrl']) && empty($ctx['merged'])) {
		// Chiude PR se l'abbiamo creata noi e non è stata mergiata.
		gh('pr close --repo ' . escapeshellarg($ctx['owner_repo']) . ' ' . escapeshellarg($ctx['prUrl']), $tmp, true);
	}
	if (!empty($ctx['pushedIssueBranch'])) {
		// Cancella la branch remota issue-*.
		git('push origin --delete ' . escapeshellarg($ctx['issueBranch']), $tmp, true);
	}
	if (!empty($ctx['pushedTag'])) {
		// Cancella tag remoto se siamo arrivati a pusharlo.
		git('push origin --delete ' . escapeshellarg($ctx['tagName']), $tmp, true);
	}

	// Ripristino stato locale: branch + commit iniziali.
	if (!empty($ctx['startBranch']) && $ctx['startBranch'] !== 'DETACHED') {
		git('checkout ' . escapeshellarg($ctx['startBranch']), $tmp, true);
	} else {
		git('checkout --detach ' . escapeshellarg($ctx['startHead']), $tmp, true);
	}
	git('reset --hard ' . escapeshellarg($ctx['startHead']), $tmp, true);

	// Elimina branch locale issue se creata.
	if (!empty($ctx['issueBranch'])) {
		git('branch -D ' . escapeshellarg($ctx['issueBranch']), $tmp, true);
	}

	// Ripristina eventuale stash creato all'avvio.
	if (!empty($ctx['stashRef'])) {
		git('stash apply ' . escapeshellarg($ctx['stashRef']), $tmp, true);
		git('stash drop ' . escapeshellarg($ctx['stashRef']), $tmp, true);
	}
}

try {
	$args = parseArgs($argv);

	$owner = getenv('GITHUB_OWNER') ?: 'ProgettoilSeme';
	$repo = getenv('GITHUB_REPO') ?: 'PBS';
	$defaultBranch = getenv('DEFAULT_BRANCH') ?: 'main';
	$pluginMain = getenv('PLUGIN_MAIN') ?: 'pbs.php';
	$readmePath = __DIR__ . '/../README.md';
	$wpReadmeTxtPath = __DIR__ . '/../readme.txt';

		$ctx = [
			'owner_repo' => "$owner/$repo",
			'startBranch' => '',
			'startHead' => '',
			'stashRef' => '',
			'stashAppliedToIssueBranch' => false,
			'issueBranch' => '',
			'pushedIssueBranch' => false,
			'prUrl' => '',
			'merged' => false,
			'tagName' => '',
		'pushedTag' => false,
	];

	// Sanity: repo git
	git('rev-parse --is-inside-work-tree', $tmp);
	$ctx['startBranch'] = currentBranchName();
	$ctx['startHead'] = currentHeadSha();

	// Stash se dirty (per garantire rollback completo).
	// git() accetta outLines by-ref (array): convertiamo a stringa solo dopo.
	$stLines = null;
	git('status --porcelain', $stLines);
	$st = trim(implode("\n", $stLines ?? []));
	if ($st !== '') {
		info('Working tree sporco: stash temporaneo (inclusi untracked).');
		git('stash push -u -m ' . escapeshellarg('bump-with-issue auto-stash'));
		$lines = null;
		git('rev-parse stash@{0}', $lines);
		$ctx['stashRef'] = trim(implode("\n", $lines ?? []));
	}

	// Sanity: gh
	gh('--version', $tmp);

	if ($args['init_labels']) {
		ensureBumpLabels($owner, $repo);
		info("Label bump pronte.");
	}

	// Seleziona issue (esplicita o automatica)
	$pickedRow = null;
	$issueNum = $args['issue'] !== null ? (int)$args['issue'] : pickIssueNumber($owner, $repo, $pickedRow);
	if ($args['issue'] === null) {
		info("Auto-pick issue: #$issueNum");
		// Stampa contesto utile per capire cosa è stata scelta.
		$open = listOpenIssues($owner, $repo);
		usort($open, function ($a, $b) {
			$ua = strtotime((string)($a['updatedAt'] ?? '')) ?: 0;
			$ub = strtotime((string)($b['updatedAt'] ?? '')) ?: 0;
			return $ub <=> $ua;
		});
		info("Issue aperte (più recenti in alto):");
		foreach ($open as $row) {
			$n = (int)($row['number'] ?? 0);
			$t = trim((string)($row['title'] ?? ''));
			$labels = [];
			foreach (($row['labels'] ?? []) as $lab) {
				if (is_array($lab) && isset($lab['name'])) {
					$labels[] = (string)$lab['name'];
				}
			}
			$bump = determineBumpLevel($labels);
			$mark = ($n === $issueNum) ? '👉' : '  ';
			$labTxt = '';
			if ($bump) {
				$labTxt = " [bump:$bump]";
			} elseif (count($labels) > 0) {
				$labTxt = ' [' . implode(',', $labels) . ']';
			}
			fwrite(STDOUT, sprintf("%s #%d %s%s\n", $mark, $n, $t, $labTxt));
		}
	}

	// Carica issue (JSON) da gh
	info("Leggo issue #$issueNum da $owner/$repo");
	$json = gh('issue view ' . escapeshellarg((string)$issueNum) . ' --repo ' . escapeshellarg("$owner/$repo") . ' --json title,body,labels,number');
	$data = json_decode($json, true);
	if (!is_array($data)) {
		fail("Risposta gh non valida (JSON).");
	}

	$issueTitle = trim((string)($data['title'] ?? ''));
	$labels = [];
	foreach (($data['labels'] ?? []) as $lab) {
		if (is_array($lab) && isset($lab['name'])) {
			$labels[] = (string)$lab['name'];
		}
	}
	$bump = determineBumpLevel($labels);
	if (!$bump) {
		fail("La issue #$issueNum non ha label bump (major|minor|patch).");
	}

	// Branch + sync base (prima di leggere la versione!)
	$pluginPath = __DIR__ . '/../' . $pluginMain;
	$branch = 'issue-' . $issueNum . '-' . slugify($issueTitle);
	$ctx['issueBranch'] = $branch;

	// Allinea la base al branch di default (non ignorare errori).
	syncToOriginBranch($defaultBranch);

	// Ora la versione è quella della base aggiornata.
	$currentVersion = readPluginVersion($pluginPath);
	$nextVersion = bumpVersion($currentVersion, $bump);
		info("Bump: $bump ($currentVersion -> $nextVersion)");

		if (!empty($args['plan'])) {
			info("Stop (--plan). Nessuna modifica applicata.");
			exit(0);
		}

		git('checkout -B ' . escapeshellarg($branch));

		// Se avevamo modifiche locali (stash), applicale sul branch della issue:
		// l'intento del bump è committare anche i file "già pronti" (es. doc).
		if (!empty($ctx['stashRef'])) {
			info('Ripristino modifiche locali sul branch di lavoro.');
			git('stash apply ' . escapeshellarg($ctx['stashRef']));
			$ctx['stashAppliedToIssueBranch'] = true;
		}

	// Applica bump + changelog
	$date = gmdate('Y-m-d'); // ISO per changelog e logiche interne
	writePluginVersion($pluginPath, $currentVersion, $nextVersion);
	writePluginReleased($pluginPath, formatDateIt($date)); // umano IT nel plugin header
	updateReadmePluginMeta($readmePath, $nextVersion, $date);
	ensureChangelogInReadme($readmePath);
	prependChangelogEntry($readmePath, $nextVersion, $date, $issueNum, $issueTitle);
	updateWpReadmeTxt($wpReadmeTxtPath, $currentVersion, $nextVersion, $date, $issueNum, $issueTitle);

	if (!empty($args['no_commit'])) {
		info("Stop (--no-commit). Modifiche applicate ai file, nessun commit/push/PR.");
		exit(0);
	}

	// Commit
	git('add -A');
	$msg = "#{$issueNum} {$issueTitle} - v{$nextVersion}, " . formatDateIt($date);
	git('commit -m ' . escapeshellarg($msg));

	// Push branch (force-with-lease se esiste già)
	info("Push branch: $branch");
	$repoDir = getcwd();
	$pushCmd = 'git -c safe.directory=' . escapeshellarg($repoDir) . ' push -u origin ' . escapeshellarg($branch);
	$out = null;
	$err = null;
	$code = sh_exit_code($pushCmd, $out, $err);
	if ($code !== 0) {
		$combined = trim((string)$err . "\n" . (string)$out);
		if (stripos($combined, 'non-fast-forward') !== false || stripos($combined, 'non fast-forward') !== false) {
			info("Push non-fast-forward: ritento con --force-with-lease (branch issue).");
			$pushForceCmd = 'git -c safe.directory=' . escapeshellarg($repoDir) . ' push --force-with-lease -u origin ' . escapeshellarg($branch);
			$code2 = sh_exit_code($pushForceCmd, $out2, $err2);
			if ($code2 !== 0) {
				$combined2 = trim((string)$err2 . "\n" . (string)$out2);
				fail("Push fallito anche con --force-with-lease.\n$combined2");
			}
		} else {
			fail("Push fallito.\n$combined");
		}
	}
	$ctx['pushedIssueBranch'] = true;

	// PR
	$prTitle = $msg;
	$prBody = "Closes #{$issueNum}\n\nBump: **{$bump}** → `v{$nextVersion}`\n";
	info("Creo PR verso $defaultBranch");
	$prUrl = trim(gh(
		'pr create --repo ' . escapeshellarg("$owner/$repo") .
		' --base ' . escapeshellarg($defaultBranch) .
		' --head ' . escapeshellarg($branch) .
		' --title ' . escapeshellarg($prTitle) .
		' --body ' . escapeshellarg($prBody)
	));
	$ctx['prUrl'] = $prUrl;
	info("PR: $prUrl");

	if ($args['no_merge']) {
		info("Stop (no merge).");
		exit(0);
	}

	// Merge squash
	info("Merge PR (squash) + delete branch");
	gh('pr merge --repo ' . escapeshellarg("$owner/$repo") . ' --squash --delete-branch --subject ' . escapeshellarg($prTitle) . ' ' . escapeshellarg($prUrl));
	$ctx['merged'] = true;
	$ctx['pushedIssueBranch'] = false; // branch remota eliminata da gh

	// Chiudi issue (di solito “Closes #N” la chiude già, ma rendiamo esplicito).
	info("Chiudo issue #$issueNum");
	gh('issue close --repo ' . escapeshellarg("$owner/$repo") . ' ' . escapeshellarg((string)$issueNum), $tmp, true);

	// Aggiorna main e tag
	syncToOriginBranch($defaultBranch);

	if (!$args['no_tag']) {
		$tag = 'v' . $nextVersion;
		$ctx['tagName'] = $tag;
		info("Tag: $tag");
		git('tag ' . escapeshellarg($tag));
		git('push origin ' . escapeshellarg($tag));
		$ctx['pushedTag'] = true;
	}

	if (!$args['no_release']) {
		$tag = 'v' . $nextVersion;
		info("Release GitHub: $tag");
		$notes = "Release {$tag}\n\n- #{$issueNum}: {$issueTitle}\n";
		gh('release create --repo ' . escapeshellarg("$owner/$repo") . ' ' . escapeshellarg($tag) . ' --title ' . escapeshellarg($tag) . ' --notes ' . escapeshellarg($notes));
	}

	// Ripristina la situazione iniziale utente (branch + eventuale stash).
		if ($ctx['startBranch'] !== 'DETACHED') {
			git('checkout ' . escapeshellarg($ctx['startBranch']), $tmp, true);
		} else {
			git('checkout --detach ' . escapeshellarg($ctx['startHead']), $tmp, true);
		}
		if (!empty($ctx['stashRef'])) {
			if (empty($ctx['stashAppliedToIssueBranch'])) {
				git('stash apply ' . escapeshellarg($ctx['stashRef']), $tmp, true);
			}
			git('stash drop ' . escapeshellarg($ctx['stashRef']), $tmp, true);
		}

		info("OK ✅");
		exit(0);
	} catch (BumpScriptException $e) {
		// Best-effort rollback se abbiamo un contesto.
		if (isset($ctx) && is_array($ctx) && !empty($ctx['startHead'])) {
			rollback($ctx, $e->getMessage());
		}
		fwrite(STDERR, "❌ " . $e->getMessage() . "\n");
		exit($e->exitCode ?? 1);
	} catch (Throwable $e) {
		if (isset($ctx) && is_array($ctx) && !empty($ctx['startHead'])) {
			rollback($ctx, $e->getMessage());
		}
		fwrite(STDERR, "❌ Errore inatteso: " . $e->getMessage() . "\n");
		exit(1);
	}
