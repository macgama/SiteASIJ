<?php
declare(strict_types=1);
/**
 * Page publique.
 * @var array $page @var string $blocksHtml @var array $children @var array $breadcrumbs @var bool $isDraft
 * @var array $news @var bool $isNewsList @var bool $isNewsItem
 */
use App\Auth;
use App\Controllers\PageController;
use App\Settings;

$isHome = ($page['path'] ?? '') === '/';
$news = $news ?? [];
$hasContent = trim($blocksHtml) !== '';
?>
<?php if (!empty($isDraft)): ?>
<div class="draft-banner" role="status">
  <div class="container"><strong>Brouillon</strong> – cette page n'est pas encore publiée : seuls les administrateurs la voient.
    <a href="/admin/pages/<?= (int) $page['id'] ?>">Modifier la page</a></div>
</div>
<?php elseif (Auth::isAdmin()): ?>
<div class="admin-bar">
  <div class="container"><a href="/admin/pages/<?= (int) $page['id'] ?>">Modifier cette page</a></div>
</div>
<?php endif; ?>

<?php if ($isHome):
    $rubs = [];
    $n = 0;
    foreach (PageController::menu() as $it) {
        if ($it['path'] !== '/') {
            $rubs[] = $it + ['color' => PageController::rubriqueColor($n++)];
        }
    }
    $tg = trim((string) Settings::get('site_tagline'));
?>
<section class="hero">
  <div class="container hero-inner">
    <div class="hero-text">
      <h1 class="hero-title"><?= e($page['title'] ?: Settings::get('site_name')) ?></h1>
      <?php if ($tg !== '' && $tg !== $page['title']): ?><p class="hero-lead"><?= e($tg) ?></p><?php endif; ?>
      <?php if (!empty($page['meta_description'])): ?><p class="hero-desc"><?= e($page['meta_description']) ?></p><?php endif; ?>
    </div>
    <?= App\View::partial('public/hero-art') ?>
  </div>
  <svg class="hero-hills" viewBox="0 0 1440 120" preserveAspectRatio="none" aria-hidden="true" focusable="false">
    <path d="M0 70 C 180 20 330 30 480 62 S 790 110 960 72 S 1260 18 1440 54 V120 H0Z" fill="#2a447c"/>
    <path d="M0 96 C 220 58 420 70 620 92 S 1010 120 1190 92 S 1380 74 1440 82 V120 H0Z" fill="#fff"/>
  </svg>
</section>
<?php
    $infos = $infos ?? null;
    $showInfos = $infos && ($infos['html'] !== '' || Auth::isAdmin());
    $showNews = $news || Auth::isAdmin();
?>
<?php if ($showInfos || $showNews): ?>
<div class="container home-top<?= $showInfos && $showNews ? ' has-infos' : '' ?>">
<?php if ($showInfos): ?>
<aside class="infos-panel" aria-labelledby="infos-title">
  <h2 id="infos-title" class="infos-title"><span class="infos-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22"><path d="M12 3a6 6 0 0 0-6 6v3.5L4.5 16h15L18 12.5V9a6 6 0 0 0-6-6zM10 19a2 2 0 0 0 4 0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"/></svg></span>Informations importantes</h2>
  <?php if ($infos['html'] !== ''): ?><div class="infos-body"><?= $infos['html'] ?></div>
  <?php else: ?><p class="admin-hint">Encadré vide : ajoutez-y les informations urgentes (visible seulement par les administrateurs).</p><?php endif; ?>
  <?php if (Auth::isAdmin()): ?><p class="infos-edit"><a href="/admin/pages/<?= (int) $infos['id'] ?>">Modifier ces informations</a></p><?php endif; ?>
</aside>
<?php endif; ?>
<?php if ($showNews): ?>
<section class="news-home" aria-labelledby="news-title">
  <div class="section-head">
    <h2 id="news-title" class="section-title">Actualités</h2>
    <?php if ($news): ?><a class="section-link" href="<?= e(PageController::href(App\Pages::NEWS_PATH)) ?>">Toutes les actualités</a><?php endif; ?>
  </div>
  <?php if ($news): ?>
  <div class="news-grid news-grid-home">
    <?php foreach ($news as $k => $n): ?><?= App\View::partial('public/news-card', ['n' => $n, 'featured' => $k === 0]) ?><?php endforeach; ?>
  </div>
  <?php else: ?>
  <p class="admin-hint">Aucune actualité publiée. <a href="/admin/pages/nouvelle?actualite=1">Publier une actualité</a> : elle apparaîtra ici (visible seulement par les administrateurs).</p>
  <?php endif; ?>
</section>
<?php endif; ?>
</div>
<?php endif; ?>
<?php if ($rubs): ?>
<section class="container rubriques" aria-labelledby="rubriques-title">
  <h2 id="rubriques-title" class="section-title">Trouver une information</h2>
  <ul class="rub-grid" role="list">
    <?php foreach ($rubs as $r): ?>
    <li class="rub-card" style="--rub: var(--b-<?= e($r['color']) ?>)">
      <a class="rub-main" href="<?= e(PageController::href($r['path'])) ?>">
        <span class="rub-balloon" aria-hidden="true"></span>
        <span class="rub-name"><?= e($r['title']) ?></span>
      </a>
      <?php if (!empty($r['children'])): ?>
      <ul class="rub-links" role="list">
        <?php foreach (array_slice($r['children'], 0, 4) as $c): ?>
        <li><a href="<?= e(PageController::href($c['path'])) ?>"><?= e($c['title']) ?></a></li>
        <?php endforeach; ?>
        <?php if (count($r['children']) > 4): ?>
        <li class="rub-more"><a href="<?= e(PageController::href($r['path'])) ?>">Toutes les pages (<?= count($r['children']) ?>)</a></li>
        <?php endif; ?>
      </ul>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<?php else:
    $coverUrl = !empty($page['cover_media_id']) ? App\Media::url((int) $page['cover_media_id'], 1600) : '';
