import { Injectable, Logger } from '@nestjs/common';
import { PrismaService } from '../prisma.service';
import { SettlementService } from './settlement.service';
import { EmailService } from '../email/email.service';
import { RecipientLine } from './recipient-line';
import { Money } from './money';
import { SplitProfile } from './split-profile';
import { SettlementCalculator } from './settlement-calculator';

/**
 * Processes Stripe webhook events that have already been validated and stored
 * in webhook_events. Mirrors Laravel HandleStripeWebhook job exactly.
 *
 * Call order:
 *   1. Mark webhook_event processing
 *   2. Route by event type
 *   3. Mark processed / reset to received on error
 *
 * Does NOT verify Stripe signatures — the HTTP controller layer does that
 * before inserting into webhook_events.
 */
@Injectable()
export class WebhookHandlerService {
  private readonly logger = new Logger(WebhookHandlerService.name);
  private readonly calc   = new SettlementCalculator();

  constructor(
    private readonly prisma:      PrismaService,
    private readonly settlements: SettlementService,
    private readonly email:       EmailService,
  ) {}

  async processWebhookEvent(webhookEventId: number): Promise<void> {
    const row = await this.prisma.webhook_events.findUnique({
      where: { id: BigInt(webhookEventId) },
    });

    if (!row || row.status !== 'received') return;

    await this.prisma.webhook_events.update({
      where: { id: BigInt(webhookEventId) },
      data:  { status: 'processing' as any, updated_at: new Date() },
    });

    try {
      const event = JSON.parse(row.payload);
      await this.route(event);

      await this.prisma.webhook_events.update({
        where: { id: BigInt(webhookEventId) },
        data:  { status: 'processed' as any, updated_at: new Date() },
      });
    } catch (err: any) {
      // Reset so the next retry attempt can process it
      await this.prisma.webhook_events.update({
        where: { id: BigInt(webhookEventId) },
        data:  { status: 'received' as any, error: err?.message ?? String(err), updated_at: new Date() },
      });
      throw err;
    }
  }

  private async route(event: any): Promise<void> {
    switch (event.type) {
      case 'checkout.session.completed':
        return this.handleCheckoutSessionCompleted(event);
      case 'payment_intent.succeeded':
        return this.handlePaymentIntentSucceeded(event);
      case 'payment_intent.amount_capturable_updated':
        return this.handleCapturableUpdated(event);
      case 'payment_intent.payment_failed':
        return this.handlePaymentFailed(event);
      case 'charge.refunded':
        return this.handleChargeRefunded(event);
      default:
        this.logger.debug(`Unhandled Stripe event type: ${event.type}`);
    }
  }

  // ── checkout.session.completed ──────────────────────────────────────────

  private async handleCheckoutSessionCompleted(event: any): Promise<void> {
    const session    = event.data?.object ?? {};
    const sessionId  = session.id;
    const piId       = session.payment_intent;
    const offerId    = session.metadata?.sell_now_offer_id;
    const purchaseId = session.metadata?.book_purchase_id;

    if (!sessionId) return;

    // ── Sell Now path ──────────────────────────────────────────────────────
    if (offerId) {
      const offer = await this.prisma.sell_now_offers.findFirst({
        where: { id: BigInt(offerId), stripe_checkout_session_id: sessionId },
      });

      if (offer && offer.status === 'accepted') {
        // Record payment intent id on the offer
        if (piId) {
          await this.prisma.sell_now_offers.update({
            where: { id: offer.id },
            data:  { stripe_payment_intent_id: piId, updated_at: new Date() },
          });
        }

        const agreedCents = Number(offer.agreed_price_cents ?? offer.offered_price_cents);
        const currency    = offer.currency ?? 'EUR';

        await this.settleSellNowOffer({
          offerId:    Number(offer.id),
          grossCents: agreedCents,
          currency,
          piId:       piId ?? `session_${sessionId}`,
          eventId:    event.id,
        });

        // Mark offer paid
        await this.prisma.sell_now_offers.update({
          where: { id: offer.id },
          data:  { status: 'paid' as any, updated_at: new Date() },
        });

        // Send payment confirmation email (best-effort, non-blocking)
        try {
          const buyer = await this.prisma.user.findUnique({
            where: { id: offer.buyer_id },
            select: { email: true },
          });
          const artTitle = `Artwork #${offerId}`;
          if (buyer?.email) {
            await this.email.sendPaymentConfirmation(buyer.email, artTitle, agreedCents, currency);
          }
        } catch (emailErr: any) {
          this.logger.warn(`Payment email failed for offer ${offerId}: ${emailErr?.message}`);
        }
      }
      return;
    }

    // ── Book purchase path ──────────────────────────────────────────────────
    if (purchaseId && piId) {
      await this.prisma.book_purchases.updateMany({
        where: {
          id:                          BigInt(purchaseId),
          stripe_checkout_session_id:  sessionId,
          status:                      'pending' as any,
        },
        data: { stripe_payment_intent_id: piId, updated_at: new Date() },
      });
    }
  }

