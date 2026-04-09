<?php

declare(strict_types=1);

namespace PBS\Services;

use PBS\Repository\FieldRepository;
use PBS\Repository\SchemaRepository;

final class ServiceDelta
{
    /**
     * Analyze delta between PBS schema fields and a service mapping.
     *
     * @return array{ok:bool,errors:array<int,string>,warnings:array<int,string>,report:array<string,mixed>}
     */
    public function analyze(int $schemaId, string $pluginSlug, string $glibDir, string $serviceKey): array
    {
        $errors = [];
        $warnings = [];

        $pluginSlug = trim($pluginSlug);
        $glibDir = trim($glibDir);
        $serviceKey = trim($serviceKey);

        if ($schemaId <= 0) {
            $errors[] = 'schema_id mancante.';
        }
        if ($pluginSlug === '' || str_contains($pluginSlug, '..') || str_contains($pluginSlug, '/')) {
            $errors[] = 'plugin_slug non valido.';
        }
        if ($glibDir === '' || str_contains($glibDir, '..') || str_contains($glibDir, '/')) {
            $errors[] = 'glib_dir non valido.';
        }
        if ($serviceKey === '') {
            $errors[] = 'service mancante.';
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors, 'warnings' => [], 'report' => []];
        }

        $schema = (new SchemaRepository())->get($schemaId);
        if (!$schema) {
            return ['ok' => false, 'errors' => ['Schema non trovato.'], 'warnings' => [], 'report' => []];
        }
        $fields = (new FieldRepository())->list_by_schema($schemaId);
        $schemaCols = $this->schema_db_columns($fields);
        if (!$schemaCols) {
            return ['ok' => false, 'errors' => ['Nessun campo nello schema.'], 'warnings' => [], 'report' => []];
        }

        $svc = $this->resolve_service($pluginSlug, $glibDir, $serviceKey);
        if (!$svc['ok']) {
            return ['ok' => false, 'errors' => $svc['errors'], 'warnings' => [], 'report' => []];
        }

        $basePath = (string) $svc['base_file'];
        $php = (string) @file_get_contents($basePath);
        if ($php === '') {
            return ['ok' => false, 'errors' => ["Impossibile leggere: {$basePath}"], 'warnings' => [], 'report' => []];
        }

        $tableKey = $this->extract_table_key($php);
        $expectedKey = $this->expected_table_key($pluginSlug, (string) ($schema['slug'] ?? ''));
        if ($tableKey !== '' && $expectedKey !== '' && $tableKey !== $expectedKey) {
            $warnings[] = "TABLE_KEY diverso da atteso (atteso={$expectedKey}, trovato={$tableKey}).";
        }

        $serviceCols = $this->extract_mapping_keys($php);
        if (!$serviceCols) {
            return ['ok' => false, 'errors' => ['match_db_inp_type non trovato o vuoto nel Base*.php.'], 'warnings' => $warnings, 'report' => ['base_file' => $basePath] ];
        }

        $svcMeta = $this->extract_mapping_meta($php);
        $serviceMeta = (array) ($svcMeta['meta'] ?? []);

        $common = array_values(array_intersect($schemaCols, $serviceCols));
        $added = array_values(array_diff($schemaCols, $serviceCols));
        $removed = array_values(array_diff($serviceCols, $schemaCols));

        // Detect "changed" fields (same db_column, different type or group kind).
        $schemaByDb = $this->schema_meta_by_db($fields);
        $changed = [];
        foreach ($common as $db) {
            $s = $schemaByDb[$db] ?? null;
            $m = $serviceMeta[$db] ?? null;
            if (!is_array($s) || !is_array($m)) {
                continue;
            }
            $st = (string) ($s['type'] ?? '');
            $sk = (string) ($s['group_kind'] ?? '');
            $mt = (string) ($m['type'] ?? '');
            $mk = (string) ($m['group_kind'] ?? '');

            $typeDiff = ($st !== '' && $mt !== '' && $st !== $mt);
            $kindDiff = ($sk !== '' && $sk !== $mk);
            if ($typeDiff || $kindDiff) {
                $changed[] = [
                    'db' => $db,
                    'schema_type' => $st,
                    'service_type' => $mt,
                    'schema_group_kind' => $sk,
                    'service_group_kind' => $mk,
                ];
            }
        }

