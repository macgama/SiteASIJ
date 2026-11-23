<?php
declare(strict_types=1);
/** Barre de navigation de l'espace membres. @var string $active */
$active = $active ?? '';
$u = App\Auth::user();
?>
<link rel="stylesheet" href="<?= e(asset('css/private.css')) ?>">
<script src="<?= e(asset('js/private.js')) ?>" defer></script>
<div class="pa-bar">
  <div class="container pa-bar-inner">
    <p class="pa-hello">Connecté·e : <strong><?= e($u['name'] ?? '') ?></strong></p>
    <nav aria-label="Espace membres" class="pa-bar-nav">
      <a href="/espace-membres"<?= $active === 'home' ? ' aria-current="page"' : '' ?>>Mes documents</a>
      <a href="/espace-membres/recherche"<?= $active === 'search' ? ' aria-current="page"' : '' ?>>Rechercher</a>
      <a href="/espace-membres/profil"<?= $active === 'profile' ? ' aria-current="page"' : '' ?>>Mon compte</a>
      <form method="post" action="/deconnexion" class="pa-inline">
        <?= csrf_field() ?>
        <button type="submit" class="pa-linkbtn">Se déconnecter</button>
      </form>
    </nav>
  </div>
</div>