  private async settleSellNowOffer(opts: {
    offerId:    number;
    grossCents: number;
    currency:   string;
    piId:       string;
    eventId:    string;
  }): Promise<number> {
    const gross   = Money.fromCents(opts.grossCents, opts.currency);
    const profile = SplitProfile.fromKey('social_pilot_45_45_10');
    const result  = this.calc.calculate(gross, profile);

    const recipients: RecipientLine[] = [
      {
        type: 'artist', legalEntityId: null, userId: null,
        entityName: 'Artist (Sell Now)', entityEik: null, entityRole: 'artist',
        stripeAccountId: null,
        amount: result.artist, weight: result.artistBps,
      },
      {
        type: 'fund', legalEntityId: null, userId: null,
        entityName: 'Impact Fund', entityEik: null, entityRole: 'fund',
        stripeAccountId: null,
        amount: result.fund, weight: result.fundBps,
      },
      {
        type: 'platform', legalEntityId: null, userId: null,
        entityName: 'ARTeuCtion', entityEik: null, entityRole: 'platform',
        stripeAccountId: null,
        amount: result.ops, weight: result.opsBps,
      },
    ];

    return this.settlements.settle({
      grossCents:    opts.grossCents,
      currency:      opts.currency,
      profileKey:    'social_pilot_45_45_10',
      paymentIntent: opts.piId,
      stripeEvent:   opts.eventId,
      recipients,
    });
  }

  // ── payment_intent.succeeded ────────────────────────────────────────────

  private async handlePaymentIntentSucceeded(event: any): Promise<void> {
    const pi   = event.data?.object ?? {};
    const piId = pi.id;
    if (!piId) return;

    // Book purchase: grant entitlement + settlement
    const bookPurchase = await this.prisma.book_purchases.findFirst({
      where: { stripe_payment_intent_id: piId, status: 'pending' as any },
    });

    if (bookPurchase) {
      const grossCents = Number(bookPurchase.gross_cents);
      const currency   = bookPurchase.currency ?? 'EUR';
      const profile    = SplitProfile.fromKey(bookPurchase.profile_key || 'library_80_10_10');
      const gross      = Money.fromCents(grossCents, currency);
      const result     = this.calc.calculate(gross, profile);

      const recipients: RecipientLine[] = [
        { type: 'artist', legalEntityId: null, userId: Number(bookPurchase.buyer_id),
          entityName: null, entityEik: null, entityRole: 'author',
          stripeAccountId: null, amount: result.artist, weight: result.artistBps },
        { type: 'fund', legalEntityId: null, userId: null,
          entityName: 'SDG Fund', entityEik: null, entityRole: 'fund',
          stripeAccountId: null, amount: result.fund, weight: result.fundBps },
        { type: 'platform', legalEntityId: null, userId: null,
          entityName: 'ARTeuCtion', entityEik: null, entityRole: 'platform',
          stripeAccountId: null, amount: result.ops, weight: result.opsBps },
      ];

      await this.settlements.settle({
        grossCents, currency,
        profileKey:    bookPurchase.profile_key || 'library_80_10_10',
        paymentIntent: piId,
        stripeEvent:   event.id,
        recipients,
        bookPurchaseId: Number(bookPurchase.id),
      });

      // Grant entitlement
      await this.prisma.book_entitlements.create({
        data: {
          user_id:    bookPurchase.buyer_id,
          book_id:    bookPurchase.book_id,
          source:     'purchase' as any,
          granted_at: new Date(),
        },
      });

      await this.prisma.book_purchases.update({
        where: { id: bookPurchase.id },
        data:  { status: 'completed' as any, updated_at: new Date() },
      });
      return;
    }

    // Non-book: mark any pending settlement for this PI as completed
    await this.prisma.settlements.updateMany({
      where: { stripe_payment_intent_id: piId, status: 'pending' as any },
      data:  { status: 'completed' as any, updated_at: new Date() },
    });
  }

