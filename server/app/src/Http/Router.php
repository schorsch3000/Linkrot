<?php

declare(strict_types=1);

namespace Linkrot\Http;

/** Minimal router: `{name}` matches one path segment. */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: callable(Request, array<string, string>): Response}> */
    private array $routes = [];

    /** @param callable(Request, array<string, string>): Response $handler */
    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = preg_replace_callback(
            '~\{([a-z_]+)\}|[^{]+~',
            static fn(array $m): string => isset($m[1]) ? '(?P<' . $m[1] . '>[^/]+)' : preg_quote($m[0], '~'),
            $pattern,
        );
        $this->routes[] = ['method' => strtoupper($method), 'regex' => '~^' . $regex . '$~', 'handler' => $handler];
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $m) !== 1) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }
            $params = [];
            foreach ($m as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }

            return ($route['handler'])($request, $params);
        }

        return $pathMatched
            ? Response::error(405, 'invalid_request', 'method not allowed')
            : Response::error(404, 'not_found', 'not found');
    }
}
