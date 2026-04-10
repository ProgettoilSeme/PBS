<?php

declare(strict_types=1);

namespace PBS\Services;

use PBS\Repository\FieldRepository;
use PBS\Repository\SchemaRepository;

/**
 * PluginGenerator
 *
 * Genera un nuovo plugin WordPress con gLib `gLib_fNN` (namespace incrementale)
 * e un servizio standard derivato dallo schema PBS.
 */
final class PluginGenerator
{
    /**
     * Generate a new plugin from schema.
     *
     * @return array{ok:bool,errors:array<int,string>,warnings:array<int,string>,output_dir?:string,glib_namespace?:string,glib_suffix?:int,files?:array<int,string>}
     */
    public function generate(int $schemaId, string $pluginSlug, string $pluginName, array $opts = []): array
    {
        $schemaRepo = new SchemaRepository();
        $fieldRepo = new FieldRepository();
        $validator = new CompatibilityValidator();

        $schema = $schemaRepo->get($schemaId);
        if (!$schema) {
            return ['ok' => false, 'errors' => ['Schema non trovato.'], 'warnings' => []];
        }

        $fields = $fieldRepo->list_by_schema($schemaId);
        $fields = $this->normalize_fields_for_generation($fields);
        $v = $validator->validate_fields($fields);
        if ($v['errors']) {
            return ['ok' => false, 'errors' => $v['errors'], 'warnings' => $v['warnings']];
        }

        $pluginSlug = sanitize_title($pluginSlug);
        $pluginName = trim($pluginName) !== '' ? $pluginName : $pluginSlug;
        if ($pluginSlug === '') {
            return ['ok' => false, 'errors' => ['plugin_slug mancante.'], 'warnings' => $v['warnings']];
        }

        $serviceSlugRaw = (string) ($opts['service_slug'] ?? '');
        $serviceSlug = $this->normalize_service_slug($serviceSlugRaw !== '' ? $serviceSlugRaw : (string) $schema['slug']);
        $serviceNameRaw = (string) ($opts['service_name'] ?? '');
        $serviceName = trim($serviceNameRaw) !== '' ? trim($serviceNameRaw) : (string) $schema['name'];
        $serviceMenuLabelRaw = (string) ($opts['service_menu_label'] ?? '');
        $serviceMenuLabel = trim($serviceMenuLabelRaw) !== '' ? trim($serviceMenuLabelRaw) : $serviceName;
        $serviceGroup = sanitize_key((string) ($opts['service_group'] ?? 'shared'));
        if (!in_array($serviceGroup, ['shared', 'internal', 'control'], true)) {
            $serviceGroup = 'shared';
        }
        $pluginIconRaw = (string) ($opts['plugin_icon'] ?? '');
        $pluginIcon = trim($pluginIconRaw) !== '' ? trim($pluginIconRaw) : 'dashicons-database';

        $serviceClass = $this->studly($serviceName !== '' ? $serviceName : $serviceSlug);
        $serviceNsSegment = $this->studly($serviceSlug);
        $groupNs = $this->service_group_ns($serviceGroup);

        $glibSuffix = $this->next_glib_suffix();
        $glibNamespace = sprintf('gLib_f%02d', $glibSuffix);

        $baseDir = trailingslashit(WP_PLUGIN_DIR) . $pluginSlug;
        $files = [];

        $mk = $this->mkdir($baseDir);
        if (!$mk['ok']) {
            return ['ok' => false, 'errors' => $mk['errors'], 'warnings' => $v['warnings']];
        }

        $files[] = $this->write_file($baseDir . "/{$pluginSlug}.php", $this->render_plugin_main($pluginName, $pluginSlug, $glibNamespace, (int) $schema['id'], (int) $schema['schema_version']));
        $this->mkdir($baseDir . "/{$glibNamespace}/Api/Interfaces");
        $this->mkdir($baseDir . "/{$glibNamespace}/Api/Callbacks");
        $this->mkdir($baseDir . "/{$glibNamespace}/Api/Services/CrossDomain");
        $this->mkdir($baseDir . "/{$glibNamespace}/Api/Services/{$groupNs}/{$serviceNsSegment}");
        $this->mkdir($baseDir . "/{$glibNamespace}/Supports/Components/Admin");
        $this->mkdir($baseDir . "/UI/BE/templates");

        $files[] = $this->write_file($baseDir . "/{$glibNamespace}/Init.php", $this->render_init($glibNamespace, $groupNs, $serviceNsSegment, $serviceClass));
        $files[] = $this->write_file($baseDir . "/{$glibNamespace}/Api/SettingsApi.php", $this->render_settings_api($glibNamespace));
        $files[] = $this->write_file($baseDir . "/{$glibNamespace}/Api/Callbacks/AdminCallbacks.php", $this->render_admin_callbacks($glibNamespace));
        $files[] = $this->write_file($baseDir . "/{$glibNamespace}/Supports/Components/Admin/BaseController.php", $this->render_base_controller($glibNamespace, $pluginSlug));
        $files[] = $this->write_file($baseDir . "/{$glibNamespace}/Api/Interfaces/iAPI.php", $this->render_interface($glibNamespace, $pluginSlug));
        $files[] = $this->write_file($baseDir . "/{$glibNamespace}/Api/Services/CrossDomain/LibraryFrontEnd.php", $this->render_library_fe($glibNamespace, $groupNs, $serviceNsSegment, $serviceClass, $serviceSlug));
        $files[] = $this->write_file($baseDir . "/{$glibNamespace}/Api/Services/CrossDomain/LibraryBackEnd.php", $this->render_library_be($glibNamespace, $groupNs, $serviceNsSegment, $serviceClass, $serviceSlug));

        $files[] = $this->write_file(
            $baseDir . "/{$glibNamespace}/Api/Services/{$groupNs}/{$serviceNsSegment}/Base{$serviceClass}.php",
            $this->render_base_service($glibNamespace, $groupNs, $serviceNsSegment, $serviceClass, $pluginSlug, $serviceSlug, $fields)
        );
        $files[] = $this->write_file(
            $baseDir . "/{$glibNamespace}/Api/Services/{$groupNs}/{$serviceNsSegment}/Admin{$serviceClass}.php",
            $this->render_admin_service($glibNamespace, $groupNs, $serviceNsSegment, $serviceClass, $pluginSlug, $pluginName, $serviceMenuLabel, $serviceSlug, $pluginIcon)
        );
        $files[] = $this->write_file(
            $baseDir . "/{$glibNamespace}/Api/Services/{$groupNs}/{$serviceNsSegment}/{$serviceClass}Callbacks.php",
            $this->render_callbacks($glibNamespace, $groupNs, $serviceNsSegment, $serviceClass, $pluginSlug, $serviceSlug)
        );
        $files[] = $this->write_file(
            $baseDir . "/UI/BE/templates/" . sanitize_key($serviceSlug) . ".php",
            $this->render_be_tab_template($pluginSlug, $pluginName, $serviceSlug, $serviceMenuLabel)
        );

        $files = array_values(array_filter($files));
        if (count($files) < 1) {
            return ['ok' => false, 'errors' => ['Nessun file generato (write fallita).'], 'warnings' => $v['warnings']];
        }

        return [
            'ok' => true,
            'errors' => [],
            'warnings' => $v['warnings'],
            'output_dir' => $baseDir,
            'glib_namespace' => $glibNamespace,
            'glib_suffix' => $glibSuffix,
            'files' => $files,
        ];
    }

