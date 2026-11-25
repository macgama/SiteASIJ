<?php
declare(strict_types=1);
/** @var array $b @var array $data */
// Le HTML a été nettoyé par Html::clean() à l'enregistrement.
$html = App\Html::display(App\Blocks::str($data, 'html'));
if ($html === '') {
    return;
}
?>
<div class="block block-text prose"><?= $html ?></div>
