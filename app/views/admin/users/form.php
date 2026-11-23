<?php
declare(strict_types=1);
/** @var array $u @var int[] $userGroups @var array $groups @var array $errors @var bool $isSelf @var bool $pendingInvite */
$isNew = empty($u['id']);
$title = $isNew ? 'Ajouter une personne' : (string) $u['name'];
$extraScripts = ['js/private.js'];
$pendingInvite = $pendingInvite ?? false;
$err = fn(string $k) => isset($errors[$k]) ? '<p class="error" id="err-' . e($k) . '">' . e($errors[$k]) . '</p>' : '';
$desc = fn(string $k, string $extra = '') => trim((isset($errors[$k]) ? 'err-' . $k : '') . ' ' . $extra);
?>
<link rel="stylesheet" href="<?= e(asset('css/private.css')) ?>">
<p class="pa-back"><a href="/admin/utilisateurs">← Retour à la liste des utilisateurs</a></p>
<div class="page-head">
  <h1><?= e($title) ?></h1>
  <?php if (!$isNew): ?>
  <p class="lead">
    <?php if ((int) $u['is_active'] !== 1): ?>Compte désactivé : la personne ne peut pas se connecter.
    <?php elseif ($u['last_login_at']): ?>Dernière connexion le <?= e(date_fr($u['last_login_at'], true)) ?>.
    <?php else: ?>Cette personne ne s'est encore jamais connectée<?= $pendingInvite ? ' (invitation en cours de validité)' : '' ?>.<?php endif; ?>
  </p>
  <?php endif; ?>
</div>

<?php if ($errors): ?>
<div class="flash flash-error" role="alert">Le formulaire contient des erreurs. Merci de les corriger ci-dessous.</div>
<?php endif; ?>

<form method="post" action="<?= $isNew ? '/admin/utilisateurs' : '/admin/utilisateurs/' . (int) $u['id'] ?>" class="card form pa-form">
  <?= csrf_field() ?>
  <div class="field">
    <label for="name">Nom et prénom</label>
    <input type="text" id="name" name="name" value="<?= e($u['name']) ?>" required maxlength="190" autocomplete="off"<?= $desc('name') ? ' aria-describedby="' . e($desc('name')) . '" aria-invalid="true"' : '' ?>>
    <?= $err('name') ?>
  </div>
  <div class="field">
    <label for="email">Adresse e-mail</label>
    <input type="email" id="email" name="email" value="<?= e($u['email']) ?>" required maxlength="190" autocomplete="off" aria-describedby="<?= e($desc('email', 'email-help')) ?>"<?= isset($errors['email']) ? ' aria-invalid="true"' : '' ?>>
    <p class="help" id="email-help">Elle sert d'identifiant de connexion et reçoit l'invitation.</p>
    <?= $err('email') ?>
  </div>

  <fieldset class="field pa-fieldset">
    <legend>Groupes</legend>
    <p class="help">La personne pourra consulter les dossiers autorisés à ces groupes.</p>
    <?php if (!$groups): ?><p class="muted">Aucun groupe. <a href="/admin/groupes">Créer un groupe</a></p><?php endif; ?>
    <?php foreach ($groups as $g): $gid = (int) $g['id']; ?>
    <label class="pa-check"><input type="checkbox" name="groups[]" value="<?= $gid ?>"<?= in_array($gid, $userGroups, true) ? ' checked' : '' ?>> <?= e($g['name']) ?></label>
    <?php endforeach; ?>
  </fieldset>

  <fieldset class="field pa-fieldset">
    <legend>Rôle</legend>
    <label class="pa-check"><input type="radio" name="role" value="member"<?= $u['role'] !== 'admin' ? ' checked' : '' ?><?= $isSelf ? ' disabled' : '' ?>> <strong>Membre</strong> — consulte les documents de ses groupes</label>
    <label class="pa-check"><input type="radio" name="role" value="admin"<?= $u['role'] === 'admin' ? ' checked' : '' ?>> <strong>Administrateur·trice</strong> — gère le site, les comptes et tous les documents</label>
    <?php if ($isSelf): ?><input type="hidden" name="role" value="admin"><p class="help">Vous ne pouvez pas retirer votre propre rôle d'administrateur.</p><?php endif; ?>
    <?= $err('role') ?>
  </fieldset>

  <div class="field">
    <?php if ($isSelf): ?>
    <input type="hidden" name="is_active" value="1">
    <?php else: ?>
    <label class="pa-check"><input type="checkbox" name="is_active" value="1"<?= (int) $u['is_active'] === 1 ? ' checked' : '' ?>> Compte actif (la personne peut se connecter)</label>
    <?php endif; ?>
    <?= $err('is_active') ?>
  </div>

  <?php if ($isNew): ?>
  <div class="field pa-highlight">
    <label class="pa-check"><input type="checkbox" name="send_invite" value="1" checked> Envoyer maintenant une invitation par e-mail</label>
    <p class="help">La personne reçoit un lien personnel (valable 7 jours) pour choisir elle-même son mot de passe. Aucun mot de passe n'est jamais envoyé par e-mail.</p>
  </div>
  <?php elseif (!empty($u['totp_secret'])): ?>
  <div class="field">
    <label class="pa-check"><input type="checkbox" name="reset_2fa" value="1"> Réinitialiser la double authentification (téléphone perdu ou changé)</label>
    <p class="help">La personne pourra se connecter avec son seul mot de passe, puis réactiver la double authentification depuis « Mon compte ».</p>
  </div>
  <?php endif; ?>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary"><?= $isNew ? 'Créer le compte' : 'Enregistrer les modifications' ?></button>
    <a class="btn btn-ghost" href="/admin/utilisateurs">Annuler</a>
  </div>
