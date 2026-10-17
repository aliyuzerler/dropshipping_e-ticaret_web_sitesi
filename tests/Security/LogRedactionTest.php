<?php

declare(strict_types=1);

/**
 * AŞAMA 6 — Logger context redaksiyon testleri.
 *
 *  1) Logger::redact() düz anahtarlar (password, card_number, cvv, secret_key,
 *     token, authorization, smtp_password) → ***.
 *  2) İç içe dizilerde özyinelemeli redaksiyon + normal anahtarlar korunur.
 *  3) Gerçek yazım yolu: Logger::info ile yazılan dosyada maskeli değer var,
 *     ham değer yok (channel dosyası test sonunda silinir).
 *
 * Tam yol: tests/Security/LogRedactionTest.php
 */
use App\Core\App;
use App\Core\Logger;

return static function (TestRunner $t): void {
    App::boot(BASE_PATH);
    restore_error_handler();
    restore_exception_handler();

    $t->addTest('Redaction: hassas anahtarlar maskelenir (içerir kuralı, case-insensitive)', static function (): void {
        $redacted = Logger::redact([
            'password'         => 'sup3rGizli!',
            'PASSWORD'         => 'buyuk',
            'user_password'    => 'x',
            'smtp_password'    => 'mail-pass',
            'card_number'      => '4111111111111111',
            'cardFamily'       => 'visa',
            'cvv'              => '123',
            'cvc'              => '456',
            'secret_key'       => 'iyzico-secret',
            'token'            => 'abcd1234',
            'auth_token'       => 'xyz',
            'Authorization'    => 'Basic dXNlcjpwYXNz',
            'api_key'          => 'ak_live_123',
            'cookie'           => 'session=abc',
        ]);

        foreach (array_keys($redacted) as $key) {
            Assert::same('***', $redacted[$key], "$key maskelenmeli");
        }
    });

    $t->addTest('Redaction: normal anahtarlar ve iç içe diziler korunur/taranır', static function (): void {
        $redacted = Logger::redact([
            'order_number' => 'ORD-20260101-ABCDE',
            'ip'           => '203.0.113.5',
            'total'        => 199.90,
            'payment'      => [
                'lastFourDigits' => '1234',
                'card_number'    => '4111111111111111', // iç içe → maskeli
                'nested'         => ['secret' => 'deep', 'ok' => 'visible'],
            ],
        ]);

        Assert::same('ORD-20260101-ABCDE', $redacted['order_number']);
        Assert::same('203.0.113.5', $redacted['ip']);
        Assert::same('1234', $redacted['payment']['lastFourDigits'], 'Son 4 hane bilgi amaçlı — korunur');
        Assert::same('***', $redacted['payment']['card_number'], 'İç içe card_number maskeli');
        Assert::same('***', $redacted['payment']['nested']['secret']);
        Assert::same('visible', $redacted['payment']['nested']['ok']);
    });

    $t->addTest('Redaction: gerçek dosyaya yazılan logda ham değer YOK, maskeli değer VAR', static function (): void {
        $channel = 'securitytest';
        $file = App::storagePath('logs') . '/' . $channel . '.log';
        if (is_file($file)) {
            @unlink($file);
        }

        try {
            Logger::info($channel, 'log.redaction_probe', [
                'password'    => 'HAM-GIZLI-DEGER',
                'card_number' => '4111111111111111',
                'order'       => 'ORD-OK-123',
            ]);

            $line = (string) @file_get_contents($file);
            Assert::contains('log.redaction_probe', $line, 'Log satırı yazılmalı');
            Assert::contains('ORD-OK-123', $line, 'Normal değer yazılmalı');
            Assert::contains('***', $line, 'Maskeli değer görünmeli');
            Assert::false(str_contains($line, 'HAM-GIZLI-DEGER'), 'Ham parola dosyaya YAZILMAMALI');
            Assert::false(str_contains($line, '4111111111111111'), 'Ham kart numarası dosyaya YAZILMAMALI');
        } finally {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    });

    $t->addTest('Redaction: boş context güvenli (redact no-op)', static function (): void {
        Assert::same([], Logger::redact([]));
        $redacted = Logger::redact(['a' => 1]);
        Assert::same(['a' => 1], $redacted);
    });
};
