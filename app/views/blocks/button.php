<?php
declare(strict_types=1);
/** @var array $b @var array $data */
use App\Blocks;

$label = Blocks::str($data, 'label');
$href = Blocks::str($data, 'url');
if ($label === '' || !App\Html::safeUrl($href)) {
    return;
}
$external = (bool) preg_match('#^https?://#i', $href)
    && strcasecmp((string) parse_url($href, PHP_URL_HOST), (string) parse_url((string) App\Config::get('site_url', ''), PHP_URL_HOST)) !== 0;
?>
<p class="block block-button">
  <a class="btn btn-primary" href="<?= e($href) ?>"<?= $external ? ' rel="noopener"' : '' ?>><?= e($label) ?><?php if ($external): ?> <span class="visually-hidden">(site externe)</span><span aria-hidden="true">↗</span><?php endif; ?></a>
</p>
