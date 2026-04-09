<?php

declare(strict_types=1);

namespace PBS\Admin;

use PBS\Admin\Pages\GeneratePage;
use PBS\Admin\Pages\GenerateServicePage;
use PBS\Admin\Pages\GlibCheckPage;
use PBS\Admin\Pages\DeltaServicePage;
use PBS\Admin\Pages\SchemasPage;
use PBS\Admin\Pages\SchemaFieldsPage;

final class Menu
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
        add_action('admin_menu', [$this, 'register_menu']);
        SchemasPage::instance()->register_actions();
        SchemaFieldsPage::instance()->register_actions();
        GeneratePage::instance()->register_actions();
        GenerateServicePage::instance()->register_actions();
        GlibCheckPage::instance()->register_actions();
        DeltaServicePage::instance()->register_actions();
    }

    public function register_menu(): void
    {
        $cap = 'manage_options';
        $slug = 'pbs';

        add_menu_page(
            'PBS',
            'PBS',
            $cap,
            $slug,
            [SchemasPage::instance(), 'render'],
            'dashicons-database',
            58
        );

        add_submenu_page(
            $slug,
            'Schemi',
            'Schemi',
            $cap,
            $slug,
            [SchemasPage::instance(), 'render']
        );

        add_submenu_page(
            $slug,
            'Dettaglio schema',
            'Dettaglio schema',
            $cap,
            'pbs-schema-fields',
            [SchemaFieldsPage::instance(), 'render']
        );

        add_submenu_page(
            $slug,
            'Genera plugin',
            'Genera plugin',
            $cap,
            'pbs-generate',
            [GeneratePage::instance(), 'render']
        );

        add_submenu_page(
            $slug,
            'Check gLib',
            'Check gLib',
            $cap,
            'pbs-glib-check',
            [GlibCheckPage::instance(), 'render']
        );

        add_submenu_page(
            $slug,
            'Genera servizio',
            'Genera servizio',
            $cap,
            'pbs-generate-service',
            [GenerateServicePage::instance(), 'render']
        );

        add_submenu_page(
            $slug,
            'Delta servizio',
            'Delta servizio',
            $cap,
            'pbs-delta-service',
            [DeltaServicePage::instance(), 'render']
        );
    }
}
