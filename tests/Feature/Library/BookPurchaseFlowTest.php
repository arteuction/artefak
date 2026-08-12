<?php

declare(strict_types=1);

namespace Tests\Feature\Library;

use App\Domain\Library\BookSettlementCalculator;
use App\Domain\Library\CreateBookSettlement;
use App\Domain\Library\FinalizePaidBookPurchase;
use App\Domain\Library\GrantBookEntitlement;
use App\Domain\Library\RevokeBookEntitlement;
use App\Models\Book;
use App\Models\BookAuthor;
use App\Models\BookEntitlement;
use App\Models\BookPurchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BookPurchaseFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;
    private User $author;
    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer  = User::factory()->create(['role' => 'buyer']);
        $this->author = User::factory()->create(['role' => 'artist']);

        $this->book = Book::create([
            'owner_id'         => $this->author->id,
            'title'            => 'Clean Code Principles',
            'slug'             => 'clean-code-principles',
            'price_cents'      => 1200,
            'currency'         => 'EUR',
            'is_free'          => false,
            'status'           => 'published',
            'preview_pages'    => 10,
        ]);
    }

    private function action(): FinalizePaidBookPurchase
    {
        return new FinalizePaidBookPurchase(
            new BookSettlementCalculator(),
            new CreateBookSettlement(),
            new GrantBookEntitlement(),
        );
    }

    private function makePendingPurchase(
        int    $grossCents   = 1200,
        int    $taxCents     = 0,
        int    $feeCents     = 0,
        string $piId         = 'pi_book_test_001',
        string $idemKey      = 'idem_001',
    ): BookPurchase {
        $splitBase = $grossCents - $taxCents - $feeCents;

        return BookPurchase::create([
            'buyer_id'                  => $this->buyer->id,
            'book_id'                   => $this->book->id,
            'status'                    => 'pending',
            'price_cents'               => $grossCents,
            'currency'                  => 'EUR',
            'gross_cents'               => $grossCents,
            'tax_cents'                 => $taxCents,
            'fee_cents'                 => $feeCents,
            'split_base_cents'          => $splitBase,
            'profile_key'               => 'library_80_10_10',
            'profile_version'           => 1,
            'author_bps'                => 8000,
            'fund_bps'                  => 1000,
            'ops_bps'                   => 1000,
            'stripe_payment_intent_id'  => $piId,
            'idempotency_key'           => $idemKey,
        ]);
    }

    // ── Happy path ────────────────────────────────────────────────

    public function test_marks_purchase_paid(): void
    {
        $purchase = $this->makePendingPurchase();

        $result = $this->action()->execute('pi_book_test_001', 'evt_001', 1200, 'EUR');

        $this->assertSame('paid', $result->status);
        $this->assertNotNull($result->paid_at);
    }

    public function test_grants_entitlement_on_payment(): void
    {
        $purchase = $this->makePendingPurchase();

        $this->action()->execute('pi_book_test_001', 'evt_001', 1200, 'EUR');

        $this->assertDatabaseHas('book_entitlements', [
            'user_id'          => $this->buyer->id,
            'book_id'          => $this->book->id,
            'source'           => 'paid',
            'revoked_at'       => null,
        ]);
    }

    public function test_creates_settlement_with_correct_80_10_10_split(): void
    {
        $this->makePendingPurchase(grossCents: 1000);

        $this->action()->execute('pi_book_test_001', 'evt_001', 1000, 'EUR');

        // Split base = 1000. 80/10/10 → author=800, fund=100, ops=100
        $this->assertDatabaseHas('settlements', [
            'stripe_payment_intent_id' => 'pi_book_test_001',
            'gross_cents'              => 1000,
            'artist_cents'             => 800,
            'fund_cents'               => 100,
            'ops_cents'                => 100,
            'profile_key'              => 'library_80_10_10',
        ]);
    }

    public function test_creates_ledger_credits_for_all_three_parties(): void
    {
        $this->makePendingPurchase();

        $this->action()->execute('pi_book_test_001', 'evt_001', 1200, 'EUR');

        // 1 author + 1 fund + 1 ops = 3 credits
        $settlementId = \DB::table('settlements')
            ->where('stripe_payment_intent_id', 'pi_book_test_001')
            ->value('id');

        $this->assertSame(3,
            \DB::table('ledger_entries')
               ->where('settlement_id', $settlementId)
               ->where('type', 'credit')
               ->count()
        );
    }

    // ── Split base (gross - tax - fee) ─────────────────────────────

    public function test_split_base_excludes_tax_and_fee(): void
    {
        // gross=1200, tax=200, fee=24 → split_base=976
        $this->makePendingPurchase(grossCents: 1200, taxCents: 200, feeCents: 24,
            piId: 'pi_tax_test', idemKey: 'idem_tax');

        $this->action()->execute('pi_tax_test', 'evt_tax', 1200, 'EUR');

        // 80% of 976 = 780 (intdiv), fund=97, ops=976-780-97=99
        $this->assertDatabaseHas('settlements', [
            'stripe_payment_intent_id' => 'pi_tax_test',
            'gross_cents'              => 976,
            'artist_cents'             => 780,
            'fund_cents'               => 97,
        ]);
    }

    // ── Co-authors ────────────────────────────────────────────────

    public function test_co_author_split_uses_share_bps(): void
    {
        $author2 = User::factory()->create(['role' => 'artist']);

        // 60/40 split of the author pool
        BookAuthor::create(['book_id' => $this->book->id, 'author_id' => $this->author->id, 'share_bps' => 6000, 'sort_order' => 1]);
        BookAuthor::create(['book_id' => $this->book->id, 'author_id' => $author2->id,       'share_bps' => 4000, 'sort_order' => 2]);

        $this->makePendingPurchase(grossCents: 1000, piId: 'pi_coauthor', idemKey: 'idem_co');

        $this->action()->execute('pi_coauthor', 'evt_co', 1000, 'EUR');

        // Author pool = 800. Author1 = floor(800×6000/10000)=480. Author2=320.
        $settlementId = \DB::table('settlements')
            ->where('stripe_payment_intent_id', 'pi_coauthor')
            ->value('id');

        $authorLines = \DB::table('settlement_lines')
            ->where('settlement_id', $settlementId)
            ->where('recipient_type', 'author')
            ->orderBy('amount_cents', 'desc')
            ->get();

        $this->assertCount(2, $authorLines);
        $this->assertSame(480, (int) $authorLines[0]->amount_cents);
        $this->assertSame(320, (int) $authorLines[1]->amount_cents);
    }

    // ── Idempotency ───────────────────────────────────────────────

    public function test_duplicate_webhook_is_safe_noop(): void
    {
        $this->makePendingPurchase();

        $this->action()->execute('pi_book_test_001', 'evt_001', 1200, 'EUR');
        $this->action()->execute('pi_book_test_001', 'evt_001', 1200, 'EUR'); // duplicate

        $this->assertDatabaseCount('book_purchases', 1);
        $this->assertDatabaseCount('book_entitlements', 1);
        $this->assertDatabaseCount('settlements', 1);
        $this->assertDatabaseCount('ledger_entries', 3);
    }

    // ── Error paths ───────────────────────────────────────────────

    public function test_amount_mismatch_throws_without_side_effects(): void
    {
        $this->makePendingPurchase(grossCents: 1200);

        $this->expectException(RuntimeException::class);

        $this->action()->execute('pi_book_test_001', 'evt_001', 999, 'EUR');

        $this->assertDatabaseMissing('settlements', ['stripe_payment_intent_id' => 'pi_book_test_001']);
        $this->assertDatabaseMissing('book_entitlements', ['user_id' => $this->buyer->id]);
        $this->assertSame('pending', BookPurchase::first()->status);
    }

    public function test_currency_mismatch_throws(): void
    {
        $this->makePendingPurchase();

        $this->expectException(RuntimeException::class);

        $this->action()->execute('pi_book_test_001', 'evt_001', 1200, 'USD');
    }

    public function test_unknown_payment_intent_throws(): void
    {
        $this->expectException(RuntimeException::class);

        $this->action()->execute('pi_does_not_exist', 'evt_x', 1200, 'EUR');
    }

    // ── Free books ────────────────────────────────────────────────

    public function test_free_book_grants_entitlement_without_purchase(): void
    {
        $freeBook = Book::create([
            'owner_id'    => $this->author->id,
            'title'       => 'Free Guide',
            'slug'        => 'free-guide',
            'price_cents' => 0,
            'currency'    => 'EUR',
            'is_free'     => true,
            'status'      => 'published',
        ]);

        (new GrantBookEntitlement())->forFreeBook($this->buyer, $freeBook);

        $this->assertDatabaseHas('book_entitlements', [
            'user_id'          => $this->buyer->id,
            'book_id'          => $freeBook->id,
            'source'           => 'free',
            'book_purchase_id' => null,
            'revoked_at'       => null,
        ]);

        // No purchase or settlement rows
        $this->assertDatabaseCount('book_purchases', 0);
        $this->assertDatabaseCount('settlements', 0);
    }

    public function test_free_entitlement_is_idempotent(): void
    {
        $freeBook = Book::create([
            'owner_id'    => $this->author->id,
            'title'       => 'Free Guide 2',
            'slug'        => 'free-guide-2',
            'price_cents' => 0,
            'currency'    => 'EUR',
            'is_free'     => true,
            'status'      => 'published',
        ]);

        $grant = new GrantBookEntitlement();
        $grant->forFreeBook($this->buyer, $freeBook);
        $grant->forFreeBook($this->buyer, $freeBook);

        $this->assertDatabaseCount('book_entitlements', 1);
    }

    // ── Revoke on refund ──────────────────────────────────────────

    public function test_revoke_entitlement_on_full_refund(): void
    {
        $purchase = $this->makePendingPurchase();
        $this->action()->execute('pi_book_test_001', 'evt_001', 1200, 'EUR');

        $purchase->refresh();
        (new RevokeBookEntitlement())->forRefund($purchase);

        $entitlement = BookEntitlement::where('user_id', $this->buyer->id)
            ->where('book_id', $this->book->id)
            ->first();

        $this->assertNotNull($entitlement->revoked_at);
    }

    public function test_revoke_is_idempotent(): void
    {
        $purchase = $this->makePendingPurchase();
        $this->action()->execute('pi_book_test_001', 'evt_001', 1200, 'EUR');
        $purchase->refresh();

        $revoke = new RevokeBookEntitlement();
        $revoke->forRefund($purchase);
        $revoke->forRefund($purchase); // second call should not error

        $this->assertDatabaseCount('book_entitlements', 1);
    }
}
