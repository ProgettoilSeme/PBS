<?php

declare(strict_types=1);

namespace PBS\Services;

use PBS\Repository\FieldRepository;
use PBS\Repository\SchemaRepository;

/**
 * ServiceDelta
 *
 * Calcola e applica un delta tra lo schema PBS e il mapping di un servizio esistente (Base*.php).
 *
 * Policy:
 * - nessuna modifica automatica al DB
 * - l'azione "Applica" modifica solo `match_db_inp_type` nel Base*.php (mapping)
 */
final class ServiceDelta
{
    /**
     * Render a generator snippet from `templates/generator/snippets/`.
     *
     * Placeholder format: `{{VAR}}`.
     *
     * @param array<string,string> $vars
     */
    private function render_snippet(string $relPath, array $vars = []): string
    {
        $base = rtrim((string) (defined('PBS_PLUGIN_DIR') ? constant('PBS_PLUGIN_DIR') : ''), "/\\");
        $path = $base !== '' ? ($base . '/templates/generator/snippets/' . ltrim($relPath, '/')) : '';
        if ($path === '' || !is_file($path)) {
            return '';
        }

        $tpl = (string) @file_get_contents($path);
        if ($tpl === '') {
            return '';
        }

        if (!$vars) {
            return $tpl;
        }

        $repl = [];
        foreach ($vars as $k => $v) {
            $repl['{{' . $k . '}}'] = (string) $v;
        }
        return strtr($tpl, $repl);
    }

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

        // DB delta: compare effective DLL (centralized preview) vs actual DB table.
        $db = $this->build_db_delta((string) ($svc['table_name'] ?? ''), $schemaId, $fields);

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
                // DB delta info (centralized DLL)
                'dll_has_preview' => !empty($db['dll_has_preview']),
                'db_table_exists' => !empty($db['table_exists']),
                'db_expected_cols' => (array) ($db['expected_cols'] ?? []),
                'db_existing_cols' => (array) ($db['existing_cols'] ?? []),
                'db_missing_cols' => (array) ($db['missing_cols'] ?? []),
                'db_extra_cols' => (array) ($db['extra_cols'] ?? []),
                'alter_sql' => (array) ($db['alter_sql'] ?? []),
                'create_sql' => (string) ($db['create_sql'] ?? ''),
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
        // Normalize schema fields so delta-update doesn't "downgrade" known complex components.
        $schemaFields = $this->normalize_schema_fields_for_update($schemaFields);
        // IMPORTANT: preserve schema order (ord ASC) when rewriting mapping, to keep BE UI stable.
        $schemaCols = $this->schema_db_columns_in_order($schemaFields);
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
        $newPhp = $this->ensure_mirror_support_in_base($newPhp);
        $newPhp = $this->ensure_table_columns_guard_in_base($newPhp);
        $ok = @file_put_contents($basePath, $newPhp);
        if ($ok === false) {
            return ['ok' => false, 'errors' => ["Impossibile scrivere: {$basePath}"], 'warnings' => (array) ($analysis['warnings'] ?? []), 'report' => $report];
        }