        if (!empty($changed)) {
            $warnings[] = sprintf(
                'Trovati %d campi con tipo/gruppo diverso (schema vs servizio). Applica update per riallineare il mapping (nessuna modifica automatica al DB).',
                count($changed)
            );
        }

        $den = max(count($schemaCols), count($serviceCols), 1);
        $deltaPct = (count($added) + count($removed)) / $den;
        if ($deltaPct > 0.30) {
            $warnings[] = sprintf('Delta alto: %.0f%% (soglia 30%%). Verifica che il servizio sia quello corretto.', $deltaPct * 100);
        }

        $alterSql = $this->build_alter_sql($svc['table_name'] ?? '', $fields, $added);

        return [
            'ok' => true,
            'errors' => [],
            'warnings' => $warnings,
            'report' => [
                'schema' => $schema,
                'schema_columns' => $schemaCols,
                'service_columns' => $serviceCols,
                'service_meta' => $serviceMeta,
                'common' => $common,
                'added' => $added,
                'removed' => $removed,
                'changed' => $changed,
                'delta_pct' => $deltaPct,
                'plugin_slug' => $pluginSlug,
                'glib_dir' => $glibDir,
                'service_key' => $serviceKey,
                'base_file' => $basePath,
                'table_key' => $tableKey,
                'expected_table_key' => $expectedKey,
                'table_name' => (string) ($svc['table_name'] ?? ''),
                'alter_sql' => $alterSql,
            ],
        ];
    }

    /**
     * Apply a delta update: only add/remove mapping entries in Base*.php.
     *
     * @return array{ok:bool,errors:array<int,string>,warnings:array<int,string>,report:array<string,mixed>}
     */
    public function apply(int $schemaId, string $pluginSlug, string $glibDir, string $serviceKey): array
    {
        $analysis = $this->analyze($schemaId, $pluginSlug, $glibDir, $serviceKey);
        if (empty($analysis['ok'])) {
            return $analysis;
        }

        $report = (array) ($analysis['report'] ?? []);
        $basePath = (string) ($report['base_file'] ?? '');
        if ($basePath === '' || !is_file($basePath)) {
            return ['ok' => false, 'errors' => ['Base file non trovato.'], 'warnings' => (array) ($analysis['warnings'] ?? []), 'report' => $report];
        }

        $schemaFields = (new FieldRepository())->list_by_schema($schemaId);
        $schemaCols = $this->schema_db_columns($schemaFields);
        $serviceCols = (array) ($report['service_columns'] ?? []);

        $added = array_values(array_diff($schemaCols, $serviceCols));
        $removed = array_values(array_diff($serviceCols, $schemaCols));

        $php = (string) @file_get_contents($basePath);
        if ($php === '') {
            return ['ok' => false, 'errors' => ["Impossibile leggere: {$basePath}"], 'warnings' => (array) ($analysis['warnings'] ?? []), 'report' => $report];
        }

        $repl = $this->replace_mapping_array($php, $schemaFields, $schemaCols, $added, $removed);
        if (!$repl['ok']) {
            return ['ok' => false, 'errors' => $repl['errors'], 'warnings' => (array) ($analysis['warnings'] ?? []), 'report' => $report];
        }

        $newPhp = (string) $repl['php'];
        $ok = @file_put_contents($basePath, $newPhp);
        if ($ok === false) {
            return ['ok' => false, 'errors' => ["Impossibile scrivere: {$basePath}"], 'warnings' => (array) ($analysis['warnings'] ?? []), 'report' => $report];
        }

        $report['applied'] = [
            'base_file' => $basePath,
            'added' => $added,
            'removed' => $removed,
        ];

        return [
            'ok' => true,
            'errors' => [],
            'warnings' => (array) ($analysis['warnings'] ?? []),
            'report' => $report,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     * @return string[]
     */
    private function schema_db_columns(array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            $db = (string) ($f['db_column'] ?? '');
            if ($db !== '') {
                $out[] = $db;
            }
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     * @return array<string,array{type:string,group_kind:string}>
     */
    private function schema_meta_by_db(array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            $db = (string) ($f['db_column'] ?? '');
            if ($db === '') {
                continue;
            }
            $type = (string) ($f['field_type'] ?? '');
            $flags = json_decode((string) ($f['flags'] ?? ''), true) ?: [];
            $groupKind = '';
            if (is_array($flags) && !empty($flags['group']) && is_array($flags['group'])) {
                $groupKind = (string) ($flags['group']['kind'] ?? '');
            }
            $out[$db] = [
                'type' => $type,
                'group_kind' => $groupKind,
            ];
        }
        return $out;
    }

    private function expected_table_key(string $pluginSlug, string $schemaSlug): string
    {
        $k = sanitize_key($pluginSlug . '_' . $schemaSlug);
        $k = str_replace('-', '_', $k);
        return $k;
    }

    private function extract_table_key(string $php): string
    {
        if (preg_match('/\\bTABLE_KEY\\s*=\\s*([\\\"\\\'])([^\\\"\\\']+)\\1\\s*;/', $php, $m) === 1) {
            return (string) ($m[2] ?? '');
        }
        return '';
    }

    /**
     * @return string[]
     */
    private function extract_mapping_keys(string $php): array
    {
        $keys = [];
        if (preg_match_all('/\\n\\s*[\\\"\\\']([a-zA-Z0-9_]+)[\\\"\\\']\\s*=>\\s*\\[/', $php, $m) > 0) {
            foreach ((array) ($m[1] ?? []) as $k) {
                $k = (string) $k;
                if ($k !== '') {
                    $keys[] = $k;
                }
            }
        }
        $keys = array_values(array_unique($keys));
        sort($keys);
        return $keys;
    }

    /**
     * Extract minimal metadata per mapping entry (db => type, group kind).
     *
     * @return array{cols:string[],meta:array<string,array{type:string,group_kind:string}>}
     */
    private function extract_mapping_meta(string $php): array
    {
        $meta = [];
        $cols = [];

        if (preg_match_all('/\\n\\s*[\\\"\\\']([a-zA-Z0-9_]+)[\\\"\\\']\\s*=>\\s*\\[(.*?)\\],\\s*/s', $php, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $row) {
                $db = (string) ($row[1] ?? '');
                $block = (string) ($row[2] ?? '');
                if ($db === '' || $block === '') {
                    continue;
                }
                $type = '';
                if (preg_match('/\\b[\\\"\\\']type[\\\"\\\']\\s*=>\\s*[\\\"\\\']([^\\\"\\\']+)[\\\"\\\']/', $block, $mm) === 1) {
                    $type = (string) ($mm[1] ?? '');
                }
                $kind = '';
                if (preg_match('/\\b[\\\"\\\']kind[\\\"\\\']\\s*=>\\s*[\\\"\\\']([^\\\"\\\']+)[\\\"\\\']/', $block, $mm) === 1) {
                    $kind = (string) ($mm[1] ?? '');
                }
                $meta[$db] = [
                    'type' => $type,
                    'group_kind' => $kind,
                ];
                $cols[] = $db;
            }
        }

        $cols = array_values(array_unique($cols));
        sort($cols);
        return ['cols' => $cols, 'meta' => $meta];
    }

    /**
     * @param array<int,array<string,mixed>> $schemaFields
     * @param string[] $added
     * @return string[]
     */
    private function build_alter_sql(string $tableName, array $schemaFields, array $added): array
    {
        $out = [];
        if ($tableName === '' || !$added) {
            return $out;
        }

        $byDb = [];
        foreach ($schemaFields as $f) {
            $db = (string) ($f['db_column'] ?? '');
            if ($db !== '') {
                $byDb[$db] = $f;
            }
        }

        foreach ($added as $db) {
            $f = $byDb[$db] ?? null;
            if (!is_array($f)) {
                continue;
            }
            $type = (string) ($f['field_type'] ?? 'text');
            $flags = json_decode((string) ($f['flags'] ?? ''), true) ?: [];
            $sqlType = match ($type) {
                'url' => 'VARCHAR(2048)',
                'email' => 'VARCHAR(255)',
                'html', 'text_area' => 'LONGTEXT',
                'int' => 'BIGINT',
                'float' => 'DOUBLE',
                'bool' => 'TINYINT(1)',
                default => 'VARCHAR(255)',
            };
            if (is_array($flags) && !empty($flags['group'])) {
                $sqlType = 'LONGTEXT';
            }
            $out[] = "ALTER TABLE {$tableName} ADD COLUMN `{$db}` {$sqlType} NULL;";
        }

        return $out;
    }

    /**
     * @return array{ok:bool,errors:array<int,string>,base_file?:string,table_name?:string}
     */
    private function resolve_service(string $pluginSlug, string $glibDir, string $serviceKey): array
    {
        $pluginDir = rtrim((string) WP_PLUGIN_DIR, '/') . '/' . $pluginSlug;
        if (!is_dir($pluginDir)) {
            return ['ok' => false, 'errors' => ["Plugin non trovato: {$pluginSlug}"]];
        }
        $glibFull = $pluginDir . '/' . $glibDir;
        if (!is_dir($glibFull)) {
            return ['ok' => false, 'errors' => ["gLib dir non trovato: {$glibDir}"]];
        }

        $svcScanner = new GlibServiceScanner();
        $services = $svcScanner->list_services($glibFull);
        foreach ($services as $svc) {
            if (!is_array($svc)) {
                continue;
            }
            if ((string) ($svc['service_key'] ?? '') !== $serviceKey) {
                continue;
            }
            $baseFile = (string) ($svc['base_file'] ?? '');
            if ($baseFile === '' || !is_file($baseFile)) {
                continue;
            }

            $tableName = '';
            $php = (string) @file_get_contents($baseFile);
            if ($php !== '' && preg_match('/\\$wpdb->prefix\\s*\\.\\s*self::TABLE_KEY/', $php) === 1) {
                $tableKey = $this->extract_table_key($php);
                if ($tableKey !== '') {
                    $tableName = (string) ($GLOBALS['wpdb']->prefix ?? 'wp_') . $tableKey;
                }
            }

            return [
                'ok' => true,
                'errors' => [],
                'base_file' => $baseFile,
                'table_name' => $tableName,
            ];
        }

        return ['ok' => false, 'errors' => ['Servizio non trovato (service_key).']];
    }

    /**
     * Replace mapping array in Base*.php by applying add/remove and leaving existing entries untouched.
     *
     * @param array<int,array<string,mixed>> $schemaFields
     * @param string[] $schemaCols
     * @param string[] $added
     * @param string[] $removed
     * @return array{ok:bool,errors:array<int,string>,php?:string}
     */
    private function replace_mapping_array(string $php, array $schemaFields, array $schemaCols, array $added, array $removed): array
    {
        $start = strpos($php, 'match_db_inp_type');
        if ($start === false) {
            return ['ok' => false, 'errors' => ['match_db_inp_type non trovato.']];
        }
        $arrStart = strpos($php, '[', $start);
        if ($arrStart === false) {
            return ['ok' => false, 'errors' => ['Array match_db_inp_type non trovato.']];
        }

        $arrEnd = $this->find_matching_bracket($php, $arrStart);
        if ($arrEnd < 0) {
            return ['ok' => false, 'errors' => ['Impossibile determinare fine array match_db_inp_type.']];
        }

        $existing = $this->extract_existing_entries($php);
        $existingMeta = $this->extract_mapping_meta($php);
        $existingMetaByDb = (array) ($existingMeta['meta'] ?? []);

        $byDb = [];
        foreach ($schemaFields as $f) {
            $db = (string) ($f['db_column'] ?? '');
            if ($db !== '') {
                $byDb[$db] = $f;
            }
        }

        $newLines = [];
        foreach ($schemaCols as $db) {
            $f = $byDb[$db] ?? null;
            if (isset($existing[$db]) && is_array($f)) {
                $flags = json_decode((string) ($f['flags'] ?? ''), true) ?: [];
                $schemaType = (string) ($f['field_type'] ?? 'text');
                $schemaKind = '';
                if (!empty($flags['group']) && is_array($flags['group'])) {
                    $schemaKind = (string) ($flags['group']['kind'] ?? '');
                }

                $m = $existingMetaByDb[$db] ?? [];
                $serviceType = (string) ($m['type'] ?? '');
                $serviceKind = (string) ($m['group_kind'] ?? '');

                $typeDiff = ($schemaType !== '' && $serviceType !== '' && $schemaType !== $serviceType);
                $kindDiff = ($schemaKind !== '' && $schemaKind !== $serviceKind);
                if (!$typeDiff && !$kindDiff) {
                    $newLines[] = $existing[$db];
                    continue;
                }
                // fallthrough: rigenero entry da schema (update mapping)
            } elseif (isset($existing[$db]) && !is_array($f)) {
                $newLines[] = $existing[$db];
                continue;
            }

            if (!is_array($f)) {
                continue;
            }
            $name = (string) ($f['input_name'] ?? $db);
            $type = (string) ($f['field_type'] ?? 'text');
            $label = (string) ($f['label'] ?? $db);
            $flags = json_decode((string) ($f['flags'] ?? ''), true) ?: [];
            $flagsCode = var_export($flags, true);
            $newLines[] = "            '{$db}' => ['name' => '{$name}', 'type' => '{$type}', 'label' => " . var_export($label, true) . ", 'flags' => {$flagsCode}],";
        }

        if (!$newLines) {
            return ['ok' => false, 'errors' => ['Nuovo mapping vuoto (verifica schema/fields).']];
        }

        $newBlock = "[\n" . implode("\n", $newLines) . "\n    ]";
        $newPhp = substr($php, 0, $arrStart) . $newBlock . substr($php, $arrEnd + 1);

        return ['ok' => true, 'errors' => [], 'php' => $newPhp];
    }

    /**
     * Extract existing mapping entries from a Base*.php generated by PBS.
     *
     * @return array<string,string> db => line
     */
    private function extract_existing_entries(string $php): array
    {
        $out = [];
        if (preg_match_all('/\\n(\\s*)[\\\"\\\']([a-zA-Z0-9_]+)[\\\"\\\']\\s*=>\\s*\\[(.*?)\\],\\s*/s', $php, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $row) {
                $indent = (string) ($row[1] ?? '            ');
                $db = (string) ($row[2] ?? '');
                $meta = (string) ($row[3] ?? '');
                if ($db === '' || $meta === '') {
                    continue;
                }
                $out[$db] = "\n{$indent}'{$db}' => [{$meta}],";
            }
        }
        // Normalize: keep only lines within match_db_inp_type by filtering keys that exist there.
        // (Best-effort: for PBS generated Base, patterns should match only the mapping array.)
        foreach ($out as $db => $line) {
            $line = trim($line, "\n");
            $out[$db] = $line;
        }
        return $out;
    }

    private function find_matching_bracket(string $s, int $openPos): int
    {
        $len = strlen($s);
        $depth = 0;
        $inStr = false;
        $strCh = '';
        $esc = false;

        for ($i = $openPos; $i < $len; $i++) {
            $ch = $s[$i];

            if ($inStr) {
                if ($esc) {
                    $esc = false;
                    continue;
                }
                if ($ch === '\\\\') {
                    $esc = true;
                    continue;
                }
                if ($ch === $strCh) {
                    $inStr = false;
                    $strCh = '';
                }
                continue;
            }

            if ($ch === '\'' || $ch === '"') {
                $inStr = true;
                $strCh = $ch;
                continue;
            }

            if ($ch === '[') {
                $depth++;
                continue;
            }
            if ($ch === ']') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return -1;
    }
}
