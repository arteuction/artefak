/**
 * Phase 0.3R — Institution route tests
 *
 * IN1  GET /institutions — 200 paginated list
 * IN2  GET /institutions/:id — 200 one institution
 * IN3  GET /institutions/:id — 404 not found
 * IN4  POST /institutions — 201 create (authenticated)
 * IN5  PATCH /institutions/:id — 200 update (authenticated)
 * IN6  POST /institutions/:id/artworks — 201 attach artwork (authenticated)
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { Injectable } from '@nestjs/common';
import { InstitutionController } from './institution.controller';
import { InstitutionService } from './institution.service';
import type { AuthenticatedUser, JwtPayload } from '../auth/auth.types';

const JWT_SECRET = 'test-institution-secret';

@Injectable()
class TestJwtStrategy extends PassportStrategy(Strategy, 'jwt') {
  constructor() {
    super({ jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(), ignoreExpiration: false, secretOrKey: JWT_SECRET });
  }
  async validate(payload: JwtPayload): Promise<AuthenticatedUser> {
    return { id: payload.sub, email: payload.email, role: payload.role as AuthenticatedUser['role'], emailVerifiedAt: null };
  }
}

const STUB = { id: 1, name: 'Louvre', slug: 'louvre', type: 'museum', country_code: 'FR', website_url: null, is_active: true, created_at: new Date('2026-01-01').toISOString() };

const mockService = {
  listInstitutions: async () => ({ data: [STUB], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } }),
  getInstitution: async (id: string) => {
    if (id === '999') throw new NotFoundException(`Institution 999 not found`);
    return STUB;
  },
  createInstitution: async (dto: { name: string; slug: string }) => ({ ...STUB, name: dto.name, slug: dto.slug }),
  updateInstitution: async (_id: string, dto: { name?: string }) => ({ ...STUB, ...dto }),
  attachArtwork: async () => ({ id: 1, institution_id: 1, artwork_id: 5 }),
};

import jwt from 'jsonwebtoken';
const { sign } = jwt;
function makeToken(role = 'admin') {
  return sign({ sub: 1, email: 'admin@example.com', role }, JWT_SECRET, { expiresIn: '1h' });
}

describe('Phase 0.3R — Institution controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: JWT_SECRET })],
      controllers: [InstitutionController],
      providers: [{ provide: InstitutionService, useValue: mockService }, TestJwtStrategy],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('IN1: GET /institutions returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/institutions' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body.data)).toBe(true);
  });

  it('IN2: GET /institutions/:id returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/institutions/1' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('name', 'Louvre');
  });

  it('IN3: GET /institutions/999 returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/institutions/999' });
    expect(res.statusCode).toBe(404);
  });

  it('IN4: POST /institutions creates (201)', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/institutions',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ name: 'Tate', slug: 'tate' }),
    });
    expect(res.statusCode).toBe(201);
  });

  it('IN5: PATCH /institutions/:id updates (200)', async () => {
    const res = await app.inject({
      method: 'PATCH', url: '/api/v1/institutions/1',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ name: 'Louvre Museum' }),
    });
    expect(res.statusCode).toBe(200);
  });

  it('IN6: POST /institutions/:id/artworks attaches (201)', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/institutions/1/artworks',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ artwork_id: 5 }),
    });
    expect(res.statusCode).toBe(201);
  });
});
