<?php
/**
 * @var array<int,array<string,mixed>> $schemas
 * @var array<string,mixed>|null $schema
 * @var array<int,array<string,mixed>> $fields
 * @var array<string,mixed>|null $genResult
 */

if (!defined('ABSPATH')) {
    exit;
}

$pageUrl = admin_url('admin.php?page=pbs-generate');
?>

<div class="wrap">
    <h1>PBS — Genera plugin</h1>

    <p class="description">
        Generatore “leggero”: crea un nuovo plugin indipendente con namespace <code>gLib_fxx</code> incrementale e uno stub servizio basato sullo schema.
        Policy: nessuna migrazione/alter automatica in runtime nel plugin generato.
    </p>

    <h2>Seleziona schema</h2>
    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
        <input type="hidden" name="page" value="pbs-generate" />
        <select name="schema_id">
            <option value="0">—</option>
            <?php foreach ($schemas as $s): ?>
                <option value="<?php echo (int) $s['id']; ?>" <?php selected($schema && (int) $schema['id'] === (int) $s['id']); ?>>
                    <?php echo esc_html($s['name'] . ' (' . $s['slug'] . ') v' . $s['schema_version']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php submit_button('Apri', 'secondary', 'submit', false); ?>
    </form>

    <?php if (!$schema): ?>
        <p>Seleziona uno schema per vedere il riepilogo e generare il plugin.</p>
    <?php else: ?>
        <hr/>
        <h2>Riepilogo schema</h2>
        <ul>
            <li><strong>Nome:</strong> <?php echo esc_html($schema['name']); ?></li>
            <li><strong>Slug:</strong> <code><?php echo esc_html($schema['slug']); ?></code></li>
            <li><strong>Schema Version:</strong> <?php echo esc_html((string) $schema['schema_version']); ?></li>
            <li><strong>Campi:</strong> <?php echo esc_html((string) count($fields)); ?></li>
        </ul>

        <h2>Generazione</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('pbs_generate_plugin'); ?>
            <input type="hidden" name="action" value="pbs_generate_plugin" />
            <input type="hidden" name="schema_id" value="<?php echo (int) $schema['id']; ?>" />

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="pbs_plugin_slug">Plugin slug</label></th>
                    <td><input name="plugin_slug" id="pbs_plugin_slug" type="text" class="regular-text" value="<?php echo esc_attr('pbs-' . $schema['slug']); ?>" required /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="pbs_plugin_name">Plugin name</label></th>
                    <td><input name="plugin_name" id="pbs_plugin_name" type="text" class="regular-text" value="<?php echo esc_attr('PBS Gen — ' . $schema['name']); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="pbs_plugin_icon">Plugin icon</label></th>
                    <td>
                        <input name="plugin_icon" id="pbs_plugin_icon" type="text" class="regular-text" value="<?php echo esc_attr('dashicons-database'); ?>" />
                        <span id="pbs_plugin_icon_preview" style="display:inline-block;vertical-align:middle;margin-left:10px;"></span>
                        <p class="description">Esempio: <code>dashicons-database</code> oppure URL (svg/png).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="pbs_service_slug">Service slug</label></th>
                    <td><input name="service_slug" id="pbs_service_slug" type="text" class="regular-text" value="<?php echo esc_attr((string) $schema['slug']); ?>" required /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="pbs_service_name">Service name</label></th>
                    <td><input name="service_name" id="pbs_service_name" type="text" class="regular-text" value="<?php echo esc_attr((string) $schema['name']); ?>" required /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="pbs_service_menu_label">Menu label</label></th>
                    <td><input name="service_menu_label" id="pbs_service_menu_label" type="text" class="regular-text" value="<?php echo esc_attr((string) $schema['name']); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="pbs_service_group">Service group</label></th>
                    <td>
                        <select name="service_group" id="pbs_service_group">
                            <option value="shared">shared_services</option>
                            <option value="internal">internal_services</option>
                            <option value="control">control_services</option>
                        </select>
                    </td>
                </tr>
            </table>

            <?php submit_button('Genera plugin'); ?>
        </form>

        <?php if (is_array($genResult)): ?>
            <hr/>
            <h2>Esito</h2>
            <?php if (!empty($genResult['ok'])): ?>
                <div class="notice notice-success"><p><strong>Plugin generato.</strong></p></div>
                <p><strong>Output dir:</strong> <code><?php echo esc_html((string) ($genResult['output_dir'] ?? '')); ?></code></p>
                <p><strong>Namespace:</strong> <code><?php echo esc_html((string) ($genResult['glib_namespace'] ?? '')); ?></code></p>
                <?php if (!empty($genResult['warnings'])): ?>
                    <div class="notice notice-warning"><p><strong>Warning</strong></p>
                        <ul style="margin-left:20px;">
                            <?php foreach ((array) $genResult['warnings'] as $w): ?>
                                <li><?php echo esc_html((string) $w); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if (!empty($genResult['files'])): ?>
                    <p><strong>Files:</strong></p>
                    <ul style="margin-left:20px;">
                        <?php foreach ((array) $genResult['files'] as $f): ?>
                            <li><code><?php echo esc_html((string) $f); ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php else: ?>
                <div class="notice notice-error"><p><strong>Generazione fallita.</strong></p></div>
                <?php if (!empty($genResult['errors'])): ?>
                    <ul style="margin-left:20px;">
                        <?php foreach ((array) $genResult['errors'] as $e): ?>
                            <li><?php echo esc_html((string) $e); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if (!empty($genResult['warnings'])): ?>
                    <p><strong>Warning:</strong></p>
                    <ul style="margin-left:20px;">
                        <?php foreach ((array) $genResult['warnings'] as $w): ?>
                            <li><?php echo esc_html((string) $w); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
(() => {
    const input = document.getElementById('pbs_plugin_icon');
    const preview = document.getElementById('pbs_plugin_icon_preview');
    if (!input || !preview) return;

    function renderPreview(value) {
        const v = (value || '').trim();
        preview.innerHTML = '';

        if (!v) return;

        // URL / data URI => image preview
        if (/^(https?:)?\/\//i.test(v) || /^data:/i.test(v)) {
            const img = document.createElement('img');
            img.src = v;
            img.alt = 'icon preview';
            img.style.width = '20px';
            img.style.height = '20px';
            img.style.objectFit = 'contain';
            img.style.verticalAlign = 'middle';
            preview.appendChild(img);
            return;
        }

        // Dashicons class
        const span = document.createElement('span');
        span.className = 'dashicons ' + v;
        span.style.fontSize = '20px';
        span.style.width = '20px';
        span.style.height = '20px';
        span.style.verticalAlign = 'middle';
        preview.appendChild(span);
    }

    input.addEventListener('input', () => renderPreview(input.value));
    renderPreview(input.value);
})();
</script>
