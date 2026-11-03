/**
 * Phase 0.3T — Sell-now offer mutation route tests
 *
 * SN1  POST /sell-now-offers/:id/counter — 200 counter offer
 * SN2  POST /sell-now-offers/:id/accept — 200 accept offer
 * SN3  POST /sell-now-offers/:id/reject — 200 reject offer
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { Injectable } from '@nestjs/common';
import { SellNowController } from './sell-now.controller';
import { SellNowService } from './sell-now.service';
import type { AuthenticatedUser, JwtPayload } from '../auth/auth.types';

const JWT_SECRET = 'test-sell-now-mut-secret';

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
  createOffer: async () => ({ id: 1 }),
  listUserOffers: async () => ({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } }),
  getOffer: async () => ({ id: 1, status: 'submitted' }),
  counterOffer: async () => ({ id: 1, status: 'countered', counter_price_cents: 150000, updated_at: null }),
  acceptOffer: async () => ({ id: 1, status: 'accepted', agreed_price_cents: 100000, updated_at: null }),
  rejectOffer: async () => ({ id: 1, status: 'rejected', updated_at: null }),
  checkoutSession: async () => ({ checkout_url: 'https://stripe.com/checkout/test', session_id: 'cs_test' }),
};

import jwt from 'jsonwebtoken';
const { sign } = jwt;
function makeToken(role = 'user') {
  return sign({ sub: 1, email: 'user@example.com', role }, JWT_SECRET, { expiresIn: '1h' });
}

describe('Phase 0.3T — Sell-now offer mutations controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: JWT_SECRET })],
      controllers: [SellNowController],
      providers: [{ provide: SellNowService, useValue: mockService }, TestJwtStrategy],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('SN1: POST /sell-now-offers/:id/counter returns 200', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/sell-now-offers/1/counter',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ counter_price_cents: 150000 }),
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.status).toBe('countered');
  });

  it('SN2: POST /sell-now-offers/:id/accept returns 200', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/sell-now-offers/1/accept',
      headers: { authorization: `Bearer ${makeToken()}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.status).toBe('accepted');
  });

  it('SN3: POST /sell-now-offers/:id/reject returns 200', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/sell-now-offers/1/reject',
      headers: { authorization: `Bearer ${makeToken()}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.status).toBe('rejected');
  });

  it('SN4: POST /sell-now-offers/:id/checkout-session returns 200 with checkout_url', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/sell-now-offers/1/checkout-session',
      headers: { authorization: `Bearer ${makeToken()}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.checkout_url).toBeDefined();
    expect(body.session_id).toBeDefined();
  });
});
