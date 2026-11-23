<?php
declare(strict_types=1);
/**
 * Mini lanceur de tests sans dépendance. Usage: php tests/run.php [filtre]
 * Chaque fichier tests/*Test.php retourne un tableau ['nom du test' => fn() => ...].
 * Utiliser check(bool, message) pour les assertions. Base SQLite en mémoire.
 */
putenv('ASIJ_CONFIG=' . __DIR__ . '/config.test.php');
require dirname(__DIR__) . '/app/bootstrap.php';
App\Db::runSchema(ROOT . '/database/schema.sql');

function check(bool $cond, string $msg = 'assertion'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

$filter = $argv[1] ?? '';
$pass = $fail = 0;
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    foreach (require $file as $name => $fn) {
        if ($filter && !str_contains(basename($file) . ' ' . $name, $filter)) {
            continue;
        }
        try {
            $fn();
            $pass++;
        } catch (Throwable $e) {
            $fail++;
            echo "ÉCHEC " . basename($file) . " :: $name\n   " . $e->getMessage() . "\n";
        }
    }
}
echo "$pass réussi(s), $fail échec(s)\n";
exit($fail ? 1 : 0);
