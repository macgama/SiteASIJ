<?php
declare(strict_types=1);

use App\Db;

/**
 * Tests de bin/import-jimdo.php sur les pages Jimdo de tests/fixtures/jimdo/ (mode hors-ligne:
 * --from-dir / --files-dir), avec des dossiers temporaires pour les médias, le cache et le rapport.
 */
require_once dirname(__DIR__) . '/bin/import-jimdo.php';

/** Prépare (une seule fois) le dossier de travail et retourne les options de l'importeur. */
function migration_opts(): array
{
    static $opts = null;
    if ($opts !== null) {
        return $opts;
    }
    $base = sys_get_temp_dir() . '/asij-migtest-' . getmypid() . '-' . bin2hex(random_bytes(3));
    $pages = "$base/pages";
    $files = "$base/files";
    foreach ([$pages, $files, "$base/media", "$base/cache"] as $d) {
        mkdir($d, 0777, true);
    }
    $all = '';
    foreach (glob(__DIR__ . '/fixtures/jimdo/*.html') as $f) {
        copy($f, "$pages/" . basename($f));
        $all .= file_get_contents($f);
    }
    // pages parentes minimales (seules 4 fixtures réalistes sont fournies)
    $mini = fn(string $t, string $body = '') => '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $t
        . ' - ASIJ - Association scolaire intercommunale du Jorat</title></head><body><div id="content_area" data-container="content">'
        . '<div class="j-module n j-header"><h1>' . $t . '</h1></div>' . $body . '</div></body></html>';
    file_put_contents("$pages/index.html", $mini('Bienvenue', '<div class="j-module n j-text"><p>Accueil</p></div>'));
    file_put_contents("$pages/presentation__le-conseil-intercommunal.html", $mini('Le Conseil intercommunal'));
    file_put_contents("$pages/batiments-scolaires.html", $mini('Bâtiments scolaires'));
    file_put_contents("$pages/contact.html", $mini('Contact', '<div class="j-module n j-formnew"><form><input name="x"></form></div>'));

    // fichiers factices: un PDF par identifiant de téléchargement, un PNG par identifiant d'image
    preg_match_all('#/app/download/(\d+)/[^"?]+\.(pdf|mp4)#', $all, $m, PREG_SET_ORDER);
    foreach ($m as [, $id, $ext]) {
        file_put_contents("$files/$id.$ext", "%PDF-1.4\n% fichier factice $id\n%%EOF\n");
    }
    copy("$files/20123300125.pdf", "$files/20123300325.pdf"); // doublon de contenu volontaire
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    preg_match_all('#/image/(i[0-9a-f]+)/#', $all, $m);
    foreach (array_unique($m[1]) as $iid) {
        file_put_contents("$files/$iid.png", $png . $iid);
    }

    $paths = [
        '/', '/about/', '/bâtiments-scolaires/', '/bâtiments-scolaires/photos-extension-2017/', '/contact/',
        '/présentation/', '/présentation/le-conseil-intercommunal/',
        '/présentation/le-conseil-intercommunal/documents-destinés-aux-membres/',
        '/présentation/le-conseil-intercommunal/préavis-2026/', '/transports-scolaires/',
    ];
    file_put_contents("$base/manifest.json", json_encode([
        'source' => 'https://www.asij.ch',
        'common' => ['logo' => 'https://image.jimcdn.com/app/cms/image/transf/dimension=239x10000:format=png/path/s2c49c8dc469ab9ea/image/ie9fe4872af78a219/version/1487177292/image.png'],
        'pages' => array_map(fn($p) => ['path' => $p, 'notes' => [], 'files' => [], 'images' => []], $paths),
    ], JSON_UNESCAPED_UNICODE));

    return $opts = [
        'manifest' => "$base/manifest.json", 'from-dir' => $pages, 'files-dir' => $files, 'media-dir' => "$base/media",
        'cache' => "$base/cache", 'report' => "$base/rapport.md", 'quiet' => true, 'delay' => 0, 'cdn-delay' => 0,
        'logo-path' => "$base/logo.png", 'write-logo' => true,
    ];
}

