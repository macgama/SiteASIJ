<?php
declare(strict_types=1);
/** Formulaire de dossier (création ou modification).
 * @var ?array $folder @var array $groups @var int[] $direct @var int[] $inherited @var array $folderOptions @var ?int $defaultParent */
$folder = $folder ?? null;
$direct = $direct ?? [];
$inherited = $inherited ?? [];
$parent = $folder ? (int) ($folder['parent_id'] ?? 0) : (int) ($defaultParent ?? 0);
$p = $folder ? 'f' . (int) $folder['id'] : 'new' . $parent;
?>
<?= csrf_field() ?>
<input type="hidden" name="id" value="<?= (int) ($folder['id'] ?? 0) ?>">
<div class="field">
  <label for="<?= $p ?>-name">Nom du dossier</label>
  <input type="text" id="<?= $p ?>-name" name="name" value="<?= e($folder['name'] ?? '') ?>" required maxlength="255">
</div>
<div class="field">
  <label for="<?= $p ?>-parent">Emplacement</label>
  <select id="<?= $p ?>-parent" name="parent_id">
    <option value="0">À la racine de l'espace sécurisé</option>
    <?php foreach ($folderOptions as $o): ?>
    <option value="<?= (int) $o['id'] ?>"<?= $o['id'] === $parent ? ' selected' : '' ?>>Dans : <?= e($o['label']) ?></option>
    <?php endforeach; ?>
  </select>
</div>
<div class="field">
  <label for="<?= $p ?>-desc">Description (facultatif)</label>
  <input type="text" id="<?= $p ?>-desc" name="description" value="<?= e($folder['description'] ?? '') ?>" maxlength="2000">
</div>
<fieldset class="field pa-fieldset">
  <legend>Groupes autorisés à consulter ce dossier</legend>
  <p class="help">Les sous-dossiers héritent automatiquement de ces autorisations. Les administrateurs voient tous les dossiers.</p>
  <?php if (!$groups): ?><p class="muted">Aucun groupe. <a href="/admin/groupes">Créer un groupe</a></p><?php endif; ?>
  <?php foreach ($groups as $g): $gid = (int) $g['id']; $inh = in_array($gid, $inherited, true); ?>
  <label class="pa-check">
    <input type="checkbox" name="groups[]" value="<?= $gid ?>"<?= in_array($gid, $direct, true) ? ' checked' : '' ?>>
    <?= e($g['name']) ?>
    <?php if ($inh): ?><span class="pa-tag pa-tag-inherit">déjà autorisé : hérité du dossier parent</span><?php endif; ?>
  </label>
  <?php endforeach; ?>
</fieldset>
