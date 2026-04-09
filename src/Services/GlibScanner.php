<?php

declare(strict_types=1);

namespace PBS\Services;

final class GlibScanner
{
    /**
     * @param string[] $whitelist Plugin directory slugs.
     * @return array<int,array<string,mixed>>
     */
    public function scan(array $whitelist): array
    {
        $out = [];
        $base = defined('WP_PLUGIN_DIR') ? (string) WP_PLUGIN_DIR : '';
        $base = rtrim($base, '/');

        foreach ($whitelist as $slug) {
            $slug = trim((string) $slug);
            if ($slug === '' || str_contains($slug, '..') || str_contains($slug, '/')) {
                continue;
            }

            $pluginDir = $base !== '' ? ($base . '/' . $slug) : '';
            if ($pluginDir === '' || !is_dir($pluginDir)) {
                $out[] = [
                    'plugin_slug' => $slug,
                    'plugin_dir' => $pluginDir,
                    'status' => 'missing',
                    'glib_dirs' => [],
                ];
                continue;
            }

            $glibDirs = $this->discover_glib_dirs($pluginDir);
            $out[] = [
                'plugin_slug' => $slug,
                'plugin_dir' => $pluginDir,
                'status' => $glibDirs ? 'found' : 'none',
                'glib_dirs' => $glibDirs,
            ];
        }

        return $out;
    }

    /**
     * Scan a single plugin slug.
     *
     * @return array{plugin_slug:string,plugin_dir:string,status:string,glib_dirs:array<int,array<string,mixed>>}
     */
    public function scan_one(string $pluginSlug): array
    {
        return $this->scan([$pluginSlug])[0] ?? [
            'plugin_slug' => $pluginSlug,
            'plugin_dir' => (defined('WP_PLUGIN_DIR') ? rtrim((string) WP_PLUGIN_DIR, '/') : '') . '/' . $pluginSlug,
            'status' => 'missing',
            'glib_dirs' => [],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function discover_glib_dirs(string $pluginDir): array
    {
        $dirs = [];
        $entries = @scandir($pluginDir);
        if (!is_array($entries)) {
            return $dirs;
        }

        foreach ($entries as $e) {
            if (!is_string($e) || $e === '.' || $e === '..') {
                continue;
            }
            if ($e !== 'gLib' && !preg_match('/^gLib_f\\d{2,}$/', $e)) {
                continue;
            }
            $full = $pluginDir . '/' . $e;
            if (!is_dir($full)) {
                continue;
            }
            $dirs[] = $this->analyze_glib_dir($full, $e);
        }

        usort($dirs, function (array $a, array $b): int {
            return (int) ($b['suffix'] ?? 0) <=> (int) ($a['suffix'] ?? 0);
        });

        return $dirs;
    }

    /**
     * @return array<string,mixed>
     */
    private function analyze_glib_dir(string $fullDir, string $dirName): array
    {
        $initPath = $this->find_case_insensitive($fullDir, 'Init.php');
        $baseControllerPath = $fullDir . '/Supports/Components/Admin/BaseController.php';

        $hasInit = $initPath !== null;
        $hasServicesDir = is_dir($fullDir . '/Api/Services');
        $hasInterfacesDir = is_dir($fullDir . '/Api/Interfaces');
        $hasBaseController = is_file($baseControllerPath);

        $deep = [
            'ok' => false,
            'blockers' => [],
            'warnings' => [],
            'evidence' => [],
        ];

        if (!$hasInit) {
            $deep['blockers'][] = 'Init.php mancante.';
        }
        if (!$hasServicesDir) {
            $deep['blockers'][] = 'Cartella Api/Services mancante.';
        }
        if (!$hasInterfacesDir) {
            $deep['warnings'][] = 'Cartella Api/Interfaces mancante (verifica standard gLib).';
        }
        if (!$hasBaseController) {
            $deep['blockers'][] = 'Supports/Components/Admin/BaseController.php mancante.';
        }

        $namespace = '';
        if ($hasInit && is_string($initPath)) {
            $txt = (string) @file_get_contents($initPath);
            $namespace = $this->extract_namespace($txt);
            if ($namespace === '') {
                $deep['warnings'][] = 'Namespace non rilevato in Init.php.';
            }
            if (!str_contains($txt, 'class Init')) {
                $deep['warnings'][] = 'Init.php non contiene "class Init" (verifica compatibilità).';
            }
            if (!str_contains($txt, 'get_services')) {
                $deep['warnings'][] = 'Init.php non contiene get_services() (verifica compatibilità).';
            }
            $deep['evidence']['init'] = $initPath;
        }

        if ($hasBaseController) {
            $txt = (string) @file_get_contents($baseControllerPath);
            if (!str_contains($txt, 'srv_managers')) {
                $deep['warnings'][] = 'BaseController.php non contiene $srv_managers (pattern LSA).';
            }
            $deep['evidence']['base_controller'] = $baseControllerPath;
        }

        $deep['ok'] = empty($deep['blockers']);

        $suffix = 0;
        if (preg_match('/^gLib_f(\\d+)$/', $dirName, $m)) {
            $suffix = (int) $m[1];
        }

        return [
            'dir' => $dirName,
            'full_dir' => $fullDir,
            'suffix' => $suffix,
            'has_init' => $hasInit,
            'init_path' => $initPath,
            'namespace' => $namespace,
            'deep' => $deep,
        ];
    }

    private function extract_namespace(string $php): string
    {
        if (preg_match('/\\bnamespace\\s+([^;\\s]+)\\s*;/', $php, $m)) {
            return trim((string) $m[1]);
        }
        return '';
    }

    private function find_case_insensitive(string $dir, string $filename): ?string
    {
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return null;
        }
        foreach ($entries as $e) {
            if (!is_string($e)) {
                continue;
            }
            if (strcasecmp($e, $filename) === 0) {
                $p = $dir . '/' . $e;
                return is_file($p) ? $p : null;
            }
        }
        return null;
    }
}
