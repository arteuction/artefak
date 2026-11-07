/**
 * Phase 0.3B — 50-concurrent-bid load test
 *
 * Fires N_CONCURRENT (default 50) simultaneous bid requests at a single
 * auction item and verifies:
 *   1. Exactly ONE bid is accepted (FOR UPDATE serialization holds at N=50)
 *   2. Every rejection carries a typed BidRejected error (no raw DB crashes)
 *   3. P50, P95, P99 latencies are recorded
 *   4. Deadlock rate is reported (not asserted — informational)
 *
 * To run standalone:
 *   DATABASE_URL="mysql://root:@127.0.0.1:3306/arteuction_test" \
 *     npx jest bid-load --verbose
 */

import { Test, TestingModule } from '@nestjs/testing';
import { PrismaService } from '../prisma.service';
import { BidConcurrencyService, BidRejected } from './bid-concurrency.service';

const HAS_DB = Boolean(process.env.DATABASE_URL);
const describeWithDb = HAS_DB ? describe : describe.skip;

const N_CONCURRENT = Number(process.env.LOAD_N ?? 50);
// Each concurrent bidder needs a different starting amount to avoid ties;
// amounts are spread across starting_bid + 0..N-1 increments.
const STARTING_BID = BigInt(50_000);   // 500 EUR in cents
const INCREMENT     = BigInt(1_000);   // 10 EUR increment

// ── helpers ──────────────────────────────────────────────────────────────────

function percentile(sorted: number[], p: number): number {
  if (sorted.length === 0) return 0;
  const idx = Math.ceil((p / 100) * sorted.length) - 1;
  return sorted[Math.max(0, Math.min(idx, sorted.length - 1))];
}

function fmtMs(ms: number): string {
  return `${ms.toFixed(1)} ms`;
}

// ── test seed state ───────────────────────────────────────────────────────────

let module: TestingModule;
let prisma: PrismaService;
let service: BidConcurrencyService;

// seeded IDs
let bidderIds: number[] = [];
let artworkId = 0;
let artLotId  = 0;
let auctionId = 0;
let itemId    = 0;

const SLUG_AUCTION = 'poc-load-test-auction';
const SLUG_ARTWORK = 'poc-load-test-artwork';

