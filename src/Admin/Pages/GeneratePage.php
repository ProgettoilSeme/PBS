<?php

declare(strict_types=1);

namespace PBS\Admin\Pages;

use PBS\Repository\SchemaRepository;
use PBS\Repository\FieldRepository;
use PBS\Repository\GenerationRepository;
use PBS\Services\PluginGenerator;

final class GeneratePage
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register_actions(): void
    {
        add_action('admin_post_pbs_generate_plugin', [$this, 'handle_generate']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }

        $schemaId = isset($_GET['schema_id']) ? (int) $_GET['schema_id'] : 0;
        $schemaRepo = new SchemaRepository();
        $schemas = $schemaRepo->list();
        $schema = $schemaId > 0 ? $schemaRepo->get($schemaId) : null;
        $fields = $schema ? (new FieldRepository())->list_by_schema((int) $schema['id']) : [];

        $genResult = null;
        if (!empty($_GET['pbs_gen'])) {
            $genResult = get_transient('pbs_last_gen_result_' . get_current_user_id());
            delete_transient('pbs_last_gen_result_' . get_current_user_id());
        }

        require PBS_PLUGIN_DIR . 'templates/admin-generate.php';
    }

    public function handle_generate(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('pbs_generate_plugin');

        $schemaId = (int) ($_POST['schema_id'] ?? 0);
        $pluginSlug = sanitize_title($_POST['plugin_slug'] ?? '');
        $pluginName = sanitize_text_field($_POST['plugin_name'] ?? '');
        $pluginIcon = sanitize_text_field($_POST['plugin_icon'] ?? '');
        // service_slug deve poter essere usato anche in namespace/directory: normalizziamo a key.
        $serviceSlug = sanitize_key($_POST['service_slug'] ?? '');
        $serviceName = sanitize_text_field($_POST['service_name'] ?? '');
        $serviceMenuLabel = sanitize_text_field($_POST['service_menu_label'] ?? '');
        $serviceGroup = sanitize_key($_POST['service_group'] ?? 'shared');
        if (!in_array($serviceGroup, ['shared', 'internal', 'control'], true)) {
            $serviceGroup = 'shared';
        }

        $generator = new PluginGenerator();
        $result = $generator->generate($schemaId, $pluginSlug, $pluginName, [
            'plugin_icon' => $pluginIcon,
            'service_slug' => $serviceSlug,
            'service_name' => $serviceName,
            'service_menu_label' => $serviceMenuLabel,
            'service_group' => $serviceGroup,
        ]);

        // Persist result temporarily for UX
        set_transient('pbs_last_gen_result_' . get_current_user_id(), $result, 60);

        if ($result['ok'] ?? false) {
            $schema = (new SchemaRepository())->get($schemaId);
            if ($schema) {
                $genRepo = new GenerationRepository();
                $genRepo->create([
                    'schema_id' => $schemaId,
                    'schema_version' => (int) $schema['schema_version'],
                    'generation_version' => $genRepo->next_generation_version($schemaId),
                    'plugin_slug' => $pluginSlug,
                    'plugin_name' => $pluginName !== '' ? $pluginName : $pluginSlug,
                    'glib_namespace' => (string) ($result['glib_namespace'] ?? ''),
                    'glib_suffix' => (int) ($result['glib_suffix'] ?? 0),
                    'output_dir' => (string) ($result['output_dir'] ?? ''),
                    'status' => 'generated',
                    'report' => [
                        'warnings' => $result['warnings'] ?? [],
                        'files' => $result['files'] ?? [],
                    ],
                ]);
            }
        }

        wp_safe_redirect(add_query_arg(['page' => 'pbs-generate', 'schema_id' => $schemaId, 'pbs_gen' => '1'], admin_url('admin.php')));
        exit;
    }
}
