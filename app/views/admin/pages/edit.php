<?php
/**
 * Formulaire de page + éditeur de blocs.
 * Variables: $page, $errors, $blocks, $media, $parents, $links, $revisionCount, $homeExists
 */
use App\Controllers\AdminMediaController;

$extraScripts = ['js/editor.js'];
$bodyClass = 'is-editor';
$wide = true;
$id = $page['id'] ? (int) $page['id'] : null;
$isHome = ($page['path'] ?? '') === '/';
$canBeHome = empty($page['parent_id']) && ($isHome || !$homeExists);
$parentPath = '';
foreach ($parents as $p) {
    if ((int) $p['id'] === (int) ($page['parent_id'] ?? 0)) {
        $parentPath = rtrim((string) $p['path'], '/');
    }
}
$editorData = [
    'blocks' => $blocks,
    'media' => (object) $media,
    'links' => $links,
    'maxBytes' => AdminMediaController::maxBytes(),
    'accept' => AdminMediaController::acceptAttr(),
];
$json = json_encode($editorData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
$err = fn(string $k) => isset($errors[$k]) ? '<p class="error" id="err-' . e($k) . '">' . e($errors[$k]) . '</p>' : '';
?>
<p class="breadcrumb"><a href="/admin/pages">Pages</a> › <?= $id ? e($page['title']) : 'Nouvelle page' ?></p>

<?php if ($errors): ?>
<div class="flash flash-error" role="alert">La page n’a pas été enregistrée&nbsp;: <?= e(implode(' ', $errors)) ?></div>
<?php endif; ?>

<form id="page-form" class="editor-form" method="post" action="<?= $id ? '/admin/pages/' . $id : '/admin/pages' ?>" data-dirty-check data-new="<?= $id ? '0' : '1' ?>">
  <?= csrf_field() ?>
  <div class="editor-grid">
    <div class="editor-main">
      <div class="field field-title">
        <label for="f-title">Titre de la page</label>
        <input id="f-title" name="title" type="text" required maxlength="255" value="<?= e($page['title']) ?>" placeholder="Ex. Transports scolaires"<?= isset($errors['title']) ? ' aria-invalid="true" aria-describedby="err-title"' : '' ?>>
        <?= $err('title') ?>
      </div>

      <div class="field">
        <h2 class="label" id="content-label">Contenu de la page</h2>
        <p class="help">Le contenu est composé de blocs (texte, photos, documents…). Cliquez sur « Ajouter un bloc » pour commencer. Raccourci&nbsp;: <kbd>Ctrl</kbd>+<kbd>S</kbd> pour enregistrer.</p>
        <div id="block-editor" class="block-editor" aria-labelledby="content-label"></div>
        <noscript><p class="flash flash-error">L’éditeur nécessite JavaScript. Activez-le dans votre navigateur.</p></noscript>
        <input type="hidden" name="blocks" id="f-blocks" value="<?= e(json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
        <script type="application/json" id="editor-data"><?= $json ?></script>
        <?= $err('blocks') ?>
      </div>
    </div>

    <aside class="editor-side">
      <section class="card side-card">
        <h2 class="h3">Publication</h2>
        <fieldset class="radio-cards">
          <legend class="sr-only">Statut</legend>
          <label class="radio-card">
            <input type="radio" name="status" value="draft"<?= $page['status'] !== 'published' ? ' checked' : '' ?>>
            <span><strong>Brouillon</strong><small>Visible seulement par les administrateurs</small></span>
          </label>
          <label class="radio-card">
            <input type="radio" name="status" value="published"<?= $page['status'] === 'published' ? ' checked' : '' ?>>
            <span><strong>Publiée</strong><small>Visible par tous les visiteurs</small></span>
          </label>
        </fieldset>
        <div class="side-actions">
          <button type="submit" class="btn btn-primary btn-block" data-save>Enregistrer</button>
          <button type="submit" class="btn btn-block" name="after" value="preview">Enregistrer et voir l’aperçu</button>
        </div>
        <p class="save-state muted small" data-save-state aria-live="polite"><?= $id ? 'Aucune modification en cours.' : 'Nouvelle page, pas encore enregistrée.' ?></p>
        <?php if ($id): ?>
        <ul class="side-links" role="list">
          <?php if ($page['status'] === 'published'): ?><li><a href="<?= e(implode('/', array_map('rawurlencode', explode('/', (string) $page['path'])))) ?>" target="_blank" rel="noopener">Voir la page sur le site ↗</a></li><?php endif; ?>
          <li><a href="/admin/pages/<?= $id ?>/apercu">Aperçu de la version enregistrée</a></li>
          <li><a href="/admin/pages/<?= $id ?>/versions">Versions précédentes (<?= (int) $revisionCount ?>)</a></li>
        </ul>
        <?php if (!empty($page['updated_at'])): ?><p class="muted small">Dernière modification le <?= e(date_fr((string) $page['updated_at'], true)) ?></p><?php endif; ?>
        <?php endif; ?>
      </section>

      <section class="card side-card">
        <h2 class="h3">Place dans le menu</h2>
        <div class="field">
          <label for="f-parent">Page parente</label>
          <select id="f-parent" name="parent_id"<?= isset($errors['parent_id']) ? ' aria-invalid="true"' : '' ?>>
            <option value="" data-path="">— Aucune (premier niveau du menu)</option>
            <?php foreach ($parents as $p): ?>
            <option value="<?= (int) $p['id'] ?>" data-path="<?= e(rtrim((string) $p['path'], '/')) ?>"<?= (int) $p['id'] === (int) ($page['parent_id'] ?? 0) ? ' selected' : '' ?>><?= str_repeat('— ', (int) $p['depth']) . e($p['title']) ?></option>
            <?php endforeach; ?>
          </select>
          <?= $err('parent_id') ?>
        </div>
        <label class="check">
          <input type="checkbox" name="show_in_menu" value="1"<?= (int) $page['show_in_menu'] === 1 ? ' checked' : '' ?>>
          <span>Afficher cette page dans le menu</span>
        </label>
        <div class="field">
          <label for="f-menu-title">Titre court dans le menu <span class="optional">(facultatif)</span></label>
          <input id="f-menu-title" name="menu_title" type="text" maxlength="255" value="<?= e((string) $page['menu_title']) ?>" placeholder="Identique au titre">
        </div>
        <?php if ($canBeHome): ?>
        <label class="check">
          <input type="checkbox" name="is_home" value="1" data-is-home<?= $isHome ? ' checked' : '' ?>>
          <span>C’est la page d’accueil du site</span>
        </label>
        <?php endif; ?>
        <div class="field" data-slug-field<?= $isHome ? ' hidden' : '' ?>>
          <label for="f-slug">Adresse de la page</label>
          <p class="help" id="h-slug">Créée automatiquement à partir du titre. Modifiez-la seulement si nécessaire.</p>
          <div class="slug-input">
            <span class="slug-prefix" data-slug-prefix><?= e($parentPath) ?>/</span>
            <input id="f-slug" name="slug" type="text" maxlength="120" value="<?= e((string) $page['slug']) ?>" aria-describedby="h-slug" spellcheck="false" autocapitalize="off" data-slug<?= $id ? ' data-touched="1"' : '' ?>>
          </div>
          <?= $err('slug') ?>
          <?php if ($id && !$isHome): ?><p class="help">Attention&nbsp;: changer l’adresse casse les liens déjà partagés vers cette page.</p><?php endif; ?>
        </div>
      </section>

      <section class="card side-card">
        <h2 class="h3">Moteurs de recherche</h2>
        <div class="field">
          <label for="f-meta">Description courte <span class="optional">(facultatif)</span></label>
          <p class="help" id="h-meta">Une ou deux phrases affichées par Google sous le titre de la page.</p>
          <textarea id="f-meta" name="meta_description" rows="3" maxlength="500" aria-describedby="h-meta f-meta-count" data-counter="160"><?= e((string) $page['meta_description']) ?></textarea>
          <p class="muted small" id="f-meta-count" data-counter-out></p>
        </div>
      </section>

      <?php if ($id): ?>
      <section class="card side-card side-danger">
        <h2 class="h3">Supprimer la page</h2>
        <p class="small">La suppression est définitive. Les sous-pages doivent d’abord être déplacées ou supprimées.</p>
        <button type="submit" form="delete-form" class="btn btn-danger btn-block">Supprimer cette page</button>
      </section>
      <?php endif; ?>
    </aside>
  </div>
  <div class="mobile-savebar">
    <span class="muted small" data-save-state-mobile></span>
    <button type="submit" class="btn btn-primary">Enregistrer</button>
  </div>
</form>
<?php if ($id): ?>
<form id="delete-form" method="post" action="/admin/pages/<?= $id ?>/supprimer" data-confirm="Supprimer définitivement la page « <?= e($page['title']) ?> » ? Cette action est irréversible." data-no-dirty>
  <?= csrf_field() ?>
</form>
<?php endif; ?>
