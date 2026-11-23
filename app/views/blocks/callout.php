<?php
declare(strict_types=1);
/** @var array $b @var array $data */
use App\Blocks;

$style = Blocks::str($data, 'style') === 'warning' ? 'warning' : 'info';
$title = Blocks::str($data, 'title');
$html = Blocks::str($data, 'html'); // nettoyé par Html::clean()
if ($title === '' && $html === '') {
    return;
}
?>
<aside class="block block-callout callout-<?= $style ?>" aria-label="<?= e($title !== '' ? $title : ($style === 'warning' ? 'Important' : 'Information')) ?>">
  <span class="callout-icon" aria-hidden="true"><?= $style === 'warning' ? '!' : 'i' ?></span>
  <div class="callout-body">
    <?php if ($title !== ''): ?><p class="callout-title"><?= e($title) ?></p><?php endif; ?>
    <?php if ($html !== ''): ?><div class="prose"><?= $html ?></div><?php endif; ?>
  </div>
</aside>
