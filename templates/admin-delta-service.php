<?php

/** @var array<int,array<string,mixed>> $schemas */
/** @var array<int,array<string,mixed>> $plugins */
/** @var string $pluginSlug */
/** @var string $glibDir */
/** @var array<int,array<string,mixed>> $glibDirs */
/** @var array<int,array<string,mixed>> $services */
/** @var int $schemaId */
/** @var string $serviceKey */
/** @var array<string,mixed>|null $result */
/** @var array<string,mixed>|null $tablesNotice */
/** @var array<int,array<string,mixed>> $tables */

?>
<div class="wrap">
    <h1>PBS — Delta servizio</h1>
    <p>Adatta in modo mirato un servizio esistente in base allo schema PBS (solo campi aggiunti/tolti). Nessuna modifica automatica al DB: vengono generati warning e SQL suggeriti.</p>

<?php if (is_array($tablesNotice) && isset($tablesNotice['ok'], $tablesNotice['message'])): ?>
    <?php $cls = !empty($tablesNotice['ok']) ? 'notice-success' : 'notice-error'; ?>
    <div class="notice <?php echo esc_attr($cls); ?>"><p><strong><?php echo esc_html((string) $tablesNotice['message']); ?></strong></p></div>
<?php endif; ?>


    <h2>Selezione</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width: 1000px;">
        <?php wp_nonce_field('pbs_delta_analyze'); ?>
        <input type="hidden" name="action" value="pbs_delta_analyze" />

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="pbs_delta_schema">Schema</label></th>
                <td>
                    <select name="schema_id" id="pbs_delta_schema" required>
                        <option value="">— seleziona —</option>
                        <?php foreach ((array) $schemas as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>" <?php selected($schemaId > 0 && (int) $s['id'] === (int) $schemaId); ?>>
                                <?php echo esc_html((string) $s['name'] . ' (' . (string) $s['slug'] . ') v' . (string) $s['schema_version']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="pbs_delta_plugin">Plugin (target)</label></th>
                <td>
                    <select name="plugin_slug" id="pbs_delta_plugin" required>
                        <option value="">— seleziona —</option>
                        <?php foreach ((array) $plugins as $p): ?>
                            <option value="<?php echo esc_attr((string) $p['plugin_slug']); ?>" <?php selected((string) $p['plugin_slug'] === (string) $pluginSlug); ?>>
                                <?php echo esc_html((string) $p['plugin_name'] . ' — ' . (string) $p['plugin_slug']); ?>
                                <?php echo !empty($p['is_active']) ? ' (attivo)' : ' (disattivo)'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Consiglio: imposta un puntatore da <strong>Check gLib</strong> e poi usa qui lo stesso plugin.</p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="pbs_delta_glib">gLib dir</label></th>
                <td>
                    <select name="glib_dir" id="pbs_delta_glib" required>
                        <option value="">— seleziona —</option>
                        <?php foreach ((array) $glibDirs as $gd): ?>
                            <?php if (!is_array($gd)) continue; ?>
                            <option value="<?php echo esc_attr((string) ($gd['dir'] ?? '')); ?>" <?php selected((string) ($gd['dir'] ?? '') === (string) $glibDir); ?>>
                                <?php echo esc_html((string) ($gd['dir'] ?? '') . ' — ns ' . (string) ($gd['namespace'] ?? '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($glibDirs) && !empty($pluginSlug)): ?>
                        <p class="description" style="color:#b32d2e;">Nessuna gLib trovata per questo plugin (verifica in Check gLib).</p>
                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="pbs_delta_service">Servizio</label></th>
                <td>
                    <select name="service_key" id="pbs_delta_service" required>
                        <option value="">— seleziona —</option>
                        <?php foreach ((array) $services as $svc): ?>
                            <?php if (!is_array($svc)) continue; ?>
                            <option value="<?php echo esc_attr((string) ($svc['service_key'] ?? '')); ?>" <?php selected((string) ($svc['service_key'] ?? '') === (string) $serviceKey); ?>>
                                <?php echo esc_html((string) ($svc['group'] ?? '') . ' / ' . (string) ($svc['service_dir'] ?? '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($services) && !empty($glibDir)): ?>
                        <p class="description" style="color:#b32d2e;">Nessun servizio rilevato in questa gLib (Api/Services/*).</p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <?php submit_button('Analizza delta', 'primary'); ?>
    </form>

    <?php if (!empty($pluginSlug) && !empty($glibDir)): ?>
        <hr/>
        <h2>Maintenance (DB)</h2>
        <p class="description">Operazioni distruttive. PBS non esegue ALTER automatici: qui puoi droppare tabelle del plugin target prima di applicare un delta.</p>

        <?php if (empty($tables)): ?>
            <div class="notice notice-warning"><p><strong>Nessuna tabella rilevata.</strong> (Base*.php senza TABLE_KEY oppure servizi non trovati)</p></div>
        <?php else: ?>
            <table class="widefat fixed striped" style="max-width:1200px;">
                <thead>
                <tr>
                    <th style="width:260px;">Service</th>
                    <th>Table</th>
                    <th style="width:120px;">Rows</th>
                    <th style="width:120px;">Status</th>
                    <th style="width:160px;">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ((array) $tables as $t): ?>
                    <?php if (!is_array($t)) continue; ?>
                    <?php
                        $exists = !empty($t['exists']);
                        $status = $exists ? 'OK' : 'Missing';
                        $rows = array_key_exists('rows', $t) ? $t['rows'] : null;
                        $rowsText = is_int($rows) ? (string) $rows : '—';
                        $serviceLabel = (string) ($t['service_label'] ?? ($t['service_key'] ?? ''));
                        $tableName = (string) ($t['table_name'] ?? '');
                        $tableKey = (string) ($t['table_key'] ?? '');
                    ?>
                    <tr>
                        <td><?php echo esc_html($serviceLabel); ?></td>
                        <td><code><?php echo esc_html($tableName); ?></code></td>
                        <td><?php echo esc_html($rowsText); ?></td>
                        <td>
                            <?php if ($exists): ?>
                                <span style="display:inline-block;padding:2px 8px;border-radius:12px;background:#e7f7ed;color:#166534;font-weight:600;">OK</span>
                            <?php else: ?>
                                <span style="display:inline-block;padding:2px 8px;border-radius:12px;background:#fff4e5;color:#92400e;font-weight:600;">Missing</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                                $dropMsg = 'DROP TABLE ' . $tableName;
                                if (is_int($rows)) {
                                    $dropMsg .= ' (' . (string) $rows . ' rows)';
                                }
                                $dropMsg .= '?';
                            ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" data-pbs-confirm="<?php echo esc_attr($dropMsg); ?>">
                                <?php wp_nonce_field('pbs_delta_drop_table'); ?>
                                <input type="hidden" name="action" value="pbs_delta_drop_table"/>
                                <input type="hidden" name="schema_id" value="<?php echo (int) $schemaId; ?>"/>
                                <input type="hidden" name="plugin_slug" value="<?php echo esc_attr((string) $pluginSlug); ?>"/>
                                <input type="hidden" name="glib_dir" value="<?php echo esc_attr((string) $glibDir); ?>"/>
                                <input type="hidden" name="service_key" value="<?php echo esc_attr((string) $serviceKey); ?>"/>
                                <input type="hidden" name="table_key" value="<?php echo esc_attr($tableKey); ?>"/>
                                <button class="button button-secondary" type="submit">
                                    Drop
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (is_array($result)): ?>
        <hr/>
        <h2>Esito</h2>

        <?php if (!empty($result['ok'])): ?>
            <div class="notice notice-success"><p><strong>Analisi completata.</strong></p></div>
        <?php else: ?>
            <div class="notice notice-error"><p><strong>Analisi fallita.</strong></p></div>
        <?php endif; ?>

        <?php if (!empty($result['errors'])): ?>
            <p><strong>Errori</strong></p>
            <ul style="margin-left:20px;">
                <?php foreach ((array) $result['errors'] as $e): ?>
                    <li><?php echo esc_html((string) $e); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if (!empty($result['warnings'])): ?>
            <p><strong>Warnings</strong></p>
            <ul style="margin-left:20px;">
                <?php foreach ((array) $result['warnings'] as $w): ?>
                    <li><?php echo esc_html((string) $w); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php $r = (array) ($result['report'] ?? []); ?>
        <?php if (!empty($r)): ?>
            <h3>Report</h3>
            <ul>
                <li><strong>Base file:</strong> <code><?php echo esc_html((string) ($r['base_file'] ?? '')); ?></code></li>
                <li><strong>Tabella:</strong> <code><?php echo esc_html((string) ($r['table_name'] ?? '')); ?></code></li>
                <li><strong>TABLE_KEY:</strong> <code><?php echo esc_html((string) ($r['table_key'] ?? '')); ?></code> (atteso: <code><?php echo esc_html((string) ($r['expected_table_key'] ?? '')); ?></code>)</li>
                <li><strong>Delta:</strong> <?php echo esc_html(sprintf('%.0f%%', ((float) ($r['delta_pct'] ?? 0)) * 100)); ?></li>
            </ul>

            <h4>Campi</h4>
            <p><strong>Aggiunti</strong>: <?php echo esc_html((string) count((array) ($r['added'] ?? []))); ?></p>
            <pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:1200px;overflow:auto;"><?php echo esc_html(implode("\n", (array) ($r['added'] ?? []))); ?></pre>
            <p><strong>Rimossi</strong>: <?php echo esc_html((string) count((array) ($r['removed'] ?? []))); ?></p>
            <pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:1200px;overflow:auto;"><?php echo esc_html(implode("\n", (array) ($r['removed'] ?? []))); ?></pre>

            <?php if (!empty($r['changed'])): ?>
                <p><strong>Modificati (tipo/gruppo)</strong>: <?php echo esc_html((string) count((array) ($r['changed'] ?? []))); ?></p>
                <pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:1200px;overflow:auto;"><?php
                    $lines = [];
                    foreach ((array) ($r['changed'] ?? []) as $c) {
                        if (!is_array($c)) continue;
                        $db = (string) ($c['db'] ?? '');
                        $st = (string) ($c['schema_type'] ?? '');
                        $mt = (string) ($c['service_type'] ?? '');
                        $sk = (string) ($c['schema_group_kind'] ?? '');
                        $mk = (string) ($c['service_group_kind'] ?? '');
                        $lines[] = $db . ' | schema=' . $st . ($sk !== '' ? ('(' . $sk . ')') : '') . ' | service=' . $mt . ($mk !== '' ? ('(' . $mk . ')') : '');
                    }
                    echo esc_html(implode("\n", $lines));
                ?></pre>
            <?php endif; ?>

            <?php if (!empty($r['alter_sql'])): ?>
                <h4>SQL suggeriti (DB)</h4>
                <pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:1200px;overflow:auto;"><?php echo esc_html(implode("\n", (array) $r['alter_sql'])); ?></pre>
            <?php endif; ?>
            <?php if (empty($r['alter_sql']) && !empty($r['create_sql'])): ?>
                <h4>SQL suggerito (CREATE TABLE)</h4>
                <pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:1200px;overflow:auto;"><?php echo esc_html((string) $r['create_sql']); ?></pre>
            <?php endif; ?>

            <h3>Applica</h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('pbs_delta_apply'); ?>
                <input type="hidden" name="action" value="pbs_delta_apply" />
                <input type="hidden" name="schema_id" value="<?php echo (int) ($r['schema']['id'] ?? 0); ?>" />
                <input type="hidden" name="plugin_slug" value="<?php echo esc_attr((string) ($r['plugin_slug'] ?? '')); ?>" />
                <input type="hidden" name="glib_dir" value="<?php echo esc_attr((string) ($r['glib_dir'] ?? '')); ?>" />
                <input type="hidden" name="service_key" value="<?php echo esc_attr((string) ($r['service_key'] ?? '')); ?>" />
                <?php submit_button('Applica update (solo mapping)', 'secondary'); ?>
            </form>

            <?php if (!empty($r['applied'])): ?>
                <div class="notice notice-success"><p><strong>Update applicato.</strong></p></div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require PBS_PLUGIN_DIR . 'templates/pbs-confirm-dialog.php'; ?>

<script>
(() => {
    const schemaSel = document.getElementById('pbs_delta_schema');
    const pluginSel = document.getElementById('pbs_delta_plugin');
    const glibSel = document.getElementById('pbs_delta_glib');

    function setParam(url, key, value) {
        if (value === null || value === undefined || value === '') {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, value);
        }
    }

    function reloadWith(params) {
        const url = new URL(window.location.href);
        // reset esito precedente
        url.searchParams.delete('pbs_delta');
        // applica params
        Object.entries(params).forEach(([k, v]) => setParam(url, k, v));
        window.location.href = url.toString();
    }

    if (schemaSel) {
        schemaSel.addEventListener('change', () => {
            reloadWith({
                schema_id: schemaSel.value,
            });
        });
    }

    if (pluginSel) {
        pluginSel.addEventListener('change', () => {
            reloadWith({
                plugin_slug: pluginSel.value,
                glib_dir: '',
                service_key: '',
            });
        });
    }

    if (glibSel) {
        glibSel.addEventListener('change', () => {
            reloadWith({
                plugin_slug: pluginSel ? pluginSel.value : '',
                glib_dir: glibSel.value,
                service_key: '',
            });
        });
    }
})();
</script>
