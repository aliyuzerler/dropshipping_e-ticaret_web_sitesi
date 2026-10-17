<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;

/**
 * Kural tabanlı doğrulayıcı.
 * Kurallar: required, email, url, numeric, int, min:N, max:N, in:a,b
 *
 * Tam yol: app/Validators/Validator.php
 */
final class Validator
{
    /** @var array<string,list<string>> */
    private array $errors = [];

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules  alan => "required|email|max:190"
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules
    ) {
    }

    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    public function fails(): bool
    {
        return $this->errors !== [] || $this->run() === false;
    }

    private function run(): bool
    {
        foreach ($this->rules as $field => $ruleString) {
            $value = $this->data[$field] ?? null;
            foreach (explode('|', $ruleString) as $rule) {
                $this->applyRule((string) $field, $value, trim($rule));
            }
        }
        return $this->errors === [];
    }

    private function applyRule(string $field, mixed $value, string $rule): void
    {
        $isEmpty = $value === null || (is_string($value) && trim($value) === '');

        if ($rule === 'required') {
            if ($isEmpty) {
                $this->addError($field, ':field alanı zorunludur.');
            }
            return;
        }

        if ($isEmpty) {
            return; // opsiyonel alan diğer kuralları atlar
        }

        if (str_starts_with($rule, 'min:')) {
            $min = (int) substr($rule, 4);
            if (mb_strlen((string) $value) < $min) {
                $this->addError($field, ":field en az $min karakter olmalıdır.");
            }
        } elseif (str_starts_with($rule, 'max:')) {
            $max = (int) substr($rule, 4);
            if (mb_strlen((string) $value) > $max) {
                $this->addError($field, ":field en fazla $max karakter olabilir.");
            }
        } elseif ($rule === 'email') {
            if (!filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
                $this->addError($field, ':field geçerli bir e-posta adresi olmalıdır.');
            }
        } elseif ($rule === 'url') {
            if (!filter_var((string) $value, FILTER_VALIDATE_URL)) {
                $this->addError($field, ':field geçerli bir URL olmalıdır.');
            }
        } elseif ($rule === 'numeric') {
            if (!is_numeric($value)) {
                $this->addError($field, ':field sayısal bir değer olmalıdır.');
            }
        } elseif ($rule === 'int') {
            if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                $this->addError($field, ':field tam sayı olmalıdır.');
            }
        } elseif (str_starts_with($rule, 'in:')) {
            $allowed = explode(',', substr($rule, 3));
            if (!in_array((string) $value, $allowed, true)) {
                $this->addError($field, ':field geçersiz bir değer içeriyor.');
            }
        }
    }

    private function addError(string $field, string $message): void
    {
        $label = $field;
        $this->errors[$label][] = str_replace(':field', $label, $message);
    }

    /**
     * Geçerliyse veriyi döndürür, değilse ValidationException fırlatır.
     *
     * @return array<string,mixed>
     */
    public function validate(): array
    {
        $this->run();
        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }
        return $this->data;
    }

    /** @return array<string,list<string>> */
    public function errors(): array
    {
        $this->run();
        return $this->errors;
    }
}
