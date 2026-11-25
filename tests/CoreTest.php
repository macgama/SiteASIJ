<?php
use App\Html;
use App\Db;

return [
    'Html::clean retire les scripts et attributs dangereux' => function () {
        $out = Html::clean('<p onclick="x">a<script>bad()</script><a href="javascript:alert(1)">l</a></p>');
        check(!str_contains($out, 'script'), 'script présent');
        check(!str_contains($out, 'onclick'), 'onclick présent');
        check(!str_contains($out, 'javascript:'), 'javascript: présent');
    },
    'Html::clean garde le formatage autorisé' => function () {
        $out = Html::clean('<p><strong>gras</strong> <a href="https://x.ch">lien</a></p>');
        check(str_contains($out, '<strong>gras</strong>'));
        check(str_contains($out, 'href="https://x.ch"'));
    },
    'Schéma: insertion utilisateur' => function () {
        $id = Db::insert('users', ['email' => 'a@b.ch', 'name' => 'A', 'password_hash' => 'x', 'role' => 'member', 'is_active' => 1, 'created_at' => Db::now()]);
        check($id > 0);
        check(Db::value('SELECT email FROM users WHERE id = ?', [$id]) === 'a@b.ch');
    },
    'Migrations: actualités et contact de l’accueil déplacé dans le pied de page' => function () {
        $blocks = [
            ['id' => 'a', 'type' => 'text', 'data' => ['html' => '<p>x</p>']],
            ['id' => 'b', 'type' => 'heading', 'data' => ['text' => 'Contact', 'level' => 2]],
            ['id' => 'c', 'type' => 'people', 'data' => ['items' => [['name' => 'N. Test', 'role' => 'Directeur', 'commune' => '']]]],
            ['id' => 'd', 'type' => 'button', 'data' => ['label' => 'Nous écrire', 'url' => '/contact']],
        ];
        Db::insert('pages', ['parent_id' => null, 'slug' => '', 'path' => '/', 'title' => 'Travaux routiers', 'blocks' => json_encode($blocks),
            'status' => 'published', 'show_in_menu' => 0, 'sort_order' => 0, 'updated_at' => Db::now(), 'updated_by' => null]);
        App\Settings::set('schema_version', '0');
        App\Migrations::run();
        App\Migrations::run(); // idempotent
        check(App\Settings::get('schema_version') === (string) App\Migrations::VERSION, 'version');
        check(App\Settings::get('contact_name') === 'N. Test' && App\Settings::get('contact_role') === 'Directeur', 'contact copié');
        $home = Db::one("SELECT * FROM pages WHERE path = '/'");
        check(count(json_decode($home['blocks'], true)) === 1, 'bloc contact retiré: ' . $home['blocks']);
        check(str_starts_with($home['title'], 'Bienvenue'), 'titre accueil');
        check((int) Db::value('SELECT COUNT(*) FROM page_revisions WHERE page_id = ?', [(int) $home['id']]) === 1, 'version gardée');
        $news = Db::one('SELECT * FROM pages WHERE path = ?', [App\Pages::NEWS_PATH]);
        check($news !== null && $news['status'] === 'published', 'rubrique Actualités');
        Db::insert('pages', ['parent_id' => (int) $news['id'], 'slug' => 'a', 'path' => '/actualites/a', 'title' => 'Ancienne', 'blocks' => '[]',
            'status' => 'published', 'show_in_menu' => 0, 'sort_order' => 0, 'news_date' => '2026-01-05', 'updated_at' => Db::now()]);
        Db::insert('pages', ['parent_id' => (int) $news['id'], 'slug' => 'b', 'path' => '/actualites/b', 'title' => 'Récente', 'blocks' => '[]',
            'status' => 'published', 'show_in_menu' => 0, 'sort_order' => 1, 'news_date' => '2026-11-01', 'updated_at' => Db::now()]);
        $l = App\Controllers\PageController::latestNews(5);
        check(count($l) === 2 && $l[0]['title'] === 'Récente', 'ordre des actualités');
        check(App\Controllers\PageController::newsDate($l[0]) === '1er novembre 2026', App\Controllers\PageController::newsDate($l[0]));
        check(App\Controllers\PageController::rubriqueIcon('/transports-scolaires/horaires') === 'bus');
        // nettoyage: les autres tests partent d'une base sans pages
        Db::run('DELETE FROM page_revisions');
        Db::run('DELETE FROM pages');
        Db::run("DELETE FROM settings WHERE name IN ('schema_version', 'contact_name', 'contact_role')");
    },
    'Migrations: accueil Jimdo (contact en texte, nouvelles en sections)' => function () {
        $blocks = [
            ['id' => 'a', 'type' => 'heading', 'data' => ['text' => 'Bienvenue sur le site de l’ASIJ !', 'level' => 3]],
            ['id' => 'b', 'type' => 'text', 'data' => ['html' => '<p>Texte d’accueil</p>']],
            ['id' => 'c', 'type' => 'text', 'data' => ['html' => '<h3><strong>Contacter l’ASIJ :</strong></h3><p>Nicolas Deprez<br>Directeur des Opérations<br>Chemin du Raffort 11<br>1083 Mézières<br><a href="mailto:x.test@example.ch">x.test@example.ch</a><br>www.asij.ch<br>079 592 20 96<br>021&nbsp;557 35 08</p>']],
            ['id' => 'd', 'type' => 'separator', 'data' => []],
            ['id' => 'e', 'type' => 'heading', 'data' => ['text' => 'Travaux routiers', 'level' => 2]],
            ['id' => 'f', 'type' => 'heading', 'data' => ['text' => 'Veuillez vous renseigner sur les sites des communes', 'level' => 3]],
            ['id' => 'g', 'type' => 'separator', 'data' => []],
            ['id' => 'h', 'type' => 'heading', 'data' => ['text' => 'L’ASIJ met au concours le poste de Secrétaire', 'level' => 2]],
            ['id' => 'i', 'type' => 'text', 'data' => ['html' => '<p>Postulez jusqu’au 2 octobre.</p>']],
        ];
        Db::insert('pages', ['parent_id' => null, 'slug' => '', 'path' => '/', 'title' => 'Bienvenue sur le site de l’ASIJ', 'blocks' => json_encode($blocks),
            'status' => 'published', 'show_in_menu' => 0, 'sort_order' => 0, 'updated_at' => Db::now(), 'updated_by' => null]);
        App\Settings::set('schema_version', '0');
        App\Migrations::run();
        App\Migrations::run();
        check(App\Settings::get('contact_name') === 'Nicolas Deprez', 'nom: ' . App\Settings::get('contact_name'));
        check(App\Settings::get('contact_role') === 'Directeur des Opérations', 'fonction');
        check(App\Settings::get('contact_email') === 'x.test@example.ch', 'email');
        check(App\Settings::get('contact_phone') === '079 592 20 96 / 021 557 35 08', 'tél: ' . App\Settings::get('contact_phone'));
        $home = json_decode((string) Db::value("SELECT blocks FROM pages WHERE path = '/'"), true);
        check(array_column($home, 'id') === ['b'], 'accueil: ' . json_encode(array_column($home, 'id')));
        $l = App\Controllers\PageController::latestNews(5);
        check(count($l) === 2 && $l[0]['title'] === 'Travaux routiers', 'actualités: ' . json_encode(array_column($l, 'title')));
        check($l[0]['meta_description'] === 'Veuillez vous renseigner sur les sites des communes', 'résumé');
        check(Db::value('SELECT id FROM pages WHERE path = ?', [App\Pages::INFOS_PATH]) !== null, 'encadré informations importantes');
        check(App\Html::tidy('<p>&nbsp;</p><p>a<br><br><br>b<br></p><p> <br></p>') === '<p>a<br>b</p>', 'grands vides retirés');
        check(str_contains(App\Html::cfDecode('<a href="/cdn-cgi/l/email-protection#4221266c23312b2802252f232b2e6c212d2f">[email&#160;protected]</a>'), 'mailto:cd.asij@gmail.com">cd.asij@gmail.com<'), 'e-mail Cloudflare décodé');
        Db::run('DELETE FROM page_revisions');
        Db::run('DELETE FROM pages');
        Db::run("DELETE FROM settings WHERE name IN ('schema_version', 'contact_name', 'contact_role', 'contact_email', 'contact_phone')");
    },
    'Migrations: tableaux des blocs Texte en blocs Tableau' => function () {
        $html = '<p>Intro</p><table><tbody><tr><td><p><strong>COMMUNE</strong></p></td><td><p><strong>MEMBRE</strong></p></td><td></td></tr>'
            . '<tr><td>1510 SYENS</td><td>FISCHER Laetitia</td><td>&nbsp;</td></tr><tr><td>1085 VULLIENS</td><td>MANIGLEY Gaël<br>Vice-Président</td><td></td></tr>'
            . '<tr><td><p><strong>BUREAU</strong></p><p>BLANC Fabienne</p></td><td></td><td></td></tr></tbody></table>';
        $parts = App\Migrations::splitTables($html);
        check(array_column($parts, 'type') === ['text', 'table', 'text'], json_encode(array_column($parts, 'type')));
        check($parts[1]['data']['head'] === true && count($parts[1]['data']['rows']) === 3 && count($parts[1]['data']['rows'][0]) === 2, 'tableau nettoyé');
        check($parts[1]['data']['rows'][2][1] === "MANIGLEY Gaël\nVice-Président", 'retour à la ligne');
        check(str_contains($parts[2]['data']['html'], 'BUREAU'), 'bureau sorti du tableau');
        $out = App\Blocks::render([['id' => 't', 'type' => 'table', 'data' => $parts[1]['data'] + ['x' => 1]]]);
        check(str_contains($out, '<th scope="col">COMMUNE</th>') && str_contains($out, 'MANIGLEY Gaël<br>Vice-Président'), 'rendu tableau');
        $clean = App\Pages::sanitizeBlocks([['type' => 'table', 'data' => ['head' => false, 'rows' => [['<b>a</b>', 'b', 'c'], ['d']]]]]);
        check($clean[0]['data'] === ['head' => false, 'rows' => [['a', 'b', 'c'], ['d', '', '']]], json_encode($clean[0]['data']));
    },
    'Migrations: listes de membres en blocs Personnes' => function () {
        $p = App\Migrations::parsePerson('Mme Sandra Favre, Présidente,   <em>Conseil Intercommunal de l’ASIJ</em>', '');
        check($p === ['name' => 'Mme Sandra Favre', 'role' => 'Présidente', 'commune' => 'Conseil Intercommunal de l’ASIJ'], json_encode($p, JSON_UNESCAPED_UNICODE));
        check(App\Migrations::parsePerson('Vous pouvez écrire à parents@example.ch', '') === null, 'pas une personne');
        $out = App\Migrations::peopleBlocks([['type' => 'text', 'data' => ['html' => '<p><strong>Pour le 1/4 Parents</strong></p><ul><li>Mme A. Exemple</li><li>M. B. Exemple</li></ul>']],
            ['type' => 'table', 'data' => ['head' => true, 'rows' => [['COMMUNE', 'MEMBRE DU COMITÉ'], ['1510 SYENS', 'FISCHER Laetitia'], ['1082 CORCELLES-LE-JORAT', 'BALISSAT Christophe, Président']]]]]);
        check(array_column($out, 'type') === ['heading', 'people', 'people'], json_encode(array_column($out, 'type')));
        check($out[2]['data']['items'][1] === ['name' => 'BALISSAT Christophe', 'role' => 'Président', 'commune' => 'Corcelles-le-Jorat'], json_encode($out[2]['data']['items'][1], JSON_UNESCAPED_UNICODE));
        $g = App\Migrations::groupYearArchives([['type' => 'text', 'data' => ['html' => '<p>Résumé des séances 2021</p>']], ['type' => 'files', 'data' => ['items' => [['media_id' => 1]]]],
            ['type' => 'heading', 'data' => ['text' => 'Résumé des séances 2020']], ['type' => 'files', 'data' => ['items' => [['media_id' => 2]]]]], 'Archives');
        check($g !== null && $g[1]['data']['title'] === 'Séances 2021' && $g[2]['data']['title'] === 'Séances 2020', 'année en texte');
    },
];
