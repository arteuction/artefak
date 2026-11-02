/**
 * Phase 0.3M — Dispute route tests
 *
 * DS1  GET /disputes         — list (200, authenticated)
 * DS2  GET /disputes         — 401 without token
 * DS3  GET /disputes/:id     — get one (200)
 * DS4  POST /disputes        — create (201)
 * DS5  GET /disputes/:id     — 404 for missing
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { Injectable } from '@nestjs/common';
import { DisputeController } from './dispute.controller';
import { DisputeService } from './dispute.service';
import type { AuthenticatedUser, JwtPayload } from '../auth/auth.types';

const JWT_SECRET = 'test-dispute-secret';

@Injectable()
class TestJwtStrategy extends PassportStrategy(Strategy, 'jwt') {
  constructor() {
    super({ jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(), ignoreExpiration: false, secretOrKey: JWT_SECRET });
  }
  async validate(payload: JwtPayload): Promise<AuthenticatedUser> {
    return { id: payload.sub, email: payload.email, role: payload.role as AuthenticatedUser['role'], emailVerifiedAt: null };
  }
}

const STUB_DISPUTE = {
  id: 1, type: 'non_delivery', status: 'open',
  description: 'Item not delivered', art_lot_id: 5,
  auction_item_id: null, sell_now_offer_id: null,
  assigned_to: null, resolved_at: null,
  created_at: new Date('2026-05-01').toISOString(),
};

const mockDisputeService = {
  listDisputes: async () => ({
    data: [STUB_DISPUTE],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  }),
  getDispute: async (id: number) => {
    if (id === 999) throw new NotFoundException(`Dispute ${id} not found`);
    return STUB_DISPUTE;
  },
  createDispute: async () => STUB_DISPUTE,
};

import jwt from 'jsonwebtoken';
const { sign } = jwt;

function makeToken(role = 'user') {
  return sign({ sub: 1, email: 'user@example.com', role }, JWT_SECRET, { expiresIn: '1h' });
}

describe('Phase 0.3M — Dispute controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: JWT_SECRET })],
      controllers: [DisputeController],
      providers: [
        { provide: DisputeService, useValue: mockDisputeService },
        TestJwtStrategy,
      ],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('DS1: GET /disputes returns 200 when authenticated', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/disputes', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body.data[0]).toHaveProperty('type');
  });

  it('DS2: GET /disputes without token returns 401', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/disputes' });
    expect(res.statusCode).toBe(401);
  });

  it('DS3: GET /disputes/:id returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/disputes/1', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('status', 'open');
  });

  it('DS4: POST /disputes returns 201', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/disputes',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ type: 'non_delivery', description: 'Item not received' }),
    });
    expect(res.statusCode).toBe(201);
  });

  it('DS5: GET /disputes/999 returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/disputes/999', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(404);
  });
});
