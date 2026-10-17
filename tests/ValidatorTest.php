<?php

declare(strict_types=1);

/**
 * Validator + XmlUploadValidator testleri.
 * Tam yol: tests/ValidatorTest.php
 */
use App\Exceptions\ValidationException;
use App\Validators\Validator;

return static function (TestRunner $t): void {
    $t->addTest('Validator: geçerli veri döndürür', static function (): void {
        $data = Validator::make(
            ['email' => 'ali@example.com', 'name' => 'Ali'],
            ['email' => 'required|email|max:180', 'name' => 'required|min:2']
        )->validate();

        Assert::same('Ali', $data['name']);
    });

    $t->addTest('Validator: eksik zorunlu alan hata verir', static function (): void {
        $validator = Validator::make(['email' => ''], ['email' => 'required|email']);
        Assert::true($validator->fails());
        Assert::throws(ValidationException::class, static fn () => $validator->validate());
    });

    $t->addTest('Validator: geçersiz e-posta hata verir', static function (): void {
        $validator = Validator::make(['email' => 'bozuk-mail'], ['email' => 'required|email']);
        Assert::true($validator->fails(), 'bozuk-mail kabul edilmemeli');
    });

    $t->addTest('Validator: in kuralı', static function (): void {
        $ok = Validator::make(['t' => 'xml'], ['t' => 'in:xml,json']);
        $bad = Validator::make(['t' => 'yaml'], ['t' => 'in:xml,json']);
        Assert::false($ok->fails());
        Assert::true($bad->fails());
    });

    $t->addTest('Validator: min/max uzunluk', static function (): void {
        $validator = Validator::make(['s' => 'abc'], ['s' => 'min:5']);
        Assert::true($validator->fails());
        $validator2 = Validator::make(['s' => 'abcdefgh'], ['s' => 'max:5']);
        Assert::true($validator2->fails());
        $validator3 = Validator::make(['s' => 'abcde'], ['s' => 'min:5|max:5']);
        Assert::false($validator3->fails());
    });

    $t->addTest('XmlUploadValidator: bozuk dosya reddedilir', static function (): void {
        $tmp = tempnam(sys_get_temp_dir(), 'notxml');
        file_put_contents($tmp, 'Bu bir XML degil, sadece metin.');
        $validator = new App\Validators\XmlUploadValidator();

        Assert::throws(ValidationException::class, static fn () => $validator->validate([
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => $tmp,
            'name' => 'test.xml',
            'size' => filesize($tmp),
        ]));
        @unlink($tmp);
    });

    $t->addTest('XmlUploadValidator: .txt uzantısı reddedilir', static function (): void {
        $tmp = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($tmp, '<?xml version="1.0"?><root/>');
        $validator = new App\Validators\XmlUploadValidator();

        Assert::throws(ValidationException::class, static fn () => $validator->validate([
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => $tmp,
            'name' => 'test.txt',
            'size' => filesize($tmp),
        ]));
        @unlink($tmp);
    });

    $t->addTest('XmlUploadValidator: DOCTYPE içerian XML reddedilir (XXE savunma)', static function (): void {
        $tmp = tempnam(sys_get_temp_dir(), 'xxe');
        file_put_contents($tmp, '<?xml version="1.0"?><!DOCTYPE product [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><product><name>&xxe;</name></product>');
        $validator = new App\Validators\XmlUploadValidator();

        Assert::throws(ValidationException::class, static fn () => $validator->validate([
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => $tmp,
            'name' => 'evil.xml',
            'size' => filesize($tmp),
        ]));
        @unlink($tmp);
    });
};
