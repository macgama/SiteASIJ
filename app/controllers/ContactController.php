<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\Controller;
use App\Db;
use App\Settings;

/** Formulaire de contact public (bloc « contact »). */
final class ContactController extends Controller
{
    public const MAX_PER_HOUR = 5;

    public function submit(): void
    {
        $back = self::backUrl($_SERVER['HTTP_REFERER'] ?? '');

        // Champ piège rempli: robot. On fait comme si tout allait bien, sans rien enregistrer.
        if ($this->input('website') !== '') {
            flash('Merci, votre message a bien été envoyé.');
            redirect($back . '#contact', 303);
        }

        $name = mb_substr(preg_replace('/[\r\n\t]+/', ' ', $this->input('name')) ?? '', 0, 190);
        $email = mb_substr(mb_strtolower($this->input('email')), 0, 190);
        $message = str_replace("\r\n", "\n", $this->input('message'));

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Indiquez votre nom.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Indiquez une adresse e-mail valide, par exemple prenom.nom@exemple.ch.';
        }
        if ($message === '') {
            $errors['message'] = 'Écrivez votre message.';
        } elseif (mb_strlen($message) > 5000) {
            $errors['message'] = 'Votre message est trop long (5000 caractères au maximum).';
        }

        if ($errors) {
            $_SESSION['contact_old'] = ['name' => $name, 'email' => $email, 'message' => mb_substr($message, 0, 5000)];
            $_SESSION['contact_errors'] = $errors;
            flash("Le message n'a pas été envoyé : merci de corriger les champs indiqués.", 'error');
            redirect($back . '#contact', 303);
        }

        $ip = client_ip();
        $since = gmdate('Y-m-d H:i:s', time() - 3600);
        $recent = (int) Db::value('SELECT COUNT(*) FROM contact_messages WHERE ip = ? AND created_at >= ?', [$ip, $since]);
        if ($recent >= self::MAX_PER_HOUR) {
            $_SESSION['contact_old'] = ['name' => $name, 'email' => $email, 'message' => $message];
            flash("Vous avez déjà envoyé plusieurs messages récemment. Merci de réessayer dans une heure ou de nous appeler.", 'error');
            redirect($back . '#contact', 303);
        }

        Db::insert('contact_messages', [
            'name' => $name,
            'email' => $email,
            'message' => $message,
            'ip' => $ip,
            'created_at' => Db::now(),
        ]);

        try {
            self::sendMail($name, $email, $message, $back);
        } catch (\Throwable $e) {
            error_log('Contact: envoi impossible: ' . $e->getMessage());
        }

        flash('Merci, votre message a bien été envoyé. Nous vous répondrons dès que possible.');
        redirect($back . '#contact', 303);
    }

    /** Chemin de retour: Referer seulement s'il pointe vers ce site, sinon l'accueil. */
    public static function backUrl(string $referer): string
    {
        if ($referer === '') {
            return '/';
        }
        $p = parse_url($referer);
        if ($p === false) {
            return '/';
        }
        $host = strtolower((string) ($p['host'] ?? ''));
        if ($host !== '') {
            $allowed = array_filter([
                strtolower((string) parse_url((string) Config::get('site_url', ''), PHP_URL_HOST)),
                strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''))),
            ]);
            if (!in_array($host, $allowed, true)) {
                return '/';
            }
        } elseif (isset($p['scheme'])) {
            return '/';
        }
        $path = (string) ($p['path'] ?? '/');
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return '/';
        }
        $path = preg_replace('/[\x00-\x1f\x7f]/', '', $path) ?? '/';
        if (isset($p['query']) && $p['query'] !== '') {
            $path .= '?' . preg_replace('/[\x00-\x1f\x7f]/', '', $p['query']);
        }
        return $path;
    }

    private static function sendMail(string $name, string $email, string $message, string $from): void
    {
        $to = (string) Config::get('mail.contact_to', '');
        if ($to === '') {
            $to = Settings::get('contact_email');
        }
        $siteName = Settings::get('site_name');
        $subject = 'Message du site ' . $siteName . ' – ' . $name;
        $body = "Nouveau message reçu par le formulaire de contact du site.\n\n"
            . "Nom : $name\nE-mail : $email\nPage : " . url($from) . "\nDate : " . date('d.m.Y H:i') . "\n\n"
            . "Message :\n" . $message . "\n\n-- \nPour répondre, utilisez simplement « Répondre » dans votre messagerie.\n";

        if (Config::get('mail.transport') === 'log' || $to === '') {
            $dir = STORAGE . '/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            file_put_contents(
                $dir . '/mail.log',
                '=== ' . date('c') . "\nÀ : $to\nObjet : $subject\nRépondre à : $email\n\n$body\n",
                FILE_APPEND | LOCK_EX
            );
            return;
        }

        $fromAddr = (string) Config::get('mail.from', 'noreply@localhost');
        $fromName = (string) Config::get('mail.from_name', $siteName);
        $clean = fn(string $s) => str_replace(["\r", "\n"], '', $s);
        $headers = [
            'From' => mb_encode_mimeheader($clean($fromName), 'UTF-8', 'Q') . ' <' . $clean($fromAddr) . '>',
            'Reply-To' => $clean($email),
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
            'X-Mailer' => 'ASIJ',
        ];
        mail($clean($to), mb_encode_mimeheader($clean($subject), 'UTF-8', 'Q'), $body, $headers);
    }
}
