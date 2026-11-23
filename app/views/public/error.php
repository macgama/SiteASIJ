<?php
declare(strict_types=1);
/** @var int $code @var string $message */
$code = isset($code) ? (int) $code : 500;
$message = isset($message) && is_string($message) && $message !== '' ? $message : 'Une erreur est survenue.';
$title = match ($code) {
    404 => 'Page introuvable',
    403 => 'Accès refusé',
    419 => 'Session expirée',
    default => 'Erreur',
};
$noindex = true;
?>
<div class="page-head">
  <div class="container">
    <p class="error-code">Erreur <?= $code ?></p>
    <h1 class="page-title"><?= e($title) ?></h1>
  </div>
</div>
<div class="container page-body">
  <div class="prose">
    <p><?= e($message) ?></p>
    <?php if ($code === 404): ?>
    <p>L'adresse a peut-être changé lors du renouvellement du site. Vous pouvez :</p>
    <ul>
      <li>revenir à la <a href="/">page d'accueil</a> ;</li>
      <li>consulter le <a href="/plan-du-site">plan du site</a> pour trouver la page ;</li>
      <li>nous <a href="/contact">écrire</a> si vous ne trouvez pas un document.</li>
    </ul>
    <?php else: ?>
    <p><a class="btn btn-primary" href="/">Retour à l'accueil</a></p>
    <?php endif; ?>
  </div>
</div>
