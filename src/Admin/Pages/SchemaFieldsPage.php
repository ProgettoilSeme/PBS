<?php

declare(strict_types=1);

namespace PBS\Admin\Pages;

use PBS\Repository\SchemaRepository;
use PBS\Repository\FieldRepository;
use PBS\Repository\GenerationRepository;
use PBS\Services\ACFImporter;

/**
 * Admin page: PBS → Dettaglio schema (campi).
 *
 * Gestisce solo i campi dello schema:
 * - TAB List/New/Edit
 * - import ACF (policy B: gruppi + members)
 * - split/copy member, merge field→group, creazione gruppi
 */
final class SchemaFieldsPage
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
     * Register admin-post handlers (fields + groups + import).
     */
    public function register_actions(): void
    {
        add_action('admin_post_pbs_field_add', [$this, 'handle_field_add']);
        add_action('admin_post_pbs_field_update', [$this, 'handle_field_update']);
        add_action('admin_post_pbs_field_delete', [$this, 'handle_field_delete']);

        add_action('admin_post_pbs_schema_import_acf', [$this, 'handle_schema_import_acf']);
        add_action('admin_post_pbs_schema_fields_clear', [$this, 'handle_schema_fields_clear']);

        add_action('admin_post_pbs_group_member_split', [$this, 'handle_group_member_split']);
        add_action('admin_post_pbs_group_member_copy', [$this, 'handle_group_member_copy']);
        add_action('admin_post_pbs_group_add_member', [$this, 'handle_group_add_member']);
        add_action('admin_post_pbs_group_create', [$this, 'handle_group_create']);
        add_action('admin_post_pbs_group_member_update', [$this, 'handle_group_member_update']);
        add_action('admin_post_pbs_schema_prefill_save', [$this, 'handle_schema_prefill_save']);
    }

    /**
     * Render page.
     */
    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }

        $schemaRepo = new SchemaRepository();
        $fieldRepo = new FieldRepository();
        $importer = new ACFImporter();

        wp_enqueue_script('jquery-ui-dialog');
        wp_enqueue_style('wp-jquery-ui-dialog');

        $schemas = $schemaRepo->list();
        $schemaId = isset($_GET['schema_id']) ? (int) $_GET['schema_id'] : 0;
        if ($schemaId <= 0 && $schemas) {
            $schemaId = (int) $schemas[0]['id'];
        }

        $schema = $schemaId > 0 ? $schemaRepo->get($schemaId) : null;
        $fields = $schema ? $fieldRepo->list_by_schema((int) $schema['id']) : [];

        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'list';

        $selectedFieldId = isset($_GET['field_id']) ? (int) $_GET['field_id'] : 0;
        $selectedField = ($schema && $selectedFieldId > 0) ? $fieldRepo->get((int) $schema['id'], $selectedFieldId) : null;
        $selectedMemberIndex = isset($_GET['member_index']) ? (int) $_GET['member_index'] : -1;
        $selectedMember = null;
        if ($selectedField && $selectedMemberIndex >= 0) {
            $flags = json_decode((string) ($selectedField['flags'] ?? ''), true) ?: [];
            $members = (array) ($flags['group']['members'] ?? []);
            if (isset($members[$selectedMemberIndex]) && is_array($members[$selectedMemberIndex])) {
                $selectedMember = $members[$selectedMemberIndex];
            }
        }
        $acfAvailable = $importer->is_available();

        require PBS_PLUGIN_DIR . 'templates/admin-schema-fields.php';
    }

    public function handle_field_add(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('pbs_field_add');

        $schemaId = (int) ($_POST['schema_id'] ?? 0);
        if ($schemaId <= 0) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields'], admin_url('admin.php')));
            exit;
        }

        $dbColumn = sanitize_key($_POST['db_column'] ?? '');
        $inputName = sanitize_key($_POST['input_name'] ?? '');
        $label = sanitize_text_field($_POST['label'] ?? '');
        $type = sanitize_text_field($_POST['field_type'] ?? 'text');

        if ($dbColumn === '' || $inputName === '' || $label === '') {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields', 'schema_id' => $schemaId, 'pbs_err' => 'field_missing'], admin_url('admin.php')));
            exit;
        }

        $flags = $this->flags_from_post();
        $fieldId = (new FieldRepository())->add($schemaId, [
            'db_column' => $dbColumn,
            'input_name' => $inputName,
            'label' => $label,
            'field_type' => $type,
            'flags' => $flags,
        ]);

        (new SchemaRepository())->bump_version($schemaId);

        wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields', 'schema_id' => $schemaId, 'field_id' => $fieldId, 'pbs_ok' => 'field_added'], admin_url('admin.php')));
        exit;
    }

    public function handle_field_update(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('pbs_field_update');

        $schemaId = (int) ($_POST['schema_id'] ?? 0);
        $fieldId = (int) ($_POST['field_id'] ?? 0);
        if ($schemaId <= 0 || $fieldId <= 0) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields'], admin_url('admin.php')));
            exit;
        }

        $dbColumn = sanitize_key($_POST['db_column'] ?? '');
        $inputName = sanitize_key($_POST['input_name'] ?? '');
        $label = sanitize_text_field($_POST['label'] ?? '');
        $type = sanitize_text_field($_POST['field_type'] ?? 'text');

        if ($dbColumn === '' || $inputName === '' || $label === '') {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields', 'schema_id' => $schemaId, 'field_id' => $fieldId, 'pbs_err' => 'field_missing'], admin_url('admin.php')));
            exit;
        }

        $fieldRepo = new FieldRepository();
        $existing = $fieldRepo->get($schemaId, $fieldId);
        $existingFlags = is_array($existing) ? (json_decode((string) ($existing['flags'] ?? ''), true) ?: []) : [];
        $incomingFlags = $this->flags_from_post();

        $mergedFlags = $this->merge_flags_for_update($existingFlags, $incomingFlags);

        $fieldRepo->update($schemaId, $fieldId, [
            'db_column' => $dbColumn,
            'input_name' => $inputName,
            'label' => $label,
            'field_type' => $type,
            'flags' => $mergedFlags,
        ]);

        (new SchemaRepository())->bump_version($schemaId);

        wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields', 'schema_id' => $schemaId, 'field_id' => $fieldId, 'pbs_ok' => 'field_saved'], admin_url('admin.php')));
        exit;
    }

    public function handle_field_delete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('pbs_field_delete');

        // Support both POST (forms) and GET (nonce links).
        $schemaId = (int) ($_REQUEST['schema_id'] ?? 0);
        $fieldId = (int) ($_REQUEST['field_id'] ?? 0);
        $returnTab = sanitize_key((string) ($_REQUEST['return_tab'] ?? ''));
        $postId = (int) ($_REQUEST['post_id'] ?? 0);
        if ($schemaId <= 0 || $fieldId <= 0) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields'], admin_url('admin.php')));
            exit;
        }

        (new FieldRepository())->delete($schemaId, $fieldId);
        (new SchemaRepository())->bump_version($schemaId);

        $args = ['page' => 'pbs-schema-fields', 'schema_id' => $schemaId, 'pbs_ok' => 'field_deleted'];
        if (in_array($returnTab, ['schema', 'preview', 'list', 'new', 'edit', 'help'], true)) {
            $args['tab'] = $returnTab;
        }
        if ($returnTab === 'preview' && $postId > 0) {
            $args['post_id'] = $postId;
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public function handle_schema_import_acf(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('pbs_schema_import_acf');

        $schemaId = (int) ($_POST['schema_id'] ?? 0);
        if ($schemaId <= 0) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields'], admin_url('admin.php')));
            exit;
        }

        // Prefer explicit post type from request; otherwise fall back to schema config, then schema slug.
        $postType = sanitize_text_field($_POST['source_post_type'] ?? '');
        $schema = (new SchemaRepository())->get($schemaId);
        if ($postType === '' && is_array($schema)) {
            $postType = sanitize_text_field((string) ($schema['source_post_type'] ?? ''));
        }
        if ($postType === '' && is_array($schema)) {
            $postType = sanitize_text_field((string) ($schema['slug'] ?? ''));
        }

        $samplePostId = (int) ($_POST['sample_post_id'] ?? 0);

        $importer = new ACFImporter();
        $onlyElements = !empty($_POST['only_elements']);
        $groupElements = !empty($_POST['group_elements']);
        $result = $importer->import_post_type_schema($postType, $samplePostId, [
            'only_elements' => $onlyElements,
            'group_elements' => $groupElements,
        ]);

        if ($samplePostId > 0) {
            set_transient(
                'pbs_acf_import_layouts_' . get_current_user_id(),
                [
                    'post_id' => $samplePostId,
                    'layouts' => $importer->debug_layouts_from_post($samplePostId),
                ],
                120
            );

            // Extra debug: show top-level values keys for the sample post (helps diagnosing clone/prefix setups).
            if (function_exists('get_fields')) {
                $vals = get_fields($samplePostId, false);
                $preview = [];
                if (is_array($vals)) {
                    $i = 0;
                    foreach ($vals as $k => $v) {
                        if (!is_string($k) || $k === '') {
                            continue;
                        }
                        if ($i >= 30) {
                            break;
                        }
                        if (is_array($v)) {
                            $isList = (array_keys($v) === range(0, count($v) - 1));
                            $entry = [
                                'type' => $isList ? 'list' : 'map',
                                'keys' => array_slice(array_map('strval', array_keys($v)), 0, 20),
                            ];
                            if ($isList && !empty($v) && is_array($v[0])) {
                                $entry['row0_keys'] = array_slice(array_map('strval', array_keys((array) $v[0])), 0, 40);
                            }
                            $preview[$k] = $entry;
                        } else {
                            $preview[$k] = [
                                'type' => gettype($v),
                            ];
                        }
                        $i++;
                    }
                }
                set_transient(
                    'pbs_acf_import_sample_' . get_current_user_id(),
                    [
                        'post_id' => $samplePostId,
                        'keys' => is_array($vals) ? array_slice(array_values(array_filter(array_map('strval', array_keys($vals)), 'strlen')), 0, 200) : [],
                        'preview' => $preview,
                    ],
                    120
                );
            }

            // PBS Preview: store per-schema preview (component samples) to render a CPT-like view.
            // TTL is longer because it's used interactively while refining the schema.
            $uid = (int) get_current_user_id();
            $schemaPreview = [
                'post_id' => $samplePostId,
                'post_title' => $samplePostId > 0 ? (string) get_the_title($samplePostId) : '',
                'components' => (array) ($result['preview'] ?? []),
            ];
            set_transient('pbs_schema_preview_' . $schemaId . '_' . $uid, $schemaPreview, 3600);
        }

        if (empty($result['fields'])) {
            set_transient(
                'pbs_acf_import_debug_' . get_current_user_id(),
                $importer->debug_match_report($postType),
                120
            );
        }

        (new SchemaRepository())->update($schemaId, [
            'source_type' => 'acf',
            'source_post_type' => $postType,
            'source_acf_groups' => $result['groups'] ?? [],
        ]);

        if (!empty($result['fields'])) {
            (new FieldRepository())->replace_all($schemaId, $result['fields']);
            (new SchemaRepository())->bump_version($schemaId);
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields', 'schema_id' => $schemaId, 'pbs_ok' => 'acf_import'], admin_url('admin.php')));
            exit;
        }

        // No fields found: guide debugging.
        wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields', 'schema_id' => $schemaId, 'pbs_err' => 'acf_no_fields'], admin_url('admin.php')));
        exit;
    }

    /**
     * Save "pre-fill" defaults/suggestions for the schema (does not modify CPT/ACF values).
     *
     * Stores values inside field flags as:
     * - group field: flags.prefill = {memberKey: value, ...}
     * - simple field: flags.prefill = scalar value
     */
    public function handle_schema_prefill_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('pbs_schema_prefill_save');

        $schemaId = (int) ($_POST['schema_id'] ?? 0);
        $postId = (int) ($_POST['post_id'] ?? 0);
        if ($schemaId <= 0) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields'], admin_url('admin.php')));
            exit;
        }

        $fieldRepo = new FieldRepository();
        $schemaRepo = new SchemaRepository();
        $fields = $fieldRepo->list_by_schema($schemaId);

        $groupPrefill = $_POST['prefill'] ?? [];
        $simplePrefill = $_POST['prefill_field'] ?? [];

        foreach ($fields as $f) {
            if (!is_array($f)) {
                continue;
            }
            $db = (string) ($f['db_column'] ?? '');
            if ($db === '') {
                continue;
            }

            $flags = json_decode((string) ($f['flags'] ?? ''), true) ?: [];
            $isGroup = !empty($flags['group']) && is_array($flags['group']);

            if ($isGroup && isset($groupPrefill[$db]) && is_array($groupPrefill[$db])) {
                $members = (array) ($flags['group']['members'] ?? []);
                $typesByKey = [];
                foreach ($members as $m) {
                    if (!is_array($m)) {
                        continue;
                    }
                    $k = sanitize_key((string) ($m['key'] ?? ''));
                    if ($k === '') {
                        continue;
                    }
                    $typesByKey[$k] = (string) ($m['field_type'] ?? 'text');
                }

                $pref = [];
                foreach ((array) $groupPrefill[$db] as $k => $v) {
                    $k = sanitize_key((string) $k);
                    if ($k === '') {
                        continue;
                    }
                    $t = $typesByKey[$k] ?? 'text';
                    $pref[$k] = $this->sanitize_prefill_value($t, $v);
                }

                $flags['prefill'] = $pref;
                $fieldRepo->update($schemaId, (int) $f['id'], [
                    'db_column' => (string) ($f['db_column'] ?? ''),
                    'input_name' => (string) ($f['input_name'] ?? ''),
                    'label' => (string) ($f['label'] ?? ''),
                    'field_type' => (string) ($f['field_type'] ?? 'array_nested'),
                    'flags' => $flags,
                ]);
                continue;
            }

            if (!$isGroup && isset($simplePrefill[$db])) {
                $t = (string) ($f['field_type'] ?? 'text');
                $flags['prefill'] = $this->sanitize_prefill_value($t, $simplePrefill[$db]);
                $fieldRepo->update($schemaId, (int) $f['id'], [
                    'db_column' => (string) ($f['db_column'] ?? ''),
                    'input_name' => (string) ($f['input_name'] ?? ''),
                    'label' => (string) ($f['label'] ?? ''),
                    'field_type' => (string) ($f['field_type'] ?? 'text'),
                    'flags' => $flags,
                ]);
            }
        }

        $schemaRepo->bump_version($schemaId);

        wp_safe_redirect(add_query_arg([
            'page' => 'pbs-schema-fields',
            'schema_id' => $schemaId,
            'tab' => 'preview',
            'post_id' => $postId > 0 ? $postId : null,
            'pbs_ok' => 'prefill_saved',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function sanitize_prefill_value(string $type, $value)
    {
        if (is_array($value)) {
            return $value;
        }
        $value = (string) ($value ?? '');
        return match ($type) {
            'url' => esc_url_raw($value),
            'email' => sanitize_email($value),
            'html' => wp_kses_post($value),
            'text_area' => sanitize_textarea_field($value),
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (!empty($value) && $value !== '0') ? 1 : 0,
            default => sanitize_text_field($value),
        };
    }

    /**
     * Clear all fields for a schema (keeps the schema itself).
     */
    public function handle_schema_fields_clear(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('pbs_schema_fields_clear');

        $schemaId = (int) ($_POST['schema_id'] ?? 0);
        if ($schemaId <= 0) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields'], admin_url('admin.php')));
            exit;
        }

        (new FieldRepository())->delete_all_by_schema($schemaId);
        (new SchemaRepository())->bump_version($schemaId);

        wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields', 'schema_id' => $schemaId, 'pbs_ok' => 'fields_cleared'], admin_url('admin.php')));
        exit;
    }

    /**
     * Split one member out of a grouped field into its own PBS field (materialized column/input).
     */
    public function handle_group_member_split(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('pbs_group_member_split');

        $schemaId = (int) ($_POST['schema_id'] ?? 0);
        $groupFieldId = (int) ($_POST['field_id'] ?? 0);
        $memberIndex = (int) ($_POST['member_index'] ?? -1);
        $returnTab = sanitize_key((string) ($_POST['return_tab'] ?? ''));
        if ($schemaId <= 0 || $groupFieldId <= 0 || $memberIndex < 0) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields', 'schema_id' => $schemaId], admin_url('admin.php')));
            exit;
        }

        $fieldRepo = new FieldRepository();
        $schemaRepo = new SchemaRepository();

        $groupField = $fieldRepo->get($schemaId, $groupFieldId);
        if (!is_array($groupField)) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields', 'schema_id' => $schemaId], admin_url('admin.php')));
            exit;
        }

        $flags = json_decode((string) ($groupField['flags'] ?? ''), true) ?: [];
        $members = (array) ($flags['group']['members'] ?? []);
        if (!isset($members[$memberIndex]) || !is_array($members[$memberIndex])) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields', 'schema_id' => $schemaId, 'tab' => 'edit', 'field_id' => $groupFieldId, 'pbs_err' => 'group_member_missing'], admin_url('admin.php')));
            exit;
        }

        $member = $members[$memberIndex];

        $memberKey = sanitize_key((string) ($member['key'] ?? ''));
        $memberLabel = sanitize_text_field((string) ($member['label'] ?? $memberKey));
        $memberType = sanitize_text_field((string) ($member['field_type'] ?? 'text'));

        $base = sanitize_key((string) ($groupField['db_column'] ?? 'group'));
        $dbColumn = sanitize_key($base . '_' . ($memberKey !== '' ? $memberKey : 'member'));
        $inputName = $dbColumn;

        $maxOrd = 0;
        foreach ($fieldRepo->list_by_schema($schemaId) as $f) {
            $maxOrd = max($maxOrd, (int) ($f['ord'] ?? 0));
        }

        $newId = $fieldRepo->add($schemaId, [
            'ord' => $maxOrd + 1,
            'db_column' => $dbColumn,
            'input_name' => $inputName,
            'label' => $memberLabel !== '' ? $memberLabel : $memberKey,
            'field_type' => $memberType !== '' ? $memberType : 'text',
            'flags' => [
                'backend' => false,
                'hidden' => false,
                'readonly' => false,
                'serialized' => false,
                'acf' => $member['acf'] ?? [],
                'group_link' => [
                    'parent_field_id' => $groupFieldId,
                    'member_key' => $memberKey,
                    'member' => $member,
                ],
            ],
        ]);

        // Remove member from group (split = "porta fuori").
        array_splice($members, $memberIndex, 1);
        $flags['group']['members'] = array_values($members);

        $fieldRepo->update($schemaId, $groupFieldId, [
            'db_column' => (string) ($groupField['db_column'] ?? ''),
            'input_name' => (string) ($groupField['input_name'] ?? ''),
            'label' => (string) ($groupField['label'] ?? ''),
            'field_type' => (string) ($groupField['field_type'] ?? 'array_nested'),
            'flags' => $flags,
        ]);

        $schemaRepo->bump_version($schemaId);

        $redir = [
            'page' => 'pbs-schema-fields',
            'schema_id' => $schemaId,
            'pbs_ok' => 'group_split',
        ];

        // Default: go back to List (expected behavior when action is triggered from List).
        // Allow explicit override from UI (e.g., split from Edit members table).
        if ($returnTab === 'edit') {
            $redir['tab'] = 'edit';
            $redir['field_id'] = $groupFieldId;
        } elseif ($returnTab === 'preview') {
            $redir['tab'] = 'preview';
        } elseif ($returnTab === 'schema') {
            $redir['tab'] = 'schema';
        } else {
            $redir['tab'] = 'list';
        }

        wp_safe_redirect(add_query_arg($redir, admin_url('admin.php')));
        exit;
    }

    public function handle_group_member_copy(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('pbs_group_member_copy');

        $schemaId = (int) ($_POST['schema_id'] ?? 0);
        $groupFieldId = (int) ($_POST['field_id'] ?? 0);
        $memberIndex = (int) ($_POST['member_index'] ?? -1);
        $returnTab = sanitize_key((string) ($_POST['return_tab'] ?? 'list'));

        if ($schemaId <= 0 || $groupFieldId <= 0 || $memberIndex < 0) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields'], admin_url('admin.php')));
            exit;
        }

        $fieldRepo = new FieldRepository();
        $schemaRepo = new SchemaRepository();

        $groupField = $fieldRepo->get($schemaId, $groupFieldId);
        if (!is_array($groupField)) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields', 'schema_id' => $schemaId], admin_url('admin.php')));
            exit;
        }

        $flags = json_decode((string) ($groupField['flags'] ?? ''), true) ?: [];
        $members = (array) ($flags['group']['members'] ?? []);
        if (!isset($members[$memberIndex]) || !is_array($members[$memberIndex])) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields', 'schema_id' => $schemaId, 'pbs_err' => 'group_member_missing'], admin_url('admin.php')));
            exit;
        }

        $member = (array) $members[$memberIndex];
        $memberKey = sanitize_key((string) ($member['key'] ?? ''));
        if ($memberKey === '') {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields', 'schema_id' => $schemaId, 'pbs_err' => 'group_member_missing'], admin_url('admin.php')));
            exit;
        }

        // Determine next ord and existing columns to avoid collisions.
        $existing = $fieldRepo->list_by_schema($schemaId);
        $maxOrd = 0;
        $dbSet = [];
        $inSet = [];
        foreach ($existing as $f) {
            $maxOrd = max($maxOrd, (int) ($f['ord'] ?? 0));
            $db = (string) ($f['db_column'] ?? '');
            $in = (string) ($f['input_name'] ?? '');
            if ($db !== '') {
                $dbSet[$db] = true;
            }
            if ($in !== '') {
                $inSet[$in] = true;
            }
        }

        $groupDb = sanitize_key((string) ($groupField['db_column'] ?? 'group'));
        $base = $groupDb !== '' ? ($groupDb . '_' . $memberKey) : $memberKey;

        $dbColumn = $this->unique_key($base, $dbSet);
        $inputName = $this->unique_key($dbColumn, $inSet);

        $label = (string) ($member['label'] ?? $memberKey);
        $type = sanitize_text_field((string) ($member['field_type'] ?? 'text'));
        if ($type === '') {
            $type = 'text';
        }

        // Mirror policy (default): one-way nested -> mirror (mirror is derived, not authoritative).
        // Purpose: keep a flat column for SQL index/search/payload-light, without breaking the typed component.
        $newFlags = [
            'backend' => false,
            'hidden' => true,
            'readonly' => true,
            'serialized' => false,
            'flow' => [
                'be' => false,          // not shown in BE forms by default
                'payload_in' => false,  // not accepted from payload by default
                'payload_out' => true,  // can be exposed (optional) if needed for FE/search
            ],
            'mirror' => [
                'mode' => 'one_way',
                'source' => [
                    'group_field_id' => $groupFieldId,
                    'group_db' => $groupDb,
                    'member_key' => $memberKey,
                    'path' => $groupDb . '.' . $memberKey,
                ],
            ],
            'origin' => [
                'from_group' => $groupFieldId,
                'group_db' => $groupDb,
                'member_key' => $memberKey,
                'copied' => true,
            ],
        ];
        if (!empty($member['acf']) && is_array($member['acf'])) {
            $newFlags['acf'] = $member['acf'];
        }

        $newId = $fieldRepo->add($schemaId, [
            'ord' => $maxOrd + 1,
            'db_column' => $dbColumn,
            'input_name' => $inputName,
            'label' => $label,
            'field_type' => $type,
            'flags' => $newFlags,
        ]);

        $schemaRepo->bump_version($schemaId);

        $redir = [
            'page' => 'pbs-schema-fields',
            'schema_id' => $schemaId,
            'pbs_ok' => 'member_copied',
        ];
        if ($returnTab === 'edit') {
            $redir['tab'] = 'edit';
            $redir['field_id'] = $newId;
        } elseif ($returnTab === 'preview') {
            $redir['tab'] = 'preview';
        } elseif ($returnTab === 'schema') {
            $redir['tab'] = 'schema';
        } else {
            $redir['tab'] = 'list';
        }

        wp_safe_redirect(add_query_arg($redir, admin_url('admin.php')));
        exit;
    }

    /**
     * @param array<string,bool> $used
     */
    private function unique_key(string $base, array $used): string
    {
        $base = sanitize_key($base);
        if ($base === '') {
            $base = 'field';
        }
        $k = $base;
        $i = 1;
        while (isset($used[$k])) {
            $i++;
            $k = $base . '_' . $i;
        }
        return $k;
    }

    /**
     * Group an existing PBS field into an existing group field.
     */
    public function handle_group_add_member(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('pbs_group_add_member');

        $schemaId = (int) ($_POST['schema_id'] ?? 0);
        $fieldId = (int) ($_POST['field_id'] ?? 0);
        $groupFieldId = (int) ($_POST['group_field_id'] ?? 0);
        $returnTab = sanitize_key((string) ($_POST['return_tab'] ?? ''));
        if ($schemaId <= 0 || $fieldId <= 0 || $groupFieldId <= 0) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields', 'schema_id' => $schemaId], admin_url('admin.php')));
            exit;
        }

        $fieldRepo = new FieldRepository();
        $schemaRepo = new SchemaRepository();

        $groupField = $fieldRepo->get($schemaId, $groupFieldId);
        if (!is_array($groupField)) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields', 'schema_id' => $schemaId], admin_url('admin.php')));
            exit;
        }

        $field = $fieldRepo->get($schemaId, $fieldId);
        if (!is_array($field)) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields', 'schema_id' => $schemaId], admin_url('admin.php')));
            exit;
        }

        $groupFlags = json_decode((string) ($groupField['flags'] ?? ''), true) ?: [];
        $members = (array) ($groupFlags['group']['members'] ?? []);

        $fieldFlags = json_decode((string) ($field['flags'] ?? ''), true) ?: [];
        $acf = $fieldFlags['acf'] ?? [];
        $key = sanitize_key((string) ($field['db_column'] ?? $field['input_name'] ?? 'field'));
        $label = sanitize_text_field((string) ($field['label'] ?? $key));
        $type = sanitize_text_field((string) ($field['field_type'] ?? 'text'));

        $members[] = [
            'key' => $key,
            'label' => $label,
            'field_type' => $type,
            'acf' => $acf,
        ];
        $groupFlags['group']['members'] = array_values($members);

        // Update group
        $fieldRepo->update($schemaId, $groupFieldId, [
            'db_column' => (string) ($groupField['db_column'] ?? ''),
            'input_name' => (string) ($groupField['input_name'] ?? ''),
            'label' => (string) ($groupField['label'] ?? ''),
            'field_type' => (string) ($groupField['field_type'] ?? 'array_nested'),
            'flags' => $groupFlags,
        ]);

        // Remove field from schema (now inside group)
        $fieldRepo->delete($schemaId, $fieldId);

        $schemaRepo->bump_version($schemaId);

        $redir = [
            'page' => 'pbs-schema-fields',
            'schema_id' => $schemaId,
            'pbs_ok' => 'group_merged',
        ];
        if ($returnTab === 'edit') {
            $redir['tab'] = 'edit';
            $redir['field_id'] = $groupFieldId;
        } elseif ($returnTab === 'schema') {
            $redir['tab'] = 'schema';
        } else {
            $redir['tab'] = 'list';
        }
        wp_safe_redirect(add_query_arg($redir, admin_url('admin.php')));
        exit;
    }

    public function handle_group_create(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('pbs_group_create');

        $schemaId = (int) ($_POST['schema_id'] ?? 0);
        if ($schemaId <= 0) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields'], admin_url('admin.php')));
            exit;
        }

        $dbColumn = sanitize_key($_POST['db_column'] ?? '');
        $inputName = sanitize_key($_POST['input_name'] ?? '');
        $label = sanitize_text_field($_POST['label'] ?? '');

        if ($dbColumn === '' || $inputName === '' || $label === '') {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields', 'schema_id' => $schemaId, 'tab' => 'new', 'mode' => 'group', 'pbs_err' => 'field_missing'], admin_url('admin.php')));
            exit;
        }

        $fieldRepo = new FieldRepository();
        $maxOrd = 0;
        foreach ($fieldRepo->list_by_schema($schemaId) as $f) {
            $maxOrd = max($maxOrd, (int) ($f['ord'] ?? 0));
        }

        $newId = $fieldRepo->add($schemaId, [
            'ord' => $maxOrd + 1,
            'db_column' => $dbColumn,
            'input_name' => $inputName,
            'label' => $label,
            'field_type' => 'array_nested',
            'flags' => [
                'backend' => false,
                'hidden' => false,
                'readonly' => false,
                'serialized' => true,
                'flow' => [
                    'be' => true,
                    'payload_in' => true,
                    'payload_out' => true,
                ],
                'group' => [
                    'kind' => 'generic',
                    'mode' => 'payload',
                    'members' => [],
                ],
            ],
        ]);

        (new SchemaRepository())->bump_version($schemaId);

        wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields', 'schema_id' => $schemaId, 'tab' => 'edit', 'field_id' => $newId, 'pbs_ok' => 'group_created'], admin_url('admin.php')));
        exit;
    }

    public function handle_group_member_update(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('pbs_group_member_update');

        $schemaId = (int) ($_POST['schema_id'] ?? 0);
        $groupFieldId = (int) ($_POST['field_id'] ?? 0);
        $memberIndex = (int) ($_POST['member_index'] ?? -1);
        if ($schemaId <= 0 || $groupFieldId <= 0 || $memberIndex < 0) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields', 'schema_id' => $schemaId], admin_url('admin.php')));
            exit;
        }

        $fieldRepo = new FieldRepository();
        $groupField = $fieldRepo->get($schemaId, $groupFieldId);
        if (!is_array($groupField)) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields', 'schema_id' => $schemaId], admin_url('admin.php')));
            exit;
        }

        $flags = json_decode((string) ($groupField['flags'] ?? ''), true) ?: [];
        $members = (array) ($flags['group']['members'] ?? []);
        if (!isset($members[$memberIndex]) || !is_array($members[$memberIndex])) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields', 'schema_id' => $schemaId, 'tab' => 'edit', 'field_id' => $groupFieldId, 'pbs_err' => 'group_member_missing'], admin_url('admin.php')));
            exit;
        }

        $members[$memberIndex]['key'] = sanitize_key($_POST['member_key'] ?? (string) ($members[$memberIndex]['key'] ?? ''));
        $members[$memberIndex]['label'] = sanitize_text_field($_POST['member_label'] ?? (string) ($members[$memberIndex]['label'] ?? ''));
        $members[$memberIndex]['field_type'] = sanitize_text_field($_POST['member_field_type'] ?? (string) ($members[$memberIndex]['field_type'] ?? 'text'));
        $flags['group']['members'] = array_values($members);

        $fieldRepo->update($schemaId, $groupFieldId, [
            'db_column' => (string) ($groupField['db_column'] ?? ''),
            'input_name' => (string) ($groupField['input_name'] ?? ''),
            'label' => (string) ($groupField['label'] ?? ''),
            'field_type' => (string) ($groupField['field_type'] ?? 'array_nested'),
            'flags' => $flags,
        ]);

        (new SchemaRepository())->bump_version($schemaId);

        wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields', 'schema_id' => $schemaId, 'tab' => 'edit', 'field_id' => $groupFieldId, 'member_index' => $memberIndex, 'pbs_ok' => 'member_saved'], admin_url('admin.php')));
        exit;
    }

    private function flags_from_post(): array
    {
        $flowBe = isset($_POST['flag_flow_be']) ? !empty($_POST['flag_flow_be']) : true;
        $flowIn = isset($_POST['flag_flow_payload_in']) ? !empty($_POST['flag_flow_payload_in']) : true;
        $flowOut = isset($_POST['flag_flow_payload_out']) ? !empty($_POST['flag_flow_payload_out']) : true;

        return [
            'backend' => !empty($_POST['flag_backend']),
            'hidden' => !empty($_POST['flag_hidden']),
            'readonly' => !empty($_POST['flag_readonly']),
            'serialized' => !empty($_POST['flag_serialized']),
            'flow' => [
                'be' => $flowBe,
                'payload_in' => $flowIn,
                'payload_out' => $flowOut,
            ],
        ];
    }

    /**
     * Merge flags for field update to avoid dropping group/mirror/acf metadata.
     *
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $incoming
     * @return array<string,mixed>
     */
    private function merge_flags_for_update(array $existing, array $incoming): array
    {
        // Preserve structural metadata.
        $preserveKeys = ['group', 'acf', 'origin', 'mirror'];
        foreach ($preserveKeys as $k) {
            if (isset($existing[$k]) && !isset($incoming[$k])) {
                $incoming[$k] = $existing[$k];
            }
        }

        // Preserve unknown keys (forward compatibility), but allow incoming to overwrite.
        return array_replace_recursive($existing, $incoming);
    }
}
