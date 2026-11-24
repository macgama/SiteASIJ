<?php
declare(strict_types=1);
/**
 * Page publique.
 * @var array $page @var string $blocksHtml @var array $children @var array $breadcrumbs @var bool $isDraft
 */
use App\Auth;
use App\Controllers\PageController;
use App\Settings;

$isHome = ($page['path'] ?? '') === '/';
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
<?php else: ?>
<div class="page-head">
  <div class="container">
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
    <?php if (!empty($page['meta_description']) && !$hasContent): ?><p class="page-lead"><?= e($page['meta_description']) ?></p><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="container page-body<?= $isHome ? ' home-body' : '' ?>">
  <?php if ($hasContent): ?>
  <div class="blocks"><?= $blocksHtml ?></div>
  <?php elseif (!$children): ?>
  <p class="empty-note">Cette page sera bientôt complétée.</p>
  <?php endif; ?>

  <?php if ($children): ?>
  <section class="subpages<?= $hasContent ? ' subpages-compact' : '' ?>" aria-labelledby="subpages-title">
    <h2 id="subpages-title" class="subpages-title"><?= $hasContent ? 'Dans cette rubrique' : 'Pages de cette rubrique' ?></h2>
    <ul class="subpage-list<?= count($children) > 8 ? ' is-long' : '' ?>" role="list">
      <?php foreach ($children as $ch): ?>
      <li>
        <a class="subpage-card" href="<?= e(PageController::href((string) $ch['path'])) ?>">
          <span class="subpage-name"><?= e($ch['menu_title'] ?: $ch['title']) ?></span>
          <?php if (!$hasContent && count($children) <= 8 && !empty($ch['meta_description'])): ?><span class="subpage-desc"><?= e($ch['meta_description']) ?></span><?php endif; ?>
          <span class="subpage-arrow" aria-hidden="true">→</span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>
</div>
