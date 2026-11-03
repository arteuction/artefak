/**
 * Phase 0.3L — ArtLot public route tests
 *
 * AL1  GET  /art-lots                           — list with pagination
 * AL2  GET  /art-lots/:artLot                   — get by ID/slug
 * AL3  GET  /art-lots/:artLot/bids              — list accepted bids
 * AL4  GET  /art-lots/:artLot/provenance        — provenance chain
 * AL5  GET  /art-lots?status=active             — filter by status
 * AL6  GET  /art-lots/:artLot/sell-now-offers   — 200 auth (list offers)
 * AL7  POST /art-lots                           — 201 create art lot
 * AL8  PATCH /art-lots/:artLot                  — 200 update art lot
 * AL9  POST /art-lots/:artLot/purchase-now      — 200 purchase at buy-now price
 * AL10 POST /art-lots/:artLot/transitions/:t    — 200 state transition
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { Injectable } from '@nestjs/common';
import { ArtLotController } from './art-lot.controller';
import { ArtLotService } from './art-lot.service';
import type { AuthenticatedUser, JwtPayload } from '../auth/auth.types';

const JWT_SECRET = 'test-art-lot-secret';

@Injectable()
class TestJwtStrategy extends PassportStrategy(Strategy, 'jwt') {
  constructor() {
    super({ jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(), ignoreExpiration: false, secretOrKey: JWT_SECRET });
  }
  async validate(payload: JwtPayload): Promise<AuthenticatedUser> {
    return { id: payload.sub, email: payload.email, role: payload.role as AuthenticatedUser['role'], emailVerifiedAt: null };
  }
}

import jwt from 'jsonwebtoken';
const { sign } = jwt;
function makeToken(role = 'admin') {
  return sign({ sub: 1, email: 'admin@example.com', role }, JWT_SECRET, { expiresIn: '1h' });
}

const STUB_LOT = {
  id: 1, sale_mode: 'sell_now', status: 'active',
  starting_bid_cents: null, buy_now_price_cents: 150000,
  currency: 'BGN', estimate_low_cents: null, estimate_high_cents: null,
  published_at: new Date('2026-01-01').toISOString(), closed_at: null,
  created_at: new Date('2026-01-01').toISOString(),
};

const STUB_BID = {
  id: 1, amount_cents: 75000, status: 'accepted',
  bid_type: 'live', created_at: new Date('2026-04-01').toISOString(),
};

const mockArtLotService = {
  listArtLots: async () => ({
    data: [STUB_LOT],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  }),
  getArtLot: async (id: string) => {
    if (id === '999') throw new NotFoundException('ArtLot "999" not found');
    return STUB_LOT;
  },
  listBids: async () => [STUB_BID],
  getProvenance: async () => [],
  listSellNowOffers: async () => ({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } }),
  createArtLot: async () => ({ ...STUB_LOT, id: 2, status: 'draft' }),
  updateArtLot: async (id: string) => ({ ...STUB_LOT, id: Number(id), buy_now_price_cents: 200000 }),
  purchaseNow: async (id: string) => ({ ...STUB_LOT, id: Number(id), status: 'sold' }),
  transition: async (id: string, transition: string) => ({ ...STUB_LOT, id: Number(id), status: transition === 'publish' ? 'active' : transition }),
};

describe('Phase 0.3L — ArtLot controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: JWT_SECRET })],
      controllers: [ArtLotController],
      providers: [{ provide: ArtLotService, useValue: mockArtLotService }, TestJwtStrategy],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('AL1: GET /art-lots returns 200 with pagination', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/art-lots' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
    expect(body.data[0]).toHaveProperty('sale_mode');
  });

  it('AL2: GET /art-lots/:artLot returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/art-lots/1' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('id', 1);
  });

  it('AL3: GET /art-lots/:artLot/bids returns 200 with array', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/art-lots/1/bids' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body)).toBe(true);
    expect(body[0]).toHaveProperty('amount_cents');
  });

  it('AL4: GET /art-lots/:artLot/provenance returns 200 with array', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/art-lots/1/provenance' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body)).toBe(true);
  });

  it('AL5: GET /art-lots?status=active returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/art-lots?status=active' });
    expect(res.statusCode).toBe(200);
  });

  it('AL6: GET /art-lots/:artLot/sell-now-offers returns 200 when authenticated', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/art-lots/1/sell-now-offers', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
  });

  it('AL7: POST /art-lots creates art lot and returns 201', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/art-lots',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ consignment_id: 1, sale_mode: 'sell_now', buy_now_price_cents: 150000 }),
    });
    expect(res.statusCode).toBe(201);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('id', 2);
    expect(body.status).toBe('draft');
  });

  it('AL8: PATCH /art-lots/:artLot returns 200 with updated lot', async () => {
    const res = await app.inject({
      method: 'PATCH', url: '/api/v1/art-lots/1',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ buy_now_price_cents: 200000 }),
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('buy_now_price_cents', 200000);
  });

  it('AL9: POST /art-lots/:artLot/purchase-now returns 200 with sold status', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/art-lots/1/purchase-now',
      headers: { authorization: `Bearer ${makeToken('buyer')}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.status).toBe('sold');
  });

  it('AL10: POST /art-lots/:artLot/transitions/publish returns 200', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/art-lots/1/transitions/publish',
      headers: { authorization: `Bearer ${makeToken('admin')}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.status).toBe('active');
  });
});
