<?php
declare(strict_types=1);

/** Échappe pour HTML. */
function e(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** URL absolue d'un chemin interne. */
function url(string $path = '/'): string
{
    return rtrim((string) App\Config::get('site_url', ''), '/') . '/' . ltrim($path, '/');
}

/** Chemin d'un fichier statique avec cache-busting. */
function asset(string $path): string
{
    $f = ROOT . '/public/assets/' . ltrim($path, '/');
    $v = is_file($f) ? filemtime($f) : 0;
    return '/assets/' . ltrim($path, '/') . '?v=' . $v;
}

function redirect(string $to, int $code = 302): never
{
    header('Location: ' . $to, true, $code);
    exit;
}

function flash(?string $msg = null, string $type = 'success'): ?array
{
    if ($msg !== null) {
        $_SESSION['_flash'][] = ['type' => $type, 'msg' => $msg];
        return null;
    }
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(App\Csrf::token()) . '">';
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Formate une taille de fichier. */
function human_size(int $bytes): string
{
    $u = ['o', 'Ko', 'Mo', 'Go'];
    $i = 0;
    $n = (float) $bytes;
    while ($n >= 1024 && $i < count($u) - 1) {
        $n /= 1024;
        $i++;
    }
    return ($i === 0 ? (string) $bytes : number_format($n, 1, ',', "'")) . ' ' . $u[$i];
}

/** Date lisible en français. */
function date_fr(?string $utc, bool $withTime = false): string
{
    if (!$utc) {
        return '';
    }
    $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    $d = $d->setTimezone(new DateTimeZone('Europe/Zurich'));
    return $d->format($withTime ? 'd.m.Y H:i' : 'd.m.Y');
}

function audit(string $action, ?string $target = null): void
{
    App\Db::insert('audit_log', [
        'user_id' => App\Auth::id(),
        'action' => $action,
        'target' => $target,
        'ip' => client_ip(),
        'created_at' => App\Db::now(),
    ]);
}
