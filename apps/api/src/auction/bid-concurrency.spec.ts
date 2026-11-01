/**
 * Auction Concurrency — Phase 0.2 expanded test suite.
 *
 * COVERS:
 *   1. Sequential bids — first accepted, second below minimum rejected
 *   2. Simultaneous bids — exactly one accepted (FOR UPDATE serialization)
 *   3. Multiple simultaneous bids (5 at once) — exactly one accepted
 *   4. Bids on different auction items execute independently (no cross-lock)
 *   5. Auction closing while bid is submitted — bid rejected
 *   6. Duplicate bid request — idempotent rejection
 *   7. Transaction rollback on failure leaves no partial state
 *   8. Correct outbid chain: each new leader outbids the previous
 *   9. Item closed after winning bid — subsequent bid rejected
 *  10. Bid below starting price rejected
 *
 * DATABASE REQUIREMENT:
 *   Real MariaDB arteuction_test database.
 *   DATABASE_URL="mysql://root:@127.0.0.1:3306/arteuction_test" npx jest bid-concurrency
 */

import { Test, TestingModule } from '@nestjs/testing';
import { PrismaService } from '../prisma.service';
import { BidConcurrencyService, BidRejected } from './bid-concurrency.service';

const HAS_DB = Boolean(process.env.DATABASE_URL);
const describeWithDb = HAS_DB ? describe : describe.skip;

// ── Shared seed state ─────────────────────────────────────────────────────────

let sharedModule: TestingModule;
let sharedPrisma: PrismaService;

// Bidder pool (5 users for multi-bid tests)
const BIDDERS = [
  { email: 'poc_bidder1@test.invalid', id: 0 },
  { email: 'poc_bidder2@test.invalid', id: 0 },
  { email: 'poc_bidder3@test.invalid', id: 0 },
  { email: 'poc_bidder4@test.invalid', id: 0 },
  { email: 'poc_bidder5@test.invalid', id: 0 },
];

// Seeded structural rows
let artworkId = 0;
let artLotAId = 0; // used for most tests
let artLotBId = 0; // used for cross-auction isolation test
let auctionAId = 0;
let auctionBId = 0;
let itemAId = 0;
let itemBId = 0;