  // ── payment_intent.amount_capturable_updated ────────────────────────────

  private async handleCapturableUpdated(event: any): Promise<void> {
    const pi   = event.data?.object ?? {};
    const piId = pi.id;
    if (!piId) return;

    // Find winning bid associated with this PI
    const bid = await this.prisma.bid.findFirst({
      where: { stripe_payment_intent_id: piId, status: 'won' as any },
    });
    if (!bid) return;

    const item = await this.prisma.auctionItem.findFirst({ where: { id: bid.auction_item_id } });
    if (!item) return;

    const auction = await this.prisma.auction.findUnique({ where: { id: item.auction_id } });
    if (!auction || auction.status !== 'closed') return;

    // Settle winning bid: gross = bid amount
    const grossCents = Number(bid.amount_cents);
    const profile    = SplitProfile.fromKey('social_pilot_45_45_10');
    const gross      = Money.fromCents(grossCents);
    const result     = this.calc.calculate(gross, profile);

    const recipients: RecipientLine[] = [
      { type: 'artist', legalEntityId: null, userId: null,
        entityName: 'Artist (Auction)', entityEik: null, entityRole: 'artist',
        stripeAccountId: null, amount: result.artist, weight: result.artistBps },
      { type: 'fund', legalEntityId: null, userId: null,
        entityName: 'Impact Fund', entityEik: null, entityRole: 'fund',
        stripeAccountId: null, amount: result.fund, weight: result.fundBps },
      { type: 'platform', legalEntityId: null, userId: null,
        entityName: 'ARTeuCtion', entityEik: null, entityRole: 'platform',
        stripeAccountId: null, amount: result.ops, weight: result.opsBps },
    ];

    await this.settlements.settle({
      grossCents, profileKey: 'social_pilot_45_45_10',
      paymentIntent: piId, stripeEvent: event.id,
      recipients, auctionId: Number(auction.id),
    });

    // Mark auction item settled
    await this.prisma.auctionItem.update({
      where: { id: item.id },
      data:  { status: 'settled' as any, updated_at: new Date() },
    });
  }

  // ── payment_intent.payment_failed ──────────────────────────────────────

  private async handlePaymentFailed(event: any): Promise<void> {
    const piId = event.data?.object?.id;
    if (!piId) return;
    // Log for ops visibility; settlement stays pending until manual review
    this.logger.warn(`Payment failed for PI ${piId} (event ${event.id})`);
  }

  // ── charge.refunded ────────────────────────────────────────────────────

  private async handleChargeRefunded(event: any): Promise<void> {
    const charge  = event.data?.object ?? {};
    const refunds = charge.refunds?.data ?? [];
    const piId    = charge.payment_intent;

    if (!piId) return;

    const settlement = await this.prisma.settlements.findUnique({
      where: { stripe_payment_intent_id: piId },
      select: { id: true },
    });
    if (!settlement) return;

    const now = new Date();

    for (const refund of refunds) {
      const amountCents = Number(refund.amount);
      const currency    = (refund.currency ?? 'EUR').toUpperCase();

      // Idempotent: skip if already recorded
      const existing = await this.prisma.refunds.findUnique({
        where: { stripe_refund_id: refund.id },
        select: { id: true },
      });
      if (existing) continue;

      await this.prisma.$transaction(async (tx) => {
        const refundRow = await tx.refunds.create({
          data: {
            stripe_refund_id:      refund.id,
            stripe_charge_id:      charge.id ?? null,
            settlement_id:         settlement.id,
            amount_cents:          amountCents,
            currency,
            is_partial:            amountCents < Number(charge.amount_captured ?? charge.amount),
            refund_status:         (refund.status ?? 'pending') as any,
            reconciliation_status: 'pending' as any,
            reason:                refund.reason ?? null,
            created_at:            now,
            updated_at:            now,
          },
          select: { id: true },
        });

        // Ledger debit entry — THREE-LEDGER INVARIANT: ledger_entries only
        await tx.ledger_entries.create({
          data: {
            settlement_id:   settlement.id,
            type:            'debit' as any,
            amount_cents:    amountCents,
            currency,
            idempotency_key: `refund:${refund.id}:debit`,
            note:            `Stripe refund ${refund.id}`,
            created_at:      now,
            updated_at:      now,
          },
        });
      });
    }
  }
}
