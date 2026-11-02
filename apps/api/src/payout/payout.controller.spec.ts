/**
 * Phase 0.3U — PayoutController tests
 *
 * PY1  GET /payouts/artist          — 200 + pagination (authenticated user)
 * PY2  GET /payouts/gallery/5       — 200 + pagination
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { INestApplication } from '@nestjs/common';
import { PassportModule } from '@nestjs/passport';
import { JwtModule } from '@nestjs/jwt';
import { Strategy as JwtStrategy, ExtractJwt } from 'passport-jwt';
import { PassportStrategy } from '@nestjs/passport';
import { Injectable } from '@nestjs/common';
import { PayoutController } from './payout.controller';
import { PayoutService } from './payout.service';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import jwt from 'jsonwebtoken';

const { sign } = jwt;

const TEST_SECRET = 'payout-test-secret';

@Injectable()
class TestJwtStrategy extends PassportStrategy(JwtStrategy) {
  constructor() {
    super({ jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(), secretOrKey: TEST_SECRET });
  }
  validate(payload: { sub: number; role: string }) {
    return { id: payload.sub, role: payload.role };
  }
}

const mockPayoutService = {
  artistPayouts: async (_userId: number, page = 1, perPage = 20) => ({
    data: [],
    meta: { current_page: page, last_page: 1, per_page: perPage, total: 0 },
  }),
  galleryPayouts: async (_galleryId: number, page = 1, perPage = 20) => ({
    data: [],
    meta: { current_page: page, last_page: 1, per_page: perPage, total: 0 },
  }),
};

describe('Phase 0.3U — PayoutController', () => {
  let app: INestApplication;
  let artistToken: string;

  beforeAll(async () => {
    artistToken = sign({ sub: 1, role: 'artist' }, TEST_SECRET, { expiresIn: '1h' });

    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: TEST_SECRET })],
      controllers: [PayoutController],
      providers: [
        TestJwtStrategy,
        { provide: PayoutService, useValue: mockPayoutService },
      ],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await (app as NestFastifyApplication).getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('PY1: GET /payouts/artist returns 200 with pagination', async () => {
    const res = await (app as NestFastifyApplication).inject({
      method: 'GET',
      url: '/api/v1/payouts/artist',
      headers: { authorization: `Bearer ${artistToken}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
  });

  it('PY2: GET /payouts/gallery/5 returns 200 with pagination', async () => {
    const res = await (app as NestFastifyApplication).inject({
      method: 'GET',
      url: '/api/v1/payouts/gallery/5',
      headers: { authorization: `Bearer ${artistToken}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
  });

  it('PY3: GET /payouts/artist without token returns 401', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/payouts/artist' });
    expect(res.statusCode).toBe(401);
  });
});
