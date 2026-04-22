<?php

declare(strict_types=1);

namespace {{GLIB_NS}}\Api\Services\{{GROUP_NS}}\{{SERVICE_NS}};

/**
 * {{SERVICE_CLASS}}Callbacks generata da PBS.
 *
 * Handler BE (admin-post) per CRUD record.
 */
final class {{SERVICE_CLASS}}Callbacks extends Base{{SERVICE_CLASS}}
{
    public function __construct()
    {
        parent::__construct();
    }

    public function sectionManager(array $args = []): void
    {
        // Placeholder: descrizione sezione (opzionale)
        // echo '<p>Gestione record.</p>';
    }

    public function handle_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer({{ACTION_SAVE_CODE}});

        $id = (int) ($_POST['record_id'] ?? 0);
        $raw = (array) ($_POST['fields'] ?? []);

        $err = '';
        $rid = $this->save_record($id, $raw, $err);

        $uid = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($uid > 0) {
            set_transient({{ACTION_PREFIX_CODE}} . '_notice_' . $uid, [
                'ok' => $rid > 0,
                'message' => $rid > 0 ? 'Record salvato.' : ('Errore: ' . ($err ?: 'save fallita')),
            ], 30);
        }

        wp_safe_redirect(admin_url('admin.php?page=' . {{SVC_PAGE_SLUG_CODE}} . '&tab=list'));
        exit;
    }

    public function handle_delete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer({{ACTION_DELETE_CODE}});

        $id = (int) ($_POST['record_id'] ?? 0);
        $err = '';
        $ok = $this->delete_record($id, $err);

        $uid = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($uid > 0) {
            set_transient({{ACTION_PREFIX_CODE}} . '_notice_' . $uid, [
                'ok' => $ok,
                'message' => $ok ? 'Record eliminato.' : ('Errore: ' . ($err ?: 'delete fallita')),
            ], 30);
        }

        wp_safe_redirect(admin_url('admin.php?page=' . {{SVC_PAGE_SLUG_CODE}} . '&tab=list'));
        exit;
    }
}

