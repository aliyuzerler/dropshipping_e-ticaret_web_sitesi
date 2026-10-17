<?php

declare(strict_types=1);

/**
 * Router testleri.
 * Tam yol: tests/RouterTest.php
 */
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Exceptions\HttpException;
use App\Middleware\MiddlewareInterface;

return static function (TestRunner $t): void {
    $t->addTest('Router: eşleşen route çalışır', static function (): void {
        $router = new Router();
        $router->get('/ping', static fn (): Response => Response::json(['pong' => true]));
        $response = $router->dispatch(new Request('GET', '/ping'));
        Assert::same(200, $response->status());
        Assert::contains('pong', $response->content());
    });

    $t->addTest('Router: 404 HttpException fırlatır', static function (): void {
        $router = new Router();
        $router->get('/mevcut', static fn (): Response => Response::html('ok'));
        Assert::throws(HttpException::class, static fn () => $router->dispatch(new Request('GET', '/yok')));
    });

    $t->addTest('Router: yanlış method 405 döner', static function (): void {
        $router = new Router();
        $router->get('/sadece-get', static fn (): Response => Response::html('ok'));
        $e = null;
        try {
            $router->dispatch(new Request('POST', '/sadece-get'));
        } catch (HttpException $e) {
        }
        Assert::true($e instanceof HttpException, 'HttpException beklenir');
        Assert::same(405, $e?->getStatusCode());
    });

    $t->addTest('Router: HEAD isteği GET handler\'a düşer', static function (): void {
        $router = new Router();
        $router->get('/head', static fn (): Response => Response::html('merhaba'));
        $response = $router->dispatch(new Request('HEAD', '/head'));
        Assert::same(200, $response->status());
    });

    $t->addTest('Router: middleware pipeline sırası', static function (): void {
        $log = [];
        $marker = new class implements MiddlewareInterface {
            public static array $log = [];

            public function handle(Request $request, callable $next): Response
            {
                self::$log[] = 'mw';
                /** @var Response $response */
                $response = $next($request);
                return $response->withHeader('X-Mw', '1');
            }
        };

        $router = new Router();
        $router->get('/mw', static fn (): Response => Response::html('govde'), [$marker::class]);
        $response = $router->dispatch(new Request('GET', '/mw'));
        Assert::same(['mw'], $marker::$log, 'Middleware çalışmalı');
        Assert::same('1', (string) $response->headers()['X-Mw'], 'Middleware header eklemeli');
    });

    $t->addTest('Router: dinamik segment parametresi handler\'a geçer', static function (): void {
        $router = new Router();
        $router->get('/feeds/{id}', static fn (Request $r, string $id): Response => Response::json(['id' => $id]));
        $response = $router->dispatch(new Request('GET', '/feeds/42'));
        // Parametreler regex eşleşmesinden string olarak gelir
        Assert::contains('"id":"42"', $response->content());
    });

    $t->addTest('Router: group prefix ve middleware devralınır', static function (): void {
        $guard = new class implements MiddlewareInterface {
            public function handle(Request $request, callable $next): Response
            {
                /** @var Response $response */
                $response = $next($request);
                return $response->withHeader('X-Group', 'ok');
            }
        };

        $router = new Router();
        $router->group('/admin', static function (Router $r): void {
            $r->get('/panel', static fn (): Response => Response::html('panel'));
        }, [$guard::class]);

        $response = $router->dispatch(new Request('GET', '/admin/panel'));
        Assert::same(200, $response->status());
        Assert::same('ok', (string) $response->headers()['X-Group']);
    });
};
