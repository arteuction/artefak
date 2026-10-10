<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use App\Domain\Governance\EraseUserData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Phase 144 — Privacy lifecycle verification.
 *
 * Validates GDPR Art. 17 erasure completeness:
 *   1. Name and email are pseudonymised (not hard-deleted).
 *   2. Password is cleared; remember_token is nulled.
 *   3. All Sanctum personal_access_tokens are revoked.
 *   4. All api_clients rows are soft-deleted and deactivated.
 *   5. All active user_consents are withdrawn (withdrawn_at set).
 *   6. A domain_events row is written with idempotency_key.
 *   7. Calling erase twice is idempotent (no duplicate domain event).
 *   8. Financial records (settlements, ledger_entries) are preserved.
 *   9. Admin accounts cannot be erased.
 *  10. Domain event payload does NOT contain the user's real PII.
 */
class ErasureLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private EraseUserData $eraser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user   = User::factory()->create([
            'name'  => 'Real Name',
            'email' => 'real@example.com',
            'role'  => 'artist',
        ]);
        $this->eraser = app(EraseUserData::class);
    }

    // ── Pseudonymisation ──────────────────────────────────────────────────────

    public function test_name_is_pseudonymised(): void
    {
        $this->eraser->execute($this->user);

        $fresh = $this->user->fresh();
        $this->assertStringStartsWith('erased_user_', $fresh->name);
        $this->assertStringNotContainsString('Real Name', $fresh->name);
    }

    public function test_email_is_pseudonymised(): void
    {
        $this->eraser->execute($this->user);

        $fresh = $this->user->fresh();
        $this->assertStringContainsString('@erased.invalid', $fresh->email);
        $this->assertStringNotContainsString('real@example.com', $fresh->email);
    }

    public function test_password_is_cleared(): void
    {
        $this->eraser->execute($this->user);

        // Read raw value — the Eloquent 'hashed' cast would re-hash on access
        $rawPassword = DB::table('users')->where('id', $this->user->id)->value('password');
        $this->assertSame('', (string) $rawPassword);
    }

    public function test_remember_token_is_nulled(): void
    {
        DB::table('users')->where('id', $this->user->id)->update(['remember_token' => 'token123']);

        $this->eraser->execute($this->user);

        $rawToken = DB::table('users')->where('id', $this->user->id)->value('remember_token');
        $this->assertNull($rawToken);
    }

    public function test_user_row_is_not_hard_deleted(): void
    {
        $this->eraser->execute($this->user);

        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    // ── Token revocation ──────────────────────────────────────────────────────

    public function test_sanctum_tokens_are_revoked(): void
    {
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id'   => $this->user->id,
            'name'           => 'api-token',
            'token'          => hash('sha256', 'plaintext1'),
            'abilities'      => '["*"]',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id'   => $this->user->id,
            'name'           => 'api-token-2',
            'token'          => hash('sha256', 'plaintext2'),
            'abilities'      => '["*"]',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $this->eraser->execute($this->user);

        $remaining = DB::table('personal_access_tokens')
            ->where('tokenable_id', $this->user->id)
            ->where('tokenable_type', User::class)
            ->count();

        $this->assertSame(0, $remaining, 'All Sanctum tokens must be deleted');
    }

    // ── API client deactivation ───────────────────────────────────────────────

    public function test_api_clients_are_deactivated_and_soft_deleted(): void
    {
        DB::table('api_clients')->insert([
            'user_id'    => $this->user->id,
            'name'       => 'My App',
            'key_hash'   => hash('sha256', 'key1'),
            'key_prefix' => substr(hash('sha256', 'key1'), 0, 12),
            'scopes'     => '["read"]',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->eraser->execute($this->user);

        $client = DB::table('api_clients')->where('user_id', $this->user->id)->first();
        $this->assertNotNull($client, 'api_clients row must not be hard-deleted (financial FK reference)');
        $this->assertFalse((bool) $client->is_active, 'api_client must be deactivated');
        $this->assertNotNull($client->deleted_at, 'api_client must be soft-deleted');
    }

    // ── Consent withdrawal ────────────────────────────────────────────────────

    public function test_active_consents_are_withdrawn(): void
    {
        $policyId = DB::table('governance_policies')->insertGetId([
            'type'           => 'terms_of_service',
            'version'        => '1.0',
            'is_active'      => true,
            'effective_from' => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        DB::table('user_consents')->insert([
            'user_id'              => $this->user->id,
            'governance_policy_id' => $policyId,
            'consented_at'         => now()->subDays(10),
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $this->eraser->execute($this->user);

        $consent = DB::table('user_consents')
            ->where('user_id', $this->user->id)
            ->first();

        $this->assertNotNull($consent->withdrawn_at, 'Consent must be withdrawn after erasure');
    }

    public function test_already_withdrawn_consents_are_not_double_withdrawn(): void
    {
        $policyId = DB::table('governance_policies')->insertGetId([
            'type'           => 'privacy_policy',
            'version'        => '1.0',
            'is_active'      => true,
            'effective_from' => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $withdrawnAt = now()->subDays(5)->toDateTimeString();
        DB::table('user_consents')->insert([
            'user_id'              => $this->user->id,
            'governance_policy_id' => $policyId,
            'consented_at'         => now()->subDays(20),
            'withdrawn_at'         => $withdrawnAt,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $this->eraser->execute($this->user);

        $consent = DB::table('user_consents')
            ->where('user_id', $this->user->id)
            ->first();

        // withdrawn_at must not change (already withdrawn)
        $this->assertSame($withdrawnAt, $consent->withdrawn_at);
    }

    // ── Domain event ─────────────────────────────────────────────────────────

    public function test_erasure_creates_domain_event(): void
    {
        $this->eraser->execute($this->user);

        $this->assertDatabaseHas('domain_events', [
            'aggregate_type' => 'User',
            'aggregate_id'   => $this->user->id,
            'event_type'     => 'user.erased',
        ]);
    }

    public function test_domain_event_payload_does_not_contain_real_pii(): void
    {
        $this->eraser->execute($this->user);

        $event = DB::table('domain_events')
            ->where('event_type', 'user.erased')
            ->where('aggregate_id', $this->user->id)
            ->first();

        $payload = $event->payload ?? '{}';
        $this->assertStringNotContainsString('real@example.com', $payload);
        $this->assertStringNotContainsString('Real Name', $payload);
    }

    public function test_domain_event_has_idempotency_key(): void
    {
        $this->eraser->execute($this->user);

        $event = DB::table('domain_events')
            ->where('event_type', 'user.erased')
            ->where('aggregate_id', $this->user->id)
            ->first();

        $this->assertSame("user.erased:{$this->user->id}", $event->idempotency_key);
    }

    // ── Idempotency ───────────────────────────────────────────────────────────

    public function test_calling_erase_twice_does_not_create_duplicate_domain_event(): void
    {
        $this->eraser->execute($this->user);

        // Second call — user is already pseudonymised; should not duplicate the event
        try {
            $this->eraser->execute($this->user->fresh());
        } catch (\Throwable) {
            // A constraint violation on idempotency_key is acceptable
        }

        $count = DB::table('domain_events')
            ->where('event_type', 'user.erased')
            ->where('aggregate_id', $this->user->id)
            ->count();

        $this->assertSame(1, $count, 'Exactly one user.erased event must exist');
    }

    // ── Financial record preservation ─────────────────────────────────────────

    public function test_settlements_are_preserved_after_erasure(): void
    {
        DB::table('settlements')->insert([
            'stripe_payment_intent_id' => 'pi_preserve_test_1',
            'stripe_event_id'          => 'evt_preserve_1',
            'gross_cents'              => 50000,
            'artist_cents'             => 22500,
            'fund_cents'               => 22500,
            'ops_cents'                => 5000,
            'currency'                 => 'EUR',
            'status'                   => 'completed',
            'profile_key'              => 'social_pilot_45_45_10',
            'profile_version'          => 1,
            'artist_bps'               => 4500,
            'fund_bps'                 => 4500,
            'ops_bps'                  => 1000,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        $this->eraser->execute($this->user);

        $this->assertDatabaseHas('settlements', [
            'stripe_payment_intent_id' => 'pi_preserve_test_1',
            'status'                   => 'completed',
        ]);
    }

    public function test_ledger_entries_are_preserved_after_erasure(): void
    {
        $settlementId = DB::table('settlements')->insertGetId([
            'stripe_payment_intent_id' => 'pi_ledger_preserve_1',
            'stripe_event_id'          => 'evt_ledger_1',
            'gross_cents'              => 10000,
            'artist_cents'             => 4500,
            'fund_cents'               => 4500,
            'ops_cents'                => 1000,
            'currency'                 => 'EUR',
            'status'                   => 'completed',
            'profile_key'              => 'social_pilot_45_45_10',
            'profile_version'          => 1,
            'artist_bps'               => 4500,
            'fund_bps'                 => 4500,
            'ops_bps'                  => 1000,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        DB::table('ledger_entries')->insert([
            'settlement_id' => $settlementId,
            'type'          => 'credit',
            'amount_cents'  => 4500,
            'currency'      => 'EUR',
            'note'          => 'artist share',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $this->eraser->execute($this->user);

        $this->assertDatabaseHas('ledger_entries', [
            'settlement_id' => $settlementId,
            'type'          => 'credit',
        ]);
    }

    // ── Admin guard ───────────────────────────────────────────────────────────

    public function test_admin_account_cannot_be_erased(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/[Aa]dmin/');

        $this->eraser->execute($admin);
    }
}
