<?php

declare(strict_types=1);

namespace PBS\Services;

final class GlibServiceScanner
{
    /**
     * List services in a gLib directory.
     *
     * @return array<int,array{service_key:string,group:string,service_dir:string,full_dir:string,base_file:string,admin_file:string,callbacks_file:string}>
     */
    public function list_services(string $glibFullDir): array
    {
        $glibFullDir = rtrim($glibFullDir, '/');
        $base = $glibFullDir . '/Api/Services';
        if (!is_dir($base)) {
            return [];
        }

        $groups = [
            'Shared',
            'Internal',
            'Control',
        ];

        $out = [];
        foreach ($groups as $g) {
            $gd = $base . '/' . $g;
            if (!is_dir($gd)) {
                continue;
            }
            $entries = @scandir($gd);
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $e) {
                if (!is_string($e) || $e === '.' || $e === '..') {
                    continue;
                }
                $svcDir = $gd . '/' . $e;
                if (!is_dir($svcDir)) {
                    continue;
                }

                $baseFile = $this->first_matching_file($svcDir, '/^Base.+\\.php$/');
                $adminFile = $this->first_matching_file($svcDir, '/^Admin.+\\.php$/');
                $cbFile = $this->first_matching_file($svcDir, '/Callbacks\\.php$/');

                if ($baseFile === '') {
                    continue;
                }

                $serviceKey = sanitize_key($e);
                if ($serviceKey === '') {
                    $serviceKey = sanitize_key($g . '_' . $e);
                }

                $out[] = [
                    'service_key' => $serviceKey,
                    'group' => $g,
                    'service_dir' => $e,
                    'full_dir' => $svcDir,
                    'base_file' => $baseFile,
                    'admin_file' => $adminFile,
                    'callbacks_file' => $cbFile,
                ];
            }
        }

        usort($out, static function (array $a, array $b): int {
            $ga = (string) ($a['group'] ?? '');
            $gb = (string) ($b['group'] ?? '');
            if ($ga !== $gb) {
                return strcmp($ga, $gb);
            }
            return strcmp((string) ($a['service_dir'] ?? ''), (string) ($b['service_dir'] ?? ''));
        });

        return $out;
    }

    private function first_matching_file(string $dir, string $pattern): string
    {
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return '';
        }
        foreach ($entries as $e) {
            if (!is_string($e)) {
                continue;
            }
            if (preg_match($pattern, $e) !== 1) {
                continue;
            }
            $p = $dir . '/' . $e;
            if (is_file($p)) {
                return $p;
            }
        }
        return '';
    }
}