    /**
     * Normalize fields for generation.
     *
     * Goal: se uno schema PBS contiene gruppi complessi con `flags.group.kind` riconosciuto
     * (componenti standard, es. `text`, `button`), il generatore applica una normalizzazione:
     * - key members (rimozione prefissi tipo `button_title`)
     * - field_type coerenti col registry (bool/url/html...)
     *
     * In questo modo il plugin generato “traduce” i descrittori nel componente standard.
     *
     * @param array<int,array<string,mixed>> $fields
     * @return array<int,array<string,mixed>>
     */
    private function normalize_fields_for_generation(array $fields): array
    {
        foreach ($fields as $i => $f) {
            if (!is_array($f)) {
                continue;
            }
            $flags = json_decode((string) ($f['flags'] ?? ''), true);
            if (!is_array($flags) || empty($flags['group']) || !is_array($flags['group'])) {
                continue;
            }

            $kind = sanitize_key((string) ($flags['group']['kind'] ?? ''));
            if ($kind === '' || $kind === 'generic' || !ComponentRegistry::is_known_kind($kind)) {
                continue;
            }

            $members = (array) ($flags['group']['members'] ?? []);
            if (!$members) {
                continue;
            }

            $outMembers = [];
            foreach ($members as $m) {
                if (!is_array($m)) {
                    continue;
                }
                $rawKey = (string) ($m['key'] ?? '');
                $normKey = ComponentRegistry::normalize_member_key($kind, $rawKey);
                if ($normKey === '') {
                    $normKey = sanitize_key($rawKey);
                }
                if ($normKey === '') {
                    continue;
                }

                $acfType = '';
                if (!empty($m['acf']) && is_array($m['acf']) && isset($m['acf']['type'])) {
                    $acfType = (string) $m['acf']['type'];
                }

                $m['key'] = $normKey;
                $m['field_type'] = ComponentRegistry::infer_member_type($kind, $normKey, $acfType);
                if (!isset($m['label']) || (string) $m['label'] === '') {
                    $m['label'] = $normKey;
                }

                $outMembers[] = $m;
            }

            $flags['group']['kind'] = $kind;
            $flags['group']['members'] = $outMembers;
            $f['flags'] = wp_json_encode($flags);
            $fields[$i] = $f;
        }

        return $fields;
    }

    private function next_glib_suffix(): int
    {
        $opt = (int) get_option('pbs_next_glib_suffix', 1);
        $opt = max(1, $opt);
        update_option('pbs_next_glib_suffix', $opt + 1, false);
        return $opt;
    }

    /**
     * Ensure directory exists.
     *
     * @return array{ok:bool,errors:array<int,string>}
     */
    private function mkdir(string $dir): array
    {
        if (is_dir($dir)) {
            return ['ok' => true, 'errors' => []];
        }
        if (wp_mkdir_p($dir)) {
            return ['ok' => true, 'errors' => []];
        }
        return ['ok' => false, 'errors' => ["Impossibile creare directory: {$dir}"]];
    }

    private function write_file(string $path, string $contents): string
    {
        $ok = @file_put_contents($path, $contents);
        return $ok === false ? '' : $path;
    }

    private function render_plugin_main(string $pluginName, string $pluginSlug, string $glibNs, int $schemaId, int $schemaVersion): string
    {
        $constId = strtoupper(str_replace('-', '_', $pluginSlug));
        $pluginFileConst = "{$constId}_PLUGIN_FILE";
        $idConst = "{$constId}_ID";
        $pluginIdConst = "{$constId}_PLUGIN_ID";

        return <<<PHP
<?php
/**
 * Plugin Name: {$pluginName}
 * Description: Generated by PBS (schema_id={$schemaId}, schema_version={$schemaVersion})
 * Version: 0.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('{$pluginFileConst}', __FILE__);
define('{$idConst}', '{$pluginSlug}');
define('{$pluginIdConst}', '{$pluginSlug}');

spl_autoload_register(static function (string \$class): void {
    \$prefix = '{$glibNs}\\\\';
    if (!str_starts_with(\$class, \$prefix)) {
        return;
    }
    \$rel = substr(\$class, strlen(\$prefix));
    \$rel = str_replace('\\\\', DIRECTORY_SEPARATOR, \$rel);
    \$path = __DIR__ . '/{$glibNs}/' . \$rel . '.php';
    if (is_file(\$path)) {
        require_once \$path;
    }
});

add_action('plugins_loaded', static function (): void {
    if (class_exists(\\{$glibNs}\\Init::class)) {
        \\{$glibNs}\\Init::register_services();
    }
});

PHP;
    }

    private function render_init(string $glibNs, string $groupNs, string $serviceNs, string $serviceClass): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$glibNs};

use {$glibNs}\\Api\\Services\\CrossDomain\\LibraryFrontEnd;
use {$glibNs}\\Api\\Services\\CrossDomain\\LibraryBackEnd;
use {$glibNs}\\Api\\Services\\{$groupNs}\\{$serviceNs}\\Admin{$serviceClass};

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
            Admin{$serviceClass}::class,
        ];
    }

    public static function register_services(): void
    {
        foreach (self::get_services() as \$class) {
            if (class_exists(\$class) && method_exists(\$class, 'instance')) {
                \$class::instance();
            }
        }
    }
}

PHP;
    }

    private function render_settings_api(string $glibNs): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$glibNs}\\Api;

/**
 * SettingsApi (light) — registra menu e (opzionalmente) settings/fields.
 *
 * Nota: in questa prima versione viene usata principalmente per la gestione menu.
 */
final class SettingsApi
{
    private static ?self \$instance = null;

    /** @var array<int,array<string,mixed>> */
    public array \$admin_pages = [];
    /** @var array<int,array<string,mixed>> */
    public array \$admin_subpages = [];

    /** @var array<int,array<string,mixed>> */
    public array \$settings = [];
    /** @var array<int,array<string,mixed>> */
    public array \$sections = [];
    /** @var array<int,array<string,mixed>> */
    public array \$fields = [];

    public static function instance(): self
    {
        if (!self::\$instance instanceof self) {
            self::\$instance = new self();
        }
        return self::\$instance;
    }

    public function register(): void
    {
        self::\$instance = \$this;

        if (!empty(\$this->admin_pages) || !empty(\$this->admin_subpages)) {
            add_action('admin_menu', [\$this, 'addAdminMenu']);
        }
        if (!empty(\$this->settings)) {
            add_action('admin_init', [\$this, 'registerCustomFields']);
        }
    }

    /**
     * @param array<int,array<string,mixed>> \$pages
     */
    public function addPages(array \$pages): self
    {
        \$this->admin_pages = \$pages;
        return \$this;
    }

    public function whithSubPage(string \$title = ''): self
    {
        if (empty(\$this->admin_pages)) {
            return \$this;
        }

        \$admin_page = array_values(\$this->admin_pages)[0];
        \$this->admin_subpages = [
            [
                'parent_slug' => \$admin_page['menu_slug'],
                'page_title' => \$admin_page['page_title'],
                'menu_title' => (\$title !== '') ? \$title : \$admin_page['menu_title'],
                'capability' => \$admin_page['capability'],
                'menu_slug' => \$admin_page['menu_slug'],
                'callback' => \$admin_page['callback'],
            ],
        ];

        return \$this;
    }

    /**
     * @param array<int,array<string,mixed>> \$pages
     */
    public function addSubPages(array \$pages): self
    {
        \$this->admin_subpages = array_merge(\$this->admin_subpages, \$pages);
        return \$this;
    }

    public function addAdminMenu(): void
    {
        foreach (\$this->admin_pages as \$page) {
            if (empty(\$page)) {
                continue;
            }
            add_menu_page(
                (string) \$page['page_title'],
                (string) \$page['menu_title'],
                (string) \$page['capability'],
                (string) \$page['menu_slug'],
                \$page['callback'],
                (string) (\$page['icon_url'] ?? ''),
                (int) (\$page['position'] ?? 58)
            );
        }

        foreach (\$this->admin_subpages as \$page) {
            if (empty(\$page)) {
                continue;
            }
            add_submenu_page(
                (string) \$page['parent_slug'],
                (string) \$page['page_title'],
                (string) \$page['menu_title'],
                (string) \$page['capability'],
                (string) \$page['menu_slug'],
                \$page['callback']
            );
        }
    }

    /**
     * @param array<int,array<string,mixed>> \$settings
     */
    public function setSettings(array \$settings): self
    {
        \$this->settings = \$settings;
        return \$this;
    }

    /**
     * @param array<int,array<string,mixed>> \$sections
     */
    public function setSections(array \$sections): self
    {
        \$this->sections = \$sections;
        return \$this;
    }

    /**
     * @param array<int,array<string,mixed>> \$fields
     */
    public function setFields(array \$fields): self
    {
        \$this->fields = \$fields;
        return \$this;
    }

    public function registerCustomFields(): void
    {
        foreach (\$this->settings as \$setting) {
            register_setting(
                (string) \$setting['option_group'],
                (string) \$setting['option_name'],
                \$setting['callback'] ?? static function (): void {}
            );
        }
        foreach (\$this->sections as \$section) {
            add_settings_section(
                (string) \$section['id'],
                (string) \$section['title'],
                \$section['callback'] ?? static function (): void {},
                (string) \$section['page']
            );
        }
        foreach (\$this->fields as \$field) {
            add_settings_field(
                (string) \$field['id'],
                (string) \$field['title'],
                \$field['callback'] ?? static function (): void {},
                (string) \$field['page'],
                (string) (\$field['section'] ?? \$field['page']),
                \$field['args'] ?? []
            );
        }
    }
}

PHP;
    }

