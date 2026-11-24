<?php
declare(strict_types=1);
/** Carte d'une actualité. @var array $n @var bool $featured */
use App\Controllers\PageController;
use App\Media;

$img = !empty($n['cover_media_id']) ? Media::url((int) $n['cover_media_id'], !empty($featured) ? 1200 : 640) : '';
$date = PageController::newsDate($n);
?>
<article class="news-card<?= !empty($featured) ? ' is-featured' : '' ?><?= $img === '' ? ' no-image' : '' ?>">
  <div class="news-media">
    <?php if ($img !== ''): ?>
    <img src="<?= e($img) ?>" alt="" loading="lazy">
    <?php else: ?>
    <span class="news-placeholder" aria-hidden="true"><?= App\View::partial('public/rub-icon', ['icon' => 'news']) ?></span>
    <?php endif; ?>
  </div>
  <div class="news-text">
    <?php if ($date !== ''): ?><p class="news-date"><time datetime="<?= e((string) ($n['news_date'] ?? '')) ?>"><?= e($date) ?></time></p><?php endif; ?>
    <h3 class="news-title"><a href="<?= e(PageController::href((string) $n['path'])) ?>"><?= e($n['title']) ?></a></h3>
    <?php if (!empty($n['meta_description'])): ?><p class="news-summary"><?= e($n['meta_description']) ?></p><?php endif; ?>
  </div>
</article>
