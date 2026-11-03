/**
 * Phase 0.3L — Venue public route tests
 *
 * VN1  GET /venues        — list with pagination
 * VN2  GET /venues/map    — map pins
 * VN3  GET /venues/:venue — get by ID/slug
 * VN4  GET /venues/999    — 404 for missing venue
 * VN5  POST /venues       — 201 creates venue (auth required)
 * VN6  PATCH /venues/:id  — 200 updates venue (auth required)
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { Injectable } from '@nestjs/common';
import { VenueController } from './venue.controller';
import { VenueService } from './venue.service';
import type { AuthenticatedUser, JwtPayload } from '../auth/auth.types';

const JWT_SECRET = 'test-venue-secret';

@Injectable()
class TestJwtStrategy extends PassportStrategy(Strategy, 'jwt') {
  constructor() {
    super({ jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(), ignoreExpiration: false, secretOrKey: JWT_SECRET });
  }
  async validate(payload: JwtPayload): Promise<AuthenticatedUser> {
    return { id: payload.sub, email: payload.email, role: payload.role as AuthenticatedUser['role'], emailVerifiedAt: null };
  }
}

import jwt from 'jsonwebtoken';
const { sign } = jwt;
function makeToken(role = 'admin') {
  return sign({ sub: 1, email: 'admin@example.com', role }, JWT_SECRET, { expiresIn: '1h' });
}

const STUB_VENUE = {
  id: 1, name: 'Sofia Art Center', slug: 'sofia-art-center',
  address: 'ul. Rakovski 125', city: 'Sofia', country: 'BG',
  lat: 42.6977, lng: 23.3219, created_at: new Date('2026-01-01').toISOString(),
};

const CREATED_VENUE = { id: 2, name: 'Plovdiv Gallery', slug: 'plovdiv-gallery', city: 'Plovdiv', country: 'BG', lat: 42.1354, lng: 24.7453, created_at: new Date('2026-02-01').toISOString() };

const mockVenueService = {
  listVenues: async () => ({
    data: [STUB_VENUE],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  }),
  getVenueMap: async () => [{ id: 1, name: 'Sofia Art Center', lat: 42.6977, lng: 23.3219, slug: 'sofia-art-center' }],
  getVenue: async (id: string) => {
    if (id === '999') throw new NotFoundException('Venue "999" not found');
    return STUB_VENUE;
  },
  createVenue: async (_dto: Record<string, unknown>) => CREATED_VENUE,
  updateVenue: async (_id: string, dto: Record<string, unknown>) => ({ ...STUB_VENUE, ...dto }),
};

describe('Phase 0.3L — Venue controller (public routes)', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [VenueController],
      providers: [{ provide: VenueService, useValue: mockVenueService }],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('VN1: GET /venues returns 200 with pagination', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/venues' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
    expect(body.data[0]).toHaveProperty('city');
  });

  it('VN2: GET /venues/map returns 200 with array of pins', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/venues/map' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body)).toBe(true);
    expect(body[0]).toHaveProperty('lat');
    expect(body[0]).toHaveProperty('lng');
  });

  it('VN3: GET /venues/:venue returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/venues/sofia-art-center' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('name', 'Sofia Art Center');
  });

  it('VN4: GET /venues/999 returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/venues/999' });
    expect(res.statusCode).toBe(404);
  });
});

describe('Phase 0.3L — Venue controller (auth routes)', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: JWT_SECRET })],
      controllers: [VenueController],
      providers: [{ provide: VenueService, useValue: mockVenueService }, TestJwtStrategy],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('VN5: POST /venues creates venue and returns 201', async () => {
    const dto = { name: 'Plovdiv Gallery', slug: 'plovdiv-gallery', city: 'Plovdiv', country: 'BG', lat: 42.1354, lng: 24.7453 };
    const res = await app.inject({
      method: 'POST', url: '/api/v1/venues',
      payload: dto,
      headers: { authorization: `Bearer ${makeToken('admin')}`, 'content-type': 'application/json' },
    });
    expect(res.statusCode).toBe(201);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('id', 2);
    expect(body).toHaveProperty('name', 'Plovdiv Gallery');
  });

  it('VN6: PATCH /venues/:id updates venue and returns 200', async () => {
    const res = await app.inject({
      method: 'PATCH', url: '/api/v1/venues/1',
      payload: { name: 'Sofia Art Center Updated' },
      headers: { authorization: `Bearer ${makeToken('admin')}`, 'content-type': 'application/json' },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('name', 'Sofia Art Center Updated');
  });
});
