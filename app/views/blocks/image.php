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
$dim = Media::dimensions($m);
// Ne jamais agrandir une petite image (QR code, logo): elle deviendrait floue.
$small = $dim !== null && ($dim['w'] <= 420 || Blocks::isSquareGraphic($m, $dim));
if ($small && $dim['w'] > 260 && Blocks::isSquareGraphic($m, $dim)) {
    $dim = ['w' => 260, 'h' => (int) round(260 * $dim['h'] / $dim['w'])]; // QR code: taille lisible au téléphone
}
$style = $dim !== null && $dim['w'] < 1600 ? ' style="--natural-w: ' . $dim['w'] . 'px"' : '';
?>
<figure class="block block-image<?= $half ? ' image-half' : '' ?><?= $small ? ' image-small' : '' ?>"<?= $style ?>>
  <img src="<?= e(Media::url($id, $half ? 960 : 1600)) ?>"
       srcset="<?= e(Media::url($id, 480)) ?> 480w, <?= e(Media::url($id, 960)) ?> 960w, <?= e(Media::url($id, 1600)) ?> 1600w"
       sizes="<?= $half ? '(min-width: 48em) 36rem, 100vw' : '(min-width: 75em) 72rem, 100vw' ?>"
       alt="<?= e($alt) ?>"<?= $dim ? ' width="' . $dim['w'] . '" height="' . $dim['h'] . '"' : '' ?> loading="lazy" decoding="async">
  <?php if ($caption !== ''): ?><figcaption><?= e($caption) ?></figcaption><?php endif; ?>
</figure>
