<?php
/**
 * @var string[] $whitelist
 * @var array{plugin_slug:string,glib_dir:string} $pointer
 * @var array<int,array<string,mixed>> $scan
 * @var array<int,array<string,mixed>> $suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

$pageUrl = admin_url('admin.php?page=pbs-glib-check');
?>

<div class="wrap">
    <h1>PBS — Check gLib</h1>

    <?php if (!empty($_GET['pbs_ok']) && $_GET['pbs_ok'] === 'saved'): ?>
        <div class="notice notice-success"><p><strong>Whitelist salvata.</strong></p></div>
    <?php endif; ?>
    <?php if (!empty($_GET['pbs_ok']) && $_GET['pbs_ok'] === 'pointer'): ?>
        <div class="notice notice-success"><p><strong>Puntatore gLib aggiornato.</strong></p></div>
    <?php endif; ?>
    <?php if (!empty($_GET['pbs_err']) && $_GET['pbs_err'] === 'invalid_pointer'): ?>
        <div class="notice notice-error"><p><strong>Puntatore non valido.</strong></p></div>
    <?php endif; ?>

    <p class="description">
        Whitelist manuale dei plugin candidati. PBS scansiona solo questi plugin per rilevare gLib (<code>gLib/</code> o <code>gLib_fNN/</code>) e produrre un report di compatibilità.
    </p>

    <h2>gLib puntata</h2>
    <?php if (!empty($pointer['plugin_slug']) && !empty($pointer['glib_dir'])): ?>
        <p><strong>Plugin:</strong> <code><?php echo esc_html($pointer['plugin_slug']); ?></code> — <strong>Dir:</strong> <code><?php echo esc_html($pointer['glib_dir']); ?></code></p>
    <?php else: ?>
        <p class="description">Nessuna gLib puntata.</p>
    <?php endif; ?>

    <h2>Whitelist</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('pbs_glib_whitelist_save'); ?>
        <input type="hidden" name="action" value="pbs_glib_whitelist_save" />
        <textarea name="whitelist" rows="8" style="width:100%; max-width:920px;"><?php echo esc_textarea(implode("\n", $whitelist)); ?></textarea>
        <p class="description">Un plugin per riga (slug cartella in <code>wp-content/plugins</code>). Commenti con <code>#</code>.</p>
        <?php submit_button('Salva whitelist'); ?>
    </form>

    <h2>Scan</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;">
        <?php wp_nonce_field('pbs_glib_scan'); ?>
        <input type="hidden" name="action" value="pbs_glib_scan" />
        <?php submit_button('Esegui scan', 'secondary'); ?>
    </form>

    <table class="widefat striped" style="margin-top:12px;">
        <thead>
        <tr>
            <th>Plugin</th>
            <th>gLib dirs</th>
            <th>Status</th>
            <th style="text-align:right;">Action</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$scan): ?>
            <tr><td colspan="4"><em>Nessun candidato.</em></td></tr>
        <?php else: ?>
            <?php foreach ($scan as $row): ?>
                <?php
                $ps = (string) ($row['plugin_slug'] ?? '');
                $status = (string) ($row['status'] ?? '');
                $glibDirs = (array) ($row['glib_dirs'] ?? []);
                ?>
                <tr>
                    <td><code><?php echo esc_html($ps); ?></code></td>
                    <td>
                        <?php if (!$glibDirs): ?>
                            <span class="description">—</span>
                        <?php else: ?>
                            <ul style="margin:0 0 0 18px;">
                                <?php foreach ($glibDirs as $gd): ?>
                                    <?php if (!is_array($gd)) { continue; } ?>
                                    <?php
                                    $dir = (string) ($gd['dir'] ?? '');
                                    $deep = (array) ($gd['deep'] ?? []);
                                    $ok = !empty($deep['ok']);
                                    ?>
                                    <li>
                                        <code><?php echo esc_html($dir); ?></code>
                                        <?php echo $ok ? '<span class="description">ok</span>' : '<span class="description">check</span>'; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($status); ?></td>
                    <td style="text-align:right;">
                        <?php if ($glibDirs): ?>
                            <?php foreach ($glibDirs as $gd): ?>
                                <?php if (!is_array($gd)) { continue; } ?>
                                <?php $dir = (string) ($gd['dir'] ?? ''); ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                    <?php wp_nonce_field('pbs_glib_set_pointer'); ?>
                                    <input type="hidden" name="action" value="pbs_glib_set_pointer" />
                                    <input type="hidden" name="plugin_slug" value="<?php echo esc_attr($ps); ?>" />
                                    <input type="hidden" name="glib_dir" value="<?php echo esc_attr($dir); ?>" />
                                    <?php submit_button('Punta', 'secondary', 'submit', false); ?>
                                </form>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($glibDirs): ?>
                    <?php foreach ($glibDirs as $gd): ?>
                        <?php if (!is_array($gd)) { continue; } ?>
                        <?php $deep = (array) ($gd['deep'] ?? []); ?>
                        <?php if (empty($deep['blockers']) && empty($deep['warnings'])) { continue; } ?>
                        <tr>
                            <td colspan="4" style="background:#fff;">
                                <details>
                                    <summary><?php echo esc_html((string) ($gd['dir'] ?? 'gLib')); ?> — report</summary>
                                    <?php if (!empty($deep['blockers'])): ?>
                                        <p><strong>Blockers</strong></p>
                                        <ul style="margin-left:20px;">
                                            <?php foreach ((array) $deep['blockers'] as $b): ?>
                                                <li><?php echo esc_html((string) $b); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                    <?php if (!empty($deep['warnings'])): ?>
                                        <p><strong>Warnings</strong></p>
                                        <ul style="margin-left:20px;">
                                            <?php foreach ((array) $deep['warnings'] as $w): ?>
                                                <li><?php echo esc_html((string) $w); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                    <?php if (!empty($gd['namespace'])): ?>
                                        <p><strong>Namespace:</strong> <code><?php echo esc_html((string) $gd['namespace']); ?></code></p>
                                    <?php endif; ?>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <h2 style="margin-top:22px;">Suggeriti</h2>
    <p class="description">
        Plugin rilevati automaticamente che contengono una gLib (<code>gLib/Init.php</code> o <code>gLib_fNN/Init.php</code>) ma non sono ancora in whitelist.
    </p>

    <?php if (!$suggestions): ?>
        <p class="description">Nessun suggerimento trovato.</p>
    <?php else: ?>
        <?php
        $allSlugs = [];
        foreach ($suggestions as $s) {
            if (is_array($s) && !empty($s['plugin_slug'])) {
                $allSlugs[] = (string) $s['plugin_slug'];
            }
        }
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:8px 0 12px;">
            <?php wp_nonce_field('pbs_glib_whitelist_add_all'); ?>
            <input type="hidden" name="action" value="pbs_glib_whitelist_add_all" />
            <input type="hidden" name="plugin_slugs" value="<?php echo esc_attr(implode(',', $allSlugs)); ?>" />
            <?php submit_button('Aggiungi tutti alla whitelist', 'secondary', 'submit', false); ?>
        </form>

        <table class="widefat striped">
            <thead>
            <tr>
                <th>Plugin</th>
                <th>Stato</th>
                <th>gLib dirs</th>
                <th style="text-align:right;">Action</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($suggestions as $row): ?>
                <?php
                if (!is_array($row)) {
                    continue;
                }
                $ps = (string) ($row['plugin_slug'] ?? '');
                $active = !empty($row['is_active']);
                $glibDirs = (array) ($row['glib_dirs'] ?? []);
                ?>
                <tr <?php echo !$active ? 'style="opacity:0.65;"' : ''; ?>>
                    <td>
                        <code><?php echo esc_html($ps); ?></code>
                        <?php if (!empty($row['plugin_name'])): ?>
                            <span class="description">— <?php echo esc_html((string) $row['plugin_name']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $active ? 'attivo' : 'disattivo'; ?></td>
                    <td>
                        <?php if (!$glibDirs): ?>
                            <span class="description">—</span>
                        <?php else: ?>
                            <?php foreach ($glibDirs as $gd): ?>
                                <?php if (!is_array($gd)) { continue; } ?>
                                <code><?php echo esc_html((string) ($gd['dir'] ?? '')); ?></code>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                            <?php wp_nonce_field('pbs_glib_whitelist_add'); ?>
                            <input type="hidden" name="action" value="pbs_glib_whitelist_add" />
                            <input type="hidden" name="plugin_slug" value="<?php echo esc_attr($ps); ?>" />
                            <?php submit_button('Aggiungi', 'secondary', 'submit', false); ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
