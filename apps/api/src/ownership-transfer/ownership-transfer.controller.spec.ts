/**
 * Phase 0.3P — OwnershipTransfer route tests
 *
 * OT1  GET /ownership-transfers     — list (200, authenticated)
 * OT2  GET /ownership-transfers     — 401 without token
 * OT3  GET /ownership-transfers/:id — get one (200)
 * OT4  GET /ownership-transfers/999 — 404
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { Injectable } from '@nestjs/common';
import { OwnershipTransferController } from './ownership-transfer.controller';
import { OwnershipTransferService } from './ownership-transfer.service';
import type { AuthenticatedUser, JwtPayload } from '../auth/auth.types';

const JWT_SECRET = 'test-ot-secret';

@Injectable()
class TestJwtStrategy extends PassportStrategy(Strategy, 'jwt') {
  constructor() {
    super({ jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(), ignoreExpiration: false, secretOrKey: JWT_SECRET });
  }
  async validate(payload: JwtPayload): Promise<AuthenticatedUser> {
    return { id: payload.sub, email: payload.email, role: payload.role as AuthenticatedUser['role'], emailVerifiedAt: null };
  }
}

const STUB_TRANSFER = {
  id: 1, transfer_price_cents: 150000, currency: 'BGN',
  channel: 'auction', transferred_at: new Date('2026-04-01').toISOString(),
  created_at: new Date('2026-04-01').toISOString(),
  art_lot_id: 5, auction_item_id: 3, sell_now_offer_id: null,
};

const mockService = {
  listTransfers: async () => ({
    data: [STUB_TRANSFER],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  }),
  getTransfer: async (id: number) => {
    if (id === 999) throw new NotFoundException(`OwnershipTransfer ${id} not found`);
    return STUB_TRANSFER;
  },
};

import jwt from 'jsonwebtoken';
const { sign } = jwt;
function makeToken(role = 'user') {
  return sign({ sub: 1, email: 'user@example.com', role }, JWT_SECRET, { expiresIn: '1h' });
}

describe('Phase 0.3P — OwnershipTransfer controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: JWT_SECRET })],
      controllers: [OwnershipTransferController],
      providers: [{ provide: OwnershipTransferService, useValue: mockService }, TestJwtStrategy],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('OT1: GET /ownership-transfers returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/ownership-transfers', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.data[0]).toHaveProperty('channel');
  });

  it('OT2: GET /ownership-transfers without token returns 401', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/ownership-transfers' });
    expect(res.statusCode).toBe(401);
  });

  it('OT3: GET /ownership-transfers/:id returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/ownership-transfers/1', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(200);
  });

  it('OT4: GET /ownership-transfers/999 returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/ownership-transfers/999', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(404);
  });
});
