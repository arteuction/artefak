/**
 * Phase 0.3H — Collection routes integration tests
 *
 * CO1  GET    /collections                     — public list
 * CO2  GET    /collections/1                   — show
 * CO3  GET    /collections/:slug               — show by slug
 * CO4  GET    /collections/not-found           — 404
 * CO5  GET    /collections/:id/artworks        — artwork list
 * CO6  POST   /collections (auth)              — create 201
 * CO7  PATCH  /collections/:id (auth)          — update 200
 * CO8  DELETE /collections/:id (auth)          — delete 200
 * CO9  POST   /collections/:id/artworks (auth) — add artwork 201
 * CO10 DELETE /collections/:id/artworks/:aw    — remove artwork 200
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException, Injectable } from '@nestjs/common';
import { PassportModule } from '@nestjs/passport';
import { JwtModule } from '@nestjs/jwt';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import jwt from 'jsonwebtoken';

import { CollectionController } from './collection.controller';
import { CollectionService } from './collection.service';
import type { AuthenticatedUser, JwtPayload } from '../auth/auth.types';

const JWT_SECRET = 'test-collection-secret';

@Injectable()
class TestJwtStrategy extends PassportStrategy(Strategy, 'jwt') {
  constructor() {
    super({ jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(), ignoreExpiration: false, secretOrKey: JWT_SECRET });
  }
  async validate(payload: JwtPayload): Promise<AuthenticatedUser> {
    return { id: payload.sub, email: payload.email, role: payload.role as AuthenticatedUser['role'], emailVerifiedAt: null };
  }
}

const { sign } = jwt;
function makeToken() { return sign({ sub: 1, email: 'user@example.com', role: 'user' }, JWT_SECRET, { expiresIn: '1h' }); }

const STUB_COLLECTION = {
  id: 1,
  title: 'Modern Masters',
  slug: 'modern-masters',
  description: 'A curated selection.',
  cover_image_url: null,
  visibility: 'public',
  created_at: new Date('2025-01-01').toISOString(),
};

const STUB_ARTWORKS = [
  { id: 1, title: 'Blue Horizon', slug: 'blue-horizon', medium: 'painting', status: 'published', position: 1 },
];

const mockCollectionService = {
  listPublicCollections: async () => ({ data: [STUB_COLLECTION], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } }),
  getCollection: async (id: string) => {
    if (id === 'not-found') throw new NotFoundException('Collection "not-found" not found');
    return STUB_COLLECTION;
  },
  getCollectionArtworks: async () => STUB_ARTWORKS,
  createCollection: async (_uid: number, dto: object) => ({ ...STUB_COLLECTION, ...dto, id: 2 }),
  updateCollection: async (_id: string, _uid: number, _role: string, dto: object) => ({ ...STUB_COLLECTION, ...dto }),
  deleteCollection: async () => ({ deleted: true }),
  addArtwork: async () => ({ collection_id: 1, artwork_id: 5, position: 2 }),
  removeArtwork: async () => ({ deleted: true }),
};

describe('Phase 0.3H — Collection controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: JWT_SECRET })],
      controllers: [CollectionController],
      providers: [{ provide: CollectionService, useValue: mockCollectionService }, TestJwtStrategy],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => {
    await app.close();
  });

  it('CO1: GET /collections returns 200 with data + meta', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/collections' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
    expect(body.data[0]).toHaveProperty('visibility', 'public');
  });

  it('CO2: GET /collections/1 returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/collections/1' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toMatchObject({ slug: 'modern-masters' });
  });

  it('CO3: GET /collections/:slug returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/collections/modern-masters' });
    expect(res.statusCode).toBe(200);
  });

  it('CO4: GET /collections/not-found returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/collections/not-found' });
    expect(res.statusCode).toBe(404);
  });

  it('CO5: GET /collections/:id/artworks returns ordered artwork list', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/collections/1/artworks' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body)).toBe(true);
    expect(body[0]).toHaveProperty('position');
    expect(body[0]).toHaveProperty('slug', 'blue-horizon');
  });

  it('CO6: POST /collections (auth) returns 201', async () => {
    const res = await app.inject({ method: 'POST', url: '/api/v1/collections', headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' }, payload: JSON.stringify({ title: 'My New Collection', slug: 'my-new', visibility: 'public' }) });
    expect(res.statusCode).toBe(201);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('visibility', 'public');
  });

  it('CO7: PATCH /collections/:id (auth) returns 200', async () => {
    const res = await app.inject({ method: 'PATCH', url: '/api/v1/collections/1', headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' }, payload: JSON.stringify({ title: 'Updated Title' }) });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('title', 'Updated Title');
  });

  it('CO8: DELETE /collections/:id (auth) returns 200', async () => {
    const res = await app.inject({ method: 'DELETE', url: '/api/v1/collections/1', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('deleted', true);
  });

  it('CO9: POST /collections/:id/artworks (auth) returns 201', async () => {
    const res = await app.inject({ method: 'POST', url: '/api/v1/collections/1/artworks', headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' }, payload: JSON.stringify({ artwork_id: 5 }) });
    expect(res.statusCode).toBe(201);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('collection_id', 1);
  });

  it('CO10: DELETE /collections/:id/artworks/:aw (auth) returns 200', async () => {
    const res = await app.inject({ method: 'DELETE', url: '/api/v1/collections/1/artworks/5', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('deleted', true);
  });
});
