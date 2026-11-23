<?php
declare(strict_types=1);
/** @var array $users @var array $groupsByUser @var int[] $pendingInvites @var array $groups @var string $q @var int $group @var string $status @var int $total */
$title = 'Utilisateurs';
$extraScripts = ['js/private.js'];
?>
<link rel="stylesheet" href="<?= e(asset('css/private.css')) ?>">
<div class="page-head pa-head-actions">
  <div>
    <h1>Utilisateurs</h1>
    <p class="lead">Chaque personne a son propre compte. Les groupes déterminent les dossiers confidentiels qu'elle peut consulter.</p>
  </div>
  <div class="btn-row">
    <a class="btn btn-primary" href="/admin/utilisateurs/nouveau">Ajouter une personne</a>
    <a class="btn" href="/admin/utilisateurs/import">Importer une liste (CSV)</a>
    <a class="btn btn-ghost" href="/admin/groupes">Gérer les groupes</a>
  </div>
</div>

<form class="toolbar pa-filters" method="get" action="/admin/utilisateurs" role="search">
  <div class="field">
    <label for="u-q">Rechercher</label>
    <input type="search" id="u-q" name="q" value="<?= e($q) ?>" placeholder="Nom ou e-mail">
  </div>
  <div class="field">
    <label for="u-g">Groupe</label>
    <select id="u-g" name="groupe">
      <option value="0">Tous les groupes</option>
      <?php foreach ($groups as $g): ?>
      <option value="<?= (int) $g['id'] ?>"<?= (int) $g['id'] === $group ? ' selected' : '' ?>><?= e($g['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="u-s">Statut</label>
    <select id="u-s" name="statut">
      <option value="actifs"<?= $status === 'actifs' ? ' selected' : '' ?>>Comptes actifs</option>
      <option value="inactifs"<?= $status === 'inactifs' ? ' selected' : '' ?>>Comptes désactivés</option>
      <option value="tous"<?= $status === 'tous' ? ' selected' : '' ?>>Tous</option>
    </select>
  </div>
  <button type="submit" class="btn">Filtrer</button>
</form>

<p class="muted"><?= count($users) ?> compte<?= count($users) > 1 ? 's' : '' ?> affiché<?= count($users) > 1 ? 's' : '' ?> sur <?= $total ?>.</p>

<?php if (!$users): ?>
<div class="empty card"><p>Aucun compte ne correspond à ces critères.</p></div>
<?php else: ?>
<div class="table-wrap">
<table class="table pa-table">
  <thead><tr>
    <th scope="col">Nom</th><th scope="col">E-mail</th><th scope="col">Groupes</th><th scope="col">Statut</th>
    <th scope="col">Dernière connexion</th><th scope="col">Double auth.</th>
  </tr></thead>
  <tbody>
  <?php foreach ($users as $u): $uid = (int) $u['id']; ?>
    <tr>
      <td data-label="Nom"><a href="/admin/utilisateurs/<?= $uid ?>"><strong><?= e($u['name']) ?></strong></a>
        <?php if ($u['role'] === 'admin'): ?> <span class="badge pa-badge pa-badge-admin">Admin</span><?php endif; ?></td>
      <td data-label="E-mail" class="break"><?= e($u['email']) ?></td>
      <td data-label="Groupes"><?= !empty($groupsByUser[$uid]) ? e(implode(', ', $groupsByUser[$uid])) : '<span class="muted">Aucun</span>' ?></td>
      <td data-label="Statut"><?php if ((int) $u['is_active'] !== 1): ?><span class="badge pa-badge pa-badge-off">Désactivé</span>
        <?php elseif ($u['last_login_at'] === null): ?><span class="badge pa-badge pa-badge-wait"><?= in_array($uid, $pendingInvites, true) ? 'Invitation envoyée' : 'Jamais connecté·e' ?></span>
        <?php else: ?><span class="badge pa-badge pa-badge-ok">Actif</span><?php endif; ?></td>
      <td data-label="Dernière connexion" class="nowrap"><?= $u['last_login_at'] ? e(date_fr($u['last_login_at'], true)) : '<span class="muted">—</span>' ?></td>
      <td data-label="Double auth."><?= !empty($u['totp_secret']) ? 'Oui' : '<span class="muted">Non</span>' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
