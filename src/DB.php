<?php

declare(strict_types=1);

namespace PBS;

/**
 * PBS internal DB schema.
 *
 * Nota: PBS usa `dbDelta()` solo per le tabelle interne (attivazione plugin = azione esplicita).
 */
final class DB
{
    /**
     * Schemas registry table (senza prefix WP).
     */
    public const SCHEMAS_TABLE = 'pbs_schemas';
    /**
     * Schema fields table (senza prefix WP).
     */
    public const FIELDS_TABLE = 'pbs_schema_fields';
    /**
     * Generations registry table (senza prefix WP).
     */
    public const GENERATIONS_TABLE = 'pbs_generations';

    /**
     * Ensure PBS tables exist (activation hook).
     */
    public static function ensure_schema(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $schemas = $wpdb->prefix . self::SCHEMAS_TABLE;
        $fields = $wpdb->prefix . self::FIELDS_TABLE;
        $gens = $wpdb->prefix . self::GENERATIONS_TABLE;

        // Nota: dbDelta è usata solo per le tabelle interne di PBS (attivazione plugin = azione esplicita).
        dbDelta(
            "CREATE TABLE {$schemas} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                slug varchar(190) NOT NULL,
                name varchar(255) NOT NULL,
                description text NULL,
                source_type varchar(32) NOT NULL DEFAULT 'manual',
                source_post_type varchar(190) NULL,
                source_acf_groups longtext NULL,
                schema_version int unsigned NOT NULL DEFAULT 1,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY slug (slug)
            ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$fields} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                schema_id bigint unsigned NOT NULL,
                ord int unsigned NOT NULL DEFAULT 0,
                db_column varchar(190) NOT NULL,
                input_name varchar(190) NOT NULL,
                label varchar(255) NOT NULL,
                field_type varchar(64) NOT NULL DEFAULT 'text',
                flags longtext NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY schema_id (schema_id),
                KEY db_column (db_column),
                KEY input_name (input_name)
            ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$gens} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                schema_id bigint unsigned NOT NULL,
                schema_version int unsigned NOT NULL DEFAULT 1,
                generation_version int unsigned NOT NULL DEFAULT 1,
                plugin_slug varchar(190) NOT NULL,
                plugin_name varchar(255) NOT NULL,
                glib_namespace varchar(32) NOT NULL,
                glib_suffix int unsigned NOT NULL,
                output_dir varchar(255) NOT NULL,
                status varchar(32) NOT NULL DEFAULT 'generated',
                report longtext NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY schema_id (schema_id),
                KEY plugin_slug (plugin_slug)
            ) {$charset};"
        );
    }
}
