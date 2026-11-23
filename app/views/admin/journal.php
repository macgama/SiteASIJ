<?php
/** Journal d'activité. Variables: $rows, $total, $page, $pages, $action, $actions */
use App\Controllers\AdminController;
?>
<div class="page-head">
  <h1>Journal d’activité</h1>
  <p class="lead">Toutes les actions importantes (connexions, modifications, téléchargements confidentiels) sont enregistrées ici.</p>
</div>
<form class="toolbar" method="get" action="/admin/journal">
  <label for="j-action">Type d’action</label>
  <select id="j-action" name="action" onchange="this.form.submit()">
    <option value="">Toutes les actions</option>
    <?php foreach ($actions as $a): ?>
    <option value="<?= e($a) ?>"<?= $a === $action ? ' selected' : '' ?>><?= e(AdminController::actionLabel($a)) ?></option>
    <?php endforeach; ?>
  </select>
  <noscript><button class="btn" type="submit">Filtrer</button></noscript>
  <span class="muted"><?= (int) $total ?> entrée<?= $total > 1 ? 's' : '' ?></span>
</form>
<?php if (!$rows): ?>
  <div class="empty card"><p>Aucune activité enregistrée.</p></div>
<?php else: ?>
<div class="table-wrap">
<table class="table">
  <thead><tr><th scope="col">Date</th><th scope="col">Personne</th><th scope="col">Action</th><th scope="col">Détail</th><th scope="col">Adresse IP</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="nowrap"><?= e(date_fr($r['created_at'], true)) ?></td>
      <td><?= $r['user_name'] ? e($r['user_name']) : '<span class="muted">—</span>' ?></td>
      <td><?= e(AdminController::actionLabel((string) $r['action'])) ?></td>
      <td class="break"><?= e((string) $r['target']) ?></td>
      <td class="muted small"><?= e((string) $r['ip']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?= App\View::partial('admin/_pagination', ['page' => $page, 'pages' => $pages, 'base' => '/admin/journal', 'params' => ['action' => $action]]) ?>
<?php endif; ?>
