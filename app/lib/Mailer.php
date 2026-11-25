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
        if (is_array(Config::get('mail.smtp')) && (string) Config::get('mail.smtp.host', '') !== '') {
            try {
                self::smtp($to, $subject, $body, $from, $fromName);
                return true;
            } catch (\Throwable $e) {
                error_log('Mailer SMTP: envoi impossible à ' . $to . ' : ' . $e->getMessage());
                return false;
            }
        }
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

    /**
     * Envoi SMTP minimal (AUTH LOGIN, SSL implicite sur 465 ou STARTTLS sur 587).
     * Config: mail.smtp = [host, port, user, password, secure => 'ssl'|'tls'|'']
     */
    private static function smtp(string $to, string $subject, string $body, string $from, string $fromName): void
    {
        $c = (array) Config::get('mail.smtp');
        $host = (string) $c['host'];
        $port = (int) ($c['port'] ?? 465);
        $secure = strtolower((string) ($c['secure'] ?? ($port === 465 ? 'ssl' : 'tls')));
        $timeout = (int) ($c['timeout'] ?? 15);
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new \RuntimeException("connexion à $remote impossible ($errno $errstr)");
        }
        stream_set_timeout($fp, $timeout);
        $read = static function () use ($fp): array {
            $text = '';
            while (($line = fgets($fp, 1024)) !== false) {
                $text .= $line;
                if (strlen($line) < 4 || $line[3] !== '-') {
                    break;
                }
            }
            return [(int) substr($text, 0, 3), trim($text)];
        };
        $cmd = static function (string $line, array $expect, bool $secret = false) use ($fp, $read): string {
            fwrite($fp, $line . "\r\n");
            [$code, $text] = $read();
            if (!in_array($code, $expect, true)) {
                throw new \RuntimeException(($secret ? '(identifiants)' : $line) . ' -> ' . $text);
            }
            return $text;
        };
        try {
            [$code, $text] = $read();
            if ($code !== 220) {
                throw new \RuntimeException('accueil du serveur: ' . $text);
            }
            $ehlo = 'EHLO ' . (parse_url((string) Config::get('site_url', ''), PHP_URL_HOST) ?: 'localhost');
            $cmd($ehlo, [250]);
            if ($secure === 'tls') {
                $cmd('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new \RuntimeException('STARTTLS impossible');
                }
                $cmd($ehlo, [250]);
            }
            if ((string) ($c['user'] ?? '') !== '') {
                $cmd('AUTH LOGIN', [334]);
                $cmd(base64_encode((string) $c['user']), [334], true);
                $cmd(base64_encode((string) ($c['password'] ?? '')), [235], true);
            }
            $cmd('MAIL FROM:<' . $from . '>', [250]);
            $cmd('RCPT TO:<' . $to . '>', [250, 251]);
            $cmd('DATA', [354]);
            $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
            $msg = 'Date: ' . date('r') . "\r\n"
                . 'From: ' . mb_encode_mimeheader($fromName, 'UTF-8', 'Q') . ' <' . $from . ">\r\n"
                . 'To: <' . $to . ">\r\n"
                . 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8', 'Q') . "\r\n"
                . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . ">\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "X-Mailer: ASIJ\r\n\r\n"
                . rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", $body)), 76, "\r\n"));
            $cmd($msg . "\r\n.", [250]);
            fwrite($fp, "QUIT\r\n");
        } finally {
            fclose($fp);
        }
    }
}
