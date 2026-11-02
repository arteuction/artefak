/**
 * Phase 0.3N — ImpactEvent public route tests
 *
 * IE1  GET /impact-events       — list (200)
 * IE2  GET /impact-events/:id   — get one (200)
 * IE3  GET /impact-events/999   — 404
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';
import { ImpactEventController } from './impact-event.controller';
import { ImpactEventService } from './impact-event.service';

const STUB_EVENT = {
  id: 1, sdg_number: 4, metric: 'funds_raised', magnitude: 5000,
  currency: 'BGN', type: 'event', created_at: new Date('2026-02-01').toISOString(),
};

const mockImpactEventService = {
  listEvents: async () => ({
    data: [STUB_EVENT],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  }),
  getEvent: async (id: number) => {
    if (id === 999) throw new NotFoundException(`ImpactEvent ${id} not found`);
    return STUB_EVENT;
  },
};

describe('Phase 0.3N — ImpactEvent controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [ImpactEventController],
      providers: [{ provide: ImpactEventService, useValue: mockImpactEventService }],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('IE1: GET /impact-events returns 200 with pagination', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/impact-events' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body.data[0]).toHaveProperty('sdg_number');
  });

  it('IE2: GET /impact-events/:id returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/impact-events/1' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('metric', 'funds_raised');
  });

  it('IE3: GET /impact-events/999 returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/impact-events/999' });
    expect(res.statusCode).toBe(404);
  });
});
