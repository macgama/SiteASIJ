<?php
declare(strict_types=1);

namespace App;

final class Router
{
    /** @var array<int,array{0:string,1:string,2:callable|array}> */
    private array $routes = [];

    public function get(string $pattern, callable|array $handler): void
    {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    public function post(string $pattern, callable|array $handler): void
    {
        $this->routes[] = ['POST', $pattern, $handler];
    }

    /**
     * Motifs: '/admin/pages/{id}' ({id} = chiffres), '{slug}' = segment, '{path*}' = reste.
     */
    public function dispatch(string $method, string $uri): bool
    {
        $path = rawurldecode(parse_url($uri, PHP_URL_PATH) ?: '/');
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        foreach ($this->routes as [$m, $pattern, $handler]) {
            if ($m !== $method) {
                continue;
            }
            $re = preg_replace_callback('/\{(\w+)(\*)?\}/', function ($mm) {
                if (!empty($mm[2])) {
                    return '(?P<' . $mm[1] . '>.*)';
                }
                return $mm[1] === 'id' ? '(?P<id>\d+)' : '(?P<' . $mm[1] . '>[^/]+)';
            }, $pattern);
            if (preg_match('#^' . $re . '$#u', $path, $mt)) {
                $params = array_filter($mt, 'is_string', ARRAY_FILTER_USE_KEY);
                if ($method === 'POST') {
                    Csrf::check();
                }
                if (is_array($handler)) {
                    [$class, $fn] = $handler;
                    (new $class())->$fn(...$params);
                } else {
                    $handler(...$params);
                }
                return true;
            }
        }
        return false;
    }
}