    private function render_base_controller(string $glibNs, string $pluginSlug): string
    {
        $pluginIdCode = var_export($pluginSlug, true);
        $prefixCode = var_export(sanitize_key($pluginSlug) . '_', true);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$glibNs}\\Supports\\Components\\Admin;

/**
 * BaseController (light) — sanitizzazione IN/OUT centralizzata.
 *
 * Policy: tutti i flussi devono rispettare il mapping `match_db_inp_type`.
 */
abstract class BaseController
{
    public const PLUGIN_ID = {$pluginIdCode};
    public const PREFIX = {$prefixCode};

    /**
     * Pattern LSA: elenco "service managers" (stub).
     * In questa versione leggera può restare vuoto, ma mantiene compatibilità di struttura.
     *
     * @var array<int,string>
     */
    public array \$srv_managers = [];

    /**
     * @param array<string,array<string,mixed>> \$mapping
     * @param array<string,mixed> \$raw
     * @return array<string,mixed>
     */
    public function sanitize_payload(array \$mapping, array \$raw): array
    {
        \$out = [];

        foreach (\$mapping as \$db => \$meta) {
            \$type = (string) (\$meta['type'] ?? 'text');
            \$val = \$raw[\$db] ?? null;

            // Supporto gruppi (array_nested) generati da PBS: salva come JSON su una singola colonna.
            \$groupMembers = (array) (\$meta['flags']['group']['members'] ?? []);
            if (!empty(\$groupMembers)) {
                if (is_string(\$val)) {
                    \$decoded = json_decode(\$val, true);
                    \$val = is_array(\$decoded) ? \$decoded : [];
                }
                if (!is_array(\$val)) {
                    \$val = [];
                }

                \$groupOut = [];
                foreach (\$groupMembers as \$m) {
                    if (!is_array(\$m)) {
                        continue;
                    }
                    \$k = (string) (\$m['key'] ?? '');
                    if (\$k === '') {
                        continue;
                    }
                    \$mt = (string) (\$m['field_type'] ?? 'text');
                    \$groupOut[\$k] = \$this->sanitize_value(\$mt, \$val[\$k] ?? null);
                }

                \$out[\$db] = wp_json_encode(\$groupOut);
                continue;
            }

            \$out[\$db] = \$this->sanitize_value(\$type, \$val);
        }

        return \$out;
    }

    /**
     * Decode JSON groups (array_nested) back to arrays for consumption (BE/FE).
     *
     * @param array<string,array<string,mixed>> \$mapping
     * @param array<string,mixed> \$row
     * @return array<string,mixed>
     */
    public function decode_payload(array \$mapping, array \$row): array
    {
        foreach (\$mapping as \$db => \$meta) {
            \$groupMembers = (array) (\$meta['flags']['group']['members'] ?? []);
            \$isGroup = !empty(\$groupMembers) || ((string) (\$meta['type'] ?? '') === 'array_nested');
            if (!\$isGroup) {
                continue;
            }
            if (!isset(\$row[\$db]) || !is_string(\$row[\$db])) {
                continue;
            }
            \$decoded = json_decode((string) \$row[\$db], true);
            if (is_array(\$decoded)) {
                \$row[\$db] = \$decoded;
            }
        }
        return \$row;
    }

    /**
     * @param mixed \$value
     * @return mixed
     */
    protected function sanitize_value(string \$type, \$value)
    {
        if (is_array(\$value)) {
            return wp_json_encode(\$value);
        }

        \$value = (string) (\$value ?? '');

        return match (\$type) {
            'url' => esc_url_raw(\$value),
            'email' => sanitize_email(\$value),
            'html' => wp_kses_post(\$value),
            'text_area' => sanitize_textarea_field(\$value),
            'int' => (int) \$value,
            'float' => (float) \$value,
            'bool' => (\$value === '1' || strtolower(\$value) === 'true') ? 1 : 0,
            default => sanitize_text_field(\$value),
        };
    }
}

PHP;
    }

    private function render_admin_callbacks(string $glibNs): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$glibNs}\\Api\\Callbacks;

/**
 * AdminCallbacks (light) — renderer field per Settings API.
 *
 * I valori vengono passati tramite `set_values()` dal servizio Admin.
 */
final class AdminCallbacks
{
    /** @var array<string,mixed> */
    private array \$values = [];

    /**
     * @param array<string,mixed> \$values
     */
    public function set_values(array \$values): void
    {
        \$this->values = \$values;
    }

