<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\GuardsAgainstProductionDatabase;

/**
 * Base test case (Laravel 13). The production-DB guard runs inside
 * refreshApplication(), which the framework calls at the start of setUp()
 * BEFORE trait setup (incl. RefreshDatabase) — so no migration can run against
 * an unsafe database.
 *
 * NOTE: this file overlays the one `laravel new` generates — replace it after scaffolding.
 */
abstract class TestCase extends BaseTestCase
{
    use GuardsAgainstProductionDatabase;

    protected function refreshApplication(): void
    {
        parent::refreshApplication();
        $this->guardDatabase();
    }
}
