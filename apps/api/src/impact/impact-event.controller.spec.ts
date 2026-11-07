/**
 * Phase 0.3N — ImpactEvent route tests
 *
 * IE1  GET /impact-events              — list (200)
 * IE2  GET /impact-events/:id          — get one (200)
 * IE3  GET /impact-events/999          — 404
 * IE4  GET /impact-events/:id/evidence — list evidence (200)
 * IE5  POST /impact-events/:id/evidence — create evidence (201, auth required)
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { Injectable } from '@nestjs/common';
import { ImpactEventController } from './impact-event.controller';
import { ImpactEventService } from './impact-event.service';
import type { AuthenticatedUser, JwtPayload } from '../auth/auth.types';
import jwt from 'jsonwebtoken';

const JWT_SECRET = 'test-impact-secret';

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
function makeToken(role = 'artist') {
  return sign({ sub: 1, email: 'artist@example.com', role }, JWT_SECRET, { expiresIn: '1h' });
}

const STUB_EVENT = {
  id: 1, sdg_number: 4, metric: 'funds_raised', magnitude: 5000,
  currency: 'BGN', type: 'event', created_at: new Date('2026-02-01').toISOString(),
};

const STUB_EVIDENCE = { id: 1, impact_event_id: 1, type: 'photo', url: 'https://cdn.example.com/photo.jpg', description: 'Proof of event', created_by: 1, created_at: new Date().toISOString() };

const mockImpactEventService = {
  listEvents: async () => ({
    data: [STUB_EVENT],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  }),
  getEvent: async (id: number) => {
    if (id === 999) throw new NotFoundException(`ImpactEvent ${id} not found`);
    return STUB_EVENT;
  },
  getEventEvidence: async (_id: number) => [STUB_EVIDENCE],
  createEventEvidence: async (_id: number, _userId: number, _body: Record<string, unknown>) => ({ ...STUB_EVIDENCE, id: 2 }),
};

describe('Phase 0.3N — ImpactEvent controller (public routes)', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [ImpactEventController],
      providers: [{ provide: ImpactEventService, useValue: mockImpactEventService }],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('IE1: GET /impact-events returns 200 with pagination', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/impact-events' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body.data[0]).toHaveProperty('sdg_number');
  });

  it('IE2: GET /impact-events/:id returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/impact-events/1' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('metric', 'funds_raised');
  });

  it('IE3: GET /impact-events/999 returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/impact-events/999' });
    expect(res.statusCode).toBe(404);
  });

  it('IE4: GET /impact-events/:id/evidence returns 200 with array', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/impact-events/1/evidence' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body)).toBe(true);
    expect(body[0]).toHaveProperty('type', 'photo');
  });
});

describe('Phase 0.3N — ImpactEvent controller (auth routes)', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: JWT_SECRET })],
      controllers: [ImpactEventController],
      providers: [{ provide: ImpactEventService, useValue: mockImpactEventService }, TestJwtStrategy],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('IE5: POST /impact-events/:id/evidence creates evidence and returns 201', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/impact-events/1/evidence',
      payload: { type: 'photo', url: 'https://cdn.example.com/photo2.jpg', description: 'Additional evidence' },
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
    });
    expect(res.statusCode).toBe(201);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('id', 2);
    expect(body).toHaveProperty('type', 'photo');
  });
});
