/**
 * Phase 0.3I — Sell-now offer routes integration tests
 *
 * Routes covered:
 *   POST /api/v1/art-lots/:id/sell-now-offers    — submit buy-now offer (auth required)
 *   GET  /api/v1/me/sell-now-offers              — list own offers (auth required)
 *   GET  /api/v1/me/sell-now-offers/:id          — single offer detail (auth required)
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { BadRequestException, NotFoundException } from '@nestjs/common';
import { JwtModule, JwtService } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { Injectable } from '@nestjs/common';

import { SellNowController } from './sell-now.controller';
import { SellNowService } from './sell-now.service';
import type { AuthenticatedUser, JwtPayload } from '../auth/auth.types';

const JWT_SECRET = 'test-sellnow-secret';

@Injectable()
class TestJwtStrategy extends PassportStrategy(Strategy, 'jwt') {
  constructor() {
    super({ jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(), ignoreExpiration: false, secretOrKey: JWT_SECRET });
  }
  async validate(payload: JwtPayload): Promise<AuthenticatedUser> {
    return { id: payload.sub, email: payload.email, role: payload.role as AuthenticatedUser['role'], emailVerifiedAt: null };
  }
}

// ── stubs ──────────────────────────────────────────────────────────────────────

const STUB_OFFER = {
  id: 1,
  art_lot_id: 5,
  buyer_id: 99,
  offered_price_cents: 80000,
  currency: 'EUR',
  status: 'submitted',
  notes: null,
  created_at: new Date('2026-06-01').toISOString(),
};

const STUB_OFFER_DETAIL = {
  ...STUB_OFFER,
  counter_price_cents: null,
  agreed_price_cents: null,
  expires_at: null,
  updated_at: new Date('2026-06-01').toISOString(),
  art_lots: { id: 5, currency: 'EUR', artworks: { id: 3, title: 'Still Life', slug: 'still-life', medium: 'oil' } },
};

const STUB_LIST = {
  data: [STUB_OFFER_DETAIL],
  meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
};

const mockSellNowService = {
  createOffer: async (_lotId: number, _userId: number, dto: { offered_price_cents: number }) => {
    if (!dto.offered_price_cents) throw new BadRequestException('offered_price_cents must be a positive integer');
    return STUB_OFFER;
  },
  listUserOffers: async () => STUB_LIST,
  getOffer: async (id: number) => {
    if (id === 999) throw new NotFoundException(`Offer 999 not found`);
    return STUB_OFFER_DETAIL;
  },
};

// ── helpers ───────────────────────────────────────────────────────────────────

import jwt from 'jsonwebtoken';
const { sign } = jwt;

function makeToken() {
  return sign({ sub: 99, email: 'buyer@example.com', role: 'user' }, JWT_SECRET, { expiresIn: '1h' });
}

// ── suite ──────────────────────────────────────────────────────────────────────

describe('Phase 0.3I — SellNow controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: JWT_SECRET })],
      controllers: [SellNowController],
      providers: [
        { provide: SellNowService, useValue: mockSellNowService },
        TestJwtStrategy,
      ],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => {
    await app.close();
  });

  it('SN1: POST /art-lots/:id/sell-now-offers returns 201 with offer', async () => {
    const token = makeToken();
    const res = await app.inject({
      method: 'POST',
      url: '/api/v1/art-lots/5/sell-now-offers',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ offered_price_cents: 80000 }),
    });
    expect(res.statusCode).toBe(201);
    const body = JSON.parse(res.payload);
    expect(body).toMatchObject({ status: 'submitted', currency: 'EUR' });
  });

  it('SN2: POST /art-lots/:id/sell-now-offers without token returns 401', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/art-lots/5/sell-now-offers',
      headers: { 'content-type': 'application/json' },
      payload: JSON.stringify({ offered_price_cents: 80000 }),
    });
    expect(res.statusCode).toBe(401);
  });

  it('SN3: GET /me/sell-now-offers returns 200 with paginated list', async () => {
    const token = makeToken();
    const res = await app.inject({
      method: 'GET', url: '/api/v1/me/sell-now-offers',
      headers: { authorization: `Bearer ${token}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
  });

  it('SN4: GET /me/sell-now-offers/:id returns 200 with detail', async () => {
    const token = makeToken();
    const res = await app.inject({
      method: 'GET', url: '/api/v1/me/sell-now-offers/1',
      headers: { authorization: `Bearer ${token}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('art_lots');
  });

  it('SN5: GET /me/sell-now-offers/999 returns 404', async () => {
    const token = makeToken();
    const res = await app.inject({
      method: 'GET', url: '/api/v1/me/sell-now-offers/999',
      headers: { authorization: `Bearer ${token}` },
    });
    expect(res.statusCode).toBe(404);
  });
});