/** Lance un import (options de base + $extra). */
function migration_import(array $extra = []): JimdoImporter
{
    $imp = new JimdoImporter(array_merge(migration_opts(), $extra));
    $imp->run();
    return $imp;
}

/** Résultat du premier import (lancé une seule fois). */
function migration_first(): JimdoImporter
{
    static $imp = null;
    return $imp ??= migration_import();
}

function migration_page(string $legacy): array
{
    migration_first();
    $p = Db::one('SELECT * FROM pages WHERE legacy_url = ?', [$legacy]);
    check($p !== null, "page $legacy absente");
    $p['b'] = json_decode((string) $p['blocks'], true);
    return $p;
}

function migration_types(array $page): array
{
    return array_column($page['b'], 'type');
}

function migration_counts(): array
{
    return [
        'pages' => (int) Db::value('SELECT COUNT(*) FROM pages WHERE legacy_url IS NOT NULL'),
        'media' => (int) Db::value('SELECT COUNT(*) FROM media WHERE legacy_url IS NOT NULL'),
        'folders' => (int) Db::value('SELECT COUNT(*) FROM private_folders'),
        'folder_groups' => (int) Db::value('SELECT COUNT(*) FROM private_folder_groups'),
        'files_on_disk' => count(glob(migration_opts()['media-dir'] . '/*')),
    ];
}

