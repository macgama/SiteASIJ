<?php
declare(strict_types=1);
/** @var array $b @var array $data */
// Textes riches nettoyés par Html::clean() à l'enregistrement.
$left = App\Blocks::str($data, 'left');
$right = App\Blocks::str($data, 'right');
if ($left === '' && $right === '') {
    return;
}
?>
<div class="block block-columns">
  <div class="prose"><?= $left ?></div>
  <div class="prose"><?= $right ?></div>
</div>
