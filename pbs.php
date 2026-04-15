<?php
/**
 * @package Environment
 * @subpackage Plugin
 */
/*
Plugin Name: PBS (Power Bridge SQL)
Plugin URI: https://www.progettoilseme.it

Description: Generatore on-demand di servizi gLib-compliant a partire da schema dati (manuale o da CPT/ACF).
Version: 0.0.2
Released: 15 apr 2026
Author: Giorgio Codazzi

Licence: © 2026 Progetto il Seme
Text Domain: PBS
*/

declare(strict_types=1);

defined('ABSPATH') or die('Hey, you can\'t access this file, you silly human!');
if (!function_exists('add_action')) {
    echo 'Hey, you can\'t access this file, you silly human!';
    exit;
}

// Main plugin file reference (directory-independent)
if (!defined('PBS_PLUGIN_FILE')) {
    define('PBS_PLUGIN_FILE', __FILE__);
}

/**
 * Esporta i metadati dell'header di questo file come costanti PBS_PLUGIN_*.
 *
 * Fonte unica di verità: l'header WordPress del plugin (Plugin Name, Version, Licence, ecc.).
 * Da queste etichette ricaviamo automaticamente costanti del tipo:
 *  - PBS_PLUGIN_PLUGIN_NAME, PBS_PLUGIN_VERSION, PBS_PLUGIN_AUTHOR, PBS_PLUGIN_LICENCE,
 *  - PBS_PLUGIN_TEXT_DOMAIN, PBS_PLUGIN_PLUGIN_URI, ...
 * oltre a utility come PBS_PLUGIN_BASENAME, PBS_PLUGIN_DIR e PBS_PLUGIN_URL.
 */
if (!defined('PBS_PLUGIN_META')) {
    if (!function_exists('get_file_data')) {
        /** @noinspection PhpIncludeInspection */
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $pbs_header = get_file_data(
        __FILE__,
        array(
            'Plugin Name'       => 'Plugin Name',
            'Plugin URI'        => 'Plugin URI',
            'GitHub plugin URI' => 'GitHub plugin URI',
            'Description'       => 'Description',
            'Version'           => 'Version',
            'Released'          => 'Released',
            'Author'            => 'Author',
            'Author URI'        => 'Author URI',
            'Licence'           => 'Licence',
            'Text Domain'       => 'Text Domain',
        ),
        'plugin'
    );

    define('PBS_PLUGIN_META', $pbs_header);

    // Evita problemi di intellisense con PBS_PLUGIN_TEXT_DOMAIN
    if (!defined('PBS_PLUGIN_TEXT_DOMAIN')) {
        define('PBS_PLUGIN_TEXT_DOMAIN', 'PBS');
    }

    foreach ($pbs_header as $label => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        $suffix    = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', trim((string) $label)));
        $constName = 'PBS_PLUGIN_' . $suffix;
        if (!defined($constName)) {
            define($constName, $value);
        }
    }

    // Utility comuni per path/URL del plugin.
    if (!defined('PBS_PLUGIN_BASENAME')) {
        define('PBS_PLUGIN_BASENAME', plugin_basename(__FILE__));
    }
    if (!defined('PBS_PLUGIN_DIR')) {
        define('PBS_PLUGIN_DIR', plugin_dir_path(__FILE__));
    }
    if (!defined('PBS_PLUGIN_URL')) {
        define('PBS_PLUGIN_URL', plugin_dir_url(__FILE__));
    }

    // Alias comodo per lo slug di traduzione senza underscore.
    if (defined('PBS_PLUGIN_TEXT_DOMAIN') && !defined('PBS_PLUGIN_TEXTDOMAIN')) {
        define('PBS_PLUGIN_TEXTDOMAIN', (string) constant('PBS_PLUGIN_TEXT_DOMAIN'));
    }
}

// Back-compat constant used in code.
if (!defined('PBS_VERSION')) {
    // Usa constant() per evitare warning statici (Intelephense) su costanti definite dinamicamente.
    define('PBS_VERSION', defined('PBS_PLUGIN_VERSION') ? (string) constant('PBS_PLUGIN_VERSION') : '0.0.1');
}

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
