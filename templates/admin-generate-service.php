<?php
/**
 * @var array<int,array<string,mixed>> $schemas
 * @var array<string,mixed>|null $schema
 * @var array{plugin_slug:string,glib_dir:string} $pointer
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1>PBS — Genera servizio</h1>

    <p class="description">
        Modalità “Extend”: genera un nuovo servizio gLib-compliant da template e prepara un report/piano di patch per agganciarlo a una gLib esistente.
        Nessuna modifica viene applicata “a caso”: l'applicazione delle patch sarà sempre confermata manualmente.
    </p>

    <h2>Target gLib (puntatore)</h2>
    <?php if (!empty($pointer['plugin_slug']) && !empty($pointer['glib_dir'])): ?>
        <p><strong>Plugin:</strong> <code><?php echo esc_html($pointer['plugin_slug']); ?></code> — <strong>Dir:</strong> <code><?php echo esc_html($pointer['glib_dir']); ?></code></p>
        <p class="description">Per cambiare target: vai in <strong>PBS → Check gLib</strong> e usa “Punta”.</p>
    <?php else: ?>
        <div class="notice notice-warning">
            <p><strong>Nessuna gLib puntata.</strong> Configura il target in <strong>PBS → Check gLib</strong> prima di procedere.</p>
        </div>
    <?php endif; ?>

    <h2>Seleziona schema</h2>
    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
        <input type="hidden" name="page" value="pbs-generate-service" />
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

    <?php if ($schema): ?>
        <hr/>
        <h2>CFG (MVP)</h2>
        <p class="description">
            In questa prima versione la pagina prepara la separazione tra “Genera plugin” e “Genera servizio”.
            La generazione file servizio + patch plan su <code>Init.php</code> e <code>Supports/Components/Admin/BaseController.php</code> verrà implementata nel passo successivo.
        </p>
    <?php endif; ?>
</div>

