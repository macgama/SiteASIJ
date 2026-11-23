<?php
/** Aperçu de secours (si le gabarit public n'est pas disponible). Variables: $page, $blocks */
$layout = is_file(APP . '/views/layout.php') ? 'layout' : null;
?>
<?= App\View::partial('admin/pages/_preview-bar', ['page' => $page]) ?>
<article class="container page-content">
  <h1><?= e($page['title']) ?></h1>
  <?php if (class_exists(App\Blocks::class)): ?>
    <?= App\Blocks::render($blocks) ?>
  <?php else: ?>
    <?php foreach ($blocks as $b): ?>
      <section><p><em><?= e($b['type'] ?? '') ?></em></p><?= ($b['type'] ?? '') === 'text' ? ($b['data']['html'] ?? '') : '' ?></section>
    <?php endforeach; ?>
  <?php endif; ?>
</article>
