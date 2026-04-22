<?php

/**
 * Template BE generato da PBS.
 *
 * Variabili attese:
 * - $service (Admin service instance)
 * - $tab (string)
 * - $editing (bool)
 * - $record_id (int)
 * - $records (array)
 */

if (!defined('ABSPATH')) {
    exit;
}

settings_errors();

$base_url = admin_url('admin.php?page=' . {{SVC_PAGE_SLUG_CODE}});

// UI columns for List tab (derived from mapping flags.ui.list).
$list_cols = [];
if (isset($service) && is_object($service) && isset($service->match_db_inp_type) && is_array($service->match_db_inp_type)) {
    foreach ($service->match_db_inp_type as $db => $meta) {
        if (!is_array($meta)) {
            continue;
        }
        $flags = (array) ($meta['flags'] ?? []);
        $ui = (array) ($flags['ui'] ?? []);
        $show = array_key_exists('list', $ui) ? (bool) $ui['list'] : false;
        if (!$show) {
            continue;
        }
        $list_cols[] = [
            'db' => (string) $db,
            'label' => (string) (($meta['label'] ?? '') !== '' ? $meta['label'] : $db),
            'type' => (string) (($meta['type'] ?? '') !== '' ? $meta['type'] : (($meta['field_type'] ?? '') !== '' ? $meta['field_type'] : 'text')),
            'flags' => $flags,
        ];
    }
}

