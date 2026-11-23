<?php
declare(strict_types=1);
/** @var array $groups @var ?array $edit */
$title = 'Groupes';
$extraScripts = ['js/private.js'];
?>
<link rel="stylesheet" href="<?= e(asset('css/private.css')) ?>">
<div class="page-head">
  <h1>Groupes</h1>
  <p class="lead">Un groupe réunit des personnes ayant la même fonction (par exemple le Conseil intercommunal). On autorise ensuite un groupe à consulter un ou plusieurs dossiers de l'espace sécurisé.</p>
</div>

<?php if (!$groups): ?>
<div class="empty card"><p>Aucun groupe pour le moment.</p></div>
<?php else: ?>
<div class="table-wrap">
<table class="table pa-table">
  <thead><tr><th scope="col">Groupe</th><th scope="col">Description</th><th scope="col">Membres actifs</th><th scope="col">Dossiers autorisés</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
  <tbody>
  <?php foreach ($groups as $g): ?>
  <tr>
    <td data-label="Groupe"><strong><?= e($g['name']) ?></strong></td>
    <td data-label="Description"><?= e((string) $g['description']) ?></td>
    <td data-label="Membres actifs"><a href="/admin/utilisateurs?groupe=<?= (int) $g['id'] ?>"><?= (int) $g['member_count'] ?></a></td>
    <td data-label="Dossiers autorisés"><?= (int) $g['folder_count'] ?></td>
    <td class="pa-actions">
      <a class="btn btn-ghost" href="/admin/groupes?modifier=<?= (int) $g['id'] ?>#form-groupe">Modifier<span class="visually-hidden"> le groupe <?= e($g['name']) ?></span></a>
      <form method="post" action="/admin/groupes/<?= (int) $g['id'] ?>/supprimer" class="pa-inline" data-confirm="Supprimer le groupe « <?= e($g['name']) ?> » ? Ses <?= (int) $g['member_count'] ?> membre(s) perdront l'accès aux dossiers réservés à ce groupe. Les comptes ne sont pas supprimés.">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-ghost pa-text-danger">Supprimer<span class="visually-hidden"> le groupe <?= e($g['name']) ?></span></button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<form method="post" action="/admin/groupes" class="card form pa-form pa-section" id="form-groupe">
  <h2 class="h3"><?= $edit ? 'Modifier le groupe « ' . e($edit['name']) . ' »' : 'Créer un groupe' ?></h2>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
  <div class="field">
    <label for="g-name">Nom du groupe</label>
    <input type="text" id="g-name" name="name" value="<?= e($edit['name'] ?? '') ?>" required maxlength="190">
  </div>
  <div class="field">
    <label for="g-desc">Description (facultatif)</label>
    <input type="text" id="g-desc" name="description" value="<?= e($edit['description'] ?? '') ?>" maxlength="1000">
  </div>
  <div class="form-actions">
    <button type="submit" class="btn btn-primary"><?= $edit ? 'Enregistrer' : 'Créer le groupe' ?></button>
    <?php if ($edit): ?><a class="btn btn-ghost" href="/admin/groupes">Annuler</a><?php endif; ?>
  </div>
</form>
