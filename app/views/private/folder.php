<?php
declare(strict_types=1);
/** @var array $folder @var array $breadcrumb @var array $subfolders @var array $files */
$title = (string) $folder['name'];
$noindex = true;
?>
<?= App\View::partial('private/_nav', ['active' => '']) ?>
<div class="container pa-page">
  <nav aria-label="Fil d'Ariane" class="pa-crumbs">
    <ol>
      <li><a href="/espace-membres">Espace membres</a></li>
      <?php foreach ($breadcrumb as $i => $c): ?>
      <li><?php if ($i === count($breadcrumb) - 1): ?><span aria-current="page"><?= e($c['name']) ?></span><?php else: ?><a href="/espace-membres/dossier/<?= (int) $c['id'] ?>"><?= e($c['name']) ?></a><?php endif; ?></li>
      <?php endforeach; ?>
    </ol>
  </nav>
  <h1 class="page-title"><?= e($folder['name']) ?></h1>
  <?php if (!empty($folder['description'])): ?><p class="pa-lead"><?= e($folder['description']) ?></p><?php endif; ?>

  <?php if ($subfolders): ?>
  <section aria-labelledby="pa-h-sub" class="pa-section">
    <h2 id="pa-h-sub">Sous-dossiers</h2>
    <?= App\View::partial('private/_folders', ['folders' => $subfolders]) ?>
  </section>
  <?php endif; ?>

  <section aria-labelledby="pa-h-files" class="pa-section">
    <h2 id="pa-h-files">Documents <span class="pa-count">(<?= count($files) ?>)</span></h2>
    <?php if ($files): ?>
    <?= App\View::partial('private/_files', ['files' => $files]) ?>
    <?php else: ?>
    <p class="pa-empty">Ce dossier ne contient pas encore de document.</p>
    <?php endif; ?>
  </section>
</div>
