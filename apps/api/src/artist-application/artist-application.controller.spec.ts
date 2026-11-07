/**
 * Phase 0.3R — ArtistApplication route tests
 *
 * AA1  GET /artist-applications — 200 (own list)
 * AA2  GET /artist-applications/:id — 200
 * AA3  GET /artist-applications/:id — 404
 * AA4  POST /artist-applications — 201 create
 * AA5  POST /artist-applications/:id/approve — 200 (admin)
 * AA6  POST /artist-applications/:id/reject — 200 (admin)
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { Injectable } from '@nestjs/common';
import { ArtistApplicationController } from './artist-application.controller';
import { ArtistApplicationService } from './artist-application.service';
import type { AuthenticatedUser, JwtPayload } from '../auth/auth.types';

const JWT_SECRET = 'test-artist-app-secret';

@Injectable()
class TestJwtStrategy extends PassportStrategy(Strategy, 'jwt') {
  constructor() {
    super({ jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(), ignoreExpiration: false, secretOrKey: JWT_SECRET });
  }
  async validate(payload: JwtPayload): Promise<AuthenticatedUser> {
    return { id: payload.sub, email: payload.email, role: payload.role as AuthenticatedUser['role'], emailVerifiedAt: null };
  }
}

const STUB = { id: 1, status: 'draft', portfolio_url: null, motivation: 'I love art', version: 1, created_at: new Date('2026-01-01').toISOString(), artist_profile_id: 10 };

const mockService = {
  listApplications: async () => ({ data: [STUB], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } }),
  getApplication: async (id: number) => {
    if (id === 999) throw new NotFoundException(`Application 999 not found`);
    return STUB;
  },
  createApplication: async () => ({ ...STUB, id: 2 }),
  reviewApplication: async (_id: number, _reviewerId: number, decision: string) => ({ id: 1, status: decision, review_note: null, reviewed_at: new Date().toISOString() }),
};

import jwt from 'jsonwebtoken';
const { sign } = jwt;
function makeToken(role = 'user') {
  return sign({ sub: 1, email: 'user@example.com', role }, JWT_SECRET, { expiresIn: '1h' });
}

describe('Phase 0.3R — ArtistApplication controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: JWT_SECRET })],
      controllers: [ArtistApplicationController],
      providers: [{ provide: ArtistApplicationService, useValue: mockService }, TestJwtStrategy],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('AA1: GET /artist-applications returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artist-applications', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body.data)).toBe(true);
  });

  it('AA2: GET /artist-applications/:id returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artist-applications/1', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(200);
  });

  it('AA3: GET /artist-applications/999 returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artist-applications/999', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(404);
  });

  it('AA4: POST /artist-applications creates (201)', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/artist-applications',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ motivation: 'I love art' }),
    });
    expect(res.statusCode).toBe(201);
  });

  it('AA5: POST /artist-applications/:id/approve approves (200)', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/artist-applications/1/approve',
      headers: { authorization: `Bearer ${makeToken('admin')}`, 'content-type': 'application/json' },
      payload: JSON.stringify({}),
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.status).toBe('approved');
  });

  it('AA6: POST /artist-applications/:id/reject rejects (200)', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/artist-applications/1/reject',
      headers: { authorization: `Bearer ${makeToken('admin')}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ review_note: 'Portfolio too thin' }),
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.status).toBe('rejected');
  });
});
