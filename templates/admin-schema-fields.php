<?php
/**
 * @var array<int,array<string,mixed>> $schemas
 * @var array<string,mixed>|null $schema
 * @var array<int,array<string,mixed>> $fields
 * @var array<string,mixed>|null $selectedField
 * @var array<string,mixed>|null $selectedMember
 * @var int $selectedMemberIndex
 * @var bool $acfAvailable
 * @var string $tab
 */

if (!defined('ABSPATH')) {
    exit;
}

$baseUrl = admin_url('admin.php?page=pbs-schema-fields');
$schemasUrl = admin_url('admin.php?page=pbs');
$schemaId = $schema ? (int) $schema['id'] : 0;

$selectedFlags = [];
if (is_array($selectedField)) {
    $selectedFlags = json_decode((string) ($selectedField['flags'] ?? ''), true) ?: [];
}

$tabs = [
    'list' => 'List',
    'new' => 'New',
    'edit' => 'Edit',
];
$tab = in_array($tab, array_keys($tabs), true) ? $tab : 'list';

$groups = [];
foreach ($fields as $ff) {
    $ffFlags = json_decode((string) ($ff['flags'] ?? ''), true) ?: [];
    if (!empty($ffFlags['group']) && is_array($ffFlags['group'])) {
        $groups[] = $ff;
    }
}
?>

