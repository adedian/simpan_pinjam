<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<int,array{method:string,regex:string,handler:array{0:string,1:string},middleware:array<int,string>}> */
    private array $routes = [];
    /** @var array<int,array{prefix:string,middleware:array<int,string>}> */
    private array $groups = [];

    /**
     * @param array{0:string,1:string} $handler [ControllerClass::class, 'method']
     * @param array<int,string> $middleware
     */
    public function get(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    /**
     * @param array{0:string,1:string} $handler
     * @param array<int,string> $middleware
     */
    public function post(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    /**
     * Daftar semua route (untuk tes struktural: memastikan tiap route terlindungi middleware yang semestinya).
     * @return array<int,array{method:string,regex:string,handler:array{0:string,1:string},middleware:array<int,string>}>
     */
    public function all(): array
    {
        return $this->routes;
    }

    /** @param array{prefix?:string,middleware?:array<int,string>} $options */
    public function group(array $options, callable $callback): void
    {
        $this->groups[] = ['prefix' => $options['prefix'] ?? '', 'middleware' => $options['middleware'] ?? []];
        $callback($this);
        array_pop($this->groups);
    }

    /**
     * @param array{0:string,1:string} $handler
     * @param array<int,string> $middleware
     */
    private function add(string $method, string $pattern, array $handler, array $middleware): void
    {
        $prefix = '';
        foreach ($this->groups as $group) {
            $prefix    .= $group['prefix'];
            $middleware = array_merge($group['middleware'], $middleware);
        }
        $full = '/' . trim($prefix . '/' . trim($pattern, '/'), '/');

        $this->routes[] = [
            'method'     => $method,
            'regex'      => self::compile($full),
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    private static function compile(string $pattern): string
    {
        $regex = preg_replace_callback(
            '/\{(\w+)(?::([^}]+))?\}|([^{]+)/',
            static function (array $m): string {
                if (isset($m[3]) && $m[3] !== '') {
                    return preg_quote($m[3], '#');
                }
                return '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')';
            },
            $pattern,
        );
        return '#^' . $regex . '$#';
    }

    /**
     * @return array{handler:array{0:string,1:string},middleware:array<int,string>,params:array<string,string>}
     * @throws HttpException 404 bila path tidak ada, 405 bila path ada tetapi method salah
     */
    public function match(string $method, string $path): array
    {
        $method     = $method === 'HEAD' ? 'GET' : $method;
        $pathExists = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $pathExists = true;
            if ($route['method'] !== $method) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return ['handler' => $route['handler'], 'middleware' => $route['middleware'], 'params' => $params];
        }

        throw new HttpException($pathExists ? 405 : 404);
    }
}
