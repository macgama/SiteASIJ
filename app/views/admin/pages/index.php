<?php
/** Arbre des pages. Variables: $tree, $count */
$renderTree = function (array $nodes) use (&$renderTree): string {
    ob_start();
    foreach ($nodes as $p):
        $id = (int) $p['id']; ?>
<li class="pt-item" data-id="<?= $id ?>" data-title="<?= e($p['title']) ?>">
  <div class="pt-row">
    <span class="pt-handle js-only" draggable="true" title="Glisser pour déplacer" aria-hidden="true">
      <svg viewBox="0 0 20 20" width="18" height="18"><path d="M7 4.5h.01M13 4.5h.01M7 10h.01M13 10h.01M7 15.5h.01M13 15.5h.01" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
    </span>
    <div class="pt-main">
      <a class="pt-title" href="/admin/pages/<?= $id ?>"><?= e($p['title']) ?></a>
      <span class="pt-badges">
        <?php if ($p['path'] === '/'): ?><span class="badge badge-home">Accueil</span><?php endif; ?>
        <?php if ($p['status'] === 'published'): ?><span class="badge badge-ok">Publiée</span><?php else: ?><span class="badge badge-draft">Brouillon</span><?php endif; ?>
        <?php if ((int) $p['show_in_menu'] !== 1): ?><span class="badge">Hors menu</span><?php endif; ?>
      </span>
      <span class="pt-path" data-path><?= e($p['path']) ?></span>
    </div>
    <div class="pt-actions">
      <span class="pt-move js-only" role="group" aria-label="Déplacer « <?= e($p['title']) ?> »">
        <button type="button" class="icon-btn" data-move="up" title="Monter" aria-label="Monter « <?= e($p['title']) ?> »"><svg aria-hidden="true" viewBox="0 0 20 20" width="18" height="18"><path d="M10 15V5M5.5 9.5L10 5l4.5 4.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
        <button type="button" class="icon-btn" data-move="down" title="Descendre" aria-label="Descendre « <?= e($p['title']) ?> »"><svg aria-hidden="true" viewBox="0 0 20 20" width="18" height="18"><path d="M10 5v10M5.5 10.5L10 15l4.5-4.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
        <button type="button" class="icon-btn" data-move="out" title="Remonter d’un niveau" aria-label="Remonter « <?= e($p['title']) ?> » d’un niveau"><svg aria-hidden="true" viewBox="0 0 20 20" width="18" height="18"><path d="M15 10H5M9.5 5.5L5 10l4.5 4.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
        <button type="button" class="icon-btn" data-move="in" title="Mettre sous la page précédente" aria-label="Placer « <?= e($p['title']) ?> » sous la page précédente"><svg aria-hidden="true" viewBox="0 0 20 20" width="18" height="18"><path d="M5 10h10M10.5 5.5L15 10l-4.5 4.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
      </span>
      <a class="btn btn-small" href="/admin/pages/<?= $id ?>">Modifier</a>
      <a class="btn btn-small btn-ghost" href="/admin/pages/nouvelle?parent=<?= $id ?>" title="Ajouter une sous-page à « <?= e($p['title']) ?> »">+ Sous-page</a>
    </div>
  </div>
  <ul class="pt-children" role="list"><?= $renderTree($p['children']) ?></ul>
</li>
<?php endforeach;
    return (string) ob_get_clean();
};
?>
<div class="page-head page-head-actions">
  <div>
    <h1>Pages du site</h1>
    <p class="lead">L’ordre ci-dessous est celui du menu du site. Les pages décalées vers la droite sont des sous-pages.</p>
  </div>
  <a class="btn btn-primary" href="/admin/pages/nouvelle">+ Nouvelle page</a>
</div>

<?php if (!$tree): ?>
  <div class="empty card">
    <p>Le site ne contient encore aucune page.</p>
    <p><a class="btn btn-primary" href="/admin/pages/nouvelle">Créer la première page</a></p>
  </div>
<?php else: ?>
  <p class="help js-only">Pour changer l’ordre, glissez une page avec la poignée <span aria-hidden="true">⋮⋮</span> ou utilisez les flèches. L’ordre est enregistré automatiquement.</p>
  <div class="card card-flush">
    <ul class="page-tree" id="page-tree" role="list" data-reorder-url="/admin/pages/ordre"><?= $renderTree($tree) ?></ul>
  </div>
  <p class="muted small"><?= (int) $count ?> page<?= $count > 1 ? 's' : '' ?> au total.</p>
<?php endif; ?>
<div class="sr-only" aria-live="polite" id="tree-status"></div>
