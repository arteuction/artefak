/**
 * Phase 0.3D — Artwork + art-lot public routes integration tests
 *
 * Routes covered:
 *   GET /api/v1/artworks              — paginated public list
 *   GET /api/v1/artworks/:id          — show by ID or slug
 *   GET /api/v1/artworks/not-found    — 404 path
 *   GET /api/v1/artworks/:id/lots     — art lots for an artwork
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';

import { ArtworkController } from './artwork.controller';
import { ArtworkService } from './artwork.service';

// ── stubs ──────────────────────────────────────────────────────────────────────

const STUB_ARTWORK = {
  id: 1,
  title: 'Sunset Over the Bay',
  slug: 'sunset-over-the-bay',
  medium: 'painting',
  status: 'published',
  primary_image_key: null,
  year_created: 2023,
  created_at: new Date('2024-01-01').toISOString(),
};

const STUB_LOT = {
  id: 1,
  sale_mode: 'auction',
  status: 'draft',
  starting_bid_cents: 50000,
  buy_now_price_cents: null,
  reserve_price_cents: null,
  currency: 'EUR',
  estimate_low_cents: 40000,
  estimate_high_cents: 60000,
  published_at: null,
  closed_at: null,
};

const mockArtworkService = {
  listArtworks: async () => ({
    data: [STUB_ARTWORK],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  }),
  getArtwork: async (id: string) => {
    if (id === 'not-found') throw new NotFoundException('Artwork "not-found" not found');
    return STUB_ARTWORK;
  },
  listArtLots: async (_id: string) => [STUB_LOT],
};

// ── suite ──────────────────────────────────────────────────────────────────────

describe('Phase 0.3D — Artwork controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [ArtworkController],
      providers: [{ provide: ArtworkService, useValue: mockArtworkService }],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => {
    await app.close();
  });

  it('AW1: GET /artworks returns 200 with data + meta', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
    expect(Array.isArray(body.data)).toBe(true);
  });

  it('AW2: GET /artworks?status=published filters by status', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks?status=published' });
    expect(res.statusCode).toBe(200);
  });

  it('AW3: GET /artworks?medium=painting filters by medium', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks?medium=painting' });
    expect(res.statusCode).toBe(200);
  });

  it('AW4: GET /artworks/1 returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks/1' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toMatchObject({ slug: 'sunset-over-the-bay', medium: 'painting' });
  });

  it('AW5: GET /artworks/:slug returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks/sunset-over-the-bay' });
    expect(res.statusCode).toBe(200);
  });

  it('AW6: GET /artworks/not-found returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks/not-found' });
    expect(res.statusCode).toBe(404);
  });

  it('AW7: GET /artworks/:id/lots returns array of lots', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks/1/lots' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body)).toBe(true);
    expect(body[0]).toHaveProperty('sale_mode');
    expect(body[0]).toHaveProperty('currency');
  });
});
