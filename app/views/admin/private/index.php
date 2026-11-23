<?php
declare(strict_types=1);
/** @var array $tree @var array $groupNames @var array $counts @var array $groups @var array $folderOptions */
$title = 'Espace sécurisé';
$extraScripts = ['js/private.js'];
?>
<link rel="stylesheet" href="<?= e(asset('css/private.css')) ?>">
<div class="page-head pa-head-actions">
  <div>
    <h1>Espace sécurisé</h1>
    <p class="lead">Les dossiers confidentiels et les groupes qui peuvent les consulter. Cliquez sur un dossier pour y ajouter des documents ou modifier ses autorisations.</p>
  </div>
  <div class="btn-row">
    <a class="btn btn-ghost" href="/espace-membres">Voir comme un membre</a>
  </div>
</div>

<section class="card pa-section" aria-labelledby="h-tree">
  <h2 id="h-tree" class="h3">Dossiers</h2>
  <?php if ($tree): ?>
  <?= App\View::partial('admin/private/_tree', ['nodes' => $tree, 'groupNames' => $groupNames, 'counts' => $counts]) ?>
  <?php else: ?>
  <p class="muted">Aucun dossier pour le moment. Créez le premier ci-dessous, par exemple « Conseil intercommunal – séances 2026 ».</p>
  <?php endif; ?>
</section>

<form method="post" action="/admin/espace/dossiers" class="card form pa-form pa-section" id="nouveau-dossier">
  <h2 class="h3">Créer un dossier</h2>
  <?= App\View::partial('admin/private/_folder_form', ['folder' => null, 'groups' => $groups, 'folderOptions' => $folderOptions, 'defaultParent' => 0]) ?>
  <div class="form-actions"><button type="submit" class="btn btn-primary">Créer le dossier</button></div>
</form>
