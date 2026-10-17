<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\HttpException;
use App\Middleware\MiddlewareInterface;

/**
 * Basit, hızlı router.
 *  - Route tanımı: $r->get('/path', [Controller::class, 'method'])
 *  - Grup: $r->group('/admin', fn($r) => ..., middleware listesi)
 *  - Dinamik segment: '/admin/feeds/{id}'
 *
 * Tam yol: app/Core/Router.php
 */
final class Router
{
    /** @var list<array{method:string, path:string, handler:callable, middleware:list<string>, pattern:string}> */
    private array $routes = [];

    /**
     * @param list<string> $middleware
     */
    public function get(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    /**
     * @param list<string> $middleware
     */
    public function group(string $prefix, callable $register, array $middleware = []): void
    {
        $before = count($this->routes);
        $register($this);
        for ($i = $before, $n = count($this->routes); $i < $n; $i++) {
            $route = $this->routes[$i];
            $route['path'] = '/' . trim($prefix . '/' . ltrim($route['path'], '/'), '/');
            $route['pattern'] = $this->buildPattern($route['path']);
            $route['middleware'] = [...$middleware, ...$route['middleware']];
            $this->routes[$i] = $route;
        }
    }

    private function add(string $method, string $path, array|callable $handler, array $middleware): void
    {
        $this->routes[] = [
            'method'     => $method,
            'path'       => '/' . trim($path, '/'),
            'handler'    => $handler,
            'middleware' => array_values($middleware),
            'pattern'    => $this->buildPattern('/' . trim($path, '/')),
        ];
    }

    private function buildPattern(string $path): string
    {
        $pattern = preg_replace('#\{[a-zA-Z_][a-zA-Z0-9_]*\}#', '([a-zA-Z0-9_-]+)', $path) ?? $path;
        return '#^' . $pattern . '$#';
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method() === 'HEAD' ? 'GET' : $request->method();
        $path = $request->path();

        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                if ($route['method'] === $method) {
                    array_shift($matches);
                    return $this->runPipeline($route, $request, array_values($matches));
                }
                $allowed[] = $route['method'];
            }
        }

        if ($allowed !== []) {
            throw HttpException::methodNotAllowed($method, $path);
        }
        throw HttpException::notFound($path);
    }

    /**
     * @param array{method:string, path:string, handler:array|callable, middleware:list<string>, pattern:string} $route
     * @param list<string> $params
     */
    private function runPipeline(array $route, Request $request, array $params): Response
    {
        $next = function (Request $req) use ($route, $params): Response {
            $callable = $this->resolveHandler($route['handler']);
            /** @var mixed $result */
            $result = $callable(...array_merge([$req], $params));
            if ($result instanceof Response) {
                return $result;
            }
            return Response::html((string) $result);
        };

        // Middleware'ler ters sırayla sarılır (ilk tanımlanan en dışta)
        $middlewares = array_reverse($route['middleware']);
        foreach ($middlewares as $middlewareClass) {
            if (!is_a($middlewareClass, MiddlewareInterface::class, true)) {
                throw new \RuntimeException('Geçersiz middleware: ' . $middlewareClass);
            }
            /** @var MiddlewareInterface $instance */
            $instance = new $middlewareClass();
            $inner = $next;
            $next = function (Request $req) use ($instance, $inner): Response {
                return $instance->handle($req, $inner);
            };
        }

        return $next($request);
    }

    /** [Controller::class, 'method'] dizisini instance callable'a çevirir. */
    private function resolveHandler(array|callable $handler): callable
    {
        if (is_array($handler) && is_string($handler[0]) && is_string($handler[1])) {
            if (!class_exists($handler[0]) || !method_exists($handler[0], $handler[1])) {
                throw new \RuntimeException('Route handler çözümlenemedi: ' . $handler[0] . '::' . $handler[1]);
            }
            return [new $handler[0](), $handler[1]];
        }
        if (!is_callable($handler)) {
            throw new \RuntimeException('Route handler çağrılabilir değil.');
        }
        return $handler;
    }

    /** @return list<array{method:string, path:string, handler:array|callable, middleware:list<string>, pattern:string}> */
    public function routes(): array
    {
        return $this->routes;
    }
}
