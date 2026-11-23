<?php
declare(strict_types=1);
/** @var array $b @var array $data */
use App\Blocks;
use App\Media;

$m = Media::find((int) ($data['media_id'] ?? 0));
if (!$m || !Media::isImage($m)) {
    return;
}
$alt = Blocks::str($data, 'alt', (string) ($m['alt'] ?? ''));
$caption = Blocks::str($data, 'caption');
$half = Blocks::str($data, 'width') === 'half';
$id = (int) $m['id'];
?>
<figure class="block block-image<?= $half ? ' image-half' : '' ?>">
  <img src="<?= e(Media::url($id, $half ? 960 : 1600)) ?>"
       srcset="<?= e(Media::url($id, 480)) ?> 480w, <?= e(Media::url($id, 960)) ?> 960w, <?= e(Media::url($id, 1600)) ?> 1600w"
       sizes="<?= $half ? '(min-width: 48em) 36rem, 100vw' : '(min-width: 75em) 72rem, 100vw' ?>"
       alt="<?= e($alt) ?>" loading="lazy" decoding="async">
  <?php if ($caption !== ''): ?><figcaption><?= e($caption) ?></figcaption><?php endif; ?>
</figure>
