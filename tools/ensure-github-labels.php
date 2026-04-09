#!/usr/bin/env php
<?php
/**
 * Ensure GitHub labels exist for this repository.
 *
 * Creates the minimal label set used by tools/bump-version.php:
 *  - major, minor, patch, bug
 *
 * Usage:
 *   php tools/ensure-github-labels.php
 *
 * Env:
 *   GITHUB_TOKEN=... (required)
 */

$githubOwner = getenv('GITHUB_OWNER') ?: "ProgettoilSeme";
$githubRepo  = getenv('GITHUB_REPO') ?: "PBS";
$githubToken = getenv("GITHUB_TOKEN") ?: null;

function fail(string $msg): void
{
    fwrite(STDERR, "❌ Errore: $msg\n");
    exit(1);
}

if (!$githubToken) {
    fail("GITHUB_TOKEN mancante. Esporta un token GitHub con permessi sul repo (issues/metadata). ");
}

function github_request(string $method, string $url, ?string $token, ?array $jsonBody = null): array
{
    if (!function_exists("curl_init")) {
        fail("PHP cURL non disponibile");
    }

    $headers = [
        "User-Agent: PBS-EnsureLabels/1.0",
        "Accept: application/vnd.github+json",
        "X-GitHub-Api-Version: 2022-11-28",
    ];
    if ($token) {
        $headers[] = "Authorization: Bearer $token";
    }

    $body = null;
    if ($jsonBody !== null) {
        $body = json_encode($jsonBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            fail("Impossibile serializzare JSON body");
        }
        $headers[] = "Content-Type: application/json";
    }

    $respHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$respHeaders) {
            $len = strlen($header);
            $parts = explode(":", $header, 2);
            if (count($parts) == 2) {
                $name = strtolower(trim($parts[0]));
                $value = trim($parts[1]);
                $respHeaders[$name] = isset($respHeaders[$name]) ? ($respHeaders[$name] . ", " . $value) : $value;
            }
            return $len;
        },
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        fail("cURL error: " . $err);
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($response, true);
    return [$httpCode, $json, $response, $respHeaders];
}

function list_labels(string $owner, string $repo, string $token): array
{
    $labels = [];
    $url = "https://api.github.com/repos/$owner/$repo/labels?per_page=100";

    while ($url) {
        [$code, $json, $raw, $hdrs] = github_request("GET", $url, $token, null);
        if ($code !== 200 || !is_array($json)) {
            $msg = is_array($json) ? ($json["message"] ?? "Errore") : "Errore";
            fail("GitHub labels list failed (HTTP $code): $msg");
        }
        $labels = array_merge($labels, $json);

        $link = $hdrs["link"] ?? "";
        $next = null;
        if ($link) {
            foreach (explode(",", $link) as $part) {
                $section = explode(";", trim($part));
                if (count($section) < 2) {
                    continue;
                }
                $u = trim($section[0], "<> ");
                $rel = "";
                for ($i = 1; $i < count($section); $i++) {
                    $seg = trim($section[$i]);
                    if (stripos($seg, "rel=") === 0) {
                        $rel = trim(substr($seg, 4), "\" ");
                    }
                }
                if ($rel === "next") {
                    $next = $u;
                }
            }
        }
        $url = $next;
    }

    return $labels;
}

function create_label(string $owner, string $repo, string $token, string $name, string $color, string $description): void
{
    $url = "https://api.github.com/repos/$owner/$repo/labels";
    [$code, $json] = github_request("POST", $url, $token, [
        "name" => $name,
        "color" => $color,
        "description" => $description,
    ]);

    if (!in_array($code, [201, 422], true)) {
        $msg = is_array($json) ? ($json["message"] ?? "Errore") : "Errore";
        fail("Create label \"$name\" failed (HTTP $code): $msg");
    }

    if ($code == 201) {
        echo "✅ Created label: $name\n";
    } else {
        echo "ℹ️ Label already exists or cannot be created: $name (HTTP 422)\n";
    }
}

$required = [
    "major" => ["b60205", "Breaking changes / major release"],
    "minor" => ["0e8a16", "New features / minor release"],
    "patch" => ["1d76db", "Fixes / patch release"],
    "bug"   => ["d73a4a", "Bug fix (treated as patch)"],
];

echo "🔧 Ensure GitHub labels for $githubOwner/$githubRepo\n";

$existing = list_labels($githubOwner, $githubRepo, $githubToken);
$existingNames = [];
foreach ($existing as $lbl) {
    $n = strtolower(trim((string)($lbl["name"] ?? "")));
    if ($n !== "") {
        $existingNames[$n] = true;
    }
}

$missing = [];
foreach ($required as $name => $meta) {
    if (!isset($existingNames[$name])) {
        $missing[] = $name;
    }
}

if (!$missing) {
    echo "✅ All required labels already exist.\n";
    exit(0);
}

echo "⚠️ Missing labels: " . implode(", ", $missing) . "\n";

echo "➡️ Creating missing labels...\n";
foreach ($missing as $name) {
    [$color, $desc] = $required[$name];
    create_label($githubOwner, $githubRepo, $githubToken, $name, $color, $desc);
}

echo "🎉 Done.\n";
