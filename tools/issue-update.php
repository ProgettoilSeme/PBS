#!/usr/bin/env php
<?php
/**
 * issue-update.php — aggiorna una Issue GitHub esistente da un file .md (front matter + body).
 *
 * Uso:
 *   php tools/issue-update.php --issue <N> --file <path>
 *   php tools/issue-update.php --file tools/issues/archive/<...--issue-N.md>   # ricava N dal nome file
 *
 * Nota:
 * - Non crea nuove issue.
 * - Aggiorna title/body e, se presenti, labels.
 */
declare(strict_types=1);

const EXIT_USAGE = 2;

function fail(string $msg, int $code = 1): void {
	fwrite(STDERR, "❌ $msg\n");
	exit($code);
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
		$err = trim((string)$stderr);
		if ($err === '') {
			$err = trim((string)$stdout);
		}
		fail("Comando fallito ($exit): $cmd\n$err");
	}
	$outLines = array_values(array_filter(preg_split('/\r\n|\r|\n/', (string)$stdout)));
	return (string)$stdout;
}

function gh(string $args, ?array &$outLines = null, bool $allowFail = false): string {
	return sh('gh ' . $args, $outLines, $allowFail);
}

function parseArgs(array $argv): array {
	$file = '';
	$issue = 0;
	for ($i = 1; $i < count($argv); $i++) {
		$a = (string)$argv[$i];
		if ($a === '--file' && isset($argv[$i + 1])) {
			$file = trim((string)$argv[++$i]);
			continue;
		}
		if ($a === '--issue' && isset($argv[$i + 1])) {
			$issue = (int) $argv[++$i];
			continue;
		}
		if ($a === '--help' || $a === '-h') {
			fwrite(STDOUT, "Uso: php tools/issue-update.php --file <path> [--issue <N>]\n");
			exit(0);
		}
		fail("Argomento non riconosciuto: $a", EXIT_USAGE);
	}
	if ($file === '') {
		fail("Parametro mancante: --file <path>", EXIT_USAGE);
	}
	return ['file' => $file, 'issue' => $issue];
}

function readFrontMatter(string $raw): array {
	$lines = preg_split('/\r\n|\r|\n/', $raw);
	if (!is_array($lines) || count($lines) < 3) {
		return [[], $raw];
	}
	if (trim((string)$lines[0]) !== '---') {
		return [[], $raw];
	}
	$meta = [];
	$labels = [];
	$inLabels = false;
	$bodyStart = 0;
	for ($i = 1; $i < count($lines); $i++) {
		$line = (string)$lines[$i];
		if (trim($line) === '---') {
			$bodyStart = $i + 1;
			break;
		}
		if (preg_match('/^\s*title:\s*(.+)\s*$/', $line, $m)) {
			$inLabels = false;
			$val = trim((string)$m[1]);
			$decoded = json_decode($val, true);
			$meta['title'] = is_string($decoded) ? $decoded : trim($val, "\"'");
			continue;
		}
		if (preg_match('/^\s*labels:\s*$/', $line)) {
			$inLabels = true;
			continue;
		}
		if ($inLabels && preg_match('/^\s*-\s*(.+)\s*$/', $line, $m)) {
			$lab = trim((string)$m[1]);
			if ($lab !== '') {
				$labels[] = $lab;
			}
			continue;
		}
		$inLabels = false;
	}
	if (!empty($labels)) {
		$meta['labels'] = $labels;
	}
	$body = implode("\n", array_slice($lines, $bodyStart));
	return [$meta, $body];
}

function ensureBumpLabel(array &$labels): void {
	$has = false;
	foreach ($labels as $l) {
		$ll = strtolower(trim((string)$l));
		if ($ll === 'major' || $ll === 'minor' || $ll === 'patch') {
			$has = true;
			break;
		}
	}
	if (!$has) {
		$labels[] = 'patch';
	}
}

