/**
 * Phase 0.3C — Auction public routes + health check integration tests
 *
 * Tests controller routing and response shape without a real DB.
 * All DB calls are stubbed on AuctionService.
 *
 * AC1  GET  /auctions                     — list
 * AC2  GET  /auctions?status=live         — filter
 * AC3  GET  /auctions/:id                 — show
 * AC4  GET  /auctions/:slug               — show by slug
 * AC5  GET  /auctions/not-found           — 404
 * AC6  GET  /auctions/:a/items/:i         — show item
 * AC7  GET  /health                       — health
 * AC8  GET  /auctions/:a/items/:i/bids    — bid history
 * AC9  POST /auctions/:a/items/:i/max-bid — 200 inline stub (auth)
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication } from '@nestjs/platform-fastify';
import { FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException, Injectable } from '@nestjs/common';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';

import { AuctionController } from './auction.controller';
import { AuctionService } from './auction.service';
import { HealthController } from '../health.controller';
import type { AuthenticatedUser, JwtPayload } from '../auth/auth.types';

const JWT_SECRET = 'test-auction-secret';

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
function makeToken(role = 'user') {
  return sign({ sub: 1, email: 'user@example.com', role }, JWT_SECRET, { expiresIn: '1h' });
}

// ── stubs ─────────────────────────────────────────────────────────────────────

const STUB_AUCTION = {
  id: 1,
  title: 'Spring Sale',
  slug: 'spring-sale',
  status: 'live',
  starts_at: new Date('2026-03-01').toISOString(),
  ends_at: new Date('2026-03-31').toISOString(),
  currency: 'EUR',
  description: null,
  venue_id: null,
  ruleset_id: null,
  created_at: new Date().toISOString(),
  updated_at: new Date().toISOString(),
};

const STUB_ITEM = {
  id: 1,
  auction_id: 1,
  art_lot_id: 1,
  lot_number: 1,
  bid_increment_cents: 1000,
  status: 'open',
  art_lots: { starting_bid_cents: 10000, currency: 'EUR', artworks: { title: 'Test Art' } },
};

const STUB_BID = {
  id: 1,
  amount_cents: 50000,
  status: 'accepted',
  bid_type: 'live',
  created_at: new Date('2026-03-10').toISOString(),
};

const mockAuctionService = {
  listAuctions: async () => ({ data: [STUB_AUCTION], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } }),
  getAuction: async (id: string) => {
    if (id === 'not-found') throw new NotFoundException('Auction "not-found" not found');
    return STUB_AUCTION;
  },
  getAuctionItem: async (_auction: string, _item: number) => STUB_ITEM,
  listBidsForItem: async (_auction: string, _item: number) => [STUB_BID],
  health: async () => ({ status: 'ok', db: 'connected', timestamp: new Date().toISOString() }),
};

// ── suite ─────────────────────────────────────────────────────────────────────

describe('Phase 0.3C — Auction controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: JWT_SECRET })],
      controllers: [AuctionController, HealthController],
      providers: [{ provide: AuctionService, useValue: mockAuctionService }, TestJwtStrategy],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => {
    await app.close();
  });

  // ── AC1-AC2: list auctions ────────────────────────────────────────────────

  it('AC1: GET /auctions returns 200 with data + meta', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/auctions' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
    expect(Array.isArray(body.data)).toBe(true);
  });

  it('AC2: GET /auctions?status=live forwards query param', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/auctions?status=live' });
    expect(res.statusCode).toBe(200);
  });

  // ── AC3-AC5: show auction ─────────────────────────────────────────────────

  it('AC3: GET /auctions/1 returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/auctions/1' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toMatchObject({ slug: 'spring-sale', status: 'live' });
  });

  it('AC4: GET /auctions/:slug returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/auctions/spring-sale' });
    expect(res.statusCode).toBe(200);
  });

  it('AC5: GET /auctions/not-found returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/auctions/not-found' });
    expect(res.statusCode).toBe(404);
  });

  // ── AC6: show auction item ────────────────────────────────────────────────

  it('AC6: GET /auctions/:a/items/:i returns 200 with nested lot', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/auctions/1/items/1' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('status', 'open');
    expect(body).toHaveProperty('art_lots');
  });

  // ── AC8: bid history ──────────────────────────────────────────────────────

  it('AC8: GET /auctions/:a/items/:i/bids returns accepted bid list', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/auctions/1/items/1/bids' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body)).toBe(true);
    expect(body[0]).toMatchObject({ status: 'accepted', bid_type: 'live' });
  });

  // ── AC7: health check ─────────────────────────────────────────────────────

  it('AC7: GET /health returns 200 with status ok', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/health' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toMatchObject({ status: 'ok', db: 'connected' });
    expect(body).toHaveProperty('timestamp');
  });

  // ── AC9: max-bid (inline stub) ────────────────────────────────────────────

  it('AC9: POST /auctions/:a/items/:i/max-bid returns 200 (inline stub)', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/auctions/1/items/1/max-bid',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ max_amount_cents: 200000 }),
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.status).toBe('max_bid_placed');
    expect(body.proxy_active).toBe(true);
  });
});
