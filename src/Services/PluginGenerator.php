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
        $this->mkdir($baseDir . "/{$glibNamespace}/Api/Services/Internal");
        $this->mkdir($baseDir . "/{$glibNamespace}/Api/Services/{$groupNs}/{$serviceNsSegment}");
        $this->mkdir($baseDir . "/{$glibNamespace}/Supports/Components/Admin");
        $this->mkdir($baseDir . "/UI/BE/templates");

        $files[] = $this->write_file($baseDir . "/{$glibNamespace}/Init.php", $this->render_init($glibNamespace, $groupNs, $serviceNsSegment, $serviceClass));
        $files[] = $this->write_file($baseDir . "/{$glibNamespace}/Api/SettingsApi.php", $this->render_settings_api($glibNamespace));
        $files[] = $this->write_file($baseDir . "/{$glibNamespace}/Api/Callbacks/AdminCallbacks.php", $this->render_admin_callbacks($glibNamespace));
        $activationKey = 'admin_' . sanitize_key($serviceSlug);
        if ($activationKey === 'admin_') {
            $activationKey = 'admin_service';
        }
        $files[] = $this->write_file(
            $baseDir . "/{$glibNamespace}/Supports/Components/Admin/BaseController.php",
            $this->render_base_controller($glibNamespace, $pluginSlug, $activationKey, $serviceMenuLabel)
        );
        $files[] = $this->write_file(
            $baseDir . "/{$glibNamespace}/Api/Services/Internal/AdminDashboard.php",
            $this->render_admin_dashboard($glibNamespace, $pluginSlug, $pluginName, $pluginIcon)
        );
        $files[] = $this->write_file($baseDir . "/{$glibNamespace}/Api/Interfaces/iAPI.php", $this->render_interface($glibNamespace, $pluginSlug));
        $files[] = $this->write_file($baseDir . "/{$glibNamespace}/Api/Services/CrossDomain/LibraryFrontEnd.php", $this->render_library_fe($glibNamespace, $groupNs, $serviceNsSegment, $serviceClass, $serviceSlug));
        $files[] = $this->write_file($baseDir . "/{$glibNamespace}/Api/Services/CrossDomain/LibraryBackEnd.php", $this->render_library_be($glibNamespace, $groupNs, $serviceNsSegment, $serviceClass, $serviceSlug));

        $files[] = $this->write_file(
            $baseDir . "/{$glibNamespace}/Api/Services/{$groupNs}/{$serviceNsSegment}/Base{$serviceClass}.php",
            $this->render_base_service($schemaId, $glibNamespace, $groupNs, $serviceNsSegment, $serviceClass, $pluginSlug, $serviceSlug, $fields)
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
                // Back-compat: older schemas/groups may not store `kind`.
                // Infer it from db_column and/or member keys (registry intersection).
                $kind = $this->infer_group_kind_from_flags($f, $flags);
            }
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
                // Prefer original ACF field name when available (robust against corrupted `key` values).
                $rawKey = '';
                if (!empty($m['acf']) && is_array($m['acf']) && !empty($m['acf']['orig_name']) && is_string($m['acf']['orig_name'])) {
                    $rawKey = (string) $m['acf']['orig_name'];
                }
                if ($rawKey === '') {
                    $rawKey = (string) ($m['key'] ?? '');
                }
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
                // Standardize label for known component members (keeps UI stable across ACF variants).
                $components = ComponentRegistry::components();
                if (isset($components[$kind]['members'][$normKey]['label'])) {
                    $m['label'] = (string) $components[$kind]['members'][$normKey]['label'];
                } elseif (!isset($m['label']) || (string) $m['label'] === '') {
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

    /**
     * Infer group kind for legacy schemas that didn't persist flags.group.kind.
     *
     * Heuristic (MVP):
     * - try db_column / input_name if they match a known kind
     * - otherwise score intersection between member keys and registry members
     *
     * @param array<string,mixed> $fieldRow
     * @param array<string,mixed> $flags
     */
    private function infer_group_kind_from_flags(array $fieldRow, array $flags): string
    {
        $fallbacks = [];
        $db = sanitize_key((string) ($fieldRow['db_column'] ?? ''));
        $in = sanitize_key((string) ($fieldRow['input_name'] ?? ''));
        if ($db !== '') {
            $fallbacks[] = $db;
        }
        if ($in !== '' && $in !== $db) {
            $fallbacks[] = $in;
        }

        foreach ($fallbacks as $fb) {
            if (ComponentRegistry::is_known_kind($fb)) {
                return $fb;
            }
        }

        $members = (array) (($flags['group']['members'] ?? []));
        if (!$members) {
            return '';
        }

        $memberKeys = [];
        foreach ($members as $m) {
            if (!is_array($m)) {
                continue;
            }
            $k = sanitize_key((string) ($m['key'] ?? ''));
            if ($k !== '') {
                $memberKeys[$k] = true;
            }
        }
        if (!$memberKeys) {
            return '';
        }

        $bestKind = '';
        $bestScore = 0;
        foreach (ComponentRegistry::components() as $kind => $def) {
            if (!is_array($def) || empty($def['members']) || !is_array($def['members'])) {
                continue;
            }
            $score = 0;
            foreach ($memberKeys as $k => $_) {
                if (isset($def['members'][$k])) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestKind = (string) $kind;
            }
        }

        // Require at least 2 matches to avoid accidental classification.
        return $bestScore >= 2 ? sanitize_key($bestKind) : '';
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

    /**
     * Render a generator template from `templates/generator/`.
     *
     * Placeholder format: `{{VAR}}`.
     *
     * @param array<string,string> $vars
     */
    private function render_tpl(string $relPath, array $vars): string
    {
        $base = rtrim((string) (defined('PBS_PLUGIN_DIR') ? constant('PBS_PLUGIN_DIR') : ''), "/\\");
        $path = $base !== '' ? ($base . '/templates/generator/' . ltrim($relPath, '/')) : '';
        if ($path === '' || !is_file($path)) {
            return '';
        }

        $tpl = (string) @file_get_contents($path);
        if ($tpl === '') {
            return '';
        }

        $repl = [];
        foreach ($vars as $k => $v) {
            $repl['{{' . $k . '}}'] = (string) $v;
        }
        return strtr($tpl, $repl);
    }

    private function render_plugin_main(string $pluginName, string $pluginSlug, string $glibNs, int $schemaId, int $schemaVersion): string
    {
        $constId = strtoupper(str_replace('-', '_', $pluginSlug));
        $pluginFileConst = "{$constId}_PLUGIN_FILE";
        $idConst = "{$constId}_ID";
        $pluginIdConst = "{$constId}_PLUGIN_ID";

        return $this->render_tpl('plugin-main.php.tpl', [
            'PLUGIN_NAME' => $pluginName,
            'SCHEMA_ID' => (string) $schemaId,
            'SCHEMA_VERSION' => (string) $schemaVersion,
            'PLUGIN_VERSION' => '0.1.0',
            'PLUGIN_FILE_CONST' => $pluginFileConst,
            'ID_CONST' => $idConst,
            'PLUGIN_ID_CONST' => $pluginIdConst,
            'PLUGIN_SLUG_PHP' => var_export($pluginSlug, true),
            'GLIB_NS' => $glibNs,
        ]);
    }

    private function render_init(string $glibNs, string $groupNs, string $serviceNs, string $serviceClass): string
    {
        return $this->render_tpl('init.php.tpl', [
            'GLIB_NS' => $glibNs,
            'GROUP_NS' => $groupNs,
            'SERVICE_NS' => $serviceNs,
            'SERVICE_CLASS' => $serviceClass,
        ]);
    }

    private function render_settings_api(string $glibNs): string
    {
        return $this->render_tpl('settings-api.php.tpl', [
            'GLIB_NS' => $glibNs,
        ]);
    }

    private function render_base_controller(string $glibNs, string $pluginSlug, string $activationKey, string $activationLabel): string
    {
        $pluginIdCode = var_export($pluginSlug, true);
        $prefixCode = var_export(sanitize_key($pluginSlug) . '_', true);
        $srv = [
            sanitize_key($activationKey) => $activationLabel !== '' ? $activationLabel : $activationKey,
        ];
        $lines = [];
        foreach ($srv as $k => $v) {
            $lines[] = "            '" . $k . "' => " . var_export('Activate ' . $v, true) . ",";
        }
        $srvManagersLines = $lines ? implode("\n", $lines) : '';
        return $this->render_tpl('base-controller.php.tpl', [
            'GLIB_NS' => $glibNs,
            'PLUGIN_ID_CODE' => $pluginIdCode,
            'PREFIX_CODE' => $prefixCode,
            'SRV_MANAGERS_LINES' => $srvManagersLines,
        ]);
    }

    private function render_admin_dashboard(string $glibNs, string $pluginSlug, string $pluginName, string $pluginIcon): string
    {
        return $this->render_tpl('admin-dashboard.php.tpl', [
            'GLIB_NS' => $glibNs,
            'PLUGIN_NAME_CODE' => var_export($pluginName, true),
            'MENU_SLUG_CODE' => var_export($pluginSlug, true),
            'PLUGIN_ICON_CODE' => var_export(($pluginIcon !== '' ? $pluginIcon : 'dashicons-admin-generic'), true),
        ]);
    }

    private function render_admin_callbacks(string $glibNs): string
    {
        return $this->render_tpl('admin-callbacks.php.tpl', [
            'GLIB_NS' => $glibNs,
        ]);
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
        return $this->render_tpl('be-tab-template.php.tpl', [
            'SVC_PAGE_SLUG_CODE' => $svcPageSlugCode,
            'SERVICE_MENU_LABEL_CODE' => $serviceMenuLabelCode,
            'ACTION_SAVE_CODE' => $actionSaveCode,
            'ACTION_DELETE_CODE' => $actionDeleteCode,
        ]);
    }

    private function render_interface(string $glibNs, string $pluginSlug): string
    {
        $prefix = strtoupper(str_replace('-', '_', $pluginSlug));
        return $this->render_tpl('interface.php.tpl', [
            'GLIB_NS' => $glibNs,
            'PLUGIN_PREFIX_CODE' => var_export($prefix, true),
        ]);
    }

    private function render_library_fe(string $glibNs, string $groupNs, string $serviceNs, string $serviceClass, string $serviceSlug): string
    {
        $fnSlug = sanitize_key($serviceSlug);
        if ($fnSlug === '') {
            $fnSlug = 'service';
        }
        $methList = 'get_' . $fnSlug . '_list';
        $methGet = 'get_' . $fnSlug . '_record';
        return $this->render_tpl('library-fe.php.tpl', [
            'GLIB_NS' => $glibNs,
            'GROUP_NS' => $groupNs,
            'SERVICE_NS' => $serviceNs,
            'SERVICE_CLASS' => $serviceClass,
            'METH_LIST' => $methList,
            'METH_GET' => $methGet,
        ]);
    }

    private function render_library_be(string $glibNs, string $groupNs, string $serviceNs, string $serviceClass, string $serviceSlug): string
    {
        $fnSlug = sanitize_key($serviceSlug);
        if ($fnSlug === '') {
            $fnSlug = 'service';
        }
        $methList = 'get_' . $fnSlug . '_list';
        $methGet = 'get_' . $fnSlug . '_record';
        return $this->render_tpl('library-be.php.tpl', [
            'GLIB_NS' => $glibNs,
            'GROUP_NS' => $groupNs,
            'SERVICE_NS' => $serviceNs,
            'SERVICE_CLASS' => $serviceClass,
            'METH_LIST' => $methList,
            'METH_GET' => $methGet,
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     */
    private function render_base_service(
        int $schemaId,
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
        }

        $mapping = implode("\n", $mappingLines);

        // Centralized DLL: use SchemaDll effective representation (preview if initialized).
        $dll = new SchemaDll();
        $effective = $dll->get_effective($schemaId, $fields);
        $cols = (array) ($effective['columns'] ?? []);
        foreach ($cols as $c) {
            if (!is_array($c)) {
                continue;
            }
            $db = sanitize_key((string) ($c['db_column'] ?? ''));
            if ($db === '' || isset($seen[$db])) {
                continue;
            }
            $sqlType = (string) ($c['sql_type'] ?? 'LONGTEXT');
            $null = !empty($c['nullable']) ? 'NULL' : 'NOT NULL';
            $columnDefs[] = "        `{$db}` {$sqlType} {$null}";
        }

        $columnsSql = implode(",\n", $columnDefs);

        // Table keys: evitare '-' nel nome tabella MySQL.
        $tableKey = sanitize_key($pluginSlug . '_' . $serviceSlug);
        $tableKey = str_replace('-', '_', $tableKey);
        if ($tableKey === '') {
            $tableKey = 'pbs_service';
        }
        $tableKeyCode = var_export($tableKey, true);
        return $this->render_tpl('base-service.php.tpl', [
            'GLIB_NS' => $glibNs,
            'GROUP_NS' => $groupNs,
            'SERVICE_NS' => $serviceNs,
            'SERVICE_CLASS' => $serviceClass,
            'TABLE_KEY_CODE' => $tableKeyCode,
            'MAPPING_LINES' => $mapping,
            'COLUMNS_SQL_PHP' => $this->php_string($columnsSql !== '' ? $columnsSql . ",\n" : ''),
        ]);
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
        $activationKeyCode = var_export('admin_' . $serviceKey, true);
        return $this->render_tpl('admin-service.php.tpl', [
            'GLIB_NS' => $glibNs,
            'GROUP_NS' => $groupNs,
            'SERVICE_NS' => $serviceNs,
            'SERVICE_CLASS' => $serviceClass,
            'PLUGIN_NAME_CODE' => $pluginNameCode,
            'SERVICE_MENU_LABEL_CODE' => $serviceMenuLabelCode,
            'MENU_SLUG_CODE' => $menuSlugCode,
            'SVC_PAGE_SLUG_CODE' => $svcPageSlugCode,
            'SERVICE_KEY_CODE' => $serviceKeyCode,
            'ACTION_SAVE_CODE' => $actionSaveCode,
            'ACTION_DELETE_CODE' => $actionDeleteCode,
            'NOTICE_PREFIX_CODE' => $this->php_string($actionPrefix),
            'PLUGIN_ICON_CODE' => $pluginIconCode,
            'ACTIVATION_KEY_CODE' => $activationKeyCode,
        ]);
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
        return $this->render_tpl('callbacks.php.tpl', [
            'GLIB_NS' => $glibNs,
            'GROUP_NS' => $groupNs,
            'SERVICE_NS' => $serviceNs,
            'SERVICE_CLASS' => $serviceClass,
            'SVC_PAGE_SLUG_CODE' => $svcPageSlugCode,
            'ACTION_PREFIX_CODE' => $actionPrefixCode,
            'ACTION_SAVE_CODE' => $actionSaveCode,
            'ACTION_DELETE_CODE' => $actionDeleteCode,
        ]);
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
