<?php
/** Tableau de bord. Variables: $stats, $recentPages, $recentMessages, $user */
$first = explode(' ', (string) ($user['name'] ?? ''))[0];
?>
<div class="page-head">
  <h1>Bonjour<?= $first !== '' ? ' ' . e($first) : '' ?></h1>
  <p class="lead">Que souhaitez-vous faire aujourd’hui&nbsp;?</p>
</div>

<div class="shortcuts">
  <a class="shortcut" href="/admin/pages">
    <svg aria-hidden="true" viewBox="0 0 24 24" width="28" height="28"><path d="M6 3h9l4 4v14H6zM15 3v4h4M9 12h7M9 16h5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" stroke-linecap="round"/></svg>
    <strong>Modifier une page</strong><span>Textes, photos, documents</span>
  </a>
  <a class="shortcut" href="/admin/pages/nouvelle">
    <svg aria-hidden="true" viewBox="0 0 24 24" width="28" height="28"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
    <strong>Créer une page</strong><span>Nouvelle page dans le menu</span>
  </a>
  <a class="shortcut" href="/admin/medias">
    <svg aria-hidden="true" viewBox="0 0 24 24" width="28" height="28"><path d="M12 16V4M7 9l5-5 5 5M4 16v4h16v-4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <strong>Ajouter des fichiers</strong><span>Photos et documents publics</span>
  </a>
  <a class="shortcut" href="/admin/espace">
    <svg aria-hidden="true" viewBox="0 0 24 24" width="28" height="28"><path d="M6 11h12v10H6zM8.5 11V8a3.5 3.5 0 0 1 7 0v3" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
    <strong>Documents des membres</strong><span>Espace sécurisé</span>
  </a>
  <a class="shortcut" href="/admin/reglages">
    <svg aria-hidden="true" viewBox="0 0 24 24" width="28" height="28"><path d="M4 7h10M18 7h2M4 17h4M12 17h8M14 4.5v5M8 14.5v5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
    <strong>Bandeau d’information</strong><span>Coordonnées, message urgent</span>
  </a>
</div>

<?php $pl = fn(int $n, string $one, string $many) => $n > 1 ? $many : $one; ?>
<ul class="stats" role="list">
  <li><a href="/admin/pages"><strong><?= (int) $stats['published'] ?></strong> <span><?= $pl($stats['published'], 'page publiée', 'pages publiées') ?></span></a></li>
  <li><a href="/admin/pages"><strong><?= (int) $stats['drafts'] ?></strong> <span><?= $pl($stats['drafts'], 'brouillon', 'brouillons') ?></span></a></li>
  <li><a href="/admin/medias"><strong><?= (int) $stats['media'] ?></strong> <span><?= $pl($stats['media'], 'fichier public', 'fichiers publics') ?></span></a></li>
  <li><a href="/admin/espace"><strong><?= (int) $stats['private_files'] ?></strong> <span><?= $pl($stats['private_files'], 'document confidentiel', 'documents confidentiels') ?></span></a></li>
  <li><a href="/admin/utilisateurs"><strong><?= (int) $stats['users'] ?></strong> <span><?= $pl($stats['users'], 'compte actif', 'comptes actifs') ?></span></a></li>
  <li><a href="/admin/messages"><strong><?= (int) $stats['messages30'] ?></strong> <span><?= $pl($stats['messages30'], 'message', 'messages') ?> (30 jours)</span></a></li>
</ul>

<div class="grid-2">
  <section class="card">
    <h2>Dernières pages modifiées</h2>
    <?php if (!$recentPages): ?>
      <p class="muted">Aucune page pour l’instant. <a href="/admin/pages/nouvelle">Créer la première page</a>.</p>
    <?php else: ?>
    <ul class="list" role="list">
      <?php foreach ($recentPages as $p): ?>
      <li>
        <a href="/admin/pages/<?= (int) $p['id'] ?>"><?= e($p['title']) ?></a>
        <?php if ($p['status'] !== 'published'): ?><span class="badge badge-draft">Brouillon</span><?php endif; ?>
        <span class="muted small">le <?= e(date_fr($p['updated_at'], true)) ?><?= $p['author'] ? ' par ' . e($p['author']) : '' ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
  <section class="card">
    <h2>Derniers messages reçus</h2>
    <?php if (!$recentMessages): ?>
      <p class="muted">Aucun message reçu par le formulaire de contact.</p>
    <?php else: ?>
    <ul class="list" role="list">
      <?php foreach ($recentMessages as $m): ?>
      <li>
        <strong><?= e($m['name']) ?></strong> <span class="muted small"><?= e(date_fr($m['created_at'], true)) ?></span>
        <p class="excerpt"><?= e(mb_strimwidth((string) $m['message'], 0, 140, '…')) ?></p>
      </li>
      <?php endforeach; ?>
    </ul>
    <p><a href="/admin/messages">Voir tous les messages →</a></p>
    <?php endif; ?>
  </section>
</div>
