<?php
declare(strict_types=1);
/** @var bool $sent */
$title = 'Mot de passe oublié';
$noindex = true;
?>
<link rel="stylesheet" href="<?= e(asset('css/private.css')) ?>">
<div class="container pa-auth">
  <div class="pa-card">
    <h1 class="pa-auth-title">Mot de passe oublié</h1>
    <?php if ($sent): ?>
    <div class="pa-alert pa-alert-success" role="status">Si cette adresse correspond à un compte actif, un e-mail contenant un lien de réinitialisation vient d'être envoyé. Le lien est valable 1 heure. Pensez à vérifier vos courriels indésirables.</div>
    <p class="pa-center"><a href="/connexion">Retour à la connexion</a></p>
    <?php else: ?>
    <p>Indiquez l'adresse e-mail de votre compte. Vous recevrez un lien pour choisir un nouveau mot de passe.</p>
    <form method="post" action="/mot-de-passe-oublie" class="pa-form">
      <?= csrf_field() ?>
      <div class="pa-field">
        <label for="email">Adresse e-mail</label>
        <input type="email" id="email" name="email" autocomplete="email" inputmode="email" required autofocus>
      </div>
      <button type="submit" class="btn btn-primary pa-btn-block">Recevoir le lien</button>
    </form>
    <p class="pa-muted pa-small">Vous avez reçu une invitation dont le lien a expiré ? Utilisez ce formulaire : il fonctionne aussi pour une première connexion.</p>
    <p class="pa-center"><a href="/connexion">Retour à la connexion</a></p>
    <?php endif; ?>
  </div>
</div>