    /**
     * Callback Settings API.
     *
     * args:
     * - db_column (string)
     * - type (string)
     * - label (string)
     */
    public function inputField(array \$args): void
    {
        \$db = (string) (\$args['db_column'] ?? '');
        \$groupDb = (string) (\$args['group_db'] ?? '');
        \$memberKey = (string) (\$args['member_key'] ?? '');
        \$groupKind = (string) (\$args['group_kind'] ?? '');

        if (\$groupDb !== '' && \$memberKey !== '') {
            \$type = (string) (\$args['type'] ?? 'text');
            \$val = '';
            if (isset(\$this->values[\$groupDb]) && is_array(\$this->values[\$groupDb]) && isset(\$this->values[\$groupDb][\$memberKey])) {
                \$val = (string) \$this->values[\$groupDb][\$memberKey];
            }
            \$name = 'fields[' . \$groupDb . '][' . \$memberKey . ']';
            \$id = 'pbs_' . sanitize_key(\$groupDb . '_' . \$memberKey);

            if (\$type === 'bool') {
                echo '<input type="hidden" name="' . esc_attr(\$name) . '" value="0"/>';
                echo '<label><input type="checkbox" id="' . esc_attr(\$id) . '" name="' . esc_attr(\$name) . '" value="1" ' . (!empty(\$val) && \$val !== '0' ? 'checked' : '') . '/> </label>';
                return;
            }

            if (\$type === 'html') {
                if (function_exists('wp_editor')) {
                    wp_editor(\$val, \$id, [
                        'textarea_name' => \$name,
                        'textarea_rows' => 8,
                        'media_buttons' => false,
                    ]);
                    return;
                }
                echo '<textarea class="large-text" rows="8" name="' . esc_attr(\$name) . '">' . esc_textarea(\$val) . '</textarea>';
                return;
            }

            if (\$type === 'text_area') {
                echo '<textarea class="large-text" rows="5" name="' . esc_attr(\$name) . '">' . esc_textarea(\$val) . '</textarea>';
                return;
            }

            \$htmlType = match (\$type) {
                'url' => 'url',
                'email' => 'email',
                default => 'text',
            };

            echo '<input class="regular-text" type="' . esc_attr(\$htmlType) . '" name="' . esc_attr(\$name) . '" value="' . esc_attr(\$val) . '"/>';
            return;
        }

        if (\$db === '') {
            return;
        }
        \$type = (string) (\$args['type'] ?? 'text');
        \$val = isset(\$this->values[\$db]) ? (string) \$this->values[\$db] : '';
        \$name = 'fields[' . \$db . ']';

        if (\$type === 'bool') {
            \$id = 'pbs_' . sanitize_key(\$db);
            echo '<input type="hidden" name="' . esc_attr(\$name) . '" value="0"/>';
            echo '<label><input type="checkbox" id="' . esc_attr(\$id) . '" name="' . esc_attr(\$name) . '" value="1" ' . (!empty(\$val) && \$val !== '0' ? 'checked' : '') . '/> </label>';
            return;
        }

        if (\$type === 'html') {
            if (function_exists('wp_editor')) {
                wp_editor(\$val, 'pbs_' . sanitize_key(\$db), [
                    'textarea_name' => \$name,
                    'textarea_rows' => 8,
                    'media_buttons' => false,
                ]);
                return;
            }
            echo '<textarea class="large-text" rows="8" name="' . esc_attr(\$name) . '">' . esc_textarea(\$val) . '</textarea>';
            return;
        }

        if (\$type === 'text_area') {
            echo '<textarea class="large-text" rows="5" name="' . esc_attr(\$name) . '">' . esc_textarea(\$val) . '</textarea>';
            return;
        }

        \$htmlType = match (\$type) {
            'url' => 'url',
            'email' => 'email',
            default => 'text',
        };

        echo '<input class="regular-text" type="' . esc_attr(\$htmlType) . '" name="' . esc_attr(\$name) . '" value="' . esc_attr(\$val) . '"/>';
    }

    /**
     * Render preview for complex components (groups).
     *
     * args:
     * - group_db
     * - group_kind
     * - group_label
     */
    public function componentPreviewField(array \$args): void
    {
        \$groupDb = (string) (\$args['group_db'] ?? '');
        \$groupKind = (string) (\$args['group_kind'] ?? '');
        if (\$groupDb === '' || \$groupKind === '') {
            return;
        }

        if (\$groupKind !== 'button') {
            return;
        }

        \$vals = [];
        if (isset(\$this->values[\$groupDb]) && is_array(\$this->values[\$groupDb])) {
            \$vals = (array) \$this->values[\$groupDb];
        }

        \$title = (string) (\$vals['title'] ?? '');
        \$link = (string) (\$vals['link'] ?? '');
        \$targetBlank = !empty(\$vals['target_blank']) && (string) \$vals['target_blank'] !== '0';
        \$hiddenText = !empty(\$vals['hidden_text']) && (string) \$vals['hidden_text'] !== '0';
        \$icon = (string) (\$vals['icon'] ?? '');

        \$previewId = 'pbs_preview_' . sanitize_key(\$groupDb);
        \$btnId = \$previewId . '_btn';
        \$iconId = \$previewId . '_icon';

        \$href = \$link !== '' ? \$link : '#';
        \$tgt = \$targetBlank ? ' target=\"_blank\" rel=\"noopener\"' : '';
        \$label = \$hiddenText ? '' : (\$title !== '' ? \$title : 'Button');

        echo '<div id="' . esc_attr(\$previewId) . '" style="padding:10px 12px; background:#fff; border:1px solid #ccd0d4; border-radius:4px;">';
        echo '<div style="margin-bottom:8px;"><strong>Anteprima bottone</strong></div>';
        echo '<a id="' . esc_attr(\$btnId) . '" class="button button-primary" href="' . esc_url(\$href) . '"' . \$tgt . '>';
        echo '<span id="' . esc_attr(\$iconId) . '" style="vertical-align:middle; margin-right:6px;">';
        if (\$icon !== '') {
            if (str_starts_with(\$icon, 'dashicons-')) {
                echo '<span class=\"dashicons ' . esc_attr(\$icon) . '\"></span>';
            } else {
                echo esc_html(\$icon);
            }
        }
        echo '</span>';
        echo '<span class="pbs-btn-label">' . esc_html(\$label) . '</span>';
        echo '</a>';
        echo '<div class="description" style="margin-top:8px;">Questo preview è solo UI BE: i dati reali sono salvati nel payload/DB secondo lo schema PBS.</div>';
        echo '</div>';

        // Live preview JS (best-effort).
        \$idTitle = 'pbs_' . sanitize_key(\$groupDb . '_title');
        \$idLink = 'pbs_' . sanitize_key(\$groupDb . '_link');
        \$idTarget = 'pbs_' . sanitize_key(\$groupDb . '_target_blank');
        \$idHidden = 'pbs_' . sanitize_key(\$groupDb . '_hidden_text');
        \$idIcon = 'pbs_' . sanitize_key(\$groupDb . '_icon');

        echo '<script>(function(){';
        echo 'var btn=document.getElementById(' . wp_json_encode(\$btnId) . ');';
        echo 'if(!btn){return;}';
        echo 'var iconWrap=document.getElementById(' . wp_json_encode(\$iconId) . ');';
        echo 'var titleEl=document.getElementById(' . wp_json_encode(\$idTitle) . ');';
        echo 'var linkEl=document.getElementById(' . wp_json_encode(\$idLink) . ');';
        echo 'var targetEl=document.getElementById(' . wp_json_encode(\$idTarget) . ');';
        echo 'var hiddenEl=document.getElementById(' . wp_json_encode(\$idHidden) . ');';
        echo 'var iconEl=document.getElementById(' . wp_json_encode(\$idIcon) . ');';
        echo 'function val(el){return el?el.value:\"\";}';
        echo 'function checked(el){return !!(el && el.checked);}';
        echo 'function setLabel(txt){var s=btn.querySelector(\".pbs-btn-label\"); if(s){s.textContent=txt;}}';
        echo 'function renderIcon(raw){ if(!iconWrap){return;} raw=(raw||\"\").trim(); if(!raw){iconWrap.innerHTML=\"\"; return;}';
        echo 'if(raw.indexOf(\"dashicons-\")===0){iconWrap.innerHTML=\"<span class=\\\"dashicons \"+raw+\"\\\"></span>\";} else {iconWrap.textContent=raw;} }';
        echo 'function update(){var t=val(titleEl); var u=val(linkEl); var tb=checked(targetEl); var ht=checked(hiddenEl);';
        echo 'btn.href = u?u:\"#\"; if(tb){btn.setAttribute(\"target\",\"_blank\"); btn.setAttribute(\"rel\",\"noopener\");} else {btn.removeAttribute(\"target\"); btn.removeAttribute(\"rel\");}';
        echo 'setLabel(ht?\"\":(t||\"Button\")); renderIcon(val(iconEl)); }';
        echo 'var els=[titleEl,linkEl,targetEl,hiddenEl,iconEl]; els.forEach(function(e){ if(!e){return;} e.addEventListener(\"input\",update); e.addEventListener(\"change\",update);});';
        echo 'update();';
        echo '})();</script>';
    }
}

