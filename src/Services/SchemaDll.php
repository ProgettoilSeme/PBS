<?php

declare(strict_types=1);

namespace PBS\Services;

/**
 * SchemaDll
 *
 * Centralizza la "DLL" (schema DB) attesa per un servizio generato da PBS.
 *
 * Concetti:
 * - Derivata: la DLL di default è ricavata dallo schema PBS (campi -> colonne).
 * - Preview: una snapshot on-demand salvata in DB (options) che può includere anche metadati UI (List/Edit/Editable).
 *
 * Nota: la preview NON è SQL, è una rappresentazione editabile (array) da cui si può generare SQL.
 */
final class SchemaDll
{
    private const OPT_PREFIX = 'pbs_schema_dll_preview_';

    /**
     * @return array<string,mixed>|null
     */
    public function get_preview(int $schemaId): ?array
    {
        if ($schemaId <= 0) {
            return null;
        }
        $raw = get_option(self::OPT_PREFIX . $schemaId, null);
        if (!is_array($raw)) {
            return null;
        }
        return $raw;
    }

    /**
     * @param array<string,mixed> $preview
     */
    public function save_preview(int $schemaId, array $preview): void
    {
        if ($schemaId <= 0) {
            return;
        }
        update_option(self::OPT_PREFIX . $schemaId, $preview, false);
    }

    public function delete_preview(int $schemaId): void
    {
        if ($schemaId <= 0) {
            return;
        }
        delete_option(self::OPT_PREFIX . $schemaId);
    }

    /**
     * Build default DLL representation from PBS schema fields.
     *
     * @param array<int,array<string,mixed>> $schemaFields
     * @return array{columns:array<int,array<string,mixed>>,meta:array<string,mixed>}
     */
    public function build_default(array $schemaFields): array
    {
        $columns = [];

        // System columns (always present in generated services)
        foreach ([
            ['db_column' => 'id', 'sql_type' => 'BIGINT UNSIGNED', 'nullable' => false, 'source' => 'system'],
            ['db_column' => 'created_at', 'sql_type' => 'DATETIME', 'nullable' => true, 'source' => 'system'],
            ['db_column' => 'updated_at', 'sql_type' => 'DATETIME', 'nullable' => true, 'source' => 'system'],
        ] as $c) {
            $columns[] = array_merge($c, [
                'enabled' => true,
                'ui' => $this->default_ui_for_column((string) $c['db_column']),
            ]);
        }

        foreach ($schemaFields as $f) {
            if (!is_array($f)) {
                continue;
            }
            $db = sanitize_key((string) ($f['db_column'] ?? ''));
            if ($db === '' || in_array($db, ['id', 'created_at', 'updated_at'], true)) {
                continue;
            }

            $fieldType = (string) ($f['field_type'] ?? 'text');
            $flags = json_decode((string) ($f['flags'] ?? ''), true) ?: [];
            $isGroup = is_array($flags) && !empty($flags['group']);
            $sqlType = $this->sql_type_from_field($fieldType, $isGroup);

            $ui = is_array($flags['ui'] ?? null) ? (array) $flags['ui'] : [];
            $ui = array_merge(['list' => false, 'edit' => true, 'editable' => empty($flags['readonly'])], $ui);

            $columns[] = [
                'db_column' => $db,
                'sql_type' => $sqlType,
                'nullable' => true,
                'source' => 'schema',
                'enabled' => true,
                'ui' => $ui,
            ];
        }

        return [
            'columns' => $columns,
            'meta' => [
                'generated_at' => current_time('mysql'),
                'version' => 1,
            ],
        ];
    }

