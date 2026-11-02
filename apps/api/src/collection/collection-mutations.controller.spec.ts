/**
 * Phase 0.3S — Collection mutation route tests
 *
 * CM1  POST /collections — 201 create
 * CM2  PATCH /collections/:id — 200 update
 * CM3  DELETE /collections/:id — 200 delete
 * CM4  POST /collections/:id/artworks — 201 add artwork
 * CM5  DELETE /collections/:id/artworks/:artwork — 200 remove artwork
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { Injectable } from '@nestjs/common';
import { CollectionController } from './collection.controller';
import { CollectionService } from './collection.service';
import type { AuthenticatedUser, JwtPayload } from '../auth/auth.types';

const JWT_SECRET = 'test-collection-mutations-secret';

@Injectable()
class TestJwtStrategy extends PassportStrategy(Strategy, 'jwt') {
  constructor() {
    super({ jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(), ignoreExpiration: false, secretOrKey: JWT_SECRET });
  }
  async validate(payload: JwtPayload): Promise<AuthenticatedUser> {
    return { id: payload.sub, email: payload.email, role: payload.role as AuthenticatedUser['role'], emailVerifiedAt: null };
  }
}

const STUB = { id: 1, title: 'My Collection', slug: 'my-collection', visibility: 'public', created_at: new Date('2026-01-01').toISOString() };

const mockService = {
  listPublicCollections: async () => ({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } }),
  getCollection: async () => STUB,
  getCollectionArtworks: async () => [],
  createCollection: async (_uid: number, dto: { title: string }) => ({ ...STUB, title: dto.title }),
  updateCollection: async (_id: string, _uid: number, _role: string, dto: object) => ({ ...STUB, ...dto }),
  deleteCollection: async () => ({ deleted: true }),
  addArtwork: async () => ({ id: 1, collection_id: 1, artwork_id: 5, position: 1 }),
  removeArtwork: async () => ({ deleted: true }),
};

import jwt from 'jsonwebtoken';
const { sign } = jwt;
function makeToken(role = 'user') {
  return sign({ sub: 1, email: 'user@example.com', role }, JWT_SECRET, { expiresIn: '1h' });
}

describe('Phase 0.3S — Collection mutations controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: JWT_SECRET })],
      controllers: [CollectionController],
      providers: [{ provide: CollectionService, useValue: mockService }, TestJwtStrategy],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('CM1: POST /collections creates (201)', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/collections',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ title: 'My Collection', slug: 'my-collection' }),
    });
    expect(res.statusCode).toBe(201);
  });

  it('CM2: PATCH /collections/:id updates (200)', async () => {
    const res = await app.inject({
      method: 'PATCH', url: '/api/v1/collections/1',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ title: 'Updated Collection' }),
    });
    expect(res.statusCode).toBe(200);
  });

  it('CM3: DELETE /collections/:id deletes (200)', async () => {
    const res = await app.inject({
      method: 'DELETE', url: '/api/v1/collections/1',
      headers: { authorization: `Bearer ${makeToken()}` },
    });
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('deleted', true);
  });

  it('CM4: POST /collections/:id/artworks adds artwork (201)', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/collections/1/artworks',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ artwork_id: 5 }),
    });
    expect(res.statusCode).toBe(201);
  });

  it('CM5: DELETE /collections/:id/artworks/:artwork removes (200)', async () => {
    const res = await app.inject({
      method: 'DELETE', url: '/api/v1/collections/1/artworks/5',
      headers: { authorization: `Bearer ${makeToken()}` },
    });
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('deleted', true);
  });
});