</form>

<?php if (!$isNew): ?>
<section class="card pa-section" aria-labelledby="h-invite">
  <h2 id="h-invite" class="h3">Invitation et mot de passe</h2>
  <p>Envoie à <?= e($u['email']) ?> un lien personnel, valable 7 jours, pour choisir <?= $u['last_login_at'] ? 'un nouveau' : 'son' ?> mot de passe.</p>
  <form method="post" action="/admin/utilisateurs/<?= (int) $u['id'] ?>/invitation">
    <?= csrf_field() ?>
    <button type="submit" class="btn"<?= (int) $u['is_active'] !== 1 ? ' disabled' : '' ?>><?= $u['last_login_at'] ? 'Envoyer un lien de réinitialisation' : ($pendingInvite ? 'Renvoyer l\'invitation' : 'Envoyer l\'invitation') ?></button>
  </form>
</section>

<?php if (!$isSelf): ?>
<section class="card pa-section pa-danger-zone" aria-labelledby="h-remove">
  <h2 id="h-remove" class="h3">Retirer l'accès</h2>
  <?php if ((int) $u['is_active'] === 1): ?>
  <p><strong>Recommandé :</strong> désactiver le compte. La personne ne peut plus se connecter, mais le compte et son historique sont conservés et peuvent être réactivés.</p>
  <form method="post" action="/admin/utilisateurs/<?= (int) $u['id'] ?>/supprimer" data-confirm="Désactiver le compte de <?= e($u['name']) ?> ?">
    <?= csrf_field() ?>
    <input type="hidden" name="mode" value="deactivate">
    <button type="submit" class="btn">Désactiver le compte</button>
  </form>
  <?php endif; ?>
  <details class="pa-details">
    <summary>Supprimer définitivement</summary>
    <form method="post" action="/admin/utilisateurs/<?= (int) $u['id'] ?>/supprimer" data-confirm="Supprimer définitivement le compte de <?= e($u['name']) ?> ? Cette action est irréversible.">
      <?= csrf_field() ?>
      <input type="hidden" name="mode" value="delete">
      <label class="pa-check"><input type="checkbox" name="confirm" value="1" required> Je confirme vouloir supprimer ce compte. Cette action est irréversible.</label>
      <button type="submit" class="btn btn-danger pa-btn-danger">Supprimer définitivement</button>
    </form>
  </details>
</section>
<?php endif; ?>
<?php endif; ?>
