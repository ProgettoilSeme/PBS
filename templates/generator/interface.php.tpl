<?php

declare(strict_types=1);

namespace {{GLIB_NS}}\Api\Interfaces;

/**
 * Interfaccia API FE “leggera” (da estendere).
 *
 * Nota: questo plugin è indipendente e usa namespace {{GLIB_NS}}.
 * Policy: nessun delta automatico DB/DDL in runtime (FE/BE).
 */
interface iAPI
{
    public const PLUGIN_PREFIX = {{PLUGIN_PREFIX_CODE}};
}

