<?php
declare(strict_types=1);
/** @var ?array $preview @var array $groups @var ?string $error */
$title = 'Importer des utilisateurs';
$extraScripts = ['js/private.js'];
?>
<link rel="stylesheet" href="<?= e(asset('css/private.css')) ?>">
<p class="pa-back"><a href="/admin/utilisateurs">← Retour à la liste des utilisateurs</a></p>
<div class="page-head">
  <h1>Importer une liste de personnes</h1>
  <p class="lead">Créez plusieurs comptes d'un coup à partir d'un tableau Excel enregistré au format CSV.</p>
</div>

<?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif; ?>

<?php if ($preview === null): ?>
<section class="card pa-section">
  <h2 class="h3">1. Préparer le fichier</h2>
  <p>Dans Excel, créez 4 colonnes dans cet ordre, puis « Enregistrer sous » → <strong>CSV (séparateur : point-virgule)</strong> ou <strong>CSV UTF-8</strong> :</p>
  <div class="table-wrap"><table class="table pa-table pa-sample">
    <thead><tr><th scope="col">nom</th><th scope="col">email</th><th scope="col">groupes</th><th scope="col">role</th></tr></thead>
    <tbody>
      <tr><td>Marie Dupont</td><td>marie.dupont@exemple.ch</td><td>Conseil intercommunal</td><td>membre</td></tr>
      <tr><td>Jean Martin</td><td>j.martin@exemple.ch</td><td>Comité de direction, Bureau du Conseil</td><td>membre</td></tr>
    </tbody>
  </table></div>
  <ul class="pa-help-list">
    <li>Plusieurs groupes : séparez-les par une virgule.</li>
    <li>Rôle : <em>membre</em> (ou vide) ou <em>admin</em>.</li>
    <li>Groupes existants : <?= $groups ? e(implode(' · ', array_column($groups, 'name'))) : 'aucun' ?> (<a href="/admin/groupes">gérer</a>).</li>
    <li>Une première ligne de titres est ignorée automatiquement. Les personnes déjà inscrites sont ignorées.</li>
  </ul>
</section>
<form method="post" action="/admin/utilisateurs/import" enctype="multipart/form-data" class="card form pa-form pa-section">
  <h2 class="h3">2. Envoyer le fichier</h2>
  <?= csrf_field() ?>
  <input type="hidden" name="step" value="preview">
  <div class="field">
    <label for="csv">Fichier CSV</label>
    <input type="file" id="csv" name="csv" accept=".csv,.txt,text/csv,text/plain">
  </div>
  <details class="pa-details">
    <summary>… ou coller le contenu directement</summary>
    <div class="field">
      <label for="csv_text">Contenu (une personne par ligne)</label>
      <textarea id="csv_text" name="csv_text" rows="8" placeholder="Marie Dupont;marie.dupont@exemple.ch;Conseil intercommunal;membre"></textarea>
    </div>
  </details>
  <div class="form-actions">
    <button type="submit" class="btn btn-primary">Voir l'aperçu</button>
  </div>
  <p class="help">Rien n'est encore créé à cette étape : vous verrez d'abord un aperçu.</p>
</form>
<?php else: ?>
<section class="card pa-section" aria-labelledby="h-prev">
  <h2 id="h-prev" class="h3">Aperçu : <?= count($preview['rows']) ?> compte<?= count($preview['rows']) > 1 ? 's' : '' ?> à créer</h2>
  <?php if ($preview['rows']): ?>
  <div class="table-wrap"><table class="table pa-table">
    <thead><tr><th scope="col">Ligne</th><th scope="col">Nom</th><th scope="col">E-mail</th><th scope="col">Groupes</th><th scope="col">Rôle</th></tr></thead>
    <tbody>
    <?php foreach ($preview['rows'] as $r): ?>
      <tr><td data-label="Ligne"><?= (int) $r['line'] ?></td><td data-label="Nom"><?= e($r['name']) ?></td><td data-label="E-mail" class="break"><?= e($r['email']) ?></td>
      <td data-label="Groupes"><?= $r['group_names'] ? e(implode(', ', $r['group_names'])) : '<span class="muted">Aucun</span>' ?></td>
      <td data-label="Rôle"><?= $r['role'] === 'admin' ? '<strong>Admin</strong>' : 'Membre' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<?php if ($preview['errors']): ?>
<section class="card pa-section pa-danger-zone" aria-labelledby="h-err">
  <h2 id="h-err" class="h3"><?= count($preview['errors']) ?> ligne<?= count($preview['errors']) > 1 ? 's' : '' ?> en erreur (ne seront pas importées)</h2>
  <ul class="pa-errlist">
    <?php foreach ($preview['errors'] as $er): ?>
    <li><strong>Ligne <?= (int) $er['line'] ?></strong> : <?= e($er['message']) ?><br><code><?= e(mb_substr($er['raw'], 0, 200)) ?></code></li>
    <?php endforeach; ?>
  </ul>
  <p class="help">Corrigez ces lignes dans votre fichier puis importez-le à nouveau : les comptes déjà créés seront ignorés.</p>
</section>
<?php endif; ?>

<?php if ($preview['skipped']): ?>
<section class="card pa-section">
  <h2 class="h3"><?= count($preview['skipped']) ?> personne<?= count($preview['skipped']) > 1 ? 's' : '' ?> déjà inscrite<?= count($preview['skipped']) > 1 ? 's' : '' ?> (ignorée<?= count($preview['skipped']) > 1 ? 's' : '' ?>)</h2>
  <p class="muted"><?= e(implode(', ', array_column($preview['skipped'], 'email'))) ?></p>
</section>
<?php endif; ?>

<?php if ($preview['rows']): ?>
<form method="post" action="/admin/utilisateurs/import" class="card form pa-form pa-section">
  <?= csrf_field() ?>
  <input type="hidden" name="step" value="confirm">
  <input type="hidden" name="key" value="<?= e($preview['key']) ?>">
  <label class="pa-check"><input type="checkbox" name="send_invites" value="1"> Envoyer tout de suite une invitation par e-mail à chaque personne créée</label>
  <p class="help">Vous pourrez aussi les envoyer plus tard, une par une, depuis la fiche de chaque personne.</p>
  <div class="form-actions">
    <button type="submit" class="btn btn-primary"><?= count($preview['rows']) > 1 ? 'Créer les ' . count($preview['rows']) . ' comptes' : 'Créer le compte' ?></button>
    <a class="btn btn-ghost" href="/admin/utilisateurs/import">Recommencer</a>
  </div>
</form>
<?php else: ?>
<p><a class="btn" href="/admin/utilisateurs/import">Recommencer avec un autre fichier</a></p>
<?php endif; ?>
<?php endif; ?>
