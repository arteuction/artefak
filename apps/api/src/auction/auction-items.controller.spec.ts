/**
 * Phase 0.3S — Auction-items sub-route tests
 *
 * AI1  GET  /auction-items/:item/bid-history     — 200 public
 * AI2  POST /auction-items/:item/re-authorize    — 200 auth
 * AI3  POST /auction-items/:item/ship            — 200 auth
 * AI4  POST /auction-items/:item/confirm-delivery — 200 auth
 * AI5  POST /auction-items/:item/checkout-session — 201 winner creates checkout URL
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { Injectable } from '@nestjs/common';
import { AuctionItemsController } from './auction-items.controller';
import { AuctionService } from './auction.service';
import type { AuthenticatedUser, JwtPayload } from '../auth/auth.types';

const JWT_SECRET = 'test-auction-items-secret';

@Injectable()
class TestJwtStrategy extends PassportStrategy(Strategy, 'jwt') {
  constructor() {
    super({ jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(), ignoreExpiration: false, secretOrKey: JWT_SECRET });
  }
  async validate(payload: JwtPayload): Promise<AuthenticatedUser> {
    return { id: payload.sub, email: payload.email, role: payload.role as AuthenticatedUser['role'], emailVerifiedAt: null };
  }
}

const mockService = {
  getBidHistory: async () => [
    { id: 1, amount_cents: 50000, status: 'accepted', bid_type: 'live', created_at: null },
  ],
  reAuthorizeItem: async (id: number) => ({ id, payment_re_authorized: true }),
  shipItem: async (id: number) => ({ id, fulfillment_status: 'shipped', updated_at: null }),
  confirmDelivery: async (id: number) => ({ id, fulfillment_status: 'delivered', updated_at: null }),
  createCheckoutSession: async (_itemId: number, _userId: number, _body: Record<string, unknown>) => ({
    checkout_url: 'https://checkout.stripe.com/test/cs_test123',
    amount_cents: 25000,
    currency: 'eur',
  }),
};

import jwt from 'jsonwebtoken';
const { sign } = jwt;
function makeToken(role = 'admin') {
  return sign({ sub: 1, email: 'admin@example.com', role }, JWT_SECRET, { expiresIn: '1h' });
}

describe('Phase 0.3S — Auction items controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: JWT_SECRET })],
      controllers: [AuctionItemsController],
      providers: [{ provide: AuctionService, useValue: mockService }, TestJwtStrategy],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('AI1: GET /auction-items/:item/bid-history returns 200 (public)', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/auction-items/1/bid-history' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body)).toBe(true);
  });

  it('AI2: POST /auction-items/:item/re-authorize returns 200', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/auction-items/1/re-authorize',
      headers: { authorization: `Bearer ${makeToken()}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.payment_re_authorized).toBe(true);
  });

  it('AI3: POST /auction-items/:item/ship returns 200', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/auction-items/1/ship',
      headers: { authorization: `Bearer ${makeToken()}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.fulfillment_status).toBe('shipped');
  });

  it('AI4: POST /auction-items/:item/confirm-delivery returns 200', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/auction-items/1/confirm-delivery',
      headers: { authorization: `Bearer ${makeToken()}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.fulfillment_status).toBe('delivered');
  });

  it('AI5: POST /auction-items/:item/checkout-session returns 201 with checkout_url', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/auction-items/1/checkout-session',
      payload: { success_url: 'https://example.com/success', cancel_url: 'https://example.com/cancel' },
      headers: { authorization: `Bearer ${makeToken('buyer')}`, 'content-type': 'application/json' },
    });
    expect(res.statusCode).toBe(201);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('checkout_url');
    expect(body).toHaveProperty('amount_cents', 25000);
    expect(body).toHaveProperty('currency', 'eur');
  });
});
