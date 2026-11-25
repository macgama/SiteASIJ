<?php
/** Bibliothèque de médias. Variables: $items, $total, $page, $pages, $q, $kind, $maxBytes, $accept */
$back = '/admin/medias' . ($_SERVER['QUERY_STRING'] ?? '' ? '?' . ($_SERVER['QUERY_STRING'] ?? '') : '');
?>
<div class="page-head">
  <h1>Médias</h1>
  <p class="lead">Photos et documents publics utilisés dans les pages du site. Les documents confidentiels se gèrent dans <a href="/admin/espace">l’espace sécurisé</a>.</p>
</div>

<form class="dropzone card" method="post" action="/admin/medias" enctype="multipart/form-data" data-dropzone data-max-bytes="<?= (int) $maxBytes ?>">
  <?= csrf_field() ?>
  <svg aria-hidden="true" viewBox="0 0 24 24" width="36" height="36"><path d="M12 16V4M7 9l5-5 5 5M4 16v4h16v-4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
  <p class="dropzone-text"><strong>Glissez vos fichiers ici</strong> ou</p>
  <label class="btn btn-primary">
    Choisir des fichiers
    <input class="sr-only" type="file" name="files[]" multiple accept="<?= e($accept) ?>" data-file-input>
  </label>
  <p class="help">Images (JPG, PNG, GIF, WebP), PDF, Word, Excel, PowerPoint, OpenDocument, MP4, TXT, CSV – <?= e(human_size($maxBytes)) ?> maximum par fichier. Les grandes photos sont réduites automatiquement.</p>
  <noscript><button type="submit" class="btn">Envoyer</button></noscript>
  <ul class="upload-list" role="list" data-upload-list aria-live="polite"></ul>
</form>

<form class="toolbar" method="get" action="/admin/medias" role="search">
  <div class="field-inline">
    <label for="m-q" class="sr-only">Rechercher un fichier</label>
    <input id="m-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Rechercher par nom…">
  </div>
  <div class="segmented" role="group" aria-label="Type de fichier">
    <?php foreach (['' => 'Tout', 'image' => 'Images', 'file' => 'Documents'] as $k => $label): ?>
    <button type="submit" name="kind" value="<?= e($k) ?>" class="<?= $kind === $k ? 'is-active' : '' ?>" aria-pressed="<?= $kind === $k ? 'true' : 'false' ?>"><?= e($label) ?></button>
    <?php endforeach; ?>
  </div>
  <span class="muted"><?= (int) $total ?> fichier<?= $total > 1 ? 's' : '' ?></span>
</form>

<?php if (!$items): ?>
  <div class="empty card"><p><?= $q !== '' || $kind !== '' ? 'Aucun fichier ne correspond à votre recherche.' : 'Aucun fichier pour l’instant. Ajoutez-en avec la zone ci-dessus.' ?></p></div>
<?php else: ?>
<ul class="media-grid" role="list">
  <?php foreach ($items as $m): ?>
  <li class="media-card" id="media-<?= (int) $m['id'] ?>">
    <a class="media-thumb" href="<?= e($m['url']) ?>" target="_blank" rel="noopener" title="Ouvrir le fichier">
      <?php if ($m['kind'] === 'image'): ?>
        <img src="<?= e($m['thumb']) ?>" alt="<?= e($m['alt']) ?>" loading="lazy">
      <?php else: ?>
        <span class="file-icon file-<?= e($m['ext']) ?>"><?= e(strtoupper($m['ext'])) ?></span>
      <?php endif; ?>
    </a>
    <div class="media-info">
      <p class="media-name" title="<?= e($m['original_name']) ?>"><?= e($m['title'] ?: $m['original_name']) ?></p>
      <p class="muted small"><?= e($m['size_human']) ?> · <?= e($m['created_at']) ?></p>
      <details class="media-edit">
        <summary>Modifier</summary>
        <form method="post" action="/admin/medias/<?= (int) $m['id'] ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="back" value="<?= e($back) ?>">
          <div class="field">
            <label for="mt-<?= (int) $m['id'] ?>">Titre</label>
            <input id="mt-<?= (int) $m['id'] ?>" name="title" value="<?= e($m['title']) ?>" maxlength="255">
          </div>
          <?php if ($m['kind'] === 'image'): ?>
          <div class="field">
            <label for="ma-<?= (int) $m['id'] ?>">Description de l’image</label>
            <input id="ma-<?= (int) $m['id'] ?>" name="alt" value="<?= e($m['alt']) ?>" maxlength="255" aria-describedby="mah-<?= (int) $m['id'] ?>">
            <p class="help" id="mah-<?= (int) $m['id'] ?>">Pour les personnes malvoyantes&nbsp;: décrivez ce que montre la photo.</p>
          </div>
          <?php endif; ?>
          <p class="small muted break">Fichier d’origine&nbsp;: <?= e($m['original_name']) ?></p>
          <div class="btn-row">
            <button type="submit" class="btn btn-small btn-primary">Enregistrer</button>
          </div>
        </form>
        <form method="post" action="/admin/medias/<?= (int) $m['id'] ?>/supprimer" data-confirm="Supprimer définitivement « <?= e($m['title'] ?: $m['original_name']) ?> » ?">
          <?= csrf_field() ?>
          <input type="hidden" name="back" value="<?= e($back) ?>">
          <button type="submit" class="btn btn-small btn-danger-link">Supprimer ce fichier</button>
        </form>
      </details>
    </div>
  </li>
  <?php endforeach; ?>
</ul>
<?= App\View::partial('admin/_pagination', ['page' => $page, 'pages' => $pages, 'base' => '/admin/medias', 'params' => ['q' => $q, 'kind' => $kind]]) ?>
<?php endif; ?>