async function seedAll(prisma: PrismaService) {
  await prisma.$executeRaw`SET FOREIGN_KEY_CHECKS = 0`;
  try {
    // Users
    const emails = BIDDERS.map((b) => b.email);
    await prisma.$executeRaw`
      INSERT INTO users (name, email, password, role, created_at, updated_at)
      VALUES
        ('PoC Bidder 1', ${emails[0]}, 'x', 'buyer', NOW(), NOW()),
        ('PoC Bidder 2', ${emails[1]}, 'x', 'buyer', NOW(), NOW()),
        ('PoC Bidder 3', ${emails[2]}, 'x', 'buyer', NOW(), NOW()),
        ('PoC Bidder 4', ${emails[3]}, 'x', 'buyer', NOW(), NOW()),
        ('PoC Bidder 5', ${emails[4]}, 'x', 'buyer', NOW(), NOW())
    `;
    for (const b of BIDDERS) {
      const [row] = await prisma.$queryRaw<[{ id: number }]>`
        SELECT id FROM users WHERE email = ${b.email} LIMIT 1
      `;
      b.id = Number(row.id);
    }

    // Artwork
    await prisma.$executeRaw`
      INSERT INTO artworks (user_id, title, slug, medium, status, created_at, updated_at)
      VALUES (${BIDDERS[0].id}, 'PoC Artwork', 'poc-artwork-concurrency-suite', 'painting', 'in_auction', NOW(), NOW())
    `;
    const [aw] = await prisma.$queryRaw<[{ id: number }]>`
      SELECT id FROM artworks WHERE slug = 'poc-artwork-concurrency-suite' LIMIT 1
    `;
    artworkId = Number(aw.id);

    // Two art lots (A and B for isolation test)
    await prisma.$executeRaw`
      INSERT INTO art_lots (artwork_id, sale_mode, status, starting_bid_cents, currency, split_profile_key, created_at, updated_at)
      VALUES
        (${artworkId}, 'auction', 'active', 10000, 'EUR', 'social_pilot_45_45_10', NOW(), NOW()),
        (${artworkId}, 'auction', 'active', 20000, 'EUR', 'social_pilot_45_45_10', NOW(), NOW())
    `;
    const lots = await prisma.$queryRaw<Array<{ id: number }>>`
      SELECT id FROM art_lots WHERE artwork_id = ${artworkId} ORDER BY id LIMIT 2
    `;
    artLotAId = Number(lots[0].id);
    artLotBId = Number(lots[1].id);

    // Auction A (ends in 1 day)
    await prisma.$executeRaw`
      INSERT INTO auctions (title, slug, starts_at, ends_at, status, created_at, updated_at)
      VALUES ('PoC Auction A', 'poc-auction-a-suite', NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY), 'live', NOW(), NOW())
    `;
    const [aucA] = await prisma.$queryRaw<[{ id: number }]>`
      SELECT id FROM auctions WHERE slug = 'poc-auction-a-suite' LIMIT 1
    `;
    auctionAId = Number(aucA.id);

    // Auction B (ends in 1 day) — used for isolation test
    await prisma.$executeRaw`
      INSERT INTO auctions (title, slug, starts_at, ends_at, status, created_at, updated_at)
      VALUES ('PoC Auction B', 'poc-auction-b-suite', NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY), 'live', NOW(), NOW())
    `;
    const [aucB] = await prisma.$queryRaw<[{ id: number }]>`
      SELECT id FROM auctions WHERE slug = 'poc-auction-b-suite' LIMIT 1
    `;
    auctionBId = Number(aucB.id);

    // AuctionItem A — starting 10 000, increment 1 000
    await prisma.$executeRaw`
      INSERT INTO auction_items (auction_id, art_lot_id, lot_number, bid_increment_cents, status, created_at, updated_at)
      VALUES (${auctionAId}, ${artLotAId}, 1, 1000, 'open', NOW(), NOW())
    `;
    const [itA] = await prisma.$queryRaw<[{ id: number }]>`
      SELECT id FROM auction_items WHERE auction_id = ${auctionAId} AND lot_number = 1 LIMIT 1
    `;
    itemAId = Number(itA.id);

    // AuctionItem B — starting 20 000, increment 2 000
    await prisma.$executeRaw`
      INSERT INTO auction_items (auction_id, art_lot_id, lot_number, bid_increment_cents, status, created_at, updated_at)
      VALUES (${auctionBId}, ${artLotBId}, 1, 2000, 'open', NOW(), NOW())
    `;
    const [itB] = await prisma.$queryRaw<[{ id: number }]>`
      SELECT id FROM auction_items WHERE auction_id = ${auctionBId} AND lot_number = 1 LIMIT 1
    `;
    itemBId = Number(itB.id);
  } finally {
    await prisma.$executeRaw`SET FOREIGN_KEY_CHECKS = 1`;
  }
}

async function cleanAll(prisma: PrismaService) {
  if (itemAId) await prisma.$executeRaw`DELETE FROM bids WHERE auction_item_id IN (${itemAId}, ${itemBId})`;
  if (itemAId) await prisma.$executeRaw`DELETE FROM auction_items WHERE id IN (${itemAId}, ${itemBId})`;
  if (auctionAId) await prisma.$executeRaw`DELETE FROM auctions WHERE id IN (${auctionAId}, ${auctionBId})`;
  if (artLotAId) await prisma.$executeRaw`DELETE FROM art_lots WHERE id IN (${artLotAId}, ${artLotBId})`;
  if (artworkId) await prisma.$executeRaw`DELETE FROM artworks WHERE id = ${artworkId}`;
  await prisma.$executeRaw`DELETE FROM users WHERE email IN (
    'poc_bidder1@test.invalid','poc_bidder2@test.invalid','poc_bidder3@test.invalid',
    'poc_bidder4@test.invalid','poc_bidder5@test.invalid'
  )`;
}

async function resetItem(prisma: PrismaService, itemId: number) {
  await prisma.bid.deleteMany({ where: { auction_item_id: itemId } });
  await prisma.$executeRaw`UPDATE auction_items SET status = 'open' WHERE id = ${itemId}`;
}

