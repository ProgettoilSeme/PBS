<?php

declare(strict_types=1);

namespace {{GLIB_NS}}\Api\Services\{{GROUP_NS}}\{{SERVICE_NS}};

use {{GLIB_NS}}\Api\Callbacks\AdminCallbacks;
use {{GLIB_NS}}\Api\SettingsApi;

/**
 * Admin{{SERVICE_CLASS}} generata da PBS (placeholder).
 *
 * Template standard “leggero”:
 * - TAB List: tabellina record (Edit/Delete)
 * - TAB Edit: New/Edit record
 * - TAB Help: info + SQL (DDL) + mapping
 */
final class Admin{{SERVICE_CLASS}} extends Base{{SERVICE_CLASS}}
{
    private static ?self $instance = null;

    private SettingsApi $settings_api;
    private {{SERVICE_CLASS}}Callbacks $callbacks;
    private AdminCallbacks $adminCallbacks;

    public static function instance(): self
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        parent::__construct();

        if (!is_admin()) {
            return;
        }

        $this->settings_api = new SettingsApi();
        $this->callbacks = new {{SERVICE_CLASS}}Callbacks();
        $this->adminCallbacks = new AdminCallbacks();
        $this->register();
    }

    private function register(): void
    {
        // Dashboard activation (gLib standard). Default: disattivo finché abilitato.
        if (!$this->activated({{ACTIVATION_KEY_CODE}})) {
            return;
        }

        $this->settings_api
            ->addSubPages([
                [
                    'parent_slug' => {{MENU_SLUG_CODE}},
                    'page_title'  => {{SERVICE_MENU_LABEL_CODE}},
                    'menu_title'  => {{SERVICE_MENU_LABEL_CODE}},
                    'capability'  => 'manage_options',
                    'menu_slug'   => {{SVC_PAGE_SLUG_CODE}},
                    'callback'    => [$this, 'render_service_page'],
                ],
            ])
            ;

        // Settings API (fields from schema mapping)
        $settings = [
            [
                'option_group' => {{SVC_PAGE_SLUG_CODE}} . '_group',
                'option_name' => {{SVC_PAGE_SLUG_CODE}} . '_options',
            ],
        ];
        $sections = [
            [
                'id' => {{SVC_PAGE_SLUG_CODE}} . '_main',
                'title' => '',
                'callback' => [$this->callbacks, 'sectionManager'],
                'page' => {{SVC_PAGE_SLUG_CODE}},
            ],
        ];
        $fields = [];
        foreach ($this->match_db_inp_type as $db => $meta) {
            $flags = (array) ($meta['flags'] ?? []);
            $flow = (array) ($flags['flow'] ?? []);
            $showBe = array_key_exists('be', $flow) ? (bool) $flow['be'] : true;
            $ui = (array) ($flags['ui'] ?? []);
            $uiEdit = array_key_exists('edit', $ui) ? (bool) $ui['edit'] : true;
            $uiEditable = array_key_exists('editable', $ui) ? (bool) $ui['editable'] : true;
            if (!empty($flags['hidden']) || !$showBe || !empty($flags['mirror']) || !$uiEdit) {
                continue;
            }

            $members = (array) ($meta['flags']['group']['members'] ?? []);
            if (!empty($members)) {
                $groupLabel = (string) ($meta['label'] ?? $db);
                $groupKind = (string) ($meta['flags']['group']['kind'] ?? '');

                // Text-like components: render a single main editor (hide internal members like boxtext).
                if (in_array($groupKind, ['text', 'title_text'], true)) {
                    $mainKey = '';
                    $mainType = 'text';
                    foreach ($members as $m) {
                        if (!is_array($m)) {
                            continue;
                        }
                        $mk2 = (string) ($m['key'] ?? '');
                        if ($mk2 === '') {
                            continue;
                        }
                        $mt2 = (string) ($m['field_type'] ?? 'text');
                        if ($mainKey === '') {
                            $mainKey = $mk2;
                            $mainType = $mt2;
                        }
                        if ($mt2 === 'html') {
                            $mainKey = $mk2;
                            $mainType = $mt2;
                            break;
                        }
                    }
                    if ($mainKey !== '') {
                        $fields[] = [
                            'id' => {{SVC_PAGE_SLUG_CODE}} . '_' . sanitize_key((string) $db . '_' . (string) $mainKey),
                            'title' => $groupLabel,
                            'callback' => [$this->adminCallbacks, 'inputField'],
                            'page' => {{SVC_PAGE_SLUG_CODE}},
                            'section' => {{SVC_PAGE_SLUG_CODE}} . '_main',
                            'args' => [
                                'group_db' => (string) $db,
                                'group_kind' => (string) $groupKind,
                                'member_key' => (string) $mainKey,
                                'type' => (string) $mainType,
                                'label' => (string) $groupLabel,
                                'readonly' => !$uiEditable,
                                'prefill' => is_array(($flags['prefill'] ?? null)) ? (string) (($flags['prefill'] ?? [])[$mainKey] ?? '') : '',
                            ],
                        ];
                        continue;
                    }
                }

                // Compact editor for known complex kinds (MVP: button)
                if ($groupKind === 'button') {
                    $fields[] = [
                        'id' => {{SVC_PAGE_SLUG_CODE}} . '_' . sanitize_key((string) $db),
                        'title' => $groupLabel,
                        'callback' => [$this->adminCallbacks, 'componentEditorField'],
                        'page' => {{SVC_PAGE_SLUG_CODE}},
                        'section' => {{SVC_PAGE_SLUG_CODE}} . '_main',
                        'args' => [
                            'group_db' => (string) $db,
                            'group_kind' => (string) $groupKind,
                            'group_label' => (string) $groupLabel,
                            'members' => $members,
                            'prefill' => is_array(($flags['prefill'] ?? null)) ? (array) ($flags['prefill'] ?? []) : [],
                        ],
                    ];
                    continue;
                }

                foreach ($members as $m) {
                    if (!is_array($m)) {
                        continue;
                    }
                    $mk = (string) ($m['key'] ?? '');
                    if ($mk === '') {
                        continue;
                    }
                    $ml = (string) ($m['label'] ?? $mk);
                    $mt = (string) ($m['field_type'] ?? 'text');

                    $fields[] = [
                        'id' => {{SVC_PAGE_SLUG_CODE}} . '_' . sanitize_key((string) $db . '_' . (string) $mk),
                        'title' => $groupLabel . ' — ' . $ml,
                        'callback' => [$this->adminCallbacks, 'inputField'],
                        'page' => {{SVC_PAGE_SLUG_CODE}},
                        'section' => {{SVC_PAGE_SLUG_CODE}} . '_main',
                        'args' => [
                            'group_db' => (string) $db,
                            'group_kind' => (string) $groupKind,
                            'member_key' => (string) $mk,
                            'type' => $mt,
                            'label' => $ml,
                            'readonly' => !$uiEditable,
                            'prefill' => is_array(($flags['prefill'] ?? null)) ? (string) (($flags['prefill'] ?? [])[$mk] ?? '') : '',
                        ],
                    ];
                }
                continue;
            }

            $fields[] = [
                'id' => {{SVC_PAGE_SLUG_CODE}} . '_' . sanitize_key((string) $db),
                'title' => (string) ($meta['label'] ?? $db),
                'callback' => [$this->adminCallbacks, 'inputField'],
                'page' => {{SVC_PAGE_SLUG_CODE}},
                'section' => {{SVC_PAGE_SLUG_CODE}} . '_main',
                'args' => [
                    'db_column' => (string) $db,
                    'type' => (string) ($meta['type'] ?? 'text'),
                    'label' => (string) ($meta['label'] ?? $db),
                    'readonly' => !$uiEditable,
                    'prefill' => !is_array(($flags['prefill'] ?? null)) ? (string) ($flags['prefill'] ?? '') : '',
                ],
            ];
        }

        $this->settings_api
            ->setSettings($settings)
            ->setSections($sections)
            ->setFields($fields)
            ->register();

        add_action('admin_post_' . {{ACTION_SAVE_CODE}}, [$this->callbacks, 'handle_save']);
        add_action('admin_post_' . {{ACTION_DELETE_CODE}}, [$this->callbacks, 'handle_delete']);
    }

    public function render_plugin_page(): void
    {
        echo '<div class="wrap"><h1>' . esc_html({{PLUGIN_NAME_CODE}}) . '</h1>';
        echo '<p>Plugin generato da PBS.</p>';
        echo '<p>Vai al servizio: <a href="' . esc_url(admin_url('admin.php?page=' . {{SVC_PAGE_SLUG_CODE}})) . '">' . esc_html({{SERVICE_MENU_LABEL_CODE}}) . '</a></p>';
        echo '</div>';
    }

    public function render_service_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }

        $tab = sanitize_key((string) ($_GET['tab'] ?? 'list'));
        if (!in_array($tab, ['list', 'edit', 'help'], true)) {
            $tab = 'list';
        }

        $record_id = isset($_GET['record_id']) ? (int) $_GET['record_id'] : 0;
        $editing = $tab === 'edit' && $record_id > 0;
        $record = $editing ? $this->get_record($record_id) : [];

        // In List tab pre-carichiamo records
        $records = $tab === 'list' ? $this->list_records(100) : [];

        // Prepara valori per i renderer Settings API
        $this->adminCallbacks->set_values(is_array($record) ? $record : []);

        $service = $this;
        $notice_prefix = {{NOTICE_PREFIX_CODE}};
        $tpl = dirname(__FILE__, 6) . '/UI/BE/templates/' . {{SERVICE_KEY_CODE}} . '.php';
        if (!is_file($tpl)) {
            echo '<div class="wrap"><h1>' . esc_html({{SERVICE_MENU_LABEL_CODE}}) . '</h1>';
            echo '<div class="notice notice-error"><p><strong>Template BE mancante:</strong> <code>' . esc_html($tpl) . '</code></p></div>';
            echo '</div>';
            return;
        }

        require $tpl;
    }
}
