<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ProductionEnvironmentTest extends TestCase
{
    public function test_valid_environment_is_accepted_without_printing_the_key_or_password(): void
    {
        $env = $this->environment();
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/docker/validate-environment.php'], env: $env);
        $process->run();
        $this->assertSame(0, $process->getExitCode());
        $this->assertStringNotContainsString($env['APP_KEY'], $process->getOutput().$process->getErrorOutput());
        $this->assertStringNotContainsString($env['DB_PASSWORD'], $process->getOutput().$process->getErrorOutput());
    }

    public function test_missing_key_insecure_cookies_and_transaction_pooler_are_rejected(): void
    {
        foreach ([['APP_KEY' => ''], ['SESSION_SECURE_COOKIE' => 'false'], ['DB_PORT' => '6543'], ['SICOVI_DEMO_ACCOUNTS' => 'true'], ['DB_SSLMODE' => 'prefer']] as $invalid) {
            $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/docker/validate-environment.php'], env: array_replace($this->environment(), $invalid));
            $process->run();
            $this->assertSame(1, $process->getExitCode());
        }
    }

    private function environment(): array
    {
        return [
            'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_URL' => 'https://sicovi.example.test', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'example.invalid',
            'DB_PORT' => '5432', 'DB_DATABASE' => 'postgres', 'DB_USERNAME' => 'fixture', 'DB_PASSWORD' => 'ephemeral-test-only',
            'DB_SCHEMA' => 'sicovi', 'DB_URL' => '', 'DB_SSLMODE' => 'require', 'SESSION_DRIVER' => 'database',
            'SESSION_SECURE_COOKIE' => 'true', 'SESSION_ENCRYPT' => 'true', 'CACHE_STORE' => 'database',
            'QUEUE_CONNECTION' => 'sync', 'SICOVI_DEMO_ACCOUNTS' => 'false', 'LOG_CHANNEL' => 'stderr', 'TRUSTED_PROXIES' => '*',
        ];
    }
}
