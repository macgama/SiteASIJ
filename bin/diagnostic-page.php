<?php
declare(strict_types=1);
/**
 * Diagnostic d'une page importée: blocs enregistrés, médias liés, modules Jimdo du cache.
 * Usage : php bin/diagnostic-page.php /cet
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Db;

$path = $argv[1] ?? '/';
$p = Db::one('SELECT id, path, title, menu_title, legacy_url, status, updated_by, updated_at, blocks FROM pages WHERE path = ?', [$path]);
if (!$p) {
    exit("Page $path introuvable.\n");
}
printf("Page #%d %s « %s » (menu: %s) legacy=%s statut=%s modifiée par=%s le %s\n", $p['id'], $p['path'], $p['title'],
    $p['menu_title'] ?? '-', $p['legacy_url'] ?? '-', $p['status'], $p['updated_by'] ?? 'import', $p['updated_at']);
echo 'Version du schéma : ', App\Settings::get('schema_version'), "\n\nBlocs :\n";
foreach ((array) json_decode((string) $p['blocks'], true) as $i => $b) {
    $d = $b['data'] ?? [];
    $info = $d['text'] ?? ($d['title'] ?? '');
    if (isset($d['html'])) {
        $info = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($d['html'])) ?? ''), 0, 70);
    }
    if (isset($d['items']) && is_array($d['items'])) {
        $ids = array_map(fn($it) => (int) ($it['media_id'] ?? 0), $d['items']);
        $found = $ids ? (int) Db::value('SELECT COUNT(*) FROM media WHERE id IN (' . implode(',', $ids) . ')') : 0;
        $info .= sprintf(' [%d élément(s), %d média(s) trouvés]', count($ids), $found);
    }
    printf("  %2d. %-9s %s\n", $i, $b['type'] ?? '?', $info);
}
if ($p['legacy_url']) {
    $url = 'https://www.asij.ch' . implode('/', array_map('rawurlencode', explode('/', (string) $p['legacy_url'])));
    $file = ROOT . '/migration/downloads/pages/' . sha1($url) . '.bin';
    echo "\nCache Jimdo : ", is_file($file) ? $file : "absent ($url)", "\n";
    if (is_file($file)) {
        $html = (string) file_get_contents($file);
        preg_match_all('/class="j-module n (j-[A-Za-z]+)/', $html, $m);
        foreach (array_count_values($m[1]) as $k => $n) {
            echo "  $k : $n\n";
        }
        echo '  liens /app/download/ : ', substr_count($html, '/app/download/'), "\n";
        echo '  taille : ', strlen($html), " octets\n";
    }
}
