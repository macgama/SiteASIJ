<?php
declare(strict_types=1);
/** Plan du site (HTML). @var array $tree */
use App\Controllers\PageController;

$title = 'Plan du site';
$currentPath = PageController::SITEMAP_PATH;
$list = function (array $items) use (&$list): string {
    $h = '<ul>';
    foreach ($items as $it) {
        $h .= '<li><a href="' . e(PageController::href($it['path'])) . '">' . e($it['title']) . '</a>';
        if (!empty($it['children'])) {
            $h .= $list($it['children']);
        }
        $h .= '</li>';
    }
    return $h . '</ul>';
};
?>
<div class="page-head">
  <div class="container">
    <nav class="breadcrumbs" aria-label="Fil d'Ariane"><ol><li><a href="/">Accueil</a></li><li><span aria-current="page">Plan du site</span></li></ol></nav>
    <h1 class="page-title">Plan du site</h1>
  </div>
</div>
<div class="container page-body">
  <div class="sitemap-tree prose">
    <?= $tree ? $list($tree) : '<p>Aucune page publiée pour le moment.</p>' ?>
    <h2>Autres pages</h2>
    <ul>
      <li><a href="/espace-membres">Espace membres</a> (accès réservé)</li>
      <li><a href="<?= e(PageController::LEGAL_PATH) ?>">Mentions légales</a></li>
    </ul>
  </div>
</div>
