<?php
/**
 * PBS centralized confirm dialog (no browser confirm()).
 *
 * Usage:
 * - Add `data-pbs-confirm="Message..."` to any <form> to intercept submit and ask confirmation.
 * - Add `data-pbs-confirm="Message..."` to any <a> to intercept click and ask confirmation.
 *
 * This component is intentionally self-contained (HTML+CSS+JS) and can be included in any admin template.
 */
?>

<style>
    #pbs-confirm-overlay[aria-hidden="true"] { display:none; }
    #pbs-confirm-overlay{
        position:fixed; inset:0; z-index:100000;
        background:rgba(0,0,0,.45);
        display:flex; align-items:center; justify-content:center;
        padding:18px;
    }
    #pbs-confirm-modal{
        width:min(680px, 100%);
        background:#fff;
        border:1px solid #c3c4c7;
        border-radius:8px;
        box-shadow:0 10px 30px rgba(0,0,0,.25);
    }
    #pbs-confirm-head{
        display:flex; align-items:center; justify-content:space-between;
        padding:14px 16px;
        border-bottom:1px solid #e2e4e7;
    }
    #pbs-confirm-title{ font-size:14px; font-weight:600; margin:0; }
    #pbs-confirm-body{ padding:14px 16px; }
    #pbs-confirm-message{ margin:0; white-space:pre-wrap; }
    #pbs-confirm-actions{
        display:flex; justify-content:flex-end; gap:10px;
        padding:14px 16px;
        border-top:1px solid #e2e4e7;
        background:#f6f7f7;
        border-radius:0 0 8px 8px;
    }
</style>

<div id="pbs-confirm-overlay" aria-hidden="true">
    <div id="pbs-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="pbs-confirm-title">
        <div id="pbs-confirm-head">
            <p id="pbs-confirm-title">Conferma azione</p>
        </div>
        <div id="pbs-confirm-body">
            <p id="pbs-confirm-message"></p>
        </div>
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

    if (!overlay || !messageEl || !cancelBtn || !okBtn) {
        return;
    }

    /** @type {null|{onConfirm:Function,onCancel:Function,restoreFocus:Function}} */
    let state = null;

    const close = (confirmed) => {
        const st = state;
        state = null;
        overlay.setAttribute("aria-hidden", "true");
        overlay.style.display = "none";
        if (st && typeof st.restoreFocus === "function") {
            st.restoreFocus();
        }
        if (!st) return;
        if (confirmed) {
            if (typeof st.onConfirm === "function") st.onConfirm();
        } else {
            if (typeof st.onCancel === "function") st.onCancel();
        }
    };

    const open = (msg, onConfirm, onCancel, focusEl) => {
        messageEl.textContent = String(msg || "");
        const active = document.activeElement;
        overlay.setAttribute("aria-hidden", "false");
        overlay.style.display = "flex";
        state = {
            onConfirm,
            onCancel,
            restoreFocus: () => {
                if (focusEl && typeof focusEl.focus === "function") {
                    focusEl.focus();
                    return;
                }
                if (active && typeof active.focus === "function") active.focus();
            }
        };
        okBtn.focus();
    };

    cancelBtn.addEventListener("click", () => close(false));
    okBtn.addEventListener("click", () => close(true));

    overlay.addEventListener("click", (e) => {
        if (e.target === overlay) {
            close(false);
        }
    });

    document.addEventListener("keydown", (e) => {
        if (overlay.getAttribute("aria-hidden") === "true") return;
        if (e.key === "Escape") {
            e.preventDefault();
            close(false);
        }
    });

    // Intercept submits for forms that declare a confirmation message.
    document.addEventListener("submit", (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        const msg = form.getAttribute("data-pbs-confirm");
        if (!msg) return;
        if (form.getAttribute("data-pbs-confirmed") === "1") return;

        e.preventDefault();
        open(msg, () => {
            form.setAttribute("data-pbs-confirmed", "1");
            if (typeof form.requestSubmit === "function") {
                form.requestSubmit();
            } else {
                form.submit();
            }
        }, () => {
            form.removeAttribute("data-pbs-confirmed");
        }, form);
    }, true);

    // Intercept clicks for links with confirmation.
    document.addEventListener("click", (e) => {
        const a = e.target && e.target.closest ? e.target.closest("a[data-pbs-confirm]") : null;
        if (!a) return;
        const msg = a.getAttribute("data-pbs-confirm");
        if (!msg) return;

        e.preventDefault();
        const href = a.getAttribute("href") || "";
        open(msg, () => { window.location.href = href; }, null, a);
    }, true);
})();
</script>

