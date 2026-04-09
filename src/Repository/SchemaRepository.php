<?php

declare(strict_types=1);

namespace PBS\Repository;

use PBS\DB;

/**
 * Repository: PBS schemas (`pbs_schemas`).
 *
 * Tutte le query sono relative alle tabelle interne PBS (con prefix WP).
 */
final class SchemaRepository
{
    /**
     * List schemas (most recent first).
     *
     * @return array<int,array<string,mixed>>
     */
    public function list(): array
    {
        global $wpdb;
        $t = $wpdb->prefix . DB::SCHEMAS_TABLE;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return $wpdb->get_results("SELECT * FROM {$t} ORDER BY updated_at DESC", ARRAY_A) ?: [];
    }

    /**
     * Get schema by id.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        global $wpdb;
        $t = $wpdb->prefix . DB::SCHEMAS_TABLE;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d", $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Create a new schema.
     *
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        global $wpdb;
        $t = $wpdb->prefix . DB::SCHEMAS_TABLE;

        $insert = [
            'slug' => (string) ($data['slug'] ?? ''),
            'name' => (string) ($data['name'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'source_type' => (string) ($data['source_type'] ?? 'manual'),
            'source_post_type' => (string) ($data['source_post_type'] ?? ''),
            'source_acf_groups' => null,
            'schema_version' => 1,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert($t, $insert);
        return (int) $wpdb->insert_id;
    }

    /**
     * Update schema fields (metadata only, not fields list).
     *
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): void
    {
        global $wpdb;
        $t = $wpdb->prefix . DB::SCHEMAS_TABLE;

        $update = [];
        $format = [];

        foreach (['name', 'description', 'source_type', 'source_post_type'] as $k) {
            if (array_key_exists($k, $data)) {
                $update[$k] = (string) $data[$k];
                $format[] = '%s';
            }
        }

        if (array_key_exists('source_acf_groups', $data)) {
            $update['source_acf_groups'] = wp_json_encode($data['source_acf_groups']);
            $format[] = '%s';
        }

        $update['updated_at'] = current_time('mysql');
        $format[] = '%s';

        if (!$update) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->update($t, $update, ['id' => $id], $format, ['%d']);
    }

    /**
     * Bump `schema_version` and update `updated_at`.
     */
    public function bump_version(int $id): void
    {
        global $wpdb;
        $t = $wpdb->prefix . DB::SCHEMAS_TABLE;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare("UPDATE {$t} SET schema_version = schema_version + 1, updated_at=%s WHERE id=%d", current_time('mysql'), $id));
    }

    /**
     * Delete schema (does not cascade; callers are responsible for related tables cleanup).
     */
    public function delete(int $id): void
    {
        global $wpdb;
        $t = $wpdb->prefix . DB::SCHEMAS_TABLE;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->delete($t, ['id' => $id], ['%d']);
    }
}
