<?php

namespace Tests\Concerns;

use RuntimeException;

/**
 * HARD safety guard: refuses to run tests unless the active database is EXACTLY
 * the dedicated test database. Prevents RefreshDatabase / migrate:fresh from ever
 * touching a real (production/staging) database.
 *
 * Rules enforced (ALL must hold, else the suite aborts):
 *   1. APP_ENV must be 'testing'.
 *   2. The default connection's database name must be EXACTLY 'arteuction_test'
 *      (not merely a name containing '_test').
 *
 * Called from TestCase::refreshApplication(), AFTER parent::refreshApplication()
 * and BEFORE RefreshDatabase runs.
 */
trait GuardsAgainstProductionDatabase
{
    /** The one and only database tests are allowed to touch. */
    private const REQUIRED_TEST_DATABASE = 'arteuction_test';

    protected function guardDatabase(): void
    {
        $env = app()->environment();
        if ($env !== 'testing') {
            $this->abortUnsafe("APP_ENV is '{$env}', expected 'testing'.");
        }

        $connection = config('database.default');
        $database   = (string) config("database.connections.{$connection}.database");

        if ($database !== self::REQUIRED_TEST_DATABASE) {
            $this->abortUnsafe(
                "Refusing to test against database '{$database}' — must be exactly '"
                . self::REQUIRED_TEST_DATABASE . "'."
            );
        }
    }

    private function abortUnsafe(string $why): void
    {
        fwrite(STDERR, "\n\033[41m HARD DB GUARD: {$why} Aborting. \033[0m\n");
        throw new RuntimeException("Unsafe test database. {$why}");
    }
}
