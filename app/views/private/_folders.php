<?php
declare(strict_types=1);
/** Grille de dossiers. @var array $folders */
?>
<ul class="pa-folders" role="list">
  <?php foreach ($folders as $f): ?>
  <li>
    <a class="pa-folder" href="/espace-membres/dossier/<?= (int) $f['id'] ?>">
      <svg class="pa-folder-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="32" height="32"><path d="M3 6.5A1.5 1.5 0 0 1 4.5 5h4.6l2 2.2h8.4A1.5 1.5 0 0 1 21 8.7v9.8a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18.5z" fill="currentColor"/></svg>
      <span class="pa-folder-name"><?= e($f['name']) ?></span>
      <span class="pa-folder-count"><?= (int) ($f['file_count'] ?? 0) ?> document<?= (int) ($f['file_count'] ?? 0) > 1 ? 's' : '' ?></span>
      <?php if (!empty($f['description'])): ?><span class="pa-folder-desc"><?= e($f['description']) ?></span><?php endif; ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>