async function seed() {
  await prisma.$transaction(async (tx) => {
    await tx.$executeRaw`SET FOREIGN_KEY_CHECKS = 0`;

    // Clean previous run
    await tx.$executeRaw`
      DELETE FROM bids WHERE auction_item_id IN (
        SELECT ai.id FROM auction_items ai
        JOIN auctions a ON a.id = ai.auction_id
        WHERE a.slug = ${SLUG_AUCTION}
      )
    `;
    await tx.$executeRaw`
      DELETE FROM auction_items WHERE auction_id IN (
        SELECT id FROM auctions WHERE slug = ${SLUG_AUCTION}
      )
    `;
    await tx.$executeRaw`DELETE FROM auctions WHERE slug = ${SLUG_AUCTION}`;
    await tx.$executeRaw`
      DELETE FROM art_lots WHERE artwork_id IN (
        SELECT id FROM artworks WHERE slug = ${SLUG_ARTWORK}
      )
    `;
    await tx.$executeRaw`DELETE FROM artworks WHERE slug = ${SLUG_ARTWORK}`;
    await tx.$executeRaw`DELETE FROM users WHERE email LIKE 'poc_load_%@test.invalid'`;

    // Seed N bidders (one INSERT per row to stay within $executeRaw parameter limits)
    for (let i = 0; i < N_CONCURRENT; i++) {
      await tx.$executeRaw`
        INSERT IGNORE INTO users (name, email, password, role, created_at, updated_at)
        VALUES (${`Load Bidder ${i + 1}`}, ${`poc_load_${i + 1}@test.invalid`}, 'x', 'buyer', NOW(), NOW())
      `;
    }

    // Resolve bidder IDs
    const rows = await tx.$queryRaw<Array<{ id: number }>>`
      SELECT id FROM users WHERE email LIKE 'poc_load_%@test.invalid' ORDER BY id
    `;
    bidderIds = rows.map((r) => Number(r.id));

    // Artwork
    await tx.$executeRaw`
      INSERT INTO artworks (user_id, title, slug, medium, status, created_at, updated_at)
      VALUES (${bidderIds[0]}, 'PoC Load Artwork', ${SLUG_ARTWORK}, 'painting', 'in_auction', NOW(), NOW())
    `;
    const [aw] = await tx.$queryRaw<[{ id: number }]>`
      SELECT id FROM artworks WHERE slug = ${SLUG_ARTWORK} LIMIT 1
    `;
    artworkId = Number(aw.id);

    // Art lot
    await tx.$executeRaw`
      INSERT INTO art_lots (artwork_id, sale_mode, status, starting_bid_cents, currency, split_profile_key, created_at, updated_at)
      VALUES (${artworkId}, 'auction', 'active', ${Number(STARTING_BID)}, 'EUR', 'social_pilot_45_45_10', NOW(), NOW())
    `;
    const [al] = await tx.$queryRaw<[{ id: number }]>`
      SELECT id FROM art_lots WHERE artwork_id = ${artworkId} LIMIT 1
    `;
    artLotId = Number(al.id);

    // Auction (ends in 1 day)
    await tx.$executeRaw`
      INSERT INTO auctions (title, slug, starts_at, ends_at, status, created_at, updated_at)
      VALUES ('PoC Load Auction', ${SLUG_AUCTION}, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY), 'live', NOW(), NOW())
    `;
    const [auc] = await tx.$queryRaw<[{ id: number }]>`
      SELECT id FROM auctions WHERE slug = ${SLUG_AUCTION} LIMIT 1
    `;
    auctionId = Number(auc.id);

    // Auction item — increment 1 000 cents
    await tx.$executeRaw`
      INSERT INTO auction_items (auction_id, art_lot_id, lot_number, bid_increment_cents, status, created_at, updated_at)
      VALUES (${auctionId}, ${artLotId}, 1, ${Number(INCREMENT)}, 'open', NOW(), NOW())
    `;
    const [it] = await tx.$queryRaw<[{ id: number }]>`
      SELECT id FROM auction_items WHERE auction_id = ${auctionId} AND lot_number = 1 LIMIT 1
    `;
    itemId = Number(it.id);

    await tx.$executeRaw`SET FOREIGN_KEY_CHECKS = 1`;
  });
}

async function clean() {
  if (!itemId) return;
  await prisma.$executeRaw`DELETE FROM bids WHERE auction_item_id = ${itemId}`;
  await prisma.$executeRaw`DELETE FROM auction_items WHERE id = ${itemId}`;
  if (auctionId) await prisma.$executeRaw`DELETE FROM auctions WHERE id = ${auctionId}`;
  if (artLotId)  await prisma.$executeRaw`DELETE FROM art_lots WHERE id = ${artLotId}`;
  if (artworkId) await prisma.$executeRaw`DELETE FROM artworks WHERE id = ${artworkId}`;
  await prisma.$executeRaw`DELETE FROM users WHERE email LIKE 'poc_load_%@test.invalid'`;
}

// ── test suite ─────────────────────────────────────────────────────────────

