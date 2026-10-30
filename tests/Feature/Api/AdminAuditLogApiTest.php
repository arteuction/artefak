<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for admin audit log endpoints:
 *   GET /api/v1/admin/audit-log
 *   GET /api/v1/admin/audit-log/{entry}
 */
final class AdminAuditLogApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function buyer(): User
    {
        return User::factory()->create(['role' => 'buyer']);
    }

    private function logEntry(User $actor, string $action = 'test.action', string $subjectType = 'App\\Models\\User'): AdminAuditLog
    {
        return AdminAuditLog::create([
            'actor_id'     => $actor->id,
            'subject_type' => $subjectType,
            'subject_id'   => $actor->id,
            'action'       => $action,
            'payload'      => ['test' => true],
        ]);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_list_audit_log(): void
    {
        $this->getJson('/api/v1/admin/audit-log')->assertStatus(401);
    }

    public function test_buyer_cannot_list_audit_log(): void
    {
        $this->actingAs($this->buyer())
            ->getJson('/api/v1/admin/audit-log')
            ->assertStatus(403);
    }

    public function test_admin_can_list_audit_log(): void
    {
        $admin = $this->admin();
        $this->logEntry($admin);
        $this->logEntry($admin);

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/admin/audit-log')
            ->assertOk();

        $this->assertSame(2, $response->json('total'));
    }

    public function test_audit_log_returns_empty_when_no_entries(): void
    {
        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/audit-log')
            ->assertOk();

        $this->assertSame(0, $response->json('total'));
    }

    public function test_audit_log_can_filter_by_action(): void
    {
        $admin = $this->admin();
        $this->logEntry($admin, 'user.updated');
        $this->logEntry($admin, 'auction.closed');

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/admin/audit-log?action=user.updated')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('user.updated', $response->json('data.0.action'));
    }

    public function test_audit_log_can_filter_by_actor_id(): void
    {
        $admin1 = $this->admin();
        $admin2 = $this->admin();

        $this->logEntry($admin1);
        $this->logEntry($admin2);

        $response = $this->actingAs($admin1)
            ->getJson("/api/v1/admin/audit-log?actor_id={$admin1->id}")
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
    }

    public function test_audit_log_can_filter_by_subject_type(): void
    {
        $admin = $this->admin();
        $this->logEntry($admin, 'action', 'App\\Models\\User');
        $this->logEntry($admin, 'action', 'App\\Models\\Auction');

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/admin/audit-log?subject_type=' . urlencode('App\\Models\\Auction'))
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_show_audit_entry(): void
    {
        $admin = $this->admin();
        $entry = $this->logEntry($admin);

        $this->getJson("/api/v1/admin/audit-log/{$entry->id}")->assertStatus(401);
    }

    public function test_buyer_cannot_show_audit_entry(): void
    {
        $admin = $this->admin();
        $entry = $this->logEntry($admin);

        $this->actingAs($this->buyer())
            ->getJson("/api/v1/admin/audit-log/{$entry->id}")
            ->assertStatus(403);
    }

    public function test_admin_can_show_audit_entry(): void
    {
        $admin = $this->admin();
        $entry = $this->logEntry($admin, 'sdg_claim.approved');

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/admin/audit-log/{$entry->id}")
            ->assertOk();

        $this->assertSame($entry->id, $response->json('data.id'));
        $this->assertSame('sdg_claim.approved', $response->json('data.action'));
    }

    public function test_show_returns_404_for_unknown_entry(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/audit-log/99999999')
            ->assertStatus(404);
    }
}
