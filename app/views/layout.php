<?php
declare(strict_types=1);
/**
 * Gabarit public. Variables reconnues (toutes facultatives sauf $content):
 * $title, $content, $metaDescription, $page, $breadcrumbs, $currentPath, $isDraft, $bodyClass, $noindex.
 */
use App\Auth;
use App\Controllers\PageController;
use App\Media;
use App\Settings;

$siteName = Settings::get('site_name') ?: 'ASIJ';
$tagline = Settings::get('site_tagline');
$title = isset($title) && is_string($title) ? trim($title) : '';
$fullTitle = $title !== '' ? $title . ' – ' . $siteName : $siteName . ($tagline !== '' ? ' – ' . $tagline : '');
$metaDescription = isset($metaDescription) && is_string($metaDescription) && trim($metaDescription) !== ''
    ? trim($metaDescription) : $tagline;
$reqPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$currentPath = isset($currentPath) && is_string($currentPath) ? $currentPath : PageController::normalizePath(rawurldecode($reqPath));
$canonical = url(PageController::href($currentPath));

$logoId = (int) Settings::get('logo_media_id');
$logoUrl = $logoId > 0 ? Media::url($logoId) : '';
$logoSrc = $logoUrl !== '' ? $logoUrl : asset('img/logo-asij.png');
$ogImage = $logoUrl !== '' ? url($logoUrl) : url('/assets/img/logo-asij.png');

$banner = trim(Settings::get('banner_text'));
$flashes = function_exists('flash') ? (flash() ?? []) : [];
$user = Auth::user();
$menu = PageController::menu();

/** Le chemin $p est-il la page courante ou un de ses ancêtres ? */
$isActive = function (string $p) use ($currentPath): bool {
    return $p === $currentPath || ($p !== '/' && str_starts_with($currentPath, $p . '/'));
};

$renderMenu = function (array $items, int $level) use (&$renderMenu, $isActive, $currentPath): string {
    $h = '<ul class="nav-list nav-l' . $level . '" role="list">';
    foreach ($items as $it) {
        $has = !empty($it['children']);
        $active = $isActive($it['path']);
        $cls = trim(($has ? 'has-sub ' : '') . ($active ? 'is-active' : ''));
        $h .= '<li' . ($cls !== '' ? ' class="' . $cls . '"' : '') . '>';
        $h .= '<div class="nav-row"><a href="' . e(PageController::href($it['path'])) . '"'
            . ($it['path'] === $currentPath ? ' aria-current="page"' : '') . '>' . e($it['title']) . '</a>';
        if ($has) {
            $sid = 'sub-' . (int) $it['id'];
            $h .= '<button type="button" class="sub-toggle" aria-expanded="false" aria-controls="' . $sid . '">'
                . '<span class="visually-hidden">Afficher les pages de « ' . e($it['title']) . ' »</span>'
                . '<svg aria-hidden="true" focusable="false" viewBox="0 0 16 16" width="16" height="16"><path d="M3.5 6l4.5 4.5L12.5 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                . '</button>';
        }
        $h .= '</div>';
        if ($has) {
            $long = count($it['children']) > 8 ? ' is-long' : '';
            $h .= '<div class="nav-sub nav-sub-l' . ($level + 1) . $long . '" id="sub-' . (int) $it['id'] . '">'
                . $renderMenu($it['children'], $level + 1) . '</div>';
        }
        $h .= '</li>';
    }
    return $h . '</ul>';
};

$address = trim(Settings::get('contact_address'));
$email = trim(Settings::get('contact_email'));
$phone = trim(Settings::get('contact_phone'));
$footerText = trim(Settings::get('footer_text'));
$robots = !empty($noindex) || !empty($isDraft) || (isset($code) && is_int($code) && $code >= 400);
?>
<!doctype html>
<html lang="fr-CH">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($fullTitle) ?></title>
<?php if ($metaDescription !== ''): ?><meta name="description" content="<?= e($metaDescription) ?>">
<?php endif; ?>
<?php if ($robots): ?><meta name="robots" content="noindex">
<?php endif; ?>
<meta name="csrf-token" content="<?= e(App\Csrf::token()) ?>">
<link rel="canonical" href="<?= e($canonical) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($siteName) ?>">
<meta property="og:title" content="<?= e($title !== '' ? $title : $siteName) ?>">
<?php if ($metaDescription !== ''): ?><meta property="og:description" content="<?= e($metaDescription) ?>">
<?php endif; ?>
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta property="og:locale" content="fr_CH">
<meta name="theme-color" content="#1f3566">
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/site.css')) ?>">
<script>document.documentElement.className += ' js';</script>
<script src="<?= e(asset('js/site.js')) ?>" defer></script>
</head>
<body class="<?= e(trim('site ' . (isset($bodyClass) && is_string($bodyClass) ? $bodyClass : ''))) ?>">
<a class="skip-link" href="#contenu">Aller au contenu</a>

