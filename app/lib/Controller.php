<?php
declare(strict_types=1);

namespace App;

/** Classe de base des contrôleurs: petits raccourcis communs. */
abstract class Controller
{
    protected function view(string $template, array $data = [], int $status = 200): void
    {
        http_response_code($status);
        echo View::render($template, $data);
    }

    protected function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function notFound(string $msg = 'Page introuvable.'): void
    {
        $this->view('public/error', ['code' => 404, 'message' => $msg], 404);
    }

    protected function input(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }
}
