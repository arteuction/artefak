/**
 * Phase 0.3L — Venue public route tests
 *
 * VN1  GET /venues        — list with pagination
 * VN2  GET /venues/map    — map pins
 * VN3  GET /venues/:venue — get by ID/slug
 * VN4  GET /venues/999    — 404 for missing venue
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';
import { VenueController } from './venue.controller';
import { VenueService } from './venue.service';

const STUB_VENUE = {
  id: 1, name: 'Sofia Art Center', slug: 'sofia-art-center',
  address: 'ul. Rakovski 125', city: 'Sofia', country: 'BG',
  lat: 42.6977, lng: 23.3219, created_at: new Date('2026-01-01').toISOString(),
};

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
};

describe('Phase 0.3L — Venue controller', () => {
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
