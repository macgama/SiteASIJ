<?php
declare(strict_types=1);
/** @var array $b @var array $data */
use App\Blocks;

$text = Blocks::str($data, 'text');
if ($text === '') {
    return;
}
$level = (int) ($data['level'] ?? 2) === 3 ? 3 : 2;
$anchor = App\Media::slug($text);
?>
<h<?= $level ?> class="block block-heading"<?= $anchor !== '' ? ' id="' . e($anchor) . '"' : '' ?>><?= e($text) ?></h<?= $level ?>>