<div class="wrap">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
        <h1 style="margin:0;">PBS — Dettaglio schema (campi)</h1>
        <div>
            <a class="button" href="<?php echo esc_url($schemasUrl); ?>">← Schemi</a>
        </div>
    </div>

    <?php if (!$schemas): ?>
        <p style="margin-top:16px;">Nessuno schema: crea uno schema in <a href="<?php echo esc_url($schemasUrl); ?>">Schemi → New</a>.</p>
    <?php else: ?>
        <?php if (!empty($_GET['pbs_err']) && $_GET['pbs_err'] === 'acf_no_fields'): ?>
            <div class="notice notice-error">
                <p><strong>Import ACF:</strong> nessun campo trovato.</p>
                <?php $dbg = get_transient('pbs_acf_import_debug_' . get_current_user_id()); ?>
                <?php if (is_array($dbg)): ?>
                    <p class="description" style="margin:6px 0 0;">
                        Debug: post_type=<code><?php echo esc_html((string) ($dbg['post_type'] ?? '')); ?></code>,
                        total_groups=<code><?php echo esc_html((string) ($dbg['total_groups'] ?? '')); ?></code>,
                        matched=<code><?php echo esc_html((string) count((array) ($dbg['matched'] ?? []))); ?></code>,
                        candidates=<code><?php echo esc_html((string) count((array) ($dbg['candidates'] ?? []))); ?></code>.
                    </p>
                    <?php if (!empty($dbg['acfe_post_type'])): ?>
                        <details style="margin-top:8px;">
                            <summary>Mostra configurazione ACFE del post type</summary>
                            <pre style="white-space:pre-wrap; background:#fff; border:1px solid #ccd0d4; padding:10px; max-height:280px; overflow:auto;"><?php echo esc_html(wp_json_encode($dbg['acfe_post_type'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
                        </details>
                    <?php endif; ?>
                    <?php if (!empty($dbg['acf_internal_post_type_post']) || !empty($dbg['acf_internal_post_type'])): ?>
                        <details style="margin-top:8px;">
                            <summary>Mostra definizione CPT (ACF Post Types)</summary>
                            <?php if (!empty($dbg['acf_internal_post_type_post'])): ?>
                                <p class="description">Post ACF: <code><?php echo esc_html(wp_json_encode($dbg['acf_internal_post_type_post'])); ?></code></p>
                            <?php endif; ?>
                            <?php if (!empty($dbg['acf_internal_post_type'])): ?>
                                <pre style="white-space:pre-wrap; background:#fff; border:1px solid #ccd0d4; padding:10px; max-height:280px; overflow:auto;"><?php echo esc_html(wp_json_encode($dbg['acf_internal_post_type'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
                            <?php else: ?>
                                <p class="description">Nessuna definizione trovata via <code>acf_get_acf_post_types()</code> per questo post type.</p>
                            <?php endif; ?>
                        </details>
                    <?php endif; ?>
                    <?php if (!empty($dbg['matched'])): ?>
                        <details style="margin-top:8px;">
                            <summary>Mostra field groups “matchati”</summary>
                            <ul style="margin-left:18px;">
                                <?php foreach ((array) $dbg['matched'] as $g): ?>
                                    <li>
                                        <code><?php echo esc_html((string) ($g['key'] ?? '')); ?></code>
                                        <?php echo esc_html((string) ($g['title'] ?? '')); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                    <?php if (!empty($dbg['candidates'])): ?>
                        <details style="margin-top:8px;">
                            <summary>Mostra field groups “candidati” (location)</summary>
                            <ul style="margin-left:18px;">
                                <?php foreach ((array) $dbg['candidates'] as $g): ?>
                                    <li>
                                        <code><?php echo esc_html((string) ($g['key'] ?? '')); ?></code>
                                        <?php echo esc_html((string) ($g['title'] ?? '')); ?>
                                        <?php if (!empty($g['location_rules'])): ?>
                                            <div class="description">
                                                <?php foreach ((array) $g['location_rules'] as $r): ?>
                                                    <code><?php echo esc_html((string) ($r['param'] ?? '')); ?></code>
                                                    <code><?php echo esc_html((string) ($r['operator'] ?? '')); ?></code>
                                                    <code><?php echo esc_html((string) ($r['value'] ?? '')); ?></code>
                                                    <br/>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                    <?php $layoutsDbg = get_transient('pbs_acf_import_layouts_' . get_current_user_id()); ?>
                    <?php if (is_array($layoutsDbg) && !empty($layoutsDbg['post_id']) && isset($layoutsDbg['layouts']) && is_array($layoutsDbg['layouts'])): ?>
                        <?php
                        $pid = (int) ($layoutsDbg['post_id'] ?? 0);
                        $title = $pid > 0 ? get_the_title($pid) : '';
                        ?>
                        <details style="margin-top:8px;">
                            <summary>Mostra filtro layout (post esempio)</summary>
                            <p class="description" style="margin:6px 0 0;">
                                Post: <code><?php echo esc_html($title !== '' ? ($title . ' #' . $pid) : ('#' . $pid)); ?></code>
                            </p>
                            <pre style="white-space:pre-wrap; background:#fff; border:1px solid #ccd0d4; padding:10px; max-height:220px; overflow:auto;"><?php echo esc_html(wp_json_encode($layoutsDbg['layouts'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
                        </details>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="description">Verifica che esista un Field Group assegnato al post type o all’archivio (ACF Extended) e che PBS stia usando il post type corretto.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($_GET['pbs_ok']) && $_GET['pbs_ok'] === 'acf_import'): ?>
            <div class="notice notice-success">
                <p><strong>Import ACF completato.</strong></p>
                <?php $layoutsDbg = get_transient('pbs_acf_import_layouts_' . get_current_user_id()); ?>
                <?php $sampleDbg = get_transient('pbs_acf_import_sample_' . get_current_user_id()); ?>
                <?php if (is_array($layoutsDbg) && !empty($layoutsDbg['post_id']) && !empty($layoutsDbg['layouts']) && is_array($layoutsDbg['layouts'])): ?>
                    <?php
                    $pid = (int) ($layoutsDbg['post_id'] ?? 0);
                    $title = $pid > 0 ? get_the_title($pid) : '';
                    ?>
                    <details style="margin-top:6px;">
                        <summary>Filtro layout (post esempio)</summary>
                        <p class="description" style="margin:6px 0 0;">
                            Post: <code><?php echo esc_html($title !== '' ? ($title . ' #' . $pid) : ('#' . $pid)); ?></code>
                        </p>
                        <pre style="white-space:pre-wrap; background:#fff; border:1px solid #ccd0d4; padding:10px; max-height:220px; overflow:auto;"><?php echo esc_html(wp_json_encode($layoutsDbg['layouts'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
                    </details>
                <?php endif; ?>
                <?php if (is_array($sampleDbg) && !empty($sampleDbg['post_id']) && is_array($sampleDbg['keys'] ?? null)): ?>
                    <?php
                    $pid2 = (int) ($sampleDbg['post_id'] ?? 0);
                    $title2 = $pid2 > 0 ? get_the_title($pid2) : '';
                    ?>
                    <details style="margin-top:6px;">
                        <summary>Debug valori ACF (post esempio)</summary>
                        <p class="description" style="margin:6px 0 0;">
                            Post: <code><?php echo esc_html($title2 !== '' ? ($title2 . ' #' . $pid2) : ('#' . $pid2)); ?></code>
                        </p>
                        <p class="description" style="margin:6px 0 0;">
                            Keys top-level: <code><?php echo esc_html(implode(', ', array_slice((array) ($sampleDbg['keys'] ?? []), 0, 60))); ?></code>
                        </p>
                        <?php if (!empty($sampleDbg['preview'])): ?>
                            <pre style="white-space:pre-wrap; background:#fff; border:1px solid #ccd0d4; padding:10px; max-height:220px; overflow:auto;"><?php echo esc_html(wp_json_encode($sampleDbg['preview'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
                        <?php endif; ?>
                    </details>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($_GET['pbs_ok']) && $_GET['pbs_ok'] === 'fields_cleared'): ?>
            <div class="notice notice-success"><p><strong>Campi rimossi.</strong></p></div>
        <?php endif; ?>
        <?php if (!empty($_GET['pbs_ok']) && $_GET['pbs_ok'] === 'group_split'): ?>
            <div class="notice notice-success"><p><strong>Split completato.</strong></p></div>
        <?php endif; ?>
        <?php if (!empty($_GET['pbs_ok']) && $_GET['pbs_ok'] === 'group_merged'): ?>
            <div class="notice notice-success"><p><strong>Merge completato.</strong></p></div>
        <?php endif; ?>
        <?php if (!empty($_GET['pbs_ok']) && $_GET['pbs_ok'] === 'group_created'): ?>
            <div class="notice notice-success"><p><strong>Gruppo creato.</strong></p></div>
        <?php endif; ?>
        <?php if (!empty($_GET['pbs_ok']) && $_GET['pbs_ok'] === 'member_saved'): ?>
            <div class="notice notice-success"><p><strong>Membro salvato.</strong></p></div>
        <?php endif; ?>
        <?php if (!empty($_GET['pbs_ok']) && $_GET['pbs_ok'] === 'member_copied'): ?>
            <div class="notice notice-success"><p><strong>Membro copiato fuori dal gruppo.</strong></p></div>
        <?php endif; ?>

        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-top:16px;">
            <input type="hidden" name="page" value="pbs-schema-fields" />
            <label for="pbs_schema_id"><strong>Schema:</strong></label>
            <select id="pbs_schema_id" name="schema_id">
                <?php foreach ($schemas as $s): ?>
                    <option value="<?php echo (int) $s['id']; ?>" <?php selected($schemaId === (int) $s['id']); ?>>
                        <?php echo esc_html($s['name'] . ' (' . $s['slug'] . ') v' . $s['schema_version']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php submit_button('Apri', 'secondary', 'submit', false); ?>
        </form>

        <?php if (!$schema): ?>
            <p>Seleziona uno schema.</p>
        <?php else: ?>
            <p class="description" style="margin-top:8px;">
                Qui gestisci solo i <strong>campi</strong>. I metadati dello schema (slug, sorgente, post type) stanno in <strong>Schemi</strong>.
            </p>
            <p class="description" style="margin-top:4px;">
                Post type per import ACF: <code><?php echo esc_html((string) (($schema['source_post_type'] ?? '') !== '' ? $schema['source_post_type'] : $schema['slug'])); ?></code>
            </p>

            <h2 class="nav-tab-wrapper" style="margin-top:16px;">
                <?php foreach ($tabs as $k => $label): ?>
                    <?php
                    $args = ['schema_id' => $schemaId, 'tab' => $k];
                    if ($k === 'edit' && $selectedField) {
                        $args['field_id'] = (int) $selectedField['id'];
                    }
                    ?>
                    <a class="nav-tab <?php echo $tab === $k ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg($args, $baseUrl)); ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <?php if ($tab === 'new' || $tab === 'edit'): ?>
                <?php
                $isEdit = ($tab === 'edit' && $selectedField);
                $isMemberEdit = ($isEdit && $selectedMemberIndex >= 0 && is_array($selectedMember));
                $mode = isset($_GET['mode']) ? sanitize_key((string) $_GET['mode']) : 'field';
                ?>
                <div style="margin-top:14px; max-width:980px;">
                    <h2 style="margin-top:0;">
                        <?php
                        if ($isMemberEdit) {
                            echo 'Membro (Edit)';
                        } elseif ($isEdit) {
                            echo 'Campo (Edit)';
                        } else {
                            echo $mode === 'group' ? 'Gruppo (New)' : 'Campo (New)';
                        }
                        ?>
                    </h2>

                    <?php if ($isEdit && !$isMemberEdit && !empty($selectedFlags['group']) && is_array($selectedFlags['group']) && !empty($selectedFlags['group']['members']) && is_array($selectedFlags['group']['members'])): ?>
                        <h3 style="margin-top:14px;">Members</h3>
                        <p class="description" style="margin-top:4px;">
                            Policy B: questo campo rappresenta un elemento complesso. Puoi estrarre (split) singoli sotto-campi per materializzarli come campi separati.
                        </p>
                        <table class="widefat striped" style="max-width:980px;">
                            <thead>
                            <tr>
                                <th>Key</th>
                                <th>Label</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ((array) $selectedFlags['group']['members'] as $idx => $m): ?>
                                <?php if (!is_array($m)) { continue; } ?>
                                <tr>
                                    <td><code><?php echo esc_html((string) ($m['key'] ?? '')); ?></code></td>
                                    <td><?php echo esc_html((string) ($m['label'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($m['field_type'] ?? 'text')); ?></td>
                                    <td>
                                        In group
                                    </td>
                                    <td style="text-align:right;">
                                        <a class="button" href="<?php echo esc_url(add_query_arg(['schema_id' => $schemaId, 'tab' => 'edit', 'field_id' => (int) $selectedField['id'], 'member_index' => (int) $idx], $baseUrl)); ?>">Edit</a>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                                <?php wp_nonce_field('pbs_group_member_split'); ?>
                                                <input type="hidden" name="action" value="pbs_group_member_split" />
                                                <input type="hidden" name="schema_id" value="<?php echo $schemaId; ?>" />
                                                <input type="hidden" name="field_id" value="<?php echo (int) $selectedField['id']; ?>" />
                                                <input type="hidden" name="member_index" value="<?php echo (int) $idx; ?>" />
                                                <input type="hidden" name="return_tab" value="edit" />
                                                <?php submit_button('Split', 'secondary', 'submit', false); ?>
                                            </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <?php if ($isMemberEdit): ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('pbs_group_member_update'); ?>
                            <input type="hidden" name="action" value="pbs_group_member_update" />
                            <input type="hidden" name="schema_id" value="<?php echo $schemaId; ?>" />
                            <input type="hidden" name="field_id" value="<?php echo (int) $selectedField['id']; ?>" />
                            <input type="hidden" name="member_index" value="<?php echo (int) $selectedMemberIndex; ?>" />

                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row"><label for="pbs_member_key">Key</label></th>
                                    <td><input name="member_key" id="pbs_member_key" type="text" class="regular-text" value="<?php echo esc_attr((string) ($selectedMember['key'] ?? '')); ?>" required /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="pbs_member_label">Label</label></th>
                                    <td><input name="member_label" id="pbs_member_label" type="text" class="regular-text" value="<?php echo esc_attr((string) ($selectedMember['label'] ?? '')); ?>" required /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="pbs_member_type">Type</label></th>
                                    <td>
                                        <?php $mft = (string) ($selectedMember['field_type'] ?? 'text'); ?>
                                        <select name="member_field_type" id="pbs_member_type">
                                            <option value="text" <?php selected($mft, 'text'); ?>>text</option>
                                            <option value="text_area" <?php selected($mft, 'text_area'); ?>>text_area</option>
                                            <option value="email" <?php selected($mft, 'email'); ?>>email</option>
                                            <option value="url" <?php selected($mft, 'url'); ?>>url</option>
                                            <option value="html" <?php selected($mft, 'html'); ?>>html</option>
                                            <option value="array_nested" <?php selected($mft, 'array_nested'); ?>>array_nested</option>
                                        </select>
                                    </td>
                                </tr>
                                <?php if (!empty($selectedMember['acf']) && is_array($selectedMember['acf'])): ?>
                                    <tr>
                                        <th scope="row">ACF</th>
                                        <td class="description">
                                            <?php if (!empty($selectedMember['acf']['key'])): ?>
                                                key: <code><?php echo esc_html((string) $selectedMember['acf']['key']); ?></code><br/>
                                            <?php endif; ?>
                                            <?php if (isset($selectedMember['acf']['path'])): ?>
                                                path: <code><?php echo esc_html(wp_json_encode($selectedMember['acf']['path'])); ?></code>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </table>

                            <?php submit_button('Salva membro'); ?>
                        </form>
                    <?php else: ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php if ($isEdit): ?>
                                <?php wp_nonce_field('pbs_field_update'); ?>
                                <input type="hidden" name="action" value="pbs_field_update" />
                                <input type="hidden" name="field_id" value="<?php echo (int) $selectedField['id']; ?>" />
                            <?php elseif ($mode === 'group'): ?>
                                <?php wp_nonce_field('pbs_group_create'); ?>
                                <input type="hidden" name="action" value="pbs_group_create" />
                            <?php else: ?>
                                <?php wp_nonce_field('pbs_field_add'); ?>
                                <input type="hidden" name="action" value="pbs_field_add" />
                            <?php endif; ?>
                            <input type="hidden" name="schema_id" value="<?php echo $schemaId; ?>" />

                        <table class="form-table" role="presentation">
                            <?php if ($isEdit && !empty($selectedFlags['acf']) && is_array($selectedFlags['acf'])): ?>
                                <tr>
                                    <th scope="row">ACF</th>
                                    <td class="description">
                                        <?php if (!empty($selectedFlags['acf']['key'])): ?>
                                            key: <code><?php echo esc_html((string) $selectedFlags['acf']['key']); ?></code><br/>
                                        <?php endif; ?>
                                        <?php if (isset($selectedFlags['acf']['path'])): ?>
                                            path: <code><?php echo esc_html(wp_json_encode($selectedFlags['acf']['path'])); ?></code>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <th scope="row"><label for="pbs_db_column">DB column</label></th>
                                <td><input name="db_column" id="pbs_db_column" type="text" class="regular-text" value="<?php echo esc_attr((string) ($selectedField['db_column'] ?? '')); ?>" required /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pbs_input_name">Input name</label></th>
                                <td><input name="input_name" id="pbs_input_name" type="text" class="regular-text" value="<?php echo esc_attr((string) ($selectedField['input_name'] ?? '')); ?>" required /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pbs_label">Label</label></th>
                                <td><input name="label" id="pbs_label" type="text" class="regular-text" value="<?php echo esc_attr((string) ($selectedField['label'] ?? '')); ?>" required /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pbs_field_type">Type</label></th>
                                <td>
                                    <?php $ft = (string) ($selectedField['field_type'] ?? 'text'); ?>
                                    <select name="field_type" id="pbs_field_type">
                                        <option value="text" <?php selected($ft, 'text'); ?>>text</option>
                                        <option value="text_area" <?php selected($ft, 'text_area'); ?>>text_area</option>
                                        <option value="email" <?php selected($ft, 'email'); ?>>email</option>
                                        <option value="url" <?php selected($ft, 'url'); ?>>url</option>
                                        <option value="html" <?php selected($ft, 'html'); ?>>html</option>
                                        <option value="array_nested" <?php selected($ft, 'array_nested'); ?>>array_nested</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Flags</th>
                                <td>
                                    <label><input type="checkbox" name="flag_backend" value="1" <?php checked(!empty($selectedFlags['backend'])); ?> /> backend</label>
                                    <label style="margin-left:12px;"><input type="checkbox" name="flag_hidden" value="1" <?php checked(!empty($selectedFlags['hidden'])); ?> /> hidden</label>
                                    <label style="margin-left:12px;"><input type="checkbox" name="flag_readonly" value="1" <?php checked(!empty($selectedFlags['readonly'])); ?> /> readonly</label>
                                    <label style="margin-left:12px;"><input type="checkbox" name="flag_serialized" value="1" <?php checked(!empty($selectedFlags['serialized'])); ?> /> serialized</label>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button($isEdit ? 'Salva campo' : ($mode === 'group' ? 'Crea gruppo' : 'Aggiungi campo')); ?>
                        </form>

                    <?php if ($isEdit): ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Eliminare il campo?');">
                            <?php wp_nonce_field('pbs_field_delete'); ?>
                            <input type="hidden" name="action" value="pbs_field_delete" />
                            <input type="hidden" name="schema_id" value="<?php echo $schemaId; ?>" />
                            <input type="hidden" name="field_id" value="<?php echo (int) $selectedField['id']; ?>" />
                            <?php submit_button('Elimina campo', 'delete'); ?>
                        </form>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div style="margin-top:16px;">
                    <div style="display:flex; gap:12px; align-items:center; margin:8px 0 12px; flex-wrap:wrap;">
                        <a class="button button-secondary" href="<?php echo esc_url(add_query_arg(['schema_id' => $schemaId, 'tab' => 'new', 'mode' => 'field', 'field_id' => 0], $baseUrl)); ?>">New field</a>
                        <a class="button button-secondary" href="<?php echo esc_url(add_query_arg(['schema_id' => $schemaId, 'tab' => 'new', 'mode' => 'group', 'field_id' => 0], $baseUrl)); ?>">New group</a>
                        <?php if ($acfAvailable): ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                <?php wp_nonce_field('pbs_schema_import_acf'); ?>
                                <input type="hidden" name="action" value="pbs_schema_import_acf" />
                                <input type="hidden" name="schema_id" value="<?php echo $schemaId; ?>" />
                                <input type="hidden" name="source_post_type" value="<?php echo esc_attr((string) ($schema['source_post_type'] ?? '')); ?>" />
                                <?php
                                $pt = (string) (($schema['source_post_type'] ?? '') !== '' ? $schema['source_post_type'] : $schema['slug']);
                                $posts = [];
                                $lastLayouts = get_transient('pbs_acf_import_layouts_' . get_current_user_id());
                                $selectedSamplePostId = is_array($lastLayouts) ? (int) ($lastLayouts['post_id'] ?? 0) : 0;
                                if ($pt !== '') {
                                    $posts = get_posts([
                                        'post_type' => $pt,
                                        'posts_per_page' => 50,
                                        'orderby' => 'date',
                                        'order' => 'DESC',
                                        'post_status' => 'any',
                                    ]);
                                }
                                ?>
                                <label class="description" for="pbs_sample_post_id">Post esempio:</label>
                                <select name="sample_post_id" id="pbs_sample_post_id">
                                    <option value="0" <?php selected($selectedSamplePostId === 0); ?>>— tutti i layout —</option>
                                    <?php foreach ((array) $posts as $p): ?>
                                        <option value="<?php echo (int) $p->ID; ?>" <?php selected($selectedSamplePostId === (int) $p->ID); ?>>
                                            <?php echo esc_html($p->post_title . ' (#' . $p->ID . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <label class="description" style="margin-left:6px;">
                                    <input type="checkbox" name="only_elements" value="1" checked />
                                    Solo campi contenuto (<code>elements</code>)
                                </label>
                                <label class="description" style="margin-left:6px;">
                                    <input type="checkbox" name="group_elements" value="1" checked />
                                    Raggruppa (policy B)
                                </label>
                                <?php submit_button('Importa ACF', 'secondary', 'submit', false); ?>
                            </form>
                        <?php endif; ?>
                    </div>

                    <table class="widefat striped">
                        <thead>
                        <tr>
                            <th>DB column</th>
                            <th>Input</th>
                            <th>Label</th>
                            <th>Type</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$fields): ?>
                            <tr><td colspan="5"><em>Nessun campo.</em></td></tr>
                        <?php else: ?>
                            <?php
                            foreach ($fields as $f):
                                $fflags = json_decode((string) ($f['flags'] ?? ''), true) ?: [];
                                $isGroup = !empty($fflags['group']) && is_array($fflags['group']);
                                $groupKind = $isGroup ? (string) ($fflags['group']['kind'] ?? '') : '';
                                ?>
                                <tr <?php echo $isGroup ? 'style="background:#f6f7f7;"' : ''; ?>>
                                    <td><code><?php echo esc_html((string) $f['db_column']); ?></code></td>
                                    <td><code><?php echo esc_html((string) $f['input_name']); ?></code></td>
                                    <td>
                                        <?php echo $isGroup ? '<strong>' . esc_html((string) ($f['label'] ?? '')) . '</strong>' : esc_html((string) ($f['label'] ?? '')); ?>
                                    </td>
                                    <td>
                                        <?php
                                        $ft = (string) ($f['field_type'] ?? '');
                                        if ($isGroup && $groupKind !== '' && $groupKind !== 'generic') {
                                            $ft .= ' (' . $groupKind . ')';
                                        }
                                        echo esc_html($ft);
                                        ?>
                                    </td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <a class="button" href="<?php echo esc_url(add_query_arg(['schema_id' => $schemaId, 'tab' => 'edit', 'field_id' => (int) $f['id']], $baseUrl)); ?>">Edit</a>
                                        <?php if (!$isGroup && $groups): ?>
                                            <button type="button" class="button pbs-open-group-dialog" data-field-id="<?php echo (int) $f['id']; ?>">Group</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php if ($isGroup): ?>
                                    <?php foreach ((array) ($fflags['group']['members'] ?? []) as $idx => $m): ?>
                                        <?php if (!is_array($m)) { continue; } ?>
                                        <tr>
                                            <td style="padding-left:28px;">↳ <code><?php echo esc_html((string) ($m['key'] ?? '')); ?></code></td>
                                            <td><code><?php echo esc_html((string) ($f['db_column'] ?? '')); ?>.<?php echo esc_html((string) ($m['key'] ?? '')); ?></code></td>
                                            <td><?php echo esc_html((string) ($m['label'] ?? '')); ?></td>
                                            <td><?php echo esc_html((string) ($m['field_type'] ?? 'text')); ?></td>
                                            <td style="text-align:right; white-space:nowrap;">
                                                <a class="button" href="<?php echo esc_url(add_query_arg(['schema_id' => $schemaId, 'tab' => 'edit', 'field_id' => (int) $f['id'], 'member_index' => (int) $idx], $baseUrl)); ?>">Edit</a>
                                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                                    <?php wp_nonce_field('pbs_group_member_copy'); ?>
                                                    <input type="hidden" name="action" value="pbs_group_member_copy" />
                                                    <input type="hidden" name="schema_id" value="<?php echo $schemaId; ?>" />
                                                    <input type="hidden" name="field_id" value="<?php echo (int) $f['id']; ?>" />
                                                    <input type="hidden" name="member_index" value="<?php echo (int) $idx; ?>" />
                                                    <input type="hidden" name="return_tab" value="list" />
                                                    <?php submit_button('Copy', 'secondary', 'submit', false); ?>
                                                </form>
                                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                                    <?php wp_nonce_field('pbs_group_member_split'); ?>
                                                    <input type="hidden" name="action" value="pbs_group_member_split" />
                                                    <input type="hidden" name="schema_id" value="<?php echo $schemaId; ?>" />
                                                    <input type="hidden" name="field_id" value="<?php echo (int) $f['id']; ?>" />
                                                    <input type="hidden" name="member_index" value="<?php echo (int) $idx; ?>" />
                                                    <input type="hidden" name="return_tab" value="list" />
                                                    <?php submit_button('Split', 'secondary', 'submit', false); ?>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>

                    <?php if ($groups): ?>
                        <div id="pbs-group-dialog" title="Aggiungi a gruppo" style="display:none;">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="pbs-group-dialog-form">
                                <?php wp_nonce_field('pbs_group_add_member'); ?>
                                <input type="hidden" name="action" value="pbs_group_add_member" />
                                <input type="hidden" name="schema_id" value="<?php echo $schemaId; ?>" />
                                <input type="hidden" name="field_id" id="pbs_group_dialog_field_id" value="0" />
                                <input type="hidden" name="return_tab" value="list" />
                                <p class="description">Seleziona il gruppo in cui inserire il campo.</p>
                                <select name="group_field_id" style="width:100%;">
                                    <?php foreach ($groups as $g): ?>
                                        <option value="<?php echo (int) $g['id']; ?>"><?php echo esc_html((string) ($g['label'] ?? $g['db_column'])); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description" style="margin-top:10px;">
                                    Non c'è il gruppo? <a href="<?php echo esc_url(add_query_arg(['schema_id' => $schemaId, 'tab' => 'new', 'mode' => 'group'], $baseUrl)); ?>">Crea un nuovo gruppo</a>.
                                </p>
                            </form>
                        </div>
                        <script>
                            jQuery(function($){
                                var $dlg = $("#pbs-group-dialog");
                                if (!$dlg.length) return;
                                $dlg.dialog({
                                    autoOpen: false,
                                    modal: true,
                                    width: 520,
                                    buttons: {
                                        "Annulla": function(){ $(this).dialog("close"); },
                                        "Group": function(){ $("#pbs-group-dialog-form").trigger("submit"); }
                                    }
                                });
                                $(".pbs-open-group-dialog").on("click", function(){
                                    var fid = $(this).data("field-id");
                                    $("#pbs_group_dialog_field_id").val(fid);
                                    $dlg.dialog("open");
                                });
                            });
                        </script>
                    <?php endif; ?>

                    <hr/>
                    <h2>Delete fields</h2>
                    <p class="description">Rimuove tutti i campi dello schema (mantiene lo schema e i suoi metadati in Schemi).</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Svuotare tutti i campi di questo schema?');">
                        <?php wp_nonce_field('pbs_schema_fields_clear'); ?>
                        <input type="hidden" name="action" value="pbs_schema_fields_clear" />
                        <input type="hidden" name="schema_id" value="<?php echo $schemaId; ?>" />
                        <?php submit_button('Delete fields', 'delete'); ?>
                    </form>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
