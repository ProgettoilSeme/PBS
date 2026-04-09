<?php

declare(strict_types=1);

namespace PBS\Admin\Pages;

use PBS\Repository\SchemaRepository;
use PBS\Repository\FieldRepository;
use PBS\Repository\GenerationRepository;
use PBS\Services\ACFImporter;

/**
 * Admin page: PBS → Schemi.
 *
 * Gestisce:
 * - lista schemi
 * - creazione/modifica metadati schema
 * - help
 *
 * Nota: i campi dello schema sono gestiti in una pagina separata (`SchemaFieldsPage`).
 */
final class SchemasPage
{
    private static ?self $instance = null;

    /**
     * Singleton instance.
     */
    public static function instance(): self
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register admin-post handlers for this page.
     */
    public function register_actions(): void
    {
        add_action('admin_post_pbs_schema_create', [$this, 'handle_schema_create']);
        add_action('admin_post_pbs_schema_update', [$this, 'handle_schema_update']);
        add_action('admin_post_pbs_schema_delete', [$this, 'handle_schema_delete']);
    }

    /**
     * Render page.
     */
    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }

        $tab = sanitize_key($_GET['tab'] ?? 'list');
        if (!in_array($tab, ['list', 'edit', 'new', 'help'], true)) {
            $tab = 'list';
        }

        $schemaRepo = new SchemaRepository();
        $importer = new ACFImporter();

        $schemas = $schemaRepo->list();

        $schemaId = isset($_GET['schema_id']) ? (int) $_GET['schema_id'] : 0;
        if ($schemaId <= 0 && $schemas) {
            $schemaId = (int) $schemas[0]['id'];
        }

        $schema = $schemaId > 0 ? $schemaRepo->get($schemaId) : null;

        $acfAvailable = $importer->is_available();

        require PBS_PLUGIN_DIR . 'templates/admin-schemas-tabs.php';
    }

    public function handle_schema_create(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('pbs_schema_create');

        $slug = sanitize_title($_POST['slug'] ?? '');
        $name = sanitize_text_field($_POST['name'] ?? '');
        $description = sanitize_textarea_field($_POST['description'] ?? '');
        $sourceType = sanitize_text_field($_POST['source_type'] ?? 'manual');
        $postType = sanitize_text_field($_POST['source_post_type'] ?? '');

        if ($slug === '' || $name === '') {
            wp_safe_redirect(add_query_arg(['page' => 'pbs', 'tab' => 'new', 'pbs_err' => 'missing'], admin_url('admin.php')));
            exit;
        }

        $schemaId = (new SchemaRepository())->create([
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
            'source_type' => $sourceType,
            'source_post_type' => $postType,
        ]);

        wp_safe_redirect(add_query_arg(['page' => 'pbs', 'tab' => 'edit', 'schema_id' => $schemaId, 'pbs_ok' => 'schema_created'], admin_url('admin.php')));
        exit;
    }

    public function handle_schema_update(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('pbs_schema_update');

        $schemaId = (int) ($_POST['schema_id'] ?? 0);
        if ($schemaId <= 0) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs'], admin_url('admin.php')));
            exit;
        }

        (new SchemaRepository())->update($schemaId, [
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'source_type' => sanitize_text_field($_POST['source_type'] ?? 'manual'),
            'source_post_type' => sanitize_text_field($_POST['source_post_type'] ?? ''),
        ]);

        wp_safe_redirect(add_query_arg(['page' => 'pbs', 'tab' => 'edit', 'schema_id' => $schemaId, 'pbs_ok' => 'schema_saved'], admin_url('admin.php')));
        exit;
    }

    public function handle_schema_delete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('pbs_schema_delete');

        $schemaId = (int) ($_POST['schema_id'] ?? 0);
        if ($schemaId <= 0) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs'], admin_url('admin.php')));
            exit;
        }
        // Deep delete to avoid orphans.
        (new FieldRepository())->delete_all_by_schema($schemaId);
        (new GenerationRepository())->delete_all_by_schema($schemaId);
        (new SchemaRepository())->delete($schemaId);

        wp_safe_redirect(add_query_arg(['page' => 'pbs', 'tab' => 'list', 'pbs_ok' => 'schema_deleted'], admin_url('admin.php')));
        exit;
    }
}