$pbs_cell = static function ($val): string {
    if (is_array($val)) {
        // Heuristic: prefer human-friendly keys when available.
        if (isset($val['title']) && is_scalar($val['title'])) {
            $s = (string) $val['title'];
        } elseif (isset($val['p']) && is_scalar($val['p'])) {
            $s = wp_strip_all_tags((string) $val['p']);
        } elseif (isset($val['url']) && is_scalar($val['url'])) {
            $s = (string) $val['url'];
        } else {
            $s = wp_json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (!is_string($s)) {
            $s = '';
        }
        if (strlen($s) > 120) {
            $s = substr($s, 0, 117) . '...';
        }
        return $s;
    }
    $s = is_scalar($val) ? (string) $val : '';
    if (strlen($s) > 120) {
        $s = substr($s, 0, 117) . '...';
    }
    return $s;
};
?>
<div class="wrap">
    <h1><?php echo esc_html({{SERVICE_MENU_LABEL_CODE}}); ?></h1>

    <?php
    // Notice from callbacks (transient)
    $uid = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    if (!empty($notice_prefix) && $uid > 0) {
        $n = get_transient((string) $notice_prefix . '_notice_' . $uid);
        if (is_array($n) && isset($n['ok'], $n['message'])) {
            delete_transient((string) $notice_prefix . '_notice_' . $uid);
            $cls = !empty($n['ok']) ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($cls) . '"><p><strong>' . esc_html((string) $n['message']) . '</strong></p></div>';
        }
    }
    ?>

    <h2 class="nav-tab-wrapper" style="margin-top:16px;">
        <?php
        $tabs = [
            'list' => 'List',
            'edit' => $editing ? 'Edit' : 'New',
            'help' => 'Help',
        ];
        foreach ($tabs as $k => $label) {
            $u = add_query_arg(['page' => {{SVC_PAGE_SLUG_CODE}}, 'tab' => $k], admin_url('admin.php'));
            $active = ($tab === $k) ? ' nav-tab-active' : '';
            echo '<a class="nav-tab' . esc_attr($active) . '" href="' . esc_url($u) . '">' . esc_html($label) . '</a>';
        }
        ?>
    </h2>

    <?php if ($tab === 'help'): ?>
        <h2>Help</h2>
        <p><strong>Policy:</strong> nessun delta automatico DB/DDL in runtime. Allineamento manuale.</p>
        <h3>DDL (SQL)</h3>
        <pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:1200px;overflow:auto;"><?php echo esc_html($service->get_create_table_sql()); ?></pre>
        <h3>Mapping</h3>
        <pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:1200px;overflow:auto;"><?php echo esc_html(var_export($service->match_db_inp_type, true)); ?></pre>
    <?php elseif ($tab === 'list'): ?>
        <p><a class="button button-primary" href="<?php echo esc_url(add_query_arg(['tab' => 'edit'], $base_url)); ?>">New</a></p>
        <table class="widefat fixed striped" style="max-width:1200px;">
            <thead>
            <tr>
                <th style="width:80px;">ID</th>
                <th>Data</th>
                <?php foreach ($list_cols as $c): ?>
                    <th><?php echo esc_html((string) ($c['label'] ?? $c['db'] ?? '')); ?></th>
                <?php endforeach; ?>
                <th style="width:220px;">Azioni</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($records)): ?>
                <tr><td colspan="<?php echo esc_attr((string) (3 + count($list_cols))); ?>"><em>Nessun record.</em></td></tr>
            <?php else: ?>
                <?php foreach ($records as $r): ?>
                    <?php $id = (int) ($r['id'] ?? 0); ?>
                    <?php $date = (string) ($r['updated_at'] ?? $r['created_at'] ?? ''); ?>
                    <?php $edit_url = add_query_arg(['tab' => 'edit', 'record_id' => $id], $base_url); ?>
                    <tr>
                        <td><?php echo esc_html((string) $id); ?></td>
                        <td><?php echo esc_html($date); ?></td>
                        <?php foreach ($list_cols as $c): ?>
                            <?php $db = (string) ($c['db'] ?? ''); ?>
                            <td><?php echo esc_html($pbs_cell($db !== '' && is_array($r) && array_key_exists($db, $r) ? $r[$db] : '')); ?></td>
                        <?php endforeach; ?>
                        <td>
                            <a class="button" href="<?php echo esc_url($edit_url); ?>">Edit</a>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" data-pbs-confirm="Delete record #<?php echo esc_attr((string) $id); ?>?">
                                <input type="hidden" name="action" value="<?php echo esc_attr({{ACTION_DELETE_CODE}}); ?>"/>
                                <input type="hidden" name="record_id" value="<?php echo esc_attr((string) $id); ?>"/>
                                <?php wp_nonce_field({{ACTION_DELETE_CODE}}); ?>
                                <button class="button button-secondary" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    <?php else: ?>
        <h2><?php echo esc_html($editing ? 'Edit record' : 'New record'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:1200px;">
            <input type="hidden" name="action" value="<?php echo esc_attr({{ACTION_SAVE_CODE}}); ?>"/>
            <input type="hidden" name="record_id" value="<?php echo esc_attr((string) $record_id); ?>"/>
            <?php wp_nonce_field({{ACTION_SAVE_CODE}}); ?>

            <table class="form-table" role="presentation">
                <?php do_settings_sections({{SVC_PAGE_SLUG_CODE}}); ?>
            </table>

            <?php submit_button('Save'); ?>
        </form>
    <?php endif; ?>
</div>

<?php /* Centralized dialog for generated admin pages (no browser confirm()). */ ?>
<style>
    #pbs-confirm-overlay[aria-hidden="true"] { display:none; }
    #pbs-confirm-overlay{ position:fixed; inset:0; z-index:100000; background:rgba(0,0,0,.45); display:flex; align-items:center; justify-content:center; padding:18px; }
    #pbs-confirm-modal{ width:min(680px, 100%); background:#fff; border:1px solid #c3c4c7; border-radius:8px; box-shadow:0 10px 30px rgba(0,0,0,.25); }
    #pbs-confirm-head{ padding:14px 16px; border-bottom:1px solid #e2e4e7; }
    #pbs-confirm-title{ font-size:14px; font-weight:600; margin:0; }
    #pbs-confirm-body{ padding:14px 16px; }
    #pbs-confirm-message{ margin:0; white-space:pre-wrap; }
    #pbs-confirm-actions{ display:flex; justify-content:flex-end; gap:10px; padding:14px 16px; border-top:1px solid #e2e4e7; background:#f6f7f7; border-radius:0 0 8px 8px; }
</style>
<div id="pbs-confirm-overlay" aria-hidden="true">
    <div id="pbs-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="pbs-confirm-title">
        <div id="pbs-confirm-head"><p id="pbs-confirm-title">Conferma azione</p></div>
        <div id="pbs-confirm-body"><p id="pbs-confirm-message"></p></div>
        <div id="pbs-confirm-actions">
            <button type="button" class="button" id="pbs-confirm-cancel">Annulla</button>
            <button type="button" class="button button-primary" id="pbs-confirm-ok">Conferma</button>
        </div>
    </div>
</div>
<script>
(() => {
    "use strict";
    const overlay = document.getElementById("pbs-confirm-overlay");
    const messageEl = document.getElementById("pbs-confirm-message");
    const cancelBtn = document.getElementById("pbs-confirm-cancel");
    const okBtn = document.getElementById("pbs-confirm-ok");
    if (!overlay || !messageEl || !cancelBtn || !okBtn) return;
    let state = null;
    const close = (confirmed) => {
        const st = state; state = null;
        overlay.setAttribute("aria-hidden", "true"); overlay.style.display = "none";
        if (st && typeof st.restoreFocus === "function") st.restoreFocus();
        if (!st) return;
        if (confirmed) { if (typeof st.onConfirm === "function") st.onConfirm(); }
        else { if (typeof st.onCancel === "function") st.onCancel(); }
    };
    const open = (msg, onConfirm, onCancel, focusEl) => {
        messageEl.textContent = String(msg || "");
        const active = document.activeElement;
        overlay.setAttribute("aria-hidden", "false"); overlay.style.display = "flex";
        state = { onConfirm, onCancel, restoreFocus: () => { (focusEl && focusEl.focus) ? focusEl.focus() : (active && active.focus && active.focus()); } };
        okBtn.focus();
    };
    cancelBtn.addEventListener("click", () => close(false));
    okBtn.addEventListener("click", () => close(true));
    overlay.addEventListener("click", (e) => { if (e.target === overlay) close(false); });
    document.addEventListener("keydown", (e) => { if (overlay.getAttribute("aria-hidden") === "true") return; if (e.key === "Escape") { e.preventDefault(); close(false);} });
    document.addEventListener("submit", (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        const msg = form.getAttribute("data-pbs-confirm");
        if (!msg) return;
        if (form.getAttribute("data-pbs-confirmed") === "1") return;
        e.preventDefault();
        open(msg, () => {
            form.setAttribute("data-pbs-confirmed", "1");
            if (typeof form.requestSubmit === "function") form.requestSubmit();
            else form.submit();
        }, () => { form.removeAttribute("data-pbs-confirmed"); }, form);
    }, true);
})();
</script>
