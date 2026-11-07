/**
 * Phase 0.3U — MyController tests (aliases for /my/* routes)
 *
 * MY1  GET  /my/artworks           — 200 + pagination
 * MY2  GET  /my/bids               — 200 + pagination
 * MY3  GET  /my/won-items          — 200 + pagination
 * MY4  GET  /my/sell-now-offers    — 200
 * MY5  GET  /my/gallery-offers     — 200
 * MY6  GET  /my/export             — 200
 * MY7  GET  /my/consents           — 200
 * MY8  POST /my/erasure-request    — 200
 * MY9  GET  /artist-profile        — 200
 * MY10 POST /artist-profile        — 201
 * MY11 PATCH /artist-profile       — 200
 * MY12 GET /my/artworks without token — 401
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { INestApplication } from '@nestjs/common';
import { PassportModule } from '@nestjs/passport';
import { JwtModule } from '@nestjs/jwt';
import { Strategy as JwtStrategy, ExtractJwt } from 'passport-jwt';
import { PassportStrategy } from '@nestjs/passport';
import { Injectable } from '@nestjs/common';
import { MyController } from './my.controller';
import { ProfileService } from './profile.service';
import { GovernanceService } from '../governance/governance.service';
import jwt from 'jsonwebtoken';

const { sign } = jwt;

const TEST_SECRET = 'my-controller-test-secret';

const STUB_ARTIST = {
  id: 1, display_name: 'Test Artist', slug: 'test-artist',
  bio: null, website: null, instagram_handle: null, status: 'active', terms_accepted_at: null, created_at: new Date(),
};

@Injectable()
class TestJwtStrategy extends PassportStrategy(JwtStrategy) {
  constructor() {
    super({ jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(), secretOrKey: TEST_SECRET });
  }
  validate(payload: { sub: number; role: string }) {
    return { id: payload.sub, role: payload.role };
  }
}

const paged = (page = 1, perPage = 20) => ({ data: [], meta: { current_page: page, last_page: 1, per_page: perPage, total: 0 } });

const mockProfileService = {
  getMyArtworks: async (_u: number, page = 1, perPage = 20) => paged(page, perPage),
  getUserBids: async (_u: number, page = 1, perPage = 20) => paged(page, perPage),
  getWonItems: async (_u: number, page = 1, perPage = 20) => paged(page, perPage),
  getGalleryOffers: async (_u: number, page = 1, perPage = 20) => paged(page, perPage),
  getArtistProfile: async (_u: number) => STUB_ARTIST,
  createArtistProfile: async (_u: number, _dto: object) => STUB_ARTIST,
  updateArtistProfile: async (_u: number, _dto: object) => STUB_ARTIST,
};

const mockGovernanceService = {
  exportData: async (_u: number) => ({ status: 'queued' }),
  myConsents: async (_u: number) => [],
  requestErasure: async (_u: number, _reason?: string) => ({ status: 'scheduled' }),
};

describe('Phase 0.3U — MyController', () => {
  let app: INestApplication;
  let userToken: string;

  beforeAll(async () => {
    userToken = sign({ sub: 1, role: 'artist' }, TEST_SECRET, { expiresIn: '1h' });

    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: TEST_SECRET })],
      controllers: [MyController],
      providers: [
        TestJwtStrategy,
        { provide: ProfileService, useValue: mockProfileService },
        { provide: GovernanceService, useValue: mockGovernanceService },
      ],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await (app as NestFastifyApplication).getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  const auth = () => ({ authorization: `Bearer ${userToken}` });

  it('MY1: GET /my/artworks returns 200 with pagination', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/my/artworks', headers: auth() });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
  });

  it('MY2: GET /my/bids returns 200 with pagination', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/my/bids', headers: auth() });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
  });

  it('MY3: GET /my/won-items returns 200 with pagination', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/my/won-items', headers: auth() });
    expect(res.statusCode).toBe(200);
  });

  it('MY4: GET /my/sell-now-offers returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/my/sell-now-offers', headers: auth() });
    expect(res.statusCode).toBe(200);
  });

  it('MY5: GET /my/gallery-offers returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/my/gallery-offers', headers: auth() });
    expect(res.statusCode).toBe(200);
  });

  it('MY6: GET /my/export returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/my/export', headers: auth() });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('status');
  });

  it('MY7: GET /my/consents returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/my/consents', headers: auth() });
    expect(res.statusCode).toBe(200);
  });

  it('MY8: POST /my/erasure-request returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'POST', url: '/api/v1/my/erasure-request', headers: auth(), payload: { reason: 'GDPR request' } });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('status');
  });

  it('MY9: GET /artist-profile returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/artist-profile', headers: auth() });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('display_name');
  });

  it('MY10: POST /artist-profile returns 201', async () => {
    const res = await (app as NestFastifyApplication).inject({
      method: 'POST',
      url: '/api/v1/artist-profile',
      headers: auth(),
      payload: { display_name: 'My Artist', slug: 'my-artist' },
    });
    expect(res.statusCode).toBe(201);
  });

  it('MY11: PATCH /artist-profile returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({
      method: 'PATCH',
      url: '/api/v1/artist-profile',
      headers: auth(),
      payload: { bio: 'Updated bio' },
    });
    expect(res.statusCode).toBe(200);
  });

  it('MY12: GET /my/artworks without token returns 401', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/my/artworks' });
    expect(res.statusCode).toBe(401);
  });
});
