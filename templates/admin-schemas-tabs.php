<?php
/**
 * @var string $tab
 * @var array<int,array<string,mixed>> $schemas
 * @var array<string,mixed>|null $schema
 * @var array<int,array<string,mixed>> $fields
 * @var array<string,mixed>|null $selectedField
 * @var bool $acfAvailable
 */

if (!defined('ABSPATH')) {
    exit;
}

$baseUrl = admin_url('admin.php?page=pbs');
$fieldsUrl = admin_url('admin.php?page=pbs-schema-fields');
$newUrl = add_query_arg(['tab' => 'new'], $baseUrl);
$listUrl = add_query_arg(['tab' => 'list'], $baseUrl);
$editUrl = add_query_arg(['tab' => 'edit'], $baseUrl);
$helpUrl = add_query_arg(['tab' => 'help'], $baseUrl);

$schemaId = $schema ? (int) $schema['id'] : 0;
?>

<div class="wrap">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
        <h1 style="margin:0;">PBS — Schemi</h1>
        <div>
            <?php if ($tab !== 'new'): ?>
                <a class="button button-primary" href="<?php echo esc_url($newUrl); ?>">New</a>
            <?php else: ?>
                <a class="button" href="<?php echo esc_url($listUrl); ?>">Lista</a>
            <?php endif; ?>
        </div>
    </div>

    <h2 class="nav-tab-wrapper" style="margin-top:16px;">
        <a href="<?php echo esc_url($listUrl); ?>" class="nav-tab <?php echo $tab === 'list' ? 'nav-tab-active' : ''; ?>">List</a>
        <a href="<?php echo esc_url($editUrl); ?>" class="nav-tab <?php echo $tab === 'edit' ? 'nav-tab-active' : ''; ?>">Edit</a>
        <a href="<?php echo esc_url($newUrl); ?>" class="nav-tab <?php echo $tab === 'new' ? 'nav-tab-active' : ''; ?>">New</a>
        <a href="<?php echo esc_url($helpUrl); ?>" class="nav-tab <?php echo $tab === 'help' ? 'nav-tab-active' : ''; ?>">Help</a>
    </h2>

    <?php if ($tab === 'help'): ?>
        <h2>Come si usa</h2>
        <ol>
            <li><strong>New</strong>: crea uno schema (manuale o impostando la sorgente CPT/ACF).</li>
            <li><strong>Edit</strong>: seleziona lo schema e modifica solo i metadati (sorgente, post type, descrizione).</li>
            <li><strong>Dettaglio schema</strong> (menu dedicato): gestisce solo i campi (struttura + editor).</li>
            <li><strong>List</strong>: usa <em>Edit</em> per modificare lo schema o <em>Delete</em> per rimuoverlo.</li>
            <li><strong>Genera plugin</strong> (menu dedicato): genera un nuovo plugin indipendente con namespace incrementale <code>gLib_fxx</code>.</li>
        </ol>
        <p class="description">
            Nota policy: i plugin generati non devono introdurre delta automatici DB/DDL in runtime (FE/BE).
            PBS è un generatore on-demand: configuro → valido → genero.
        </p>
    <?php endif; ?>

    <?php if ($tab === 'list'): ?>
        <table class="widefat striped" style="margin-top:16px;">
            <thead>
            <tr>
                <th>Nome</th>
                <th>Slug</th>
                <th>Version</th>
                <th>Sorgente</th>
                <th style="text-align:right;">Azioni</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$schemas): ?>
                <tr><td colspan="5"><em>Nessuno schema.</em></td></tr>
            <?php else: ?>
                <?php foreach ($schemas as $s): ?>
                    <tr>
                        <td><?php echo esc_html((string) $s['name']); ?></td>
                        <td><code><?php echo esc_html((string) $s['slug']); ?></code></td>
                        <td><?php echo esc_html((string) $s['schema_version']); ?></td>
                        <td><?php echo esc_html((string) ($s['source_type'] ?? 'manual')); ?></td>
                        <td style="text-align:right; white-space:nowrap;">
                            <a class="button" href="<?php echo esc_url(add_query_arg(['tab' => 'edit', 'schema_id' => (int) $s['id']], $baseUrl)); ?>">Edit</a>
                            <a class="button" href="<?php echo esc_url(add_query_arg(['schema_id' => (int) $s['id']], $fieldsUrl)); ?>">Campi</a>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('Eliminare lo schema?');">
                                <?php wp_nonce_field('pbs_schema_delete'); ?>
                                <input type="hidden" name="action" value="pbs_schema_delete" />
                                <input type="hidden" name="schema_id" value="<?php echo (int) $s['id']; ?>" />
                                <?php submit_button('Delete', 'delete', 'submit', false); ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($tab === 'new'): ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px;">
            <?php wp_nonce_field('pbs_schema_create'); ?>
            <input type="hidden" name="action" value="pbs_schema_create" />

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="pbs_slug">Slug</label></th>
                    <td><input name="slug" id="pbs_slug" type="text" class="regular-text" placeholder="es. providers" required /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="pbs_name">Nome</label></th>
                    <td><input name="name" id="pbs_name" type="text" class="regular-text" placeholder="Es. Providers" required /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="pbs_desc">Descrizione</label></th>
                    <td><textarea name="description" id="pbs_desc" class="large-text" rows="3"></textarea></td>
                </tr>
                <tr>
                    <th scope="row">Sorgente</th>
                    <td>
                        <label><input type="radio" name="source_type" value="manual" checked /> Manuale</label><br/>
                        <label><input type="radio" name="source_type" value="acf" <?php echo $acfAvailable ? '' : 'disabled'; ?> /> CPT/ACF</label>
                        <?php if (!$acfAvailable): ?>
                            <p class="description">ACF non rilevato: puoi creare lo schema in modalità manuale.</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="pbs_post_type">Post type (se ACF)</label></th>
                    <td><input name="source_post_type" id="pbs_post_type" type="text" class="regular-text" placeholder="es. bridge_prodotti" /></td>
                </tr>
            </table>

            <?php submit_button('Crea schema'); ?>
        </form>
    <?php endif; ?>

    <?php if ($tab === 'edit'): ?>
        <?php if (!$schemas): ?>
            <p style="margin-top:16px;">Nessuno schema: usa il TAB <strong>New</strong>.</p>
        <?php else: ?>
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-top:16px;">
                <input type="hidden" name="page" value="pbs" />
                <input type="hidden" name="tab" value="edit" />
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
                <div style="margin-top:16px;">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                        <h2 style="margin:0;">Schema</h2>
                        <a class="button" href="<?php echo esc_url(add_query_arg(['schema_id' => $schemaId], $fieldsUrl)); ?>">Apri dettaglio campi</a>
                    </div>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('pbs_schema_update'); ?>
                            <input type="hidden" name="action" value="pbs_schema_update" />
                            <input type="hidden" name="schema_id" value="<?php echo $schemaId; ?>" />

                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row">Slug</th>
                                    <td><code><?php echo esc_html((string) $schema['slug']); ?></code></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="pbs_name_u">Nome</label></th>
                                    <td><input name="name" id="pbs_name_u" type="text" class="regular-text" value="<?php echo esc_attr((string) $schema['name']); ?>" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="pbs_desc_u">Descrizione</label></th>
                                    <td><textarea name="description" id="pbs_desc_u" class="large-text" rows="3"><?php echo esc_textarea((string) ($schema['description'] ?? '')); ?></textarea></td>
                                </tr>
                                <tr>
                                    <th scope="row">Sorgente</th>
                                    <td>
                                        <select name="source_type">
                                            <option value="manual" <?php selected((string) $schema['source_type'], 'manual'); ?>>Manuale</option>
                                            <option value="acf" <?php selected((string) $schema['source_type'], 'acf'); ?> <?php echo $acfAvailable ? '' : 'disabled'; ?>>CPT/ACF</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="pbs_post_type_u">Post type</label></th>
                                    <td><input name="source_post_type" id="pbs_post_type_u" type="text" class="regular-text" value="<?php echo esc_attr((string) ($schema['source_post_type'] ?? '')); ?>" /></td>
                                </tr>
                                <tr>
                                    <th scope="row">Schema Version</th>
                                    <td><strong><?php echo esc_html((string) $schema['schema_version']); ?></strong></td>
                                </tr>
                            </table>

                            <?php submit_button('Salva schema', 'secondary'); ?>
                        </form>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