PHP;
    }

    private function render_be_tab_template(string $pluginSlug, string $pluginName, string $serviceSlug, string $serviceMenuLabel): string
    {
        $serviceKey = sanitize_key($serviceSlug);
        $svcPageSlug = $pluginSlug . '-' . ($serviceKey !== '' ? $serviceKey : 'service');
        $actionPrefix = sanitize_key($pluginSlug . '_' . strtolower($serviceKey !== '' ? $serviceKey : 'service'));
        if ($actionPrefix === '') {
            $actionPrefix = 'pbs_service';
        }
        $actionSave = $actionPrefix . '_save';
        $actionDelete = $actionPrefix . '_delete';

        $pluginNameCode = var_export($pluginName, true);
        $serviceMenuLabelCode = var_export($serviceMenuLabel, true);
        $svcPageSlugCode = var_export($svcPageSlug, true);
        $actionSaveCode = var_export($actionSave, true);
        $actionDeleteCode = var_export($actionDelete, true);

        return <<<PHP
<?php

/**
 * Template BE generato da PBS.
 *
 * Variabili attese:
 * - \$service (Admin service instance)
 * - \$tab (string)
 * - \$editing (bool)
 * - \$record_id (int)
 * - \$records (array)
 */

if (!defined('ABSPATH')) {
    exit;
}

settings_errors();

\$base_url = admin_url('admin.php?page=' . {$svcPageSlugCode});
?>
<div class="wrap">
    <h1><?php echo esc_html({$serviceMenuLabelCode}); ?></h1>

    <?php
    // Notice from callbacks (transient)
    \$uid = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    if (!empty(\$notice_prefix) && \$uid > 0) {
        \$n = get_transient((string) \$notice_prefix . '_notice_' . \$uid);
        if (is_array(\$n) && isset(\$n['ok'], \$n['message'])) {
            delete_transient((string) \$notice_prefix . '_notice_' . \$uid);
            \$cls = !empty(\$n['ok']) ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr(\$cls) . '"><p><strong>' . esc_html((string) \$n['message']) . '</strong></p></div>';
        }
    }
    ?>

    <h2 class="nav-tab-wrapper" style="margin-top:16px;">
        <?php
        \$tabs = [
            'list' => 'List',
            'edit' => \$editing ? 'Edit' : 'New',
            'help' => 'Help',
        ];
        foreach (\$tabs as \$k => \$label) {
            \$u = add_query_arg(['page' => {$svcPageSlugCode}, 'tab' => \$k], admin_url('admin.php'));
            \$active = (\$tab === \$k) ? ' nav-tab-active' : '';
            echo '<a class="nav-tab' . esc_attr(\$active) . '" href="' . esc_url(\$u) . '">' . esc_html(\$label) . '</a>';
        }
        ?>
    </h2>

    <?php if (\$tab === 'help'): ?>
        <h2>Help</h2>
        <p><strong>Policy:</strong> nessun delta automatico DB/DDL in runtime. Allineamento manuale.</p>
        <h3>DDL (SQL)</h3>
        <pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:1200px;overflow:auto;"><?php echo esc_html(\$service->get_create_table_sql()); ?></pre>
        <h3>Mapping</h3>
        <pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:1200px;overflow:auto;"><?php echo esc_html(var_export(\$service->match_db_inp_type, true)); ?></pre>
    <?php elseif (\$tab === 'list'): ?>
        <p><a class="button button-primary" href="<?php echo esc_url(add_query_arg(['tab' => 'edit'], \$base_url)); ?>">New</a></p>
        <table class="widefat fixed striped" style="max-width:1200px;">
            <thead><tr><th style="width:80px;">ID</th><th>Data</th><th style="width:220px;">Azioni</th></tr></thead>
            <tbody>
            <?php if (empty(\$records)): ?>
                <tr><td colspan="3"><em>Nessun record.</em></td></tr>
            <?php else: ?>
                <?php foreach (\$records as \$r): ?>
                    <?php \$id = (int) (\$r['id'] ?? 0); ?>
                    <?php \$date = (string) (\$r['updated_at'] ?? \$r['created_at'] ?? ''); ?>
                    <?php \$edit_url = add_query_arg(['tab' => 'edit', 'record_id' => \$id], \$base_url); ?>
                    <tr>
                        <td><?php echo esc_html((string) \$id); ?></td>
                        <td><?php echo esc_html(\$date); ?></td>
                        <td>
                            <a class="button" href="<?php echo esc_url(\$edit_url); ?>">Edit</a>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                <input type="hidden" name="action" value="<?php echo esc_attr({$actionDeleteCode}); ?>"/>
                                <input type="hidden" name="record_id" value="<?php echo esc_attr((string) \$id); ?>"/>
                                <?php wp_nonce_field({$actionDeleteCode}); ?>
                                <button class="button button-secondary" type="submit" onclick="return confirm('Delete record #<?php echo esc_js((string) \$id); ?>?');">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    <?php else: ?>
        <h2><?php echo esc_html(\$editing ? 'Edit record' : 'New record'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:1200px;">
            <input type="hidden" name="action" value="<?php echo esc_attr({$actionSaveCode}); ?>"/>
            <input type="hidden" name="record_id" value="<?php echo esc_attr((string) \$record_id); ?>"/>
            <?php wp_nonce_field({$actionSaveCode}); ?>

            <table class="form-table" role="presentation">
                <?php do_settings_sections({$svcPageSlugCode}); ?>
            </table>

            <?php submit_button('Save'); ?>
        </form>
    <?php endif; ?>
</div>

PHP;
    }

    private function render_interface(string $glibNs, string $pluginSlug): string
    {
        $prefix = strtoupper(str_replace('-', '_', $pluginSlug));
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$glibNs}\\Api\\Interfaces;

/**
 * Interfaccia API FE “leggera” (da estendere).
 *
 * Nota: questo plugin è indipendente e usa namespace {$glibNs}.
 * Policy: nessun delta automatico DB/DDL in runtime (FE/BE).
 */
interface iAPI
{
    public const PLUGIN_PREFIX = '{$prefix}';
}

PHP;
    }

    private function render_library_fe(string $glibNs, string $groupNs, string $serviceNs, string $serviceClass, string $serviceSlug): string
    {
        $fnSlug = sanitize_key($serviceSlug);
        if ($fnSlug === '') {
            $fnSlug = 'service';
        }
        $methList = 'get_' . $fnSlug . '_list';
        $methGet = 'get_' . $fnSlug . '_record';

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$glibNs}\\Api\\Services\\CrossDomain;

