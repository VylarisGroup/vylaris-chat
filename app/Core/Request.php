<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    public array $params = [];
    private ?array $json = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query,
        private readonly array $post,
        private readonly array $files,
        private readonly array $server,
        private readonly string $rawBody
    ) {
    }

    public static function capture(): self
    {
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $base = app_base_path();
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        $uri = '/' . trim(rawurldecode($uri), '/');
        // Direkter Aufruf von /index.php wird wie "/" behandelt
        if ($uri === '/index.php') {
            $uri = '/';
        }
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $raw = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? (string) file_get_contents('php://input') : '';
        return new self($method, $uri, $_GET, $_POST, $_FILES, $_SERVER, $raw);
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path, '/api/');
    }

    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string) ($this->server[$key] ?? '');
    }

    public function wantsJson(): bool
    {
        return $this->isApi() || str_contains($this->header('Accept'), 'application/json');
    }

    private function jsonBody(): array
    {
        if ($this->json === null) {
            $this->json = [];
            if ($this->rawBody !== '' && str_contains(strtolower((string) ($this->server['CONTENT_TYPE'] ?? '')), 'json')) {
                $decoded = json_decode($this->rawBody, true);
                if (!is_array($decoded)) {
                    throw new HttpException(400, 'Ungültiger JSON-Body.');
                }
                $this->json = $decoded;
            }
        }
        return $this->json;
    }

    /** Eingabewert aus JSON-Body, POST oder Query (in dieser Reihenfolge). */
    public function input(string $key, mixed $default = null): mixed
    {
        $json = $this->jsonBody();
        if (array_key_exists($key, $json)) {
            return $json[$key];
        }
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }
        return $this->query[$key] ?? $default;
    }

    public function str(string $key, string $default = '', int $max = 10000): string
    {
        $v = $this->input($key, $default);
        if (!is_scalar($v)) {
            return $default;
        }
        $v = (string) $v;
        // Steuerzeichen ausser Zeilenumbruch und Tab entfernen
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
        return mb_substr($v, 0, $max);
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->input($key, null);
        if ($v === null) {
            return $default;
        }
        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return int[] */
    public function intList(string $key): array
    {
        $v = $this->input($key, []);
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $item) {
            if (is_numeric($item) && (int) $item > 0) {
                $out[] = (int) $item;
            }
        }
        return array_values(array_unique($out));
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        return (is_array($f) && isset($f['tmp_name']) && !is_array($f['tmp_name'])) ? $f : null;
    }
}
