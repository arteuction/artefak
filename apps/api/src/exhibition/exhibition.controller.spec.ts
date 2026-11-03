/**
 * Phase 0.3L — Exhibition route tests
 *
 * EX1  GET /exhibitions             — list with pagination
 * EX2  GET /exhibitions/:exhibition — get by ID/slug
 * EX3  GET /exhibitions/999         — 404 for missing exhibition
 * EX4  POST /exhibitions            — 201 creates exhibition (auth required)
 * EX5  PATCH /exhibitions/:id       — 200 updates exhibition (auth required)
 * EX6  PUT /exhibitions/:id/sdg-tags — 200 sets SDG tags (auth required)
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { Injectable } from '@nestjs/common';
import { ExhibitionController } from './exhibition.controller';
import { ExhibitionService } from './exhibition.service';
import type { AuthenticatedUser, JwtPayload } from '../auth/auth.types';
import jwt from 'jsonwebtoken';

const JWT_SECRET = 'test-exhibition-secret';

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
function makeToken(role = 'admin') {
  return sign({ sub: 1, email: 'admin@example.com', role }, JWT_SECRET, { expiresIn: '1h' });
}

const STUB_EXHIBITION = {
  id: 1, title: 'Spring Salon 2026', slug: 'spring-salon-2026',
  description: 'Annual salon exhibition.',
  starts_at: new Date('2026-03-01').toISOString(),
  ends_at: new Date('2026-04-30').toISOString(),
  is_active: true, created_at: new Date('2026-01-15').toISOString(),
};

const CREATED_EXHIBITION = { id: 2, title: 'Autumn Show', slug: 'autumn-show', starts_at: '2026-09-01T00:00:00.000Z', ends_at: '2026-10-31T00:00:00.000Z', is_active: false, created_at: new Date().toISOString() };

const mockExhibitionService = {
  listExhibitions: async () => ({
    data: [STUB_EXHIBITION],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  }),
  getExhibition: async (id: string) => {
    if (id === '999') throw new NotFoundException('Exhibition "999" not found');
    return STUB_EXHIBITION;
  },
  createExhibition: async (_dto: Record<string, unknown>) => CREATED_EXHIBITION,
  updateExhibition: async (_id: string, dto: Record<string, unknown>) => ({ ...STUB_EXHIBITION, ...dto }),
  tagSdgs: async (_id: string, sdgNumbers: number[]) => ({ sdg_numbers: sdgNumbers }),
};

describe('Phase 0.3L — Exhibition controller (public routes)', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [ExhibitionController],
      providers: [{ provide: ExhibitionService, useValue: mockExhibitionService }],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('EX1: GET /exhibitions returns 200 with pagination', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/exhibitions' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
    expect(body.data[0]).toHaveProperty('title');
  });

  it('EX2: GET /exhibitions/:exhibition returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/exhibitions/spring-salon-2026' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('slug', 'spring-salon-2026');
  });

  it('EX3: GET /exhibitions/999 returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/exhibitions/999' });
    expect(res.statusCode).toBe(404);
  });
});

describe('Phase 0.3L — Exhibition controller (auth routes)', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: JWT_SECRET })],
      controllers: [ExhibitionController],
      providers: [{ provide: ExhibitionService, useValue: mockExhibitionService }, TestJwtStrategy],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('EX4: POST /exhibitions creates exhibition and returns 201', async () => {
    const dto = { title: 'Autumn Show', slug: 'autumn-show', venue_id: 1, starts_at: '2026-09-01T00:00:00Z', ends_at: '2026-10-31T00:00:00Z' };
    const res = await app.inject({
      method: 'POST', url: '/api/v1/exhibitions',
      payload: dto,
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
    });
    expect(res.statusCode).toBe(201);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('id', 2);
    expect(body).toHaveProperty('slug', 'autumn-show');
  });

  it('EX5: PATCH /exhibitions/:id updates exhibition and returns 200', async () => {
    const res = await app.inject({
      method: 'PATCH', url: '/api/v1/exhibitions/1',
      payload: { title: 'Spring Salon 2026 Extended', is_active: false },
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('title', 'Spring Salon 2026 Extended');
  });

  it('EX6: PUT /exhibitions/:id/sdg-tags sets SDG tags and returns 200', async () => {
    const res = await app.inject({
      method: 'PUT', url: '/api/v1/exhibitions/1/sdg-tags',
      payload: { sdg_numbers: [4, 10, 13] },
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('sdg_numbers');
    expect(body.sdg_numbers).toContain(4);
  });
});
