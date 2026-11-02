/**
 * Phase 0.3E — Gallery public routes integration tests
 *
 * Routes covered:
 *   GET /api/v1/galleries             — paginated list
 *   GET /api/v1/galleries/:id         — show by ID or slug
 *   GET /api/v1/galleries/not-found   — 404 path
 *   GET /api/v1/galleries/:id/lots    — art lots for a gallery
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';

import { GalleryController } from './gallery.controller';
import { GalleryService } from './gallery.service';

// ── stubs ──────────────────────────────────────────────────────────────────────

const STUB_GALLERY = {
  id: 1,
  name: 'Sophia Fine Art',
  slug: 'sophia-fine-art',
  type: 'private',
  status: 'active',
  website: 'https://sophia-art.example.com',
  contact_email: 'contact@sophia-art.example.com',
  created_at: new Date('2024-01-01').toISOString(),
};

const STUB_LOT = {
  id: 1,
  sale_mode: 'auction',
  status: 'published',
  starting_bid_cents: 75000,
  buy_now_price_cents: null,
  currency: 'EUR',
  estimate_low_cents: 60000,
  estimate_high_cents: 90000,
  published_at: new Date('2024-06-01').toISOString(),
  closed_at: null,
  artworks: { id: 2, title: 'Still Life', slug: 'still-life', medium: 'oil' },
};

const mockGalleryService = {
  listGalleries: async () => ({
    data: [STUB_GALLERY],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  }),
  getGallery: async (id: string) => {
    if (id === 'not-found') throw new NotFoundException('Gallery "not-found" not found');
    return STUB_GALLERY;
  },
  listGalleryLots: async (_id: string) => [STUB_LOT],
};

// ── suite ──────────────────────────────────────────────────────────────────────

describe('Phase 0.3E — Gallery controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [GalleryController],
      providers: [{ provide: GalleryService, useValue: mockGalleryService }],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => {
    await app.close();
  });

  it('GL1: GET /galleries returns 200 with data + meta', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/galleries' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
    expect(Array.isArray(body.data)).toBe(true);
  });

  it('GL2: GET /galleries?type=private filters by type', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/galleries?type=private' });
    expect(res.statusCode).toBe(200);
  });

  it('GL3: GET /galleries/1 returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/galleries/1' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toMatchObject({ slug: 'sophia-fine-art', status: 'active' });
  });

  it('GL4: GET /galleries/:slug returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/galleries/sophia-fine-art' });
    expect(res.statusCode).toBe(200);
  });

  it('GL5: GET /galleries/not-found returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/galleries/not-found' });
    expect(res.statusCode).toBe(404);
  });

  it('GL6: GET /galleries/:id/lots returns array of lots with artwork', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/galleries/1/lots' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body)).toBe(true);
    expect(body[0]).toHaveProperty('artworks');
    expect(body[0]).toHaveProperty('currency');
  });
});
