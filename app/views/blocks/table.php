<?php
declare(strict_types=1);
/** @var array $b @var array $data */
// Cellules en texte brut: échappées, retours à la ligne et liens (e-mail, web, téléphone) ajoutés ici.
$rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
$rows = array_values(array_filter($rows, fn($r) => is_array($r) && implode('', array_map('strval', $r)) !== ''));
if (!$rows) {
    return;
}
$head = ($data['head'] ?? true) !== false && count($rows) > 1;
$cell = static function (mixed $v): string {
    $h = e(trim((string) $v));
    $h = preg_replace_callback('~\b(https?://[^\s<]+[^\s<.,;:!?)])|\b([\w.+-]+@[\w-]+(?:\.[\w-]+)+)\b~u', function ($m) {
        if (($m[1] ?? '') !== '') {
            return '<a href="' . $m[1] . '" target="_blank" rel="noopener noreferrer">' . preg_replace('~^https?://(www\.)?~', '', $m[1]) . '</a>';
        }
        return '<a href="mailto:' . $m[2] . '">' . $m[2] . '</a>';
    }, $h) ?? $h;
    return str_replace(["\r\n", "\n"], '<br>', $h);
};
?>
<div class="block block-table prose">
  <div class="table-wrap">
    <table>
      <?php if ($head): ?><thead><tr><?php foreach ($rows[0] as $c): ?><th scope="col"><?= $cell($c) ?></th><?php endforeach; ?></tr></thead><?php endif; ?>
      <tbody>
        <?php foreach (array_slice($rows, $head ? 1 : 0) as $r): ?>
        <tr><?php foreach ($r as $c): ?><td><?= $cell($c) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
