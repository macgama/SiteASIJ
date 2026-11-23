<?php
declare(strict_types=1);
/** Liste de documents. @var array $files @var bool $showFolder */
$showFolder = $showFolder ?? false;
?>
<ul class="pa-files" role="list">
  <?php foreach ($files as $f):
      $ext = strtoupper(pathinfo((string) $f['original_name'], PATHINFO_EXTENSION)) ?: 'FICHIER';
      $label = (string) ($f['title'] ?: $f['original_name']); ?>
  <li class="pa-file">
    <span class="pa-ext pa-ext-<?= e(strtolower($ext)) ?>" aria-hidden="true"><?= e(mb_substr($ext, 0, 4)) ?></span>
    <div class="pa-file-main">
      <a class="pa-file-link" href="/espace-membres/fichier/<?= (int) $f['id'] ?>" download>
        <?= e($label) ?><span class="visually-hidden"> (télécharger, <?= e($ext) ?>, <?= e(human_size((int) $f['size'])) ?>)</span>
      </a>
      <p class="pa-file-meta">
        <?= e($ext) ?> · <?= e(human_size((int) $f['size'])) ?> · ajouté le <?= e(date_fr($f['created_at'])) ?>
        <?php if ($showFolder && !empty($f['folder_name'])): ?>
        · dans <a href="/espace-membres/dossier/<?= (int) $f['folder_id'] ?>"><?= e($f['folder_name']) ?></a>
        <?php endif; ?>
      </p>
    </div>
  </li>
  <?php endforeach; ?>
</ul>
