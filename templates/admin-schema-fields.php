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
$selectedFlow = is_array($selectedFlags['flow'] ?? null) ? (array) $selectedFlags['flow'] : [];

$tabs = [
    'list' => 'List',
    'preview' => 'Preview',
    'schema' => 'Schema',
    'dll' => 'DLL',
    'new' => 'New',
    'edit' => 'Edit',
    'help' => 'Help',
];
$tab = in_array($tab, array_keys($tabs), true) ? $tab : 'list';

// Hide Edit tab unless a target field exists.
if ($tab === 'edit' && !$selectedField) {
    $tab = 'schema';
}

// If opened without explicit tab (e.g. from Schemi), default to Schema.
if (!isset($_GET['tab']) || (string) $_GET['tab'] === '') {
    $tab = 'schema';
}

$groups = [];
foreach ($fields as $ff) {
    $ffFlags = json_decode((string) ($ff['flags'] ?? ''), true) ?: [];
    if (!empty($ffFlags['group']) && is_array($ffFlags['group'])) {
        $groups[] = $ff;
    }
}

$pbsHelpTip = static function (string $text): string {
    return '<span class="dashicons dashicons-editor-help" style="vertical-align:middle; margin-left:4px; cursor:help; color:#646970;" title="' . esc_attr($text) . '"></span>';
};
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
        <?php if (!empty($_GET['pbs_err']) && $_GET['pbs_err'] === 'split_locked_kind'): ?>
            <div class="notice notice-error"><p><strong>Split non consentito per componenti tipizzati (text/button). Usa Copy.</strong></p></div>
        <?php endif; ?>

        <?php $dllNotice = get_transient('pbs_schema_dll_notice_' . get_current_user_id()); ?>
        <?php if (is_array($dllNotice) && isset($dllNotice['ok'], $dllNotice['message'])): ?>
            <?php delete_transient('pbs_schema_dll_notice_' . get_current_user_id()); ?>
            <?php $cls = !empty($dllNotice['ok']) ? 'notice-success' : 'notice-error'; ?>
            <div class="notice <?php echo esc_attr($cls); ?>">
                <p><strong><?php echo esc_html((string) $dllNotice['message']); ?></strong></p>
                <?php if (!empty($dllNotice['diff']) && is_array($dllNotice['diff'])): ?>
                    <?php $d = (array) $dllNotice['diff']; ?>
                    <?php if (!empty($d['added']) || !empty($d['orphaned'])): ?>
                        <ul style="margin-left:20px; list-style:disc;">
                            <?php if (!empty($d['added'])): ?>
                                <li>Aggiunte: <code><?php echo esc_html(implode(', ', (array) $d['added'])); ?></code></li>
                            <?php endif; ?>
                            <?php if (!empty($d['orphaned'])): ?>
                                <li>Orfane (non più nello schema): <code><?php echo esc_html(implode(', ', (array) $d['orphaned'])); ?></code></li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-top:16px;">
            <input type="hidden" name="page" value="pbs-schema-fields" />
            <label for="pbs_schema_id"><strong>Schema:</strong></label>
            <?php echo $pbsHelpTip('Seleziona lo schema PBS di cui vuoi gestire la struttura (campi/gruppi).'); ?>
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
                    <?php if ($k !== 'edit' || $selectedField): ?>
                        <a class="nav-tab <?php echo $tab === $k ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg($args, $baseUrl)); ?>">
                            <?php echo esc_html($label); ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </h2>

            <?php if ($tab === 'help'): ?>
                <div style="margin-top:14px; max-width:980px;">
                    <h2 style="margin-top:0;">Help</h2>

                    <p>
                        Questa pagina gestisce la <strong>struttura</strong> dello schema PBS (campi, gruppi, tipi e regole di flusso).
                        I valori/record del servizio vengono gestiti dal plugin generato, non qui.
                    </p>

                    <h3>Colonne principali</h3>
                    <ul style="list-style:disc; padding-left:20px;">
                        <li><strong>DB column</strong>: nome colonna nella DLL (tabella SQL) del servizio generato. Deve essere breve, stabile e “SQL friendly”.</li>
                        <li><strong>Input</strong>: nome logico input/payload (può coincidere con DB column). Serve per mapping e compatibilità; è il nome che vive nel payload/API.</li>
                        <li><strong>Label</strong>: etichetta descrittiva per UI/report. Non è un “valore” del record.</li>
                        <li><strong>Type</strong>: tipo PBS standard (text, url, html, array_nested...).</li>
                    </ul>

                    <h3>Gruppi e componenti</h3>
                    <p>
                        Un campo <code>array_nested</code> può essere:
                        <strong>generic</strong> (gruppo libero) oppure un <strong>componente tipizzato</strong> (es. <code>text</code>, <code>button</code>).
                        Un componente tipizzato preserva la struttura necessaria per essere riconosciuto e renderizzato nei template di generazione.
                    </p>
                    <p class="description">
                        Nota: nei plugin generati i componenti tipizzati vengono resi come “prodotto finale” (UI standard gLib). I sotto-campi descrittivi del componente non devono comparire come input separati.
                    </p>

                    <h3>Azioni</h3>
                    <ul style="list-style:disc; padding-left:20px;">
                        <li><strong>Edit</strong>: modifica un campo o un membro del gruppo.</li>
                        <li><strong>Split</strong>: estrae un membro dal gruppo e lo materializza come campo separato. Se stai lavorando con componenti tipizzati, lo split può rendere il gruppo non più rappresentabile come componente.</li>
                        <li><strong>Copy/Mirror</strong>: duplica un valore fuori dal gruppo per scopi SQL (indice/ricerca/payload leggero). Modalità consigliata: <strong>one-way</strong> (nested → mirror), cioè il mirror si aggiorna dal nested e non viceversa.</li>
                    </ul>

                    <h3>Flussi (payload/visibilità)</h3>
                    <p>
                        PBS può gestire flag per decidere se un campo deve essere incluso/esposto nei vari layer (BE/FE/AJAX).
                        Queste impostazioni servono a definire un payload “professionale” e controllato.
                    </p>
                </div>

            <?php elseif ($tab === 'preview'): ?>
                <?php
                $postId = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;
                $pt = (string) (($schema['source_post_type'] ?? '') !== '' ? $schema['source_post_type'] : $schema['slug']);
                $posts = $pt !== '' ? get_posts([
                    'post_type' => $pt,
                    'posts_per_page' => 100,
                    'orderby' => 'date',
                    'order' => 'DESC',
                    'post_status' => 'any',
                ]) : [];
                if ($postId <= 0 && !empty($posts) && is_object($posts[0])) {
                    $postId = (int) $posts[0]->ID;
                }
                $postTitle = $postId > 0 ? (string) get_the_title($postId) : '';

                // Live import preview (best-effort) from selected CPT instance.
                $previewComponents = [];
                if ($acfAvailable && $postId > 0 && $pt !== '') {
                    $importer = new \PBS\Services\ACFImporter();
                    $res = $importer->import_post_type_schema($pt, $postId, [
                        'only_elements' => true,
                        'group_elements' => true,
                    ]);
                    $previewComponents = (array) ($res['preview'] ?? []);
                }
                ?>
                <div style="margin-top:14px; max-width:1100px;">
                    <h2 style="margin-top:0;">Preview (istanza CPT → pre-fill PBS)</h2>
                    <p class="description">
                        Qui vedi e modifichi valori “di riferimento” (default/suggerimenti) per lo schema PBS.
                        I dati vengono letti dall’istanza CPT selezionata e possono essere salvati come pre-fill PBS (senza modificare ACF/CPT).
                    </p>

                    <?php if (!empty($_GET['pbs_ok']) && $_GET['pbs_ok'] === 'prefill_saved'): ?>
                        <div class="notice notice-success"><p><strong>Pre-fill salvato.</strong></p></div>
                    <?php endif; ?>

                    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin:10px 0 16px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                        <input type="hidden" name="page" value="pbs-schema-fields" />
                        <input type="hidden" name="schema_id" value="<?php echo (int) $schemaId; ?>" />
                        <input type="hidden" name="tab" value="preview" />
                        <label for="pbs_preview_post_id"><strong>Post:</strong></label>
                        <?php echo $pbsHelpTip('Seleziona l’istanza CPT da cui leggere i valori per il pre-fill.'); ?>
                        <select name="post_id" id="pbs_preview_post_id">
                            <?php foreach ((array) $posts as $p): ?>
                                <option value="<?php echo (int) $p->ID; ?>" <?php selected($postId === (int) $p->ID); ?>>
                                    <?php echo esc_html($p->post_title . ' (#' . $p->ID . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php submit_button('Apri', 'secondary', 'submit', false); ?>
                    </form>

                    <?php if ($postId <= 0): ?>
                        <div class="notice notice-warning"><p>Nessun post trovato per il post type <code><?php echo esc_html($pt); ?></code>.</p></div>
                    <?php else: ?>
                        <div class="notice notice-info" style="margin-top:10px;">
                            <p>Istanza: <code><?php echo esc_html($postTitle !== '' ? ($postTitle . ' #' . $postId) : ('#' . $postId)); ?></code></p>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:14px;">
                        <?php wp_nonce_field('pbs_schema_prefill_save'); ?>
                        <input type="hidden" name="action" value="pbs_schema_prefill_save" />
                        <input type="hidden" name="schema_id" value="<?php echo (int) $schemaId; ?>" />
                        <input type="hidden" name="post_id" value="<?php echo (int) $postId; ?>" />

                    <?php foreach ($fields as $f): ?>
                        <?php
                        if (!is_array($f)) {
                            continue;
                        }
                        $fflags = json_decode((string) ($f['flags'] ?? ''), true) ?: [];
                        $isGroup = !empty($fflags['group']) && is_array($fflags['group']);
                        $groupKind = $isGroup ? sanitize_key((string) ($fflags['group']['kind'] ?? 'generic')) : '';
                        $groupSplitLocked = $isGroup && in_array($groupKind, ['text', 'button'], true);
                        $db = (string) ($f['db_column'] ?? '');
                        $label = (string) ($f['label'] ?? $db);
                        $prefillGroup = is_array($fflags['prefill'] ?? null) ? (array) $fflags['prefill'] : [];
                        $prefillSimple = !$isGroup && !is_array($fflags['prefill'] ?? null) ? (string) ($fflags['prefill'] ?? '') : '';
                        ?>

                        <div style="background:#fff; border:1px solid #ccd0d4; border-radius:6px; padding:14px; margin:12px 0;">
                            <div style="display:flex; align-items:center; justify-content:space-between; gap:10px;">
                                <div>
                                    <strong style="font-size:14px;"><?php echo esc_html($label !== '' ? $label : $db); ?></strong>
                                    <?php if ($isGroup): ?>
                                        <span class="description" style="margin-left:8px;">(component: <code><?php echo esc_html($groupKind !== '' ? $groupKind : 'generic'); ?></code>)</span>
                                    <?php else: ?>
                                        <span class="description" style="margin-left:8px;">(field: <code><?php echo esc_html((string) ($f['field_type'] ?? 'text')); ?></code>)</span>
                                    <?php endif; ?>
                                </div>
                                <div style="white-space:nowrap;">
                                    <a class="button" href="<?php echo esc_url(add_query_arg(['schema_id' => $schemaId, 'tab' => 'edit', 'field_id' => (int) $f['id']], $baseUrl)); ?>">Edit</a>
                                    <?php
                                    $deleteUrlPreview = wp_nonce_url(
                                        add_query_arg(
                                            [
                                                'action' => 'pbs_field_delete',
                                                'schema_id' => (int) $schemaId,
                                                'field_id' => (int) $f['id'],
                                                'return_tab' => 'preview',
                                                'post_id' => (int) $postId,
                                            ],
                                            admin_url('admin-post.php')
                                        ),
                                        'pbs_field_delete'
                                    );
                                    ?>
                                    <a class="button button-link-delete" href="<?php echo esc_url($deleteUrlPreview); ?>" data-pbs-confirm="<?php echo esc_attr('Eliminare questo campo dallo schema?'); ?>">Elimina</a>
                                </div>
                            </div>

                            <?php if ($isGroup && $groupKind === 'button'): ?>
                                <?php
                                $pc = $previewComponents[$db] ?? null;
                                if (!is_array($pc)) {
                                    foreach ($previewComponents as $tmp) {
                                        if (is_array($tmp) && (string) ($tmp['kind'] ?? '') === 'button') {
                                            $pc = $tmp;
                                            break;
                                        }
                                    }
                                }
                                $sample = is_array($pc) ? (array) ($pc['sample'] ?? []) : [];
                                $icon = (string) (($prefillGroup['icon'] ?? null) ?? ($sample['icon'] ?? ''));
                                $title = (string) (($prefillGroup['title'] ?? null) ?? ($sample['title'] ?? ''));
                                $link = ($prefillGroup['link'] ?? null) ?? ($sample['link'] ?? '');
                                $url = '';
                                $urlLabel = '';
                                if (is_array($link)) {
                                    $url = (string) ($link['url'] ?? '');
                                    $urlLabel = (string) ($link['title'] ?? '');
                                } else {
                                    $url = (string) $link;
                                }
                                $targetBlank = !empty(($prefillGroup['target_blank'] ?? null) ?? $sample['target_blank']) && (string) (($prefillGroup['target_blank'] ?? null) ?? $sample['target_blank']) !== '0';
                                $hiddenText = !empty(($prefillGroup['hidden_text'] ?? null) ?? $sample['hidden_text']) && (string) (($prefillGroup['hidden_text'] ?? null) ?? $sample['hidden_text']) !== '0';
                                ?>
                                <div style="margin-top:12px;">
                                    <div style="display:grid; grid-template-columns: 1fr 1fr 2fr; gap:12px; align-items:end;">
                                        <div>
                                            <label class="description">Icon</label>
                                            <input class="regular-text" type="text" name="prefill[<?php echo esc_attr($db); ?>][icon]" value="<?php echo esc_attr($icon); ?>" placeholder="dashicons-..." />
                                        </div>
                                        <div>
                                            <label class="description">Titolo</label>
                                            <input class="regular-text" type="text" name="prefill[<?php echo esc_attr($db); ?>][title]" value="<?php echo esc_attr($title); ?>" />
                                        </div>
                                        <div>
                                            <label class="description">Link</label>
                                            <input class="regular-text" type="url" name="prefill[<?php echo esc_attr($db); ?>][link]" value="<?php echo esc_attr($url); ?>" placeholder="https://..." />
                                            <?php if ($urlLabel !== ''): ?>
                                                <div class="description">label: <code><?php echo esc_html($urlLabel); ?></code></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div style="margin-top:10px; display:flex; gap:18px; align-items:center;">
                                        <label class="description"><input type="checkbox" name="prefill[<?php echo esc_attr($db); ?>][target_blank]" value="1" <?php checked($targetBlank); ?> /> target blank</label>
                                        <label class="description"><input type="checkbox" name="prefill[<?php echo esc_attr($db); ?>][hidden_text]" value="1" <?php checked($hiddenText); ?> /> nascondi testo</label>
                                    </div>
                                    <div style="margin-top:12px;">
                                        <div class="description" style="margin-bottom:6px;">Anteprima bottone</div>
                                        <?php
                                        $btnLabel = $hiddenText ? '' : ($title !== '' ? $title : 'Button');
                                        $href = $url !== '' ? $url : '#';
                                        ?>
                                        <a class="button button-primary" href="<?php echo esc_url($href); ?>" <?php echo $targetBlank ? 'target="_blank" rel="noopener"' : ''; ?>>
                                            <?php if ($icon !== '' && str_starts_with($icon, 'dashicons-')): ?>
                                                <span class="dashicons <?php echo esc_attr($icon); ?>" style="vertical-align:middle; margin-right:6px;"></span>
                                            <?php elseif ($icon !== ''): ?>
                                                <span style="margin-right:6px;"><?php echo esc_html($icon); ?></span>
                                            <?php endif; ?>
                                            <?php echo esc_html($btnLabel); ?>
                                        </a>
                                        <div class="description" style="margin-top:8px;">Questa preview usa i valori del post esempio (se disponibili). La struttura del componente resta definita dallo schema PBS.</div>
                                    </div>
                                </div>

                            <?php elseif ($isGroup && in_array($groupKind, ['text', 'title_text'], true)): ?>
                                <?php
                                $pc = $previewComponents[$db] ?? null;
                                if (!is_array($pc)) {
                                    foreach ($previewComponents as $tmp) {
                                        if (is_array($tmp) && in_array((string) ($tmp['kind'] ?? ''), ['text', 'title_text'], true)) {
                                            $pc = $tmp;
                                            break;
                                        }
                                    }
                                }
                                $sample = is_array($pc) ? (array) ($pc['sample'] ?? []) : [];
                                $tagTitle = (string) (($prefillGroup['tag_title'] ?? null) ?? ($sample['tag_title'] ?? ''));
                                $boxtext = !empty(($prefillGroup['boxtext'] ?? null) ?? $sample['boxtext']) && (string) (($prefillGroup['boxtext'] ?? null) ?? $sample['boxtext']) !== '0';
                                $title = (string) (($prefillGroup['title'] ?? null) ?? ($sample['title'] ?? ''));
                                $p = (string) (($prefillGroup['p'] ?? null) ?? ($sample['p'] ?? ''));
                                ?>
                                <div style="margin-top:12px;">
                                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                                        <div>
                                            <label class="description">Tag title</label>
                                            <input class="regular-text" type="text" name="prefill[<?php echo esc_attr($db); ?>][tag_title]" value="<?php echo esc_attr($tagTitle); ?>" />
                                        </div>
                                        <div>
                                            <label class="description">Boxtext</label><br/>
                                            <label class="description"><input type="checkbox" name="prefill[<?php echo esc_attr($db); ?>][boxtext]" value="1" <?php checked($boxtext); ?> /> abilita boxtext</label>
                                        </div>
                                        <div style="grid-column: 1 / -1;">
                                            <label class="description">Titolo</label>
                                            <input class="large-text" type="text" name="prefill[<?php echo esc_attr($db); ?>][title]" value="<?php echo esc_attr($title); ?>" />
                                        </div>
                                        <div style="grid-column: 1 / -1;">
                                            <label class="description">Descrizione (HTML)</label>
                                            <textarea class="large-text" rows="7" name="prefill[<?php echo esc_attr($db); ?>][p]"><?php echo esc_textarea($p); ?></textarea>
                                        </div>
                                    </div>
                                    <div class="description" style="margin-top:8px;">Preview basata su post esempio (se disponibile). Per operazioni PBS (mirror/split) usa le azioni sui membri.</div>
                                </div>

                            <?php elseif (!$isGroup): ?>
                                <?php
                                $ft = (string) ($f['field_type'] ?? 'text');
                                ?>
                                <div style="margin-top:12px;">
                                    <?php if ($ft === 'html'): ?>
                                        <textarea class="large-text" rows="7" name="prefill_field[<?php echo esc_attr($db); ?>]" placeholder="(default/suggerimento)"><?php echo esc_textarea($prefillSimple); ?></textarea>
                                    <?php elseif ($ft === 'text_area'): ?>
                                        <textarea class="large-text" rows="5" name="prefill_field[<?php echo esc_attr($db); ?>]" placeholder="(default/suggerimento)"><?php echo esc_textarea($prefillSimple); ?></textarea>
                                    <?php elseif ($ft === 'bool'): ?>
                                        <label class="description"><input type="checkbox" name="prefill_field[<?php echo esc_attr($db); ?>]" value="1" <?php checked($prefillSimple === '1'); ?> /> bool</label>
                                    <?php else: ?>
                                        <input class="regular-text" type="text" name="prefill_field[<?php echo esc_attr($db); ?>]" value="<?php echo esc_attr($prefillSimple); ?>" placeholder="(default/suggerimento)" />
                                    <?php endif; ?>
                                    <div class="description" style="margin-top:8px;">Campo PBS libero: non deriva da CPT/ACF, vive nello schema PBS.</div>
                                </div>
                            <?php else: ?>
                                <div class="description" style="margin-top:12px;">
                                    Nessuna preview dedicata per questo gruppo. Usa la tabella Members in Edit.
                                </div>
                            <?php endif; ?>

                            <?php if ($isGroup && !empty($fflags['group']['members']) && is_array($fflags['group']['members'])): ?>
                                <details style="margin-top:12px;">
                                    <summary>Azioni membri (schema)</summary>
                                    <table class="widefat striped" style="margin-top:10px;">
                                        <thead>
                                        <tr>
                                            <th>Member</th>
                                            <th>Type</th>
                                            <th style="text-align:right;">Actions</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ((array) $fflags['group']['members'] as $idx => $m): ?>
                                            <?php if (!is_array($m)) { continue; } ?>
                                            <?php
                                            $copyUrl = wp_nonce_url(
                                                add_query_arg(
                                                    [
                                                        'action' => 'pbs_group_member_copy',
                                                        'schema_id' => $schemaId,
                                                        'field_id' => (int) $f['id'],
                                                        'member_index' => (int) $idx,
                                                        'return_tab' => 'preview',
                                                    ],
                                                    admin_url('admin-post.php')
                                                ),
                                                'pbs_group_member_copy'
                                            );
                                            $splitUrl = wp_nonce_url(
                                                add_query_arg(
                                                    [
                                                        'action' => 'pbs_group_member_split',
                                                        'schema_id' => $schemaId,
                                                        'field_id' => (int) $f['id'],
                                                        'member_index' => (int) $idx,
                                                        'return_tab' => 'preview',
                                                    ],
                                                    admin_url('admin-post.php')
                                                ),
                                                'pbs_group_member_split'
                                            );
                                            ?>
                                            <tr>
                                                <td><code><?php echo esc_html($db . '.' . (string) ($m['key'] ?? '')); ?></code> — <?php echo esc_html((string) ($m['label'] ?? '')); ?></td>
                                                <td><?php echo esc_html((string) ($m['field_type'] ?? 'text')); ?></td>
                                                <td style="text-align:right; white-space:nowrap;">
                                                    <a class="button" href="<?php echo esc_url(add_query_arg(['schema_id' => $schemaId, 'tab' => 'edit', 'field_id' => (int) $f['id'], 'member_index' => (int) $idx], $baseUrl)); ?>">Edit</a>
                                                    <a class="button button-secondary" href="<?php echo esc_url($copyUrl); ?>">Copy</a>
                                                    <?php if (!$groupSplitLocked): ?>
                                                        <a class="button button-secondary" href="<?php echo esc_url($splitUrl); ?>">Split</a>
                                                    <?php else: ?>
                                                        <span class="description" title="<?php echo esc_attr('Split disabilitato per componenti tipizzati (text/button). Usa Copy per creare un mirror fuori dal gruppo.'); ?>" style="margin-left:8px;">Split (locked)</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </details>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php submit_button('Salva pre-fill (PBS)'); ?>
                    </form>
                </div>

            <?php elseif ($tab === 'new' || $tab === 'edit'): ?>
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
                        <?php
                        $groupKind = sanitize_key((string) ($selectedFlags['group']['kind'] ?? ''));
                        $membersForPreview = (array) ($selectedFlags['group']['members'] ?? []);
                        ?>
                        <h3 style="margin-top:14px;">
                            Componente (preview)
                            <?php echo $pbsHelpTip('Rappresentazione “CPT-like” del componente importato. È solo una preview di schema: qui non si inseriscono valori record.'); ?>
                        </h3>

                        <div style="background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:12px; max-width:980px;">
                            <?php if ($groupKind === 'button'): ?>
                                <div style="margin-bottom:10px;"><strong>Bottone</strong></div>
                                <div style="display:grid; grid-template-columns: 1fr 1fr 2fr; gap:12px; align-items:end;">
                                    <div>
                                        <label class="description">Icon</label>
                                        <input class="regular-text" type="text" value="" placeholder="dashicons-..." disabled />
                                    </div>
                                    <div>
                                        <label class="description">Titolo</label>
                                        <input class="regular-text" type="text" value="" placeholder="Titolo bottone" disabled />
                                    </div>
                                    <div>
                                        <label class="description">Link</label>
                                        <input class="regular-text" type="url" value="" placeholder="https://..." disabled />
                                    </div>
                                </div>
                                <div style="margin-top:10px; display:flex; gap:18px; align-items:center;">
                                    <label class="description"><input type="checkbox" disabled /> target blank</label>
                                    <label class="description"><input type="checkbox" disabled /> nascondi testo</label>
                                </div>
                                <div class="description" style="margin-top:10px;">Il plugin generato renderizza il bottone come “prodotto finale”. Le opzioni dettagliate vengono gestite in PBS (schema/policy/mirror).</div>
                            <?php elseif (in_array($groupKind, ['text', 'title_text'], true)): ?>
                                <div style="margin-bottom:10px;"><strong>Titolo e testo</strong></div>
                                <div style="display:grid; grid-template-columns: 1fr; gap:10px;">
                                    <div>
                                        <label class="description">Titolo</label>
                                        <input class="regular-text" type="text" value="" placeholder="Titolo" disabled />
                                    </div>
                                    <div>
                                        <label class="description">Boxtext (flag)</label>
                                        <label class="description"><input type="checkbox" disabled /> abilita boxtext</label>
                                    </div>
                                    <div>
                                        <label class="description">Descrizione (HTML)</label>
                                        <textarea class="large-text" rows="6" disabled placeholder="Testo formattato..."></textarea>
                                    </div>
                                </div>
                                <div class="description" style="margin-top:10px;">Questo layout è una preview della struttura del componente. Per gestire i membri usa la tabella “Members” qui sotto.</div>
                            <?php else: ?>
                                <div class="description">
                                    Nessuna preview dedicata per kind <code><?php echo esc_html($groupKind !== '' ? $groupKind : 'generic'); ?></code>.
                                    (Viene usata la gestione standard tramite Members.)
                                </div>
                            <?php endif; ?>

                            <?php if ($membersForPreview): ?>
                                <details style="margin-top:10px;">
                                    <summary>Mostra membri (debug)</summary>
                                    <pre style="white-space:pre-wrap; background:#f6f7f7; border:1px solid #ccd0d4; padding:10px; max-height:220px; overflow:auto;"><?php echo esc_html(wp_json_encode($membersForPreview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
                                </details>
                            <?php endif; ?>
                        </div>

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
                                    <th scope="row">
                                        <label for="pbs_member_key">Key</label>
                                        <?php echo $pbsHelpTip('Chiave del membro dentro il gruppo (es. title, link). Deve rimanere stabile per riconoscere il componente.'); ?>
                                    </th>
                                    <td><input name="member_key" id="pbs_member_key" type="text" class="regular-text" value="<?php echo esc_attr((string) ($selectedMember['key'] ?? '')); ?>" required /></td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="pbs_member_label">Label</label>
                                        <?php echo $pbsHelpTip('Etichetta UI del membro (solo descrittiva).'); ?>
                                    </th>
                                    <td><input name="member_label" id="pbs_member_label" type="text" class="regular-text" value="<?php echo esc_attr((string) ($selectedMember['label'] ?? '')); ?>" required /></td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="pbs_member_type">Type</label>
                                        <?php echo $pbsHelpTip('Tipo PBS del membro. Deve riflettere il tipo “standard” previsto dal componente (es. url, html, bool).'); ?>
                                    </th>
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
                                <th scope="row">
                                    <label for="pbs_db_column">DB column</label>
                                    <?php echo $pbsHelpTip('Nome colonna SQL nella DLL del servizio. Breve e stabile (es. text, button, user_id).'); ?>
                                </th>
                                <td><input name="db_column" id="pbs_db_column" type="text" class="regular-text" value="<?php echo esc_attr((string) ($selectedField['db_column'] ?? '')); ?>" required /></td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="pbs_input_name">Input name</label>
                                    <?php echo $pbsHelpTip('Nome logico usato nel payload/API. Può coincidere con DB column; è quello che “vive” nell’integrazione con la DLL.'); ?>
                                </th>
                                <td><input name="input_name" id="pbs_input_name" type="text" class="regular-text" value="<?php echo esc_attr((string) ($selectedField['input_name'] ?? '')); ?>" required /></td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="pbs_label">Label</label>
                                    <?php echo $pbsHelpTip('Etichetta UI/report. Non è un valore del record.'); ?>
                                </th>
                                <td><input name="label" id="pbs_label" type="text" class="regular-text" value="<?php echo esc_attr((string) ($selectedField['label'] ?? '')); ?>" required /></td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="pbs_field_type">Type</label>
                                    <?php echo $pbsHelpTip('Tipo PBS standard. Per componenti/gruppi usare array_nested.'); ?>
                                </th>
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
                                <th scope="row">
                                    Flags
                                    <?php echo $pbsHelpTip('Flag tecnici PBS. Esempio: hidden (non mostrare in UI), readonly (solo lettura), serialized (salvato come JSON).'); ?>
                                </th>
                                <td>
                                    <label><input type="checkbox" name="flag_backend" value="1" <?php checked(!empty($selectedFlags['backend'])); ?> /> backend</label>
                                    <label style="margin-left:12px;"><input type="checkbox" name="flag_hidden" value="1" <?php checked(!empty($selectedFlags['hidden'])); ?> /> hidden</label>
                                    <label style="margin-left:12px;"><input type="checkbox" name="flag_readonly" value="1" <?php checked(!empty($selectedFlags['readonly'])); ?> /> readonly</label>
                                    <label style="margin-left:12px;"><input type="checkbox" name="flag_serialized" value="1" <?php checked(!empty($selectedFlags['serialized'])); ?> /> serialized</label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    Flows
                                    <?php echo $pbsHelpTip('Controllo professionale del payload/visibilità. Serve per definire cosa entra/esce dai layer (BE/FE/AJAX).'); ?>
                                </th>
                                <td>
                                    <?php
                                    $flowBe = array_key_exists('be', $selectedFlow) ? (bool) $selectedFlow['be'] : true;
                                    $flowIn = array_key_exists('payload_in', $selectedFlow) ? (bool) $selectedFlow['payload_in'] : true;
                                    $flowOut = array_key_exists('payload_out', $selectedFlow) ? (bool) $selectedFlow['payload_out'] : true;
                                    ?>
                                    <label title="<?php echo esc_attr('Mostra il campo nell’UI BE del servizio generato (Settings API).'); ?>">
                                        <input type="checkbox" name="flag_flow_be" value="1" <?php checked($flowBe); ?> />
                                        BE
                                    </label>
                                    <label style="margin-left:12px;" title="<?php echo esc_attr('Accetta questo campo in input da payload/API (es. salvataggio da FE/AJAX).'); ?>">
                                        <input type="checkbox" name="flag_flow_payload_in" value="1" <?php checked($flowIn); ?> />
                                        payload_in
                                    </label>
                                    <label style="margin-left:12px;" title="<?php echo esc_attr('Espone questo campo in output nel payload/API (es. fetch per FE/AJAX).'); ?>">
                                        <input type="checkbox" name="flag_flow_payload_out" value="1" <?php checked($flowOut); ?> />
                                        payload_out
                                    </label>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button($isEdit ? 'Salva campo' : ($mode === 'group' ? 'Crea gruppo' : 'Aggiungi campo')); ?>
                        </form>

                    <?php if ($isEdit): ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-pbs-confirm="<?php echo esc_attr('Eliminare il campo?'); ?>">
                            <?php wp_nonce_field('pbs_field_delete'); ?>
                            <input type="hidden" name="action" value="pbs_field_delete" />
                            <input type="hidden" name="schema_id" value="<?php echo $schemaId; ?>" />
                            <input type="hidden" name="field_id" value="<?php echo (int) $selectedField['id']; ?>" />
                            <?php submit_button('Elimina campo', 'delete'); ?>
                        </form>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php elseif ($tab === 'list'): ?>
                <?php
                $pt = (string) (($schema['source_post_type'] ?? '') !== '' ? $schema['source_post_type'] : $schema['slug']);
                $posts = $pt !== '' ? get_posts([
                    'post_type' => $pt,
                    'posts_per_page' => 100,
                    'orderby' => 'date',
                    'order' => 'DESC',
                    'post_status' => 'any',
                ]) : [];
                ?>
                <div style="margin-top:16px; max-width:1100px;">
                    <h2 style="margin-top:0;">Istanze (CPT)</h2>
                    <p class="description">
                        Seleziona un post (istanza) per aprire la vista <strong>Preview</strong> e gestire il pre-fill PBS.
                    </p>
                    <table class="widefat striped">
                        <thead>
                        <tr>
                            <th>Titolo</th>
                            <th>ID</th>
                            <th>Data</th>
                            <th style="text-align:right;">Azioni</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$posts): ?>
                            <tr><td colspan="4"><em>Nessuna istanza trovata per <code><?php echo esc_html($pt); ?></code>.</em></td></tr>
                        <?php else: ?>
                            <?php foreach ((array) $posts as $p): ?>
                                <tr>
                                    <td><?php echo esc_html((string) $p->post_title); ?></td>
                                    <td><code><?php echo (int) $p->ID; ?></code></td>
                                    <td><?php echo esc_html((string) $p->post_date); ?></td>
                                    <td style="text-align:right;">
                                        <a class="button button-primary" href="<?php echo esc_url(add_query_arg(['schema_id' => $schemaId, 'tab' => 'preview', 'post_id' => (int) $p->ID], $baseUrl)); ?>">Apri</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($tab === 'schema'): ?>
                <div style="margin-top:16px;">
                    <div style="display:flex; gap:12px; align-items:center; margin:8px 0 12px; flex-wrap:wrap;">
                        <a class="button button-secondary" title="<?php echo esc_attr('Crea un nuovo campo semplice (non raggruppato).'); ?>" href="<?php echo esc_url(add_query_arg(['schema_id' => $schemaId, 'tab' => 'new', 'mode' => 'field', 'field_id' => 0], $baseUrl)); ?>">New field</a>
                        <a class="button button-secondary" title="<?php echo esc_attr('Crea un nuovo gruppo (array_nested). Può essere un gruppo generico o un componente tipizzato.'); ?>" href="<?php echo esc_url(add_query_arg(['schema_id' => $schemaId, 'tab' => 'new', 'mode' => 'group', 'field_id' => 0], $baseUrl)); ?>">New group</a>
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
                                if ($selectedSamplePostId <= 0 && !empty($posts) && is_object($posts[0])) {
                                    $selectedSamplePostId = (int) $posts[0]->ID;
                                }
                                ?>
                                <label class="description" for="pbs_sample_post_id">Post:</label>
                                <?php echo $pbsHelpTip('Seleziona un post (istanza) per determinare i layout realmente usati e importare solo quelli.'); ?>
                                <select name="sample_post_id" id="pbs_sample_post_id" required>
                                    <?php foreach ((array) $posts as $p): ?>
                                        <option value="<?php echo (int) $p->ID; ?>" <?php selected($selectedSamplePostId === (int) $p->ID); ?>>
                                            <?php echo esc_html($p->post_title . ' (#' . $p->ID . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <label class="description" style="margin-left:6px;">
                                    <input type="checkbox" name="only_elements" value="1" checked />
                                    Solo campi contenuto (<code>elements</code>)
                                    <?php echo $pbsHelpTip('Importa solo i campi di contenuto (evita impostazioni/struttura troppo verbosa).'); ?>
                                </label>
                                <label class="description" style="margin-left:6px;">
                                    <input type="checkbox" name="group_elements" value="1" checked />
                                    Raggruppa (policy B)
                                    <?php echo $pbsHelpTip('Raggruppa membri di componenti complessi in un singolo campo array_nested tipizzato (es. text, button).'); ?>
                                </label>
                                <?php submit_button('Importa ACF', 'secondary', 'submit', false); ?>
                            </form>
                        <?php endif; ?>
                    </div>

                    <table class="widefat striped">
                        <thead>
                        <tr>
                            <th>DB column<?php echo $pbsHelpTip('Colonna SQL nella DLL del servizio.'); ?></th>
                            <th>Input<?php echo $pbsHelpTip('Nome logico nel payload/API.'); ?></th>
                            <th>Label<?php echo $pbsHelpTip('Etichetta descrittiva (UI/report).'); ?></th>
                            <th>Type<?php echo $pbsHelpTip('Tipo PBS standard. Per gruppi/componenti: array_nested.'); ?></th>
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
                                $groupSplitLocked = $isGroup && in_array(sanitize_key($groupKind), ['text', 'button'], true);
                                $isMirror = !empty($fflags['mirror']) && is_array($fflags['mirror']);
                                $mirrorPath = '';
                                if ($isMirror) {
                                    $mirrorPath = (string) (($fflags['mirror']['source']['path'] ?? '') ?: (($fflags['mirror']['source']['group_db'] ?? '') . '.' . ($fflags['mirror']['source']['member_key'] ?? '')));
                                }
                                ?>
                                <tr <?php echo $isGroup ? 'style="background:#f6f7f7;"' : ''; ?>>
                                    <td><code><?php echo esc_html((string) $f['db_column']); ?></code></td>
                                    <td><code><?php echo esc_html((string) $f['input_name']); ?></code></td>
                                    <td>
                                        <?php
                                        $lbl = $isGroup ? '<strong>' . esc_html((string) ($f['label'] ?? '')) . '</strong>' : esc_html((string) ($f['label'] ?? ''));
                                        echo $lbl;
                                        if ($isMirror && $mirrorPath !== '') {
                                            echo '<div class="description" style="margin-top:2px;">mirror: <code>' . esc_html($mirrorPath) . '</code>' . $pbsHelpTip('Mirror one-way: questo campo è derivato dal nested e serve per SQL/indice/ricerca. Di default non appare nei form BE.') . '</div>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $ft = (string) ($f['field_type'] ?? '');
                                        if ($isGroup && $groupKind !== '' && $groupKind !== 'generic') {
                                            $ft .= ' (' . $groupKind . ')';
                                        }
                                        if ($isMirror) {
                                            $ft .= ' (mirror)';
                                        }
                                        echo esc_html($ft);
                                        ?>
                                    </td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <a class="button" title="<?php echo esc_attr('Modifica campo/gruppo.'); ?>" href="<?php echo esc_url(add_query_arg(['schema_id' => $schemaId, 'tab' => 'edit', 'field_id' => (int) $f['id']], $baseUrl)); ?>">Edit</a>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" data-pbs-confirm="<?php echo esc_attr($isGroup ? 'Eliminare questo gruppo e tutti i suoi membri?' : 'Eliminare questo campo?'); ?>">
                                            <?php wp_nonce_field('pbs_field_delete'); ?>
                                            <input type="hidden" name="action" value="pbs_field_delete" />
                                            <input type="hidden" name="schema_id" value="<?php echo (int) $schemaId; ?>" />
                                            <input type="hidden" name="field_id" value="<?php echo (int) $f['id']; ?>" />
                                            <input type="hidden" name="return_tab" value="schema" />
                                            <?php submit_button('Elimina', 'delete', 'submit', false); ?>
                                        </form>
                                        <?php if (!$isGroup && $groups): ?>
                                            <button type="button" class="button pbs-open-group-dialog" title="<?php echo esc_attr('Inserisci questo campo in un gruppo esistente.'); ?>" data-field-id="<?php echo (int) $f['id']; ?>">Group</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php if ($isGroup): ?>
                                    <?php foreach ((array) ($fflags['group']['members'] ?? []) as $idx => $m): ?>
                                        <?php if (!is_array($m)) { continue; } ?>
                                        <tr class="pbs-members-row pbs-members-of-<?php echo (int) $f['id']; ?>">
                                            <td style="padding-left:28px;">↳ <code><?php echo esc_html((string) ($m['key'] ?? '')); ?></code></td>
                                            <td><code><?php echo esc_html((string) ($f['db_column'] ?? '')); ?>.<?php echo esc_html((string) ($m['key'] ?? '')); ?></code></td>
                                            <td><?php echo esc_html((string) ($m['label'] ?? '')); ?></td>
                                            <td><?php echo esc_html((string) ($m['field_type'] ?? 'text')); ?></td>
                                            <td style="text-align:right; white-space:nowrap;">
                                                    <a class="button" title="<?php echo esc_attr('Modifica solo questo membro del gruppo.'); ?>" href="<?php echo esc_url(add_query_arg(['schema_id' => $schemaId, 'tab' => 'edit', 'field_id' => (int) $f['id'], 'member_index' => (int) $idx], $baseUrl)); ?>">Edit</a>
                                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                                        <?php wp_nonce_field('pbs_group_member_copy'); ?>
                                                        <input type="hidden" name="action" value="pbs_group_member_copy" />
                                                        <input type="hidden" name="schema_id" value="<?php echo $schemaId; ?>" />
                                                        <input type="hidden" name="field_id" value="<?php echo (int) $f['id']; ?>" />
                                                        <input type="hidden" name="member_index" value="<?php echo (int) $idx; ?>" />
                                                    <input type="hidden" name="return_tab" value="schema" />
                                                        <?php submit_button('Copy', 'secondary', 'submit', false, ['title' => 'Crea una copia “mirror” fuori dal gruppo (consigliato: one-way nested → mirror) per SQL/indice/ricerca.']); ?>
                                                    </form>
                                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                                        <?php wp_nonce_field('pbs_group_member_split'); ?>
                                                        <input type="hidden" name="action" value="pbs_group_member_split" />
                                                        <input type="hidden" name="schema_id" value="<?php echo $schemaId; ?>" />
                                                        <input type="hidden" name="field_id" value="<?php echo (int) $f['id']; ?>" />
                                                        <input type="hidden" name="member_index" value="<?php echo (int) $idx; ?>" />
                                                    <input type="hidden" name="return_tab" value="schema" />
                                                        <?php if ($groupSplitLocked): ?>
                                                            <?php submit_button('Split (locked)', 'secondary', 'submit', false, ['disabled' => true, 'title' => 'Split disabilitato per componenti tipizzati (text/button). Usa Copy per creare un mirror fuori dal gruppo.']); ?>
                                                        <?php else: ?>
                                                            <?php submit_button('Split', 'secondary', 'submit', false, ['title' => 'Estrae il membro dal gruppo (rompe l’integrità del componente tipizzato). Usa quando non vuoi più mantenere la rappresentazione del componente.']); ?>
                                                        <?php endif; ?>
                                                    </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>

                    <script>
                        // Keep group members visible by default (preserve ordering/context).
                        // Future: add per-group collapse/expand without losing hierarchy.
                    </script>

                    <?php if ($groups): ?>
                        <div id="pbs-group-dialog" title="Aggiungi a gruppo" style="display:none;">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="pbs-group-dialog-form">
                                <?php wp_nonce_field('pbs_group_add_member'); ?>
                                <input type="hidden" name="action" value="pbs_group_add_member" />
                                <input type="hidden" name="schema_id" value="<?php echo $schemaId; ?>" />
                                <input type="hidden" name="field_id" id="pbs_group_dialog_field_id" value="0" />
                                <input type="hidden" name="return_tab" value="schema" />
                                <p class="description">Seleziona il gruppo in cui inserire il campo.<?php echo $pbsHelpTip('Utile per costruire componenti/gruppi complessi (array_nested) e tenere i campi ordinati.'); ?></p>
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
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-pbs-confirm="<?php echo esc_attr('Svuotare tutti i campi di questo schema?'); ?>">
                        <?php wp_nonce_field('pbs_schema_fields_clear'); ?>
                        <input type="hidden" name="action" value="pbs_schema_fields_clear" />
                        <input type="hidden" name="schema_id" value="<?php echo $schemaId; ?>" />
                        <?php submit_button('Delete fields', 'delete'); ?>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($tab === 'dll' && $schema): ?>
                <?php
                $dllSvc = new \PBS\Services\SchemaDll();
                $preview = $dllSvc->get_preview($schemaId);
                $effective = $dllSvc->get_effective($schemaId, $fields);
                $cols = (array) ($effective['columns'] ?? []);
                $hasPreview = !empty($effective['has_preview']);
                ?>

                <div style="margin-top:16px; max-width:1200px;">
                    <h2 style="margin-top:0;">PREVIEW DB SCHEMA (DLL)</h2>
                    <p class="description">
                        Questa tab centralizza la DLL attesa per il servizio generato. È una preview editabile (non SQL) usata da generazione e delta.
                        <?php if (!$hasPreview): ?>
                            <strong>Modalità fallback:</strong> preview non inizializzata (solo lettura). Premi <strong>Init/Merge</strong> per crearla.
                        <?php endif; ?>
                    </p>

                    <div style="margin:10px 0; display:flex; gap:10px; align-items:center;">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                            <?php wp_nonce_field('pbs_schema_dll_merge'); ?>
                            <input type="hidden" name="action" value="pbs_schema_dll_merge" />
                            <input type="hidden" name="schema_id" value="<?php echo (int) $schemaId; ?>" />
                            <?php submit_button($hasPreview ? 'Merge DLL (on-demand)' : 'Init DLL (on-demand)', 'primary', 'submit', false); ?>
                        </form>

                        <?php if ($hasPreview): ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" data-pbs-confirm="<?php echo esc_attr('Rimuovere la DLL preview? (torna in fallback)'); ?>">
                                <?php wp_nonce_field('pbs_schema_dll_delete'); ?>
                                <input type="hidden" name="action" value="pbs_schema_dll_delete" />
                                <input type="hidden" name="schema_id" value="<?php echo (int) $schemaId; ?>" />
                                <?php submit_button('Delete preview', 'delete', 'submit', false); ?>
                            </form>
                        <?php endif; ?>
                    </div>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
                        <?php wp_nonce_field('pbs_schema_dll_save'); ?>
                        <input type="hidden" name="action" value="pbs_schema_dll_save" />
                        <input type="hidden" name="schema_id" value="<?php echo (int) $schemaId; ?>" />

                        <table class="widefat striped">
                            <thead>
                            <tr>
                                <th>DB column<?php echo $pbsHelpTip('Nome colonna finale nella tabella (DLL).'); ?></th>
                                <th>SQL type<?php echo $pbsHelpTip('Tipo SQL (es. LONGTEXT, VARCHAR(255), TINYINT(1)).'); ?></th>
                                <th style="text-align:center;">NULL<?php echo $pbsHelpTip('Se abilitato, colonna NULL.'); ?></th>
                                <th style="text-align:center;">On<?php echo $pbsHelpTip('Se disabilitato, la colonna NON viene considerata dalla DLL effettiva (generazione/delta).'); ?></th>
                                <th>Source<?php echo $pbsHelpTip('Origine: system / schema / manual / orphan.'); ?></th>
                                <th style="text-align:right;">Azioni</th>
                                <th style="text-align:center;">List<?php echo $pbsHelpTip('Mostra in tabellina BE del generato.'); ?></th>
                                <th style="text-align:center;">Edit<?php echo $pbsHelpTip('Mostra nel form Edit/New BE del generato.'); ?></th>
                                <th style="text-align:center;">Editable<?php echo $pbsHelpTip('Campo modificabile nel form BE del generato.'); ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($cols as $c): ?>
                                <?php if (!is_array($c)) continue; ?>
                                <?php
                                $db = (string) ($c['db_column'] ?? '');
                                $k = sanitize_key($db);
                                $ui = is_array($c['ui'] ?? null) ? (array) $c['ui'] : ['list' => false, 'edit' => true, 'editable' => true];
                                $src = (string) ($c['source'] ?? '');
                                $isSys = in_array($db, ['id', 'created_at', 'updated_at'], true);
                                $isRemovable = $hasPreview && in_array($src, ['manual', 'orphan'], true) && !$isSys;
                                $enabled = array_key_exists('enabled', $c) ? (bool) $c['enabled'] : true;
                                ?>
                                <tr <?php echo !$enabled ? 'style="opacity:0.55;"' : ($src === 'orphan' ? 'style="background:#fff7ed;"' : ''); ?>>
                                    <td><code><?php echo esc_html($db); ?></code></td>
                                    <td>
                                        <?php if ($hasPreview && !$isSys): ?>
                                            <input type="text" class="regular-text" name="sql_type[<?php echo esc_attr($k); ?>]" value="<?php echo esc_attr((string) ($c['sql_type'] ?? 'LONGTEXT')); ?>" />
                                        <?php else: ?>
                                            <code><?php echo esc_html((string) ($c['sql_type'] ?? '')); ?></code>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if ($hasPreview && !$isSys): ?>
                                            <input type="checkbox" name="nullable[<?php echo esc_attr($k); ?>]" value="1" <?php checked(!empty($c['nullable'])); ?> />
                                        <?php else: ?>
                                            <?php echo !empty($c['nullable']) ? 'YES' : 'NO'; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if ($hasPreview && !$isSys): ?>
                                            <input type="checkbox" name="enabled[<?php echo esc_attr($k); ?>]" value="1" <?php checked($enabled); ?> />
                                        <?php else: ?>
                                            <?php echo $enabled ? '✓' : '—'; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($src !== '' ? $src : 'schema'); ?></td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <?php if ($isRemovable): ?>
                                            <?php
                                            $delUrl = wp_nonce_url(add_query_arg([
                                                'action' => 'pbs_schema_dll_col_delete',
                                                'schema_id' => (int) $schemaId,
                                                'db_column' => (string) $db,
                                            ], admin_url('admin-post.php')), 'pbs_schema_dll_col_delete');
                                            ?>
                                            <a class="button button-link-delete" href="<?php echo esc_url($delUrl); ?>" data-pbs-confirm="<?php echo esc_attr('Rimuovere la colonna dalla DLL preview?'); ?>">Elimina</a>
                                        <?php else: ?>
                                            <span class="description">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if ($hasPreview): ?>
                                            <input type="checkbox" name="ui_list[<?php echo esc_attr($k); ?>]" value="1" <?php checked(!empty($ui['list'])); ?> />
                                        <?php else: ?>
                                            <?php echo !empty($ui['list']) ? '✓' : '—'; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if ($hasPreview): ?>
                                            <input type="checkbox" name="ui_edit[<?php echo esc_attr($k); ?>]" value="1" <?php checked(!empty($ui['edit'])); ?> />
                                        <?php else: ?>
                                            <?php echo !empty($ui['edit']) ? '✓' : '—'; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if ($hasPreview): ?>
                                            <input type="checkbox" name="ui_editable[<?php echo esc_attr($k); ?>]" value="1" <?php checked(!empty($ui['editable'])); ?> />
                                        <?php else: ?>
                                            <?php echo !empty($ui['editable']) ? '✓' : '—'; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if ($hasPreview): ?>
                                <tr style="background:#f6f7f7;">
                                    <td><input name="new_db_column" type="text" class="regular-text" placeholder="es. my_extra" /></td>
                                    <td><input name="new_sql_type" type="text" class="regular-text" value="LONGTEXT" /></td>
                                    <td style="text-align:center;"><input type="checkbox" name="new_nullable" value="1" /></td>
                                    <td style="text-align:center;">✓</td>
                                    <td><span class="description">manual</span></td>
                                    <td style="text-align:right;"><span class="description">—</span></td>
                                    <td style="text-align:center;"><input type="checkbox" disabled /></td>
                                    <td style="text-align:center;"><input type="checkbox" disabled checked /></td>
                                    <td style="text-align:center;"><input type="checkbox" disabled checked /></td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>

                        <?php if ($hasPreview): ?>
                            <?php submit_button('Salva DLL preview'); ?>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require PBS_PLUGIN_DIR . 'templates/pbs-confirm-dialog.php'; ?>
