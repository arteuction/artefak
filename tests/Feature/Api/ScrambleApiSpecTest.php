<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Tests\TestCase;

/**
 * Phase 84 — Scramble OpenAPI spec tests.
 *
 * Verifies that the spec endpoint responds and the spec shape is valid.
 * The spec is generated from live route introspection — these tests are
 * intentionally lightweight so they do not fail on every route addition.
 */
final class ScrambleApiSpecTest extends TestCase
{
    public function test_docs_json_endpoint_returns_valid_openapi_spec(): void
    {
        $response = $this->get('/docs/api.json');

        $response->assertOk();
        $response->assertJsonStructure([
            'openapi',
            'info' => ['title', 'version'],
            'paths',
        ]);
        $this->assertSame('3.1.0', $response->json('openapi'));
    }

    public function test_docs_ui_endpoint_is_reachable(): void
    {
        $this->get('/docs/api')->assertOk();
    }

    public function test_spec_includes_artworks_routes(): void
    {
        $response = $this->get('/docs/api.json')->assertOk();

        $paths = $response->json('paths');
        $this->assertNotEmpty($paths, 'OpenAPI spec must contain at least one path.');

        $artworkPaths = collect(array_keys($paths))
            ->filter(fn ($p) => str_contains($p, 'artwork'));

        $this->assertGreaterThan(0, $artworkPaths->count(),
            'Spec must include artwork-related paths.');
    }

    public function test_spec_does_not_expose_webhook_endpoint(): void
    {
        $response = $this->get('/docs/api.json')->assertOk();
        $paths    = array_keys($response->json('paths') ?? []);

        $webhookPaths = array_filter($paths, fn ($p) => str_contains($p, 'webhook'));

        $this->assertEmpty($webhookPaths,
            'Stripe webhook endpoints must be excluded from the public spec.');
    }
}
