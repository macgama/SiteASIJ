<?php
declare(strict_types=1);

namespace App;

/** Réglages modifiables depuis l'administration (nom du site, pied de page, etc.). */
final class Settings
{
    private static ?array $cache = null;

    public const DEFAULTS = [
        'site_name' => 'ASIJ',
        'site_tagline' => 'Association scolaire intercommunale du Jorat',
        'contact_address' => "Chemin du Raffort 11\n1083 Mézières",
        'contact_email' => '',
        'contact_phone' => '',
        'footer_text' => '',
        'banner_text' => '',          // bandeau d'information urgente (vide = masqué)
        'logo_media_id' => '',
    ];

    public static function get(string $name): string
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Db::all('SELECT name, value FROM settings') as $r) {
                self::$cache[$r['name']] = (string) $r['value'];
            }
        }
        return self::$cache[$name] ?? (self::DEFAULTS[$name] ?? '');
    }

    public static function set(string $name, string $value): void
    {
        if (Db::one('SELECT name FROM settings WHERE name = ?', [$name])) {
            Db::update('settings', ['value' => $value], 'name = :n', ['n' => $name]);
        } else {
            Db::insert('settings', ['name' => $name, 'value' => $value]);
        }
        self::$cache = null;
    }
}