use {$glibNs}\\Api\\Interfaces\\iAPI;
use {$glibNs}\\Api\\Services\\{$groupNs}\\{$serviceNs}\\Base{$serviceClass};

final class LibraryFrontEnd implements iAPI
{
    private static ?self \$instance = null;

    public static function instance(): self
    {
        if (!self::\$instance instanceof self) {
            self::\$instance = new self();
        }
        return self::\$instance;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function {$methList}(int \$limit = 100): array
    {
        \$base = new Base{$serviceClass}();
        return \$base->list_records(\$limit);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function {$methGet}(int \$id): ?array
    {
        \$base = new Base{$serviceClass}();
        return \$base->get_record(\$id);
    }
}

PHP;
    }

    private function render_library_be(string $glibNs, string $groupNs, string $serviceNs, string $serviceClass, string $serviceSlug): string
    {
        $fnSlug = sanitize_key($serviceSlug);
        if ($fnSlug === '') {
            $fnSlug = 'service';
        }
        $methList = 'get_' . $fnSlug . '_list';
        $methGet = 'get_' . $fnSlug . '_record';

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$glibNs}\\Api\\Services\\CrossDomain;

use {$glibNs}\\Api\\Interfaces\\iAPI;
use {$glibNs}\\Api\\Services\\{$groupNs}\\{$serviceNs}\\Base{$serviceClass};

final class LibraryBackEnd implements iAPI
{
    private static ?self \$instance = null;

