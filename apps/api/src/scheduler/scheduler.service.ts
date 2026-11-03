import { Injectable, Logger } from '@nestjs/common';
import { Cron, CronExpression } from '@nestjs/schedule';
import { PrismaService } from '../prisma.service';

/**
 * Scheduled task runner — mirrors Laravel Kernel scheduler exactly.
 *
 * auction:close-expired-lots    → every minute (matches Laravel schedule)
 * auction:expire-bid-authorizations → every hour
 *
 * In production these will move to a dedicated worker process.
 * For PoC they run inside the API process.
 */
@Injectable()
export class SchedulerService {
  private readonly logger = new Logger(SchedulerService.name);

  constructor(private readonly prisma: PrismaService) {}

  /**
   * Every minute: close auction items whose auction has ended.
   * Mirrors CloseExpiredLotsCommand + CloseAuctionItemJob.
   * Uses a raw query join (more efficient than whereHas at scale).
   */
  @Cron(CronExpression.EVERY_MINUTE, { name: 'close-expired-lots' })
  async closeExpiredLots(): Promise<void> {
    const now = new Date();

    // Find open items in ended auctions
    const expiredItems = await this.prisma.$queryRaw<{ id: bigint }[]>`
      SELECT ai.id
      FROM auction_items ai
      INNER JOIN auctions a ON a.id = ai.auction_id
      WHERE ai.status = 'open'
        AND a.ends_at <= ${now}
      LIMIT 200
    `;

    if (expiredItems.length === 0) return;

    let closed = 0;
    for (const item of expiredItems) {
      try {
        await this.closeAuctionItem(Number(item.id));
        closed++;
      } catch (err: any) {
        this.logger.error(`Failed to close auction item ${item.id}: ${err?.message}`);
      }
    }

    if (closed > 0) this.logger.log(`Closed ${closed} expired auction item(s)`);
  }

  private async closeAuctionItem(itemId: number): Promise<void> {
    await this.prisma.$transaction(async (tx) => {
      const item = await tx.auctionItem.findUnique({
        where: { id: BigInt(itemId) },
        select: { id: true, status: true },
      });

      if (!item || item.status !== 'open') return; // idempotent

      // Find winning bid (highest accepted bid)
      const winningBid = await tx.bid.findFirst({
        where:   { auction_item_id: item.id, status: 'accepted' as any },
        orderBy: { amount_cents: 'desc' },
        select:  { id: true, user_id: true, amount_cents: true },
      });

      if (winningBid) {
        // Mark winning bid
        await tx.bid.update({
          where: { id: winningBid.id },
          data:  { status: 'won' as any, updated_at: new Date() },
        });

        // Mark all other bids outbid
        await tx.bid.updateMany({
          where: {
            auction_item_id: item.id,
            id:              { not: winningBid.id },
            status:          'accepted' as any,
          },
          data: { status: 'outbid' as any, updated_at: new Date() },
        });

        await tx.auctionItem.update({
          where: { id: item.id },
          data:  { status: 'sold' as any, winner_user_id: winningBid.user_id, updated_at: new Date() },
        });
      } else {
        // No bids — lot unsold
        await tx.auctionItem.update({
          where: { id: item.id },
          data:  { status: 'unsold' as any, updated_at: new Date() },
        });
      }
    });
  }

  /**
   * Every hour: mark bids whose Stripe authorization has expired.
   * Mirrors ExpireBidAuthorizations command.
   * Stripe cancels uncaptured PIs after 7 days.
   */
  @Cron(CronExpression.EVERY_HOUR, { name: 'expire-bid-authorizations' })
  async expireBidAuthorizations(): Promise<void> {
    const now = new Date();

    const expiredBids = await this.prisma.bid.findMany({
      where: {
        status:                    { in: ['accepted', 'won'] as any[] },
        payment_status:            'authorized' as any,
        authorization_expires_at:  { not: null, lte: now },
      },
      select: { id: true, status: true, auction_item_id: true, amount_cents: true, user_id: true },
      take: 100,
    });

    if (expiredBids.length === 0) return;

    let processed = 0;
    for (const bid of expiredBids) {
      try {
        await this.prisma.bid.update({
          where: { id: bid.id },
          data:  { payment_status: 'authorization_expired' as any, updated_at: new Date() },
        });

        if (bid.status === 'won') {
          this.logger.warn(
            `Winning bid ${bid.id} (item ${bid.auction_item_id}) authorization expired — ` +
            `winner ${bid.user_id} must re-authorize EUR ${Number(bid.amount_cents) / 100}`
          );
        }

        processed++;
      } catch (err: any) {
        this.logger.error(`Failed to expire bid ${bid.id}: ${err?.message}`);
      }
    }

    if (processed > 0) this.logger.log(`Expired ${processed} bid authorization(s)`);
  }
}
