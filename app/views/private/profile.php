<?php
declare(strict_types=1);
/** @var array $user @var array $groups @var ?array $setup @var bool $adminMust2fa */
$title = 'Mon compte';
$noindex = true;
?>
<?= App\View::partial('private/_nav', ['active' => 'profile']) ?>
<div class="container pa-page pa-narrow">
  <h1 class="page-title">Mon compte</h1>

  <section class="pa-panel" aria-labelledby="pa-h-info">
    <h2 id="pa-h-info">Mes informations</h2>
    <dl class="pa-dl">
      <dt>Nom</dt><dd><?= e($user['name']) ?></dd>
      <dt>Adresse e-mail (identifiant)</dt><dd><?= e($user['email']) ?></dd>
      <dt>Groupes</dt><dd><?= $groups ? e(implode(', ', array_column($groups, 'name'))) : 'Aucun' ?></dd>
      <?php if ($user['role'] === 'admin'): ?><dt>Rôle</dt><dd>Administrateur·trice (<a href="/admin">administration</a>)</dd><?php endif; ?>
    </dl>
    <p class="pa-help">Pour modifier votre nom, votre adresse ou vos groupes, adressez-vous à l'administration de l'ASIJ.</p>
  </section>

  <section class="pa-panel" id="mot-de-passe" aria-labelledby="pa-h-pw">
    <h2 id="pa-h-pw">Changer mon mot de passe</h2>
    <form method="post" action="/espace-membres/profil/mot-de-passe" class="pa-form">
      <?= csrf_field() ?>
      <input type="text" name="username" value="<?= e($user['email']) ?>" autocomplete="username" hidden>
      <div class="pa-field">
        <label for="current_password">Mot de passe actuel</label>
        <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
      </div>
      <div class="pa-field">
        <label for="password">Nouveau mot de passe</label>
        <input type="password" id="password" name="password" autocomplete="new-password" minlength="12" required aria-describedby="pw-help">
        <p class="pa-help" id="pw-help">Au moins 12 caractères. Une phrase facile à retenir est idéale.</p>
      </div>
      <div class="pa-field">
        <label for="password_confirm">Répéter le nouveau mot de passe</label>
        <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" minlength="12" required>
        <label class="pa-check pa-showpw"><input type="checkbox" data-show-password="current_password password password_confirm"> Afficher les mots de passe</label>
      </div>
      <button type="submit" class="btn btn-primary">Enregistrer le nouveau mot de passe</button>
    </form>
  </section>

  <section class="pa-panel" id="double-authentification" aria-labelledby="pa-h-2fa">
    <h2 id="pa-h-2fa">Double authentification</h2>
    <?php if (!empty($user['totp_secret'])): ?>
    <p><span class="pa-badge pa-badge-ok">Activée</span> À chaque connexion, un code de votre téléphone vous est demandé en plus du mot de passe.</p>
    <?php if ($adminMust2fa): ?>
    <p class="pa-help">Elle est obligatoire pour les administrateurs et ne peut pas être désactivée.</p>
    <?php else: ?>
    <details class="pa-details">
      <summary>Désactiver la double authentification</summary>
      <form method="post" action="/espace-membres/profil/2fa/desactiver" class="pa-form">
        <?= csrf_field() ?>
        <div class="pa-field">
          <label for="disable_pw">Votre mot de passe (pour confirmer)</label>
          <input type="password" id="disable_pw" name="current_password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn pa-btn-danger">Désactiver</button>
      </form>
    </details>
    <?php endif; ?>
    <?php elseif ($setup): ?>
    <p>Suivez ces étapes pour relier votre téléphone à votre compte :</p>
    <?= App\View::partial('private/_totp_steps', $setup) ?>
    <form method="post" action="/espace-membres/profil/2fa" class="pa-form">
      <?= csrf_field() ?>
      <input type="hidden" name="step" value="confirm">
      <div class="pa-field">
        <label for="code">Code à 6 chiffres</label>
        <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" class="pa-code" required>
      </div>
      <button type="submit" class="btn btn-primary">Vérifier et activer</button>
    </form>
    <form method="post" action="/espace-membres/profil/2fa" class="pa-inline">
      <?= csrf_field() ?>
      <input type="hidden" name="step" value="cancel">
      <button type="submit" class="pa-linkbtn">Annuler</button>
    </form>
    <?php else: ?>
    <p><span class="pa-badge">Non activée</span> Protégez votre compte : même si quelqu'un découvre votre mot de passe, il ne pourra pas se connecter sans votre téléphone.</p>
    <?php if ($adminMust2fa): ?><div class="pa-alert pa-alert-warning" role="status">Elle est obligatoire pour les administrateurs.</div><?php endif; ?>
    <form method="post" action="/espace-membres/profil/2fa">
      <?= csrf_field() ?>
      <input type="hidden" name="step" value="start">
      <button type="submit" class="btn btn-primary">Activer la double authentification</button>
    </form>
    <?php endif; ?>
  </section>
</div>
