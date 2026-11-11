/**
 * Phase 0.5 — WebhookHandlerService
 *
 * WH1  processWebhookEvent — skips non-received status
 * WH2  checkout.session.completed — sell-now path: settles offer and marks paid
 * WH3  checkout.session.completed — book purchase path: stores PI id
 * WH4  payment_intent.succeeded — book purchase: grants entitlement + settlement
 * WH5  payment_intent.succeeded — non-book: marks settlement completed
 * WH6  payment_intent.amount_capturable_updated — settles closed auction
 * WH7  payment_intent.amount_capturable_updated — skips if auction not closed
 * WH8  charge.refunded — delegates each refund to RefundService with mapped fields
 * WH9  charge.refunded — RefundService error (no settlement / over-refund): rethrown, webhook reset to received
 * WH10 unknown event type — no error thrown
 * WH11 processWebhookEvent — resets status to received on error
 * WH12 checkout.session.completed — auction item path: settles + marks paid
 * WH13 checkout.session.completed — auction item not found or wrong status: no-op
 * WH14 charge.refunded — several refunds on one charge: each processed in order
 * WH17 charge.refunded — no payment_intent: RefundService not called
 * WH15 checkout.session.completed — sell-now offer not found: no-op
 * WH16 payment_intent.payment_failed — logs and returns without DB writes
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { WebhookHandlerService } from './webhook-handler.service';
import { SettlementService } from './settlement.service';
import { PrismaService } from '../prisma.service';
import { EmailService } from '../email/email.service';
import { RefundService } from './refund.service';

const fn = () => jest.fn() as any;

const mockSettlementService = { settle: fn(), preview: fn() };
const mockEmailService = { sendPaymentConfirmation: fn() };
const mockRefundService = { process: fn() };

const mockPrisma = {
  webhook_events:    { findUnique: fn(), update: fn() },
  sell_now_offers:   { findFirst: fn(), update: fn(), updateMany: fn() },
  book_purchases:    { findFirst: fn(), update: fn(), updateMany: fn() },
  book_entitlements: { create: fn() },
  settlements:       { findUnique: fn(), updateMany: fn() },
  bid:               { findFirst: fn(), update: fn(), updateMany: fn() },
  auctionItem:       { findFirst: fn(), update: fn() },
  auction:           { findUnique: fn() },
  refunds:           { findUnique: fn(), create: fn() },
  ledger_entries:    { create: fn() },
  user:              { findUnique: fn() },
  $transaction:      fn(),
};

const RECV_EVENT = {
  id: BigInt(1), stripe_event_id: 'evt_1', type: 'checkout.session.completed',
  payload: '{}', status: 'received', error: null,
  created_at: new Date(), updated_at: new Date(),
};

describe('Phase 0.5 — WebhookHandlerService', () => {
  let svc: WebhookHandlerService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        WebhookHandlerService,
        { provide: SettlementService, useValue: mockSettlementService },
        { provide: PrismaService,     useValue: mockPrisma },
        { provide: EmailService,      useValue: mockEmailService },
        { provide: RefundService,     useValue: mockRefundService },
      ],
    }).compile();
    svc = module.get(WebhookHandlerService);
  });

  beforeEach(() => { jest.resetAllMocks(); });

  it('WH1: skips non-received status', async () => {
    mockPrisma.webhook_events.findUnique.mockResolvedValueOnce({ ...RECV_EVENT, status: 'processed' });
    await svc.processWebhookEvent(1);
    expect(mockPrisma.webhook_events.update).not.toHaveBeenCalled();
  });

  it('WH2: checkout.session.completed — sell-now: settles + marks paid', async () => {
    const payload = {
      type: 'checkout.session.completed',
      id: 'evt_2',
      data: { object: {
        id: 'cs_1', payment_intent: 'pi_1',
        metadata: { sell_now_offer_id: '5' },
      }},
    };
    mockPrisma.webhook_events.findUnique.mockResolvedValueOnce({
      ...RECV_EVENT, payload: JSON.stringify(payload),
    });
    mockPrisma.webhook_events.update.mockResolvedValue(undefined);
    mockPrisma.sell_now_offers.findFirst.mockResolvedValueOnce({
      id: BigInt(5), status: 'accepted',
      agreed_price_cents: BigInt(10000), offered_price_cents: BigInt(10000),
      currency: 'EUR', stripe_checkout_session_id: 'cs_1',
    });
    mockPrisma.sell_now_offers.update.mockResolvedValue(undefined);
    mockSettlementService.settle.mockResolvedValueOnce(1);
    mockPrisma.user.findUnique.mockResolvedValueOnce({ email: 'buyer@example.com' });
    mockEmailService.sendPaymentConfirmation.mockResolvedValueOnce(undefined);

    await svc.processWebhookEvent(1);

    expect(mockSettlementService.settle).toHaveBeenCalledWith(
      expect.objectContaining({ grossCents: 10000, profileKey: 'social_pilot_45_45_10' })
    );
    expect(mockPrisma.sell_now_offers.update).toHaveBeenCalledWith(
      expect.objectContaining({ data: expect.objectContaining({ status: 'paid' }) })
    );
  });

  it('WH3: checkout.session.completed — book purchase: stores PI id', async () => {
    const payload = {
      type: 'checkout.session.completed',
      id: 'evt_3',
      data: { object: {
        id: 'cs_2', payment_intent: 'pi_2',
        metadata: { book_purchase_id: '9' },
      }},
    };
    mockPrisma.webhook_events.findUnique.mockResolvedValueOnce({
      ...RECV_EVENT, payload: JSON.stringify(payload),
    });
    mockPrisma.webhook_events.update.mockResolvedValue(undefined);
    mockPrisma.book_purchases.updateMany.mockResolvedValueOnce({ count: 1 });

    await svc.processWebhookEvent(1);

    expect(mockPrisma.book_purchases.updateMany).toHaveBeenCalledWith(
      expect.objectContaining({ data: expect.objectContaining({ stripe_payment_intent_id: 'pi_2' }) })
    );
  });

  it('WH4: payment_intent.succeeded — book purchase: grants entitlement + settlement', async () => {
    const payload = {
      type: 'payment_intent.succeeded', id: 'evt_4',
      data: { object: { id: 'pi_book', amount_received: 5000, currency: 'eur' } },
    };
    mockPrisma.webhook_events.findUnique.mockResolvedValueOnce({
      ...RECV_EVENT, payload: JSON.stringify(payload),
    });
    mockPrisma.webhook_events.update.mockResolvedValue(undefined);
    mockPrisma.book_purchases.findFirst.mockResolvedValueOnce({
      id: BigInt(3), buyer_id: BigInt(10), book_id: BigInt(7),
      gross_cents: 5000, currency: 'EUR', profile_key: 'library_80_10_10',
      status: 'pending',
    });
    mockSettlementService.settle.mockResolvedValueOnce(2);
    mockPrisma.book_entitlements.create.mockResolvedValueOnce(undefined);
    mockPrisma.book_purchases.update.mockResolvedValueOnce(undefined);

    await svc.processWebhookEvent(1);

    expect(mockSettlementService.settle).toHaveBeenCalledWith(
      expect.objectContaining({ profileKey: 'library_80_10_10', grossCents: 5000 })
    );
    expect(mockPrisma.book_entitlements.create).toHaveBeenCalled();
    expect(mockPrisma.book_purchases.update).toHaveBeenCalledWith(
      expect.objectContaining({ data: expect.objectContaining({ status: 'completed' }) })
    );
  });

  it('WH5: payment_intent.succeeded — non-book: marks settlement completed', async () => {
    const payload = {
      type: 'payment_intent.succeeded', id: 'evt_5',
      data: { object: { id: 'pi_generic', amount: 1000, currency: 'eur' } },
    };
    mockPrisma.webhook_events.findUnique.mockResolvedValueOnce({
      ...RECV_EVENT, payload: JSON.stringify(payload),
    });
    mockPrisma.webhook_events.update.mockResolvedValue(undefined);
    mockPrisma.book_purchases.findFirst.mockResolvedValueOnce(null);
    mockPrisma.settlements.updateMany.mockResolvedValueOnce({ count: 1 });

    await svc.processWebhookEvent(1);

    expect(mockPrisma.settlements.updateMany).toHaveBeenCalledWith(
      expect.objectContaining({ data: expect.objectContaining({ status: 'completed' }) })
    );
  });

  it('WH6: payment_intent.amount_capturable_updated — settles closed auction', async () => {
    const payload = {
      type: 'payment_intent.amount_capturable_updated', id: 'evt_6',
      data: { object: { id: 'pi_auction' } },
    };
    mockPrisma.webhook_events.findUnique.mockResolvedValueOnce({
      ...RECV_EVENT, payload: JSON.stringify(payload),
    });
    mockPrisma.webhook_events.update.mockResolvedValue(undefined);
    mockPrisma.bid.findFirst.mockResolvedValueOnce({
      id: BigInt(1), amount_cents: BigInt(10000), auction_item_id: BigInt(2), status: 'won',
    });
    mockPrisma.auctionItem.findFirst.mockResolvedValueOnce({ id: BigInt(2), auction_id: 3 });
    mockPrisma.auction.findUnique.mockResolvedValueOnce({ id: 3, status: 'closed' });
    mockSettlementService.settle.mockResolvedValueOnce(10);
    mockPrisma.auctionItem.update.mockResolvedValueOnce(undefined);

    await svc.processWebhookEvent(1);

    expect(mockSettlementService.settle).toHaveBeenCalledWith(
      expect.objectContaining({ grossCents: 10000, auctionId: 3 })
    );
    expect(mockPrisma.auctionItem.update).toHaveBeenCalledWith(
      expect.objectContaining({ data: expect.objectContaining({ status: 'settled' }) })
    );
  });

  it('WH7: payment_intent.amount_capturable_updated — skips if auction not closed', async () => {
    const payload = {
      type: 'payment_intent.amount_capturable_updated', id: 'evt_7',
      data: { object: { id: 'pi_open' } },
    };
    mockPrisma.webhook_events.findUnique.mockResolvedValueOnce({
      ...RECV_EVENT, payload: JSON.stringify(payload),
    });
    mockPrisma.webhook_events.update.mockResolvedValue(undefined);
    mockPrisma.bid.findFirst.mockResolvedValueOnce({
      id: BigInt(1), amount_cents: BigInt(5000), auction_item_id: BigInt(2), status: 'won',
    });
    mockPrisma.auctionItem.findFirst.mockResolvedValueOnce({ id: BigInt(2), auction_id: 3 });
    mockPrisma.auction.findUnique.mockResolvedValueOnce({ id: 3, status: 'live' });

    await svc.processWebhookEvent(1);

    expect(mockSettlementService.settle).not.toHaveBeenCalled();
  });

  it('WH8: charge.refunded — delegates each refund to RefundService with mapped fields', async () => {
    const payload = {
      type: 'charge.refunded', id: 'evt_8',
      data: { object: {
        id: 'ch_1', payment_intent: 'pi_settled', amount_captured: 10000,
        refunds: { data: [{ id: 're_1', amount: 1000, currency: 'eur', status: 'succeeded', reason: 'duplicate' }] },
      }},
    };
    mockPrisma.webhook_events.findUnique.mockResolvedValueOnce({ ...RECV_EVENT, payload: JSON.stringify(payload) });
    mockPrisma.webhook_events.update.mockResolvedValue(undefined);
    mockRefundService.process.mockResolvedValueOnce({ refundId: BigInt(1), reversalOrderIds: [] });

    await svc.processWebhookEvent(1);

    expect(mockRefundService.process).toHaveBeenCalledTimes(1);
    expect(mockRefundService.process).toHaveBeenCalledWith({
      stripeRefundId: 're_1', paymentIntentId: 'pi_settled', amountCents: 1000, currency: 'EUR',
      refundStatus: 'succeeded', chargeId: 'ch_1', reason: 'duplicate',
    });
    // The handler itself never writes ledger rows; RefundService owns that.
    expect(mockPrisma.ledger_entries.create).not.toHaveBeenCalled();
  });

  it('WH9: charge.refunded — RefundService error: rethrown and webhook reset to received', async () => {
    const payload = {
      type: 'charge.refunded', id: 'evt_9',
      data: { object: {
        id: 'ch_2', payment_intent: 'pi_unknown',
        refunds: { data: [{ id: 're_2', amount: 500, currency: 'eur', status: 'succeeded' }] },
      }},
    };
    mockPrisma.webhook_events.findUnique.mockResolvedValueOnce({ ...RECV_EVENT, payload: JSON.stringify(payload) });
    mockPrisma.webhook_events.update.mockResolvedValue(undefined);
    mockRefundService.process.mockRejectedValueOnce(new Error('No settlement found for payment_intent pi_unknown.'));

    await expect(svc.processWebhookEvent(1)).rejects.toThrow('No settlement found');

    const resetCall = mockPrisma.webhook_events.update.mock.calls[1];
    expect((resetCall as any)[0].data.status).toBe('received');
  });

  it('WH10: unknown event type — no error', async () => {
    const payload = { type: 'customer.created', id: 'evt_10', data: { object: {} } };
    mockPrisma.webhook_events.findUnique.mockResolvedValueOnce({
      ...RECV_EVENT, payload: JSON.stringify(payload),
    });
    mockPrisma.webhook_events.update.mockResolvedValue(undefined);

    await expect(svc.processWebhookEvent(1)).resolves.not.toThrow();
  });

  it('WH12: checkout.session.completed — auction item path: settles + marks paid', async () => {
    const payload = {
      type: 'checkout.session.completed',
      id: 'evt_12',
      data: { object: {
        id: 'cs_auction', payment_intent: 'pi_auction_checkout',
        metadata: { auction_item_id: '7', user_id: '3' },
      }},
    };
    mockPrisma.webhook_events.findUnique.mockResolvedValueOnce({
      ...RECV_EVENT, payload: JSON.stringify(payload),
    });
    mockPrisma.webhook_events.update.mockResolvedValue(undefined);
    mockPrisma.auctionItem.findFirst.mockResolvedValueOnce({
      id: BigInt(7), status: 'won',
      winning_bid_amount_cents: BigInt(25000), currency: 'EUR',
      stripe_checkout_session_id: 'cs_auction',
    });
    mockPrisma.auctionItem.update.mockResolvedValue(undefined);
    mockSettlementService.settle.mockResolvedValueOnce(5);
    mockPrisma.user.findUnique.mockResolvedValueOnce({ email: 'winner@example.com' });
    mockEmailService.sendPaymentConfirmation.mockResolvedValueOnce(undefined);

    await svc.processWebhookEvent(1);

    expect(mockSettlementService.settle).toHaveBeenCalledWith(
      expect.objectContaining({ grossCents: 25000, profileKey: 'social_pilot_45_45_10' })
    );
    expect(mockPrisma.auctionItem.update).toHaveBeenCalledWith(
      expect.objectContaining({ data: expect.objectContaining({ status: 'paid' }) })
    );
  });

  it('WH13: checkout.session.completed — auction item not won: no settlement', async () => {
    const payload = {
      type: 'checkout.session.completed',
      id: 'evt_13',
      data: { object: {
        id: 'cs_not_won', payment_intent: 'pi_not_won',
        metadata: { auction_item_id: '8', user_id: '4' },
      }},
    };
    mockPrisma.webhook_events.findUnique.mockResolvedValueOnce({
      ...RECV_EVENT, payload: JSON.stringify(payload),
    });
    mockPrisma.webhook_events.update.mockResolvedValue(undefined);
    mockPrisma.auctionItem.findFirst.mockResolvedValueOnce(null);

    await svc.processWebhookEvent(1);

    expect(mockSettlementService.settle).not.toHaveBeenCalled();
    expect(mockPrisma.auctionItem.update).not.toHaveBeenCalled();
  });

  it('WH14: charge.refunded — several refunds on one charge: each processed in order', async () => {
    const payload = {
      type: 'charge.refunded', id: 'evt_14',
      data: { object: {
        id: 'ch_3', payment_intent: 'pi_settled2', amount_captured: 10000,
        refunds: { data: [
          { id: 're_a', amount: 3333, currency: 'eur', status: 'succeeded' },
          { id: 're_b', amount: 6666, currency: 'eur', status: 'succeeded' },
        ] },
      }},
    };
    mockPrisma.webhook_events.findUnique.mockResolvedValueOnce({ ...RECV_EVENT, payload: JSON.stringify(payload) });
    mockPrisma.webhook_events.update.mockResolvedValue(undefined);
    mockRefundService.process.mockResolvedValue({ refundId: BigInt(1), reversalOrderIds: [] });

    await svc.processWebhookEvent(1);

    expect(mockRefundService.process).toHaveBeenCalledTimes(2);
    expect((mockRefundService.process.mock.calls[0] as any)[0].stripeRefundId).toBe('re_a');
    expect((mockRefundService.process.mock.calls[1] as any)[0].stripeRefundId).toBe('re_b');
  });

  it('WH17: charge.refunded — no payment_intent: RefundService not called', async () => {
    const payload = {
      type: 'charge.refunded', id: 'evt_17',
      data: { object: { id: 'ch_9', refunds: { data: [{ id: 're_x', amount: 100, currency: 'eur', status: 'succeeded' }] } } },
    };
    mockPrisma.webhook_events.findUnique.mockResolvedValueOnce({ ...RECV_EVENT, payload: JSON.stringify(payload) });
    mockPrisma.webhook_events.update.mockResolvedValue(undefined);

    await svc.processWebhookEvent(1);

    expect(mockRefundService.process).not.toHaveBeenCalled();
  });

  it('WH15: checkout.session.completed — sell-now offer not found: no-op', async () => {
    const payload = {
      type: 'checkout.session.completed',
      id: 'evt_15',
      data: { object: {
        id: 'cs_ghost', payment_intent: 'pi_ghost',
        metadata: { sell_now_offer_id: '99' },
      }},
    };
    mockPrisma.webhook_events.findUnique.mockResolvedValueOnce({
      ...RECV_EVENT, payload: JSON.stringify(payload),
    });
    mockPrisma.webhook_events.update.mockResolvedValue(undefined);
    mockPrisma.sell_now_offers.findFirst.mockResolvedValueOnce(null);

    await svc.processWebhookEvent(1);

    expect(mockSettlementService.settle).not.toHaveBeenCalled();
    expect(mockPrisma.sell_now_offers.update).not.toHaveBeenCalled();
  });

  it('WH16: payment_intent.payment_failed — no DB writes, resolves cleanly', async () => {
    const payload = {
      type: 'payment_intent.payment_failed', id: 'evt_16',
      data: { object: { id: 'pi_failed_001' } },
    };
    mockPrisma.webhook_events.findUnique.mockResolvedValueOnce({
      ...RECV_EVENT, payload: JSON.stringify(payload),
    });
    mockPrisma.webhook_events.update.mockResolvedValue(undefined);

    await svc.processWebhookEvent(1);

    // Only the two lifecycle updates (processing → processed) — no domain DB calls
    expect(mockPrisma.webhook_events.update).toHaveBeenCalledTimes(2);
    expect(mockSettlementService.settle).not.toHaveBeenCalled();
  });

  it('WH11: resets status to received on error', async () => {
    const payload = {
      type: 'payment_intent.succeeded', id: 'evt_11',
      data: { object: { id: 'pi_err' } },
    };
    mockPrisma.webhook_events.findUnique.mockResolvedValueOnce({
      ...RECV_EVENT, payload: JSON.stringify(payload),
    });
    mockPrisma.webhook_events.update.mockResolvedValueOnce(undefined); // mark processing
    mockPrisma.book_purchases.findFirst.mockRejectedValueOnce(new Error('DB error'));
    mockPrisma.webhook_events.update.mockResolvedValueOnce(undefined); // reset to received

    await expect(svc.processWebhookEvent(1)).rejects.toThrow('DB error');

    const resetCall = mockPrisma.webhook_events.update.mock.calls[1];
    expect((resetCall as any)[0].data.status).toBe('received');
  });
});
