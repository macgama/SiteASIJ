<?php
/** Confirmation de suppression d'un média utilisé. Variables: $media, $pages, $isLogo */
?>
<div class="page-head">
  <h1>Ce fichier est utilisé</h1>
</div>
<div class="card">
  <p><strong><?= e($media['title'] ?: $media['original_name']) ?></strong> est encore utilisé&nbsp;:</p>
  <ul>
    <?php if ($isLogo): ?><li>comme <strong>logo du site</strong> (Réglages)</li><?php endif; ?>
    <?php foreach ($pages as $p): ?>
    <li>sur la page <a href="/admin/pages/<?= (int) $p['id'] ?>"><?= e($p['title']) ?></a> <span class="muted small">(<?= e($p['path']) ?>)</span></li>
    <?php endforeach; ?>
  </ul>
  <p>Si vous le supprimez, il disparaîtra de ces pages.</p>
  <div class="btn-row">
    <a class="btn btn-primary" href="/admin/medias">Annuler et garder le fichier</a>
    <form method="post" action="/admin/medias/<?= (int) $media['id'] ?>/supprimer">
      <?= csrf_field() ?>
      <input type="hidden" name="force" value="1">
      <button type="submit" class="btn btn-danger">Supprimer quand même</button>
    </form>
  </div>
</div>
