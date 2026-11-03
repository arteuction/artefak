/**
 * Phase 0.6A — StripeService unit tests
 *
 * ST1  disabled (no STRIPE_SECRET_KEY): createCheckoutSession returns null checkout_url
 * ST2  disabled: retrieveSession returns null
 * ST3  disabled: constructEvent throws
 * ST4  disabled: retrieveTransfer returns null
 * ST5  disabled: findTransferByGroup returns null
 * ST6  disabled: createTransfer returns null
 * ST7  enabled (mock): createCheckoutSession calls stripe.checkout.sessions.create
 * ST8  enabled (mock): createTransfer calls stripe.transfers.create with idempotency_key
 * ST9  enabled (mock): findTransferByGroup returns match when single verified transfer
 * ST10 enabled (mock): findTransferByGroup returns null when multiple verified matches
 */

import { jest } from '@jest/globals';
import { StripeService } from './stripe.service';

describe('Phase 0.6A — StripeService', () => {
  describe('disabled (no credentials)', () => {
    let svc: StripeService;

    beforeAll(() => {
      delete process.env.STRIPE_SECRET_KEY;
      svc = new StripeService();
    });

    it('ST1: createCheckoutSession returns null urls', async () => {
      const result = await svc.createCheckoutSession({
        lineItems: [{ name: 'Test', amount_cents: 1000, currency: 'eur' }],
        successUrl: 'https://example.com/success',
        cancelUrl: 'https://example.com/cancel',
      });
      expect(result.checkout_url).toBeNull();
      expect(result.session_id).toBeNull();
    });

    it('ST2: retrieveSession returns null', async () => {
      const result = await svc.retrieveSession('cs_test');
      expect(result).toBeNull();
    });

    it('ST3: constructEvent throws when stripe not configured', () => {
      expect(() => svc.constructEvent('{}', 'sig', 'secret')).toThrow('Stripe not configured');
    });

    it('ST4: retrieveTransfer returns null', async () => {
      const result = await svc.retrieveTransfer('tr_test');
      expect(result).toBeNull();
    });

    it('ST5: findTransferByGroup returns null', async () => {
      const result = await svc.findTransferByGroup({
        transferGroup: 'grp', externalRef: 'ref', amountCents: 100, currency: 'eur', destination: 'acct_1',
      });
      expect(result).toBeNull();
    });

    it('ST6: createTransfer returns null', async () => {
      const result = await svc.createTransfer({ amount_cents: 100, currency: 'eur', destination: 'acct_1' });
      expect(result).toBeNull();
    });
  });

  describe('enabled (mocked stripe client)', () => {
    let svc: StripeService;

    const mockSession = { id: 'cs_mock', url: 'https://checkout.stripe.com/mock' };
    const mockTransfer = { id: 'tr_mock', amount: 5000 };

    const mockStripeClient = {
      checkout: { sessions: { create: jest.fn(async () => mockSession) as any } },
      transfers: {
        create: jest.fn(async () => mockTransfer) as any,
        retrieve: jest.fn(async () => ({ ...mockTransfer, destination: 'acct_1', currency: 'eur', metadata: {} })) as any,
        list: jest.fn(async () => ({ data: [] })) as any,
      },
    };

    beforeAll(() => {
      process.env.STRIPE_SECRET_KEY = 'sk_test_mock';
      svc = new StripeService();
      // Inject mock client directly
      (svc as any).stripe = mockStripeClient;
    });

    afterAll(() => {
      delete process.env.STRIPE_SECRET_KEY;
    });

    beforeEach(() => { jest.resetAllMocks(); });

    it('ST7: createCheckoutSession calls stripe and returns url + session_id', async () => {
      mockStripeClient.checkout.sessions.create.mockResolvedValueOnce(mockSession);
      const result = await svc.createCheckoutSession({
        lineItems: [{ name: 'Artwork', amount_cents: 10000, currency: 'eur', description: 'Test' }],
        successUrl: 'https://example.com/success',
        cancelUrl: 'https://example.com/cancel',
        clientReferenceId: 'auction_item:1',
        metadata: { auction_item_id: '1' },
      });
      expect(result.checkout_url).toBe('https://checkout.stripe.com/mock');
      expect(result.session_id).toBe('cs_mock');
      expect(mockStripeClient.checkout.sessions.create).toHaveBeenCalledWith(
        expect.objectContaining({
          mode: 'payment',
          client_reference_id: 'auction_item:1',
          metadata: { auction_item_id: '1' },
        })
      );
    });

    it('ST8: createTransfer passes idempotency_key as request option', async () => {
      mockStripeClient.transfers.create.mockResolvedValueOnce(mockTransfer);
      const result = await svc.createTransfer({
        amount_cents: 5000,
        currency: 'EUR',
        destination: 'acct_1',
        transfer_group: 'grp_1',
        idempotency_key: 'idem_key_1',
        metadata: { external_ref: 'ext_1' },
      });
      expect(result?.transfer_id).toBe('tr_mock');
      expect(mockStripeClient.transfers.create).toHaveBeenCalledWith(
        expect.objectContaining({ amount: 5000, currency: 'eur', destination: 'acct_1' }),
        expect.objectContaining({ idempotencyKey: 'idem_key_1' }),
      );
    });

    it('ST9: findTransferByGroup returns match when exactly one verified transfer', async () => {
      mockStripeClient.transfers.list.mockResolvedValueOnce({
        data: [{
          id: 'tr_verified', amount: 1000, currency: 'eur',
          destination: 'acct_dest', metadata: { external_ref: 'ref_1' },
        }],
      });
      const result = await svc.findTransferByGroup({
        transferGroup: 'grp_1', externalRef: 'ref_1', amountCents: 1000, currency: 'EUR', destination: 'acct_dest',
      });
      expect(result?.transfer_id).toBe('tr_verified');
    });

    it('ST10: findTransferByGroup returns null when multiple verified matches (ambiguous)', async () => {
      const t = { amount: 1000, currency: 'eur', destination: 'acct_dest', metadata: { external_ref: 'ref_dup' } };
      mockStripeClient.transfers.list.mockResolvedValueOnce({
        data: [{ ...t, id: 'tr_1' }, { ...t, id: 'tr_2' }],
      });
      const result = await svc.findTransferByGroup({
        transferGroup: 'grp_dup', externalRef: 'ref_dup', amountCents: 1000, currency: 'EUR', destination: 'acct_dest',
      });
      expect(result).toBeNull();
    });
  });
});
