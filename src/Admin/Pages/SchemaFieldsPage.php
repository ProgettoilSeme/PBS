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

        (new FieldRepository())->update($schemaId, $fieldId, [
            'db_column' => $dbColumn,
            'input_name' => $inputName,
            'label' => $label,
            'field_type' => $type,
            'flags' => $this->flags_from_post(),
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

        $schemaId = (int) ($_POST['schema_id'] ?? 0);
        $fieldId = (int) ($_POST['field_id'] ?? 0);
        if ($schemaId <= 0 || $fieldId <= 0) {
            wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields'], admin_url('admin.php')));
            exit;
        }

        (new FieldRepository())->delete($schemaId, $fieldId);
        (new SchemaRepository())->bump_version($schemaId);

        wp_safe_redirect(add_query_arg(['page' => 'pbs-schema-fields', 'schema_id' => $schemaId, 'pbs_ok' => 'field_deleted'], admin_url('admin.php')));
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

        $newFlags = [
            'backend' => false,
            'hidden' => false,
            'readonly' => false,
            'serialized' => false,
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
        return [
            'backend' => !empty($_POST['flag_backend']),
            'hidden' => !empty($_POST['flag_hidden']),
            'readonly' => !empty($_POST['flag_readonly']),
            'serialized' => !empty($_POST['flag_serialized']),
        ];
    }
}
