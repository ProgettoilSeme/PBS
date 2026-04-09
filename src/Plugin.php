<?php

declare(strict_types=1);

namespace PBS;

use PBS\Admin\Menu;

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register(): void
    {
        // Admin UI
        if (is_admin()) {
            Menu::instance()->register();
        }
    }
}

