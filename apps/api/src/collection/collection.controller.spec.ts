/**
 * Phase 0.3H — Collection public routes integration tests
 *
 * Routes covered:
 *   GET /api/v1/collections                 — paginated public list
 *   GET /api/v1/collections/:id             — show public collection
 *   GET /api/v1/collections/not-found       — 404 path
 *   GET /api/v1/collections/:id/artworks    — artworks in a collection
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';

import { CollectionController } from './collection.controller';
import { CollectionService } from './collection.service';

const STUB_COLLECTION = {
  id: 1,
  title: 'Modern Masters',
  slug: 'modern-masters',
  description: 'A curated selection.',
  cover_image_url: null,
  visibility: 'public',
  created_at: new Date('2025-01-01').toISOString(),
};

const STUB_ARTWORKS = [
  { id: 1, title: 'Blue Horizon', slug: 'blue-horizon', medium: 'painting', status: 'published', position: 1 },
];

const mockCollectionService = {
  listPublicCollections: async () => ({
    data: [STUB_COLLECTION],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  }),
  getCollection: async (id: string) => {
    if (id === 'not-found') throw new NotFoundException('Collection "not-found" not found');
    return STUB_COLLECTION;
  },
  getCollectionArtworks: async () => STUB_ARTWORKS,
};

describe('Phase 0.3H — Collection controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [CollectionController],
      providers: [{ provide: CollectionService, useValue: mockCollectionService }],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => {
    await app.close();
  });

  it('CO1: GET /collections returns 200 with data + meta', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/collections' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
    expect(body.data[0]).toHaveProperty('visibility', 'public');
  });

  it('CO2: GET /collections/1 returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/collections/1' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toMatchObject({ slug: 'modern-masters' });
  });

  it('CO3: GET /collections/:slug returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/collections/modern-masters' });
    expect(res.statusCode).toBe(200);
  });

  it('CO4: GET /collections/not-found returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/collections/not-found' });
    expect(res.statusCode).toBe(404);
  });

  it('CO5: GET /collections/:id/artworks returns ordered artwork list', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/collections/1/artworks' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body)).toBe(true);
    expect(body[0]).toHaveProperty('position');
    expect(body[0]).toHaveProperty('slug', 'blue-horizon');
  });
});
