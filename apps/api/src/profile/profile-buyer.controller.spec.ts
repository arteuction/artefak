/**
 * Phase 0.3Q — Profile buyer & artist-profile route tests
 *
 * WI1  GET /me/won-items — returns paginated won auction items (200)
 * WI2  GET /me/won-items — 401 without token
 * AP1  GET /me/artist-profile — returns profile (200)
 * AP2  GET /me/artist-profile — 404 when no profile
 * AP3  POST /me/artist-profile — creates profile (201)
 * AP4  PATCH /me/artist-profile — updates profile (200)
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { Injectable } from '@nestjs/common';
import { ProfileController } from './profile.controller';
import { ProfileService } from './profile.service';
import { GovernanceService } from '../governance/governance.service';
import type { AuthenticatedUser, JwtPayload } from '../auth/auth.types';

const JWT_SECRET = 'test-profile-buyer-secret';

@Injectable()
class TestJwtStrategy extends PassportStrategy(Strategy, 'jwt') {
  constructor() {
    super({ jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(), ignoreExpiration: false, secretOrKey: JWT_SECRET });
  }
  async validate(payload: JwtPayload): Promise<AuthenticatedUser> {
    return { id: payload.sub, email: payload.email, role: payload.role as AuthenticatedUser['role'], emailVerifiedAt: null };
  }
}

const STUB_WON_ITEM = {
  id: 1, lot_number: 7, status: 'sold', fulfillment_status: 'none',
  payment_deadline: null, created_at: new Date('2026-03-01').toISOString(),
  art_lots: { id: 2, status: 'active', sale_mode: 'auction' },
  auction: { id: 3, title: 'Spring Auction', slug: 'spring-auction' },
};

const STUB_ARTIST_PROFILE = {
  id: 10, display_name: 'Test Artist', slug: 'test-artist',
  bio: 'A test bio', website: null, instagram_handle: null,
  status: 'pending', terms_accepted_at: null, created_at: new Date('2026-01-01').toISOString(),
};

let hasProfile = true;

const mockService = {
  getProfile: async () => ({ id: 1, email: 'user@example.com', role: 'user', email_verified_at: null }),
  getUserBids: async () => ({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } }),
  getWatchlist: async () => [],
  addToWatchlist: async () => ({}),
  removeFromWatchlist: async () => ({ deleted: true }),
  getUserCollections: async () => ({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } }),
  getUserConsignments: async () => ({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } }),
  getWonItems: async () => ({
    data: [STUB_WON_ITEM],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  }),
  getArtistProfile: async () => {
    if (!hasProfile) throw new NotFoundException('Artist profile not found');
    return STUB_ARTIST_PROFILE;
  },
  createArtistProfile: async (_userId: number, dto: { display_name: string; slug: string }) => ({
    ...STUB_ARTIST_PROFILE, display_name: dto.display_name, slug: dto.slug,
  }),
  updateArtistProfile: async (_userId: number, dto: { display_name?: string }) => ({
    ...STUB_ARTIST_PROFILE, ...dto,
  }),
};

import jwt from 'jsonwebtoken';
const { sign } = jwt;
function makeToken(role = 'user') {
  return sign({ sub: 1, email: 'user@example.com', role }, JWT_SECRET, { expiresIn: '1h' });
}

describe('Phase 0.3Q — Profile buyer & artist-profile controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: JWT_SECRET })],
      controllers: [ProfileController],
      providers: [
        { provide: ProfileService, useValue: mockService },
        { provide: GovernanceService, useValue: { myConsents: async () => [], requestErasure: async () => ({ status: 'scheduled' }), exportData: async () => ({ status: 'queued' }) } },
        TestJwtStrategy,
      ],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('WI1: GET /me/won-items returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/me/won-items', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body.data)).toBe(true);
    expect(body.data[0]).toHaveProperty('status', 'sold');
  });

  it('WI2: GET /me/won-items without token returns 401', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/me/won-items' });
    expect(res.statusCode).toBe(401);
  });

  it('AP1: GET /me/artist-profile returns 200 when profile exists', async () => {
    hasProfile = true;
    const res = await app.inject({ method: 'GET', url: '/api/v1/me/artist-profile', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('display_name', 'Test Artist');
  });

  it('AP2: GET /me/artist-profile returns 404 when no profile', async () => {
    hasProfile = false;
    const res = await app.inject({ method: 'GET', url: '/api/v1/me/artist-profile', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(404);
    hasProfile = true;
  });

  it('AP3: POST /me/artist-profile creates profile (201)', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/me/artist-profile',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ display_name: 'New Artist', slug: 'new-artist' }),
    });
    expect(res.statusCode).toBe(201);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('display_name', 'New Artist');
  });

  it('AP4: PATCH /me/artist-profile updates profile (200)', async () => {
    const res = await app.inject({
      method: 'PATCH', url: '/api/v1/me/artist-profile',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ display_name: 'Updated Artist' }),
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('display_name', 'Updated Artist');
  });
});
