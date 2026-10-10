<?php

declare(strict_types=1);

namespace App\Domain\Provenance;

use App\Models\ArtLot;
use App\Models\Artwork;
use Illuminate\Support\Facades\DB;

/**
 * Builds a W3C PROV-O JSON-LD document for an ArtLot's lifecycle.
 *
 * PROV-O concepts used:
 *   prov:Entity   — the Artwork and each ArtLot state snapshot
 *   prov:Activity — domain events (offer.submitted, payment.confirmed, etc.)
 *   prov:Agent    — users / galleries that participated
 *
 * The returned array is ready for json_encode() and should be served with
 * Content-Type: application/ld+json.
 */
final class BuildProvenanceGraph
{
    private const CONTEXT = [
        'prov' => 'http://www.w3.org/ns/prov#',
        'xsd'  => 'http://www.w3.org/2001/XMLSchema#',
        'arto' => 'https://arteuction.example/vocab#',
    ];

    public function execute(ArtLot $lot): array
    {
        $artwork = $lot->artwork;
        $events  = DB::table('domain_events')
            ->where('aggregate_type', 'ArtLot')
            ->where('aggregate_id', $lot->id)
            ->orderBy('id')
            ->get();

        $graph = [];

        // Artwork entity
        if ($artwork) {
            $graph[] = $this->artworkEntity($artwork);
        }

        // ArtLot entity
        $graph[] = $this->artLotEntity($lot, $artwork);

        // Per-event Activities + Agents
        foreach ($events as $event) {
            $payload = is_string($event->payload)
                ? json_decode($event->payload, true)
                : (array) $event->payload;

            $graph[] = $this->eventActivity($lot, $event, $payload);

            foreach ($this->agentsFromPayload($payload) as $agent) {
                $graph[] = $agent;
            }
        }

        // Deduplicate agents by @id
        $seen  = [];
        $dedup = [];
        foreach ($graph as $node) {
            $id = $node['@id'] ?? null;
            if ($id === null || ! isset($seen[$id])) {
                $dedup[] = $node;
                if ($id !== null) {
                    $seen[$id] = true;
                }
            }
        }

        return [
            '@context' => self::CONTEXT,
            '@graph'   => $dedup,
        ];
    }

    private function artworkEntity(Artwork $artwork): array
    {
        return [
            '@id'    => "arto:artwork/{$artwork->id}",
            '@type'  => ['prov:Entity', 'arto:Artwork'],
            'prov:wasAttributedTo' => ["@id" => "arto:artist/{$artwork->user_id}"],
            'arto:title'           => $artwork->title,
            'arto:createdAt'       => $artwork->created_at?->toAtomString(),
        ];
    }

    private function artLotEntity(ArtLot $lot, ?Artwork $artwork): array
    {
        $entity = [
            '@id'   => "arto:art-lot/{$lot->id}",
            '@type' => ['prov:Entity', 'arto:ArtLot'],
            'arto:status'    => $lot->status,
            'arto:createdAt' => $lot->created_at?->toAtomString(),
        ];

        if ($artwork) {
            $entity['prov:wasDerivedFrom'] = ['@id' => "arto:artwork/{$artwork->id}"];
        }

        return $entity;
    }

    private function eventActivity(ArtLot $lot, object $event, array $payload): array
    {
        $activityId = "arto:activity/domain-event/{$event->id}";

        $activity = [
            '@id'    => $activityId,
            '@type'  => ['prov:Activity', "arto:{$this->sanitizeType($event->event_type)}"],
            'prov:used'       => [['@id' => "arto:art-lot/{$lot->id}"]],
            'prov:endedAtTime' => [
                '@type'  => 'xsd:dateTime',
                '@value' => $event->created_at,
            ],
        ];

        // Link agents that performed this activity
        foreach ($this->agentIdsFromPayload($payload) as $agentId) {
            $activity['prov:wasAssociatedWith'][] = ['@id' => $agentId];
        }

        return $activity;
    }

    /**
     * @return array<array<string,mixed>>
     */
    private function agentsFromPayload(array $payload): array
    {
        $agents = [];
        foreach (['buyer_id', 'seller_id', 'gallery_id', 'user_id'] as $key) {
            if (! empty($payload[$key])) {
                $type = str_contains($key, 'gallery') ? 'arto:Gallery' : 'arto:User';
                $prefix = str_contains($key, 'gallery') ? 'gallery' : 'user';
                $agents[] = [
                    '@id'   => "arto:{$prefix}/{$payload[$key]}",
                    '@type' => ['prov:Agent', $type],
                ];
            }
        }
        return $agents;
    }

    /** @return string[] */
    private function agentIdsFromPayload(array $payload): array
    {
        $ids = [];
        foreach (['buyer_id', 'seller_id', 'gallery_id', 'user_id'] as $key) {
            if (! empty($payload[$key])) {
                $prefix = str_contains($key, 'gallery') ? 'gallery' : 'user';
                $ids[]  = "arto:{$prefix}/{$payload[$key]}";
            }
        }
        return $ids;
    }

    private function sanitizeType(string $eventType): string
    {
        // 'offer.submitted' → 'OfferSubmitted'
        return implode('', array_map(
            fn (string $part) => ucfirst($part),
            preg_split('/[.\-_]/', $eventType) ?: [$eventType],
        ));
    }
}
