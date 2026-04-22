<?php

declare(strict_types=1);

namespace PBS\Admin\Pages;

use PBS\Repository\FieldRepository;
use PBS\Repository\SchemaRepository;
use PBS\Services\GlibScanner;
use PBS\Services\GlibServiceScanner;
use PBS\Services\ServiceDelta;

/**
 * Admin page: PBS → Delta servizio.
 *
 * Permette di selezionare:
 * - schema PBS
 * - plugin target + gLib dir + servizio
 *
 * E poi:
 * - analizzare il delta (added/removed/changed) con warning + SQL suggeriti
 * - applicare update “solo mapping” sul Base*.php del servizio (no auto DB changes)
 */
final class DeltaServicePage
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
     * Register admin-post handlers.
     */
    public function register_actions(): void
    {
        add_action('admin_post_pbs_delta_analyze', [$this, 'handle_analyze']);
        add_action('admin_post_pbs_delta_apply', [$this, 'handle_apply']);
        add_action('admin_post_pbs_delta_drop_table', [$this, 'handle_drop_table']);
    }

    /**
     * Render page.
     */
    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }

        $schemaRepo = new SchemaRepository();
        $schemas = $schemaRepo->list();

        $pointer = $this->get_pointer();
        $pluginSlug = sanitize_text_field((string) ($_GET['plugin_slug'] ?? $pointer['plugin_slug']));
        $glibDir = sanitize_text_field((string) ($_GET['glib_dir'] ?? $pointer['glib_dir']));

        $scanner = new GlibScanner();
        $plugins = $this->list_glib_plugins($scanner);

        // UX: se non c'è un puntatore e c'è un solo plugin candidato, pre-selezionalo.
        if ($pluginSlug === '' && count($plugins) === 1) {
            $pluginSlug = (string) ($plugins[0]['plugin_slug'] ?? '');
        }

        $glibDirs = [];
        $glibNamespace = '';
        $glibFullDir = '';
        if ($pluginSlug !== '') {
            $row = $scanner->scan_one($pluginSlug);
            $glibDirs = (array) ($row['glib_dirs'] ?? []);
            // Default: scegli il primo (già ordinato per suffix desc).
            if ($glibDir === '' && !empty($glibDirs[0]['dir'])) {
                $glibDir = (string) $glibDirs[0]['dir'];
            }
            foreach ($glibDirs as $gd) {
                if (is_array($gd) && (string) ($gd['dir'] ?? '') === $glibDir) {
                    $glibNamespace = (string) ($gd['namespace'] ?? '');
                    $glibFullDir = (string) ($gd['full_dir'] ?? '');
                    break;
                }
            }
        }

        $svcScanner = new GlibServiceScanner();
        $services = $glibFullDir !== '' ? $svcScanner->list_services($glibFullDir) : [];

        $schemaId = isset($_GET['schema_id']) ? (int) $_GET['schema_id'] : 0;
        $serviceKey = sanitize_key((string) ($_GET['service_key'] ?? ''));
        if ($serviceKey === '' && !empty($services[0]['service_key'])) {
            $serviceKey = (string) $services[0]['service_key'];
        }

        $result = null;
        if (!empty($_GET['pbs_delta'])) {
            $result = get_transient('pbs_last_delta_result_' . get_current_user_id());
            delete_transient('pbs_last_delta_result_' . get_current_user_id());
        }

        $tablesNotice = null;
        if (!empty($_GET['pbs_tables_notice'])) {
            $tablesNotice = get_transient('pbs_delta_tables_notice_' . get_current_user_id());
            delete_transient('pbs_delta_tables_notice_' . get_current_user_id());
        }

        $tables = [];
        if ($pluginSlug !== '' && $glibFullDir !== '') {
            $tables = $this->list_service_tables($services);
        }

        require PBS_PLUGIN_DIR . 'templates/admin-delta-service.php';
    }

    public function handle_analyze(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('pbs_delta_analyze');

        $schemaId = (int) ($_POST['schema_id'] ?? 0);
        $pluginSlug = sanitize_text_field((string) ($_POST['plugin_slug'] ?? ''));
        $glibDir = sanitize_text_field((string) ($_POST['glib_dir'] ?? ''));
        $serviceKey = sanitize_key((string) ($_POST['service_key'] ?? ''));

        $delta = new ServiceDelta();
        $result = $delta->analyze($schemaId, $pluginSlug, $glibDir, $serviceKey);
        set_transient('pbs_last_delta_result_' . get_current_user_id(), $result, 60);

        wp_safe_redirect(add_query_arg([
            'page' => 'pbs-delta-service',
            'schema_id' => $schemaId,
            'plugin_slug' => $pluginSlug,
            'glib_dir' => $glibDir,
            'service_key' => $serviceKey,
            'pbs_delta' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_apply(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('pbs_delta_apply');

        $schemaId = (int) ($_POST['schema_id'] ?? 0);
        $pluginSlug = sanitize_text_field((string) ($_POST['plugin_slug'] ?? ''));
        $glibDir = sanitize_text_field((string) ($_POST['glib_dir'] ?? ''));
        $serviceKey = sanitize_key((string) ($_POST['service_key'] ?? ''));

        $delta = new ServiceDelta();
        $result = $delta->apply($schemaId, $pluginSlug, $glibDir, $serviceKey);
        set_transient('pbs_last_delta_result_' . get_current_user_id(), $result, 60);

        wp_safe_redirect(add_query_arg([
            'page' => 'pbs-delta-service',
            'schema_id' => $schemaId,
            'plugin_slug' => $pluginSlug,
            'glib_dir' => $glibDir,
            'service_key' => $serviceKey,
            'pbs_delta' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_drop_table(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('pbs_delta_drop_table');

        $schemaId = (int) ($_POST['schema_id'] ?? 0);
        $pluginSlug = sanitize_text_field((string) ($_POST['plugin_slug'] ?? ''));
        $glibDir = sanitize_text_field((string) ($_POST['glib_dir'] ?? ''));
        $serviceKey = sanitize_key((string) ($_POST['service_key'] ?? ''));
        $tableKey = sanitize_key((string) ($_POST['table_key'] ?? ''));

        $ok = false;
        $msg = '';

        if ($pluginSlug === '' || $glibDir === '' || $tableKey === '') {
            $msg = 'Parametri mancanti.';
        } else {
            $scanner = new GlibScanner();
            $row = $scanner->scan_one($pluginSlug);
            $glibFullDir = '';
            foreach ((array) ($row['glib_dirs'] ?? []) as $gd) {
                if (is_array($gd) && (string) ($gd['dir'] ?? '') === $glibDir) {
                    $glibFullDir = (string) ($gd['full_dir'] ?? '');
                    break;
                }
            }

            if ($glibFullDir === '' || !is_dir($glibFullDir)) {
                $msg = 'gLib non trovata.';
            } else {
                $svcScanner = new GlibServiceScanner();
                $services = $svcScanner->list_services($glibFullDir);

                // Whitelist: allow drop only for tables declared by Base*.php in this gLib.
                $allowed = [];
                foreach ($services as $svc) {
                    if (!is_array($svc)) {
                        continue;
                    }
                    $baseFile = (string) ($svc['base_file'] ?? '');
                    if ($baseFile === '' || !is_file($baseFile)) {
                        continue;
                    }
                    $php = (string) @file_get_contents($baseFile);
                    if ($php === '') {
                        continue;
                    }
                    $k = $this->extract_table_key($php);
                    if ($k !== '') {
                        $allowed[$k] = true;
                    }
                }

                if (!isset($allowed[$tableKey])) {
                    $msg = 'Tabella non consentita (table_key non riconosciuta per questa gLib).';
                } else {
                    global $wpdb;
                    $tableName = $wpdb->prefix . $tableKey;
                    $safe = str_replace('`', '', $tableName);
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
                    $r = $wpdb->query("DROP TABLE IF EXISTS `{$safe}`");
                    if ($r === false) {
                        $msg = $wpdb->last_error !== '' ? $wpdb->last_error : 'DROP fallito.';
                    } else {
                        $ok = true;
                        $msg = 'Tabella droppata: ' . $safe;
                    }
                }
            }
        }

        set_transient('pbs_delta_tables_notice_' . get_current_user_id(), [
            'ok' => $ok,
            'message' => $msg,
        ], 60);

        wp_safe_redirect(add_query_arg([
            'page' => 'pbs-delta-service',
            'schema_id' => $schemaId,
            'plugin_slug' => $pluginSlug,
            'glib_dir' => $glibDir,
            'service_key' => $serviceKey,
            'pbs_tables_notice' => '1',
        ], admin_url('admin.php')));
        exit;
    }


    /**
     * @return array{plugin_slug:string,glib_dir:string}
     */
    private function get_pointer(): array
    {
        $p = get_option('pbs_glib_pointer', ['plugin_slug' => '', 'glib_dir' => '']);
        if (!is_array($p)) {
            return ['plugin_slug' => '', 'glib_dir' => ''];
        }
        return [
            'plugin_slug' => (string) ($p['plugin_slug'] ?? ''),
            'glib_dir' => (string) ($p['glib_dir'] ?? ''),
        ];
    }

    /**
     * @return array<int,array{plugin_slug:string,plugin_name:string,is_active:bool,glib_dirs:array<int,array<string,mixed>>}>
     */
    private function list_glib_plugins(GlibScanner $scanner): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = function_exists('get_plugins') ? get_plugins() : [];
        if (!is_array($plugins) || !$plugins) {
            return [];
        }

        $rows = [];
        foreach ($plugins as $pluginFile => $data) {
            $pluginFile = (string) $pluginFile;
            $slug = dirname($pluginFile);
            if ($slug === '.' || $slug === '') {
                continue;
            }
            $row = $scanner->scan_one($slug);
            if (($row['status'] ?? '') !== 'found') {
                continue;
            }
            $rows[] = [
                'plugin_slug' => (string) ($row['plugin_slug'] ?? $slug),
                'plugin_name' => is_array($data) ? (string) ($data['Name'] ?? $slug) : $slug,
                'is_active' => function_exists('is_plugin_active') ? (bool) is_plugin_active($pluginFile) : false,
                'glib_dirs' => (array) ($row['glib_dirs'] ?? []),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $aa = !empty($a['is_active']);
            $bb = !empty($b['is_active']);
            if ($aa !== $bb) {
                return $bb <=> $aa;
            }
            return strcmp((string) ($a['plugin_slug'] ?? ''), (string) ($b['plugin_slug'] ?? ''));
        });

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $services
     * @return array<int,array{service_key:string,service_label:string,table_key:string,table_name:string,exists:bool,rows:int|null}>
     */
    private function list_service_tables(array $services): array
    {
        global $wpdb;

        $out = [];
        foreach ($services as $svc) {
            if (!is_array($svc)) {
                continue;
            }
            $baseFile = (string) ($svc['base_file'] ?? '');
            if ($baseFile === '' || !is_file($baseFile)) {
                continue;
            }
            $php = (string) @file_get_contents($baseFile);
            if ($php === '') {
                continue;
            }
            $tableKey = $this->extract_table_key($php);
            if ($tableKey === '') {
                continue;
            }

            $tableName = $wpdb->prefix . $tableKey;
            $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tableName)) === $tableName;

            $rows = null;
            if ($exists) {
                $safe = str_replace('`', '', $tableName);
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
                $cnt = $wpdb->get_var("SELECT COUNT(*) FROM `{$safe}`");
                if ($cnt !== null && $cnt !== false) {
                    $rows = (int) $cnt;
                }
            }

            $group = (string) ($svc['group'] ?? '');
            $dir = (string) ($svc['service_dir'] ?? '');
            $serviceLabel = trim($group . ' / ' . $dir);

            $out[] = [
                'service_key' => (string) ($svc['service_key'] ?? ''),
                'service_label' => $serviceLabel !== '' ? $serviceLabel : (string) ($svc['service_key'] ?? ''),
                'table_key' => $tableKey,
                'table_name' => $tableName,
                'exists' => $exists,
                'rows' => $rows,
            ];
        }

        usort($out, static function (array $a, array $b): int {
            return strcmp((string) ($a['service_label'] ?? ''), (string) ($b['service_label'] ?? ''));
        });

        return $out;
    }

    private function extract_table_key(string $php): string
    {
        if (preg_match('/\\bTABLE_KEY\\s*=\\s*([\\\"\\\'])([^\\\"\\\']+)\\1\\s*;/', $php, $m) === 1) {
            return (string) ($m[2] ?? '');
        }
        return '';
    }
}
