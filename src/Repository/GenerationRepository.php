<?php

declare(strict_types=1);

namespace PBS\Repository;

use PBS\DB;

/**
 * Repository: generations registry (`pbs_generations`).
 *
 * Registra output di generazioni (plugin/service) e le versioni (schema + generation).
 */
final class GenerationRepository
{
    /**
     * Compute next generation version for a schema.
     */
    public function next_generation_version(int $schemaId): int
    {
        global $wpdb;
        $t = $wpdb->prefix . DB::GENERATIONS_TABLE;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $max = $wpdb->get_var($wpdb->prepare("SELECT MAX(generation_version) FROM {$t} WHERE schema_id=%d", $schemaId));
        $max = (int) ($max ?: 0);
        return max(1, $max + 1);
    }

    /**
     * Insert a generation record.
     *
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        global $wpdb;
        $t = $wpdb->prefix . DB::GENERATIONS_TABLE;

        $insert = [
            'schema_id' => (int) ($data['schema_id'] ?? 0),
            'schema_version' => (int) ($data['schema_version'] ?? 1),
            'generation_version' => (int) ($data['generation_version'] ?? 1),
            'plugin_slug' => (string) ($data['plugin_slug'] ?? ''),
            'plugin_name' => (string) ($data['plugin_name'] ?? ''),
            'glib_namespace' => (string) ($data['glib_namespace'] ?? ''),
            'glib_suffix' => (int) ($data['glib_suffix'] ?? 0),
            'output_dir' => (string) ($data['output_dir'] ?? ''),
            'status' => (string) ($data['status'] ?? 'generated'),
            'report' => isset($data['report']) ? wp_json_encode($data['report']) : null,
            'created_at' => current_time('mysql'),
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert($t, $insert);
        return (int) $wpdb->insert_id;
    }

    /**
     * Delete all generation records for a schema.
     */
    public function delete_all_by_schema(int $schemaId): void
    {
        global $wpdb;
        $t = $wpdb->prefix . DB::GENERATIONS_TABLE;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE schema_id=%d", $schemaId));
    }
}
