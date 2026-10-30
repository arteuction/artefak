<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use App\Domain\Governance\RecordConsent;
use App\Models\GovernancePolicy;
use App\Models\User;
use App\Models\UserConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Unit tests for the RecordConsent domain action.
 */
final class RecordConsentTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['role' => 'buyer']);
    }

    private function policy(bool $isActive = true): GovernancePolicy
    {
        return GovernancePolicy::create([
            'type'           => 'terms_of_service',
            'version'        => '1.' . uniqid(),
            'effective_from' => now()->subDay(),
            'is_active'      => $isActive,
        ]);
    }

    private function fakeRequest(): Request
    {
        return Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR'   => '127.0.0.1',
            'HTTP_USER_AGENT' => 'TestAgent/1.0',
        ]);
    }

    // ── execute (record consent) ──────────────────────────────────────────────

    public function test_execute_creates_consent_row(): void
    {
        $user    = $this->user();
        $policy  = $this->policy();
        $request = $this->fakeRequest();

        (new RecordConsent())->execute($user, $policy, $request);

        $this->assertDatabaseHas('user_consents', [
            'user_id'              => $user->id,
            'governance_policy_id' => $policy->id,
        ]);
    }

    public function test_execute_returns_consent_instance(): void
    {
        $user    = $this->user();
        $policy  = $this->policy();
        $request = $this->fakeRequest();

        $consent = (new RecordConsent())->execute($user, $policy, $request);

        $this->assertInstanceOf(UserConsent::class, $consent);
        $this->assertSame($user->id, $consent->user_id);
        $this->assertSame($policy->id, $consent->governance_policy_id);
        $this->assertNotNull($consent->consented_at);
    }

    public function test_execute_is_idempotent_upserts_on_same_policy(): void
    {
        $user    = $this->user();
        $policy  = $this->policy();
        $request = $this->fakeRequest();

        (new RecordConsent())->execute($user, $policy, $request);
        (new RecordConsent())->execute($user, $policy, $request);

        $this->assertSame(
            1,
            UserConsent::where('user_id', $user->id)
                ->where('governance_policy_id', $policy->id)
                ->count()
        );
    }

    public function test_execute_re_records_consent_after_withdrawal(): void
    {
        $user    = $this->user();
        $policy  = $this->policy();
        $request = $this->fakeRequest();

        (new RecordConsent())->execute($user, $policy, $request);
        (new RecordConsent())->withdraw($user, $policy);

        // Re-recording should clear withdrawn_at
        (new RecordConsent())->execute($user, $policy, $request);

        $consent = UserConsent::where('user_id', $user->id)
            ->where('governance_policy_id', $policy->id)
            ->first();

        $this->assertNull($consent->withdrawn_at);
    }

    public function test_execute_throws_for_inactive_policy(): void
    {
        $user    = $this->user();
        $policy  = $this->policy(isActive: false);
        $request = $this->fakeRequest();

        $this->expectException(InvalidArgumentException::class);

        (new RecordConsent())->execute($user, $policy, $request);
    }

    // ── withdraw ─────────────────────────────────────────────────────────────

    public function test_withdraw_sets_withdrawn_at(): void
    {
        $user    = $this->user();
        $policy  = $this->policy();
        $request = $this->fakeRequest();

        (new RecordConsent())->execute($user, $policy, $request);
        (new RecordConsent())->withdraw($user, $policy);

        $consent = UserConsent::where('user_id', $user->id)
            ->where('governance_policy_id', $policy->id)
            ->first();

        $this->assertNotNull($consent->withdrawn_at);
    }

    public function test_withdraw_is_idempotent_when_no_consent_exists(): void
    {
        $user   = $this->user();
        $policy = $this->policy();

        // Should not throw even when there's nothing to withdraw
        (new RecordConsent())->withdraw($user, $policy);

        $this->assertDatabaseMissing('user_consents', [
            'user_id'              => $user->id,
            'governance_policy_id' => $policy->id,
        ]);
    }

    public function test_withdraw_does_not_withdraw_already_withdrawn_consent(): void
    {
        $user    = $this->user();
        $policy  = $this->policy();
        $request = $this->fakeRequest();

        (new RecordConsent())->execute($user, $policy, $request);
        (new RecordConsent())->withdraw($user, $policy);

        $firstWithdrawnAt = UserConsent::where('user_id', $user->id)
            ->where('governance_policy_id', $policy->id)
            ->value('withdrawn_at');

        // Second withdraw should not overwrite the original timestamp
        (new RecordConsent())->withdraw($user, $policy);

        $secondWithdrawnAt = UserConsent::where('user_id', $user->id)
            ->where('governance_policy_id', $policy->id)
            ->value('withdrawn_at');

        $this->assertEquals($firstWithdrawnAt, $secondWithdrawnAt);
    }
}
