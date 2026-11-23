<?php
declare(strict_types=1);
/** Arborescence récursive. @var array $nodes @var array $groupNames @var array $counts */
?>
<ul class="pa-tree" role="list">
  <?php foreach ($nodes as $n): $id = (int) $n['id']; ?>
  <li>
    <div class="pa-tree-row">
      <svg class="pa-folder-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="22" height="22"><path d="M3 6.5A1.5 1.5 0 0 1 4.5 5h4.6l2 2.2h8.4A1.5 1.5 0 0 1 21 8.7v9.8a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18.5z" fill="currentColor"/></svg>
      <a href="/admin/espace/dossier/<?= $id ?>" class="pa-tree-name"><?= e($n['name']) ?></a>
      <span class="muted small"><?= (int) ($counts[$id] ?? 0) ?> doc.</span>
      <?php if (!empty($groupNames[$id])): ?>
        <span class="pa-tags"><?php foreach ($groupNames[$id] as $gn): ?><span class="pa-tag"><?= e($gn) ?></span><?php endforeach; ?></span>
      <?php endif; ?>
    </div>
    <?php if ($n['children']): ?>
      <?= App\View::partial('admin/private/_tree', ['nodes' => $n['children'], 'groupNames' => $groupNames, 'counts' => $counts]) ?>
    <?php endif; ?>
  </li>
  <?php endforeach; ?>
</ul>