function fetchRepoLabels(string $ownerRepo): array {
	gh('label list --limit 200 --repo ' . escapeshellarg($ownerRepo), $lines);
	$existing = [];
	foreach ($lines as $line) {
		$parts = preg_split("/\t+/", (string)$line);
		if (!$parts || !isset($parts[0])) {
			continue;
		}
		$existing[strtolower(trim((string)$parts[0]))] = true;
	}
	return $existing;
}

function inferIssueFromFilename(string $path): int {
	if (preg_match('/--issue-(\d+)\.md$/', $path, $m)) {
		return (int) $m[1];
	}
	return 0;
}

$args = parseArgs($argv);
$owner = getenv('GITHUB_OWNER') ?: 'ProgettoilSeme';
$repo = getenv('GITHUB_REPO') ?: 'PBS';
$ownerRepo = $owner . '/' . $repo;

$file = $args['file'];
if (!str_contains($file, '/')) {
	// Se passa solo un basename, prova in drafts e archive.
	$draft = __DIR__ . '/issues/drafts/' . $file;
	$arch  = __DIR__ . '/issues/archive/' . $file;
	if (is_readable($draft)) $file = $draft;
	elseif (is_readable($arch)) $file = $arch;
}
if (!is_readable($file)) {
	fail("File non leggibile: $file");
}

$issue = (int) $args['issue'];
if ($issue <= 0) {
	$issue = inferIssueFromFilename($file);
}
if ($issue <= 0) {
	fail("Numero issue mancante: passa --issue <N> oppure usa un filename con suffisso --issue-N.md", EXIT_USAGE);
}

gh('--version', $tmp);

$raw = file_get_contents($file);
if (!is_string($raw)) {
	fail("Impossibile leggere file: $file");
}

[$meta, $body] = readFrontMatter($raw);
$title = trim((string)($meta['title'] ?? ''));
if ($title === '') {
	fail("Front matter: title mancante in $file");
}

$labels = $meta['labels'] ?? [];
if (!is_array($labels)) {
	$labels = [];
}
ensureBumpLabel($labels);

// Valida labels per evitare refusi (solo se sono state fornite).
if (!empty($labels)) {
	$repoLabels = fetchRepoLabels($ownerRepo);
	$bad = [];
	foreach ($labels as $lab) {
		$k = strtolower(trim((string)$lab));
		if ($k === '') continue;
		if (!isset($repoLabels[$k])) {
			$bad[] = (string)$lab;
		}
	}
	if (!empty($bad)) {
		fail("Label non valide (non esistono nel repo): " . implode(', ', $bad) . "\nCrea le label con: php tools/init-labels-gh.php");
	}
}

// Crea file temporaneo con body (senza front matter).
$tmpBody = tempnam(sys_get_temp_dir(), 'pbs-issue-body-');
if (!$tmpBody) {
	fail("Impossibile creare tmp file.");
}
file_put_contents($tmpBody, trim($body) . "\n");

info("Aggiorno issue #$issue su $ownerRepo: $title");

// Aggiorna title/body.
gh('issue edit ' . (int)$issue
	. ' --repo ' . escapeshellarg($ownerRepo)
	. ' --title ' . escapeshellarg($title)
	. ' --body-file ' . escapeshellarg($tmpBody)
);

// Aggiorna labels (best effort): rimuovi tutte e riapplica solo quelle definite.
// Nota: GitHub CLI non ha un "set labels" atomico; facciamo sync rimuovendo e aggiungendo.
if (!empty($labels)) {
	gh('issue edit ' . (int)$issue . ' --repo ' . escapeshellarg($ownerRepo) . ' --remove-label \"*\"', $out, true);
	foreach ($labels as $lab) {
		gh('issue edit ' . (int)$issue . ' --repo ' . escapeshellarg($ownerRepo) . ' --add-label ' . escapeshellarg((string)$lab));
	}
}

@unlink($tmpBody);
info("Done.");

