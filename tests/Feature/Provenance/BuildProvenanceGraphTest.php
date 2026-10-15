<?php

declare(strict_types=1);

namespace Tests\Feature\Provenance;

use App\Domain\Provenance\BuildProvenanceGraph;
use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 180 — W3C PROV-O JSON-LD provenance graph builder.
 *
 * Verifies BuildProvenanceGraph produces a correct PROV-O document
 * for an ArtLot's lifecycle events.
 */
final class BuildProvenanceGraphTest extends TestCase
{
    use RefreshDatabase;

    private BuildProvenanceGraph $builder;
    private User $artist;
    private Artwork $artwork;
    private ArtLot $lot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = app(BuildProvenanceGraph::class);
        $this->artist  = User::factory()->create(['role' => 'artist']);

        $this->artwork = Artwork::create([
            'user_id' => $this->artist->id,
            'title'   => 'Morning Light',
            'slug'    => 'morning-light-' . Str::random(4),
            'status'  => 'listed',
        ]);

        $this->lot = ArtLot::create([
            'artwork_id' => $this->artwork->id,
            'sale_mode'  => 'sell_now',
            'status'     => 'active',
            'buy_now_price_cents' => 100_000_00,
            'currency'   => 'EUR',
        ]);
    }

    // ── Top-level structure ────────────────────────────────────────────────────

    public function test_output_has_prov_context(): void
    {
        $graph = $this->builder->execute($this->lot);

        $this->assertArrayHasKey('@context', $graph);
        $this->assertSame('http://www.w3.org/ns/prov#', $graph['@context']['prov']);
    }

    public function test_output_has_graph_key(): void
    {
        $graph = $this->builder->execute($this->lot);

        $this->assertArrayHasKey('@graph', $graph);
        $this->assertIsArray($graph['@graph']);
    }

    // ── Artwork entity ─────────────────────────────────────────────────────────

    public function test_graph_contains_artwork_entity(): void
    {
        $graph = $this->builder->execute($this->lot);
        $ids   = array_column($graph['@graph'], '@id');

        $this->assertContains("arto:artwork/{$this->artwork->id}", $ids);
    }

    public function test_artwork_entity_has_prov_entity_type(): void
    {
        $graph  = $this->builder->execute($this->lot);
        $node   = $this->findNode($graph['@graph'], "arto:artwork/{$this->artwork->id}");

        $this->assertContains('prov:Entity', $node['@type']);
        $this->assertContains('arto:Artwork', $node['@type']);
    }

    public function test_artwork_entity_has_title(): void
    {
        $graph = $this->builder->execute($this->lot);
        $node  = $this->findNode($graph['@graph'], "arto:artwork/{$this->artwork->id}");

        $this->assertSame('Morning Light', $node['arto:title']);
    }

    public function test_artwork_entity_attributes_to_artist(): void
    {
        $graph = $this->builder->execute($this->lot);
        $node  = $this->findNode($graph['@graph'], "arto:artwork/{$this->artwork->id}");

        $this->assertSame(
            [['@id' => "arto:artist/{$this->artist->id}"]],
            $node['prov:wasAttributedTo'],
        );
    }

    // ── ArtLot entity ──────────────────────────────────────────────────────────

    public function test_graph_contains_art_lot_entity(): void
    {
        $graph = $this->builder->execute($this->lot);
        $ids   = array_column($graph['@graph'], '@id');

        $this->assertContains("arto:art-lot/{$this->lot->id}", $ids);
    }

    public function test_art_lot_entity_derived_from_artwork(): void
    {
        $graph = $this->builder->execute($this->lot);
        $node  = $this->findNode($graph['@graph'], "arto:art-lot/{$this->lot->id}");

        $this->assertSame(
            ['@id' => "arto:artwork/{$this->artwork->id}"],
            $node['prov:wasDerivedFrom'],
        );
    }

    public function test_art_lot_entity_has_status(): void
    {
        $graph = $this->builder->execute($this->lot);
        $node  = $this->findNode($graph['@graph'], "arto:art-lot/{$this->lot->id}");

        $this->assertSame('active', $node['arto:status']);
    }

    // ── Domain events ──────────────────────────────────────────────────────────

    public function test_domain_events_become_activities(): void
    {
        DB::table('domain_events')->insert([
            'aggregate_type'  => 'ArtLot',
            'aggregate_id'    => $this->lot->id,
            'event_type'      => 'offer.submitted',
            'payload'         => json_encode(['buyer_id' => 99]),
            'idempotency_key' => 'test-prov-' . Str::uuid(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $graph   = $this->builder->execute($this->lot);
        $types   = collect($graph['@graph'])->pluck('@type')->flatten()->all();

        $this->assertContains('prov:Activity', $types);
    }

    public function test_activity_uses_art_lot(): void
    {
        DB::table('domain_events')->insert([
            'aggregate_type'  => 'ArtLot',
            'aggregate_id'    => $this->lot->id,
            'event_type'      => 'offer.submitted',
            'payload'         => json_encode([]),
            'idempotency_key' => 'test-prov-use-' . Str::uuid(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $graph    = $this->builder->execute($this->lot);
        $activity = collect($graph['@graph'])
            ->first(fn ($n) => in_array('prov:Activity', (array) ($n['@type'] ?? [])));

        $this->assertNotNull($activity);
        $this->assertSame(
            [['@id' => "arto:art-lot/{$this->lot->id}"]],
            $activity['prov:used'],
        );
    }

    public function test_buyer_id_in_payload_creates_user_agent(): void
    {
        DB::table('domain_events')->insert([
            'aggregate_type'  => 'ArtLot',
            'aggregate_id'    => $this->lot->id,
            'event_type'      => 'offer.submitted',
            'payload'         => json_encode(['buyer_id' => 42]),
            'idempotency_key' => 'test-prov-buyer-' . Str::uuid(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $graph = $this->builder->execute($this->lot);
        $ids   = array_column($graph['@graph'], '@id');

        $this->assertContains('arto:user/42', $ids);
    }

    public function test_gallery_id_in_payload_creates_gallery_agent(): void
    {
        DB::table('domain_events')->insert([
            'aggregate_type'  => 'ArtLot',
            'aggregate_id'    => $this->lot->id,
            'event_type'      => 'offer.submitted',
            'payload'         => json_encode(['gallery_id' => 7]),
            'idempotency_key' => 'test-prov-gal-' . Str::uuid(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $graph = $this->builder->execute($this->lot);
        $ids   = array_column($graph['@graph'], '@id');

        $this->assertContains('arto:gallery/7', $ids);
    }

    // ── Agent deduplication ────────────────────────────────────────────────────

    public function test_same_agent_in_two_events_appears_once(): void
    {
        foreach (['event-a-' . Str::uuid(), 'event-b-' . Str::uuid()] as $key) {
            DB::table('domain_events')->insert([
                'aggregate_type'  => 'ArtLot',
                'aggregate_id'    => $this->lot->id,
                'event_type'      => 'offer.submitted',
                'payload'         => json_encode(['buyer_id' => 55]),
                'idempotency_key' => $key,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        $graph       = $this->builder->execute($this->lot);
        $agentNodes  = array_filter(
            $graph['@graph'],
            fn ($n) => ($n['@id'] ?? '') === 'arto:user/55',
        );

        $this->assertCount(1, $agentNodes, 'Agent must be deduplicated across events');
    }

    // ── No events ─────────────────────────────────────────────────────────────

    public function test_graph_with_no_events_contains_artwork_and_lot_only(): void
    {
        $graph = $this->builder->execute($this->lot);

        // 2 nodes: artwork entity + art-lot entity
        $this->assertCount(2, $graph['@graph']);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function findNode(array $graph, string $id): array
    {
        foreach ($graph as $node) {
            if (($node['@id'] ?? null) === $id) {
                return $node;
            }
        }
        $this->fail("Node with @id '{$id}' not found in graph");
    }
}
