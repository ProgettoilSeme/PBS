#!/usr/bin/env php
<?php
/**
 * init-labels-gh.php — crea le label GitHub necessarie al versionamento.
 *
 * Uso:
 *   php tools/init-labels-gh.php
 *   php tools/init-labels-gh.php --extra
 *
 * Requisiti:
 * - gh (GitHub CLI) autenticato (gh auth login)
 *
 * Env (opzionali):
 * - GITHUB_OWNER (default: ProgettoilSeme)
 * - GITHUB_REPO  (default: PBS)
 */
declare(strict_types=1);

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
	$extra = false;
	for ($i = 1; $i < count($argv); $i++) {
		$a = $argv[$i];
		if ($a === '--extra') {
			$extra = true;
			continue;
		}
		if ($a === '--help' || $a === '-h') {
			fwrite(STDOUT, "Uso: php tools/init-labels-gh.php [--extra]\n");
			exit(0);
		}
		fail("Argomento non riconosciuto: $a", 2);
	}
	return ['extra' => $extra];
}

function ensureLabels(string $owner, string $repo, array $wanted): void {
	gh('--version', $tmp);
	gh('auth status', $tmp, true);

	info("Leggo label esistenti su $owner/$repo");
	gh('label list --limit 200 --repo ' . escapeshellarg("$owner/$repo"), $lines);
	$existing = [];
	foreach ($lines as $line) {
		$parts = preg_split("/\t+/", $line);
		if (!$parts || !isset($parts[0])) {
			continue;
		}
		$existing[strtolower(trim($parts[0]))] = true;
	}

	foreach ($wanted as $lab) {
		$name = strtolower($lab['name']);
		if (isset($existing[$name])) {
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

$args = parseArgs($argv);
$owner = getenv('GITHUB_OWNER') ?: 'ProgettoilSeme';
$repo = getenv('GITHUB_REPO') ?: 'PBS';

$wanted = [
	['name' => 'major', 'color' => 'b60205', 'description' => 'Bump major (breaking changes)'],
	['name' => 'minor', 'color' => '0e8a16', 'description' => 'Bump minor (feature)'],
	['name' => 'patch', 'color' => '1d76db', 'description' => 'Bump patch (fix)'],
];

if ($args['extra']) {
	$wanted = array_merge($wanted, [
		['name' => 'type:feature', 'color' => '0e8a16', 'description' => 'Feature'],
		['name' => 'type:fix', 'color' => '1d76db', 'description' => 'Bugfix'],
		['name' => 'type:chore', 'color' => '6a737d', 'description' => 'Chore/maintenance'],
	]);
}

ensureLabels($owner, $repo, $wanted);
info("OK ✅");

