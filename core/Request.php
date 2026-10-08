<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $server
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $post = [],
        public array $server = [],
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $post   = $_POST;

        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            $post    = is_array($decoded) ? $decoded : [];
        }

        // Form HTML hanya mengenal GET/POST; method lain lewat field tersembunyi.
        if ($method === 'POST' && isset($post['_method']) && in_array(strtoupper((string) $post['_method']), ['PUT', 'PATCH', 'DELETE'], true)) {
            $method = strtoupper((string) $post['_method']);
        }

        $uriPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        return new self($method, self::normalizePath($uriPath, Url::basePath()), $_GET, $post, $_SERVER);
    }

    public static function normalizePath(string $uriPath, string $base): string
    {
        $path = rawurldecode($uriPath);
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . trim($path, '/');
        return str_replace("\0", '', $path);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($this->server[$key]) ? (string) $this->server[$key] : null;
    }

    /** IP klien. Sengaja hanya REMOTE_ADDR: header X-Forwarded-For bisa dipalsukan. */
    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        return $https !== '' && strtolower((string) $https) !== 'off';
    }

    public function expectsJson(): bool
    {
        return strtolower((string) $this->header('X-Requested-With')) === 'xmlhttprequest'
            || str_contains((string) $this->header('Accept'), 'application/json')
            || str_starts_with($this->path, '/api/');
    }
}
