<?php
/** Versions d'une page. Variables: $page, $revisions */
$labels = ['heading' => 'titre', 'text' => 'texte', 'image' => 'image', 'gallery' => 'galerie', 'files' => 'documents', 'video' => 'vidéo', 'callout' => 'encadré', 'button' => 'bouton', 'contact' => 'formulaire', 'columns' => 'colonnes', 'people' => 'personnes', 'separator' => 'ligne'];
$summary = function (string $json) use ($labels): array {
    $blocks = json_decode($json, true) ?: [];
    $types = [];
    $text = '';
    foreach ($blocks as $b) {
        $t = $b['type'] ?? '';
        $types[$labels[$t] ?? $t] = ($types[$labels[$t] ?? $t] ?? 0) + 1;
        if ($text === '' && in_array($t, ['text', 'heading', 'callout'], true)) {
            $text = trim(html_entity_decode(strip_tags((string) ($b['data']['html'] ?? $b['data']['text'] ?? '')), ENT_QUOTES, 'UTF-8'));
        }
    }
    $parts = [];
    foreach ($types as $k => $n) {
        $parts[] = $n . ' ' . $k;
    }
    return [count($blocks), implode(', ', $parts), mb_strimwidth($text, 0, 160, '…')];
};
?>
<p class="breadcrumb"><a href="/admin/pages">Pages</a> › <a href="/admin/pages/<?= (int) $page['id'] ?>"><?= e($page['title']) ?></a> › Versions</p>
<div class="page-head">
  <h1>Versions précédentes</h1>
  <p class="lead">Chaque enregistrement de « <?= e($page['title']) ?> » crée une version. Les 30 dernières sont conservées. Restaurer une version remplace le titre et le contenu actuels (la version actuelle reste dans la liste).</p>
</div>
<?php if (!$revisions): ?>
  <div class="empty card"><p>Aucune version enregistrée.</p></div>
<?php else: ?>
<ol class="revisions" role="list">
  <?php foreach ($revisions as $i => $r): [$n, $types, $excerpt] = $summary((string) $r['blocks']); ?>
  <li class="card revision">
    <div>
      <p class="revision-date"><strong><?= e(date_fr($r['created_at'], true)) ?></strong><?= $r['author'] ? ' – ' . e($r['author']) : '' ?>
        <?php if ($i === 0): ?><span class="badge badge-ok">Version actuelle</span><?php endif; ?></p>
      <p>Titre&nbsp;: <?= e($r['title']) ?></p>
      <p class="muted small"><?= $n ?> bloc<?= $n > 1 ? 's' : '' ?><?= $types ? ' (' . e($types) . ')' : '' ?></p>
      <?php if ($excerpt !== ''): ?><p class="excerpt">« <?= e($excerpt) ?> »</p><?php endif; ?>
    </div>
    <?php if ($i > 0): ?>
    <form method="post" action="/admin/pages/<?= (int) $page['id'] ?>/versions/<?= (int) $r['id'] ?>" data-confirm="Restaurer la version du <?= e(date_fr($r['created_at'], true)) ?> ? Le contenu actuel de la page sera remplacé.">
      <?= csrf_field() ?>
      <button type="submit" class="btn">Restaurer cette version</button>
    </form>
    <?php endif; ?>
  </li>
  <?php endforeach; ?>
</ol>
<?php endif; ?>
<p><a href="/admin/pages/<?= (int) $page['id'] ?>">← Retour à la page</a></p>
