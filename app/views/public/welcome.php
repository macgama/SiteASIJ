<?php
declare(strict_types=1);
/** Accueil par défaut, affiché tant qu'aucune page n'a l'adresse « / ». @var array $tree */
use App\Auth;
use App\Controllers\PageController;
use App\Settings;

$siteName = Settings::get('site_name') ?: 'ASIJ';
$tagline = trim((string) Settings::get('site_tagline'));
$title = $siteName;
?>
<div class="page-head">
  <div class="container">
    <h1 class="page-title"><?= e($siteName) ?></h1>
    <?php if ($tagline !== ''): ?><p class="page-lead"><?= e($tagline) ?></p><?php endif; ?>
  </div>
</div>
<div class="container page-body">
  <div class="prose">
    <?php if (Auth::isAdmin()): ?>
      <div class="callout callout-info">
        <p><strong>Pas encore de page d'accueil.</strong> Si l'import du site actuel est en cours, elle apparaîtra automatiquement à la fin.
        Sinon, créez une page en laissant l'adresse vide dans <a href="/admin/pages/nouvelle">Admin › Pages › Nouvelle page</a>.</p>
      </div>
    <?php endif; ?>
    <p>Bienvenue sur le site de l'Association scolaire intercommunale du Jorat. Le site est en cours de mise à jour.</p>
    <?php if ($tree): ?>
      <h2>Pages disponibles</h2>
      <ul>
        <?php foreach ($tree as $it): ?>
          <li><a href="<?= e(PageController::href($it['path'])) ?>"><?= e($it['title']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <p><a href="/espace-membres">Espace membres</a> (accès réservé)</p>
  </div>
</div>
