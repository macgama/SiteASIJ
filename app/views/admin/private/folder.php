<?php
declare(strict_types=1);
/** @var array $folder @var array $breadcrumb @var array $children @var array $counts @var array $files @var array $groups
 *  @var int[] $direct @var int[] $inherited @var array $folderOptions @var int $maxBytes @var string[] $extensions */
$title = (string) $folder['name'];
$extraScripts = ['js/private.js'];
$fid = (int) $folder['id'];
$allowedNames = array_unique(array_map(fn($x) => $x === 'jpeg' ? 'jpg' : $x, $extensions));
$groupName = array_column($groups, 'name', 'id');
$effective = array_unique(array_merge($direct, $inherited));
?>
<link rel="stylesheet" href="<?= e(asset('css/private.css')) ?>">
<nav aria-label="Fil d'Ariane" class="pa-crumbs">
  <ol>
    <li><a href="/admin/espace">Espace sécurisé</a></li>
    <?php foreach ($breadcrumb as $i => $c): ?>
    <li><?php if ($i === count($breadcrumb) - 1): ?><span aria-current="page"><?= e($c['name']) ?></span><?php else: ?><a href="/admin/espace/dossier/<?= (int) $c['id'] ?>"><?= e($c['name']) ?></a><?php endif; ?></li>
    <?php endforeach; ?>
  </ol>
</nav>
<div class="page-head">
  <h1><?= e($folder['name']) ?></h1>
  <p class="lead">
    <?php if ($effective): ?>Consultable par : <strong><?= e(implode(', ', array_map(fn($g) => $groupName[$g] ?? '?', $effective))) ?></strong> (et les administrateurs).
    <?php else: ?><span class="pa-warn-text">Aucun groupe autorisé : seuls les administrateurs voient ce dossier.</span><?php endif; ?>
  </p>
</div>

<section class="card pa-section" aria-labelledby="h-files">
  <h2 id="h-files" class="h3">Documents (<?= count($files) ?>)</h2>
  <form method="post" action="/admin/espace/dossier/<?= $fid ?>/fichiers" enctype="multipart/form-data" class="pa-upload">
    <?= csrf_field() ?>
    <div class="field">
      <label for="files">Ajouter des documents</label>
      <input type="file" id="files" name="files[]" multiple required aria-describedby="files-help" accept="<?= e(implode(',', array_map(fn($x) => '.' . $x, $extensions))) ?>">
      <p class="help" id="files-help">Vous pouvez en choisir plusieurs à la fois. Formats acceptés : <?= e(implode(', ', array_map('strtoupper', $allowedNames))) ?>. Taille maximale : <?= e(human_size($maxBytes)) ?> par fichier.</p>
    </div>
    <div class="field">
      <label for="title">Titre (facultatif, si un seul document)</label>
      <input type="text" id="title" name="title" maxlength="255" aria-describedby="title-help">
      <p class="help" id="title-help">Sans titre, le nom du fichier est utilisé.</p>
    </div>
    <button type="submit" class="btn btn-primary">Envoyer</button>
  </form>

  <?php if ($files): ?>
  <div class="table-wrap">
  <table class="table pa-table">
    <thead><tr><th scope="col">Document</th><th scope="col">Taille</th><th scope="col">Ajouté</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($files as $f): ?>
      <tr>
        <td data-label="Document"><a href="/espace-membres/fichier/<?= (int) $f['id'] ?>"><?= e($f['title'] ?: $f['original_name']) ?></a><br><span class="muted small break"><?= e($f['original_name']) ?></span></td>
        <td data-label="Taille" class="nowrap"><?= e(human_size((int) $f['size'])) ?></td>
        <td data-label="Ajouté" class="nowrap"><?= e(date_fr($f['created_at'])) ?><?= $f['uploader'] ? '<br><span class="muted small">' . e($f['uploader']) . '</span>' : '' ?></td>
        <td class="pa-actions">
          <form method="post" action="/admin/espace/fichiers/<?= (int) $f['id'] ?>/supprimer" data-confirm="Supprimer définitivement « <?= e($f['title'] ?: $f['original_name']) ?> » ?">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-ghost pa-text-danger">Supprimer<span class="visually-hidden"> <?= e($f['title'] ?: $f['original_name']) ?></span></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <p class="muted">Aucun document dans ce dossier.</p>
  <?php endif; ?>
</section>

<section class="card pa-section" aria-labelledby="h-sub">
  <h2 id="h-sub" class="h3">Sous-dossiers</h2>
  <?php if ($children): ?>
  <ul class="pa-tree" role="list">
    <?php foreach ($children as $c): ?>
    <li><div class="pa-tree-row"><a class="pa-tree-name" href="/admin/espace/dossier/<?= (int) $c['id'] ?>"><?= e($c['name']) ?></a> <span class="muted small"><?= (int) ($counts[(int) $c['id']] ?? 0) ?> doc.</span></div></li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?>
  <p class="muted">Aucun sous-dossier.</p>
  <?php endif; ?>
  <details class="pa-details">
    <summary>Créer un sous-dossier</summary>
    <form method="post" action="/admin/espace/dossiers" class="pa-form">
      <?= App\View::partial('admin/private/_folder_form', ['folder' => null, 'groups' => $groups, 'folderOptions' => array_merge([['id' => $fid, 'label' => $folder['name']]], $folderOptions), 'defaultParent' => $fid, 'inherited' => $effective]) ?>
      <div class="form-actions"><button type="submit" class="btn btn-primary">Créer le sous-dossier</button></div>
    </form>
  </details>
</section>

<form method="post" action="/admin/espace/dossiers" class="card form pa-form pa-section" aria-labelledby="h-settings">
  <h2 id="h-settings" class="h3">Nom, emplacement et autorisations</h2>
  <?= App\View::partial('admin/private/_folder_form', ['folder' => $folder, 'groups' => $groups, 'direct' => $direct, 'inherited' => $inherited, 'folderOptions' => $folderOptions]) ?>
  <div class="form-actions"><button type="submit" class="btn btn-primary">Enregistrer</button></div>
</form>

<section class="card pa-section pa-danger-zone" aria-labelledby="h-del">
  <h2 id="h-del" class="h3">Supprimer ce dossier</h2>
  <?php if ($files || $children): ?>
  <p class="muted">Seul un dossier vide peut être supprimé. Supprimez ou déplacez d'abord ses documents et sous-dossiers.</p>
  <?php else: ?>
  <form method="post" action="/admin/espace/dossiers/<?= $fid ?>/supprimer" data-confirm="Supprimer le dossier « <?= e($folder['name']) ?> » ?">
    <?= csrf_field() ?>
    <label class="pa-check"><input type="checkbox" name="confirm" value="1" required> Je confirme la suppression de ce dossier vide.</label>
    <button type="submit" class="btn btn-danger pa-btn-danger">Supprimer le dossier</button>
  </form>
  <?php endif; ?>
</section>
