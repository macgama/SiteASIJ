<?php
declare(strict_types=1);

namespace App;

/**
 * Mots de passe à usage unique basés sur le temps (TOTP, RFC 6238 / HOTP RFC 4226).
 * SHA1, 6 chiffres, période 30 s, fenêtre de tolérance ±1 période.
 * Compatible avec les applications d'authentification courantes
 * (Google Authenticator, Microsoft Authenticator, FreeOTP, Aegis...).
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Nouveau secret aléatoire encodé en base32 (20 octets = 32 caractères). */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $bin === '' ? '' : $out;
    }

    /** Décode du base32 (espaces, tirets, casse et remplissage '=' ignorés). */
    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper((string) preg_replace('/[\s\-=]/', '', $b32));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $p = strpos(self::ALPHABET, $c);
            if ($p === false) {
                throw new \InvalidArgumentException('Secret base32 invalide');
            }
            $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    /** HOTP (RFC 4226) à partir d'une clé brute. */
    public static function hotp(string $key, int $counter, int $digits = self::DIGITS, string $algo = 'sha1'): string
    {
        $msg = pack('J', $counter); // 64 bits big-endian
        $h = hash_hmac($algo, $msg, $key, true);
        $offset = ord($h[strlen($h) - 1]) & 0x0F;
        $bin = ((ord($h[$offset]) & 0x7F) << 24)
            | (ord($h[$offset + 1]) << 16)
            | (ord($h[$offset + 2]) << 8)
            | ord($h[$offset + 3]);
        return str_pad((string) ($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /** Code TOTP à partir d'une clé brute (utile pour les vecteurs de test RFC). */
    public static function codeFromKey(string $key, ?int $time = null, int $digits = self::DIGITS, string $algo = 'sha1'): string
    {
        $t = intdiv($time ?? time(), self::PERIOD);
        return self::hotp($key, $t, $digits, $algo);
    }

    /** Code TOTP courant pour un secret base32. */
    public static function code(string $secret, ?int $time = null): string
    {
        return self::codeFromKey(self::base32Decode($secret), $time);
    }

    /** Vérifie un code saisi (espaces tolérés), fenêtre ±$window périodes, comparaison à temps constant. */
    public static function verify(string $secret, string $code, int $window = 1, ?int $time = null): bool
    {
        $code = (string) preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return false;
        }
        try {
            $key = self::base32Decode($secret);
        } catch (\InvalidArgumentException) {
            return false;
        }
        if ($key === '') {
            return false;
        }
        $t = intdiv($time ?? time(), self::PERIOD);
        $ok = false;
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::hotp($key, $t + $i), $code)) {
                $ok = true;
            }
        }
        return $ok;
    }

    /** Comme verify(), mais retourne le pas de temps accepté (pour refuser un rejeu), ou null. */
    public static function verifyStep(string $secret, string $code, int $window = 1, ?int $time = null): ?int
    {
        $code = (string) preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }
        try {
            $key = self::base32Decode($secret);
        } catch (\InvalidArgumentException) {
            return null;
        }
        if ($key === '') {
            return null;
        }
        $t = intdiv($time ?? time(), self::PERIOD);
        $found = null;
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::hotp($key, $t + $i), $code)) {
                $found = $t + $i;
            }
        }
        return $found;
    }

    /** URI otpauth:// à ouvrir dans une application d'authentification. */
    public static function uri(string $secret, string $account, string $issuer = 'ASIJ'): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Secret groupé par 4 caractères pour une saisie manuelle facile. */
    public static function formatSecret(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }
}
