<?php
/** Messages du formulaire de contact. Variables: $rows, $total, $page, $pages */
?>
<div class="page-head">
  <h1>Messages reçus</h1>
  <p class="lead"><?= (int) $total ?> message<?= $total > 1 ? 's' : '' ?> envoyé<?= $total > 1 ? 's' : '' ?> depuis le formulaire de contact du site. Pour répondre, cliquez sur l’adresse e-mail.</p>
</div>
<?php if (!$rows): ?>
  <div class="empty card"><p>Aucun message pour le moment.</p></div>
<?php else: ?>
<div class="messages">
  <?php foreach ($rows as $m): ?>
  <article class="card message">
    <header class="message-head">
      <h2 class="h3"><?= e($m['name']) ?></h2>
      <a href="mailto:<?= e($m['email']) ?>?subject=<?= e(rawurlencode('Re: votre message à l’ASIJ')) ?>"><?= e($m['email']) ?></a>
      <span class="muted small"><time datetime="<?= e($m['created_at']) ?>Z"><?= e(date_fr($m['created_at'], true)) ?></time></span>
    </header>
    <div class="message-body"><?= App\Html::nl((string) $m['message']) ?></div>
  </article>
  <?php endforeach; ?>
</div>
<?= App\View::partial('admin/_pagination', ['page' => $page, 'pages' => $pages, 'base' => '/admin/messages', 'params' => []]) ?>
<?php endif; ?>
