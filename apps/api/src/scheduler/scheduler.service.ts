import { Injectable, Logger } from '@nestjs/common';
import { Cron, CronExpression } from '@nestjs/schedule';
import { PrismaService } from '../prisma.service';
import { StripeService } from '../stripe/stripe.service';

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

  constructor(
    private readonly prisma: PrismaService,
    private readonly stripe: StripeService,
  ) {}

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

  /**
   * Every 30 seconds: dispatch pending domain_events to event listeners.
   *
   * Multi-instance safety: each event is claimed atomically with an UPDATE WHERE
   * status='pending'. Only one instance can transition a row from pending→processing.
   * An instance that crashes leaves the row in 'processing'; a lease expiry scan
   * (see recoverStaleClaims) resets it to 'pending' after LEASE_TIMEOUT_MS.
   */
  private static readonly DOMAIN_EVENT_LEASE_MS = 5 * 60 * 1000; // 5 min

  @Cron('*/30 * * * * *', { name: 'dispatch-domain-events' })
  async dispatchDomainEvents(): Promise<void> {
    const now = new Date();

    const events = await this.prisma.domain_events.findMany({
      where: { status: 'pending' as any, next_attempt_at: { lte: now } },
      orderBy: [{ next_attempt_at: 'asc' }],
      take: 50,
    });

    if (events.length === 0) return;

    let dispatched = 0;
    for (const event of events) {
      // Atomic claim: UPDATE ... WHERE status = 'pending' AND id = ?
      // If another instance already claimed it, rowsAffected = 0 and we skip.
      const claimed = await this.prisma.$executeRaw`
        UPDATE domain_events
        SET    status = 'processing',
               attempt = attempt + 1,
               lease_expires_at = ${new Date(Date.now() + SchedulerService.DOMAIN_EVENT_LEASE_MS)},
               updated_at = ${new Date()}
        WHERE  id = ${event.id}
          AND  status = 'pending'
      `;
      if (claimed === 0) continue; // another instance claimed it

      try {
        await this.handleDomainEvent(event.event_type, JSON.parse(event.payload));

        await this.prisma.$executeRaw`
          UPDATE domain_events
          SET status = 'dispatched', lease_expires_at = NULL, updated_at = ${new Date()}
          WHERE id = ${event.id}
        `;
        dispatched++;
      } catch (err: any) {
        const backoffSecs = Math.min(300, 30 * Math.pow(2, event.attempt));
        const nextAttempt = new Date(Date.now() + backoffSecs * 1000);
        await this.prisma.$executeRaw`
          UPDATE domain_events
          SET status = 'pending', lease_expires_at = NULL,
              last_error = ${(err as any)?.message?.slice(0, 500)},
              next_attempt_at = ${nextAttempt}, updated_at = ${new Date()}
          WHERE id = ${event.id}
        `;
        this.logger.error(`domain_event ${event.id} (${event.event_type}) failed: ${err?.message}`);
      }
    }

    if (dispatched > 0) this.logger.log(`Dispatched ${dispatched} domain event(s)`);
  }

  private async handleDomainEvent(eventType: string, payload: unknown): Promise<void> {
    switch (eventType) {
      case 'artwork.derivatives_requested': {
        const p = payload as { artwork_id: number; slug: string };
        this.logger.log(`Generating derivatives for artwork ${p.slug} (id=${p.artwork_id})`);
        // Real derivative generation (resize, watermark) would dispatch to an image
        // processing worker here. For now we log and mark complete so the outbox
        // does not retry indefinitely; the image pipeline is an M2 infrastructure task.
        break;
      }
      case 'artwork.published':
      case 'auction.closed':
      case 'bid.won':
      case 'settlement.completed':
        this.logger.debug(`domain_event '${eventType}' received — no handler yet`);
        break;
      default:
        this.logger.debug(`domain_event '${eventType}' has no registered handler`);
    }
  }

  /**
   * Every minute: dispatch pending Stripe transfer outbox entries.
   *
   * Multi-instance safety: each entry is claimed atomically with an UPDATE WHERE
   * status='pending'. Only one instance can transition pending→processing.
   *
   * Stripe idempotency: idempotency_key = transfer_outbox:{id} is stable per row.
   * Stripe deduplicates calls with the same key for 24 hours. For delayed retries
   * beyond 24 hours, we first check the persisted stripe_transfer_id to reconcile
   * the original transfer before creating a new one.
   */
  private static readonly TRANSFER_LEASE_MS = 10 * 60 * 1000; // 10 min

  @Cron(CronExpression.EVERY_MINUTE, { name: 'dispatch-transfer-outbox' })
  async dispatchTransferOutbox(): Promise<void> {
    if (!this.stripe.enabled) return;

    const now = new Date();
    const entries = await this.prisma.transfer_outbox.findMany({
      where: { status: 'pending' as any, next_attempt_at: { lte: now } },
      include: { settlement_lines: { select: { stripe_account_id: true } } },
      orderBy: [{ next_attempt_at: 'asc' }],
      take: 20,
    });

    if (entries.length === 0) return;

    let dispatched = 0;
    for (const entry of entries) {
      // Atomic claim — skip if another instance already claimed it
      const claimed = await this.prisma.$executeRaw`
        UPDATE transfer_outbox
        SET    status = 'processing',
               attempt = attempt + 1,
               lease_expires_at = ${new Date(Date.now() + SchedulerService.TRANSFER_LEASE_MS)},
               updated_at = ${new Date()}
        WHERE  id = ${entry.id}
          AND  status = 'pending'
      `;
      if (claimed === 0) continue;

      try {
        const stripeAccount = (entry.settlement_lines as any)?.stripe_account_id ?? null;

        if (stripeAccount) {
          // Reconciliation strategy (handles crash-after-Stripe-before-DB scenarios):
          //
          // 1. stripe_transfer_id already persisted → retrieve directly (covers delayed
          //    retries beyond Stripe's 24h idempotency window).
          // 2. external_ref present but no stripe_transfer_id → search Stripe by
          //    transfer_group which encodes the external_ref; covers crash between
          //    Stripe API success and our first DB write.
          // 3. Neither present → create new transfer (first attempt).
          const existingTransferId = entry.stripe_transfer_id;
          if (existingTransferId) {
            const reconciled = await this.stripe.retrieveTransfer(existingTransferId);
            if (reconciled) {
              this.logger.log(`transfer_outbox ${entry.id}: reconciled existing Stripe transfer ${existingTransferId}`);
              await this.prisma.$executeRaw`
                UPDATE transfer_outbox SET status = 'completed', lease_expires_at = NULL, dispatched_at = ${new Date()}, updated_at = ${new Date()} WHERE id = ${entry.id}
              `;
              dispatched++;
              continue;
            }
          }

          // Ensure a stable external_ref exists before calling Stripe.
          // Generated on first attempt; preserved on every subsequent retry.
          let externalRef = (entry as any).external_ref as string | null;
          if (!externalRef) {
            externalRef = `to-${entry.id}-${Date.now()}`;
            await this.prisma.$executeRaw`
              UPDATE transfer_outbox SET external_ref = ${externalRef}, updated_at = ${new Date()} WHERE id = ${entry.id}
            `;
          }

          const transferGroup = `outbox:${externalRef}`;

          // If no stripe_transfer_id yet, try to find an existing transfer via
          // transfer_group (covers crash after Stripe accepted but before DB write).
          // Verifies exact external_ref, amount, currency and destination to avoid
          // blindly accepting any transfer in the group.
          if (!existingTransferId) {
            const found = await this.stripe.findTransferByGroup({
              transferGroup,
              externalRef,
              amountCents: Number(entry.amount_cents),
              currency: entry.currency,
              destination: stripeAccount,
            });
            if (found) {
              this.logger.log(`transfer_outbox ${entry.id}: found orphaned Stripe transfer ${found.transfer_id} via transfer_group`);
              await this.prisma.$executeRaw`
                UPDATE transfer_outbox
                SET stripe_transfer_id = ${found.transfer_id}, status = 'completed',
                    lease_expires_at = NULL, dispatched_at = ${new Date()}, updated_at = ${new Date()}
                WHERE id = ${entry.id}
              `;
              dispatched++;
              continue;
            }
          }

          const result = await this.stripe.createTransfer({
            amount_cents: Number(entry.amount_cents),
            currency: 'eur',
            destination: stripeAccount,
            transfer_group: transferGroup,
            idempotency_key: `transfer_outbox:${entry.id}`,
            metadata: { outbox_id: String(entry.id), external_ref: externalRef },
          });

          // Persist the Stripe transfer ID immediately so crash recovery can reconcile
          await this.prisma.$executeRaw`
            UPDATE transfer_outbox
            SET stripe_transfer_id = ${result?.transfer_id ?? null}, updated_at = ${new Date()}
            WHERE id = ${entry.id}
          `;

          this.logger.log(`transfer_outbox ${entry.id}: EUR ${Number(entry.amount_cents) / 100} dispatched to ${stripeAccount} (${result?.transfer_id ?? 'no-id'})`);
        } else {
          this.logger.warn(`transfer_outbox ${entry.id}: no stripe_account_id — skipping transfer`);
        }

        await this.prisma.$executeRaw`
          UPDATE transfer_outbox SET status = 'completed', lease_expires_at = NULL, updated_at = ${new Date()} WHERE id = ${entry.id}
        `;
        dispatched++;
      } catch (err: any) {
        const backoffSecs = Math.min(300, 60 * Math.pow(2, entry.attempt));
        const nextAttempt = new Date(Date.now() + backoffSecs * 1000);
        await this.prisma.$executeRaw`
          UPDATE transfer_outbox
          SET status = 'pending', lease_expires_at = NULL,
              last_error = ${(err as any)?.message?.slice(0, 500)},
              next_attempt_at = ${nextAttempt}, updated_at = ${new Date()}
          WHERE id = ${entry.id}
        `;
        this.logger.error(`transfer_outbox ${entry.id} failed: ${err?.message}`);
      }
    }

    if (dispatched > 0) this.logger.log(`Dispatched ${dispatched} transfer outbox entry/entries`);
  }

  /**
   * Every 5 minutes: reset stale 'processing' claims whose lease has expired.
   * This recovers from worker crashes that left entries stuck in processing.
   */
  @Cron('*/5 * * * *', { name: 'recover-stale-claims' })
  async recoverStaleClaims(): Promise<void> {
    const now = new Date();

    const [domainRecovered, transferRecovered] = await Promise.all([
      this.prisma.$executeRaw`
        UPDATE domain_events
        SET    status = 'pending', lease_expires_at = NULL, updated_at = ${now}
        WHERE  status = 'processing'
          AND  lease_expires_at IS NOT NULL
          AND  lease_expires_at < ${now}
      `,
      this.prisma.$executeRaw`
        UPDATE transfer_outbox
        SET    status = 'pending', lease_expires_at = NULL, updated_at = ${now}
        WHERE  status = 'processing'
          AND  lease_expires_at IS NOT NULL
          AND  lease_expires_at < ${now}
      `,
    ]);

    if (Number(domainRecovered) > 0)
      this.logger.warn(`Recovered ${Number(domainRecovered)} stale domain_event claim(s)`);
    if (Number(transferRecovered) > 0)
      this.logger.warn(`Recovered ${Number(transferRecovered)} stale transfer_outbox claim(s)`);
  }
}
