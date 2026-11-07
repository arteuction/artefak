/**
 * Phase 0.3S — Artwork mutation route tests
 *
 * AM1  POST /artworks — 201 create
 * AM2  PATCH /artworks/:id — 200 update
 * AM3  POST /artworks/:id/submit — 200 submit for review
 * AM4  GET /artworks/:id/evidence — 200 list
 * AM5  POST /artworks/:id/evidence — 201 add evidence
 * AM6  GET /artworks/:id/sdg-claims — 200 list
 * AM7  POST /artworks/:id/sdg-claims — 201 create claim
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { Injectable } from '@nestjs/common';
import { ArtworkController } from './artwork.controller';
import { ArtworkService } from './artwork.service';
import type { AuthenticatedUser, JwtPayload } from '../auth/auth.types';

const JWT_SECRET = 'test-artwork-mutations-secret';

@Injectable()
class TestJwtStrategy extends PassportStrategy(Strategy, 'jwt') {
  constructor() {
    super({ jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(), ignoreExpiration: false, secretOrKey: JWT_SECRET });
  }
  async validate(payload: JwtPayload): Promise<AuthenticatedUser> {
    return { id: payload.sub, email: payload.email, role: payload.role as AuthenticatedUser['role'], emailVerifiedAt: null };
  }
}

const STUB_AW = { id: 1, title: 'Blue Sky', slug: 'blue-sky', medium: 'oil', status: 'draft', created_at: new Date('2026-01-01').toISOString() };

const mockService = {
  listArtworks: async () => ({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } }),
  getArtwork: async () => STUB_AW,
  listArtLots: async () => [],
  createArtwork: async (_uid: number, dto: { title: string }) => ({ ...STUB_AW, title: dto.title }),
  updateArtwork: async (_id: string, _uid: number, _role: string, dto: object) => ({ ...STUB_AW, ...dto }),
  submitArtwork: async () => ({ id: 1, status: 'review', updated_at: new Date().toISOString() }),
  listEvidence: async () => [],
  createEvidence: async () => ({ id: 1, type: 'provenance', issuer: null, verification_status: 'pending', created_at: new Date().toISOString() }),
  listSdgClaims: async () => [],
  createSdgClaim: async () => ({ id: 1, sdg_number: 4, status: 'pending', created_at: new Date().toISOString() }),
};

import jwt from 'jsonwebtoken';
const { sign } = jwt;
function makeToken(role = 'user') {
  return sign({ sub: 1, email: 'user@example.com', role }, JWT_SECRET, { expiresIn: '1h' });
}

describe('Phase 0.3S — Artwork mutations controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: JWT_SECRET })],
      controllers: [ArtworkController],
      providers: [{ provide: ArtworkService, useValue: mockService }, TestJwtStrategy],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('AM1: POST /artworks creates artwork (201)', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/artworks',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ title: 'Blue Sky', slug: 'blue-sky' }),
    });
    expect(res.statusCode).toBe(201);
  });

  it('AM2: PATCH /artworks/:id updates artwork (200)', async () => {
    const res = await app.inject({
      method: 'PATCH', url: '/api/v1/artworks/1',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ title: 'Blue Sky Updated' }),
    });
    expect(res.statusCode).toBe(200);
  });

  it('AM3: POST /artworks/:id/submit submits for review (200)', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/artworks/1/submit',
      headers: { authorization: `Bearer ${makeToken()}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.status).toBe('review');
  });

  it('AM4: GET /artworks/:id/evidence returns list (200)', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks/1/evidence' });
    expect(res.statusCode).toBe(200);
    expect(Array.isArray(JSON.parse(res.payload))).toBe(true);
  });

  it('AM5: POST /artworks/:id/evidence creates evidence (201)', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/artworks/1/evidence',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ type: 'provenance' }),
    });
    expect(res.statusCode).toBe(201);
  });

  it('AM6: GET /artworks/:id/sdg-claims returns list (200)', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks/1/sdg-claims' });
    expect(res.statusCode).toBe(200);
    expect(Array.isArray(JSON.parse(res.payload))).toBe(true);
  });

  it('AM7: POST /artworks/:id/sdg-claims creates claim (201)', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/artworks/1/sdg-claims',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ sdg_number: 4, rationale: 'Supports education' }),
    });
    expect(res.statusCode).toBe(201);
    const body = JSON.parse(res.payload);
    expect(body.sdg_number).toBe(4);
  });
});
