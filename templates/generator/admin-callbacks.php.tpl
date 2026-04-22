<?php

declare(strict_types=1);

namespace {{GLIB_NS}}\Api\Callbacks;

/**
 * AdminCallbacks (light) — renderer field per Settings API.
 *
 * I valori vengono passati tramite `set_values()` dal servizio Admin.
 */
final class AdminCallbacks
{
    /** @var array<string,mixed> */
    private array $values = [];

    /**
     * @param array<string,mixed> $values
     */
    public function set_values(array $values): void
    {
        $this->values = $values;
    }

    /**
     * Callback Settings API.
     *
     * args:
     * - db_column (string)
     * - type (string)
     * - label (string)
     */
    public function inputField(array $args): void
    {
        $db = (string) ($args['db_column'] ?? '');
        $groupDb = (string) ($args['group_db'] ?? '');
        $memberKey = (string) ($args['member_key'] ?? '');
        $groupKind = (string) ($args['group_kind'] ?? '');
        $readonly = !empty($args['readonly']);

        if ($groupDb !== '' && $memberKey !== '') {
            $type = (string) ($args['type'] ?? 'text');
            $val = '';
            if (isset($this->values[$groupDb]) && is_array($this->values[$groupDb]) && isset($this->values[$groupDb][$memberKey])) {
                $val = (string) $this->values[$groupDb][$memberKey];
            }
            // PBS pre-fill (default/suggestion) baked in mapping at generation time.
            $prefill = $args['prefill'] ?? null;
            if ($val === '' && $prefill !== null && !is_array($prefill)) {
                $val = (string) $prefill;
            }
            $name = 'fields[' . $groupDb . '][' . $memberKey . ']';
            $id = 'pbs_' . sanitize_key($groupDb . '_' . $memberKey);

            if ($type === 'bool') {
                echo '<input type="hidden" name="' . esc_attr($name) . '" value="0"/>';
                echo '<label><input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1" ' . (!empty($val) && $val !== '0' ? 'checked' : '') . ($readonly ? ' disabled' : '') . '/> </label>';
                return;
            }

            if ($type === 'html') {
                if (function_exists('wp_editor')) {
                    wp_editor($val, $id, [
                        'textarea_name' => $name,
                        'textarea_rows' => 8,
                        'media_buttons' => false,
                    ]);
                    return;
                }
                echo '<textarea class="large-text" rows="8" name="' . esc_attr($name) . '"' . ($readonly ? ' readonly' : '') . '>' . esc_textarea($val) . '</textarea>';
                return;
            }

            if ($type === 'text_area') {
                echo '<textarea class="large-text" rows="5" name="' . esc_attr($name) . '"' . ($readonly ? ' readonly' : '') . '>' . esc_textarea($val) . '</textarea>';
                return;
            }

            $htmlType = match ($type) {
                'url' => 'url',
                'email' => 'email',
                default => 'text',
            };

            echo '<input class="regular-text" type="' . esc_attr($htmlType) . '" name="' . esc_attr($name) . '" value="' . esc_attr($val) . '"' . ($readonly ? ' readonly disabled' : '') . '/>';
            return;
        }

        if ($db === '') {
            return;
        }
        $type = (string) ($args['type'] ?? 'text');
        $val = isset($this->values[$db]) ? (string) $this->values[$db] : '';
        $prefill = $args['prefill'] ?? null;
        if ($val === '' && $prefill !== null && !is_array($prefill)) {
            $val = (string) $prefill;
        }
        $name = 'fields[' . $db . ']';

        if ($type === 'bool') {
            $id = 'pbs_' . sanitize_key($db);
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="0"/>';
            echo '<label><input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1" ' . (!empty($val) && $val !== '0' ? 'checked' : '') . ($readonly ? ' disabled' : '') . '/> </label>';
            return;
        }

        if ($type === 'html') {
            if (function_exists('wp_editor')) {
                wp_editor($val, 'pbs_' . sanitize_key($db), [
                    'textarea_name' => $name,
                    'textarea_rows' => 8,
                    'media_buttons' => false,
                ]);
                return;
            }
            echo '<textarea class="large-text" rows="8" name="' . esc_attr($name) . '"' . ($readonly ? ' readonly' : '') . '>' . esc_textarea($val) . '</textarea>';
            return;
        }

        if ($type === 'text_area') {
            echo '<textarea class="large-text" rows="5" name="' . esc_attr($name) . '"' . ($readonly ? ' readonly' : '') . '>' . esc_textarea($val) . '</textarea>';
            return;
        }

        $htmlType = match ($type) {
            'url' => 'url',
            'email' => 'email',
            default => 'text',
        };

        echo '<input class="regular-text" type="' . esc_attr($htmlType) . '" name="' . esc_attr($name) . '" value="' . esc_attr($val) . '"' . ($readonly ? ' readonly disabled' : '') . '/>';
    }

