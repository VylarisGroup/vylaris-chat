<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<int,array{method:string,regex:string,handler:array,middleware:array}> */
    private array $routes = [];

    /** @param array<int,string> $middleware Klassen mit statischer handle(Request): ?Response */
    public function add(string $method, string $pattern, array $handler, array $middleware = []): void
    {
        $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern);
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    /** @return array<int,array{method:string,regex:string,handler:array,middleware:array}> */
    public function all(): array
    {
        return $this->routes;
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }
            foreach ($m as $k => $v) {
                if (is_string($k)) {
                    $request->params[$k] = $v;
                }
            }
            foreach ($route['middleware'] as $mw) {
                $res = $mw::handle($request);
                if ($res instanceof Response) {
                    return $res;
                }
            }
            [$class, $method] = $route['handler'];
            $result = (new $class())->$method($request);
            if (!$result instanceof Response) {
                throw new \LogicException('Controller muss eine Response liefern.');
            }
            return $result;
        }
        throw new HttpException($pathMatched ? 405 : 404, $pathMatched ? 'Methode nicht erlaubt.' : 'Nicht gefunden.');
    }
}
