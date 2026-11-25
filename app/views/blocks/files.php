<?php
declare(strict_types=1);
/** @var array $b @var array $data */
use App\Blocks;
use App\Media;

$items = Blocks::items($data);
$media = Media::findMany(array_map(fn($i) => (int) ($i['media_id'] ?? 0), $items));
$rows = [];
foreach ($items as $it) {
    $m = $media[(int) ($it['media_id'] ?? 0)] ?? null;
    if (!$m) {
        continue;
    }
    $label = Blocks::str($it, 'label');
    if ($label === '') {
        $label = trim((string) ($m['title'] ?? '')) ?: (string) pathinfo((string) $m['original_name'], PATHINFO_FILENAME);
    }
    $rows[] = ['m' => $m, 'label' => $label];
}
if (!$rows) {
    return;
}
if (Blocks::str($data, 'sort') === 'name_desc') {
    usort($rows, fn($a, $z) => strnatcasecmp($z['label'], $a['label']));
}
$title = Blocks::str($data, 'title');
$collapsed = !empty($data['collapsed']) && $title !== '';
$icons = [
    'pdf' => 'PDF', 'word' => 'DOC', 'sheet' => 'XLS', 'slides' => 'PPT', 'image' => 'IMG',
    'archive' => 'ZIP', 'audio' => 'SON', 'video' => 'VID', 'file' => 'FICH',
];
?>
<?php if ($collapsed): ?>
<details class="block block-files files-collapsible"<?= !empty($data['open']) ? ' open' : '' ?>>
  <summary><span class="files-title"><?= e($title) ?></span><span class="files-count"><?= count($rows) ?> document<?= count($rows) > 1 ? 's' : '' ?></span></summary>
<?php else: ?>
<section class="block block-files"<?= $title !== '' ? ' aria-label="' . e($title) . '"' : '' ?>>
  <?php if ($title !== ''): ?><h2 class="files-title"><?= e($title) ?></h2><?php endif; ?>
<?php endif; ?>
  <ul class="file-list" role="list">
    <?php foreach ($rows as $r):
        $m = $r['m'];
        $type = Media::iconType($m);
        $ext = Media::extension($m);
        $badge = $ext !== '' && strlen($ext) <= 4 ? $ext : $icons[$type];
        $meta = trim(($ext !== '' ? $ext . ' · ' : '') . human_size((int) $m['size']));
    ?>
    <li class="file-item file-<?= e($type) ?>">
      <a href="<?= e(Media::url((int) $m['id'])) ?>" class="file-link">
        <span class="file-icon" aria-hidden="true">
          <svg viewBox="0 0 32 40" width="32" height="40" focusable="false"><path d="M3 1h18l8 8v28a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2z" fill="currentColor" opacity=".12"/><path d="M3 1h18l8 8v28a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2z M21 1v8h8" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
          <span class="file-badge"><?= e($badge) ?></span>
        </span>
        <span class="file-text">
          <span class="file-label"><?= e($r['label']) ?></span>
          <span class="file-meta"><span class="visually-hidden">Fichier </span><?= e($meta) ?></span>
        </span>
        <span class="file-action" aria-hidden="true"><?= $type === 'pdf' || $type === 'image' ? 'Ouvrir' : 'Télécharger' ?></span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
<?= $collapsed ? '</details>' : '</section>' ?>
