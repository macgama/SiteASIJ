<?php
declare(strict_types=1);
/** @var string $mode 'verify'|'setup' @var ?string $error */
$title = $mode === 'setup' ? 'Activer la double authentification' : 'Code de sécurité';
$noindex = true;
?>
<link rel="stylesheet" href="<?= e(asset('css/private.css')) ?>">
<div class="container pa-auth">
  <div class="pa-card">
    <?php if ($mode === 'setup'): ?>
    <h1 class="pa-auth-title">Activer la double authentification</h1>
    <p>Pour protéger les documents confidentiels, les comptes administrateurs doivent utiliser un code temporaire en plus du mot de passe.</p>
    <?= App\View::partial('private/_totp_steps', ['secretFormatted' => $secretFormatted, 'uri' => $uri]) ?>
    <?php else: ?>
    <h1 class="pa-auth-title">Code de sécurité</h1>
    <p>Ouvrez l'application d'authentification de votre téléphone et saisissez le code à 6 chiffres affiché pour « ASIJ ».</p>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="pa-alert pa-alert-error" role="alert" id="code-error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/connexion/code" class="pa-form">
      <?= csrf_field() ?>
      <div class="pa-field">
        <label for="code">Code à 6 chiffres</label>
        <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" class="pa-code" required autofocus<?= $error ? ' aria-describedby="code-error"' : '' ?>>
      </div>
      <button type="submit" class="btn btn-primary pa-btn-block"><?= $mode === 'setup' ? 'Vérifier et activer' : 'Valider' ?></button>
    </form>
    <p class="pa-center pa-muted pa-small">Le code change toutes les 30 secondes. Téléphone perdu ? Contactez un administrateur.</p>
    <p class="pa-center"><a href="/connexion">Annuler et revenir à la connexion</a></p>
  </div>
</div>
