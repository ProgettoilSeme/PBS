<?php

declare(strict_types=1);

namespace PBS\Admin\Pages;

use PBS\Services\GlibScanner;

final class GlibCheckPage
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
        add_action('admin_post_pbs_glib_whitelist_save', [$this, 'handle_whitelist_save']);
        add_action('admin_post_pbs_glib_whitelist_add', [$this, 'handle_whitelist_add']);
        add_action('admin_post_pbs_glib_whitelist_add_all', [$this, 'handle_whitelist_add_all']);
        add_action('admin_post_pbs_glib_scan', [$this, 'handle_scan']);
        add_action('admin_post_pbs_glib_set_pointer', [$this, 'handle_set_pointer']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }

        $whitelist = $this->get_whitelist();
        $pointer = $this->get_pointer();

        $scanner = new GlibScanner();
        $scan = $scanner->scan($whitelist);

        $suggestions = $this->build_suggestions($scanner, $whitelist);

        // Validate pointer against current scan; reset if missing.
        if (!empty($pointer['plugin_slug']) && !empty($pointer['glib_dir'])) {
            $ok = false;
            foreach ($scan as $row) {
                if (($row['plugin_slug'] ?? '') !== $pointer['plugin_slug']) {
                    continue;
                }
                foreach ((array) ($row['glib_dirs'] ?? []) as $gd) {
                    if (is_array($gd) && (string) ($gd['dir'] ?? '') === (string) $pointer['glib_dir']) {
                        $ok = true;
                        break 2;
                    }
                }
            }
            if (!$ok) {
                $pointer = ['plugin_slug' => '', 'glib_dir' => ''];
                update_option('pbs_glib_pointer', $pointer, false);
            }
        }

        require PBS_PLUGIN_DIR . 'templates/admin-glib-check.php';
    }

    public function handle_whitelist_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('pbs_glib_whitelist_save');

        $raw = (string) ($_POST['whitelist'] ?? '');
        $lines = preg_split('/\\R/', $raw) ?: [];
        $out = [];
        foreach ($lines as $l) {
            $l = trim((string) $l);
            if ($l === '' || str_starts_with($l, '#')) {
                continue;
            }
            $l = sanitize_text_field($l);
            if ($l === '' || str_contains($l, '..') || str_contains($l, '/')) {
                continue;
            }
            $out[$l] = true;
        }
        update_option('pbs_glib_whitelist', array_keys($out), false);

        wp_safe_redirect(add_query_arg(['page' => 'pbs-glib-check', 'pbs_ok' => 'saved'], admin_url('admin.php')));
        exit;
    }

    public function handle_whitelist_add(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('pbs_glib_whitelist_add');

        $slug = sanitize_text_field((string) ($_POST['plugin_slug'] ?? ''));
        $slug = trim($slug);
        if ($slug === '' || str_contains($slug, '..') || str_contains($slug, '/')) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-glib-check', 'pbs_err' => 'invalid_slug'], admin_url('admin.php')));
            exit;
        }

        $wl = $this->get_whitelist();
        $wl[] = $slug;
        $wl = array_values(array_unique($wl));
        update_option('pbs_glib_whitelist', $wl, false);

        wp_safe_redirect(add_query_arg(['page' => 'pbs-glib-check', 'pbs_ok' => 'saved'], admin_url('admin.php')));
        exit;
    }

    public function handle_whitelist_add_all(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('pbs_glib_whitelist_add_all');

        $raw = (string) ($_POST['plugin_slugs'] ?? '');
        $slugs = array_filter(array_map('trim', explode(',', $raw)), 'strlen');

        $wl = $this->get_whitelist();
        foreach ($slugs as $slug) {
            $slug = sanitize_text_field((string) $slug);
            if ($slug === '' || str_contains($slug, '..') || str_contains($slug, '/')) {
                continue;
            }
            $wl[] = $slug;
        }
        $wl = array_values(array_unique($wl));
        update_option('pbs_glib_whitelist', $wl, false);

        wp_safe_redirect(add_query_arg(['page' => 'pbs-glib-check', 'pbs_ok' => 'saved'], admin_url('admin.php')));
        exit;
    }

    public function handle_scan(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('pbs_glib_scan');

        // Scan is executed on render; this action exists to make UX explicit.
        wp_safe_redirect(add_query_arg(['page' => 'pbs-glib-check', 'pbs_ok' => 'scanned'], admin_url('admin.php')));
        exit;
    }

    public function handle_set_pointer(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('pbs_glib_set_pointer');

        $pluginSlug = sanitize_text_field((string) ($_POST['plugin_slug'] ?? ''));
        $glibDir = sanitize_text_field((string) ($_POST['glib_dir'] ?? ''));

        if ($pluginSlug === '' || $glibDir === '' || str_contains($pluginSlug, '..') || str_contains($pluginSlug, '/') || str_contains($glibDir, '..') || str_contains($glibDir, '/')) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-glib-check', 'pbs_err' => 'invalid_pointer'], admin_url('admin.php')));
            exit;
        }

        update_option('pbs_glib_pointer', [
            'plugin_slug' => $pluginSlug,
            'glib_dir' => $glibDir,
        ], false);

        wp_safe_redirect(add_query_arg(['page' => 'pbs-glib-check', 'pbs_ok' => 'pointer'], admin_url('admin.php')));
        exit;
    }

    /**
     * @return string[]
     */
    private function get_whitelist(): array
    {
        $v = get_option('pbs_glib_whitelist', []);
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $x) {
            $x = trim((string) $x);
            if ($x !== '') {
                $out[] = $x;
            }
        }
        return $out;
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
     * Build suggestions by scanning all plugin directories for gLib markers.
     *
     * @param string[] $whitelist
     * @return array<int,array<string,mixed>>
     */
    private function build_suggestions(GlibScanner $scanner, array $whitelist): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = function_exists('get_plugins') ? get_plugins() : [];
        if (!is_array($plugins) || !$plugins) {
            return [];
        }

        $wlSet = [];
        foreach ($whitelist as $w) {
            $wlSet[(string) $w] = true;
        }

        $rows = [];
        foreach ($plugins as $pluginFile => $data) {
            $pluginFile = (string) $pluginFile;
            $slug = dirname($pluginFile);
            if ($slug === '.' || $slug === '') {
                // single-file plugin at root: skip for now (not supported by PBS slug rules)
                continue;
            }
            if (isset($wlSet[$slug])) {
                continue;
            }

            $row = $scanner->scan_one($slug);
            if (($row['status'] ?? '') !== 'found') {
                continue;
            }
            $row['plugin_file'] = $pluginFile;
            $row['is_active'] = function_exists('is_plugin_active') ? (bool) is_plugin_active($pluginFile) : false;
            $row['plugin_name'] = is_array($data) ? (string) ($data['Name'] ?? $slug) : $slug;
            $rows[] = $row;
        }

        usort($rows, function (array $a, array $b): int {
            $aa = !empty($a['is_active']);
            $bb = !empty($b['is_active']);
            if ($aa !== $bb) {
                return $bb <=> $aa;
            }
            return strcmp((string) ($a['plugin_slug'] ?? ''), (string) ($b['plugin_slug'] ?? ''));
        });

        return $rows;
    }
}
