<?php
/** Barre d'aperçu insérée en haut de la page publique. Variable: $page */
$draft = ($page['status'] ?? '') !== 'published';
?>
<div role="region" aria-label="Aperçu" style="position:sticky;top:0;z-index:9999;display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:center;justify-content:space-between;padding:.6rem 1rem;background:#1d2b3a;color:#fff;font:15px/1.4 system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;box-shadow:0 2px 6px rgba(0,0,0,.25)">
  <span><strong>Aperçu</strong> – <?= $draft ? 'cette page est un <strong>brouillon</strong> : les visiteurs ne la voient pas encore.' : 'page publiée, telle que la voient les visiteurs.' ?></span>
  <a href="/admin/pages/<?= (int) $page['id'] ?>" style="color:#1d2b3a;background:#fff;padding:.4rem .8rem;border-radius:6px;text-decoration:none;font-weight:600">← Retour à l’édition</a>
</div>
