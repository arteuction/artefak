/**
 * Phase 0.3L — Exhibition public route tests
 *
 * EX1  GET /exhibitions           — list with pagination
 * EX2  GET /exhibitions/:exhibition — get by ID/slug
 * EX3  GET /exhibitions/999       — 404 for missing exhibition
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';
import { ExhibitionController } from './exhibition.controller';
import { ExhibitionService } from './exhibition.service';

const STUB_EXHIBITION = {
  id: 1, title: 'Spring Salon 2026', slug: 'spring-salon-2026',
  description: 'Annual salon exhibition.',
  starts_at: new Date('2026-03-01').toISOString(),
  ends_at: new Date('2026-04-30').toISOString(),
  is_active: true, created_at: new Date('2026-01-15').toISOString(),
};

const mockExhibitionService = {
  listExhibitions: async () => ({
    data: [STUB_EXHIBITION],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  }),
  getExhibition: async (id: string) => {
    if (id === '999') throw new NotFoundException('Exhibition "999" not found');
    return STUB_EXHIBITION;
  },
};

describe('Phase 0.3L — Exhibition controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [ExhibitionController],
      providers: [{ provide: ExhibitionService, useValue: mockExhibitionService }],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('EX1: GET /exhibitions returns 200 with pagination', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/exhibitions' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
    expect(body.data[0]).toHaveProperty('title');
  });

  it('EX2: GET /exhibitions/:exhibition returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/exhibitions/spring-salon-2026' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('slug', 'spring-salon-2026');
  });

  it('EX3: GET /exhibitions/999 returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/exhibitions/999' });
    expect(res.statusCode).toBe(404);
  });
});