describeWithDb(`Bid load test — ${N_CONCURRENT} concurrent bids`, () => {
  beforeAll(async () => {
    module = await Test.createTestingModule({
      providers: [PrismaService, BidConcurrencyService],
    }).compile();

    prisma  = module.get(PrismaService);
    service = module.get(BidConcurrencyService);
    await (prisma as any).onModuleInit?.();
    await seed();
  }, 60_000);

  afterAll(async () => {
    await clean();
    await (prisma as any).onModuleDestroy?.();
    await module.close();
  }, 30_000);

  it(
    `L1: exactly 1 accepted bid out of ${N_CONCURRENT} concurrent submissions`,
    async () => {
      // All bidders submit the SAME amount (STARTING_BID).
      // After the first bid is accepted, every other bid falls below the new
      // minimum (STARTING_BID + INCREMENT), so only 1 can succeed.
      // This is the FOR UPDATE serialization invariant under N=50 concurrency.
      const start = performance.now();

      const tasks = bidderIds.slice(0, N_CONCURRENT).map((bidderId) =>
        service.placeBid({
          auctionItemId: itemId,
          bidderId,
          amountCents: STARTING_BID,
        }).then((r) => ({ ok: true as const, r, ms: performance.now() - start }))
          .catch((e: unknown) => ({ ok: false as const, e, ms: performance.now() - start })),
      );

      const results = await Promise.all(tasks);

      const successes = results.filter((r) => r.ok);
      const failures  = results.filter((r) => !r.ok);

      // ── Core invariant ────────────────────────────────────────────────────
      expect(successes).toHaveLength(1);

      // ── All failures must be typed BidRejected (no raw DB crashes) ────────
      const untyped = failures.filter(
        (r) => !r.ok && !(r.e instanceof BidRejected),
      );
      if (untyped.length > 0) {
        console.error('Untyped errors:', untyped.map((r) => (!r.ok ? String(r.e) : '')));
      }
      expect(untyped).toHaveLength(0);

      // ── DB confirmation ───────────────────────────────────────────────────
      const acceptedCount = await prisma.bid.count({
        where: { auction_item_id: itemId, status: 'accepted' },
      });
      expect(acceptedCount).toBe(1);

      // ── Latency report ────────────────────────────────────────────────────
      const latencies = results.map((r) => r.ms).sort((a, b) => a - b);
      const p50  = percentile(latencies, 50);
      const p95  = percentile(latencies, 95);
      const p99  = percentile(latencies, 99);
      const pMax = latencies[latencies.length - 1] ?? 0;

      // Deadlocks are BidRejected errors containing "deadlock" or "serialization conflict"
      const deadlocks = failures.filter(
        (r) => !r.ok && r.e instanceof BidRejected &&
               (r.e.message.includes('deadlock') || r.e.message.includes('serialization conflict')),
      );
      const deadlockRate = (deadlocks.length / N_CONCURRENT) * 100;

      console.log(
        `\n  === Load test results (N=${N_CONCURRENT}) ===\n` +
        `  Accepted:      ${successes.length} / ${N_CONCURRENT}\n` +
        `  Rejected:      ${failures.length}  (${deadlocks.length} deadlocks, ${deadlockRate.toFixed(1)}%)\n` +
        `  Latency P50:   ${fmtMs(p50)}\n` +
        `  Latency P95:   ${fmtMs(p95)}\n` +
        `  Latency P99:   ${fmtMs(p99)}\n` +
        `  Latency max:   ${fmtMs(pMax)}\n`,
      );
    },
    120_000,
  );

  it(
    'L2: second run on same item — still exactly 1 winner (bid chain integrity)',
    async () => {
      // Reset: delete all bids and reopen the item
      await prisma.bid.deleteMany({ where: { auction_item_id: itemId } });
      await prisma.$executeRaw`UPDATE auction_items SET status = 'open' WHERE id = ${itemId}`;

      const results = await Promise.all(
        bidderIds.slice(0, N_CONCURRENT).map((bidderId) =>
          service.placeBid({ auctionItemId: itemId, bidderId, amountCents: STARTING_BID })
            .then(() => 'ok' as const)
            .catch(() => 'rejected' as const),
        ),
      );

      const accepted = results.filter((r) => r === 'ok');
      expect(accepted).toHaveLength(1);

      const dbCount = await prisma.bid.count({
        where: { auction_item_id: itemId, status: 'accepted' },
      });
      expect(dbCount).toBe(1);
    },
    120_000,
  );
});
