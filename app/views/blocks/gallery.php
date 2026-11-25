<?php
declare(strict_types=1);
/** @var array $b @var array $data */
use App\Blocks;
use App\Media;

$items = Blocks::items($data);
$media = Media::findMany(array_map(fn($i) => (int) ($i['media_id'] ?? 0), $items));
$items = array_values(array_filter($items, fn($i) => isset($media[(int) ($i['media_id'] ?? 0)]) && Media::isImage($media[(int) $i['media_id']])));
if (!$items) {
    return;
}
$title = Blocks::str($data, 'title');
$count = count($items);
?>
<section class="block block-gallery"<?= $title !== '' ? ' aria-label="' . e($title) . '"' : ' aria-label="Galerie de photos"' ?>>
  <?php if ($title !== ''): ?><h2 class="gallery-title"><?= e($title) ?></h2><?php endif; ?>
  <ul class="gallery-grid" role="list" data-gallery>
    <?php foreach ($items as $n => $it):
        $m = $media[(int) $it['media_id']];
        $id = (int) $m['id'];
        $caption = Blocks::str($it, 'caption');
        $alt = $caption !== '' ? $caption : (string) ($m['alt'] ?? '');
    ?>
    <li>
      <a href="<?= e(Media::url($id, 1600)) ?>" class="gallery-link" data-lightbox
         data-caption="<?= e($caption) ?>"
         aria-label="<?= e('Agrandir la photo ' . ($n + 1) . ' sur ' . $count . ($alt !== '' ? ' : ' . $alt : '')) ?>">
        <img src="<?= e(Media::url($id, 480)) ?>" alt="<?= e($alt) ?>" loading="lazy" decoding="async">
      </a>
      <?php if ($caption !== ''): ?><p class="gallery-caption"><?= e($caption) ?></p><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
