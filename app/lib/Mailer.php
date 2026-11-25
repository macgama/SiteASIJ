<?php
declare(strict_types=1);

namespace App;

/**
 * Envoi d'e-mails texte via mail().
 * Config 'mail.transport' === 'log' : écrit dans storage/logs/mail.log (développement, tests)
 * au lieu d'envoyer ('mail.log_file' permet de changer de fichier).
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $textBody): bool
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) {
            return false;
        }
        $subject = str_replace(["\r", "\n"], ' ', $subject);
        $from = (string) Config::get('mail.from', 'noreply@localhost');
        $fromName = str_replace(["\r", "\n", '"'], '', (string) Config::get('mail.from_name', 'ASIJ'));
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $from = 'noreply@localhost';
        }
        $body = str_replace(["\r\n", "\r"], "\n", $textBody);

        if (Config::get('mail.transport') === 'log') {
            $file = (string) Config::get('mail.log_file', STORAGE . '/logs/mail.log');
            $entry = sprintf(
                "=== %s ===\nTo: %s\nFrom: %s <%s>\nSubject: %s\n\n%s\n\n",
                gmdate('Y-m-d H:i:s'),
                $to,
                $fromName,
                $from,
                $subject,
                $body
            );
            return @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX) !== false;
        }

        $headers = [
            'From' => mb_encode_mimeheader($fromName, 'UTF-8', 'Q') . ' <' . $from . '>',
            'Reply-To' => $from,
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
            'X-Mailer' => 'ASIJ',
        ];
        if (!function_exists('mail')) {
            error_log('Mailer: la fonction mail() est désactivée sur cet hébergement.');
            return false;
        }
        try {
            // -f : adresse d'enveloppe (meilleure délivrabilité sur les mutualisés)
            $ok = mail($to, mb_encode_mimeheader($subject, 'UTF-8', 'Q'), $body, $headers, '-f' . $from);
            if (!$ok) {
                // certains hébergeurs refusent le paramètre -f: nouvel essai sans
                $ok = mail($to, mb_encode_mimeheader($subject, 'UTF-8', 'Q'), $body, $headers);
            }
        } catch (\Throwable $e) {
            error_log('Mailer: envoi impossible à ' . $to . ' : ' . $e->getMessage());
            return false;
        }
        if (!$ok) {
            $err = error_get_last();
            error_log('Mailer: mail() a échoué pour ' . $to . ($err ? ' : ' . $err['message'] : ''));
        }
        return $ok;
    }
}
