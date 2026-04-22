<?php

declare(strict_types=1);

namespace {{GLIB_NS}}\Api\Services\{{GROUP_NS}}\{{SERVICE_NS}};

use {{GLIB_NS}}\Supports\Components\Admin\BaseController;

/**
 * Base{{SERVICE_CLASS}} generata da PBS.
 *
 * - DDL/DLL: esposta come SQL (Help tab). Nessun allineamento automatico.
 * - CRUD: minimale per iniziare (da evolvere secondo standard gLib).
 */
class Base{{SERVICE_CLASS}} extends BaseController
{
    public const TABLE_KEY = {{TABLE_KEY_CODE}};

    public string $table = '';

    /**
     * Mapping (db_column -> name/type/label/flags).
     * Questo mapping deriva dallo schema PBS (import ACF o manuale).
     */
    public array $match_db_inp_type = [
{{MAPPING_LINES}}
    ];

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . self::TABLE_KEY;
    }

    public function ensure_table_exists(string &$err = ''): bool
    {
        global $wpdb;
        $err = '';

        // Quick check
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->table));
        if ($found === $this->table) {
            return true;
        }

        if (!function_exists('maybe_create_table')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $ddl = $this->get_create_table_sql();
        $ok = maybe_create_table($this->table, $ddl);
        if (!$ok) {
            // maybe_create_table a volte non lascia last_error valorizzato: riprova una query diretta per ottenere dettaglio.
            if ($wpdb->last_error === '') {
                $wpdb->query($ddl);
            }
            $err = $wpdb->last_error !== '' ? $wpdb->last_error : ('Impossibile creare la tabella. SQL: ' . $ddl);
            return false;
        }

        return true;
    }

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

        // Collect existing columns
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $cols = (array) $wpdb->get_results("SHOW COLUMNS FROM {$this->table}", ARRAY_A);
        $existing = [];
        foreach ($cols as $c) {
            if (is_array($c) && isset($c['Field'])) {
                $existing[(string) $c['Field']] = true;
            }
        }
        if (!$existing) {
            // If we can't read columns, don't block save: let WPDB return a real error.
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
            // Default: LONGTEXT NULL (compatibile con payload nested/serialized).
            $sql[] = "ALTER TABLE {$this->table} ADD COLUMN `" . esc_sql($m) . "` LONGTEXT NULL;";
        }
        $err = 'Colonne mancanti nella tabella: ' . implode(', ', $missing) . '. SQL suggerito: ' . implode(' ', $sql);
        return false;
    }

    public function get_create_table_sql(): string
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE " . $this->table . " (\n" .
            "        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
            {{COLUMNS_SQL_PHP}} .
            "        `created_at` DATETIME NULL,\n" .
            "        `updated_at` DATETIME NULL,\n" .
            "        PRIMARY KEY (`id`)\n" .
            ") " . $charset . ";";
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function list_records(int $limit = 100): array
    {
        global $wpdb;
        $e = '';
        if (!$this->ensure_table_exists($e)) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $sql = "SELECT * FROM {$this->table} ORDER BY id DESC LIMIT " . (int) $limit;
        $rows = (array) $wpdb->get_results($sql, ARRAY_A);
        foreach ($rows as $i => $r) {
            if (is_array($r)) {
                $rows[$i] = $this->decode_payload($this->match_db_inp_type, $r);
            }
        }
        return $rows;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get_record(int $id): ?array
    {
        global $wpdb;
        $e = '';
        if (!$this->ensure_table_exists($e)) {
            return null;
        }
        if ($id <= 0) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id=%d", $id), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        return $this->decode_payload($this->match_db_inp_type, $row);
    }

    public function delete_record(int $id, string &$err = ''): bool
    {
        global $wpdb;
        if (!$this->ensure_table_exists($err)) {
            return false;
        }
        if ($id <= 0) {
            $err = 'ID non valido.';
            return false;
        }
        $ok = $wpdb->delete($this->table, ['id' => $id], ['%d']);
        if ($ok === false) {
            $err = $wpdb->last_error !== '' ? $wpdb->last_error : 'Delete fallita.';
            return false;
        }
        return true;
    }

    /**
     * @param array<string,mixed> $raw
     */
    public function save_record(int $id, array $raw, string &$err = ''): int
    {
        global $wpdb;
        if (!$this->ensure_table_exists($err)) {
            return 0;
        }
        $data = $this->sanitize_payload($this->match_db_inp_type, $raw);
        $data = $this->apply_mirrors($raw, $data);
        if (!$this->ensure_table_has_columns($data, $err)) {
            return 0;
        }

        // timestamps
        $now = current_time('mysql');
        if ($id > 0) {
            $data['updated_at'] = $now;
            $formats = $this->infer_formats($data);
            $ok = $wpdb->update($this->table, $data, ['id' => $id], $formats, ['%d']);
            if ($ok === false) {
                $err = $wpdb->last_error !== '' ? $wpdb->last_error : 'Update fallita.';
                return 0;
            }
            return $id;
        }

        $data['created_at'] = $now;
        $formats = $this->infer_formats($data);
        $ok = $wpdb->insert($this->table, $data, $formats);
        if (!$ok) {
            $err = $wpdb->last_error !== '' ? $wpdb->last_error : 'Insert fallita.';
            return 0;
        }
        return (int) $wpdb->insert_id;
    }

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

    /**
     * @param array<string,mixed> $data
     * @return array<int,string>
     */
    protected function infer_formats(array $data): array
    {
        $formats = [];
        foreach (array_keys($data) as $k) {
            $formats[] = $k === 'id' ? '%d' : '%s';
        }
        return $formats;
    }
}

