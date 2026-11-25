<?php
declare(strict_types=1);
/** @var string $email @var ?string $error */
$title = 'Connexion';
$noindex = true;
$bodyClass = 'page-auth';
?>
<link rel="stylesheet" href="<?= e(asset('css/private.css')) ?>">
<div class="container pa-auth">
  <div class="pa-card">
    <h1 class="pa-auth-title">Connexion à l'espace membres</h1>
    <p class="pa-muted">Espace réservé aux membres du Comité de direction, du Conseil intercommunal, du Bureau et de la Direction.</p>
    <?php if ($error): ?>
    <div class="pa-alert pa-alert-error" role="alert" id="login-error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/connexion" class="pa-form" novalidate>
      <?= csrf_field() ?>
      <div class="pa-field">
        <label for="email">Adresse e-mail</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="username" inputmode="email" required autofocus<?= $error ? ' aria-describedby="login-error"' : '' ?>>
      </div>
      <div class="pa-field">
        <label for="password">Mot de passe</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
        <label class="pa-check pa-showpw"><input type="checkbox" data-show-password="password"> Afficher le mot de passe</label>
      </div>
      <button type="submit" class="btn btn-primary pa-btn-block">Se connecter</button>
    </form>
    <p class="pa-center"><a href="/mot-de-passe-oublie">Mot de passe oublié ou première connexion ?</a></p>
  </div>
</div>
<script src="<?= e(asset('js/private.js')) ?>" defer></script>
