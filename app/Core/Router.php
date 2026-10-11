<?php
declare(strict_types=1);
namespace WB2A\Core;

/**
 * Router: tiny regex-based front controller router.
 */
final class Router
{
    private array $routes = [];

    public function add($methods, string $pattern, array $handler): void
    {
        foreach ((array)$methods as $m) {
            $this->routes[] = [$m, $pattern, $handler];
        }
    }

    public function dispatch(string $path): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        foreach ($this->routes as [$m, $pattern, $handler]) {
            if ($m !== $method) { continue; }
            if (preg_match($pattern, '/' . $path, $mt)) {
                // expose subpatterns to handlers via $GLOBALS['_route_args']
                $GLOBALS['_route_args'] = array_slice($mt, 1);
                [$class, $fn] = $handler;
                $class::$fn(...array_slice($mt, 1));
                return;
            }
        }
        Util::jsonOut(['ok' => false, 'error' => 'not found'], 404);
    }
}
