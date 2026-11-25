<?php
/** Pagination. Variables: $page, $pages, $base (URL sans page), $params (tableau de paramètres GET à conserver). */
if (($pages ?? 1) <= 1) {
    return;
}
$params = array_filter($params ?? [], fn($v) => $v !== '' && $v !== null);
$link = function (int $n) use ($base, $params): string {
    $q = http_build_query($params + ['page' => $n]);
    return $base . '?' . $q;
};
?>
<nav class="pagination" aria-label="Pagination">
  <?php if ($page > 1): ?><a class="btn btn-ghost" href="<?= e($link($page - 1)) ?>" rel="prev">← Précédent</a><?php endif; ?>
  <span class="pagination-info">Page <?= (int) $page ?> sur <?= (int) $pages ?></span>
  <?php if ($page < $pages): ?><a class="btn btn-ghost" href="<?= e($link($page + 1)) ?>" rel="next">Suivant →</a><?php endif; ?>
</nav>
