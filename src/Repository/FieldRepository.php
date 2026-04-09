<?php

declare(strict_types=1);

namespace PBS\Repository;

use PBS\DB;

final class FieldRepository
{
    public function get(int $schemaId, int $fieldId): ?array
    {
        global $wpdb;
        $t = $wpdb->prefix . DB::FIELDS_TABLE;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE schema_id=%d AND id=%d", $schemaId, $fieldId), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function list_by_schema(int $schemaId): array
    {
        global $wpdb;
        $t = $wpdb->prefix . DB::FIELDS_TABLE;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE schema_id=%d ORDER BY ord ASC, id ASC", $schemaId), ARRAY_A) ?: [];
    }

    public function add(int $schemaId, array $data): int
    {
        global $wpdb;
        $t = $wpdb->prefix . DB::FIELDS_TABLE;

        $flags = $data['flags'] ?? [];
        $insert = [
            'schema_id' => $schemaId,
            'ord' => (int) ($data['ord'] ?? 0),
            'db_column' => (string) ($data['db_column'] ?? ''),
            'input_name' => (string) ($data['input_name'] ?? ''),
            'label' => (string) ($data['label'] ?? ''),
            'field_type' => (string) ($data['field_type'] ?? 'text'),
            'flags' => $flags ? wp_json_encode($flags) : null,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert($t, $insert);
        return (int) $wpdb->insert_id;
    }

    public function delete(int $schemaId, int $fieldId): void
    {
        global $wpdb;
        $t = $wpdb->prefix . DB::FIELDS_TABLE;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->delete($t, ['schema_id' => $schemaId, 'id' => $fieldId], ['%d', '%d']);
    }

    public function update(int $schemaId, int $fieldId, array $data): void
    {
        global $wpdb;
        $t = $wpdb->prefix . DB::FIELDS_TABLE;

        $update = [
            'db_column' => (string) ($data['db_column'] ?? ''),
            'input_name' => (string) ($data['input_name'] ?? ''),
            'label' => (string) ($data['label'] ?? ''),
            'field_type' => (string) ($data['field_type'] ?? 'text'),
            'flags' => isset($data['flags']) ? wp_json_encode($data['flags']) : null,
            'updated_at' => current_time('mysql'),
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->update(
            $t,
            $update,
            ['schema_id' => $schemaId, 'id' => $fieldId],
            ['%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d', '%d']
        );
    }

    public function delete_all_by_schema(int $schemaId): void
    {
        global $wpdb;
        $t = $wpdb->prefix . DB::FIELDS_TABLE;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE schema_id=%d", $schemaId));
    }

    /**
     * Replace all fields for schema with provided normalized list.
     *
     * @param int $schemaId
     * @param array<int,array<string,mixed>> $fields
     */
    public function replace_all(int $schemaId, array $fields): void
    {
        global $wpdb;
        $t = $wpdb->prefix . DB::FIELDS_TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE schema_id=%d", $schemaId));

        $ord = 0;
        foreach ($fields as $f) {
            $ord++;
            $this->add($schemaId, [
                'ord' => $ord,
                'db_column' => (string) ($f['db_column'] ?? ''),
                'input_name' => (string) ($f['input_name'] ?? ''),
                'label' => (string) ($f['label'] ?? ''),
                'field_type' => (string) ($f['field_type'] ?? 'text'),
                'flags' => $f['flags'] ?? [],
            ]);
        }
    }
}
