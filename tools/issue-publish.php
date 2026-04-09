#!/usr/bin/env php
<?php
/**
 * issue-publish.php — pubblica una Issue GitHub da un draft .md (front matter + body).
 *
 * Uso:
 *   php tools/issue-publish.php                 # prende il draft più recente
 *   php tools/issue-publish.php --file <path>   # usa un draft specifico
 *
 * Se la pubblicazione va a buon fine:
 * - rinomina e sposta il file in tools/issues/archive/
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
	for ($i = 1; $i < count($argv); $i++) {
		$a = $argv[$i];
		if ($a === '--file' && isset($argv[$i + 1])) {
			$file = (string)$argv[++$i];
			continue;
		}
		if ($a === '--help' || $a === '-h') {
			fwrite(STDOUT, "Uso: php tools/issue-publish.php [--file <path>]\n");
			exit(0);
		}
		fail("Argomento non riconosciuto: $a", EXIT_USAGE);
	}
	return ['file' => trim($file)];
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
			// title può essere JSON string (come scrive issue-draft.php) o testo.
			$decoded = json_decode($val, true);
			$meta['title'] = is_string($decoded) ? $decoded : trim($val, '"\'');
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

function pickLatestDraft(string $draftDir): string {
	if (!is_dir($draftDir)) {
		fail("Nessun draft trovato (directory mancante): $draftDir");
	}
	$files = glob(rtrim($draftDir, '/') . '/*.md');
	if (!$files) {
		fail("Nessun draft .md trovato in: $draftDir");
	}
	usort($files, function ($a, $b) {
		return filemtime($b) <=> filemtime($a);
	});
	return (string)$files[0];
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

$args = parseArgs($argv);
$owner = getenv('GITHUB_OWNER') ?: 'ProgettoilSeme';
$repo = getenv('GITHUB_REPO') ?: 'PBS';
$ownerRepo = $owner . '/' . $repo;

$draftDir = __DIR__ . '/issues/drafts';
$file = $args['file'] !== '' ? $args['file'] : pickLatestDraft($draftDir);

// Se passa solo un basename, risolvi dentro drafts.
if (!str_contains($file, '/')) {
	$file = $draftDir . '/' . $file;
}
if (!is_readable($file)) {
	fail("Draft non leggibile: $file");
}

gh('--version', $tmp);

$raw = file_get_contents($file);
if (!is_string($raw)) {
	fail("Impossibile leggere draft: $file");
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

// Valida labels per evitare refusi.
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

// Crea file temporaneo con body (senza front matter).
$tmpBody = tempnam(sys_get_temp_dir(), 'pbs-issue-body-');
if (!$tmpBody) {
	fail("Impossibile creare tmp file.");
}
file_put_contents($tmpBody, trim($body) . "\n");

info("Creo issue su $ownerRepo: $title");
$labelArgs = '';
foreach ($labels as $lab) {
	$labelArgs .= ' --label ' . escapeshellarg((string)$lab);
}
$url = trim(gh(
	'issue create --repo ' . escapeshellarg($ownerRepo) .
	' --title ' . escapeshellarg($title) .
	' --body-file ' . escapeshellarg($tmpBody) .
	$labelArgs
));
@unlink($tmpBody);

if ($url === '') {
	fail("gh issue create non ha restituito un URL.");
}
info("Issue: $url");

// Estrai numero issue.
$num = 0;
if (preg_match('~/issues/([0-9]+)~', $url, $m)) {
	$num = (int)$m[1];
}

// Archivia draft.
$archiveDir = __DIR__ . '/issues/archive';
if (!is_dir($archiveDir)) {
	mkdir($archiveDir, 0775, true);
}
$baseName = basename($file);
$newName = $baseName;
if ($num > 0) {
	$newName = preg_replace('/\.md$/', '', $baseName) . '--issue-' . $num . '.md';
}
$dest = rtrim($archiveDir, '/') . '/' . $newName;
if (!rename($file, $dest)) {
	fail("Issue creata, ma non riesco ad archiviare il draft.\nSource: $file\nDest: $dest");
}
info("Archiviato draft: $dest");

