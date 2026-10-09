<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Tests\TestCase;

/**
 * Phase 88 — TypeScript API contract generation tests.
 *
 * These tests verify that the committed OpenAPI spec and generated TypeScript
 * types are present and internally consistent.  The spec is authoritative;
 * the generated types are derived from it and must be kept in sync.
 */
final class TypeContractsTest extends TestCase
{
    private string $specPath;
    private string $typesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->specPath  = base_path('resources/js/api/openapi.json');
        $this->typesPath = base_path('resources/js/api/generated/types.gen.ts');
    }

    public function test_openapi_spec_file_exists(): void
    {
        $this->assertFileExists($this->specPath,
            'resources/js/api/openapi.json must be committed — run: php artisan scramble:export');
    }

    public function test_openapi_spec_is_valid_json(): void
    {
        $this->assertFileExists($this->specPath);
        $json = json_decode(file_get_contents($this->specPath), true);

        $this->assertNotNull($json, 'openapi.json must be valid JSON');
        $this->assertSame('3.1.0', $json['openapi'] ?? null,
            'spec must declare OpenAPI 3.1.0');
        $this->assertArrayHasKey('paths', $json);
        $this->assertArrayHasKey('info',  $json);
    }

    public function test_generated_types_file_exists(): void
    {
        $this->assertFileExists($this->typesPath,
            'resources/js/api/generated/types.gen.ts must be committed — run: npm run generate:api-types');
    }

    public function test_generated_types_cover_core_domain_entities(): void
    {
        $this->assertFileExists($this->typesPath);
        $content = file_get_contents($this->typesPath);

        foreach (['Artwork', 'ArtLot', 'Auction', 'Gallery', 'AdminAuditLog'] as $entity) {
            $this->assertStringContainsString(
                "export type {$entity}",
                $content,
                "types.gen.ts must export a {$entity} type"
            );
        }
    }

    public function test_spec_path_count_matches_expected_minimum(): void
    {
        $json  = json_decode(file_get_contents($this->specPath), true);
        $count = count($json['paths'] ?? []);

        $this->assertGreaterThan(50, $count,
            "OpenAPI spec must document at least 50 paths; found {$count}.");
    }
}
