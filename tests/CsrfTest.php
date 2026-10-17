<?php

declare(strict_types=1);

/**
 * CSRF testleri.
 * Tam yol: tests/CsrfTest.php
 */
use App\Core\Csrf;
use App\Core\Session;

return static function (TestRunner $t): void {
    $t->addTest('Csrf: token üretilir ve oturumda sabit kalır', static function (): void {
        Session::start();
        $token1 = Csrf::token();
        $token2 = Csrf::token();
        Assert::true($token1 !== '', 'Token boş olamaz');
        Assert::same($token1, $token2, 'Aynı oturumda token değişmemeli');
        Assert::same(64, strlen($token1), '32 byte hex = 64 karakter');
    });

    $t->addTest('Csrf: doğru token kabul edilir', static function (): void {
        Session::start();
        $token = Csrf::token();
        Assert::true(Csrf::validate($token));
    });

    $t->addTest('Csrf: yanlış/eksik token reddedilir (timing-safe)', static function (): void {
        Session::start();
        Csrf::token();
        Assert::false(Csrf::validate('yanlis-token'));
        Assert::false(Csrf::validate(null));
        Assert::false(Csrf::validate(''));
    });

    $t->addTest('Csrf: rotate yeni token üretir', static function (): void {
        Session::start();
        $old = Csrf::token();
        $new = Csrf::rotate();
        Assert::true($old !== $new, 'Token rotasyonu farklı token üretmeli');
        Assert::true(Csrf::validate($new));
        Assert::false(Csrf::validate($old), 'Eski token artık geçersiz olmalı');
    });

    $t->addTest('Csrf: field() gizli input üretir', static function (): void {
        Session::start();
        $html = Csrf::field();
        Assert::contains('name="_token"', $html);
        Assert::contains('type="hidden"', $html);
    });
};