    /**
     * Merge schema-derived default into an existing preview.
     *
     * Policy:
     * - system + schema columns are kept in sync (added if missing)
     * - manual columns are preserved
     * - orphan columns (present in preview but no longer in schema) are preserved and marked source=orphan
     *
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $derived
     * @return array{preview:array<string,mixed>,diff:array{added:array<int,string>,orphaned:array<int,string>}}
     */
    public function merge_preview(array $existing, array $derived): array
    {
        $existingCols = is_array($existing['columns'] ?? null) ? (array) $existing['columns'] : [];
        $derivedCols = is_array($derived['columns'] ?? null) ? (array) $derived['columns'] : [];

        $exByDb = [];
        foreach ($existingCols as $c) {
            if (is_array($c) && !empty($c['db_column'])) {
                $exByDb[(string) $c['db_column']] = $c;
            }
        }
        $derByDb = [];
        foreach ($derivedCols as $c) {
            if (is_array($c) && !empty($c['db_column'])) {
                $derByDb[(string) $c['db_column']] = $c;
            }
        }

        $added = [];
        foreach ($derByDb as $db => $c) {
            if (!isset($exByDb[$db])) {
                $added[] = $db;
                $exByDb[$db] = $c;
            } else {
                // Keep user ui settings if present, but update sql_type if schema changed.
                $ui = is_array($exByDb[$db]['ui'] ?? null) ? (array) $exByDb[$db]['ui'] : [];
                $enabled = array_key_exists('enabled', $exByDb[$db]) ? (bool) $exByDb[$db]['enabled'] : true;
                $exByDb[$db] = array_merge($exByDb[$db], $c);
                if ($ui) {
                    $exByDb[$db]['ui'] = array_merge((array) ($c['ui'] ?? []), $ui);
                }
                $exByDb[$db]['enabled'] = $enabled;
            }
        }

        $orphaned = [];
        foreach ($exByDb as $db => $c) {
            $src = (string) ($c['source'] ?? '');
            if ($src === 'manual') {
                continue;
            }
            if (isset($derByDb[$db])) {
                continue;
            }
            if (!in_array($db, ['id', 'created_at', 'updated_at'], true)) {
                $orphaned[] = $db;
                $exByDb[$db]['source'] = 'orphan';
            }
        }

        // Preserve manual columns that are not in derived (already in exByDb).
        // Output ordered: system, schema, manual, orphan
        $orderBuckets = ['system' => 1, 'schema' => 2, 'manual' => 3, 'orphan' => 4];
        $colsOut = array_values($exByDb);
        usort($colsOut, static function ($a, $b) use ($orderBuckets): int {
            $sa = is_array($a) ? (string) ($a['source'] ?? '') : '';
            $sb = is_array($b) ? (string) ($b['source'] ?? '') : '';
            $oa = $orderBuckets[$sa] ?? 99;
            $ob = $orderBuckets[$sb] ?? 99;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }
            $da = is_array($a) ? (string) ($a['db_column'] ?? '') : '';
            $dbb = is_array($b) ? (string) ($b['db_column'] ?? '') : '';
            return $da <=> $dbb;
        });

        $preview = $existing;
        $preview['columns'] = $colsOut;
        $preview['meta'] = array_merge(is_array($existing['meta'] ?? null) ? (array) $existing['meta'] : [], [
            'merged_at' => current_time('mysql'),
        ]);

        return [
            'preview' => $preview,
            'diff' => [
                'added' => $added,
                'orphaned' => $orphaned,
            ],
        ];
    }

    /**
     * Effective DLL representation used by generator/delta:
     * - preview if exists (user-initialized)
     * - otherwise derived default
     *
     * @param array<int,array<string,mixed>> $schemaFields
     * @return array{columns:array<int,array<string,mixed>>,meta:array<string,mixed>,has_preview:bool}
     */
    public function get_effective(int $schemaId, array $schemaFields): array
    {
        $preview = $this->get_preview($schemaId);
        if (is_array($preview) && is_array($preview['columns'] ?? null)) {
            $cols = [];
            foreach ((array) $preview['columns'] as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $db = sanitize_key((string) ($c['db_column'] ?? ''));
                if ($db === '') {
                    continue;
                }
                if (in_array($db, ['id', 'created_at', 'updated_at'], true)) {
                    $c['enabled'] = true;
                }
                $enabled = array_key_exists('enabled', $c) ? (bool) $c['enabled'] : true;
                if ($enabled) {
                    $cols[] = $c;
                }
            }
            return [
                'columns' => $cols,
                'meta' => is_array($preview['meta'] ?? null) ? (array) $preview['meta'] : [],
                'has_preview' => true,
            ];
        }
        $def = $this->build_default($schemaFields);
        return [
            'columns' => (array) ($def['columns'] ?? []),
            'meta' => (array) ($def['meta'] ?? []),
            'has_preview' => false,
        ];
    }

    private function default_ui_for_column(string $db): array
    {
        return match ($db) {
            'id' => ['list' => true, 'edit' => true, 'editable' => false],
            'updated_at' => ['list' => true, 'edit' => false, 'editable' => false],
            'created_at' => ['list' => false, 'edit' => false, 'editable' => false],
            default => ['list' => false, 'edit' => true, 'editable' => true],
        };
    }

    private function sql_type_from_field(string $fieldType, bool $isGroup): string
    {
        if ($isGroup) {
            return 'LONGTEXT';
        }
        return match ($fieldType) {
            'url' => 'VARCHAR(2048)',
            'email' => 'VARCHAR(255)',
            'html', 'text_area' => 'LONGTEXT',
            'int' => 'BIGINT',
            'float' => 'DOUBLE',
            'bool' => 'TINYINT(1)',
            default => 'VARCHAR(255)',
        };
    }
}
