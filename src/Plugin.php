<?php

declare(strict_types=1);

namespace PBS;

use PBS\Admin\Menu;

/**
 * PBS plugin bootstrap.
 *
 * PBS è un generatore on-demand: registra prevalentemente la UI admin.
 */
final class Plugin
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
     * Register hooks.
     */
    public function register(): void
    {
        // Admin UI
        if (is_admin()) {
            Menu::instance()->register();
        }
    }
}
