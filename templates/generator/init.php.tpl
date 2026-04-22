<?php

declare(strict_types=1);

namespace {{GLIB_NS}};

use {{GLIB_NS}}\Api\Services\CrossDomain\LibraryFrontEnd;
use {{GLIB_NS}}\Api\Services\CrossDomain\LibraryBackEnd;
use {{GLIB_NS}}\Api\Services\Internal\AdminDashboard;
use {{GLIB_NS}}\Api\Services\{{GROUP_NS}}\{{SERVICE_NS}}\Admin{{SERVICE_CLASS}};

final class Init
{
    /**
     * In questa prima versione “leggera” la Init registra solo le Library.
     * I servizi specifici sono presenti ma non vengono auto-migrati né auto-abilitati.
     */
    public static function get_services(): array
    {
        return [
            LibraryFrontEnd::class,
            LibraryBackEnd::class,
            AdminDashboard::class,
            Admin{{SERVICE_CLASS}}::class,
        ];
    }

    public static function register_services(): void
    {
        foreach (self::get_services() as $class) {
            if (class_exists($class) && method_exists($class, 'instance')) {
                $class::instance();
            }
        }
    }
}
