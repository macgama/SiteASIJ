<?php
declare(strict_types=1);
/** @var array $b @var array $data */
use App\Blocks;

$intro = Blocks::str($data, 'intro');
$old = is_array($_SESSION['contact_old'] ?? null) ? $_SESSION['contact_old'] : [];
$errors = is_array($_SESSION['contact_errors'] ?? null) ? $_SESSION['contact_errors'] : [];
unset($_SESSION['contact_old'], $_SESSION['contact_errors']);
$fid = 'c' . substr(md5((string) ($b['id'] ?? 'contact')), 0, 6);
$val = fn(string $k) => e(is_string($old[$k] ?? null) ? $old[$k] : '');
$err = function (string $k) use ($errors, $fid): string {
    return isset($errors[$k]) ? '<p class="field-error" id="' . $fid . '-' . $k . '-err">' . e($errors[$k]) . '</p>' : '';
};
$describe = fn(string $k) => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="' . $fid . '-' . $k . '-err"' : '';
?>
<section class="block block-contact" id="contact" aria-labelledby="<?= $fid ?>-title">
  <h2 id="<?= $fid ?>-title" class="contact-title">Nous écrire</h2>
  <?php if ($intro !== ''): ?><p class="contact-intro"><?= App\Html::nl($intro) ?></p><?php endif; ?>
  <form class="contact-form" method="post" action="/contact/envoyer" novalidate>
    <?= csrf_field() ?>
    <div class="field">
      <label for="<?= $fid ?>-name">Votre nom <span class="req">(obligatoire)</span></label>
      <input type="text" id="<?= $fid ?>-name" name="name" required maxlength="190" autocomplete="name" value="<?= $val('name') ?>"<?= $describe('name') ?>>
      <?= $err('name') ?>
    </div>
    <div class="field">
      <label for="<?= $fid ?>-email">Votre adresse e-mail <span class="req">(obligatoire)</span></label>
      <input type="email" id="<?= $fid ?>-email" name="email" required maxlength="190" autocomplete="email" inputmode="email" value="<?= $val('email') ?>"<?= $describe('email') ?>>
      <?= $err('email') ?>
    </div>
    <div class="field">
      <label for="<?= $fid ?>-message">Votre message <span class="req">(obligatoire)</span></label>
      <textarea id="<?= $fid ?>-message" name="message" required rows="6" maxlength="5000"<?= $describe('message') ?>><?= $val('message') ?></textarea>
      <?= $err('message') ?>
    </div>
    <div class="field-trap" aria-hidden="true">
      <label for="<?= $fid ?>-website">Ne pas remplir ce champ</label>
      <input type="text" id="<?= $fid ?>-website" name="website" tabindex="-1" autocomplete="off" value="">
    </div>
    <p class="form-note">Vos coordonnées servent uniquement à vous répondre.</p>
    <button type="submit" class="btn btn-primary">Envoyer le message</button>
  </form>
</section>
