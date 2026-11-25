<?php
/** Réglages. Variables: $values, $errors, $logo, $fields */
$help = [
    'site_name' => 'Affiché dans l’en-tête et l’onglet du navigateur.',
    'contact_address' => 'Une ligne par ligne d’adresse.',
    'contact_name' => 'Affichée en bas de toutes les pages avec sa fonction, le téléphone et l’e-mail ci-dessous (ex. Nicolas Deprez).',
    'contact_role' => 'Ex. Directeur des opérations.',
    'footer_text' => 'Texte libre affiché en bas de toutes les pages (horaires, remarque…).',
    'banner_text' => 'Laissez vide pour masquer le bandeau. S’il est rempli, il s’affiche en haut de toutes les pages (ex. fermeture exceptionnelle, travaux).',
];
?>
<div class="page-head">
  <h1>Réglages du site</h1>
  <p class="lead">Ces informations apparaissent sur toutes les pages du site.</p>
</div>
<?php if ($errors): ?>
<div class="flash flash-error" role="alert">Certains champs doivent être corrigés&nbsp;: voir les messages en rouge ci-dessous.</div>
<?php endif; ?>
<form method="post" action="/admin/reglages" class="card form" data-dirty-check>
  <?= csrf_field() ?>
  <?php foreach ($fields as $k => [$label, $max, $multi]): $err = $errors[$k] ?? null; ?>
  <div class="field<?= $k === 'banner_text' ? ' field-highlight' : '' ?>">
    <label for="s-<?= e($k) ?>"><?= e($label) ?></label>
    <?php if (isset($help[$k])): ?><p class="help" id="h-<?= e($k) ?>"><?= e($help[$k]) ?></p><?php endif; ?>
    <?php if ($multi): ?>
    <textarea id="s-<?= e($k) ?>" name="<?= e($k) ?>" rows="3" maxlength="<?= (int) $max ?>"<?= isset($help[$k]) ? ' aria-describedby="h-' . e($k) . '"' : '' ?><?= $err ? ' aria-invalid="true"' : '' ?>><?= e($values[$k] ?? '') ?></textarea>
    <?php else: ?>
    <input id="s-<?= e($k) ?>" name="<?= e($k) ?>" type="<?= $k === 'contact_email' ? 'email' : ($k === 'contact_phone' ? 'tel' : 'text') ?>" maxlength="<?= (int) $max ?>" value="<?= e($values[$k] ?? '') ?>"<?= isset($help[$k]) ? ' aria-describedby="h-' . e($k) . '"' : '' ?><?= $err ? ' aria-invalid="true"' : '' ?><?= $k === 'site_name' ? ' required' : '' ?>>
    <?php endif; ?>
    <?php if ($err): ?><p class="error"><?= e($err) ?></p><?php endif; ?>
  </div>
  <?php endforeach; ?>

  <div class="field">
    <span class="label" id="logo-label">Logo</span>
    <p class="help">Choisissez une image parmi les médias (format PNG ou SVG de préférence, fond transparent).</p>
    <div class="logo-picker" data-logo-picker>
      <input type="hidden" name="logo_media_id" value="<?= e($values['logo_media_id'] ?? '') ?>">
      <div class="logo-preview" data-logo-preview>
        <?php if ($logo): ?><img src="<?= e($logo['url']) ?>" alt="Logo actuel"><?php else: ?><span class="muted">Aucun logo choisi (le logo par défaut est utilisé).</span><?php endif; ?>
      </div>
      <div class="btn-row">
        <button type="button" class="btn" data-logo-choose aria-describedby="logo-label">Choisir une image</button>
        <button type="button" class="btn btn-ghost" data-logo-clear<?= $logo ? '' : ' hidden' ?>>Retirer le logo</button>
      </div>
    </div>
    <?php if (isset($errors['logo_media_id'])): ?><p class="error"><?= e($errors['logo_media_id']) ?></p><?php endif; ?>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary">Enregistrer les réglages</button>
  </div>
</form>
