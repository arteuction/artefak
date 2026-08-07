<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0 smoke test — proves the clean L13 app boots and the tests are bound to
 * the real 'arteuction_test' connection (not just a matching config string).
 */
class Phase0SmokeTest extends TestCase
{
    public function test_application_boots_in_testing_env(): void
    {
        $this->assertSame('testing', app()->environment());
    }

    public function test_config_targets_the_test_database(): void
    {
        $conn = config('database.default');
        $this->assertSame('arteuction_test', (string) config("database.connections.{$conn}.database"));
    }

    public function test_live_connection_is_actually_the_test_database(): void
    {
        // Ask the SERVER which database the connection really landed on — this
        // catches a mismatch between config and the actual live connection.
        $row = DB::selectOne('SELECT DATABASE() AS db');
        $this->assertSame('arteuction_test', $row->db,
            'Live DB connection must be arteuction_test, got: ' . ($row->db ?? 'null'));
    }
}
