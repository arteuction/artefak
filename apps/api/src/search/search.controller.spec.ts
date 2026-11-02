/**
 * Phase 0.3Q — Search route tests
 *
 * SR1  GET /search/artworks — returns paginated results (200)
 * SR2  GET /search/artworks?q=oil — filters by query (200)
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { SearchController } from './search.controller';
import { SearchService } from './search.service';

const STUB_ARTWORKS = [
  { id: 1, title: 'Oil Painting', slug: 'oil-painting', medium: 'oil', year_created: 2020, status: 'active', created_at: new Date('2026-01-01').toISOString() },
];

const mockService = {
  searchArtworks: async (query: string) => ({
    data: query ? STUB_ARTWORKS.filter(a => a.title.toLowerCase().includes(query.toLowerCase())) : STUB_ARTWORKS,
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1, query },
  }),
};

describe('Phase 0.3Q — Search controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [SearchController],
      providers: [{ provide: SearchService, useValue: mockService }],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('SR1: GET /search/artworks returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/search/artworks' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(Array.isArray(body.data)).toBe(true);
  });

  it('SR2: GET /search/artworks?q=oil returns filtered results', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/search/artworks?q=oil' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.meta.query).toBe('oil');
    expect(body.data.length).toBeGreaterThan(0);
  });
});
