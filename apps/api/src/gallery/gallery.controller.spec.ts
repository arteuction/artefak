/**
 * Phase 0.3E — Gallery route tests
 *
 * GL1  GET  /galleries                    — list
 * GL2  GET  /galleries?type=private       — filter
 * GL3  GET  /galleries/:id                — show
 * GL4  GET  /galleries/:slug              — show by slug
 * GL5  GET  /galleries/not-found          — 404
 * GL6  GET  /galleries/:id/lots           — lots
 * GL7  GET  /galleries/:id/profile        — gallery profile with staff
 * GL8  GET  /galleries/:id/staff          — list staff
 * GL9  POST /galleries                    — 201 create (auth)
 * GL10 PATCH /galleries/:id               — 200 update (auth)
 * GL11 POST /galleries/:id/staff          — 201 add staff (auth)
 * GL12 PATCH /galleries/:id/staff/:sid    — 200 update staff (auth)
 * GL13 DELETE /galleries/:id/staff/:uid   — 200 remove staff (auth)
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { Injectable } from '@nestjs/common';

import { GalleryController } from './gallery.controller';
import { GalleryService } from './gallery.service';
import type { AuthenticatedUser, JwtPayload } from '../auth/auth.types';

const JWT_SECRET = 'test-gallery-secret';

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

// ── stubs ──────────────────────────────────────────────────────────────────────

const STUB_GALLERY = {
  id: 1,
  name: 'Sophia Fine Art',
  slug: 'sophia-fine-art',
  type: 'private',
  status: 'active',
  website: 'https://sophia-art.example.com',
  contact_email: 'contact@sophia-art.example.com',
  created_at: new Date('2024-01-01').toISOString(),
};

const STUB_LOT = {
  id: 1,
  sale_mode: 'auction',
  status: 'published',
  starting_bid_cents: 75000,
  buy_now_price_cents: null,
  currency: 'EUR',
  estimate_low_cents: 60000,
  estimate_high_cents: 90000,
  published_at: new Date('2024-06-01').toISOString(),
  closed_at: null,
  artworks: { id: 2, title: 'Still Life', slug: 'still-life', medium: 'oil' },
};

const STUB_STAFF = { id: 1, user_id: 5, gallery_id: 1, role: 'staff', status: 'active', created_at: new Date('2026-01-01').toISOString() };

const mockGalleryService = {
  listGalleries: async () => ({
    data: [STUB_GALLERY],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  }),
  getGallery: async (id: string) => {
    if (id === 'not-found') throw new NotFoundException('Gallery "not-found" not found');
    return STUB_GALLERY;
  },
  listGalleryLots: async (_id: string) => [STUB_LOT],
  getGalleryProfile: async (_id: string) => ({ ...STUB_GALLERY, staff: [STUB_STAFF] }),
  listStaff: async (_id: string) => [STUB_STAFF],
  createGallery: async (dto: object) => ({ ...STUB_GALLERY, id: 2, ...dto }),
  updateGallery: async (_id: string, _userId: number, _role: string, dto: object) => ({ ...STUB_GALLERY, ...dto }),
  addStaff: async (_id: string, _userId: number, _role: string, dto: object) => ({ ...STUB_STAFF, ...dto }),
  updateStaff: async (_id: string, staffId: number, _userId: number, _role: string, dto: object) => ({ ...STUB_STAFF, id: staffId, ...dto }),
  removeStaff: async () => ({ deleted: true }),
};

// ── suite ──────────────────────────────────────────────────────────────────────

describe('Phase 0.3E — Gallery controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: JWT_SECRET })],
      controllers: [GalleryController],
      providers: [{ provide: GalleryService, useValue: mockGalleryService }, TestJwtStrategy],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => {
    await app.close();
  });

  it('GL1: GET /galleries returns 200 with data + meta', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/galleries' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
    expect(Array.isArray(body.data)).toBe(true);
  });

  it('GL2: GET /galleries?type=private filters by type', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/galleries?type=private' });
    expect(res.statusCode).toBe(200);
  });

  it('GL3: GET /galleries/1 returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/galleries/1' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toMatchObject({ slug: 'sophia-fine-art', status: 'active' });
  });

  it('GL4: GET /galleries/:slug returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/galleries/sophia-fine-art' });
    expect(res.statusCode).toBe(200);
  });

  it('GL5: GET /galleries/not-found returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/galleries/not-found' });
    expect(res.statusCode).toBe(404);
  });

  it('GL6: GET /galleries/:id/lots returns array of lots with artwork', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/galleries/1/lots' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body)).toBe(true);
    expect(body[0]).toHaveProperty('artworks');
    expect(body[0]).toHaveProperty('currency');
  });

  it('GL7: GET /galleries/:id/profile returns gallery with staff', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/galleries/1/profile' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('staff');
    expect(Array.isArray(body.staff)).toBe(true);
  });

  it('GL8: GET /galleries/:id/staff returns staff array', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/galleries/1/staff' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body)).toBe(true);
    expect(body[0]).toHaveProperty('role');
  });

  it('GL9: POST /galleries creates gallery (201)', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/galleries',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ name: 'New Gallery', slug: 'new-gallery', type: 'private' }),
    });
    expect(res.statusCode).toBe(201);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('id', 2);
  });

  it('GL10: PATCH /galleries/:id updates gallery (200)', async () => {
    const res = await app.inject({
      method: 'PATCH', url: '/api/v1/galleries/1',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ website: 'https://new-site.example.com' }),
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('website', 'https://new-site.example.com');
  });

  it('GL11: POST /galleries/:id/staff adds staff (201)', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/galleries/1/staff',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ user_id: 5, role: 'staff' }),
    });
    expect(res.statusCode).toBe(201);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('role', 'staff');
  });

  it('GL12: PATCH /galleries/:id/staff/:sid updates staff (200)', async () => {
    const res = await app.inject({
      method: 'PATCH', url: '/api/v1/galleries/1/staff/1',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ role: 'manager' }),
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('role', 'manager');
  });

  it('GL13: DELETE /galleries/:id/staff/:uid removes staff (200)', async () => {
    const res = await app.inject({
      method: 'DELETE', url: '/api/v1/galleries/1/staff/5',
      headers: { authorization: `Bearer ${makeToken()}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('deleted', true);
  });
});
