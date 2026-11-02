/**
 * Phase 0.3N — Consignment CRUD route tests
 *
 * CS1  GET /consignments         — list (200, authenticated)
 * CS2  GET /consignments         — 401 without token
 * CS3  GET /consignments/:id     — get one (200)
 * CS4  POST /consignments        — create (201)
 * CS5  GET /consignments/999     — 404 for missing
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { Injectable } from '@nestjs/common';
import { ConsignmentController } from './consignment.controller';
import { ConsignmentService } from './consignment.service';
import type { AuthenticatedUser, JwtPayload } from '../auth/auth.types';

const JWT_SECRET = 'test-consignment-secret';

@Injectable()
class TestJwtStrategy extends PassportStrategy(Strategy, 'jwt') {
  constructor() {
    super({ jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(), ignoreExpiration: false, secretOrKey: JWT_SECRET });
  }
  async validate(payload: JwtPayload): Promise<AuthenticatedUser> {
    return { id: payload.sub, email: payload.email, role: payload.role as AuthenticatedUser['role'], emailVerifiedAt: null };
  }
}

const STUB_CONSIGNMENT = {
  id: 1, commission_bps: 1500, status: 'active',
  starts_at: new Date('2026-01-01').toISOString(), ends_at: null,
  created_at: new Date('2026-01-01').toISOString(),
  artworks: { id: 3, title: 'Blue Horizon', slug: 'blue-horizon', medium: 'painting' },
  galleries: { id: 2, name: 'Sophia Fine Art', slug: 'sophia-fine-art' },
};

const mockConsignmentService = {
  listConsignments: async () => ({
    data: [STUB_CONSIGNMENT],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  }),
  getConsignment: async (id: number) => {
    if (id === 999) throw new NotFoundException(`Consignment ${id} not found`);
    return STUB_CONSIGNMENT;
  },
  createConsignment: async () => ({ id: 2, status: 'draft', commission_bps: 0 }),
};

import jwt from 'jsonwebtoken';
const { sign } = jwt;

function makeToken(role = 'user') {
  return sign({ sub: 1, email: 'user@example.com', role }, JWT_SECRET, { expiresIn: '1h' });
}

describe('Phase 0.3N — Consignment controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: JWT_SECRET })],
      controllers: [ConsignmentController],
      providers: [
        { provide: ConsignmentService, useValue: mockConsignmentService },
        TestJwtStrategy,
      ],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('CS1: GET /consignments returns 200 when authenticated', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/consignments', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body.data[0]).toHaveProperty('artworks');
  });

  it('CS2: GET /consignments without token returns 401', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/consignments' });
    expect(res.statusCode).toBe(401);
  });

  it('CS3: GET /consignments/:id returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/consignments/1', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('status', 'active');
  });

  it('CS4: POST /consignments returns 201', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/consignments',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({ artwork_id: 3 }),
    });
    expect(res.statusCode).toBe(201);
  });

  it('CS5: GET /consignments/999 returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/consignments/999', headers: { authorization: `Bearer ${makeToken()}` } });
    expect(res.statusCode).toBe(404);
  });
});