    public static function instance(): self
    {
        if (!self::\$instance instanceof self) {
            self::\$instance = new self();
        }
        return self::\$instance;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function {$methList}(int \$limit = 100): array
    {
        \$base = new Base{$serviceClass}();
        return \$base->list_records(\$limit);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function {$methGet}(int \$id): ?array
    {
        \$base = new Base{$serviceClass}();
        return \$base->get_record(\$id);
    }
}

PHP;
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     */
    private function render_base_service(
        string $glibNs,
        string $groupNs,
        string $serviceNs,
        string $serviceClass,
        string $pluginSlug,
        string $serviceSlug,
        array $fields
    ): string {
        $mappingLines = [];
        $columnDefs = [];

        $seen = [
            'id' => true,
            'created_at' => true,
            'updated_at' => true,
        ];

        foreach ($fields as $f) {
            $db = (string) $f['db_column'];
            $name = (string) $f['input_name'];
            $type = (string) $f['field_type'];
            $label = (string) $f['label'];
            $flags = json_decode((string) ($f['flags'] ?? ''), true) ?: [];

            if ($db === '' || isset($seen[$db])) {
                continue;
            }
            $seen[$db] = true;

            $flagsCode = var_export($flags, true);
            $mappingLines[] = "            '{$db}' => ['name' => '{$name}', 'type' => '{$type}', 'label' => " . var_export($label, true) . ", 'flags' => {$flagsCode}],";

            $sqlType = match ($type) {
                'url' => 'VARCHAR(2048)',
                'email' => 'VARCHAR(255)',
                'html', 'text_area' => 'LONGTEXT',
                'int' => 'BIGINT',
                'float' => 'DOUBLE',
                'bool' => 'TINYINT(1)',
                default => 'VARCHAR(255)',
            };
            if (is_array($flags) && !empty($flags['group'])) {
                $sqlType = 'LONGTEXT';
            }
            $columnDefs[] = "        `{$db}` {$sqlType} NULL";
        }

        $mapping = implode("\n", $mappingLines);
        $columnsSql = implode(",\n", $columnDefs);

        // Table keys: evitare '-' nel nome tabella MySQL.
        $tableKey = sanitize_key($pluginSlug . '_' . $serviceSlug);
        $tableKey = str_replace('-', '_', $tableKey);
        if ($tableKey === '') {
            $tableKey = 'pbs_service';
        }
        $tableKeyCode = var_export($tableKey, true);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$glibNs}\\Api\\Services\\{$groupNs}\\{$serviceNs};

use {$glibNs}\\Supports\\Components\\Admin\\BaseController;

/**
 * Base{$serviceClass} generata da PBS.
 *
 * - DDL/DLL: esposta come SQL (Help tab). Nessun allineamento automatico.
 * - CRUD: minimale per iniziare (da evolvere secondo standard gLib).
 */
class Base{$serviceClass} extends BaseController
{
    public const TABLE_KEY = {$tableKeyCode};

    public string \$table = '';

    /**
     * Mapping (db_column -> name/type/label/flags).
     * Questo mapping deriva dallo schema PBS (import ACF o manuale).
     */
    public array \$match_db_inp_type = [
{$mapping}
    ];

    public function __construct()
    {
        global \$wpdb;
        \$this->table = \$wpdb->prefix . self::TABLE_KEY;
    }

    public function ensure_table_exists(string &\$err = ''): bool
    {
        global \$wpdb;
        \$err = '';

        // Quick check
        \$found = \$wpdb->get_var(\$wpdb->prepare('SHOW TABLES LIKE %s', \$this->table));
        if (\$found === \$this->table) {
            return true;
        }

        if (!function_exists('maybe_create_table')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        \$ddl = \$this->get_create_table_sql();
        \$ok = maybe_create_table(\$this->table, \$ddl);
        if (!\$ok) {
            // maybe_create_table a volte non lascia last_error valorizzato: riprova una query diretta per ottenere dettaglio.
            if (\$wpdb->last_error === '') {
                \$wpdb->query(\$ddl);
            }
            \$err = \$wpdb->last_error !== '' ? \$wpdb->last_error : ('Impossibile creare la tabella. SQL: ' . \$ddl);
            return false;
        }

        return true;
    }

    public function get_create_table_sql(): string
    {
        global \$wpdb;
        \$charset = \$wpdb->get_charset_collate();

        return "CREATE TABLE " . \$this->table . " (\n" .
            "        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
            {$this->php_string($columnsSql !== '' ? $columnsSql . ",\n" : '')} .
            "        `created_at` DATETIME NULL,\n" .
            "        `updated_at` DATETIME NULL,\n" .
            "        PRIMARY KEY (`id`)\n" .
            ") " . \$charset . ";";
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function list_records(int \$limit = 100): array
    {
        global \$wpdb;
        \$e = '';
        if (!\$this->ensure_table_exists(\$e)) {
            return [];
        }
        \$limit = max(1, min(500, \$limit));
        \$sql = "SELECT * FROM {\$this->table} ORDER BY id DESC LIMIT " . (int) \$limit;
        \$rows = (array) \$wpdb->get_results(\$sql, ARRAY_A);
        foreach (\$rows as \$i => \$r) {
            if (is_array(\$r)) {
                \$rows[\$i] = \$this->decode_payload(\$this->match_db_inp_type, \$r);
            }
        }
        return \$rows;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get_record(int \$id): ?array
    {
        global \$wpdb;
        \$e = '';
        if (!\$this->ensure_table_exists(\$e)) {
            return null;
        }
        if (\$id <= 0) {
            return null;
        }
        \$row = \$wpdb->get_row(\$wpdb->prepare("SELECT * FROM {\$this->table} WHERE id=%d", \$id), ARRAY_A);
        if (!is_array(\$row)) {
            return null;
        }
        return \$this->decode_payload(\$this->match_db_inp_type, \$row);
    }

    public function delete_record(int \$id, string &\$err = ''): bool
    {
        global \$wpdb;
        if (!\$this->ensure_table_exists(\$err)) {
            return false;
        }
        if (\$id <= 0) {
            \$err = 'ID non valido.';
            return false;
        }
        \$ok = \$wpdb->delete(\$this->table, ['id' => \$id], ['%d']);
        if (\$ok === false) {
            \$err = \$wpdb->last_error !== '' ? \$wpdb->last_error : 'Delete fallita.';
            return false;
        }
        return true;
    }

    /**
     * @param array<string,mixed> \$raw
     */
    public function save_record(int \$id, array \$raw, string &\$err = ''): int
    {
        global \$wpdb;
        if (!\$this->ensure_table_exists(\$err)) {
            return 0;
        }
        \$data = \$this->sanitize_payload(\$this->match_db_inp_type, \$raw);

        // timestamps
        \$now = current_time('mysql');
        if (\$id > 0) {
            \$data['updated_at'] = \$now;
            \$formats = \$this->infer_formats(\$data);
            \$ok = \$wpdb->update(\$this->table, \$data, ['id' => \$id], \$formats, ['%d']);
            if (\$ok === false) {
                \$err = \$wpdb->last_error !== '' ? \$wpdb->last_error : 'Update fallita.';
                return 0;
            }
            return \$id;
        }

        \$data['created_at'] = \$now;
        \$formats = \$this->infer_formats(\$data);
        \$ok = \$wpdb->insert(\$this->table, \$data, \$formats);
        if (!\$ok) {
            \$err = \$wpdb->last_error !== '' ? \$wpdb->last_error : 'Insert fallita.';
            return 0;
        }
        return (int) \$wpdb->insert_id;
    }

    /**
     * @param array<string,mixed> \$data
     * @return array<int,string>
     */
    protected function infer_formats(array \$data): array
    {
        \$formats = [];
        foreach (array_keys(\$data) as \$k) {
            \$formats[] = \$k === 'id' ? '%d' : '%s';
        }
        return \$formats;
    }
}

PHP;
    }

    private function render_admin_service(
        string $glibNs,
        string $groupNs,
        string $serviceNs,
        string $serviceClass,
        string $pluginSlug,
        string $pluginName,
        string $serviceMenuLabel,
        string $serviceSlug,
        string $pluginIcon
    ): string {
        $menuSlug = $pluginSlug;
        $serviceKey = sanitize_key($serviceSlug);
        if ($serviceKey === '') {
            $serviceKey = sanitize_key($serviceNs);
        }
        if ($serviceKey === '') {
            $serviceKey = 'service';
        }

        $svcPageSlug = $pluginSlug . '-' . $serviceKey;
        $actionPrefix = sanitize_key($pluginSlug . '_' . $serviceKey);
        if ($actionPrefix === '') {
            $actionPrefix = 'pbs_service';
        }
        $actionSave = $actionPrefix . '_save';
        $actionDelete = $actionPrefix . '_delete';
        $pluginNameCode = var_export($pluginName, true);
        $serviceMenuLabelCode = var_export($serviceMenuLabel, true);
        $menuSlugCode = var_export($menuSlug, true);
        $svcPageSlugCode = var_export($svcPageSlug, true);
        $serviceNsCode = var_export($serviceNs, true);
        $serviceKeyCode = var_export($serviceKey, true);
        $actionSaveCode = var_export($actionSave, true);
        $actionDeleteCode = var_export($actionDelete, true);
        $pluginIconCode = var_export($pluginIcon, true);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$glibNs}\\Api\\Services\\{$groupNs}\\{$serviceNs};

use {$glibNs}\\Api\\Callbacks\\AdminCallbacks;
use {$glibNs}\\Api\\SettingsApi;

/**
 * Admin{$serviceClass} generata da PBS (placeholder).
 *
 * Template standard “leggero”:
 * - TAB List: tabellina record (Edit/Delete)
 * - TAB Edit: New/Edit record
 * - TAB Help: info + SQL (DDL) + mapping
 */
final class Admin{$serviceClass} extends Base{$serviceClass}
{
    private static ?self \$instance = null;

    private SettingsApi \$settings_api;
    private {$serviceClass}Callbacks \$callbacks;
    private AdminCallbacks \$adminCallbacks;

    public static function instance(): self
    {
        if (!self::\$instance instanceof self) {
            self::\$instance = new self();
        }
        return self::\$instance;
    }

    private function __construct()
    {
        parent::__construct();

        if (!is_admin()) {
            return;
        }

        \$this->settings_api = new SettingsApi();
        \$this->callbacks = new {$serviceClass}Callbacks();
        \$this->adminCallbacks = new AdminCallbacks();
        \$this->register();
    }

    private function register(): void
    {
        \$this->settings_api
            ->addPages([
                [
                    'page_title' => {$pluginNameCode},
                    'menu_title' => {$pluginNameCode},
                    'capability' => 'manage_options',
                    'menu_slug'  => {$menuSlugCode},
                    'callback'   => [\$this, 'render_plugin_page'],
                    'icon_url'   => {$pluginIconCode},
                    'position'   => 58,
                ],
            ])
            ->whithSubPage({$pluginNameCode})
            ->addSubPages([
                [
                    'parent_slug' => {$menuSlugCode},
                    'page_title'  => {$serviceMenuLabelCode},
                    'menu_title'  => {$serviceMenuLabelCode},
                    'capability'  => 'manage_options',
                    'menu_slug'   => {$svcPageSlugCode},
                    'callback'    => [\$this, 'render_service_page'],
                ],
            ])
            ;

        // Settings API (fields from schema mapping)
        \$settings = [
            [
                'option_group' => {$svcPageSlugCode} . '_group',
                'option_name' => {$svcPageSlugCode} . '_options',
            ],
        ];
        \$sections = [
            [
                'id' => {$svcPageSlugCode} . '_main',
                'title' => '',
                'callback' => [\$this->callbacks, 'sectionManager'],
                'page' => {$svcPageSlugCode},
            ],
        ];
        \$fields = [];
        foreach (\$this->match_db_inp_type as \$db => \$meta) {
            \$members = (array) (\$meta['flags']['group']['members'] ?? []);
            if (!empty(\$members)) {
                \$groupLabel = (string) (\$meta['label'] ?? \$db);
                \$groupKind = (string) (\$meta['flags']['group']['kind'] ?? '');

                // Component preview for known kinds (MVP: button)
                if (\$groupKind === 'button') {
                    \$fields[] = [
                        'id' => {$svcPageSlugCode} . '_' . sanitize_key((string) \$db . '_preview'),
                        'title' => \$groupLabel . ' — Anteprima',
                        'callback' => [\$this->adminCallbacks, 'componentPreviewField'],
                        'page' => {$svcPageSlugCode},
                        'section' => {$svcPageSlugCode} . '_main',
                        'args' => [
                            'group_db' => (string) \$db,
                            'group_kind' => (string) \$groupKind,
                            'group_label' => (string) \$groupLabel,
                        ],
                    ];
                }
                foreach (\$members as \$m) {
                    if (!is_array(\$m)) {
                        continue;
                    }
                    \$mk = (string) (\$m['key'] ?? '');
                    if (\$mk === '') {
                        continue;
                    }
                    \$ml = (string) (\$m['label'] ?? \$mk);
                    \$mt = (string) (\$m['field_type'] ?? 'text');

                    \$fields[] = [
                        'id' => {$svcPageSlugCode} . '_' . sanitize_key((string) \$db . '_' . (string) \$mk),
                        'title' => \$groupLabel . ' — ' . \$ml,
                        'callback' => [\$this->adminCallbacks, 'inputField'],
                        'page' => {$svcPageSlugCode},
                        'section' => {$svcPageSlugCode} . '_main',
                        'args' => [
                            'group_db' => (string) \$db,
                            'group_kind' => (string) \$groupKind,
                            'member_key' => (string) \$mk,
                            'type' => \$mt,
                            'label' => \$ml,
                        ],
                    ];
                }
                continue;
            }

            \$fields[] = [
                'id' => {$svcPageSlugCode} . '_' . sanitize_key((string) \$db),
                'title' => (string) (\$meta['label'] ?? \$db),
                'callback' => [\$this->adminCallbacks, 'inputField'],
                'page' => {$svcPageSlugCode},
                'section' => {$svcPageSlugCode} . '_main',
                'args' => [
                    'db_column' => (string) \$db,
                    'type' => (string) (\$meta['type'] ?? 'text'),
                    'label' => (string) (\$meta['label'] ?? \$db),
                ],
            ];
        }

        \$this->settings_api
            ->setSettings(\$settings)
            ->setSections(\$sections)
            ->setFields(\$fields)
            ->register();

        add_action('admin_post_' . {$actionSaveCode}, [\$this->callbacks, 'handle_save']);
        add_action('admin_post_' . {$actionDeleteCode}, [\$this->callbacks, 'handle_delete']);
    }

    public function render_plugin_page(): void
    {
        echo '<div class="wrap"><h1>' . esc_html({$pluginNameCode}) . '</h1>';
        echo '<p>Plugin generato da PBS.</p>';
        echo '<p>Vai al servizio: <a href=\"' . esc_url(admin_url('admin.php?page=' . {$svcPageSlugCode})) . '\">' . esc_html({$serviceMenuLabelCode}) . '</a></p>';
        echo '</div>';
    }

    public function render_service_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }

        \$tab = sanitize_key((string) (\$_GET['tab'] ?? 'list'));
        if (!in_array(\$tab, ['list', 'edit', 'help'], true)) {
            \$tab = 'list';
        }

        \$record_id = isset(\$_GET['record_id']) ? (int) \$_GET['record_id'] : 0;
        \$editing = \$tab === 'edit' && \$record_id > 0;
        \$record = \$editing ? \$this->get_record(\$record_id) : [];

        // In List tab pre-carichiamo records
        \$records = \$tab === 'list' ? \$this->list_records(100) : [];

        // Prepara valori per i renderer Settings API
        \$this->adminCallbacks->set_values(is_array(\$record) ? \$record : []);

        \$service = \$this;
        \$notice_prefix = {$this->php_string($actionPrefix)};
        \$tpl = dirname(__FILE__, 6) . '/UI/BE/templates/' . {$serviceKeyCode} . '.php';
        if (!is_file(\$tpl)) {
            echo '<div class="wrap"><h1>' . esc_html({$serviceMenuLabelCode}) . '</h1>';
            echo '<div class="notice notice-error"><p><strong>Template BE mancante:</strong> <code>' . esc_html(\$tpl) . '</code></p></div>';
            echo '</div>';
            return;
        }

        require \$tpl;
    }
}

PHP;
    }

