<?php

declare(strict_types=1);

namespace {{GLIB_NS}}\Api\Services\Internal;

use {{GLIB_NS}}\Api\SettingsApi;
use {{GLIB_NS}}\Supports\Components\Admin\BaseController;

/**
 * AdminDashboard (gLib) — attivazione/disattivazione servizi.
 *
 * Pattern LSA:
 * - opzione salvata in `get_option(BaseController::PLUGIN_ID)`
 * - chiavi abilitate: quelle in `$this->srv_managers`
 * - default: disattivo finché non abilitato dalla dashboard
 */
final class AdminDashboard extends BaseController
{
    private static ?self $instance = null;

    private SettingsApi $settings_api;

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
        $this->register();
    }

    private function register(): void
    {
        $this->settings_api
            ->addPages([
                [
                    'page_title' => {{PLUGIN_NAME_CODE}},
                    'menu_title' => {{PLUGIN_NAME_CODE}},
                    'capability' => 'manage_options',
                    'menu_slug'  => {{MENU_SLUG_CODE}},
                    'callback'   => [$this, 'render_dashboard'],
                    'icon_url'   => {{PLUGIN_ICON_CODE}},
                    'position'   => 58,
                ],
            ])
            ->whithSubPage({{PLUGIN_NAME_CODE}});

        $settings = [
            [
                'option_group' => (string) self::PLUGIN_ID . '_group',
                'option_name' => (string) self::PLUGIN_ID,
            ],
        ];
        $sections = [
            [
                'id' => (string) self::PLUGIN_ID . '_dash',
                'title' => '',
                'callback' => static function (): void {},
                'page' => (string) self::SETTINGS_ID,
            ],
        ];

        $fields = [];
        foreach ($this->srv_managers as $key => $label) {
            $fields[] = [
                'id' => (string) $key,
                'title' => (string) $label,
                'callback' => [$this, 'checkboxField'],
                'page' => (string) self::SETTINGS_ID,
                'section' => (string) self::PLUGIN_ID . '_dash',
                'args' => [
                    'id' => (string) $key,
                    'label' => (string) $label,
                ],
            ];
        }

        $this->settings_api
            ->setSettings($settings)
            ->setSections($sections)
            ->setFields($fields)
            ->register();
    }
    /**
     * @param array<string,mixed> $args
     */
    public function checkboxField(array $args): void
    {
        $id = sanitize_key((string) ($args['id'] ?? ''));
        if ($id === '') {
            return;
        }
        $opt = get_option(self::PLUGIN_ID);
        $val = is_array($opt) && array_key_exists($id, $opt) ? (int) (bool) $opt[$id] : 0;
        $cbId = 'pbs_srv_' . $id;

        echo '<div class="ui-toggle">';
        echo '<input type="checkbox" id="' . esc_attr($cbId) . '" name="' . esc_attr((string) self::PLUGIN_ID) . '[' . esc_attr($id) . ']" value="1" ' . checked(1, $val, false) . ' />';
        echo '<label for="' . esc_attr($cbId) . '"><div></div></label>';
        echo '</div>';
    }



    public function render_dashboard(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html({{PLUGIN_NAME_CODE}}) . '</h1>';
        echo '<p class="description">Dashboard gLib: attiva/disattiva i servizi del plugin.</p>';

        // Toggle style (pattern LSA).
        echo '<style>
        div.ui-toggle{margin:0;padding:0}
        div.ui-toggle input[type=checkbox]{display:none}
        div.ui-toggle input[type=checkbox]:checked+label{border-color:#009eea;background:#009eea;box-shadow:inset 0 0 0 10px #009eea}
        div.ui-toggle input[type=checkbox]:checked+label>div{margin-left:20px}
        div.ui-toggle label{transition:all 200ms ease;display:inline-block;position:relative;user-select:none;background:#8c8c8c;box-shadow:inset 0 0 0 0 #009eea;border:2px solid #8c8c8c;border-radius:22px;width:40px;height:20px}
        div.ui-toggle label div{transition:all 200ms ease;background:#fff;width:20px;height:20px;border-radius:10px}
        div.ui-toggle label:hover,div.ui-toggle label>div:hover{cursor:pointer}
        </style>';

        echo '<form method="post" action="options.php">';
        settings_fields((string) self::PLUGIN_ID . '_group');
        do_settings_sections((string) self::SETTINGS_ID);
        submit_button('Salva');
        echo '</form>';
        echo '</div>';
    }
}
