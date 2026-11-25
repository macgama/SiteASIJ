<?php
declare(strict_types=1);
/**
 * Layout de l'administration.
 * Contrat: $title (string), $content (string, injecté par View).
 * Facultatif: $extraScripts (liste de chemins sous public/assets, ex. ['js/editor.js']),
 *             $bodyClass (string), $wide (bool: contenu pleine largeur).
 */
use App\Auth;
use App\Settings;

$user = Auth::user();
$siteName = Settings::get('site_name') ?: 'ASIJ';
$title = isset($title) && is_string($title) ? $title : 'Administration';
$reqPath = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/admin', PHP_URL_PATH), '/') ?: '/admin';
$flashes = flash() ?? [];

$nav = [
    ['/admin', 'Tableau de bord', 'M3 10.5L10 4l7 6.5V17a1 1 0 0 1-1 1h-4v-5H8v5H4a1 1 0 0 1-1-1z'],
    ['/admin/pages', 'Pages', 'M5 2.5h7l4 4V17a.5.5 0 0 1-.5.5h-10A.5.5 0 0 1 5 17zM12 2.5V7h4M8 11h6M8 14h5'],
    ['/admin/medias', 'Médias', 'M3 4.5h14v11H3zM3 13l4-4 3 3 2-2 5 5M13 7.5a1 1 0 1 0 0 .1'],
    ['/admin/espace', 'Espace sécurisé', 'M5 9h10v8.5H5zM7 9V6.5a3 3 0 0 1 6 0V9'],
    ['/admin/utilisateurs', 'Utilisateurs', 'M10 9.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM4 17.5c.6-3.2 3-5 6-5s5.4 1.8 6 5'],
    ['/admin/groupes', 'Groupes', 'M7 9a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM13.5 9a2 2 0 1 0 0-4M2.5 16.5c.5-2.8 2.3-4.5 4.5-4.5s4 1.7 4.5 4.5M13 12c2.2 0 3.8 1.5 4.3 4'],
    ['/admin/messages', 'Messages', 'M2.5 5h15v10h-15zM2.5 5.5l7.5 6 7.5-6'],
    ['/admin/reglages', 'Réglages', 'M10 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM10 2v2.5M10 15.5V18M2 10h2.5M15.5 10H18M4.3 4.3l1.8 1.8M13.9 13.9l1.8 1.8M4.3 15.7l1.8-1.8M13.9 6.1l1.8-1.8'],
    ['/admin/journal', 'Journal', 'M4 3.5h12v13H4zM7 7h6M7 10h6M7 13h4'],
];
$isCurrent = function (string $href) use ($reqPath): bool {
    if ($href === '/admin') {
        return $reqPath === '/admin';
    }
    return $reqPath === $href || str_starts_with($reqPath, $href . '/');
};
?>
<!doctype html>
<html lang="fr-CH">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e(App\Csrf::token()) ?>">
<title><?= e($title) ?> – Administration <?= e($siteName) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<script>document.documentElement.classList.add('js');</script>
<script src="<?= e(asset('js/admin.js')) ?>" defer></script>
<?php foreach ((isset($extraScripts) && is_array($extraScripts) ? $extraScripts : []) as $s): ?>
<script src="<?= e(asset((string) $s)) ?>" defer></script>
<?php endforeach; ?>
</head>
<body class="admin <?= e(isset($bodyClass) && is_string($bodyClass) ? $bodyClass : '') ?>">
<a class="skip-link" href="#admin-main">Aller au contenu</a>

<header class="adm-top">
  <button type="button" class="adm-menu-toggle" aria-expanded="false" aria-controls="adm-nav">
    <svg aria-hidden="true" viewBox="0 0 20 20" width="22" height="22"><path d="M3 5.5h14M3 10h14M3 14.5h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    <span>Menu</span>
  </button>
  <a class="adm-brand" href="/admin"><strong><?= e($siteName) ?></strong> <span>Administration</span></a>
  <div class="adm-top-tools">
    <a class="adm-top-link" href="/" target="_blank" rel="noopener">
      <svg aria-hidden="true" viewBox="0 0 20 20" width="18" height="18"><path d="M11 3h6v6M17 3l-8 8M14 11.5V16a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h4.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
      <span>Voir le site</span>
    </a>
  </div>
</header>

<div class="adm-shell">
  <nav class="adm-nav" id="adm-nav" aria-label="Menu de l’administration">
    <ul role="list">
      <?php foreach ($nav as [$href, $label, $icon]): $cur = $isCurrent($href); ?>
      <li><a href="<?= e($href) ?>"<?= $cur ? ' aria-current="page" class="is-current"' : '' ?>>
        <svg aria-hidden="true" viewBox="0 0 20 20" width="20" height="20"><path d="<?= e($icon) ?>" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span><?= e($label) ?></span>
      </a></li>
      <?php endforeach; ?>
    </ul>
    <div class="adm-user">
      <?php if ($user): ?>
      <p class="adm-user-name">
        <svg aria-hidden="true" viewBox="0 0 20 20" width="18" height="18"><path d="M10 9.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM4 17.5c.6-3.2 3-5 6-5s5.4 1.8 6 5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        <span>Connecté·e&nbsp;: <strong><?= e($user['name']) ?></strong></span>
      </p>
      <a class="adm-user-link" href="/espace-membres/profil">Mon profil et mot de passe</a>
      <?php endif; ?>
      <form method="post" action="/deconnexion">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-ghost btn-block">Se déconnecter</button>
      </form>
    </div>
  </nav>
  <div class="adm-nav-backdrop" hidden></div>

  <main class="adm-main<?= !empty($wide) ? ' is-wide' : '' ?>" id="admin-main" tabindex="-1">
    <?php if ($flashes): ?>
    <div class="flashes">
      <?php foreach ($flashes as $f):
          $type = in_array($f['type'] ?? '', ['success', 'error', 'warning', 'info'], true) ? $f['type'] : 'info'; ?>
      <div class="flash flash-<?= e($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>">
        <span><?= e($f['msg'] ?? '') ?></span>
        <button type="button" class="flash-close" aria-label="Fermer ce message">×</button>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?= $content ?>
  </main>
</div>
<div class="toast" role="status" aria-live="polite" hidden></div>
</body>
</html>
