<?php

declare(strict_types=1);

namespace {{GLIB_NS}}\Api\Services\CrossDomain;

use {{GLIB_NS}}\Api\Interfaces\iAPI;
use {{GLIB_NS}}\Api\Services\{{GROUP_NS}}\{{SERVICE_NS}}\Base{{SERVICE_CLASS}};

final class LibraryFrontEnd implements iAPI
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function {{METH_LIST}}(int $limit = 100): array
    {
        $base = new Base{{SERVICE_CLASS}}();
        return $base->list_records($limit);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function {{METH_GET}}(int $id): ?array
    {
        $base = new Base{{SERVICE_CLASS}}();
        return $base->get_record($id);
    }
}

