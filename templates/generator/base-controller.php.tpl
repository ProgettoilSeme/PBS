<?php

declare(strict_types=1);

namespace {{GLIB_NS}}\Supports\Components\Admin;

/**
 * BaseController (light) — sanitizzazione IN/OUT centralizzata.
 *
 * Policy: tutti i flussi devono rispettare il mapping `match_db_inp_type`.
 */
abstract class BaseController
{
    public const PLUGIN_ID = {{PLUGIN_ID_CODE}};
    public const PREFIX = {{PREFIX_CODE}};
    public const SETTINGS_ID = self::PLUGIN_ID . '_settings';

    /**
     * Pattern LSA: elenco "service managers" per attivazione servizi via Dashboard.
     *
     * Chiave: es. `admin_archives`
     * Valore: etichetta (es. "Activate Archives")
     *
     * @var array<string,string>
     */
    public array $srv_managers = [
{{SRV_MANAGERS_LINES}}
    ];

    public function __construct()
    {
        // Safety: remove empty rows.
        $this->srv_managers = array_filter($this->srv_managers, 'strlen');
    }

    /**
     * Determina se un servizio è attivo (dashboard).
     *
     * Default: disattivo finché l'admin non abilita esplicitamente dalla Dashboard.
     */
    public function activated(string $key): bool
    {
        if (!array_key_exists($key, $this->srv_managers)) {
            return false;
        }
        $opt = get_option(self::PLUGIN_ID);
        if ($opt === false || !is_array($opt)) {
            return false;
        }
        return array_key_exists($key, $opt) ? (bool) $opt[$key] : false;
    }

    /**
     * @param array<string,array<string,mixed>> $mapping
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public function sanitize_payload(array $mapping, array $raw): array
    {
        $out = [];

        foreach ($mapping as $db => $meta) {
            $type = (string) ($meta['type'] ?? 'text');
            $val = $raw[$db] ?? null;

            // Supporto gruppi (array_nested) generati da PBS: salva come JSON su una singola colonna.
            $groupMembers = (array) ($meta['flags']['group']['members'] ?? []);
            if (!empty($groupMembers)) {
                if (is_string($val)) {
                    $decoded = json_decode($val, true);
                    $val = is_array($decoded) ? $decoded : [];
                }
                if (!is_array($val)) {
                    $val = [];
                }

                $groupOut = [];
                foreach ($groupMembers as $m) {
                    if (!is_array($m)) {
                        continue;
                    }
                    $k = (string) ($m['key'] ?? '');
                    if ($k === '') {
                        continue;
                    }
                    $mt = (string) ($m['field_type'] ?? 'text');
                    $groupOut[$k] = $this->sanitize_value($mt, $val[$k] ?? null);
                }

                $out[$db] = wp_json_encode($groupOut);
                continue;
            }

            $out[$db] = $this->sanitize_value($type, $val);
        }

        return $out;
    }

    /**
     * Decode JSON groups (array_nested) back to arrays for consumption (BE/FE).
     *
     * @param array<string,array<string,mixed>> $mapping
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function decode_payload(array $mapping, array $row): array
    {
        foreach ($mapping as $db => $meta) {
            $groupMembers = (array) ($meta['flags']['group']['members'] ?? []);
            $isGroup = !empty($groupMembers) || ((string) ($meta['type'] ?? '') === 'array_nested');
            if (!$isGroup) {
                continue;
            }
            if (!isset($row[$db]) || !is_string($row[$db])) {
                continue;
            }
            $decoded = json_decode((string) $row[$db], true);
            if (is_array($decoded)) {
                $row[$db] = $decoded;
            }
        }
        return $row;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    protected function sanitize_value(string $type, $value)
    {
        if (is_array($value)) {
            return wp_json_encode($value);
        }

        $value = (string) ($value ?? '');

        return match ($type) {
            'url' => esc_url_raw($value),
            'email' => sanitize_email($value),
            'html' => wp_kses_post($value),
            'text_area' => sanitize_textarea_field($value),
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => ($value === '1' || strtolower($value) === 'true') ? 1 : 0,
            default => sanitize_text_field($value),
        };
    }
}
