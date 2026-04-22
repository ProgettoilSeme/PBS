<?php

declare(strict_types=1);

namespace {{GLIB_NS}}\Api;

/**
 * SettingsApi (light) — registra menu e (opzionalmente) settings/fields.
 *
 * Nota: in questa prima versione viene usata principalmente per la gestione menu.
 */
final class SettingsApi
{
    private static ?self $instance = null;

    /** @var array<int,array<string,mixed>> */
    public array $admin_pages = [];
    /** @var array<int,array<string,mixed>> */
    public array $admin_subpages = [];

    /** @var array<int,array<string,mixed>> */
    public array $settings = [];
    /** @var array<int,array<string,mixed>> */
    public array $sections = [];
    /** @var array<int,array<string,mixed>> */
    public array $fields = [];

    public static function instance(): self
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register(): void
    {
        self::$instance = $this;

        if (!empty($this->admin_pages) || !empty($this->admin_subpages)) {
            add_action('admin_menu', [$this, 'addAdminMenu']);
        }
        if (!empty($this->settings)) {
            add_action('admin_init', [$this, 'registerCustomFields']);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     */
    public function addPages(array $pages): self
    {
        $this->admin_pages = $pages;
        return $this;
    }

    public function whithSubPage(string $title = null): self
    {
        if (empty($this->admin_pages)) {
            return $this;
        }
        $admin_page = $this->admin_pages[0];
        $this->admin_subpages[] = [
            'parent_slug' => $admin_page['menu_slug'],
            'page_title' => $admin_page['page_title'],
            'menu_title' => $title ?? $admin_page['menu_title'],
            'capability' => $admin_page['capability'],
            'menu_slug' => $admin_page['menu_slug'],
            'callback' => $admin_page['callback'],
        ];
        return $this;
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     */
    public function addSubPages(array $pages): self
    {
        $this->admin_subpages = array_merge($this->admin_subpages, $pages);
        return $this;
    }

    public function addAdminMenu(): void
    {
        foreach ($this->admin_pages as $page) {
            add_menu_page(
                (string) $page['page_title'],
                (string) $page['menu_title'],
                (string) $page['capability'],
                (string) $page['menu_slug'],
                $page['callback'],
                $page['icon_url'] ?? '',
                $page['position'] ?? null
            );
        }
        foreach ($this->admin_subpages as $page) {
            add_submenu_page(
                (string) $page['parent_slug'],
                (string) $page['page_title'],
                (string) $page['menu_title'],
                (string) $page['capability'],
                (string) $page['menu_slug'],
                $page['callback']
            );
        }
    }

    /**
     * @param array<int,array<string,mixed>> $settings
     */
    public function setSettings(array $settings): self
    {
        $this->settings = $settings;
        return $this;
    }

    /**
     * @param array<int,array<string,mixed>> $sections
     */
    public function setSections(array $sections): self
    {
        $this->sections = $sections;
        return $this;
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     */
    public function setFields(array $fields): self
    {
        $this->fields = $fields;
        return $this;
    }

    public function registerCustomFields(): void
    {
        foreach ($this->settings as $setting) {
            register_setting(
                (string) $setting['option_group'],
                (string) $setting['option_name'],
                $setting['callback'] ?? static function ($input) {
                    return $input;
                }
            );
        }
        foreach ($this->sections as $section) {
            add_settings_section(
                (string) $section['id'],
                (string) $section['title'],
                $section['callback'] ?? static function (): void {},
                (string) $section['page']
            );
        }
        foreach ($this->fields as $field) {
            add_settings_field(
                (string) $field['id'],
                (string) $field['title'],
                $field['callback'] ?? static function (): void {},
                (string) $field['page'],
                (string) ($field['section'] ?? $field['page']),
                $field['args'] ?? []
            );
        }
    }
}
