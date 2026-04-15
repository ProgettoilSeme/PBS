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
                $insert = <<<'PHP'

    /**
     * Render a compact editor for complex components (single Settings API row).
     *
     * Prodotto finale: mostra solo l'anteprima. La configurazione dettagliata
     * del componente è gestita in PBS (non nel plugin generato).
     */
    public function componentEditorField(array $args): void
    {
        $groupDb = (string) ($args['group_db'] ?? '');
        $groupKind = (string) ($args['group_kind'] ?? '');
        $groupLabel = (string) ($args['group_label'] ?? $groupDb);
        if ($groupDb === '' || $groupKind === '') {
            return;
        }

        if ($groupKind !== 'button') {
            return;
        }

        $this->componentPreviewField([
            'group_db' => $groupDb,
            'group_kind' => $groupKind,
            'group_label' => $groupLabel,
            'prefill' => is_array($args['prefill'] ?? null) ? (array) $args['prefill'] : [],
        ]);
        echo '<p class="description" style="margin-top:8px;">Configurazione componente gestita in PBS (non nel plugin generato).</p>';
    }

PHP;

                // If exists, replace; otherwise insert before class end.
	                if (str_contains($php, 'function componentEditorField')) {
	                    $phpNew = preg_replace(
	                        '/\n\s{4}public function componentEditorField\(array \$args\): void\s*\{.*?\n\s{4}\}\n/s',
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

        // 2) Patch service Admin*.php Settings API loop to pass prefill to callbacks.
        if ($adminFile !== '' && is_file($adminFile)) {
            $php = (string) @file_get_contents($adminFile);
            if ($php === '') {
                return;
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
                    "foreach (\$this->match_db_inp_type as \$db => \$meta) {\n            \$flags = (array) (\$meta['flags'] ?? []);\n            \$flow = (array) (\$flags['flow'] ?? []);\n            \$showBe = array_key_exists('be', \$flow) ? (bool) \$flow['be'] : true;\n            if (!empty(\$flags['hidden']) || !\$showBe || !empty(\$flags['mirror'])) {\n                continue;\n            }\n\n            ",
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
            if ($svcPageSlug !== '' && !str_contains($php, "in_array(\$groupKind, ['text', 'title_text']")) {
                $textInsert = <<<PHP

                // Text-like components: render a single main editor (hide internal members like boxtext).
                if (in_array(\$groupKind, ['text', 'title_text'], true)) {
                    \$mainKey = '';
                    \$mainType = 'text';
                    foreach (\$members as \$m) {
                        if (!is_array(\$m)) {
                            continue;
                        }
                        \$mk2 = (string) (\$m['key'] ?? '');
                        if (\$mk2 === '') {
                            continue;
                        }
                        \$mt2 = (string) (\$m['field_type'] ?? 'text');
                        if (\$mainKey === '') {
                            \$mainKey = \$mk2;
                            \$mainType = \$mt2;
                        }
                        if (\$mt2 === 'html') {
                            \$mainKey = \$mk2;
                            \$mainType = \$mt2;
                            break;
                        }
                    }
                    if (\$mainKey !== '') {
                        \$fields[] = [
                            'id' => {$svcPageSlugCode} . '_' . sanitize_key((string) \$db . '_' . (string) \$mainKey),
                            'title' => \$groupLabel,
                            'callback' => [\$this->adminCallbacks, 'inputField'],
                            'page' => {$svcPageSlugCode},
                            'section' => {$svcPageSlugCode} . '_main',
                            'args' => [
                                'group_db' => (string) \$db,
                                'group_kind' => (string) \$groupKind,
                                'member_key' => (string) \$mainKey,
                                'type' => (string) \$mainType,
                                'label' => (string) \$groupLabel,
                                'prefill' => is_array((\$flags['prefill'] ?? null)) ? (string) ((\$flags['prefill'] ?? [])[\$mainKey] ?? '') : '',
                            ],
                        ];
                        continue;
                    }
                }

PHP;

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
            if (!str_contains($php, "'prefill' =>") && str_contains($php, 'componentEditorField') && str_contains($php, '\'members\' => $members')) {
                $repPrefill = <<<'REPL'
$1                            'prefill' => is_array(($flags['prefill'] ?? null)) ? (array) ($flags['prefill'] ?? []) : [],

REPL;
                $php2 = preg_replace(
                    '/(\'members\'\\s*=>\\s*\\$members,\\s*\\n)/m',
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
'label' => $ml,
                            'prefill' => is_array(($flags['prefill'] ?? null)) ? (string) (($flags['prefill'] ?? [])[$mk] ?? '') : '',

REPL;
                $php3 = preg_replace(
                    '/\'label\'\\s*=>\\s*\\$ml,\\s*\\n(?!\\s*\'prefill\'\\s*=>)/m',
                    $repMemberPrefill,
                    $php
                );
                if (is_string($php3) && $php3 !== '' && $php3 !== $php) {
                    @file_put_contents($adminFile, $php3);
                }
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
