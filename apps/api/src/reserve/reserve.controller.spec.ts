/**
 * Phase 0.3P — Reserve route tests
 *
 * RV1  GET /reserves/:id — get one (200, authenticated)
 * RV2  GET /reserves/:id — 401 without token
 * RV3  GET /reserves/999 — 404
 * RV4  POST /reserves/:id/waive — returns { status: 'waived' }
 * RV5  POST /reserves/:id/counter-offer — returns { status: 'counter_offered' }
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { Injectable } from '@nestjs/common';
import { ReserveController } from './reserve.controller';
import { ReserveService } from './reserve.service';
import type { AuthenticatedUser, JwtPayload } from '../auth/auth.types';

const JWT_SECRET = 'test-reserve-secret';

@Injectable()
class TestJwtStrategy extends PassportStrategy(Strategy, 'jwt') {
  constructor() {
    super({ jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(), ignoreExpiration: false, secretOrKey: JWT_SECRET });
  }
  async validate(payload: JwtPayload): Promise<AuthenticatedUser> {
    return { id: payload.sub, email: payload.email, role: payload.role as AuthenticatedUser['role'], emailVerifiedAt: null };
  }
}

const STUB_RESERVE = {
  id: 1, auction_item_id: 3, reserve_price_cents: 100000,
  highest_bid_cents: 80000, status: 'not_reached',
  decided_by: null, decided_at: null, counter_offer_cents: null,
  created_at: new Date('2026-04-01').toISOString(),
};

const mockService = {
  getReserve: async (id: number) => {
    if (id === 999) throw new NotFoundException(`Reserve ${id} not found`);
    return STUB_RESERVE;
  },
};

import jwt from 'jsonwebtoken';
const { sign } = jwt;
function makeToken(role = 'user') {
  return sign({ sub: 1, email: 'user@example.com', role }, JWT_SECRET, { expiresIn: '1h' });
}

describe('Phase 0.3P — Reserve controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: JWT_SECRET })],
      controllers: [ReserveController],
      providers: [{ provide: ReserveService, useValue: mockService }, TestJwtStrategy],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('RV1: GET /reserves/:id returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/reserves/1', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('status', 'not_reached');
  });

  it('RV2: GET /reserves/:id without token returns 401', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/reserves/1' });
    expect(res.statusCode).toBe(401);
  });

  it('RV3: GET /reserves/999 returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/reserves/999', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(404);
  });

  it('RV4: POST /reserves/:id/waive returns 200 with status waived', async () => {
    const res = await app.inject({ method: 'POST', url: '/api/v1/reserves/1/waive', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.status).toBe('waived');
    expect(body).toHaveProperty('waived_at');
  });

  it('RV5: POST /reserves/:id/counter-offer returns 200 with status counter_offered', async () => {
    const res = await app.inject({ method: 'POST', url: '/api/v1/reserves/1/counter-offer', payload: { counter_price_cents: 90000 }, headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' } });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.status).toBe('counter_offered');
  });
});