return [
    'Migration: slugs ASCII et nouveaux chemins' => function () {
        $cases = [
            '/présentation/' => '/presentation',
            '/présentation/le-comité-de-direction/' => '/presentation/le-comite-de-direction',
            '/présentation/le-conseil-intercommunal/décisions-2026/' => '/presentation/le-conseil-intercommunal/decisions-2026',
            '/transports-scolaires/règlement/' => '/transports-scolaires/reglement',
            '/bâtiments-scolaires/collège-du-raffort-mézières/' => '/batiments-scolaires/college-du-raffort-mezieres',
            '/transports-scolaires/elèves-en-classe-de-racc-i-et-ii/' => '/transports-scolaires/eleves-en-classe-de-racc-i-et-ii',
            '/pr%C3%A9sentation/les-statuts-de-l-asij/' => '/presentation/les-statuts-de-l-asij',
            '/accueil-extrascolaire/devoirs-surveille%CC%81s/' => '/accueil-extrascolaire/devoirs-surveilles', // accents décomposés
            '/' => '/',
            '/about/' => '/mentions-legales',
        ];
        foreach ($cases as $old => $new) {
            check(JimdoImporter::newPath($old) === $new, "$old -> " . JimdoImporter::newPath($old) . " (attendu $new)");
        }
        check(JimdoImporter::normPath('https://www.asij.ch/pr%C3%A9sentation/le-comit%C3%A9-de-direction/?x=1') === '/présentation/le-comité-de-direction/');
    },

    'Migration: URL originale des images Jimdo (avec ou sans transf)' => function () {
        $orig = 'https://image.jimcdn.com/app/cms/image/path/s2c49c8dc469ab9ea/image/ie9fe4872af78a219/version/1487177292/image.png';
        check(JimdoImporter::originalImageUrl('https://image.jimcdn.com/app/cms/image/transf/dimension=239x10000:format=png/path/s2c49c8dc469ab9ea/image/ie9fe4872af78a219/version/1487177292/image.png') === $orig);
        check(JimdoImporter::originalImageUrl('//image.jimcdn.com/app/cms/image/transf/none/path/s2c49c8dc469ab9ea/image/ie9fe4872af78a219/version/1487177292/image.png?x=1') === $orig);
        check(JimdoImporter::originalImageUrl($orig) === $orig, 'URL déjà originale');
        check(JimdoImporter::videoUrl('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0') === 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    },

    'Migration: page de texte (titre, blocs, colonnes, vidéo, bouton)' => function () {
        $p = migration_page('/présentation/');
        check($p['title'] === '10 communes pour favoriser une meilleure scolarité', 'titre = h1: ' . $p['title']);
        check($p['menu_title'] === 'Présentation', 'titre de menu');
        check($p['path'] === '/presentation' && $p['slug'] === 'presentation', 'chemin ' . $p['path']);
        check($p['status'] === 'published' && (int) $p['show_in_menu'] === 1);
        check(str_starts_with((string) $p['meta_description'], 'Présentation'), 'meta description');
        $types = migration_types($p);
        check($types === ['text', 'heading', 'text', 'heading', 'text', 'separator', 'video', 'button'], 'blocs: ' . implode(',', $types));
        check($p['b'][3]['data']['level'] === 3, 'h3 -> niveau 3');
        check($p['b'][6]['data']['url'] === 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'vidéo YouTube');
        check($p['b'][7]['data'] === ['label' => 'Nous contacter', 'url' => '/contact'], 'bouton');
        foreach ($p['b'] as $b) {
            check((bool) preg_match('/^b_[a-z0-9]{10}$/', $b['id']), 'id de bloc');
        }
    },

    'Migration: aucune balise script ni attribut dangereux' => function () {
        migration_first();
        foreach (Db::all('SELECT legacy_url, blocks FROM pages WHERE legacy_url IS NOT NULL') as $r) {
            $b = strtolower($r['blocks']);
            check(!str_contains($b, '<script') && !str_contains($b, 'onclick') && !str_contains($b, 'javascript:') && !str_contains($b, 'style='), 'contenu dangereux: ' . $r['legacy_url']);
            check(!str_contains($b, 'tracker.example'), 'image de pistage');
        }
    },

    'Migration: liens internes réécrits' => function () {
        $html = migration_page('/présentation/')['b'][0]['data']['html'];
        check(str_contains($html, 'href="/presentation/le-comite-de-direction"'), 'lien relatif encodé');
        check(str_contains($html, 'href="/presentation/le-conseil-intercommunal"'), 'lien absolu www.asij.ch');
        check(str_contains($html, 'href="/presentation/le-conseil-intercommunal/preavis-2026#oj"'), 'lien non encodé + ancre');
        check(str_contains($html, 'href="http://www.vd.ch/'), 'lien externe conservé');
        $p = migration_page('/présentation/');
        check(str_contains($p['b'][2]['data']['html'], 'href="/transports-scolaires"'), 'lien dans une colonne');
        check((bool) preg_match('#href="/media/\d+/[^"]+\.pdf"#', $p['b'][4]['data']['html']), 'lien /app/download/ -> média');
        check(Db::value('SELECT COUNT(*) FROM media WHERE legacy_url = ?', ['https://www.asij.ch/app/download/17419337725/20180601_Statuts%2B+avenant+2018+approuv%C3%A9s.pdf']) == 1, 'statuts importés sans ?t=');
    },

    'Migration: page Préavis avec 3 PDF (bloc files, dédoublonnage)' => function () {
        $p = migration_page('/présentation/le-conseil-intercommunal/préavis-2026/');
        check($p['path'] === '/presentation/le-conseil-intercommunal/preavis-2026', $p['path']);
        check(migration_types($p) === ['text', 'files', 'separator', 'text'], implode(',', migration_types($p)));
        $items = $p['b'][1]['data']['items'];
        check(count($items) === 3, '3 fichiers dans un seul bloc');
        check($items[0]['label'] === '01_26 Préavis budget 2027', 'libellé');
        check($items[2]['media_id'] === $items[0]['media_id'], 'contenu identique -> même média (sha1)');
        $m = Db::one('SELECT * FROM media WHERE id = ?', [$items[1]['media_id']]);
        check($m['mime'] === 'application/pdf' && $m['kind'] === 'file' && (int) $m['size'] > 0, 'mime/kind/size');
        check($m['title'] === '02_26 Préavis crédit rénovation Raffort', 'titre = libellé');
        check($m['original_name'] === Normalizer::normalize('02_26 Préavis crédit rénovation Raffort.pdf'), 'nom NFC: ' . $m['original_name']);
        check(!str_contains($m['legacy_url'], '?t='), 'legacy_url sans ?t=');
        check((bool) preg_match('/^[0-9a-f]{32}\.pdf$/', $m['stored_name']), 'nom stocké aléatoire');
        check(is_file(migration_opts()['media-dir'] . '/' . $m['stored_name']), 'fichier présent');
    },

    'Migration: galerie et images (version originale)' => function () {
        $p = migration_page('/bâtiments-scolaires/photos-extension-2017/');
        check($p['title'] === "Photos de l'extension 2017", $p['title']);
        check(migration_types($p) === ['gallery', 'image', 'image', 'text'], implode(',', migration_types($p)));
        $g = $p['b'][0]['data'];
        check(count($g['items']) === 4, 'galerie de 4 images');
        check($g['items'][0]['caption'] === 'Façade sud' && $g['items'][1]['caption'] === '', 'légendes');
        $m = Db::one('SELECT * FROM media WHERE id = ?', [$g['items'][1]['media_id']]);
        check($m['legacy_url'] === 'https://image.jimcdn.com/app/cms/image/path/s2c49c8dc469ab9ea/image/ic1077141a2412f8a/version/1486720866/image.jpg', $m['legacy_url']);
        check($m['kind'] === 'image' && $m['mime'] === 'image/png', 'image');
        $img = $p['b'][1]['data'];
        check($img['caption'] === 'Inauguration du 11 février 2017' && $img['alt'] === 'Inauguration' && $img['width'] === 'half', 'image avec légende');
        check($p['b'][2]['data']['width'] === 'half' && str_contains($p['b'][3]['data']['html'], 'bibliothèque'), 'texte avec image');
    },

    'Migration: hiérarchie et ordre du menu' => function () {
        $home = migration_page('/');
        check($home['path'] === '/' && $home['slug'] === '' && $home['parent_id'] === null, 'accueil');
        $pres = migration_page('/présentation/');
        $ci = migration_page('/présentation/le-conseil-intercommunal/');
        $prv = migration_page('/présentation/le-conseil-intercommunal/préavis-2026/');
        check($pres['parent_id'] === null, 'pages de 1er niveau sans parent');
        check((int) $ci['parent_id'] === (int) $pres['id'], 'parent du Conseil');
        check((int) $prv['parent_id'] === (int) $ci['id'], 'parent des Préavis');
        check((int) migration_page('/bâtiments-scolaires/photos-extension-2017/')['parent_id'] === (int) migration_page('/bâtiments-scolaires/')['id']);
        // ordre Jimdo: Accueil, Présentation, Bâtiments, Transports, Contact
        check((int) $home['sort_order'] < (int) $pres['sort_order'], 'ordre accueil/présentation');
        check((int) $pres['sort_order'] < (int) migration_page('/bâtiments-scolaires/')['sort_order']);
        check((int) migration_page('/transports-scolaires/')['sort_order'] < (int) migration_page('/contact/')['sort_order']);
        check((int) $prv['sort_order'] === 1, 'Préavis 2026 en 2e position sous le Conseil');
    },

    'Migration: pages spéciales (mentions légales, contact, zone protégée)' => function () {
        $l = migration_page('/about/');
        check($l['path'] === '/mentions-legales' && $l['status'] === 'draft' && (int) $l['show_in_menu'] === 0, 'mentions légales');
        check(str_contains($l['blocks'], 'nLPD'), 'modèle nLPD');
        $c = migration_page('/contact/');
        check(in_array('contact', migration_types($c), true), 'bloc contact');
        check(!str_contains($c['blocks'], '<input'), 'formulaire Jimdo retiré');
        check(Db::value('SELECT COUNT(*) FROM pages WHERE legacy_url LIKE ?', ['%documents-destin%']) == 0, 'page protégée non importée');
        $f = Db::one('SELECT * FROM private_folders WHERE name = ?', ['Documents destinés aux membres']);
        check($f !== null, 'dossier privé créé');
        $g = Db::value('SELECT g.slug FROM private_folder_groups fg JOIN user_groups g ON g.id = fg.group_id WHERE fg.folder_id = ?', [$f['id']]);
        check($g === 'conseil', 'dossier autorisé au groupe conseil');
        check(str_contains(migration_first()->stats['report'], 'Documents destinés aux membres'), 'signalé dans le rapport');
    },

    'Migration: bloc Informations Transports importé une seule fois' => function () {
        $t = migration_page('/transports-scolaires/');
        $types = migration_types($t);
        check($types === ['text', 'image', 'files', 'separator', 'callout', 'image', 'files', 'image'], implode(',', $types));
        check($t['b'][4]['data']['title'] === 'Informations Transports', 'titre encadré');
        check(str_contains($t['b'][4]['data']['html'], 'href="/transports-scolaires/horaires-des-transports"'), 'lien horaires réécrit');
        check($t['b'][6]['data']['items'][0]['label'] === '2023_PEDIBUS AFFICHE', 'affiche PEDIBUS');
        $n = (int) Db::value("SELECT COUNT(*) FROM pages WHERE blocks LIKE '%PEDIBUS%'");
        check($n === 1, "PEDIBUS présent sur $n page(s)");
        check(!str_contains(migration_page('/présentation/')['blocks'], 'Informations Transports'), 'absent des autres pages');
        $imp = migration_first();
        check(count($imp->videos) === 1 && str_contains($imp->videos[0]['url'], 'Film+des+travaux') && !str_contains($imp->videos[0]['url'], '?t='), 'vidéo listée');
        check(str_contains($imp->stats['report'], 'Film des travaux'), 'vidéo dans le rapport');
        check(Db::value("SELECT COUNT(*) FROM media WHERE legacy_url LIKE '%.mp4'") == 0, 'vidéo non téléchargée');
    },

    'Migration: logo enregistré' => function () {
        migration_first();
        $id = (int) App\Settings::get('logo_media_id');
        $m = Db::one('SELECT * FROM media WHERE id = ?', [$id]);
        check($m !== null && str_contains($m['legacy_url'], 'ie9fe4872af78a219') && !str_contains($m['legacy_url'], 'transf'), 'média logo');
        check(is_file(migration_opts()['logo-path']), '--write-logo copie le fichier');
    },

    'Migration: idempotence (2e passage identique)' => function () {
        $first = migration_first();
        check($first->errors === [], 'erreurs: ' . implode(' / ', $first->errors));
        check($first->stats['pages_created'] === 9, 'pages créées: ' . $first->stats['pages_created']);
        $before = migration_counts();
        $blocks = Db::all('SELECT legacy_url, blocks FROM pages WHERE legacy_url IS NOT NULL ORDER BY id');
        $second = migration_import();
        check(migration_counts() === $before, 'mêmes nombres: ' . json_encode([$before, migration_counts()]));
        check($second->stats['pages_created'] === 0 && $second->stats['pages_updated'] === 0 && $second->stats['pages_unchanged'] === 9, json_encode($second->stats));
        check($second->stats['media_created'] === 0, 'aucun nouveau média');
        check(Db::all('SELECT legacy_url, blocks FROM pages WHERE legacy_url IS NOT NULL ORDER BY id') === $blocks, 'blocs identiques');
    },

    'Migration: page modifiée dans l’administration conservée, --dry-run sans écriture' => function () {
        migration_first();
        $p = migration_page('/contact/');
        Db::update('pages', ['title' => 'Nous écrire', 'updated_by' => 1], 'id = :id', ['id' => $p['id']]);
        $before = migration_counts();
        $imp = migration_import(['only' => '/contact/']);
        check(migration_page('/contact/')['title'] === 'Nous écrire', 'modification conservée');
        check($imp->stats['pages_skipped'] === 1, 'page signalée');
        Db::run('DELETE FROM pages WHERE legacy_url = ?', ['/transports-scolaires/']);
        $dry = migration_import(['dry-run' => true, 'only' => '/transports-scolaires/', 'report' => '']);
        check($dry->stats['pages_created'] === 1, 'simulation compte la page');
        check(Db::value('SELECT COUNT(*) FROM pages WHERE legacy_url = ?', ['/transports-scolaires/']) == 0, 'rien écrit en simulation');
        migration_import(['only' => '/transports-scolaires/']);
        check(migration_counts() === $before, 'réimport partiel: mêmes nombres');
        check(migration_page('/transports-scolaires/')['parent_id'] === null);
    },
];
