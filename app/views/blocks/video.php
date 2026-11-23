<?php
declare(strict_types=1);
/** @var array $b @var array $data */
use App\Blocks;
use App\Media;

$title = Blocks::str($data, 'title');
$caption = Blocks::str($data, 'caption');
$mediaId = (int) ($data['media_id'] ?? 0);
$embed = Blocks::videoEmbed(Blocks::str($data, 'url'));
$m = null;
if ($embed === null && $mediaId > 0) {
    $m = Media::find($mediaId);
    if ($m && !str_starts_with((string) $m['mime'], 'video/')) {
        $m = null;
    }
}
if ($embed === null && $m === null) {
    return;
}
if ($title === '') {
    $title = $m ? (trim((string) ($m['title'] ?? '')) ?: 'Vidéo') : 'Vidéo';
}
?>
<figure class="block block-video">
  <div class="video-frame">
    <?php if ($embed !== null): ?>
    <iframe src="<?= e($embed) ?>" title="<?= e($title) ?>" loading="lazy"
            allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen
            referrerpolicy="strict-origin-when-cross-origin"></iframe>
    <?php else: ?>
    <video controls preload="metadata" title="<?= e($title) ?>">
      <source src="<?= e(Media::url((int) $m['id'])) ?>" type="<?= e((string) $m['mime']) ?>">
      <a href="<?= e(Media::url((int) $m['id'])) ?>">Télécharger la vidéo</a>
    </video>
    <?php endif; ?>
  </div>
  <?php if ($caption !== ''): ?><figcaption><?= e($caption) ?></figcaption><?php endif; ?>
</figure>