        // Also patch Admin/Callbacks templates to stay in sync with generator templates (centralized behavior).
        $svc = $this->resolve_service($pluginSlug, $glibDir, $serviceKey);
        if (!empty($svc['ok'])) {
            $this->patch_admin_templates((string) ($svc['plugin_dir'] ?? ''), (string) ($svc['glib_full'] ?? ''), (string) ($svc['admin_file'] ?? ''));
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
     * Keep db_column order as defined by PBS (ord ASC from repository).
     *
     * @param array<int,array<string,mixed>> $fields
     * @return string[]
     */
    private function schema_db_columns_in_order(array $fields): array
    {
        $out = [];
        $seen = [];
        foreach ($fields as $f) {
            $db = (string) ($f['db_column'] ?? '');
            if ($db === '' || isset($seen[$db])) {
                continue;
            }
            $seen[$db] = true;
            $out[] = $db;
        }
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
     * DB delta builder based on centralized DLL preview.
     *
     * @param array<int,array<string,mixed>> $schemaFields
     * @return array<string,mixed>
     */
    private function build_db_delta(string $tableName, int $schemaId, array $schemaFields): array
    {
        $out = [
            'dll_has_preview' => false,
            'table_exists' => false,
            'expected_cols' => [],
            'existing_cols' => [],
            'missing_cols' => [],
            'extra_cols' => [],
            'alter_sql' => [],
            'create_sql' => '',
        ];

        if ($tableName === '' || $schemaId <= 0) {
            return $out;
        }

        $dll = new SchemaDll();
        $effective = $dll->get_effective($schemaId, $schemaFields);
        $out['dll_has_preview'] = !empty($effective['has_preview']);

        $expectedDefs = [];
        foreach ((array) ($effective['columns'] ?? []) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $db = sanitize_key((string) ($c['db_column'] ?? ''));
            if ($db === '') {
                continue;
            }
            $expectedDefs[$db] = [
                'sql_type' => (string) ($c['sql_type'] ?? 'LONGTEXT'),
                'nullable' => !empty($c['nullable']),
            ];
        }
        $out['expected_cols'] = array_keys($expectedDefs);

        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tableName));
        $exists = ($found === $tableName);
        $out['table_exists'] = $exists;

        if (!$exists) {
            // Provide create table SQL (best-effort, no PRIMARY KEY details for now beyond id).
            $charset = $wpdb->get_charset_collate();
            $lines = [];
            $lines[] = "CREATE TABLE {$tableName} (";
            $lines[] = "  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,";

            foreach ($expectedDefs as $db => $def) {
                if ($db === 'id') {
                    continue;
                }
                $type = (string) ($def['sql_type'] ?? 'LONGTEXT');
                $null = !empty($def['nullable']) ? 'NULL' : 'NOT NULL';
                $lines[] = "  `{$db}` {$type} {$null},";
            }
            $lines[] = "  PRIMARY KEY (`id`)";
            $lines[] = ") {$charset};";
            $out['create_sql'] = implode("\n", $lines);
            return $out;
        }

        // Existing columns
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $cols = (array) $wpdb->get_results("SHOW COLUMNS FROM {$tableName}", ARRAY_A);
        $existing = [];
        foreach ($cols as $c) {
            if (is_array($c) && isset($c['Field'])) {
                $existing[sanitize_key((string) $c['Field'])] = true;
            }
        }
        $out['existing_cols'] = array_keys($existing);

        $missing = [];
        foreach (array_keys($expectedDefs) as $db) {
            if (!isset($existing[$db])) {
                $missing[] = $db;
            }
        }
        $out['missing_cols'] = $missing;

        $extra = [];
        foreach (array_keys($existing) as $db) {
            if (!isset($expectedDefs[$db])) {
                $extra[] = $db;
            }
        }
        $out['extra_cols'] = $extra;

        $sql = [];
        foreach ($missing as $db) {
            if ($db === 'id') {
                continue;
            }
            $def = $expectedDefs[$db] ?? ['sql_type' => 'LONGTEXT', 'nullable' => true];
            $type = (string) ($def['sql_type'] ?? 'LONGTEXT');
            $null = !empty($def['nullable']) ? 'NULL' : 'NOT NULL';
            $sql[] = "ALTER TABLE {$tableName} ADD COLUMN `{$db}` {$type} {$null};";
        }
        $out['alter_sql'] = $sql;

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
                'plugin_dir' => $pluginDir,
                'glib_full' => $glibFull,
                'base_file' => $baseFile,
                'table_name' => $tableName,
                'admin_file' => (string) ($svc['admin_file'] ?? ''),
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
            if (isset($existing[$db]) && !is_array($f)) {
                // Keep untouched when schema has no row for this mapping entry.
                $newLines[] = $existing[$db];
                continue;
            }

            if (!is_array($f)) {
                continue;
            }

            // IMPORTANT: Always regenerate the entry from schema for PBS-managed fields.
            // This keeps flags (especially flags.ui.list/edit/editable) in sync when user updates DLL preview.
            $name = (string) ($f['input_name'] ?? $db);
            $type = (string) ($f['field_type'] ?? 'text');
            $label = (string) ($f['label'] ?? $db);
            $flags = json_decode((string) ($f['flags'] ?? ''), true) ?: [];
            $flagsCode = var_export($flags, true);

            // If type/group kind matches, we still update flags (ui/prefill/mirror/etc).
            // If it differs, this regeneration also upgrades the mapping as expected.
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
     * Normalize schema fields before writing into an existing service mapping.
     *
     * Ensures:
     * - group kind is present for known components (button/text/...)
     * - member keys/types are standardized according to ComponentRegistry
     * - member labels are stable (registry labels) for known kinds
     *
     * @param array<int,array<string,mixed>> $fields
     * @return array<int,array<string,mixed>>
     */
    private function normalize_schema_fields_for_update(array $fields): array
    {
        foreach ($fields as $i => $f) {
            if (!is_array($f)) {
                continue;
            }
            $flags = json_decode((string) ($f['flags'] ?? ''), true);
            if (!is_array($flags) || empty($flags['group']) || !is_array($flags['group'])) {
                continue;
            }

            $kind = sanitize_key((string) ($flags['group']['kind'] ?? ''));
            if ($kind === '' || $kind === 'generic' || !ComponentRegistry::is_known_kind($kind)) {
                $kind = $this->infer_group_kind_from_field_row($f, $flags);
            }
            if ($kind === '' || $kind === 'generic' || !ComponentRegistry::is_known_kind($kind)) {
                continue;
            }

            $members = (array) ($flags['group']['members'] ?? []);
            if (!$members) {
                continue;
            }

            $outMembers = [];
            $components = ComponentRegistry::components();
            foreach ($members as $m) {
                if (!is_array($m)) {
                    continue;
                }

                $rawKey = '';
                if (!empty($m['acf']) && is_array($m['acf']) && !empty($m['acf']['orig_name']) && is_string($m['acf']['orig_name'])) {
                    $rawKey = (string) $m['acf']['orig_name'];
                }
                if ($rawKey === '') {
                    $rawKey = (string) ($m['key'] ?? '');
                }

                $normKey = ComponentRegistry::normalize_member_key($kind, $rawKey);
                if ($normKey === '') {
                    $normKey = sanitize_key($rawKey);
                }
                if ($normKey === '') {
                    continue;
                }

                $acfType = '';
                if (!empty($m['acf']) && is_array($m['acf']) && isset($m['acf']['type'])) {
                    $acfType = (string) $m['acf']['type'];
                }

                $m['key'] = $normKey;
                $m['field_type'] = ComponentRegistry::infer_member_type($kind, $normKey, $acfType);
                if (isset($components[$kind]['members'][$normKey]['label'])) {
                    $m['label'] = (string) $components[$kind]['members'][$normKey]['label'];
                } elseif (!isset($m['label']) || (string) $m['label'] === '') {
                    $m['label'] = $normKey;
                }

                $outMembers[] = $m;
            }

            $flags['group']['kind'] = $kind;
            $flags['group']['members'] = $outMembers;
            $f['flags'] = wp_json_encode($flags);
            $fields[$i] = $f;
        }

        return $fields;
    }

    /**
     * Infer kind from db_column/input_name or member-keys intersection with registry.
     *
     * @param array<string,mixed> $fieldRow
     * @param array<string,mixed> $flags
     */
    private function infer_group_kind_from_field_row(array $fieldRow, array $flags): string
    {
        $db = sanitize_key((string) ($fieldRow['db_column'] ?? ''));
        $in = sanitize_key((string) ($fieldRow['input_name'] ?? ''));
        foreach ([$db, $in] as $fb) {
            if ($fb !== '' && ComponentRegistry::is_known_kind($fb)) {
                return $fb;
            }
        }

        $members = (array) (($flags['group']['members'] ?? []));
        if (!$members) {
            return '';
        }

        $memberKeys = [];
        foreach ($members as $m) {
            if (!is_array($m)) {
                continue;
            }
            $k = '';
            if (!empty($m['acf']) && is_array($m['acf']) && !empty($m['acf']['orig_name']) && is_string($m['acf']['orig_name'])) {
                $k = sanitize_key((string) $m['acf']['orig_name']);
            }
            if ($k === '') {
                $k = sanitize_key((string) ($m['key'] ?? ''));
            }
            if ($k !== '') {
                $memberKeys[$k] = true;
            }
        }
        if (!$memberKeys) {
            return '';
        }

        $bestKind = '';
        $bestScore = 0;
        foreach (ComponentRegistry::components() as $kind => $def) {
            if (!is_array($def) || empty($def['members']) || !is_array($def['members'])) {
                continue;
            }
            $score = 0;
            foreach ($memberKeys as $k => $_) {
                if (isset($def['members'][$k])) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestKind = (string) $kind;
            }
        }

        return $bestScore >= 2 ? sanitize_key($bestKind) : '';
    }

    /**
     * Keep generated service UI in sync when applying a delta.
     *
     * Policy: best-effort patch, only for PBS-generated templates.
     */
    private function patch_admin_templates(string $pluginDir, string $glibFull, string $adminFile): void
    {
        // 0) Ensure gLib has Dashboard + activation pattern (srv_managers + activated()).
        $pluginSlug = basename(rtrim($pluginDir, "/\\"));
        $glibNs = basename(rtrim($glibFull, "/\\"));
        $serviceKey = '';
        if ($adminFile !== '' && is_file($adminFile)) {
            // ex: .../Api/Services/Shared/Archive/AdminArchives.php -> Archive
            $serviceKey = sanitize_key(basename(dirname($adminFile)));
        }
        if ($serviceKey === '') {
            $serviceKey = 'service';
        }
        $activationKey = 'admin_' . $serviceKey;

        // BaseController: ensure srv_managers contains activation key and activated() exists.
        $baseCtrl = rtrim($glibFull, '/') . '/Supports/Components/Admin/BaseController.php';
        if (is_file($baseCtrl)) {
            $php = (string) @file_get_contents($baseCtrl);
            if ($php !== '' && str_contains($php, 'BaseController (light)')) {
                if (!str_contains($php, 'const SETTINGS_ID')) {
                    $php2 = preg_replace('/(public\\s+const\\s+PREFIX\\s*=\\s*[^;]+;\\s*\\n)/', "$1    public const SETTINGS_ID = self::PLUGIN_ID . '_settings';\n", $php, 1);
                    if (is_string($php2) && $php2 !== '' && $php2 !== $php) {
                        $php = $php2;
                    }
                }

                if (preg_match('/public\\s+array\\s+\\$srv_managers\\s*=\\s*\\[(.*?)\\];/s', $php, $m) === 1) {
                    $block = (string) ($m[1] ?? '');
                    if (!str_contains($block, "'" . $activationKey . "'")) {
                        $line = "\n            '" . $activationKey . "' => " . var_export('Activate ' . ucfirst($serviceKey), true) . ",";
                        $newBlock = rtrim($block) . $line . "\n        ";
                        $php = str_replace($m[0], "public array \$srv_managers = [{$newBlock}];", $php);
                    }
                }

                if (!str_contains($php, 'function __construct')) {
                    $php2 = preg_replace('/(public\\s+array\\s+\\$srv_managers\\s*=\\s*\\[[^\\]]*\\];\\s*\\n)/s', "$1\n    public function __construct()\n    {\n        \$this->srv_managers = array_filter(\$this->srv_managers, 'strlen');\n    }\n\n", $php, 1);
                    if (is_string($php2) && $php2 !== '' && $php2 !== $php) {
                        $php = $php2;
                    }
                }

                if (!str_contains($php, 'function activated')) {
                    $insertActivated = <<<'PHP'

    /**
     * Determina se un servizio è attivo (dashboard).
     *
     * Default: disattivo finché l'admin non abilita esplicitamente dalla Dashboard.
     */
    public function activated(string $key): bool
    {
        if (!array_key_exists($key, $this->srv_managers)) {
            return false;
        }
        $opt = get_option(self::PLUGIN_ID);
        if ($opt === false || !is_array($opt)) {
            return false;
        }
        return array_key_exists($key, $opt) ? (bool) $opt[$key] : false;
    }

PHP;
                    $php2 = preg_replace('/\\}\\s*\\z/', $insertActivated . "}\n", $php, 1);
                    if (is_string($php2) && $php2 !== '' && $php2 !== $php) {
                        $php = $php2;
                    }
                }

                @file_put_contents($baseCtrl, $php);
            }
        }

        // AdminDashboard: create if missing (best-effort).
        $dashDir = rtrim($glibFull, '/') . '/Api/Services/Internal';
        $dashFile = $dashDir . '/AdminDashboard.php';
        if (!is_dir($dashDir)) {
            @mkdir($dashDir, 0775, true);
        }
        if (!is_file($dashFile)) {
            $pluginName = $pluginSlug !== '' ? $pluginSlug : 'PBS Plugin';
            $dashPhp = $this->render_snippet('admin-dashboard.php.snip', [
                'GLIB_NS' => $glibNs,
                'PLUGIN_NAME' => $pluginName,
                'MENU_SLUG' => $pluginSlug,
                'PLUGIN_ICON' => 'dashicons-admin-generic',
            ]);
            if ($dashPhp !== '') {
                @file_put_contents($dashFile, $dashPhp);
            }
        } else {
            // Patch existing dashboard to use LSA-like toggle UI (no need to regenerate whole file).
            $dashPhp = (string) @file_get_contents($dashFile);
            if ($dashPhp !== '') {
                // 1) Ensure checkboxField renders toggle UI (replace whole method body, robustly).
                if (str_contains($dashPhp, 'function checkboxField') && str_contains($dashPhp, 'display:flex')) {
                    $method = <<<'PHP'
    /**
     * @param array<string,mixed> $args
     */
    public function checkboxField(array $args): void
    {
        $id = sanitize_key((string) ($args['id'] ?? ''));
        if ($id === '') {
            return;
        }
        $opt = get_option(self::PLUGIN_ID);
        $val = is_array($opt) && array_key_exists($id, $opt) ? (int) (bool) $opt[$id] : 0;
        $cbId = 'pbs_srv_' . $id;

        echo '<div class="ui-toggle">';
        echo '<input type="checkbox" id="' . esc_attr($cbId) . '" name="' . esc_attr((string) self::PLUGIN_ID) . '[' . esc_attr($id) . ']" value="1" ' . checked(1, $val, false) . ' />';
        echo '<label for="' . esc_attr($cbId) . '"><div></div></label>';
        echo '</div>';
    }

PHP;
                    $dashPhp2 = preg_replace(
                        '/\\n\\s*\\/\\*\\*\\s*\\n\\s*\\*\\s*@param\\s+array<[^>]+>\\s*\\$args\\s*\\n\\s*\\*\\/\\s*\\n\\s*public\\s+function\\s+checkboxField\\(array\\s+\\$args\\)\\s*:\\s*void\\s*\{.*?(?=\n\s*public\s+function\s+render_dashboard)/s',
                        "\n" . $method,
                        $dashPhp,
                        1
                    );
                    if (is_string($dashPhp2) && $dashPhp2 !== '' && $dashPhp2 !== $dashPhp) {
                        $dashPhp = $dashPhp2;
                    }
                }

                // 2) Inject toggle CSS once inside render_dashboard (after description).
                if (str_contains($dashPhp, 'function render_dashboard') && !str_contains($dashPhp, 'div.ui-toggle input[type=checkbox]{display:none}')) {
                    $css = "        echo '<style>\n"
                        . "        div.ui-toggle{margin:0;padding:0}\n"
                        . "        div.ui-toggle input[type=checkbox]{display:none}\n"
                        . "        div.ui-toggle input[type=checkbox]:checked+label{border-color:#009eea;background:#009eea;box-shadow:inset 0 0 0 10px #009eea}\n"
                        . "        div.ui-toggle input[type=checkbox]:checked+label>div{margin-left:20px}\n"
                        . "        div.ui-toggle label{transition:all 200ms ease;display:inline-block;position:relative;user-select:none;background:#8c8c8c;box-shadow:inset 0 0 0 0 #009eea;border:2px solid #8c8c8c;border-radius:22px;width:40px;height:20px}\n"
                        . "        div.ui-toggle label div{transition:all 200ms ease;background:#fff;width:20px;height:20px;border-radius:10px}\n"
                        . "        div.ui-toggle label:hover,div.ui-toggle label>div:hover{cursor:pointer}\n"
                        . "        </style>';\n";
                    $dashPhp2 = preg_replace(
                        "/(echo\\s+'<p\\s+class=\\\\\\\"description\\\\\\\"[^;]+;\\s*\\n)/",
                        "$1\n" . $css . "\n",
                        $dashPhp,
                        1
                    );
                    if (is_string($dashPhp2) && $dashPhp2 !== '' && $dashPhp2 !== $dashPhp) {
                        $dashPhp = $dashPhp2;
                    }
                }

                @file_put_contents($dashFile, $dashPhp);
            }
        }

        // Init.php: ensure AdminDashboard is included in services.
        $initFile = rtrim($glibFull, '/') . '/Init.php';
        if (is_file($initFile)) {
            $php = (string) @file_get_contents($initFile);
            if ($php !== '' && !str_contains($php, 'AdminDashboard::class')) {
                if (!str_contains($php, 'use ' . $glibNs . '\\Api\\Services\\Internal\\AdminDashboard;')) {
                    $php2 = preg_replace(
                        '/(use\\s+' . preg_quote($glibNs, '/') . '\\\\Api\\\\Services\\\\CrossDomain\\\\LibraryBackEnd;\\s*\\n)/',
                        "$1use {$glibNs}\\Api\\Services\\Internal\\AdminDashboard;\n",
                        $php,
                        1
                    );
                    if (is_string($php2) && $php2 !== '' && $php2 !== $php) {
                        $php = $php2;
                    }
                }
                $php2 = preg_replace(
                    '/(LibraryBackEnd::class,\\s*\\n)/',
                    "$1            AdminDashboard::class,\n",
                    $php,
                    1
                );
                if (is_string($php2) && $php2 !== '' && $php2 !== $php) {
                    $php = $php2;
                }
                @file_put_contents($initFile, $php);
            }
        }

        // SettingsApi: ensure default sanitize callback returns input (otherwise options won't save).
        $settingsApi = rtrim($glibFull, '/') . '/Api/SettingsApi.php';
        if (is_file($settingsApi)) {
            $php = (string) @file_get_contents($settingsApi);
            if ($php !== '' && str_contains($php, 'register_setting')) {
                $php2 = preg_replace(
                    '/static\\s+function\\s*\\(\\s*\\)\\s*:\\s*void\\s*\\{\\s*\\}/',
                    'static function ($input) { return $input; }',
                    $php
                );
                if (is_string($php2) && $php2 !== '' && $php2 !== $php) {
                    @file_put_contents($settingsApi, $php2);
                }
            }
        }

        // 1) Ensure gLib AdminCallbacks supports PBS prefill baked in mapping.
        $adminCallbacks = rtrim($glibFull, '/') . '/Api/Callbacks/AdminCallbacks.php';
        if (is_file($adminCallbacks)) {
            $php = (string) @file_get_contents($adminCallbacks);
            if ($php !== '') {
                // Keep generated plugin UI minimal: remove heading inside button preview box.
                if (str_contains($php, 'Anteprima bottone')) {
                    $php = str_replace(
                        '<div style="margin-bottom:8px;"><strong>Anteprima bottone</strong></div>',
                        '',
                        $php
                    );
                }

                // a) inputField: apply args['prefill'] as default if record value is empty.
                // Also: readonly/disabled support via args['readonly'] (centralized UI flags).
                if (!str_contains($php, "args['prefill']") && str_contains($php, 'public function inputField')) {
                    $repGroup = <<<'REPL'
$1            // PBS pre-fill (default/suggestion) baked in mapping at generation time.
            $prefill = $args['prefill'] ?? null;
            if ($val === '' && $prefill !== null && !is_array($prefill)) {
                $val = (string) $prefill;
            }

REPL;
                    $php2 = preg_replace(
                        '/(\\$val\\s*=\\s*\\x27\\x27;\\s*\\n\\s*if\\s*\\(isset\\(\\$this->values\\[\\$groupDb\\]\\).*?\\)\\s*\\{\\s*\\n\\s*\\$val\\s*=\\s*\\(string\\)\\s*\\$this->values\\[\\$groupDb\\]\\[\\$memberKey\\];\\s*\\n\\s*\\}\\s*\\n)/s',
                        $repGroup,
                        $php,
                        1
                    );
                    if (is_string($php2) && $php2 !== '' && $php2 !== $php) {
                        $php = $php2;
                    }

                    $repSimple = <<<'REPL'
$1        $prefill = $args['prefill'] ?? null;
        if ($val === '' && $prefill !== null && !is_array($prefill)) {
            $val = (string) $prefill;
        }

REPL;
                    $php3 = preg_replace(
                        '/(\\$val\\s*=\\s*isset\\(\\$this->values\\[\\$db\\]\\)\\s*\\?\\s*\\(string\\)\\s*\\$this->values\\[\\$db\\]\\s*:\\s*\\x27\\x27;\\s*\\n)/',
                        $repSimple,
                        $php,
                        1
                    );
                    if (is_string($php3) && $php3 !== '' && $php3 !== $php) {
                        $php = $php3;
                    }
                }

                // readonly support (best-effort): add $readonly and apply to inputs.
                if (str_contains($php, 'public function inputField') && !str_contains($php, '$readonly')) {
                    $php2 = preg_replace(
                        '/(\\$groupKind\\s*=\\s*\\(string\\)\\s*\\(\\$args\\[\\x27group_kind\\x27\\]\\s*\\?\\?\\s*\\x27\\x27\\)\\s*;\\s*\\n)/',
                        "$1        \$readonly = !empty(\$args['readonly']);\n",
                        $php,
                        1
                    );
                    if (is_string($php2) && $php2 !== '' && $php2 !== $php) {
                        $php = $php2;
                    }

                    // checkboxes: add disabled
                    $php2 = preg_replace('/(<input[^>]+type=\\\"checkbox\\\"[^>]*)(\\/>)/', '$1' . " . (\$readonly ? ' disabled' : '') . " . '$2', $php);
                    if (is_string($php2) && $php2 !== '' && $php2 !== $php) {
                        $php = $php2;
                    }
                    // text inputs: add readonly disabled
                    $php2 = preg_replace('/(<input[^>]+class=\\\"regular-text\\\"[^>]*)(\\/>)/', '$1' . " . (\$readonly ? ' readonly disabled' : '') . " . '$2', $php);
                    if (is_string($php2) && $php2 !== '' && $php2 !== $php) {
                        $php = $php2;
                    }
                    // textareas: add readonly
                    $php2 = preg_replace('/(<textarea[^>]+name=\\\"\\\"\\s*\\.\\s*esc_attr\\(\\$name\\)\\s*\\.\\s*\\\"\\\"[^>]*)(>)/', '$1' . " . (\$readonly ? ' readonly' : '') . " . '$2', $php);
                    if (is_string($php2) && $php2 !== '' && $php2 !== $php) {
                        $php = $php2;
                    }
                }

                // b) componentPreviewField: merge defaults from args['prefill'] when present.
                if (!str_contains($php, 'array_merge($prefill') && str_contains($php, 'public function componentPreviewField')) {
                    $repPreview = <<<'REPL'
$1
        $prefill = is_array($args['prefill'] ?? null) ? (array) $args['prefill'] : [];
        if ($prefill) {
            // Record values override defaults.
            $vals = array_merge($prefill, $vals);
        }

REPL;
                    $php2 = preg_replace(
                        '/(\\$vals\\s*=\\s*\\[\\];\\s*\\n\\s*if\\s*\\(isset\\(\\$this->values\\[\\$groupDb\\]\\).*?\\)\\s*\\{\\s*\\n\\s*\\$vals\\s*=\\s*\\(array\\)\\s*\\$this->values\\[\\$groupDb\\];\\s*\\n\\s*\\}\\s*\\n)/s',
                        $repPreview,
                        $php,
                        1
                    );
                    if (is_string($php2) && $php2 !== '' && $php2 !== $php) {
                        $php = $php2;
                    }
                }

                // c) componentEditorField: ensure it forwards prefill to preview.
                $insert = $this->render_snippet('admincallbacks-component-editor.php.snip');

                // If exists, replace; otherwise insert before class end.
	                if (str_contains($php, 'function componentEditorField')) {
                        $patternComponentEditor = '/\n\s{4}public function componentEditorField\(array __PBS_ARGS__\): void\s*\{.*?\n\s{4}\}\n/s';
                        // Avoid Intelephense false positives on "$args" inside regex strings.
                        $patternComponentEditor = str_replace('__PBS_ARGS__', '\\$args', $patternComponentEditor);
	                    $phpNew = preg_replace(
	                        $patternComponentEditor,
	                        $insert . "\n",
	                        $php
	                    );
                    if (is_string($phpNew) && $phpNew !== '' && $phpNew !== $php) {
                        $php = $phpNew;
                    }
                } else {
                    $pos = strrpos($php, "\n}");
                    if ($pos !== false) {
                        $php = substr($php, 0, $pos) . $insert . substr($php, $pos);
                    }
                }

                @file_put_contents($adminCallbacks, $php);
            }
        }

        // 2) Patch service Admin*.php Settings API loop to respect ui flags (edit/editable) + prefill.
        if ($adminFile !== '' && is_file($adminFile)) {
            $php = (string) @file_get_contents($adminFile);
            if ($php === '') {
                return;
            }

            // Ensure activation gate exists at start of register().
            if (!str_contains($php, 'activated(') && str_contains($php, 'private function register(): void')) {
                $php2 = preg_replace(
                    '/(private\\s+function\\s+register\\(\\)\\s*:\\s*void\\s*\\{\\s*\\n)/',
                    "$1        // Dashboard activation (gLib standard). Default: disattivo finché abilitato.\n        if (!\$this->activated(" . var_export($activationKey, true) . ")) {\n            return;\n        }\n\n",
                    $php,
                    1
                );
                if (is_string($php2) && $php2 !== '' && $php2 !== $php) {
                    $php = $php2;
                    @file_put_contents($adminFile, $php);
                }
                $php = (string) @file_get_contents($adminFile);
            }

            // Ensure service doesn't create its own main menu (Dashboard owns it).
            if (str_contains($php, '->addPages([') && str_contains($php, '->addSubPages([')) {
                $php2 = preg_replace('/->addPages\\(\\[.*?\\]\\]\\)\\s*->whithSubPage\\(.*?\\)\\s*/s', '', $php, 1);
                if (is_string($php2) && $php2 !== '' && $php2 !== $php) {
                    $php = $php2;
                    @file_put_contents($adminFile, $php);
                }
                $php = (string) @file_get_contents($adminFile);
            }

            // Infer service page slug literal for inserts.
            $svcPageSlug = '';
            if (preg_match("/'page'\\s*=>\\s*'([^']+)'\\s*,/", $php, $m)) {
                $svcPageSlug = (string) ($m[1] ?? '');
            }
            $svcPageSlugCode = $svcPageSlug !== '' ? var_export($svcPageSlug, true) : "''";

            if (!str_contains($php, '$showBe') && str_contains($php, 'foreach ($this->match_db_inp_type as $db => $meta)')) {
                $php = preg_replace(
                    '/foreach\s*\(\s*\\$this->match_db_inp_type\s+as\s+\\$db\s*=>\s*\\$meta\s*\)\s*\\{\s*/',
                    "foreach (\$this->match_db_inp_type as \$db => \$meta) {\n            \$flags = (array) (\$meta['flags'] ?? []);\n            \$flow = (array) (\$flags['flow'] ?? []);\n            \$showBe = array_key_exists('be', \$flow) ? (bool) \$flow['be'] : true;\n            \$ui = (array) (\$flags['ui'] ?? []);\n            \$uiEdit = array_key_exists('edit', \$ui) ? (bool) \$ui['edit'] : true;\n            \$uiEditable = array_key_exists('editable', \$ui) ? (bool) \$ui['editable'] : true;\n            if (!empty(\$flags['hidden']) || !\$showBe || !empty(\$flags['mirror']) || !\$uiEdit) {\n                continue;\n            }\n\n            ",
                    $php,
                    1
                );
                if (is_string($php) && $php !== '') {
                    @file_put_contents($adminFile, $php);
                }
                // reload for subsequent patches
                $php = (string) @file_get_contents($adminFile);
            }

            // Text-like components: render a single main editor (hide internal members like boxtext).
            $needleTextKinds = "in_array(__PBS_GROUPKIND__, ['text', 'title_text']";
            // Avoid Intelephense false positives on "$groupKind" inside strings.
            $needleTextKinds = str_replace('__PBS_GROUPKIND__', '$groupKind', $needleTextKinds);
            if ($svcPageSlug !== '' && !str_contains($php, $needleTextKinds)) {
                $textInsert = $this->render_snippet('adminservice-text-like-main-editor.php.snip', [
                    'SVC_PAGE_SLUG_CODE' => $svcPageSlugCode,
                ]);

                $php2 = preg_replace(
                    "/\\n\\s*\\/\\/ Compact editor for known complex kinds \\(MVP: button\\)\\s*\\n/",
                    $textInsert . "\n                // Compact editor for known complex kinds (MVP: button)\n",
                    $php,
                    1
                );
                if (is_string($php2) && $php2 !== '' && $php2 !== $php) {
                    $php = $php2;
                    @file_put_contents($adminFile, $php);
                }
            }
            // Ensure componentEditorField args include prefill for button groups.
            $needleMembers = '\'members\' => __PBS_MEMBERS__';
            // Avoid Intelephense false positives on "$members" inside strings.
            $needleMembers = str_replace('__PBS_MEMBERS__', '$members', $needleMembers);
            if (!str_contains($php, "'prefill' =>") && str_contains($php, 'componentEditorField') && str_contains($php, $needleMembers)) {
                $repPrefill = <<<'REPL'
$1                            'prefill' => is_array(($flags['prefill'] ?? null)) ? (array) ($flags['prefill'] ?? []) : [],

REPL;
                $php2 = preg_replace(
                    str_replace('__PBS_MEMBERS_RE__', '\\$members', '/(\'members\'\\s*=>\\s*__PBS_MEMBERS_RE__,\\s*\\n)/m'),
                    $repPrefill,
                    $php,
                    1
                );
                if (is_string($php2) && $php2 !== '' && $php2 !== $php) {
                    @file_put_contents($adminFile, $php2);
                }
            }

            // For member rows, pass member prefill if available (no-op if already patched).
            $php = (string) @file_get_contents($adminFile);
            if ($php !== '' && str_contains($php, "'member_key'")) {
                $repMemberPrefill = <<<'REPL'
'label' => __PBS_ML__,
                            'prefill' => is_array((__PBS_FLAGS__['prefill'] ?? null)) ? (string) ((__PBS_FLAGS__['prefill'] ?? [])[__PBS_MK__] ?? '') : '',

REPL;
                // Avoid Intelephense false positives on "$ml/$flags/$mk" inside nowdoc strings.
                $repMemberPrefill = str_replace(
                    ['__PBS_ML__', '__PBS_FLAGS__', '__PBS_MK__'],
                    ['$ml', '$flags', '$mk'],
                    $repMemberPrefill
                );
                $patternLabelPrefill = str_replace(
                    '__PBS_ML_RE__',
                    '\\$ml',
                    '/\'label\'\\s*=>\\s*__PBS_ML_RE__,\\s*\\n(?!\\s*\'prefill\'\\s*=>)/m'
                );
                $php3 = preg_replace(
                    $patternLabelPrefill,
                    $repMemberPrefill,
                    $php
                );
                if (is_string($php3) && $php3 !== '' && $php3 !== $php) {
                    @file_put_contents($adminFile, $php3);
                }
            }

            // Ensure args include readonly => !$uiEditable (best-effort).
            if (!str_contains($php, "'readonly'") && str_contains($php, "'args' => [")) {
                $php2 = preg_replace(
                    "/('label'\\s*=>\\s*\\(string\\)\\s*\\$groupLabel,\\s*\\n)/",
                    "$1                                'readonly' => !\$uiEditable,\n",
                    $php
                );
                if (is_string($php2) && $php2 !== '' && $php2 !== $php) {
                    $php = $php2;
                    @file_put_contents($adminFile, $php);
                }
            }
        }

        // 3) Patch UI/BE templates to render List columns dynamically (flags.ui.list).
        $tplDir = rtrim($pluginDir, '/') . '/UI/BE/templates';
        if (is_dir($tplDir)) {
            foreach (glob($tplDir . '/*.php') ?: [] as $tpl) {
                if (!is_file($tpl)) {
                    continue;
                }
                $php = (string) @file_get_contents($tpl);
                if ($php === '') {
                    continue;
                }

                // Replace static header with dynamic header (robust to whitespace/newlines).
                $headerPattern = '/<thead>\\s*<tr>\\s*<th[^>]*>\\s*ID\\s*<\\/th>\\s*<th[^>]*>\\s*Data\\s*<\\/th>\\s*<th[^>]*>\\s*Azioni\\s*<\\/th>\\s*<\\/tr>\\s*<\\/thead>/is';
                if (preg_match($headerPattern, $php) !== 1) {
                    // Not a PBS-like service list template.
                    continue;
                }

                // 3a) Header: replace only if it doesn't already iterate list_cols.
                if (!str_contains($php, 'foreach ($list_cols as $c)')) {
                    $insert = <<<'PHP'
            <thead>
            <tr>
                <th style="width:80px;">ID</th>
                <th>Data</th>
                <?php foreach ($list_cols as $c): ?>
                    <th><?php echo esc_html((string) ($c['label'] ?? $c['db'] ?? '')); ?></th>
                <?php endforeach; ?>
                <th style="width:220px;">Azioni</th>
            </tr>
            </thead>
PHP;
                    $php2 = preg_replace($headerPattern, $insert, $php, 1);
                    if (is_string($php2) && $php2 !== '' && $php2 !== $php) {
                        $php = $php2;
                    }
                }

                // 3b) Add list_cols computation after $base_url line if missing.
                if (!str_contains($php, '$list_cols')) {
                    $php = preg_replace(
                        '/(\\$base_url\\s*=\\s*admin_url\\(\\x27admin\\.php\\?page=\\x27\\s*\\.\\s*[^;]+;\\s*\\n)/',
                        "$1\n// UI columns for List tab (derived from mapping flags.ui.list).\n\$list_cols = [];\nif (isset(\$service) && is_object(\$service) && isset(\$service->match_db_inp_type) && is_array(\$service->match_db_inp_type)) {\n    foreach (\$service->match_db_inp_type as \$db => \$meta) {\n        if (!is_array(\$meta)) { continue; }\n        \$flags = (array) (\$meta['flags'] ?? []);\n        \$ui = (array) (\$flags['ui'] ?? []);\n        \$show = array_key_exists('list', \$ui) ? (bool) \$ui['list'] : false;\n        if (!\$show) { continue; }\n        \$list_cols[] = [\n            'db' => (string) \$db,\n            'label' => (string) ((\$meta['label'] ?? '') !== '' ? \$meta['label'] : \$db),\n        ];\n    }\n}\n\n\$pbs_cell = static function (\$val): string {\n    if (is_array(\$val)) {\n        if (isset(\$val['title']) && is_scalar(\$val['title'])) {\n            \$s = (string) \$val['title'];\n        } elseif (isset(\$val['p']) && is_scalar(\$val['p'])) {\n            \$s = wp_strip_all_tags((string) \$val['p']);\n        } elseif (isset(\$val['url']) && is_scalar(\$val['url'])) {\n            \$s = (string) \$val['url'];\n        } else {\n            \$s = wp_json_encode(\$val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);\n        }\n        if (!is_string(\$s)) { \$s = ''; }\n        if (strlen(\$s) > 120) { \$s = substr(\$s, 0, 117) . '...'; }\n        return \$s;\n    }\n    \$s = is_scalar(\$val) ? (string) \$val : '';\n    if (strlen(\$s) > 120) { \$s = substr(\$s, 0, 117) . '...'; }\n    return \$s;\n};\n",
                        $php,
                        1
                    ) ?: $php;
                }

                // 3c) Patch row rendering: insert columns cells after date (only if not already present).
                if (!str_contains($php, 'foreach ($list_cols as $c):') || !str_contains($php, '$pbs_cell')) {
                    $php = preg_replace(
                        '/(<td>\\s*<\\?php\\s+echo\\s+esc_html\\(\\$date\\);\\s*\\?>\\s*<\\/td>\\s*)<td>/i',
                        "$1\n                        <?php foreach (\$list_cols as \$c): ?>\n                            <?php \$db = (string) (\$c['db'] ?? ''); ?>\n                            <td><?php echo esc_html(\$pbs_cell(\$db !== '' && is_array(\$r) && array_key_exists(\$db, \$r) ? \$r[\$db] : '')); ?></td>\n                        <?php endforeach; ?>\n                        <td>",
                        $php,
                        1
                    ) ?: $php;
                }

                // Patch colspan in empty records row (3 -> 3+count(list_cols)).
                $php = preg_replace(
                    '/<tr>\\s*<td\\s+colspan=\"3\"\\s*>\\s*<em>\\s*Nessun record\\.\\s*<\\/em>\\s*<\\/td>\\s*<\\/tr>/i',
                    '<tr><td colspan="<?php echo esc_attr((string) (3 + count($list_cols))); ?>"><em>Nessun record.</em></td></tr>',
                    $php,
                    1
                ) ?: $php;

                @file_put_contents($tpl, $php);
            }
        }
    }

    /**
     * Ensure Base*.php supports mirror one-way (nested -> mirror columns).
     *
     * This must be applied on delta-update too, otherwise mirror columns would stay empty.
     */
    private function ensure_mirror_support_in_base(string $php): string
    {
        if (str_contains($php, 'function apply_mirrors')) {
            // Still ensure save_record calls it.
            if (!str_contains($php, 'apply_mirrors($raw, $data)') && str_contains($php, '$data = $this->sanitize_payload')) {
                $php2 = preg_replace(
                    '/\\$data\\s*=\\s*\\$this->sanitize_payload\\([^;]*\\);/',
                    "\$data = \$this->sanitize_payload(\$this->match_db_inp_type, \$raw);\n        \$data = \$this->apply_mirrors(\$raw, \$data);",
                    $php,
                    1
                );
                if (is_string($php2) && $php2 !== '') {
                    $php = $php2;
                }
            }
            return $php;
        }

        // 1) Add call in save_record after sanitize_payload.
        if (str_contains($php, '$data = $this->sanitize_payload')) {
            $php2 = preg_replace(
                '/\\$data\\s*=\\s*\\$this->sanitize_payload\\([^;]*\\);/',
                "\$data = \$this->sanitize_payload(\$this->match_db_inp_type, \$raw);\n        \$data = \$this->apply_mirrors(\$raw, \$data);",
                $php,
                1
            );
            if (is_string($php2) && $php2 !== '') {
                $php = $php2;
            }
        }

        // 2) Insert apply_mirrors method before infer_formats().
        $method = <<<'PHP'

    /**
     * Apply one-way mirrors (nested -> flat columns).
     *
     * Mirror columns are derived values used for SQL/index/search/payload-light.
     * Canonical data remains in the nested group column.
     *
     * @param array<string,mixed> $raw   Raw input (POST/payload)
     * @param array<string,mixed> $data  Sanitized db-ready data
     * @return array<string,mixed>
     */
    protected function apply_mirrors(array $raw, array $data): array
    {
        foreach ($this->match_db_inp_type as $db => $meta) {
            $flags = (array) ($meta['flags'] ?? []);
            $mirror = $flags['mirror'] ?? null;
            if (!is_array($mirror)) {
                continue;
            }
            if ((string) ($mirror['mode'] ?? '') !== 'one_way') {
                continue;
            }
            $src = (array) ($mirror['source'] ?? []);
            $groupDb = sanitize_key((string) ($src['group_db'] ?? ''));
            $memberKey = sanitize_key((string) ($src['member_key'] ?? ''));
            if ($groupDb === '' || $memberKey === '') {
                continue;
            }

            $val = null;
            if (isset($raw[$groupDb])) {
                $v = $raw[$groupDb];
                if (is_string($v)) {
                    $decoded = json_decode($v, true);
                    $v = is_array($decoded) ? $decoded : null;
                }
                if (is_array($v) && array_key_exists($memberKey, $v)) {
                    $val = $v[$memberKey];
                }
            }

            // fallback: try from already-encoded group column
            if ($val === null && isset($data[$groupDb]) && is_string($data[$groupDb])) {
                $decoded = json_decode((string) $data[$groupDb], true);
                if (is_array($decoded) && array_key_exists($memberKey, $decoded)) {
                    $val = $decoded[$memberKey];
                }
            }

            if ($val === null) {
                continue;
            }

            $type = (string) ($meta['type'] ?? 'text');
            $data[$db] = $this->sanitize_value($type, $val);
        }
        return $data;
    }

PHP;

        if (!str_contains($php, 'protected function infer_formats')) {
            return $php;
        }

        $php2 = preg_replace('/\\n\\s*protected function infer_formats\\(/', $method . "\n    protected function infer_formats(", $php, 1);
        return is_string($php2) && $php2 !== '' ? $php2 : $php;
    }

    /**
     * Ensure Base*.php guards against missing DB columns after a schema evolution.
     *
     * PBS does not automatically ALTER tables; we return a clear error + suggested SQL instead.
     */
    private function ensure_table_columns_guard_in_base(string $php): string
    {
        if (str_contains($php, 'function ensure_table_has_columns')) {
            // Ensure save_record calls it.
            if (!str_contains($php, 'ensure_table_has_columns($data, $err)') && str_contains($php, '$data = $this->apply_mirrors')) {
                $php2 = preg_replace(
                    '/\\$data\\s*=\\s*\\$this->apply_mirrors\\([^;]*\\);/',
                    "\$data = \$this->apply_mirrors(\$raw, \$data);\n        if (!\$this->ensure_table_has_columns(\$data, \$err)) {\n            return 0;\n        }",
                    $php,
                    1
                );
                if (is_string($php2) && $php2 !== '') {
                    $php = $php2;
                }
            }
            return $php;
        }

        // 1) Insert call in save_record after apply_mirrors.
        if (str_contains($php, '$data = $this->apply_mirrors')) {
            $php2 = preg_replace(
                '/\\$data\\s*=\\s*\\$this->apply_mirrors\\([^;]*\\);/',
                "\$data = \$this->apply_mirrors(\$raw, \$data);\n        if (!\$this->ensure_table_has_columns(\$data, \$err)) {\n            return 0;\n        }",
                $php,
                1
            );
            if (is_string($php2) && $php2 !== '') {
                $php = $php2;
            }
        }

        // 2) Insert method before get_create_table_sql (stable anchor).
        $method = <<<'PHP'

    /**
     * Guard: avoid writing data into a table that is missing expected columns.
     *
     * PBS non modifica automaticamente il DB quando lo schema evolve.
     * Se mancano colonne (es. dopo un Delta servizio), questa funzione ritorna false
     * e fornisce SQL suggerito per l'ALTER TABLE.
     *
     * @param array<string,mixed> $data
     */
    protected function ensure_table_has_columns(array $data, string &$err = ''): bool
    {
        global $wpdb;
        $err = '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $cols = (array) $wpdb->get_results("SHOW COLUMNS FROM {$this->table}", ARRAY_A);
        $existing = [];
        foreach ($cols as $c) {
            if (is_array($c) && isset($c['Field'])) {
                $existing[(string) $c['Field']] = true;
            }
        }
        if (!$existing) {
            return true;
        }

        $missing = [];
        foreach (array_keys($data) as $k) {
            $k = (string) $k;
            if ($k === '' || $k === 'id') {
                continue;
            }
            if (!isset($existing[$k])) {
                $missing[] = $k;
            }
        }
        if (!$missing) {
            return true;
        }

        $sql = [];
        foreach ($missing as $m) {
            $sql[] = "ALTER TABLE {$this->table} ADD COLUMN `" . esc_sql($m) . "` LONGTEXT NULL;";
        }
        $err = 'Colonne mancanti nella tabella: ' . implode(', ', $missing) . '. SQL suggerito: ' . implode(' ', $sql);
        return false;
    }

PHP;

        if (str_contains($php, 'public function get_create_table_sql')) {
            $php2 = preg_replace(
                '/\\n\\s*public function get_create_table_sql\\(\\): string\\s*\\{/m',
                $method . "\n    public function get_create_table_sql(): string\n    {",
                $php,
                1
            );
            if (is_string($php2) && $php2 !== '') {
                $php = $php2;
            }
        }

        return $php;
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