    private function render_callbacks(string $glibNs, string $groupNs, string $serviceNs, string $serviceClass, string $pluginSlug, string $serviceSlug): string
    {
        $serviceKey = sanitize_key($serviceSlug);
        if ($serviceKey === '') {
            $serviceKey = sanitize_key($serviceNs);
        }
        if ($serviceKey === '') {
            $serviceKey = 'service';
        }

        $svcPageSlug = $pluginSlug . '-' . $serviceKey;
        $actionPrefix = sanitize_key($pluginSlug . '_' . $serviceKey);
        if ($actionPrefix === '') {
            $actionPrefix = 'pbs_service';
        }
        $actionSave = $actionPrefix . '_save';
        $actionDelete = $actionPrefix . '_delete';

        $svcPageSlugCode = var_export($svcPageSlug, true);
        $actionPrefixCode = var_export($actionPrefix, true);
        $actionSaveCode = var_export($actionSave, true);
        $actionDeleteCode = var_export($actionDelete, true);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$glibNs}\\Api\\Services\\{$groupNs}\\{$serviceNs};

/**
 * {$serviceClass}Callbacks generata da PBS.
 *
 * Handler BE (admin-post) per CRUD record.
 */
final class {$serviceClass}Callbacks extends Base{$serviceClass}
{
    public function __construct()
    {
        parent::__construct();
    }

    public function sectionManager(array \$args = []): void
    {
        // Placeholder: descrizione sezione (opzionale)
        // echo '<p>Gestione record.</p>';
    }

    public function handle_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer({$actionSaveCode});

        \$id = (int) (\$_POST['record_id'] ?? 0);
        \$raw = (array) (\$_POST['fields'] ?? []);

        \$err = '';
        \$rid = \$this->save_record(\$id, \$raw, \$err);

        \$uid = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if (\$uid > 0) {
            set_transient({$actionPrefixCode} . '_notice_' . \$uid, [
                'ok' => \$rid > 0,
                'message' => \$rid > 0 ? 'Record salvato.' : ('Errore: ' . (\$err ?: 'save fallita')),
            ], 30);
        }

        wp_safe_redirect(admin_url('admin.php?page=' . {$svcPageSlugCode} . '&tab=list'));
        exit;
    }

    public function handle_delete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer({$actionDeleteCode});

        \$id = (int) (\$_POST['record_id'] ?? 0);
        \$err = '';
        \$ok = \$this->delete_record(\$id, \$err);

        \$uid = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if (\$uid > 0) {
            set_transient({$actionPrefixCode} . '_notice_' . \$uid, [
                'ok' => \$ok,
                'message' => \$ok ? 'Record eliminato.' : ('Errore: ' . (\$err ?: 'delete fallita')),
            ], 30);
        }

        wp_safe_redirect(admin_url('admin.php?page=' . {$svcPageSlugCode} . '&tab=list'));
        exit;
    }
}

PHP;
    }

    private function normalize_service_slug(string $slug): string
    {
        $slug = sanitize_key($slug);
        $slug = str_replace('-', '_', $slug);
        $slug = trim($slug, '_');
        return $slug !== '' ? $slug : 'service';
    }

    private function service_group_ns(string $serviceGroup): string
    {
        return match ($serviceGroup) {
            'internal' => 'Internal',
            'control' => 'Control',
            default => 'Shared',
        };
    }

    private function studly(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'Service';
        }

        $value = preg_replace('/[^a-zA-Z0-9 _\\-\\.]+/', ' ', $value) ?? $value;
        $value = str_replace(['-', '_', '.'], ' ', $value);
        $value = ucwords(strtolower($value));
        $value = preg_replace('/\\s+/', '', $value) ?? $value;
        $value = preg_replace('/[^A-Za-z0-9]+/', '', $value) ?? $value;

        if ($value === '' || ctype_digit($value[0])) {
            $value = 'S' . $value;
        }
        return $value;
    }

    private function php_string(string $value): string
    {
        return var_export($value, true);
    }
}
