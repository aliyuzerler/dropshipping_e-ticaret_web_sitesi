<?php

declare(strict_types=1);

/**
 * Test runner çekirdeği.
 * Tam yol: tests/Support/TestRunner.php
 */

final class TestRunner
{
    /** @var list<array{name:string, fn:callable}> */
    private array $tests = [];

    public function addTest(string $name, callable $fn): void
    {
        $this->tests[] = ['name' => $name, 'fn' => $fn];
    }

    /** @return array{pass:int, fail:int, skip:int} */
    public function run(): array
    {
        $pass = $fail = $skip = 0;

        foreach ($this->tests as $test) {
            $start = microtime(true);
            try {
                ($test['fn'])();
                $pass++;
                printf("  ✓ %s (%.1f ms)\n", $test['name'], (microtime(true) - $start) * 1000);
            } catch (TestSkip $e) {
                $skip++;
                printf("  - %s [SKIP] %s\n", $test['name'], $e->getMessage());
            } catch (\Throwable $e) {
                $fail++;
                printf("  ✗ %s\n      %s\n", $test['name'], $e->getMessage());
            }
        }

        echo str_repeat('─', 58) . PHP_EOL;
        printf("SONUÇ: %d geçti, %d başarısız, %d atlandı\n", $pass, $fail, $skip);

        return ['pass' => $pass, 'fail' => $fail, 'skip' => $skip];
    }
}
