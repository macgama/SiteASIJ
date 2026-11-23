<?php
declare(strict_types=1);
/** @var array $user @var array $folders @var array $recent @var bool $hasGroups */
$title = 'Espace membres';
$noindex = true;
?>
<?= App\View::partial('private/_nav', ['active' => 'home']) ?>
<div class="container pa-page">
  <h1 class="page-title">Espace membres</h1>
  <p class="pa-lead">Bienvenue <?= e($user['name']) ?>. Vous trouverez ici les documents réservés à votre fonction.</p>

  <?= App\View::partial('private/_search_form', ['q' => '']) ?>

  <?php if (!$hasGroups): ?>
  <div class="pa-alert pa-alert-info" role="status">Votre compte n'est encore rattaché à aucun groupe : aucun document ne vous est accessible pour le moment. Contactez l'administration de l'ASIJ si vous pensez qu'il s'agit d'une erreur.</div>
  <?php endif; ?>

  <section aria-labelledby="pa-h-folders" class="pa-section">
    <h2 id="pa-h-folders">Dossiers</h2>
    <?php if ($folders): ?>
    <?= App\View::partial('private/_folders', ['folders' => $folders]) ?>
    <?php elseif ($hasGroups): ?>
    <p class="pa-empty">Aucun dossier n'est encore disponible pour vous.</p>
    <?php endif; ?>
  </section>

  <?php if ($recent): ?>
  <section aria-labelledby="pa-h-recent" class="pa-section">
    <h2 id="pa-h-recent">Derniers documents ajoutés</h2>
    <?= App\View::partial('private/_files', ['files' => $recent, 'showFolder' => true]) ?>
  </section>
  <?php endif; ?>
</div>
