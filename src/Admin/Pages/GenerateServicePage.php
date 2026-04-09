<?php

declare(strict_types=1);

namespace PBS\Admin\Pages;

use PBS\Repository\SchemaRepository;

/**
 * Admin page: PBS → Genera servizio.
 *
 * (MVP) UI/config per generazione/aggancio di un servizio su gLib esistente.
 * Le azioni di patch/apply verranno aggiunte in iterazioni successive.
 */
final class GenerateServicePage
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
     * Register handlers (MVP: none).
     */
    public function register_actions(): void
    {
        // MVP: solo UI/config. Le azioni di patch/apply saranno aggiunte in seguito.
    }

    /**
     * Render page.
     */
    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }

        $schemaId = isset($_GET['schema_id']) ? (int) $_GET['schema_id'] : 0;
        $schemaRepo = new SchemaRepository();
        $schemas = $schemaRepo->list();
        $schema = $schemaId > 0 ? $schemaRepo->get($schemaId) : null;

        $pointer = get_option('pbs_glib_pointer', ['plugin_slug' => '', 'glib_dir' => '']);
        if (!is_array($pointer)) {
            $pointer = ['plugin_slug' => '', 'glib_dir' => ''];
        }
        $pointer = [
            'plugin_slug' => (string) ($pointer['plugin_slug'] ?? ''),
            'glib_dir' => (string) ($pointer['glib_dir'] ?? ''),
        ];

        require PBS_PLUGIN_DIR . 'templates/admin-generate-service.php';
    }
}
