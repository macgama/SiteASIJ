<?php
declare(strict_types=1);
/**
 * Remplit une base VIDE avec un site de démonstration (structure de l'ancien site,
 * textes résumés, documents et photos factices, comptes et dossiers de démo).
 * Ne JAMAIS lancer en production.  Usage: php bin/demo-seed.php
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI uniquement');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Db;
use App\Pages;
use App\Settings;

try {
    Db::value('SELECT COUNT(*) FROM users');
} catch (Throwable) {
    Db::runSchema(ROOT . '/database/schema.sql');
}
if ((int) Db::value('SELECT COUNT(*) FROM pages') > 0) {
    fwrite(STDERR, "La base contient déjà des pages: démo non installée.\n");
    exit(1);
}

$now = Db::now();
$mediaDir = STORAGE . '/media';
$privDir = STORAGE . '/private';
@mkdir($mediaDir, 0775, true);
@mkdir($privDir, 0775, true);

/** PDF minimal valide contenant quelques lignes de texte. */
function demo_pdf(string $title): string
{
    $lines = [$title, '', 'Document de demonstration - ASIJ', 'Le vrai document sera repris de l\'ancien site lors de l\'import.'];
    $txt = "BT /F1 16 Tf 60 780 Td 20 TL\n";
    foreach ($lines as $l) {
        $l = iconv('UTF-8', 'ASCII//TRANSLIT', $l) ?: $l;
        $txt .= '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $l) . ") Tj T*\n";
    }
    $txt .= "ET";
    $objs = [
        "<< /Type /Catalog /Pages 2 0 R >>",
        "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>",
        "<< /Length " . strlen($txt) . " >>\nstream\n$txt\nendstream",
        "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    ];
    $pdf = "%PDF-1.4\n";
    $off = [];
    foreach ($objs as $i => $o) {
        $off[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n$o\nendobj\n";
    }
    $x = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($off as $o) {
        $pdf .= sprintf("%010d 00000 n \n", $o);
    }
    return $pdf . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$x\n%%EOF\n";
}

/** Photo factice: paysage stylisé du Jorat (dégradé, collines, bâtiment). */
function demo_photo(int $seed): string
{
    $w = 1200; $h = 800;
    $im = imagecreatetruecolor($w, $h);
    mt_srand($seed);
    $skyTop = [90 + mt_rand(0, 40), 150 + mt_rand(0, 40), 210 + mt_rand(0, 30)];
    for ($y = 0; $y < $h; $y++) {
        $k = $y / $h;
        $c = imagecolorallocate($im, min(255, (int) ($skyTop[0] + 100 * $k)), min(255, (int) ($skyTop[1] + 70 * $k)), min(255, (int) ($skyTop[2] + 30 * $k)));
        imageline($im, 0, $y, $w, $y, $c);
    }
    foreach ([[0.62, [86, 140, 70]], [0.75, [60, 110, 50]], [0.88, [45, 90, 40]]] as $i => [$base, $col]) {
        $c = imagecolorallocate($im, $col[0], $col[1], $col[2]);
        $amp = 30 + mt_rand(0, 40); $ph = mt_rand(0, 100) / 10;
        $pts = [];
        for ($x = 0; $x <= $w; $x += 20) {
            $pts[] = $x; $pts[] = (int) ($h * $base + $amp * sin($x / (180 + 40 * $i) + $ph));
        }
        array_push($pts, $w, $h, 0, $h);
        imagefilledpolygon($im, $pts, $c);
        if ($i === 0) {
            $bx = 250 + mt_rand(0, 600); $by = (int) ($h * 0.62) - 40; $bw = 220 + mt_rand(0, 120);
            imagefilledrectangle($im, $bx, $by - 120, $bx + $bw, $by + 20, imagecolorallocate($im, 236, 230, 218));
            imagefilledpolygon($im, [$bx - 15, $by - 120, $bx + $bw / 2, $by - 190, $bx + $bw + 15, $by - 120], imagecolorallocate($im, 160, 60, 45));
            $win = imagecolorallocate($im, 70, 95, 120);
            for ($wx = $bx + 18; $wx < $bx + $bw - 30; $wx += 42) {
                imagefilledrectangle($im, $wx, $by - 100, $wx + 24, $by - 70, $win);
                imagefilledrectangle($im, $wx, $by - 50, $wx + 24, $by - 20, $win);
            }
        }
    }
    ob_start();
    imagejpeg($im, null, 82);
    imagedestroy($im);
    return (string) ob_get_clean();
}

function demo_media(string $name, string $title, string $content, string $mime, string $kind): int
{
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $stored = bin2hex(random_bytes(12)) . '.' . $ext;
    file_put_contents(STORAGE . '/media/' . $stored, $content);
    return Db::insert('media', [
        'stored_name' => $stored, 'original_name' => $name, 'title' => $title, 'mime' => $mime,
        'size' => strlen($content), 'kind' => $kind, 'alt' => $kind === 'image' ? $title : null,
        'created_at' => Db::now(),
    ]);
}

$bid = 0;
$B = function (string $type, array $data) use (&$bid): array {
    return ['id' => 'b_demo' . (++$bid), 'type' => $type, 'data' => $data];
};

// ----------------------------------------------------------------- arborescence
$manifest = json_decode((string) file_get_contents(ROOT . '/migration/manifest.json'), true);
$byPath = [];
foreach ($manifest['pages'] as $p) {
    $byPath[$p['path']] = $p;
}
$TITLES = [
    '/' => 'Accueil', '/présentation/' => 'Présentation', '/présentation/le-comité-de-direction/' => 'Le Comité de direction',
    '/présentation/le-conseil-intercommunal/' => 'Le Conseil intercommunal', '/présentation/le-conseil-intercommunal/ordres-du-jour/' => 'Ordres du jour',
    '/présentation/le-conseil-intercommunal/prochaines-séances-du-conseil-intercommunal/' => 'Prochaines séances',
    '/présentation/le-conseil-intercommunal/règlement-du-conseil-intercommunal/' => 'Règlement du Conseil intercommunal',
    '/présentation/les-statuts-de-l-asij/' => "Les statuts de l'ASIJ", '/accueil-extrascolaire/' => 'Accueil extrascolaire',
    '/accueil-extrascolaire/réfectoire-et-repas/' => 'Réfectoire et repas', '/accueil-extrascolaire/devoirs-surveillés/' => 'Devoirs surveillés',
    '/transports-scolaires/' => 'Transports scolaires', '/transports-scolaires/horaires-des-transports/' => 'Horaires des transports',
    '/transports-scolaires/règlement/' => 'Règlement', '/transports-scolaires/elèves-en-classe-de-racc-i-et-ii/' => 'Élèves en classe de racc I et II',
    '/transports-scolaires/evolution-des-transports/' => 'Évolution des transports', '/bâtiments-scolaires/' => 'Bâtiments scolaires',
    '/bâtiments-scolaires/collège-du-raffort-mézières/' => 'Collège du Raffort, Mézières', '/bâtiments-scolaires/photos-extension-2017/' => 'Photos extension 2017',
    '/bâtiments-scolaires/collège-des-gollies-servion/' => 'Collège des Gollies, Servion', '/bâtiments-scolaires/collège-gustave-roud-carrouge/' => 'Collège Gustave Roud, Carrouge',
    '/bâtiments-scolaires/collège-gustave-roud-carrouge/en-images/' => 'En images', '/liens-utiles/' => 'Liens utiles', '/en-cours/' => 'En cours',
    '/contact/' => 'Contact', '/cet/' => "Conseil d'établissement (CEt)",
];
$ORDER = ['/', '/présentation/', '/bâtiments-scolaires/', '/transports-scolaires/', '/accueil-extrascolaire/', '/cet/', '/liens-utiles/', '/en-cours/', '/contact/'];
$skip = ['/about/', '/présentation/le-conseil-intercommunal/documents-destinés-aux-membres/'];

$title = function (string $path) use ($TITLES): string {
    if (isset($TITLES[$path])) {
        return $TITLES[$path];
    }
    $seg = basename(rtrim($path, '/'));
    if (preg_match('/^(préavis|décisions)-(.+)$/u', $seg, $m)) {
        return ($m[1] === 'préavis' ? 'Préavis ' : 'Décisions ') . $m[2];
    }
    return mb_strtoupper(mb_substr($seg, 0, 1)) . str_replace('-', ' ', mb_substr($seg, 1));
};

$paths = array_values(array_filter(array_keys($byPath), fn($p) => !in_array($p, $skip, true)));
usort($paths, function ($a, $b) use ($ORDER) {
    $da = substr_count($a, '/'); $db = substr_count($b, '/');
    if ($da !== $db) {
        return $da <=> $db;
    }
    $ia = array_search($a, $ORDER, true); $ib = array_search($b, $ORDER, true);
    if ($ia !== false || $ib !== false) {
        return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
    }
    // Préavis / décisions: plus récent d'abord
    if (preg_match('/(préavis|décisions)-(\d{4})/u', $a, $ma) && preg_match('/(préavis|décisions)-(\d{4})/u', $b, $mb)) {
        return [$ma[1], -(int) $ma[2]] <=> [$mb[1], -(int) $mb[2]];
    }
    return strcmp($a, $b);
});

$photo = 0;
$ids = [];
foreach ($paths as $i => $path) {
    $p = $byPath[$path];
    $parentPath = $path === '/' ? null : (preg_replace('#[^/]+/$#u', '', $path) ?: '/');
    $parentId = ($parentPath && $parentPath !== '/') ? ($ids[$parentPath] ?? null) : null;
    $t = $title($path);
    $blocks = [];
    if ($path === '/') {
        $blocks[] = $B('text', ['html' => "<p>L'Association scolaire intercommunale du Jorat (ASIJ) assure la gestion des <strong>bâtiments</strong>, des <strong>transports scolaires</strong> et de l'<strong>accueil extrascolaire</strong> pour la scolarité obligatoire des élèves de Forel (Lavaux), Jorat-Mézières, Savigny et des autres communes membres, soit dix communes.</p>"]);
        $blocks[] = $B('callout', ['style' => 'warning', 'title' => 'Travaux routiers', 'html' => '<p>Veuillez vous renseigner sur les sites des communes.</p>']);
        $blocks[] = $B('heading', ['text' => 'Actualités', 'level' => 2]);
        $blocks[] = $B('columns', [
            'left' => "<h3>Mise au concours</h3><p>L'ASIJ met au concours le poste de <strong>secrétaire</strong> (environ 20 %), basé au Collège du Raffort à Mézières.</p>",
            'right' => '<h3>Transports scolaires</h3><p>Les horaires 2026-2027 sont disponibles sur la page <a href="/transports-scolaires/horaires-des-transports">Horaires des transports</a>.</p>',
        ]);
        $img = demo_media('jorat.jpg', 'Le collège du Raffort, à Mézières (image de démonstration)', demo_photo(++$photo), 'image/jpeg', 'image');
        $blocks[] = $B('image', ['media_id' => $img, 'alt' => 'Collège dans le paysage du Jorat', 'caption' => 'Image de démonstration', 'width' => 'full']);
        $blocks[] = $B('heading', ['text' => 'Contact', 'level' => 2]);
        $blocks[] = $B('people', ['items' => [['name' => 'Nicolas Deprez', 'role' => 'Directeur des opérations', 'commune' => 'Mézières']]]);
        $blocks[] = $B('button', ['label' => 'Nous écrire', 'url' => '/contact']);
    } else {
        $notes = array_filter($p['notes'] ?? [], fn($n) => $n !== '' && !str_starts_with($n, 'Fichiers') && !str_starts_with($n, 'Image'));
        if ($notes) {
            $blocks[] = $B('text', ['html' => '<p>' . implode('</p><p>', array_map('e', $notes)) . '</p>']);
        }
        if ($path === '/contact/') {
            $blocks[] = $B('contact', ['intro' => "Écrivez-nous, nous vous répondrons rapidement."]);
        }
        if ($path === '/liens-utiles/') {
            $blocks[] = $B('button', ['label' => 'Établissement scolaire du Jorat', 'url' => 'https://www.ecolesdujorat.ch/']);
        }
        if (str_contains($path, 'collège-gustave-roud-carrouge/') && !str_contains($path, 'en-images')) {
            $blocks[] = $B('callout', ['style' => 'info', 'title' => 'Vidéos du chantier', 'html' => "<p>Les 48 films hebdomadaires du chantier (2020-2021) seront publiés sur YouTube et intégrés ici avec le bloc « Vidéo ».</p>"]);
        }
        if (!empty($p['files'])) {
            $items = [];
            foreach ($p['files'] as $f) {
                $label = $f['label'];
                $name = preg_replace('/[^\w.\- ]+/u', '', iconv('UTF-8', 'ASCII//TRANSLIT', $label) ?: 'document') . '.pdf';
                $items[] = ['media_id' => demo_media($name, $label, demo_pdf($label), 'application/pdf', 'file'), 'label' => $label];
            }
            $blocks[] = $B('files', ['title' => 'Documents', 'items' => $items, 'sort' => 'manual']);
        }
        $nImg = count($p['images'] ?? []);
        if ($nImg > 1) {
            $items = [];
            for ($k = 0; $k < min($nImg, 12); $k++) {
                $items[] = ['media_id' => demo_media("photo-$photo.jpg", "$t, photo " . ($k + 1), demo_photo(++$photo), 'image/jpeg', 'image'), 'caption' => "$t, photo " . ($k + 1)];
            }
            $blocks[] = $B('gallery', ['title' => 'Photos', 'items' => $items]);
        } elseif ($nImg === 1) {
            $blocks[] = $B('image', ['media_id' => demo_media("photo-$photo.jpg", $t, demo_photo(++$photo), 'image/jpeg', 'image'), 'alt' => $t, 'caption' => '', 'width' => 'half']);
        }
    }
    $slug = $path === '/' ? '' : Pages::slugify(basename(rtrim($path, '/')));
    $id = Db::insert('pages', [
        'parent_id' => $parentId, 'slug' => $slug, 'path' => $path === '/' ? '/' : Pages::computePath($parentId, $slug),
        'title' => $path === '/' ? "Bienvenue sur le site de l'ASIJ" : $t, 'menu_title' => $path === '/' ? 'Accueil' : null,
        'meta_description' => null, 'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE),
        'status' => 'published', 'show_in_menu' => $path === '/' ? 0 : 1, 'sort_order' => $i,
        'legacy_url' => $path, 'updated_at' => $now, 'updated_by' => null,
    ]);
    $ids[$path] = $id;
}

// ----------------------------------------------------------------- réglages
Settings::set('contact_email', 'info@exemple.ch');
Settings::set('contact_phone', '021 000 00 00');
Settings::set('banner_text', 'Site de démonstration : contenus et documents factices.');

// ----------------------------------------------------------------- comptes et espace sécurisé
$groups = [];
foreach ([['Comité de direction', 'codir'], ['Conseil intercommunal', 'conseil'], ['Bureau du Conseil', 'bureau'], ['Direction', 'direction']] as [$n, $s]) {
    $groups[$s] = (int) (Db::value('SELECT id FROM user_groups WHERE slug = ?', [$s]) ?: Db::insert('user_groups', ['name' => $n, 'slug' => $s, 'description' => null]));
}
$accounts = [
    ['admin@demo.asij.ch', 'Admin Démo', 'admin', [], 'DemoAdmin2026!'],
    ['codir@demo.asij.ch', 'Membre CoDir (démo)', 'member', ['codir'], 'DemoCodir2026!'],
    ['conseil@demo.asij.ch', 'Membre Conseil (démo)', 'member', ['conseil'], 'DemoConseil2026!'],
];
foreach ($accounts as [$email, $name, $role, $gs, $pw]) {
    if (Db::one('SELECT id FROM users WHERE email = ?', [$email])) {
        continue;
    }
    $uid = Db::insert('users', ['email' => $email, 'name' => $name, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => $role, 'is_active' => 1, 'created_at' => $now]);
    foreach ($gs as $g) {
        Db::insert('user_group_members', ['user_id' => $uid, 'group_id' => $groups[$g]]);
    }
}
$folder = function (string $name, ?int $parent, array $gs, array $docs) use ($groups, $now) {
    $id = Db::insert('private_folders', ['parent_id' => $parent, 'name' => $name, 'description' => null, 'sort_order' => 0, 'created_at' => $now]);
    foreach ($gs as $g) {
        Db::insert('private_folder_groups', ['folder_id' => $id, 'group_id' => $groups[$g]]);
    }
    foreach ($docs as $d) {
        $c = demo_pdf($d . ' (CONFIDENTIEL - demo)');
        $stored = bin2hex(random_bytes(16));
        file_put_contents(STORAGE . '/private/' . $stored, $c);
        Db::insert('private_files', ['folder_id' => $id, 'stored_name' => $stored, 'original_name' => $d . '.pdf', 'title' => $d,
            'mime' => 'application/pdf', 'size' => strlen($c), 'sha256' => hash('sha256', $c), 'uploaded_by' => null, 'created_at' => $now]);
    }
    return $id;
};
$codir = $folder('Comité de direction', null, ['codir', 'direction'], ['PV séance CoDir du 3 novembre 2026', 'Budget 2027 - document de travail']);
$folder('Ressources humaines', $codir, [], ['Cahier des charges secrétaire']);
$folder('Documents destinés aux membres du Conseil', null, ['conseil', 'bureau', 'codir'], ['Rapport de la commission de gestion 2026', 'Convocation séance du 13 janvier 2027']);
$folder('Bureau du Conseil', null, ['bureau'], ['Notes du bureau - décembre 2026']);

echo "Démo installée: " . count($ids) . " pages, " . Db::value('SELECT COUNT(*) FROM media') . " médias.\n";
foreach ($accounts as [$email, , $role, , $pw]) {
    echo "  $email / $pw ($role)\n";
}
