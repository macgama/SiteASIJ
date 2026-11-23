<?php
declare(strict_types=1);
/** @var array $b @var array $data */
use App\Blocks;

$people = array_filter(Blocks::items($data), fn($p) => Blocks::str($p, 'name') !== '');
if (!$people) {
    return;
}
?>
<ul class="block block-people" role="list">
  <?php foreach ($people as $p):
      $name = Blocks::str($p, 'name');
      $words = preg_split('/\s+/u', $name) ?: [];
      $initials = '';
      foreach (array_slice($words, 0, 2) as $w) {
          $initials .= mb_strtoupper(mb_substr($w, 0, 1));
      }
  ?>
  <li class="person">
    <span class="person-initials" aria-hidden="true"><?= e($initials) ?></span>
    <span class="person-text">
      <strong class="person-name"><?= e($name) ?></strong>
      <?php if (($role = Blocks::str($p, 'role')) !== ''): ?><span class="person-role"><?= e($role) ?></span><?php endif; ?>
      <?php if (($commune = Blocks::str($p, 'commune')) !== ''): ?><span class="person-commune"><?= e($commune) ?></span><?php endif; ?>
    </span>
  </li>
  <?php endforeach; ?>
</ul>
