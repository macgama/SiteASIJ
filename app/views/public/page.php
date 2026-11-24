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

<?php if ($isHome): ?>
<div class="hero">
  <div class="container">
    <h1 class="hero-title"><?= e($page['title'] ?: Settings::get('site_name')) ?></h1>
    <?php if (($tg = Settings::get('site_tagline')) !== '' && $tg !== $page['title']): ?><p class="hero-lead"><?= e($tg) ?></p><?php endif; ?>
  </div>
</div>
<?php $quick = array_values(array_filter(PageController::pageTree(true, 1), fn($it) => $it['path'] !== '/')); ?>
<?php if ($quick): ?>
<nav class="container quick-links" aria-label="Accès rapide">
  <ul class="quick-list" role="list">
    <?php foreach (array_slice($quick, 0, 8) as $it): ?>
    <li><a class="quick-card" href="<?= e(PageController::href($it['path'])) ?>"><span class="quick-dot" aria-hidden="true"></span><?= e($it['title']) ?></a></li>
    <?php endforeach; ?>
  </ul>
</nav>
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

<div class="container page-body">
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