    /**
     * Render preview for complex components (groups).
     *
     * args:
     * - group_db
     * - group_kind
     * - group_label
     */
    public function componentPreviewField(array $args): void
    {
        $groupDb = (string) ($args['group_db'] ?? '');
        $groupKind = (string) ($args['group_kind'] ?? '');
        if ($groupDb === '' || $groupKind === '') {
            return;
        }

        if ($groupKind !== 'button') {
            return;
        }

        $vals = [];
        if (isset($this->values[$groupDb]) && is_array($this->values[$groupDb])) {
            $vals = (array) $this->values[$groupDb];
        }
        $prefill = is_array($args['prefill'] ?? null) ? (array) $args['prefill'] : [];
        if ($prefill) {
            // Record values override defaults.
            $vals = array_merge($prefill, $vals);
        }

        $title = (string) ($vals['title'] ?? '');
        $link = (string) ($vals['link'] ?? '');
        $targetBlank = !empty($vals['target_blank']) && (string) $vals['target_blank'] !== '0';
        $hiddenText = !empty($vals['hidden_text']) && (string) $vals['hidden_text'] !== '0';
        $icon = (string) ($vals['icon'] ?? '');

        $previewId = 'pbs_preview_' . sanitize_key($groupDb);
        $btnId = $previewId . '_btn';
        $iconId = $previewId . '_icon';

        $href = $link !== '' ? $link : '#';
        $tgt = $targetBlank ? ' target="_blank" rel="noopener"' : '';
        $label = $hiddenText ? '' : ($title !== '' ? $title : 'Button');

        echo '<div id="' . esc_attr($previewId) . '" style="padding:10px 12px; background:#fff; border:1px solid #ccd0d4; border-radius:4px;">';
        echo '<a id="' . esc_attr($btnId) . '" class="button button-primary" href="' . esc_url($href) . '"' . $tgt . '>';
        echo '<span id="' . esc_attr($iconId) . '" style="vertical-align:middle; margin-right:6px;">';
        if ($icon !== '') {
            if (str_starts_with($icon, 'dashicons-')) {
                echo '<span class="dashicons ' . esc_attr($icon) . '"></span>';
            } else {
                echo esc_html($icon);
            }
        }
        echo '</span>';
        echo '<span class="pbs-btn-label">' . esc_html($label) . '</span>';
        echo '</a>';
        echo '<div class="description" style="margin-top:8px;">Questo preview è solo UI BE: i dati reali sono salvati nel payload/DB secondo lo schema PBS.</div>';
        echo '</div>';

        // Live preview JS (best-effort).
        $idTitle = 'pbs_' . sanitize_key($groupDb . '_title');
        $idLink = 'pbs_' . sanitize_key($groupDb . '_link');
        $idTarget = 'pbs_' . sanitize_key($groupDb . '_target_blank');
        $idHidden = 'pbs_' . sanitize_key($groupDb . '_hidden_text');
        $idIcon = 'pbs_' . sanitize_key($groupDb . '_icon');

        echo '<script>(function(){';
        echo 'var btn=document.getElementById(' . wp_json_encode($btnId) . ');';
        echo 'if(!btn){return;}';
        echo 'var iconWrap=document.getElementById(' . wp_json_encode($iconId) . ');';
        echo 'var titleEl=document.getElementById(' . wp_json_encode($idTitle) . ');';
        echo 'var linkEl=document.getElementById(' . wp_json_encode($idLink) . ');';
        echo 'var targetEl=document.getElementById(' . wp_json_encode($idTarget) . ');';
        echo 'var hiddenEl=document.getElementById(' . wp_json_encode($idHidden) . ');';
        echo 'var iconEl=document.getElementById(' . wp_json_encode($idIcon) . ');';
        echo 'function val(el){return el?el.value:"";}';
        echo 'function checked(el){return !!(el && el.checked);}';
        echo 'function setLabel(txt){var s=btn.querySelector(".pbs-btn-label"); if(s){s.textContent=txt;}}';
        echo 'function renderIcon(raw){ if(!iconWrap){return;} raw=(raw||"").trim(); if(!raw){iconWrap.innerHTML=""; return;}';
        echo 'if(raw.indexOf("dashicons-")===0){iconWrap.innerHTML="<span class=\\"dashicons "+raw+"\\"></span>";} else {iconWrap.textContent=raw;} }';
        echo 'function update(){var t=val(titleEl); var u=val(linkEl); var tb=checked(targetEl); var ht=checked(hiddenEl);';
        echo 'btn.href = u?u:"#"; if(tb){btn.setAttribute("target","_blank"); btn.setAttribute("rel","noopener");} else {btn.removeAttribute("target"); btn.removeAttribute("rel");}';
        echo 'setLabel(ht?"":(t||"Button")); renderIcon(val(iconEl)); }';
        echo 'var els=[titleEl,linkEl,targetEl,hiddenEl,iconEl]; els.forEach(function(e){ if(!e){return;} e.addEventListener("input",update); e.addEventListener("change",update);});';
        echo 'update();';
        echo '})();</script>';
    }

    /**
     * Render a compact editor for complex components (single Settings API row).
     *
     * args:
     * - group_db
     * - group_kind
     * - group_label
     * - members (optional): array of member descriptors
     */
    public function componentEditorField(array $args): void
    {
        $groupDb = (string) ($args['group_db'] ?? '');
        $groupKind = (string) ($args['group_kind'] ?? '');
        $groupLabel = (string) ($args['group_label'] ?? $groupDb);
        if ($groupDb === '' || $groupKind === '') {
            return;
        }

        // MVP: only "button" gets a compact editor to avoid clutter.
        if ($groupKind !== 'button') {
            echo '<div class="description">Componente non supportato: ' . esc_html($groupKind) . '</div>';
            return;
        }

        // Prodotto finale: mostra solo l'anteprima.
        // La configurazione dettagliata (sotto-campi) non deve esistere nel plugin generato.
        $this->componentPreviewField([
            'group_db' => $groupDb,
            'group_kind' => $groupKind,
            'group_label' => $groupLabel,
            'prefill' => is_array($args['prefill'] ?? null) ? (array) $args['prefill'] : [],
        ]);
        echo '<p class="description" style="margin-top:8px;">Configurazione componente gestita in PBS (non nel plugin generato).</p>';
    }
}