?>
<div class="page-head<?= $coverUrl !== '' ? ' has-photo' : '' ?>">
  <?php if ($coverUrl !== ''): ?><img class="page-head-photo" src="<?= e($coverUrl) ?>" alt=""><?php endif; ?>
  <div class="container page-head-inner">
    <div class="page-head-text">
      <?php if ($breadcrumbs): ?>
      <nav class="breadcrumbs" aria-label="Fil d'Ariane">
        <ol>
          <?php foreach ($breadcrumbs as $c): ?>
          <li><a href="<?= e(PageController::href($c['path'])) ?>"><?= e($c['title']) ?></a></li>
          <?php endforeach; ?>
          <li><span aria-current="page"><?= e($page['menu_title'] ?: $page['title']) ?></span></li>
        </ol>
      </nav>
      <?php endif; ?>
      <h1 class="page-title"><?= e($page['title']) ?></h1>
      <?php if (!empty($isNewsItem) && ($d = PageController::newsDate($page)) !== ''): ?>
      <p class="page-date"><time datetime="<?= e((string) ($page['news_date'] ?? '')) ?>"><?= e($d) ?></time></p>
      <?php elseif (!empty($page['meta_description']) && (!$hasContent || !empty($isNewsList))): ?><p class="page-lead"><?= e($page['meta_description']) ?></p><?php endif; ?>
    </div>
    <?php if ($coverUrl === ''): ?>
    <div class="page-head-art"><?= App\View::partial('public/rub-icon', ['icon' => PageController::rubriqueIcon((string) $page['path'])]) ?></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php
// Navigation latérale de la rubrique (pages de 2e niveau et plus) : on se repère sans remonter au menu.
$side = null;
$topCrumb = null;
foreach ($breadcrumbs as $c) {
    if ($c['path'] !== '/') {
        $topCrumb = $c['path'];
        break;
    }
}
if (!$isHome && $topCrumb !== null && empty($isNewsItem)) {
    foreach (PageController::menu() as $top) {
        if ($top['path'] === $topCrumb && !empty($top['children'])) {
            $side = $top;
            break;
        }
    }
}
$cur = (string) $page['path'];
?>
<div class="container page-body<?= $isHome ? ' home-body' : '' ?><?= $side ? ' has-side' : '' ?>">
  <?php if ($side): ?>
  <aside class="side-nav" aria-label="Pages de la rubrique <?= e($side['title']) ?>">
    <p class="side-title"><a href="<?= e(PageController::href($side['path'])) ?>"><?= e($side['title']) ?></a></p>
    <ul role="list">
      <?php foreach ($side['children'] as $c):
          $on = $cur === $c['path'] || str_starts_with($cur, $c['path'] . '/'); ?>
      <li class="<?= $on ? 'is-on' : '' ?>">
        <a href="<?= e(PageController::href($c['path'])) ?>"<?= $cur === $c['path'] ? ' aria-current="page"' : '' ?>><?= e($c['title']) ?></a>
        <?php if ($on && !empty($c['children']) && count($c['children']) <= 12): ?>
        <ul role="list">
          <?php foreach ($c['children'] as $g): ?>
          <li><a href="<?= e(PageController::href($g['path'])) ?>"<?= $cur === $g['path'] ? ' aria-current="page"' : '' ?>><?= e($g['title']) ?></a></li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </aside>
  <div class="page-main">
  <?php endif; ?>
  <?php if ($hasContent && $isHome): ?>
  <section class="home-about" aria-labelledby="about-title">
    <h2 id="about-title" class="section-title">L’ASIJ en bref</h2>
    <div class="blocks"><?= $blocksHtml ?></div>
  </section>
  <?php elseif ($hasContent): ?>
  <div class="blocks"><?= $blocksHtml ?></div>
  <?php elseif (!$children && empty($isNewsList)): ?>
  <p class="empty-note">Cette page sera bientôt complétée.</p>
  <?php endif; ?>

  <?php if (!empty($isNewsList)): ?>
  <?php if ($children): ?>
  <div class="news-grid"><?php foreach ($children as $n): ?><?= App\View::partial('public/news-card', ['n' => $n]) ?><?php endforeach; ?></div>
  <?php else: ?>
  <p class="empty-note">Aucune actualité pour le moment.</p>
  <?php endif; ?>
  <?php elseif ($children): ?>
  <section class="subpages<?= $hasContent ? ' subpages-compact' : '' ?>" aria-labelledby="subpages-title">
    <h2 id="subpages-title" class="subpages-title"><?= $hasContent ? 'Dans cette rubrique' : 'Pages de cette rubrique' ?></h2>
    <ul class="subpage-list<?= count($children) > 8 ? ' is-long' : '' ?>" role="list">
      <?php foreach ($children as $ch): ?>
      <li>
        <a class="subpage-card" href="<?= e(PageController::href((string) $ch['path'])) ?>">
          <span class="subpage-name"><?= e($ch['menu_title'] ?: $ch['title']) ?></span>
          <?php if (!$hasContent && count($children) <= 8 && !empty($ch['meta_description'])): ?><span class="subpage-desc"><?= e($ch['meta_description']) ?></span><?php endif; ?>
          <span class="subpage-arrow" aria-hidden="true"></span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>
  <?php if ($side): ?>
  </div>
  <?php endif; ?>
  <?php if (!empty($isNewsItem)): ?>
  <p class="news-back"><a href="<?= e(PageController::href(App\Pages::NEWS_PATH)) ?>">Toutes les actualités</a></p>
  <?php endif; ?>
</div>