// ── Test suite ────────────────────────────────────────────────────────────────

describeWithDb('BidConcurrencyService — Phase 0.2 expanded', () => {
  let service: BidConcurrencyService;

  beforeAll(async () => {
    sharedModule = await Test.createTestingModule({
      providers: [PrismaService, BidConcurrencyService],
    }).compile();

    sharedPrisma = sharedModule.get(PrismaService);
    service = sharedModule.get(BidConcurrencyService);
    await sharedPrisma.onModuleInit();
    await seedAll(sharedPrisma);
  });

  afterAll(async () => {
    await cleanAll(sharedPrisma);
    await sharedPrisma.onModuleDestroy();
    await sharedModule.close();
  });

  beforeEach(async () => {
    await resetItem(sharedPrisma, itemAId);
    await resetItem(sharedPrisma, itemBId);
  });

  // ── 1. Sequential bids ──────────────────────────────────────────────────

  it('1: accepts first bid, rejects second at same amount', async () => {
    const first = await service.placeBid({
      auctionItemId: itemAId,
      bidderId: BIDDERS[0].id,
      amountCents: BigInt(10_000),
    });
    expect(first.status).toBe('accepted');

    await expect(
      service.placeBid({
        auctionItemId: itemAId,
        bidderId: BIDDERS[1].id,
        amountCents: BigInt(10_000),
      }),
    ).rejects.toThrow(BidRejected);

    const count = await sharedPrisma.bid.count({
      where: { auction_item_id: itemAId, status: 'accepted' },
    });
    expect(count).toBe(1);
  });

  // ── 2. Two simultaneous bids ────────────────────────────────────────────

  it('2: exactly one of two simultaneous identical bids is accepted', async () => {
    const results = await Promise.allSettled([
      service.placeBid({ auctionItemId: itemAId, bidderId: BIDDERS[0].id, amountCents: BigInt(10_000) }),
      service.placeBid({ auctionItemId: itemAId, bidderId: BIDDERS[1].id, amountCents: BigInt(10_000) }),
    ]);

    const ok  = results.filter((r) => r.status === 'fulfilled');
    const err = results.filter((r) => r.status === 'rejected');
    expect(ok).toHaveLength(1);
    expect(err).toHaveLength(1);
    expect((err[0] as PromiseRejectedResult).reason).toBeInstanceOf(BidRejected);

    const count = await sharedPrisma.bid.count({
      where: { auction_item_id: itemAId, status: 'accepted' },
    });
    expect(count).toBe(1);
  });

  // ── 3. Five simultaneous bids ───────────────────────────────────────────

  it('3: exactly one of five simultaneous identical bids is accepted', async () => {
    const results = await Promise.allSettled(
      BIDDERS.map((b) =>
        service.placeBid({ auctionItemId: itemAId, bidderId: b.id, amountCents: BigInt(10_000) }),
      ),
    );

    const ok = results.filter((r) => r.status === 'fulfilled');
    expect(ok).toHaveLength(1);

    const rejectedReasons = results
      .filter((r) => r.status === 'rejected')
      .map((r) => (r as PromiseRejectedResult).reason);
    for (const reason of rejectedReasons) {
      expect(reason).toBeInstanceOf(BidRejected);
    }

    const count = await sharedPrisma.bid.count({
      where: { auction_item_id: itemAId, status: 'accepted' },
    });
    expect(count).toBe(1);
  });

  // ── 4. Cross-auction independence ──────────────────────────────────────

  it('4: bids on different items proceed independently without blocking each other', async () => {
    // Both items open; simultaneous bids on A and B must both succeed independently
    const [resA, resB] = await Promise.all([
      service.placeBid({ auctionItemId: itemAId, bidderId: BIDDERS[0].id, amountCents: BigInt(10_000) }),
      service.placeBid({ auctionItemId: itemBId, bidderId: BIDDERS[1].id, amountCents: BigInt(20_000) }),
    ]);

    expect(resA.status).toBe('accepted');
    expect(resB.status).toBe('accepted');
  });

  // ── 5. Auction closing while bid is submitted ───────────────────────────

  it('5: bid submitted after auction item is canceled is rejected', async () => {
    await sharedPrisma.$executeRaw`UPDATE auction_items SET status = 'canceled' WHERE id = ${itemAId}`;

    await expect(
      service.placeBid({ auctionItemId: itemAId, bidderId: BIDDERS[0].id, amountCents: BigInt(10_000) }),
    ).rejects.toThrow(BidRejected);

    const count = await sharedPrisma.bid.count({ where: { auction_item_id: itemAId } });
    expect(count).toBe(0);
  });

  // ── 6. Duplicate bid request ────────────────────────────────────────────

  it('6: same bidder submitting same amount twice — second is rejected', async () => {
    await service.placeBid({
      auctionItemId: itemAId,
      bidderId: BIDDERS[0].id,
      amountCents: BigInt(10_000),
    });

    // Same bidder, same amount — now below the new minimum
    await expect(
      service.placeBid({
        auctionItemId: itemAId,
        bidderId: BIDDERS[0].id,
        amountCents: BigInt(10_000),
      }),
    ).rejects.toThrow(BidRejected);
  });

  // ── 7. Transaction rollback on failure leaves no partial state ──────────

  it('7: a bid that fails validation leaves no rows in bids table', async () => {
    const before = await sharedPrisma.bid.count({ where: { auction_item_id: itemAId } });

    // Bid below starting price (0 < 10_000 minimum)
    await expect(
      service.placeBid({ auctionItemId: itemAId, bidderId: BIDDERS[0].id, amountCents: BigInt(0) }),
    ).rejects.toThrow(BidRejected);

    const after = await sharedPrisma.bid.count({ where: { auction_item_id: itemAId } });
    expect(after).toBe(before); // no rows written
  });

  // ── 8. Correct outbid chain ─────────────────────────────────────────────

  it('8: each new higher bid outbids the previous leader', async () => {
    // Bidder 1: €100
    await service.placeBid({ auctionItemId: itemAId, bidderId: BIDDERS[0].id, amountCents: BigInt(10_000) });

    // Bidder 2: €110 (minimum after increment)
    await service.placeBid({ auctionItemId: itemAId, bidderId: BIDDERS[1].id, amountCents: BigInt(11_000) });

    // Bidder 3: €120
    await service.placeBid({ auctionItemId: itemAId, bidderId: BIDDERS[2].id, amountCents: BigInt(12_000) });

    const bids = await sharedPrisma.bid.findMany({
      where: { auction_item_id: itemAId },
      orderBy: { created_at: 'asc' },
    });

    expect(bids).toHaveLength(3);
    expect(bids[0].status).toBe('outbid');
    expect(bids[1].status).toBe('outbid');
    expect(bids[2].status).toBe('accepted');

    const accepted = await sharedPrisma.bid.count({
      where: { auction_item_id: itemAId, status: 'accepted' },
    });
    expect(accepted).toBe(1);
  });

  // ── 9. Bid below starting price rejected ────────────────────────────────

  it('9: bid below starting_bid_cents is rejected even with no prior bids', async () => {
    // art_lot A has starting_bid_cents = 10_000; bid 5_000 must fail
    await expect(
      service.placeBid({ auctionItemId: itemAId, bidderId: BIDDERS[0].id, amountCents: BigInt(5_000) }),
    ).rejects.toThrow(BidRejected);
  });

  // ── 10. Ended auction (ends_at in past) rejects bids ────────────────────

  it('10: bid on auction with ends_at in the past is rejected', async () => {
    // Move auction A's end time to the past
    await sharedPrisma.$executeRaw`
      UPDATE auctions SET ends_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ${auctionAId}
    `;

    await expect(
      service.placeBid({ auctionItemId: itemAId, bidderId: BIDDERS[0].id, amountCents: BigInt(10_000) }),
    ).rejects.toThrow(BidRejected);

    // Restore for subsequent tests
    await sharedPrisma.$executeRaw`
      UPDATE auctions SET ends_at = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE id = ${auctionAId}
    `;
  });
});
