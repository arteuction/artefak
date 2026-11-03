/**
 * Phase 0.5 — StripeWebhookController
 *
 * SW1  valid signature + new event: stores + processes, returns {received:true}
 * SW2  invalid signature: throws BadRequestException
 * SW3  missing signature header: throws BadRequestException
 * SW4  idempotent: P2002 returns 200 without processing
 * SW5  processing error: swallows error, still returns {received:true}
 * SW6  missing STRIPE_WEBHOOK_SECRET: returns {received:true} without verify
 * SW7  malformed JSON body: throws BadRequestException
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { BadRequestException } from '@nestjs/common';
import { createHmac } from 'node:crypto';
import { StripeWebhookController } from './stripe-webhook.controller';
import { WebhookHandlerService } from './webhook-handler.service';
import { PrismaService } from '../prisma.service';

const fn = () => jest.fn() as any;

const WEBHOOK_SECRET = 'whsec_test_secret_for_spec';

const mockHandler  = { processWebhookEvent: fn() };
const mockPrisma   = { webhook_events: { create: fn() } };

function makeSignedRequest(body: object, secret: string) {
  const rawBody  = Buffer.from(JSON.stringify(body));
  const ts       = Math.floor(Date.now() / 1000).toString();
  const signed   = `${ts}.${rawBody.toString('utf8')}`;
  const mac      = createHmac('sha256', secret).update(signed).digest('hex');
  const sig      = `t=${ts},v1=${mac}`;
  return { rawBody, sig };
}

const STUB_EVENT = { id: 'evt_test_1', type: 'payment_intent.succeeded', data: { object: {} } };

describe('Phase 0.5 — StripeWebhookController', () => {
  let ctrl: StripeWebhookController;
  let origSecret: string | undefined;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [StripeWebhookController],
      providers: [
        { provide: WebhookHandlerService, useValue: mockHandler },
        { provide: PrismaService,         useValue: mockPrisma },
      ],
    }).compile();
    ctrl = module.get(StripeWebhookController);
    origSecret = process.env.STRIPE_WEBHOOK_SECRET;
    process.env.STRIPE_WEBHOOK_SECRET = WEBHOOK_SECRET;
  });

  afterAll(() => {
    process.env.STRIPE_WEBHOOK_SECRET = origSecret;
  });

  beforeEach(() => { jest.resetAllMocks(); });

  it('SW1: valid signature + new event: stores + processes', async () => {
    const { rawBody, sig } = makeSignedRequest(STUB_EVENT, WEBHOOK_SECRET);
    mockPrisma.webhook_events.create.mockResolvedValueOnce({ id: BigInt(1) });
    mockHandler.processWebhookEvent.mockResolvedValueOnce(undefined);

    const result = await ctrl.handle({ rawBody } as any, sig);

    expect(result).toEqual({ received: true });
    expect(mockPrisma.webhook_events.create).toHaveBeenCalledWith(
      expect.objectContaining({ data: expect.objectContaining({ stripe_event_id: 'evt_test_1' }) })
    );
    expect(mockHandler.processWebhookEvent).toHaveBeenCalledWith(1);
  });

  it('SW2: invalid signature: throws BadRequestException', async () => {
    const rawBody = Buffer.from(JSON.stringify(STUB_EVENT));
    const badSig  = 't=12345,v1=deadbeefdeadbeef';

    await expect(ctrl.handle({ rawBody } as any, badSig))
      .rejects.toBeInstanceOf(BadRequestException);
  });

  it('SW3: missing signature header: throws BadRequestException', async () => {
    const rawBody = Buffer.from(JSON.stringify(STUB_EVENT));

    await expect(ctrl.handle({ rawBody } as any, undefined as any))
      .rejects.toBeInstanceOf(BadRequestException);
  });

  it('SW4: idempotent — P2002 returns 200 without processing', async () => {
    const { rawBody, sig } = makeSignedRequest(STUB_EVENT, WEBHOOK_SECRET);
    const p2002 = Object.assign(new Error('Unique constraint'), { code: 'P2002' });
    mockPrisma.webhook_events.create.mockRejectedValueOnce(p2002);

    const result = await ctrl.handle({ rawBody } as any, sig);

    expect(result).toEqual({ received: true });
    expect(mockHandler.processWebhookEvent).not.toHaveBeenCalled();
  });

  it('SW5: processing error swallowed — still returns {received:true}', async () => {
    const { rawBody, sig } = makeSignedRequest(STUB_EVENT, WEBHOOK_SECRET);
    mockPrisma.webhook_events.create.mockResolvedValueOnce({ id: BigInt(2) });
    mockHandler.processWebhookEvent.mockRejectedValueOnce(new Error('processing blew up'));

    const result = await ctrl.handle({ rawBody } as any, sig);

    expect(result).toEqual({ received: true });
  });

  it('SW6: missing STRIPE_WEBHOOK_SECRET: returns {received:true}', async () => {
    const savedSecret = process.env.STRIPE_WEBHOOK_SECRET;
    delete process.env.STRIPE_WEBHOOK_SECRET;

    const rawBody = Buffer.from(JSON.stringify(STUB_EVENT));
    const result  = await ctrl.handle({ rawBody } as any, 't=1,v1=abc');

    expect(result).toEqual({ received: true });
    process.env.STRIPE_WEBHOOK_SECRET = savedSecret;
  });

  it('SW7: malformed JSON body: throws BadRequestException', async () => {
    const rawBody = Buffer.from('not-json{{{');
    const secret  = WEBHOOK_SECRET;
    const ts      = '12345';
    const signed  = `${ts}.${rawBody.toString('utf8')}`;
    const mac     = createHmac('sha256', secret).update(signed).digest('hex');
    const sig     = `t=${ts},v1=${mac}`;

    await expect(ctrl.handle({ rawBody } as any, sig))
      .rejects.toBeInstanceOf(BadRequestException);
  });
});