<?php if ($banner !== ''): ?>
<div class="urgent-banner" role="region" aria-label="Information importante">
  <div class="container"><span class="urgent-label">Information</span> <?= App\Html::nl($banner) ?></div>
</div>
<?php endif; ?>

<header class="site-header">
  <div class="container header-inner">
    <a class="brand" href="/">
      <img class="brand-logo" src="<?= e($logoSrc) ?>" alt="<?= e($siteName) ?>" height="48">
      <?php if ($tagline !== ''): ?><span class="brand-tagline"><?= e($tagline) ?></span><?php endif; ?>
      <span class="visually-hidden"> – retour à l'accueil</span>
    </a>
    <div class="header-tools">
      <?php if ($user && ($user['role'] ?? '') === 'admin'): ?>
      <a class="tool-link" href="/admin">Administration</a>
      <?php endif; ?>
      <a class="btn btn-members" href="/espace-membres">
        <svg aria-hidden="true" focusable="false" viewBox="0 0 20 20" width="18" height="18"><rect x="4" y="9" width="12" height="9" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M7 9V6.5a3 3 0 0 1 6 0V9" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
        <span>Espace membres</span>
      </a>
      <button type="button" class="nav-toggle" aria-expanded="false" aria-controls="main-nav">
        <span class="nav-toggle-bars" aria-hidden="true"><span></span></span>
        <span class="nav-toggle-label">Menu</span>
      </button>
    </div>
  </div>
  <nav class="main-nav" id="main-nav" aria-label="Menu principal">
    <div class="container">
      <?= $menu ? $renderMenu($menu, 1) : '' ?>
    </div>
  </nav>
</header>

<main id="contenu" tabindex="-1">
  <?php if ($flashes): ?>
  <div class="container flashes">
    <?php foreach ($flashes as $f):
        $type = in_array($f['type'] ?? '', ['success', 'error', 'warning', 'info'], true) ? $f['type'] : 'info'; ?>
    <div class="flash flash-<?= e($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>"><?= e($f['msg'] ?? '') ?></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?= $content ?>
</main>

<footer class="site-footer">
  <div class="container footer-inner">
    <div class="footer-col">
      <p class="footer-name"><?= e($siteName) ?></p>
      <?php if ($tagline !== ''): ?><p class="footer-tagline"><?= e($tagline) ?></p><?php endif; ?>
      <?php if ($address !== ''): ?><address><?= App\Html::nl($address) ?></address><?php endif; ?>
    </div>
    <div class="footer-col">
      <p class="footer-heading">Contact</p>
      <ul class="footer-list" role="list">
        <?php if ($phone !== ''): ?><li>Téléphone&nbsp;: <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>"><?= e($phone) ?></a></li><?php endif; ?>
        <?php if ($email !== ''): ?><li>E-mail&nbsp;: <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></li><?php endif; ?>
        <li><a href="/contact">Formulaire de contact</a></li>
      </ul>
      <?php if ($footerText !== ''): ?><p class="footer-text"><?= App\Html::nl($footerText) ?></p><?php endif; ?>
    </div>
    <div class="footer-col">
      <p class="footer-heading">Liens</p>
      <ul class="footer-list" role="list">
        <li><a href="<?= e(PageController::LEGAL_PATH) ?>">Mentions légales</a></li>
        <li><a href="<?= e(PageController::SITEMAP_PATH) ?>">Plan du site</a></li>
        <li><a href="/espace-membres">Espace membres</a></li>
      </ul>
    </div>
  </div>
  <div class="container footer-bottom">
    <p>© <?= date('Y') ?> <?= e($siteName) ?></p>
  </div>
</footer>

<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="Photo agrandie" hidden>
  <div class="lightbox-inner">
    <figure class="lightbox-figure">
      <img class="lightbox-img" src="data:," alt="">
      <figcaption class="lightbox-caption"></figcaption>
    </figure>
    <p class="lightbox-count" aria-live="polite"></p>
    <button type="button" class="lightbox-btn lightbox-close" aria-label="Fermer (Échap)">
      <svg aria-hidden="true" focusable="false" viewBox="0 0 20 20" width="22" height="22"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
    </button>
    <button type="button" class="lightbox-btn lightbox-prev" aria-label="Photo précédente">
      <svg aria-hidden="true" focusable="false" viewBox="0 0 20 20" width="24" height="24"><path d="M12.5 4L6.5 10l6 6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>
    <button type="button" class="lightbox-btn lightbox-next" aria-label="Photo suivante">
      <svg aria-hidden="true" focusable="false" viewBox="0 0 20 20" width="24" height="24"><path d="M7.5 4l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>
  </div>
</div>
</body>
</html>
