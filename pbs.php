<?php
/**
 * Plugin Name: PBS (Power Bridge SQL)
 * Description: Generatore on-demand di servizi gLib-compliant a partire da schema dati (manuale o da CPT/ACF).
 * Version: 0.0.0
 * Author: Giorgio
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('PBS_VERSION', '0.0.0');
define('PBS_PLUGIN_FILE', __FILE__);
define('PBS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PBS_PLUGIN_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'PBS\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);
    $path = PBS_PLUGIN_DIR . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

register_activation_hook(__FILE__, static function (): void {
    if (class_exists(\PBS\DB::class)) {
        \PBS\DB::ensure_schema();
    }
});

add_action('plugins_loaded', static function (): void {
    if (class_exists(\PBS\Plugin::class)) {
        \PBS\Plugin::instance()->register();
    }
});
