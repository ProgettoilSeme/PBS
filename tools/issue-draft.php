#!/usr/bin/env php
<?php
/**
 * issue-draft.php — crea uno scheletro .md per una Issue GitHub.
 *
 * Uso:
 *   php tools/issue-draft.php -t "Titolo issue"
 *   php tools/issue-draft.php -t "Titolo" --labels "patch,type:fix"
 *
 * Note:
 * - Se non specifichi label bump, default: patch.
 * - Lo scheletro è pensato per essere completato in VSCode, poi pubblicato con tools/issue-publish.php.
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

function slugify(string $s): string {
	$s = strtolower(trim($s));
	$s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s);
	$s = trim((string)$s, '-');
	if ($s === '') {
		return 'issue';
	}
	return substr($s, 0, 60);
}

function parseArgs(array $argv): array {
	$title = '';
	$labels = '';
	for ($i = 1; $i < count($argv); $i++) {
		$a = $argv[$i];
		if (($a === '-t' || $a === '--title') && isset($argv[$i + 1])) {
			$title = (string)$argv[++$i];
			continue;
		}
		if ($a === '--labels' && isset($argv[$i + 1])) {
			$labels = (string)$argv[++$i];
			continue;
		}
		if ($a === '--help' || $a === '-h') {
			fwrite(STDOUT, "Uso: php tools/issue-draft.php -t \"Titolo\" [--labels \"patch,type:fix\"]\n");
			exit(0);
		}
		fail("Argomento non riconosciuto: $a", EXIT_USAGE);
	}
	$title = trim($title);
	if ($title === '') {
		fail("Titolo mancante. Usa -t \"Titolo issue\".", EXIT_USAGE);
	}
	return [
		'title' => $title,
		'labels' => trim($labels),
	];
}

$args = parseArgs($argv);
$title = $args['title'];
$labelsRaw = $args['labels'];

$labels = [];
if ($labelsRaw !== '') {
	foreach (explode(',', $labelsRaw) as $p) {
		$p = trim($p);
		if ($p !== '') {
			$labels[] = $p;
		}
	}
}
// Default bump label
if (!in_array('major', $labels, true) && !in_array('minor', $labels, true) && !in_array('patch', $labels, true)) {
	$labels[] = 'patch';
}

$date = gmdate('Y-m-d');
$slug = slugify($title);
$baseDir = __DIR__ . '/issues/drafts';
if (!is_dir($baseDir)) {
	if (!mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
		fail("Impossibile creare directory: $baseDir");
	}
}

$path = $baseDir . '/' . $date . '--' . $slug . '.md';
if (file_exists($path)) {
	$i = 2;
	while (file_exists($baseDir . '/' . $date . '--' . $slug . '-' . $i . '.md')) {
		$i++;
	}
	$path = $baseDir . '/' . $date . '--' . $slug . '-' . $i . '.md';
}

$front = [];
$front[] = '---';
$front[] = 'title: ' . json_encode($title, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$front[] = 'labels:';
foreach ($labels as $lab) {
	$front[] = '  - ' . $lab;
}
$front[] = '---';

$body = [];
$body[] = '';
$body[] = '## Contesto';
$body[] = '';
$body[] = '- (spiega in 2 righe perché serve)';
$body[] = '';
$body[] = '## Obiettivo';
$body[] = '';
$body[] = '- (cosa deve essere vero a fine lavoro)';
$body[] = '';
$body[] = '## Note tecniche';
$body[] = '';
$body[] = '- (se serve: file/endpoint/screenshot)';
$body[] = '';
$body[] = '## Checklist (opzionale)';
$body[] = '';
$body[] = '- [ ] ...';
$body[] = '';
$body[] = '> Label bump richieste: major | minor | patch (default: patch).';
$body[] = '> Pubblica con: php tools/issue-publish.php --file "' . basename($path) . '"';
$body[] = '';

$content = implode("\n", array_merge($front, $body));
file_put_contents($path, $content);

info("Creato draft: $path");

