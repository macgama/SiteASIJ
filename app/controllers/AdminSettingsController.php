<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Controller;
use App\Db;
use App\Settings;

/** Réglages généraux du site. */
final class AdminSettingsController extends Controller
{
    /** nom => [libellé, longueur max, multi-lignes] */
    public const FIELDS = [
        'site_name' => ['Nom du site', 100, false],
        'site_tagline' => ['Slogan (sous le nom)', 200, false],
        'contact_address' => ['Adresse postale', 500, true],
        'contact_email' => ['Adresse e-mail de contact', 190, false],
        'contact_phone' => ['Téléphone', 50, false],
        'footer_text' => ['Texte du pied de page', 1000, true],
        'banner_text' => ['Bandeau d’information urgente', 500, true],
    ];

    public function index(): void
    {
        Auth::requireAdmin();
        $values = [];
        foreach (array_keys(Settings::DEFAULTS) as $k) {
            $values[$k] = Settings::get($k);
        }
        $this->form($values, []);
    }

    public function save(): void
    {
        Auth::requireAdmin();
        $values = [];
        $errors = [];
        foreach (self::FIELDS as $k => [$label, $max, $multi]) {
            $v = str_replace("\r\n", "\n", $this->input($k));
            $v = trim(strip_tags($v));
            if (!$multi) {
                $v = preg_replace('/\s+/', ' ', $v) ?? '';
            }
            $values[$k] = mb_substr($v, 0, $max);
        }
        if ($values['site_name'] === '') {
            $errors['site_name'] = 'Le nom du site est obligatoire.';
        }
        if ($values['contact_email'] !== '' && !filter_var($values['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['contact_email'] = 'Cette adresse e-mail ne semble pas valide (exemple : info@asij.ch).';
        }
        $logo = $this->input('logo_media_id');
        if ($logo !== '' && !(ctype_digit($logo) && Db::value("SELECT id FROM media WHERE id = ? AND kind = 'image'", [(int) $logo]))) {
            $errors['logo_media_id'] = 'Le logo choisi n’existe plus. Choisissez une autre image.';
            $logo = '';
        }
        $values['logo_media_id'] = $logo;
        if ($errors) {
            $this->form($values, $errors, 422);
            return;
        }
        foreach ($values as $k => $v) {
            Settings::set($k, $v);
        }
        audit('settings.update');
        flash('Les réglages ont été enregistrés.');
        redirect('/admin/reglages');
    }

    private function form(array $values, array $errors, int $status = 200): void
    {
        $logo = null;
        if ($values['logo_media_id'] !== '' && ctype_digit($values['logo_media_id'])) {
            $m = Db::one('SELECT * FROM media WHERE id = ?', [(int) $values['logo_media_id']]);
            $logo = $m ? AdminMediaController::toJson($m) : null;
        }
        $this->view('admin/reglages', [
            'title' => 'Réglages du site',
            'values' => $values,
            'errors' => $errors,
            'logo' => $logo,
            'fields' => self::FIELDS,
        ], $status);
    }
}
