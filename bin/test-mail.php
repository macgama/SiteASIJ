<?php
declare(strict_types=1);
/**
 * Envoie un e-mail de test avec la configuration de config.php (SMTP ou mail()).
 * Usage : php bin/test-mail.php destinataire@exemple.ch
 * En cas d'échec, la raison s'affiche (et figure dans storage/logs/php-error.log).
 */
require dirname(__DIR__) . '/app/bootstrap.php';

ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');

$to = $argv[1] ?? '';
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    exit("Usage : php bin/test-mail.php destinataire@exemple.ch\n");
}
$smtp = App\Config::get('mail.smtp');
echo 'Mode : ', is_array($smtp) ? 'SMTP ' . ($smtp['host'] ?? '?') . ':' . ($smtp['port'] ?? 465) : 'mail() de PHP', "\n";
echo 'Expéditeur : ', App\Config::get('mail.from', '(non défini)'), "\n";
$ok = App\Mailer::send($to, 'Test d’envoi – site ASIJ', "Bonjour,\n\nCeci est un message de test du site ASIJ.\nSi vous le recevez, l'envoi des e-mails fonctionne.\n");
echo $ok ? "Envoyé à $to. Vérifiez la boîte de réception (et les indésirables).\n" : "Échec de l'envoi (voir le message ci-dessus).\n";
exit($ok ? 0 : 1);
