<?php
declare(strict_types=1);

use App\Db;
use App\Pages;
use App\Controllers\AdminMediaController;

/** Crée une page de test et retourne son id. */
function admin_test_page(string $title, ?int $parent = null, string $blocks = '[]'): int
{
    $slug = Pages::uniqueSlug($parent, Pages::slugify($title));
    return Db::insert('pages', [
        'parent_id' => $parent,
        'slug' => $slug,
        'path' => Pages::computePath($parent, $slug),
        'title' => $title,
        'blocks' => $blocks,
        'status' => 'published',
        'show_in_menu' => 1,
        'sort_order' => 0,
        'updated_at' => Db::now(),
    ]);
}

function admin_test_media(string $kind = 'image', string $mime = 'image/jpeg'): int
{
    return Db::insert('media', [
        'stored_name' => bin2hex(random_bytes(8)) . '.bin',
        'original_name' => 'test.' . ($kind === 'image' ? 'jpg' : 'pdf'),
        'mime' => $mime,
        'size' => 10,
        'kind' => $kind,
        'created_at' => Db::now(),
    ]);
}

return [
    'Pages::slugify translittère les accents français' => function () {
        check(Pages::slugify('Bâtiments scolaires') === 'batiments-scolaires', Pages::slugify('Bâtiments scolaires'));
        check(Pages::slugify('Réfectoire & repas') === 'refectoire-et-repas', Pages::slugify('Réfectoire & repas'));
        check(Pages::slugify("L'œuvre – Collège des Gollies (Servion)") === 'l-oeuvre-college-des-gollies-servion');
        check(Pages::slugify('  ÉCOLE Ça Où  ') === 'ecole-ca-ou');
        check(Pages::slugify('!!!') === '');
    },
    'Pages::computePath et unicité du chemin' => function () {
        $a = admin_test_page('Présentation T1');
        check(Db::value('SELECT path FROM pages WHERE id = ?', [$a]) === '/presentation-t1');
        check(Pages::computePath($a, 'statuts') === '/presentation-t1/statuts');
        check(Pages::computePath(null, '') === '/', 'accueil');
        $b = admin_test_page('Présentation T1');
        check(Db::value('SELECT path FROM pages WHERE id = ?', [$b]) === '/presentation-t1-2', 'suffixe -2');
    },
    'Pages::applyOrder déplace, recalcule les chemins et refuse les cycles' => function () {
        $p = admin_test_page('Parent T2');
        $c = admin_test_page('Enfant T2', $p);
        $g = admin_test_page('Petit T2', $c);
        $other = admin_test_page('Autre T2');
        check(Db::value('SELECT path FROM pages WHERE id = ?', [$g]) === '/parent-t2/enfant-t2/petit-t2');
        // l'enfant passe sous « Autre »: les descendants suivent
        Pages::applyOrder([['id' => $c, 'parent_id' => $other, 'sort_order' => 1]]);
        check(Db::value('SELECT path FROM pages WHERE id = ?', [$c]) === '/autre-t2/enfant-t2');
        check(Db::value('SELECT path FROM pages WHERE id = ?', [$g]) === '/autre-t2/enfant-t2/petit-t2');
        $failed = false;
        try {
            Pages::applyOrder([['id' => $other, 'parent_id' => $g, 'sort_order' => 1]]);
        } catch (InvalidArgumentException) {
            $failed = true;
        }
        check($failed, 'cycle accepté');
        check(Db::value('SELECT parent_id FROM pages WHERE id = ?', [$other]) === null, 'cycle appliqué');
        check(Pages::wouldCycle($p, $p) && !Pages::wouldCycle($g, $p));
    },
    'Pages::move et refreshDescendantPaths après changement de slug' => function () {
        $p = admin_test_page('Parent T3');
        $c = admin_test_page('Enfant T3', $p);
        Db::update('pages', ['slug' => 'nouveau-t3', 'path' => '/nouveau-t3'], 'id = :id', ['id' => $p]);
        Pages::refreshDescendantPaths($p);
        check(Db::value('SELECT path FROM pages WHERE id = ?', [$c]) === '/nouveau-t3/enfant-t3');
        $q = admin_test_page('Cible T3');
        Pages::move($c, $q, 3);
        check(Db::value('SELECT path FROM pages WHERE id = ?', [$c]) === '/cible-t3/enfant-t3');
    },
    'Pages::tree imbrique les sous-pages' => function () {
        $p = admin_test_page('Racine T4');
        admin_test_page('Fille T4', $p);
        $found = null;
        foreach (Pages::tree() as $n) {
            if ((int) $n['id'] === $p) {
                $found = $n;
            }
        }
        check($found !== null && count($found['children']) === 1 && $found['children'][0]['depth'] === 1);
        $flat = array_column(Pages::flat($p), 'id');
        check(!in_array($p, array_map('intval', $flat), true), 'flat exclut la page');
    },
    'Pages::sanitizeBlocks valide types, HTML, URLs, médias et ids' => function () {
        $img = admin_test_media('image');
        $pdf = admin_test_media('file', 'application/pdf');
        $out = Pages::sanitizeBlocks([
            ['id' => 'b_ok123', 'type' => 'text', 'data' => ['html' => '<p onclick="x">Salut<script>alert(1)</script></p>']],
            ['id' => 'b_ok123', 'type' => 'heading', 'data' => ['text' => '<b>Titre</b>', 'level' => 7]],
            ['id' => '"><script>', 'type' => 'button', 'data' => ['label' => 'Go', 'url' => 'javascript:alert(1)']],
            ['type' => 'inconnu', 'data' => []],
            ['type' => 'gallery', 'data' => ['items' => [['media_id' => $img, 'caption' => 'ok'], ['media_id' => 999999], ['media_id' => $pdf]]]],
            ['type' => 'files', 'data' => ['items' => [['media_id' => (string) $pdf, 'label' => 'PV']], 'sort' => 'bizarre']],
            ['type' => 'image', 'data' => ['media_id' => $pdf, 'width' => 'half']],
            ['type' => 'video', 'data' => ['url' => 'http://youtube.com/watch?v=abc']],
            ['type' => 'callout', 'data' => ['style' => 'warning', 'html' => '<a href="https://vd.ch">vd</a>']],
            'pas un tableau',
        ]);
        check(count($out) === 8, 'nombre de blocs: ' . count($out));
        check($out[0]['id'] === 'b_ok123');
        check(!str_contains($out[0]['data']['html'], 'script') && !str_contains($out[0]['data']['html'], 'onclick'));
        check($out[1]['id'] !== 'b_ok123' && str_starts_with($out[1]['id'], 'b_'), 'id dupliqué régénéré');
        check($out[1]['data'] === ['text' => 'Titre', 'level' => 2]);
        check(preg_match('/^b_[a-z0-9]+$/', $out[2]['id']) === 1, 'id invalide régénéré');
        check($out[2]['data']['url'] === '', 'javascript: refusé');
        check($out[3]['data']['items'] === [['media_id' => $img, 'caption' => 'ok']], 'galerie filtrée');
        check($out[4]['data']['items'][0]['media_id'] === $pdf && $out[4]['data']['sort'] === 'manual');
        check($out[5]['data']['media_id'] === null && $out[5]['data']['width'] === 'half', 'pdf refusé comme image');
        check($out[6]['data']['url'] === '', 'vidéo http refusée');
        check($out[7]['data']['style'] === 'warning' && str_contains($out[7]['data']['html'], 'https://vd.ch'));
        check(Pages::sanitizeBlocks('pas du json') === []);
    },
    'Pages::usingMedia trouve les pages qui utilisent un média' => function () {
        $m = admin_test_media('image');
        $blocks = json_encode([['id' => 'b_x1y2z3', 'type' => 'image', 'data' => ['media_id' => $m, 'alt' => '']]]);
        $p = admin_test_page('Utilise T5', null, $blocks);
        $m2 = admin_test_media('image');
        $used = Pages::usingMedia($m);
        check(count($used) === 1 && $used[0]['id'] === $p);
        check(Pages::usingMedia($m2) === []);
    },
    'AdminMediaController::store vérifie le type réel et réduit les grandes images' => function () {
        if (!function_exists('imagecreatetruecolor')) {
            return;
        }
        $dir = sys_get_temp_dir() . '/asij-admintest-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $jpg = $dir . '/grande.jpg';
        $im = imagecreatetruecolor(3000, 1500);
        imagejpeg($im, $jpg, 70);
        $row = AdminMediaController::store(['name' => 'Grande photo.jpg', 'tmp_name' => $jpg, 'error' => UPLOAD_ERR_OK, 'size' => filesize($jpg)], false);
        $file = AdminMediaController::dir() . '/' . $row['stored_name'];
        try {
            check($row['kind'] === 'image' && $row['mime'] === 'image/jpeg');
            check(preg_match('/^[0-9a-f]{32}\.jpg$/', $row['stored_name']) === 1, 'nom aléatoire');
            [$w, $h] = getimagesize($file);
            check($w === 2400 && $h === 1200, "redimensionnée: {$w}x{$h}");
            $fake = $dir . '/faux.pdf';
            file_put_contents($fake, '<html><script>alert(1)</script></html>');
            $err = null;
            try {
                AdminMediaController::store(['name' => 'faux.pdf', 'tmp_name' => $fake, 'error' => 0, 'size' => 10], false);
            } catch (RuntimeException $e) {
                $err = $e->getMessage();
            }
            check($err !== null && str_contains($err, 'ne correspond pas'), 'faux PDF accepté');
            $php = $dir . '/x.php';
            file_put_contents($php, '<?php echo 1;');
            $err = null;
            try {
                AdminMediaController::store(['name' => 'x.php', 'tmp_name' => $php, 'error' => 0, 'size' => 10], false);
            } catch (RuntimeException $e) {
                $err = $e->getMessage();
            }
            check($err !== null, '.php accepté');
        } finally {
            @unlink($file);
            array_map('unlink', glob($dir . '/*'));
            rmdir($dir);
        }
    },
];
