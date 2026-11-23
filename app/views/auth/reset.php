<?php
declare(strict_types=1);
/** @var string $token @var array $user @var bool $invite @var ?string $error */
$title = $invite ? 'Activer votre compte' : 'Nouveau mot de passe';
$noindex = true;
?>
<link rel="stylesheet" href="<?= e(asset('css/private.css')) ?>">
<div class="container pa-auth">
  <div class="pa-card">
    <h1 class="pa-auth-title"><?= $invite ? 'Bienvenue, ' . e($user['name']) : 'Choisir un nouveau mot de passe' ?></h1>
    <?php if ($invite): ?>
    <p>Choisissez le mot de passe de votre compte. Vous vous connecterez ensuite avec votre adresse e-mail <strong><?= e($user['email']) ?></strong>.</p>
    <?php else: ?>
    <p>Compte : <strong><?= e($user['email']) ?></strong></p>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="pa-alert pa-alert-error" role="alert" id="pw-error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/reinitialiser/<?= e($token) ?>" class="pa-form">
      <?= csrf_field() ?>
      <input type="text" name="username" value="<?= e($user['email']) ?>" autocomplete="username" hidden>
      <div class="pa-field">
        <label for="password">Nouveau mot de passe</label>
        <input type="password" id="password" name="password" autocomplete="new-password" minlength="12" required aria-describedby="pw-help<?= $error ? ' pw-error' : '' ?>" autofocus>
        <p class="pa-help" id="pw-help">Au moins 12 caractères. Conseil : une phrase facile à retenir, par exemple « Mon chat aime le Jorat ! ».</p>
      </div>
      <div class="pa-field">
        <label for="password_confirm">Répéter le mot de passe</label>
        <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" minlength="12" required>
        <label class="pa-check pa-showpw"><input type="checkbox" data-show-password="password password_confirm"> Afficher les mots de passe</label>
      </div>
      <button type="submit" class="btn btn-primary pa-btn-block">Enregistrer le mot de passe</button>
    </form>
  </div>
</div>
<script src="<?= e(asset('js/private.js')) ?>" defer></script>
