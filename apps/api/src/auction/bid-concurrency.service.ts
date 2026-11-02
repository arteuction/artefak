import { Injectable, Logger } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

export class BidRejected extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'BidRejected';
  }
}

/**
 * Minimal PlaceBid equivalent demonstrating Prisma + MariaDB row-level locking.
 *
 * This service intentionally omits Stripe (the PoC tests the locking contract,
 * not the payment flow). In the full migration Stripe is added back inside the
 * transaction after the lock is acquired, exactly as PlaceBid.php does.
 *
 * The critical invariant replicated from PlaceBid.php:
 *   SELECT ... FOR UPDATE on auction_items ensures only one bid writer holds
 *   the lock at a time. The second concurrent writer blocks until the first
 *   commits, then re-reads the already-updated state and must respect the new
 *   minimum bid amount.
 */
@Injectable()
export class BidConcurrencyService {
  private readonly logger = new Logger(BidConcurrencyService.name);

  constructor(private readonly prisma: PrismaService) {}

  /**
   * Place a bid inside a serializable transaction with FOR UPDATE.
   *
   * Prisma does not expose lockForUpdate() in its fluent API, so we use
   * $queryRaw for the locking SELECT, then Prisma's typed API for writes.
   * This is the same pattern as Eloquent's lockForUpdate() — both compile
   * to "SELECT ... FOR UPDATE".
   */
  async placeBid(params: {
    auctionItemId: number;
    bidderId: number;
    amountCents: bigint;
    ipAddress?: string;
  }): Promise<{ bidId: bigint; amountCents: bigint; status: string }> {
    const { auctionItemId, bidderId, amountCents, ipAddress } = params;

    try {
     return await this.prisma.$transaction(
      async (tx) => {
        // NOTE: all reads and writes in this function use the same `tx` client —
        // the FOR UPDATE lock is only effective when every operation is on the
        // same transaction connection (Prisma Interactive Transactions guarantee this).
        // ── Step 1: acquire exclusive row lock ──────────────────────────
        // Equivalent to: AuctionItem::lockForUpdate()->findOrFail($item->id)
        // Join auctions to check ends_at — mirrors isOpenForBidding() in AuctionItem.php:
        //   "status must be 'open' AND auction.ends_at must be in the future"
        // Also join art_lots for starting_bid_cents — it does NOT live on auction_items.
        // auction_ended: 1 if NOW() >= ends_at (server-side comparison avoids
        // timezone mismatch between application and DB server clocks)
        const rows = await tx.$queryRaw<
          Array<{
            id: number;
            status: string;
            bid_increment_cents: bigint | null;
            auction_ended: number;
            starting_bid_cents: bigint | null;
            ruleset_increment: bigint | null;
          }>
        >`
          SELECT
            ai.id,
            ai.status,
            ai.bid_increment_cents,
            (UTC_TIMESTAMP() >= a.ends_at) AS auction_ended,
            al.starting_bid_cents,
            ar.default_bid_increment_cents AS ruleset_increment
          FROM auction_items ai
          JOIN auctions a ON a.id = ai.auction_id
          LEFT JOIN art_lots al ON al.id = ai.art_lot_id
          LEFT JOIN auction_rulesets ar ON ar.id = a.ruleset_id
          WHERE ai.id = ${auctionItemId}
          FOR UPDATE
        `;

        if (rows.length === 0) {
          throw new BidRejected(`Auction item ${auctionItemId} not found`);
        }

        const item = rows[0];

        // Server-authoritative check — matches isOpenForBidding() exactly
        if (item.status !== 'open') {
          throw new BidRejected(
            `Lot ${auctionItemId} is not open for bidding (status: ${item.status})`,
          );
        }
        // auction_ended is computed server-side (NOW() >= ends_at) to avoid
        // timezone skew between the Node.js process and the MariaDB server clock.
        if (item.auction_ended) {
          throw new BidRejected(`Lot ${auctionItemId} auction has ended`);
        }

        // ── Step 2: compute minimum bid — mirrors nextBidCents() ─────────
        // increment priority: auction_items.bid_increment_cents
        //   → auction_rulesets.default_bid_increment_cents → 1000
        const increment =
          item.bid_increment_cents ?? item.ruleset_increment ?? BigInt(1000);

        const currentBestRows = await tx.$queryRaw<
          Array<{ max_amount: bigint | null }>
        >`
          SELECT MAX(amount_cents) AS max_amount
          FROM bids
          WHERE auction_item_id = ${auctionItemId}
          AND status = 'accepted'
        `;

        const currentBest = currentBestRows[0]?.max_amount ?? null;
        const minimum =
          currentBest !== null
            ? currentBest + increment
            : (item.starting_bid_cents ?? BigInt(0));

        if (amountCents < minimum) {
          throw new BidRejected(
            `Bid of ${amountCents} cents is below the minimum of ${minimum} cents`,
          );
        }

        // ── Step 3: outbid the previous leader ───────────────────────────
        // Only call updateMany when there IS a current leader — avoids acquiring a
        // next-key/supremum lock on an empty bids range, which would deadlock
        // concurrent transactions on different auction items.
        if (currentBest !== null) {
          await tx.bid.updateMany({
            where: { auction_item_id: auctionItemId, status: 'accepted' },
            data: { status: 'outbid' },
          });
        }

        // ── Step 4: insert new accepted bid ──────────────────────────────
        const bid = await tx.bid.create({
          data: {
            auction_item_id: auctionItemId,
            user_id: bidderId,
            amount_cents: amountCents,
            status: 'accepted',
            ip_address: ipAddress ?? null,
          },
        });

        this.logger.log(
          `Bid accepted: id=${bid.id} item=${auctionItemId} bidder=${bidderId} amount=${amountCents}`,
        );

        return {
          bidId: bid.id,
          amountCents: bid.amount_cents,
          status: bid.status,
        };
      },
      {
        // RepeatableRead is sufficient for SELECT ... FOR UPDATE locking in MariaDB InnoDB.
        // SERIALIZABLE would also work but causes unnecessary cross-row deadlocks when
        // two transactions touch different auction_items rows simultaneously.
        isolationLevel: 'RepeatableRead',
        timeout: 10_000,
      },
    );
    } catch (err: unknown) {
      // Re-throw BidRejected (our domain error) unchanged.
      // Convert Prisma deadlock / write-conflict errors into BidRejected so callers
      // receive a typed business error rather than a raw DB error.
      if (err instanceof BidRejected) throw err;
      const msg = err instanceof Error ? err.message : String(err);
      if (
        msg.includes('deadlock') ||
        msg.includes('write conflict') ||
        msg.includes('Transaction failed due to')
      ) {
        throw new BidRejected(`Bid serialization conflict — please retry (${msg})`);
      }
      throw err;
    }
  }
}
