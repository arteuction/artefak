/**
 * Phase 0.3M — Donation route tests
 *
 * DN1  GET /donations       — list (200, authenticated)
 * DN2  GET /donations       — 401 without token
 * DN3  GET /donations/:id   — get one (200)
 * DN4  GET /donations/999   — 404 for missing
 * DN5  GET /donations/tax-receipt/2026 — 200 (route before :donation)
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { Injectable } from '@nestjs/common';
import { DonationController } from './donation.controller';
import { DonationService } from './donation.service';
import type { AuthenticatedUser, JwtPayload } from '../auth/auth.types';

const JWT_SECRET = 'test-donation-secret';

@Injectable()
class TestJwtStrategy extends PassportStrategy(Strategy, 'jwt') {
  constructor() {
    super({ jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(), ignoreExpiration: false, secretOrKey: JWT_SECRET });
  }
  async validate(payload: JwtPayload): Promise<AuthenticatedUser> {
    return { id: payload.sub, email: payload.email, role: payload.role as AuthenticatedUser['role'], emailVerifiedAt: null };
  }
}

const STUB_DONATION = {
  id: 1, donated_cents: 5000, currency: 'BGN',
  type: 'donation', status: 'confirmed', eligibility_basis: 'public_benefit',
  deduction_bps: 1000, created_at: new Date('2026-03-01').toISOString(),
};

const mockDonationService = {
  listDonations: async () => ({
    data: [STUB_DONATION],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  }),
  getDonation: async (id: number) => {
    if (id === 999) throw new NotFoundException(`Donation ${id} not found`);
    return STUB_DONATION;
  },
  taxReceipt: async (_userId: number, year: number) => ({
    year, currency: 'EUR', total_donated_cents: 5000, total_deductible_cents: 5000, rows: [],
  }),
};

import jwt from 'jsonwebtoken';
const { sign } = jwt;

function makeToken(role = 'user') {
  return sign({ sub: 1, email: 'user@example.com', role }, JWT_SECRET, { expiresIn: '1h' });
}

describe('Phase 0.3M — Donation controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: JWT_SECRET })],
      controllers: [DonationController],
      providers: [
        { provide: DonationService, useValue: mockDonationService },
        TestJwtStrategy,
      ],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('DN1: GET /donations returns 200 when authenticated', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/donations', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body.data[0]).toHaveProperty('donated_cents');
  });

  it('DN2: GET /donations without token returns 401', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/donations' });
    expect(res.statusCode).toBe(401);
  });

  it('DN3: GET /donations/:id returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/donations/1', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('status', 'confirmed');
  });

  it('DN4: GET /donations/999 returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/donations/999', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(404);
  });

  it('DN5: GET /donations/tax-receipt/2026 returns 200 (route ordering fix)', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/donations/tax-receipt/2026', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.year).toBe(2026);
    expect(body).toHaveProperty('total_donated_cents');
  });
});
