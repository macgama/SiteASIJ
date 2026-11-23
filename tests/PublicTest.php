<?php
use App\Blocks;
use App\Db;
use App\Media;
use App\Controllers\PageController;
use App\Controllers\ContactController;

$_SESSION = $_SESSION ?? [];

$mkMedia = function (string $orig, string $mime, int $size = 1000): int {
    return Db::insert('media', [
        'stored_name' => 'pt-' . bin2hex(random_bytes(6)), 'original_name' => $orig, 'title' => null,
        'mime' => $mime, 'size' => $size, 'kind' => str_starts_with($mime, 'image/') ? 'image' : 'file',
        'alt' => null, 'created_at' => Db::now(),
    ]);
};
$mkPage = function (?int $parent, string $path, string $title, array $extra = []): int {
    return Db::insert('pages', $extra + [
        'parent_id' => $parent, 'slug' => basename($path), 'path' => $path, 'title' => $title, 'blocks' => '[]',
        'status' => 'published', 'show_in_menu' => 1, 'sort_order' => 0, 'updated_at' => Db::now(),
    ]);
};

return [
    'Blocks: types inconnus et données mal formées ignorés sans erreur' => function () {
        $html = Blocks::render([
            'texte', null, ['type' => 'inconnu'], ['type' => '../layout'], ['type' => 'text'],
            ['type' => 'image', 'data' => ['media_id' => 999999]], ['type' => 'gallery', 'data' => ['items' => 'x']],
            ['type' => 'files', 'data' => null], ['type' => 'people', 'data' => ['items' => [['role' => 'sans nom']]]],
            ['type' => 'heading', 'data' => ['text' => ['tableau']]],
        ]);
        check(trim($html) === '', 'sortie inattendue: ' . $html);
    },
    'Blocks: titre échappé, niveau borné' => function () {
        $html = Blocks::render([['type' => 'heading', 'data' => ['text' => '<script>x</script>', 'level' => 7]]]);
        check(str_contains($html, '<h2') && str_contains($html, '&lt;script&gt;'), $html);
    },
    'Blocks: bouton refuse javascript:' => function () {
        check(trim(Blocks::render([['type' => 'button', 'data' => ['label' => 'X', 'url' => 'javascript:alert(1)']]])) === '');
        check(str_contains(Blocks::render([['type' => 'button', 'data' => ['label' => 'X', 'url' => '/contact']]]), 'href="/contact"'));
    },
    'Blocks: vidéos YouTube/Vimeo uniquement (nocookie)' => function () {
        check(Blocks::videoEmbed('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=3') === 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0');
        check(str_starts_with((string) Blocks::videoEmbed('https://youtu.be/dQw4w9WgXcQ'), 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'));
        check(str_starts_with((string) Blocks::videoEmbed('https://vimeo.com/76979871'), 'https://player.vimeo.com/video/76979871'));
        check(Blocks::videoEmbed('https://evil.example/watch?v=dQw4w9WgXcQ') === null);
        check(Blocks::videoEmbed('javascript:alert(1)') === null);
        check(Blocks::videoEmbed('https://www.youtube.com/watch?v="><x') === null);
        $html = Blocks::render([['type' => 'video', 'data' => ['url' => 'https://youtu.be/dQw4w9WgXcQ']]]);
        check(str_contains($html, '<iframe') && str_contains($html, 'title="Vidéo"'));
    },
    'Media: url lisible, find, chemin sans traversée' => function () use ($mkMedia) {
        $id = $mkMedia('Préavis 01/2026 été.PDF', 'application/pdf');
        check(Media::url($id) === "/media/$id/preavis-01-2026-ete.pdf", Media::url($id));
        check(Media::url(987654) === '');
        check(Media::find(987654) === null);
        check(Media::path(['stored_name' => '../../config.php']) === STORAGE . '/media/config.php');
        check(Media::iconType(['mime' => 'application/pdf', 'original_name' => 'a.pdf']) === 'pdf');
        check(Media::iconType(['mime' => 'application/octet-stream', 'original_name' => 'a.xlsx']) === 'sheet');
    },
    'Blocks files: tri name_desc, taille lisible, média manquant ignoré' => function () use ($mkMedia) {
        $a = $mkMedia('a.pdf', 'application/pdf', 2048);
        $b = $mkMedia('b.docx', 'application/msword', 1536000);
        $html = Blocks::render([['type' => 'files', 'data' => ['sort' => 'name_desc', 'items' => [
            ['media_id' => $a, 'label' => 'Préavis 02-2026'], ['media_id' => 424242, 'label' => 'Fantôme'],
            ['media_id' => $b, 'label' => 'Préavis 10-2026'],
        ]]]]);
        check(strpos($html, 'Préavis 10-2026') < strpos($html, 'Préavis 02-2026'), 'ordre');
        check(!str_contains($html, 'Fantôme'));
        check(str_contains($html, '2,0 Ko') && str_contains($html, '1,5 Mo'), 'tailles');
        check(str_contains($html, "/media/$a/a.pdf"));
    },
    'Blocks galerie et contact' => function () use ($mkMedia) {
        $i = $mkMedia('Photo.jpg', 'image/jpeg');
        $html = Blocks::render([['type' => 'gallery', 'data' => ['items' => [['media_id' => $i, 'caption' => 'Préau <b>']]]]]);
        check(str_contains($html, 'data-lightbox') && str_contains($html, 'Préau &lt;b&gt;'));
        $html = Blocks::render([['type' => 'contact', 'data' => ['intro' => 'Bonjour']]]);
        check(str_contains($html, 'name="_csrf"') && str_contains($html, 'name="website"') && str_contains($html, 'action="/contact/envoyer"'));
    },
    'Pages: clé d\'ancienne URL (encodage, NFD, / final, domaine)' => function () {
        $k = PageController::legacyKey('/présentation/le-conseil-intercommunal');
        check(PageController::legacyKey('https://www.asij.ch/pr%C3%A9sentation/le-conseil-intercommunal/') === $k);
        check(PageController::legacyKey('/pre%CC%81sentation/Le-Conseil-intercommunal/?x=1') === $k);
        check(PageController::normalizePath('') === '/' && PageController::normalizePath('a/b/') === '/a/b');
        check(PageController::href('/bâtiments/collège') === '/b%C3%A2timents/coll%C3%A8ge');
    },
    'Pages: redirection legacy et arbre du menu' => function () use ($mkPage) {
        $p = $mkPage(null, '/pt-rubrique', 'Rubrique', ['sort_order' => 1, 'legacy_url' => 'https://www.asij.ch/vieille-adr%C3%A9sse/']);
        $mkPage($p, '/pt-rubrique/b', 'B', ['sort_order' => 2]);
        $mkPage($p, '/pt-rubrique/a', 'A', ['sort_order' => 1]);
        $d = $mkPage($p, '/pt-rubrique/brouillon', 'Brouillon', ['status' => 'draft']);
        $mkPage($d, '/pt-rubrique/brouillon/enfant', 'Orphelin');
        $mkPage($p, '/pt-rubrique/cachee', 'Cachée', ['show_in_menu' => 0]);
        check(PageController::findLegacy('/vieille-adrésse') === '/pt-rubrique');
        check(PageController::findLegacy('/rien') === null);
        $tree = array_values(array_filter(PageController::pageTree(), fn($n) => $n['path'] === '/pt-rubrique'));
        check(count($tree) === 1);
        check(array_column($tree[0]['children'], 'title') === ['A', 'B'], json_encode($tree[0]['children']));
        $all = array_values(array_filter(PageController::pageTree(false), fn($n) => $n['path'] === '/pt-rubrique'));
        check(in_array('Cachée', array_column($all[0]['children'], 'title'), true));
        $crumbs = PageController::breadcrumbs(Db::one("SELECT * FROM pages WHERE path = '/pt-rubrique/a'"));
        check(array_column($crumbs, 'title') === ['Accueil', 'Rubrique']);
    },
    'Contact: retour uniquement vers une URL interne' => function () {
        check(ContactController::backUrl('http://localhost/contact?x=1') === '/contact?x=1');
        check(ContactController::backUrl('https://evil.com/x') === '/');
        check(ContactController::backUrl('//evil.com/x') === '/');
        check(ContactController::backUrl('javascript:alert(1)') === '/');
        check(ContactController::backUrl('') === '/');
    },
];
