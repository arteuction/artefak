/**
 * Phase 0.3F — Profile controller integration tests
 *
 * Routes covered:
 *   GET /api/v1/me        — authenticated user profile (existing guard test covers 401/403)
 *   GET /api/v1/me/bids   — authenticated user bid history
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { PassportStrategy } from '@nestjs/passport';
import { Injectable } from '@nestjs/common';

import { ProfileController } from './profile.controller';
import { ProfileService } from './profile.service';
import { GovernanceService } from '../governance/governance.service';
import type { AuthenticatedUser, JwtPayload } from '../auth/auth.types';

const JWT_SECRET = 'test-profile-secret';

@Injectable()
class TestJwtStrategy extends PassportStrategy(Strategy, 'jwt') {
  constructor() {
    super({
      jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(),
      ignoreExpiration: false,
      secretOrKey: JWT_SECRET,
    });
  }
  async validate(payload: JwtPayload): Promise<AuthenticatedUser> {
    return {
      id: payload.sub,
      email: payload.email,
      role: payload.role as AuthenticatedUser['role'],
      emailVerifiedAt: null,
    };
  }
}

// ── stubs ──────────────────────────────────────────────────────────────────────

const STUB_BIDS_RESPONSE = {
  data: [
    {
      id: 1,
      amount_cents: 75000,
      status: 'accepted',
      bid_type: 'live',
      created_at: new Date('2026-04-01').toISOString(),
      auction_item: {
        id: 1,
        lot_number: 1,
        auction: { id: 1, title: 'Spring Sale', slug: 'spring-sale' },
      },
    },
  ],
  meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
};

const STUB_WATCHLIST_ENTRY = {
  id: 1,
  subject_type: 'artworks',
  subject_id: 42,
  created_at: new Date('2026-05-01').toISOString(),
};

const STUB_COLLECTIONS_RESPONSE = {
  data: [{ id: 1, title: 'My Public Collection', slug: 'my-public', visibility: 'public', created_at: new Date('2026-01-01').toISOString() }],
  meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
};

const STUB_CONSIGNMENTS = {
  data: [{
    id: 1, commission_bps: 1500, status: 'active',
    starts_at: new Date('2026-01-01').toISOString(), ends_at: null,
    created_at: new Date('2026-01-01').toISOString(),
    artworks: { id: 3, title: 'Blue Horizon', slug: 'blue-horizon', medium: 'painting' },
    galleries: { id: 2, name: 'Sophia Fine Art', slug: 'sophia-fine-art' },
  }],
  meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
};

const mockProfileService = {
  getUserBids: async () => STUB_BIDS_RESPONSE,
  getWatchlist: async () => [STUB_WATCHLIST_ENTRY],
  addToWatchlist: async () => STUB_WATCHLIST_ENTRY,
  removeFromWatchlist: async () => ({ deleted: true }),
  getUserCollections: async () => STUB_COLLECTIONS_RESPONSE,
  getUserConsignments: async () => STUB_CONSIGNMENTS,
};

// ── helpers ───────────────────────────────────────────────────────────────────

import jwt from 'jsonwebtoken';
const { sign } = jwt;

function makeToken(role: string = 'user') {
  return sign({ sub: 99, email: 'me@example.com', role }, JWT_SECRET, { expiresIn: '1h' });
}

// ── suite ──────────────────────────────────────────────────────────────────────

describe('Phase 0.3F — Profile controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [
        PassportModule,
        JwtModule.register({ secret: JWT_SECRET }),
      ],
      controllers: [ProfileController],
      providers: [
        { provide: ProfileService, useValue: mockProfileService },
        { provide: GovernanceService, useValue: { myConsents: async () => [], requestErasure: async () => ({ status: 'scheduled' }), exportData: async () => ({ status: 'queued' }) } },
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

  it('PF1: GET /me returns 200 with user profile', async () => {
    const token = makeToken('user');
    const res = await app.inject({
      method: 'GET',
      url: '/api/v1/me',
      headers: { authorization: `Bearer ${token}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toMatchObject({ id: 99, email: 'me@example.com', role: 'user' });
  });

  it('PF2: GET /me without token returns 401', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/me' });
    expect(res.statusCode).toBe(401);
  });

  it('PF3: GET /me/bids returns 200 with paginated bid list', async () => {
    const token = makeToken('user');
    const res = await app.inject({
      method: 'GET',
      url: '/api/v1/me/bids',
      headers: { authorization: `Bearer ${token}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
    expect(Array.isArray(body.data)).toBe(true);
    expect(body.data[0]).toHaveProperty('auction_item');
  });

  it('PF4: GET /me/bids without token returns 401', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/me/bids' });
    expect(res.statusCode).toBe(401);
  });

  it('PF5: GET /me/watchlist returns 200 with array', async () => {
    const token = makeToken('user');
    const res = await app.inject({
      method: 'GET', url: '/api/v1/me/watchlist',
      headers: { authorization: `Bearer ${token}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body)).toBe(true);
    expect(body[0]).toHaveProperty('subject_type');
  });

  it('PF6: POST /me/watchlist returns 201 with new entry', async () => {
    const token = makeToken('user');
    const res = await app.inject({
      method: 'POST', url: '/api/v1/me/watchlist',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ subject_type: 'artworks', subject_id: 42 }),
    });
    expect(res.statusCode).toBe(201);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('subject_type', 'artworks');
  });

  it('PF7: DELETE /me/watchlist/:id returns 200 with deleted flag', async () => {
    const token = makeToken('user');
    const res = await app.inject({
      method: 'DELETE', url: '/api/v1/me/watchlist/1',
      headers: { authorization: `Bearer ${token}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toMatchObject({ deleted: true });
  });

  it('PF8: GET /me/collections returns 200 with paginated list', async () => {
    const token = makeToken('user');
    const res = await app.inject({
      method: 'GET', url: '/api/v1/me/collections',
      headers: { authorization: `Bearer ${token}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
    expect(body.data[0]).toHaveProperty('visibility', 'public');
  });

  it('PF9: GET /me/consignments returns 200 with paginated consignments', async () => {
    const token = makeToken('user');
    const res = await app.inject({
      method: 'GET', url: '/api/v1/me/consignments',
      headers: { authorization: `Bearer ${token}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
    expect(body.data[0]).toHaveProperty('artworks');
    expect(body.data[0]).toHaveProperty('galleries');
  });
});
