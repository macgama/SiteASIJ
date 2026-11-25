<?php
declare(strict_types=1);
/** @var string $q @var array $results */
$title = 'Rechercher un document';
$noindex = true;
?>
<?= App\View::partial('private/_nav', ['active' => 'search']) ?>
<div class="container pa-page">
  <h1 class="page-title">Rechercher un document</h1>
  <?= App\View::partial('private/_search_form', ['q' => $q]) ?>
  <div aria-live="polite">
  <?php if ($q !== '' && mb_strlen($q) < 2): ?>
    <p class="pa-empty">Saisissez au moins 2 caractères.</p>
  <?php elseif ($q !== ''): ?>
    <h2><?= count($results) ?> résultat<?= count($results) > 1 ? 's' : '' ?> pour « <?= e($q) ?> »</h2>
    <?php if ($results): ?>
    <?= App\View::partial('private/_files', ['files' => $results, 'showFolder' => true]) ?>
    <?php else: ?>
    <p class="pa-empty">Aucun document ne correspond. Essayez un autre mot, par exemple une partie du titre ou une année.</p>
    <?php endif; ?>
  <?php endif; ?>
  </div>
</div>
